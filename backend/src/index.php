<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/cache.php';
require __DIR__ . '/mailer.php';

date_default_timezone_set(getenv('TZ') ?: 'America/Mexico_City');
header('Content-Type: application/json; charset=utf-8');

const ESTADOS = ['pendiente', 'en_progreso', 'completada'];
const NOMBRES_ESTADO = [
    'pendiente'   => 'Pendiente',
    'en_progreso' => 'En progreso',
    'completada'  => 'Completada',
];

function responder(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error(int $codigo, string $mensaje, array $detalles = []): never
{
    $cuerpo = ['error' => $mensaje];
    if ($detalles !== []) {
        $cuerpo['detalles'] = $detalles;
    }
    responder($codigo, $cuerpo);
}

function leer_json(): array
{
    $datos = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($datos)) {
        error(400, 'El cuerpo de la petición debe ser un objeto JSON válido.');
    }
    return $datos;
}

/**
 * Valida los campos recibidos. Con $parcial = true (PUT) solo revisa los enviados.
 * Devuelve [campos limpios, errores por campo].
 */
function validar(array $datos, bool $parcial): array
{
    $limpios = [];
    $errores = [];

    if (array_key_exists('titulo', $datos) || !$parcial) {
        $titulo = is_string($datos['titulo'] ?? null) ? trim($datos['titulo']) : '';
        $largo  = mb_strlen($titulo);
        if ($largo < 3 || $largo > 120) {
            $errores['titulo'] = 'El título debe tener entre 3 y 120 caracteres.';
        }
        $limpios['titulo'] = $titulo;
    }

    if (array_key_exists('descripcion', $datos)) {
        $descripcion = is_string($datos['descripcion']) ? trim($datos['descripcion']) : '';
        if (mb_strlen($descripcion) > 500) {
            $errores['descripcion'] = 'La descripción admite hasta 500 caracteres.';
        }
        $limpios['descripcion'] = $descripcion;
    }

    if (array_key_exists('fecha_limite', $datos) || !$parcial) {
        $fecha  = is_string($datos['fecha_limite'] ?? null) ? $datos['fecha_limite'] : '';
        $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if ($objeto === false || $objeto->format('Y-m-d') !== $fecha) {
            $errores['fecha_limite'] = 'La fecha límite es obligatoria y debe tener el formato AAAA-MM-DD.';
        }
        $limpios['fecha_limite'] = $fecha;
    }

    if (array_key_exists('estado', $datos)) {
        if (!in_array($datos['estado'], ESTADOS, true)) {
            $errores['estado'] = 'El estado debe ser pendiente, en_progreso o completada.';
        }
        $limpios['estado'] = $datos['estado'];
    }

    return [$limpios, $errores];
}

/**
 * Agrega a cada fila el cálculo de vencimiento (mejora individual: fecha límite).
 * Se calcula al responder, no al guardar en caché, para que nunca quede desfasado.
 */
function presentar(array $fila): array
{
    $hoy    = new DateTimeImmutable('today');
    $limite = new DateTimeImmutable($fila['fecha_limite']);
    $dias   = (int) $hoy->diff($limite)->format('%r%a');
    $aviso  = (int) (getenv('DIAS_AVISO') ?: 3);

    $vencimiento = match (true) {
        $fila['estado'] === 'completada' => 'completada',
        $dias < 0                        => 'vencida',
        $dias <= $aviso                  => 'proxima',
        default                          => 'en_tiempo',
    };

    return [
        'id'             => (int) $fila['id'],
        'titulo'         => $fila['titulo'],
        'descripcion'    => $fila['descripcion'],
        'fecha_limite'   => $fila['fecha_limite'],
        'estado'         => $fila['estado'],
        'creado_en'      => $fila['creado_en'],
        'actualizado_en' => $fila['actualizado_en'],
        'dias_restantes' => $dias,
        'vencimiento'    => $vencimiento,
    ];
}

function buscar_actividad(int $id): array
{
    $consulta = db()->prepare('SELECT * FROM actividades WHERE id = ?');
    $consulta->execute([$id]);
    $fila = $consulta->fetch();
    if ($fila === false) {
        error(404, "No existe la actividad $id.");
    }
    return $fila;
}

function es_duplicado(PDOException $e): bool
{
    return ($e->errorInfo[1] ?? null) === 1062;
}

const MENSAJE_DUPLICADO = 'Ya existe una actividad con ese título y esa fecha límite.';

// ---------------------------------------------------------------- endpoints

function salud(): never
{
    $estado = ['mariadb' => 'ok', 'redis' => 'ok'];

    try {
        db()->query('SELECT 1');
    } catch (Throwable $e) {
        error_log('[health] MariaDB: ' . $e->getMessage());
        $estado['mariadb'] = 'error';
    }

    try {
        conectar_redis()->ping();
    } catch (Throwable $e) {
        error_log('[health] Redis: ' . $e->getMessage());
        $estado['redis'] = 'error';
    }

    $sano = !in_array('error', $estado, true);
    responder($sano ? 200 : 503, ['estado' => $sano ? 'ok' : 'degradado'] + $estado + ['hora' => date('c')]);
}

function listar(): never
{
    $redis     = cache();
    $filas     = null;
    $origen    = 'mariadb';
    $ttl       = null;
    $consultas = null;

    try {
        if ($redis !== null) {
            $consultas = $redis->incr(CLAVE_CONTADOR);
            $guardado  = $redis->get(CLAVE_LISTA);
            if (is_string($guardado)) {
                $filas  = json_decode($guardado, true);
                $origen = 'redis';
                $ttl    = $redis->ttl(CLAVE_LISTA);
            }
        }
    } catch (Throwable $e) {
        error_log('[cache] Error de lectura: ' . $e->getMessage());
        $redis = null;
    }

    if (!is_array($filas)) {
        $origen = 'mariadb';
        $filas  = db()->query('SELECT * FROM actividades ORDER BY fecha_limite, id')->fetchAll();
        try {
            if ($redis !== null) {
                $ttl = cache_ttl();
                $redis->setex(CLAVE_LISTA, $ttl, json_encode($filas, JSON_UNESCAPED_UNICODE));
            }
        } catch (Throwable $e) {
            error_log('[cache] Error de escritura: ' . $e->getMessage());
        }
    }

    error_log(sprintf(
        '[cache] %s GET /actividades (consulta #%s, origen %s)',
        $origen === 'redis' ? 'HIT' : 'MISS',
        $consultas ?? '?',
        $origen
    ));

    header('X-Cache: ' . ($origen === 'redis' ? 'HIT' : 'MISS'));
    responder(200, [
        'origen'      => $origen,
        'ttl'         => $ttl,
        'consultas'   => $consultas,
        'total'       => count($filas),
        'actividades' => array_map('presentar', $filas),
    ]);
}

function crear(): never
{
    [$campos, $errores] = validar(leer_json(), false);
    if ($errores !== []) {
        error(422, 'Revisa los campos marcados.', $errores);
    }

    try {
        $alta = db()->prepare(
            'INSERT INTO actividades (titulo, descripcion, fecha_limite, estado) VALUES (?, ?, ?, ?)'
        );
        $alta->execute([
            $campos['titulo'],
            $campos['descripcion'] ?? '',
            $campos['fecha_limite'],
            $campos['estado'] ?? 'pendiente',
        ]);
    } catch (PDOException $e) {
        if (es_duplicado($e)) {
            error(409, MENSAJE_DUPLICADO);
        }
        throw $e;
    }

    $actividad = presentar(buscar_actividad((int) db()->lastInsertId()));
    invalidar_cache('alta de la actividad ' . $actividad['id']);

    $correo = true;
    try {
        enviar_correo(
            'Nueva actividad: ' . $actividad['titulo'],
            implode("\n", [
                'Se registró una actividad en la agenda.',
                '',
                'Folio:        ' . $actividad['id'],
                'Título:       ' . $actividad['titulo'],
                'Descripción:  ' . ($actividad['descripcion'] !== '' ? $actividad['descripcion'] : '(sin descripción)'),
                'Fecha límite: ' . $actividad['fecha_limite'],
                'Estado:       ' . NOMBRES_ESTADO[$actividad['estado']],
                'Registrada:   ' . $actividad['creado_en'],
                '',
                'Mensaje de prueba capturado por Mailpit; no se entrega a un buzón real.',
            ])
        );
        error_log('[correo] Aviso enviado por la actividad ' . $actividad['id']);
    } catch (Throwable $e) {
        // La actividad ya quedó guardada: el fallo del correo no revierte el alta.
        $correo = false;
        error_log('[correo] No se pudo enviar: ' . $e->getMessage());
    }

    header('Location: /api/actividades/' . $actividad['id']);
    responder(201, ['mensaje' => 'Actividad creada.', 'correo_enviado' => $correo, 'actividad' => $actividad]);
}

function actualizar(int $id): never
{
    $actual = buscar_actividad($id);
    [$campos, $errores] = validar(leer_json(), true);
    if ($errores !== []) {
        error(422, 'Revisa los campos marcados.', $errores);
    }
    if ($campos === []) {
        error(400, 'Envía al menos un campo: titulo, descripcion, fecha_limite o estado.');
    }

    $nuevo = array_merge($actual, $campos);
    try {
        $cambio = db()->prepare(
            'UPDATE actividades SET titulo = ?, descripcion = ?, fecha_limite = ?, estado = ? WHERE id = ?'
        );
        $cambio->execute([$nuevo['titulo'], $nuevo['descripcion'], $nuevo['fecha_limite'], $nuevo['estado'], $id]);
    } catch (PDOException $e) {
        if (es_duplicado($e)) {
            error(409, MENSAJE_DUPLICADO);
        }
        throw $e;
    }

    invalidar_cache("cambio en la actividad $id");
    responder(200, ['mensaje' => 'Actividad actualizada.', 'actividad' => presentar(buscar_actividad($id))]);
}

function eliminar(int $id): never
{
    $actividad = buscar_actividad($id);
    db()->prepare('DELETE FROM actividades WHERE id = ?')->execute([$id]);
    invalidar_cache("baja de la actividad $id");
    responder(200, ['mensaje' => 'Actividad eliminada.', 'actividad' => presentar($actividad)]);
}

// ------------------------------------------------------------------ rutas

$metodo = $_SERVER['REQUEST_METHOD'];
$ruta   = trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$partes = $ruta === '' ? [] : explode('/', $ruta);

try {
    if ($partes === [] && $metodo === 'GET') {
        responder(200, [
            'servicio'  => 'API de la agenda de actividades',
            'endpoints' => [
                'GET /health',
                'GET /actividades',
                'GET /actividades/{id}',
                'POST /actividades',
                'PUT /actividades/{id}',
                'DELETE /actividades/{id}',
            ],
        ]);
    }

    if ($partes === ['health']) {
        $metodo === 'GET' ? salud() : error(405, 'Método no permitido.');
    }

    if ($partes === ['actividades']) {
        match ($metodo) {
            'GET'   => listar(),
            'POST'  => crear(),
            default => error(405, 'Método no permitido. Usa GET o POST.'),
        };
    }

    if (count($partes) === 2 && $partes[0] === 'actividades') {
        if (!ctype_digit($partes[1]) || (int) $partes[1] < 1) {
            error(400, 'El identificador debe ser un número entero positivo.');
        }
        $id = (int) $partes[1];
        match ($metodo) {
            'GET'    => responder(200, ['actividad' => presentar(buscar_actividad($id))]),
            'PUT'    => actualizar($id),
            'DELETE' => eliminar($id),
            default  => error(405, 'Método no permitido. Usa GET, PUT o DELETE.'),
        };
    }

    error(404, 'Ruta no encontrada.');
} catch (PDOException $e) {
    error_log('[db] ' . $e->getMessage());
    error(503, 'La base de datos no está disponible. Intenta de nuevo en unos segundos.');
} catch (Throwable $e) {
    error_log('[api] ' . $e->getMessage());
    error(500, 'Error interno del servidor.');
}
