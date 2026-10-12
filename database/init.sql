-- Agenda de actividades: esquema y datos iniciales.
-- MariaDB ejecuta este archivo una sola vez, cuando el volumen de datos está vacío.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS actividades (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    titulo         VARCHAR(120) NOT NULL,
    descripcion    VARCHAR(500) NOT NULL DEFAULT '',
    fecha_limite   DATE         NOT NULL,
    estado         ENUM('pendiente', 'en_progreso', 'completada') NOT NULL DEFAULT 'pendiente',
    creado_en      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT uq_actividad_titulo_fecha UNIQUE (titulo, fecha_limite),
    CONSTRAINT chk_actividad_titulo CHECK (CHAR_LENGTH(TRIM(titulo)) >= 3),
    INDEX idx_actividad_fecha (fecha_limite)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Las fechas se calculan respecto al día de la inicialización para que la agenda
-- muestre desde el primer arranque una actividad vencida, una próxima y una lejana.
INSERT INTO actividades (titulo, descripcion, fecha_limite, estado) VALUES
    ('Entregar práctica de Docker Compose',
     'Subir el repositorio y el PDF de evidencias de Infraestructura de Servicios.',
     CURDATE() + INTERVAL 2 DAY, 'en_progreso'),
    ('Repasar redes y volúmenes de Docker',
     'Leer los apuntes de redes bridge, DNS interno y volúmenes nombrados.',
     CURDATE() - INTERVAL 1 DAY, 'pendiente'),
    ('Exposición del proyecto final',
     'Preparar las diapositivas y ensayar la demostración en vivo.',
     CURDATE() + INTERVAL 15 DAY, 'pendiente');
