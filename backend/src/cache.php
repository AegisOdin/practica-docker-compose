<?php
declare(strict_types=1);

const CLAVE_LISTA    = 'actividades:todas';
const CLAVE_CONTADOR = 'contador_consultas';

/** Conexión a Redis; lanza una excepción si el servicio no responde. */
function conectar_redis(): Redis
{
    $redis = new Redis();
    $redis->connect(getenv('REDIS_HOST') ?: 'redis', (int) (getenv('REDIS_PORT') ?: 6379), 1.5);
    $clave = getenv('REDIS_PASSWORD') ?: '';
    if ($clave !== '') {
        $redis->auth($clave);
    }
    return $redis;
}

/**
 * Redis para la caché. Devuelve null si no está disponible: la agenda sigue
 * funcionando con MariaDB aunque Redis se caiga.
 */
function cache(): ?Redis
{
    static $redis = false;
    if ($redis === false) {
        try {
            $redis = conectar_redis();
        } catch (Throwable $e) {
            error_log('[cache] Redis no disponible: ' . $e->getMessage());
            $redis = null;
        }
    }
    return $redis;
}

function cache_ttl(): int
{
    return max(30, (int) (getenv('CACHE_TTL') ?: 60));
}

/** Borra la lista guardada para que la siguiente consulta lea MariaDB. */
function invalidar_cache(string $motivo): void
{
    try {
        cache()?->del(CLAVE_LISTA);
        error_log("[cache] INVALIDADA ($motivo)");
    } catch (Throwable $e) {
        error_log('[cache] No se pudo invalidar: ' . $e->getMessage());
    }
}
