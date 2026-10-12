<?php
declare(strict_types=1);

/**
 * Envía un correo de texto por SMTP sin autenticación (Mailpit).
 * Lanza RuntimeException si el servidor rechaza algún paso.
 */
function enviar_correo(string $asunto, string $texto): void
{
    $host = getenv('SMTP_HOST') ?: 'mailpit';
    $port = (int) (getenv('SMTP_PORT') ?: 1025);
    $de   = getenv('MAIL_FROM') ?: 'agenda@practica.local';
    $para = getenv('MAIL_TO') ?: 'estudiante@practica.local';

    $smtp = @fsockopen($host, $port, $errno, $error, 3);
    if ($smtp === false) {
        throw new RuntimeException("No se pudo conectar a $host:$port ($error)");
    }
    stream_set_timeout($smtp, 5);

    try {
        smtp_esperar($smtp, 220);
        smtp_orden($smtp, 'EHLO backend', 250);
        smtp_orden($smtp, "MAIL FROM:<$de>", 250);
        smtp_orden($smtp, "RCPT TO:<$para>", 250);
        smtp_orden($smtp, 'DATA', 354);

        $mensaje = implode("\r\n", [
            "From: Agenda de actividades <$de>",
            "To: <$para>",
            'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@agenda.practica.local>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($texto))),
        ]);
        smtp_orden($smtp, $mensaje . "\r\n.", 250);
        smtp_orden($smtp, 'QUIT', 221);
    } finally {
        fclose($smtp);
    }
}

/** @param resource $smtp */
function smtp_orden($smtp, string $orden, int $esperado): void
{
    fwrite($smtp, $orden . "\r\n");
    smtp_esperar($smtp, $esperado);
}

/** @param resource $smtp */
function smtp_esperar($smtp, int $esperado): void
{
    do {
        $linea = fgets($smtp, 1024);
        if ($linea === false) {
            throw new RuntimeException('El servidor SMTP cerró la conexión');
        }
        // Las respuestas multilínea llevan un guion después del código (250-...)
    } while (isset($linea[3]) && $linea[3] === '-');

    if ((int) substr($linea, 0, 3) !== $esperado) {
        throw new RuntimeException('Respuesta SMTP inesperada: ' . trim($linea));
    }
}
