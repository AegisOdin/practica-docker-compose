# Agenda de actividades

Práctica individual de Docker Compose con cinco servicios: **frontend, backend, MariaDB, Redis y Mailpit**.

| Dato | Información |
| --- | --- |
| Asignatura | Infraestructura de Servicios |
| Estudiante | Neftalí Odín García Ramírez |
| Número de control | 121291 |
| Dominio asignado | Último dígito 1: agenda de actividades (entidad `Actividad`) |
| Grupo | A |
| Mejora individual | Fecha límite con detección de actividades vencidas y próximas a vencer |
| Repositorio | https://github.com/AegisOdin/practica-docker-compose |

## Descripción

Aplicación web para llevar una agenda: se pueden registrar actividades con su fecha límite, consultarlas,
editarlas, cambiar su estado y eliminarlas. El backend guarda los datos en MariaDB, conserva el listado
en Redis durante 60 segundos, cuenta cada consulta y envía un correo de prueba a Mailpit al crear una actividad.

## Arquitectura

```
Navegador
  └── http://localhost:8081
        └── frontend (Nginx)
              ├── archivos estáticos (HTML, CSS, JavaScript)
              └── /api/ → backend:80 (PHP 8.3 + Apache)
                           ├── db:5432      MariaDB 11.4
                           ├── redis:6379   Redis 7.4
                           └── mailpit:1025 SMTP de prueba

Mailpit UI → http://localhost:8025
```

| Servicio | Imagen | Responsabilidad | Exposición |
| --- | --- | --- | --- |
| `frontend` | propia, desde `nginx:1.27-alpine` | Interfaz y proxy de `/api/` | Puerto 8081 del host |
| `backend` | propia, desde `php:8.3-apache` | API REST, caché, contador y correo | Solo red interna |
| `db` | `mariadb:11.4` | Persistencia de actividades | Solo red interna |
| `redis` | `redis:7.4-alpine` | Caché del listado y contador | Solo red interna |
| `mailpit` | `axllent/mailpit:v1.21` | Captura de correos de prueba | Interfaz web en 8025 |

Los cinco servicios pertenecen a la red `app_network`. Solo se publican los puertos 8081 y 8025.

### Notas sobre el enunciado

- El enunciado sugiere la imagen `MariaDB:16-alpine`, que no existe en Docker Hub (MariaDB no tiene versión 16
  ni variante Alpine). Se usa `mariadb:11.4`, la versión LTS vigente.
- El enunciado pide `DB_PORT=5432`. MariaDB escucha por defecto en 3306, así que el servicio `db` se inicia
  con `--port=5432` para cumplir el requisito. El valor se toma de la variable `DB_PORT`.
- El directorio de datos real de MariaDB es `/var/lib/mysql`; ahí se monta el volumen nombrado `mariadb_data`.

## Estructura

```
proyecto-garcia-neftali/
├── compose.yaml
├── .env.example
├── .gitignore
├── README.md
├── database/
│   └── init.sql
├── backend/
│   ├── Dockerfile
│   └── src/            index.php, db.php, cache.php, mailer.php
├── frontend/
│   ├── Dockerfile
│   ├── nginx.conf
│   └── src/            index.html, styles.css, app.js
└── docs/               reporte en PDF y capturas
```

## Requisitos

- Docker Engine o Docker Desktop con Docker Compose v2
- Puertos 8081 y 8025 libres en el equipo
- Navegador, `curl` y Git

## Ejecución desde cero

```bash
git clone https://github.com/AegisOdin/practica-docker-compose.git
cd practica-docker-compose

cp .env.example .env        # después edita .env y cambia las tres contraseñas

docker compose config       # valida el archivo
docker compose up -d --build
docker compose ps           # cinco servicios; db y redis en estado healthy
curl http://localhost:8081/api/health
```

| Recurso | URL |
| --- | --- |
| Aplicación | http://localhost:8081 |
| Estado de la API | http://localhost:8081/api/health |
| Correos capturados (Mailpit) | http://localhost:8025 |

Para detener el proyecto: `docker compose down`. Para borrar también los datos: `docker compose down -v`.

## Variables de entorno

Las contraseñas viven en `.env`, que está en `.gitignore` y no se versiona. `.env.example` sirve de plantilla.

| Variable | Uso |
| --- | --- |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Base de datos y credenciales de MariaDB |
| `DB_PORT` | Puerto interno de MariaDB (5432) |
| `REDIS_PASSWORD`, `REDIS_PORT` | Contraseña y puerto de Redis |
| `CACHE_TTL` | Segundos que el listado permanece en caché (mínimo 30, por defecto 60) |
| `SMTP_PORT`, `MAIL_FROM`, `MAIL_TO` | Envío de correo hacia Mailpit |
| `DIAS_AVISO` | Días de anticipación para marcar una actividad como próxima a vencer |
| `FRONTEND_PORT`, `MAILPIT_PORT` | Puertos publicados en el host |
| `TZ` | Zona horaria para calcular vencimientos |

El backend recibe además `DB_HOST=db`, `REDIS_HOST=redis` y `SMTP_HOST=mailpit`, fijos en `compose.yaml`.

## API

El navegador llama a `/api/...`; Nginx quita el prefijo y reenvía la petición al backend.

| Método | Ruta | Descripción | Códigos |
| --- | --- | --- | --- |
| GET | `/api/health` | Conectividad con MariaDB y Redis | 200, 503 |
| GET | `/api/actividades` | Listado (usa la caché y aumenta el contador) | 200 |
| GET | `/api/actividades/{id}` | Una actividad | 200, 404 |
| POST | `/api/actividades` | Crea, invalida la caché y envía el correo | 201, 400, 409, 422 |
| PUT | `/api/actividades/{id}` | Modifica uno o más campos, invalida la caché | 200, 400, 404, 409, 422 |
| DELETE | `/api/actividades/{id}` | Elimina, invalida la caché | 200, 404 |

Campos: `titulo` (3 a 120 caracteres), `descripcion` (hasta 500), `fecha_limite` (`AAAA-MM-DD`) y
`estado` (`pendiente`, `en_progreso` o `completada`).

```bash
curl -X POST http://localhost:8081/api/actividades \
  -H 'Content-Type: application/json' \
  -d '{"titulo":"Estudiar para el examen","descripcion":"Unidades 3 y 4","fecha_limite":"2026-10-20"}'

curl -X PUT http://localhost:8081/api/actividades/1 \
  -H 'Content-Type: application/json' -d '{"estado":"completada"}'

curl -X DELETE http://localhost:8081/api/actividades/1
```

## Redis: caché y contador

| Clave | Tipo | Contenido |
| --- | --- | --- |
| `actividades:todas` | cadena con TTL de 60 s | Resultado en JSON de la consulta general |
| `contador_consultas` | entero | Veces que se ha consultado el listado |

- La primera consulta lee MariaDB y guarda el resultado (`MISS`). Las siguientes se atienden desde Redis (`HIT`)
  hasta que la clave expira.
- Crear, actualizar o eliminar una actividad borra la clave, de modo que la siguiente consulta vuelve a MariaDB.
- Cada respuesta indica su procedencia en el campo `origen`, en la cabecera `X-Cache` y en los registros del backend.
- Redis guarda sus datos con AOF (`--appendonly yes`) en el volumen `redis_data`.

## Mailpit

Al crear una actividad el backend abre una conexión SMTP con `mailpit:1025` y envía un mensaje con el folio,
título, descripción, fecha límite y estado. El correo se consulta en http://localhost:8025.

Mailpit es solo una herramienta de desarrollo: captura los mensajes y los muestra en su interfaz, pero **no
entrega correo real** a ningún buzón, no autentica remitentes y guarda los mensajes de forma temporal.

## Mejora individual: fecha límite

Cada actividad tiene una fecha de vencimiento (`fecha_limite`, columna `DATE` obligatoria). El backend calcula
al responder los días restantes y clasifica cada actividad:

| `vencimiento` | Condición | En la interfaz |
| --- | --- | --- |
| `vencida` | La fecha ya pasó y no está completada | Grupo «Vencidas», hoja de calendario roja |
| `proxima` | Vence hoy o en los próximos `DIAS_AVISO` días (3) | Grupo «Vencen pronto», hoja amarilla |
| `en_tiempo` | Faltan más días | Grupo «Más adelante», hoja azul |
| `completada` | Estado completada | Grupo «Completadas», hoja verde y título tachado |

El cálculo se hace después de leer la caché, así que la clasificación siempre corresponde al día actual.
Componentes involucrados: frontend, backend y MariaDB.

## Pruebas

| Prueba | Procedimiento | Resultado esperado |
| --- | --- | --- |
| Validación YAML | `docker compose config` | Sin errores |
| Estado | `docker compose ps` | Cinco servicios activos; `db` y `redis` saludables |
| CRUD | Crear, editar y eliminar desde http://localhost:8081 | Cambios visibles y persistentes |
| Persistencia | `docker compose rm -sf db && docker compose up -d db` | Las actividades permanecen |
| Caché | Dos `curl -i http://localhost:8081/api/actividades` seguidos | `X-Cache: MISS` y luego `X-Cache: HIT` |
| Invalidación | Crear una actividad y consultar | La lista incluye el dato nuevo (`MISS`) |
| Contador | Consultar varias veces y `redis-cli GET contador_consultas` | El valor aumenta |
| Correo | Crear una actividad y abrir http://localhost:8025 | Mensaje con los datos de la actividad |
| Aislamiento | `docker compose ps` | `db`, `redis` y `backend` sin puertos publicados |
| Recuperación | `docker compose stop backend && docker compose start backend` | La aplicación vuelve a operar |

### Comandos de evidencia

```bash
docker compose config
docker compose ps
docker compose images
docker compose exec db sh -c 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT id, titulo, fecha_limite, estado FROM actividades"'
docker compose exec redis redis-cli KEYS "*"
docker compose exec redis redis-cli GET contador_consultas
docker compose exec redis redis-cli TTL actividades:todas
docker compose logs backend | grep cache
docker network inspect agenda-actividades_app_network
docker volume inspect agenda-actividades_mariadb_data
```

## Cómo se resuelven los nombres db, redis, mailpit y backend

Compose crea la red `agenda-actividades_app_network` de tipo bridge y conecta a ella los cinco contenedores.
En las redes definidas por el usuario, Docker ofrece a cada contenedor un servidor DNS interno en `127.0.0.11`.
Ese servidor registra el nombre de cada servicio de Compose como alias de red y responde con la IP que el
contenedor tiene en ese momento dentro de la red.

Por eso el backend se conecta a `db`, `redis` y `mailpit` sin conocer ninguna dirección IP, y Nginx reenvía
`/api/` a `backend`. Si un contenedor se recrea y cambia de IP, el nombre sigue apuntando al contenedor nuevo.
`localhost` no sirve para esto porque dentro de un contenedor se refiere al propio contenedor.

En `frontend/nginx.conf` se declara `resolver 127.0.0.11` y se usa una variable en `proxy_pass` para que Nginx
vuelva a consultar el DNS en lugar de guardar la IP del arranque.

## Seguridad

- Solo se publican los puertos 8081 (frontend) y 8025 (interfaz de Mailpit).
- MariaDB, Redis y el backend usan `expose`: son alcanzables únicamente dentro de `app_network`.
- Las contraseñas están en `.env`, fuera del repositorio. Compose se niega a iniciar si falta alguna.
- Redis exige contraseña (`--requirepass`).
- Ningún contenedor usa `privileged` ni monta el socket de Docker.
- No se usa `container_name`, por lo que los servicios pueden escalarse.

## Preguntas de reflexión

**1. ¿Por qué el backend debe usar db:5432 y no localhost para conectarse a MariaDB?**
Cada contenedor tiene su propia pila de red, así que `localhost` dentro del backend es el propio backend, donde
no hay ninguna base de datos. `db` es el nombre del servicio, que el DNS interno de Docker traduce a la IP del
contenedor de MariaDB dentro de `app_network`.

**2. ¿Cuál es la diferencia entre ports y expose en Docker Compose?**
`ports` publica un puerto del contenedor en el host, por lo que queda accesible desde fuera de Docker. `expose`
solo documenta que el contenedor escucha en ese puerto para los demás servicios de la misma red; no abre nada
en el host.

**3. ¿Qué información permanece cuando se elimina un contenedor y por qué?**
Permanece lo guardado en volúmenes: aquí, los datos de MariaDB (`mariadb_data`) y el AOF de Redis
(`redis_data`). Los volúmenes tienen un ciclo de vida independiente del contenedor. La capa de escritura del
contenedor sí se pierde al eliminarlo.

**4. ¿Qué problema resuelve el health check antes de iniciar el backend?**
Que un contenedor esté iniciado no significa que el servicio ya acepte conexiones: MariaDB tarda varios
segundos en inicializarse. Con `depends_on` y `condition: service_healthy` el backend arranca hasta que la
base de datos y Redis responden, y se evitan errores de conexión en el arranque.

**5. ¿Cuándo puede una caché devolver información desactualizada y cómo lo evitaste?**
Cuando los datos cambian en MariaDB mientras la copia en Redis sigue vigente. Se evita de dos formas: toda
operación de alta, cambio o baja borra la clave `actividades:todas`, y la clave expira sola a los 60 segundos.
Además, el vencimiento se calcula después de leer la caché para que no dependa del momento en que se guardó.

**6. ¿Por qué Mailpit no debe considerarse un servidor de correo de producción?**
Porque no entrega los mensajes: los captura para inspeccionarlos. Tampoco exige autenticación ni cifrado por
defecto, y su almacenamiento es temporal.

**7. ¿Qué servicio escalarías primero y qué cambio harías para evitar conflictos de puertos?**
El backend, que concentra el trabajo de la aplicación. Puede escalarse con
`docker compose up -d --scale backend=3` porque no publica puertos en el host ni tiene `container_name`; Nginx
reparte las peticiones al resolver el nombre `backend`. Un servicio con un puerto fijo publicado, como el
frontend, no puede tener varias réplicas: habría que quitar el puerto fijo o publicar un rango y colocar un
balanceador delante.

**8. ¿Qué riesgos existirían si MariaDB y Redis se publicaran hacia Internet?**
Quedarían expuestos a ataques de fuerza bruta sobre las contraseñas, a la explotación de vulnerabilidades del
servicio y al robo, alteración o borrado de los datos. Redis en particular se diseñó para redes de confianza y
un acceso no autorizado permite leer y vaciar toda la caché.
