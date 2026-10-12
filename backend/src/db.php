<?php
declare(strict_types=1);

/**
 * Conexión a MariaDB. El host es el nombre del servicio de Compose (db),
 * que el DNS interno de Docker resuelve dentro de app_network.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: 'db',
            getenv('DB_PORT') ?: '5432',
            getenv('DB_NAME') ?: 'agenda'
        );
        $pdo = new PDO($dsn, getenv('DB_USER') ?: '', getenv('DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 3,
        ]);
    }
    return $pdo;
}
