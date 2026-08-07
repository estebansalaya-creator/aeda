<?php
/**
 * Envío del mail de confirmación por SMTP.
 *
 * Se habla SMTP directo sobre un socket, sin librerías. Son cuatro
 * comandos y evita tener que instalar PHPMailer con composer en Railway,
 * que es una fuente de problemas al desplegar por una funcionalidad chica.
 *
 * Soporta las dos formas habituales:
 *   - puerto 465 con TLS desde el arranque (ssl://)
 *   - puerto 587 en claro y STARTTLS
 *
 * Si algo falla NO se corta la inscripción: ya está guardada en la base y
 * el mail es un extra. Se registra en el log y sigue.
 */

declare(strict_types=1);

/**
 * Codifica una cabecera con acentos. mbstring es lo correcto, pero no
 * siempre está compilada; el formato B de RFC 2047 se arma a mano en dos
 * líneas y evita depender de una extensión por el asunto del mail.
 */
function cabeceraMime(string $texto): string
{
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($texto, 'UTF-8');
    }
    return preg_match('/[^\x20-\x7E]/', $texto)
        ? '=?UTF-8?B?' . base64_encode($texto) . '?='
        : $texto;
}

/**
 * Abre el socket contra el servidor de correo, forzando IPv4.
 *
 * smtp.gmail.com resuelve a IPv6 y a IPv4. Si la máquina tiene IPv6
 * configurado pero sin salida real —bastante común detrás de un router
 * hogareño— PHP elige la dirección IPv6, el TCP parece establecerse y
 * después no llega nada: la conexión se cuelga hasta el timeout.
 *
 * Se resuelve el nombre a IPv4 y se conecta contra la IP, pero declarando
 * `peer_name` para que la validación del certificado y el SNI sigan siendo
 * contra el nombre de dominio. Si la resolución falla, se cae al método
 * de siempre.
 */
function abrirSocket(string $host, int $puerto, ?string &$errstr)
{
    $esquema = ($puerto === 465) ? 'ssl://' : '';
    $ipv4    = gethostbyname($host);          // devuelve el host si no resuelve
    $destino = ($ipv4 !== $host) ? $ipv4 : $host;

    $ctx = stream_context_create(['ssl' => [
        'peer_name'         => $host,
        'SNI_enabled'       => true,
        'verify_peer'       => true,
        'verify_peer_name'  => true,
    ]]);

    $errno = 0;
    $sock = @stream_socket_client(
        "$esquema$destino:$puerto", $errno, $errstr, 10,
        STREAM_CLIENT_CONNECT, $ctx
    );

    // Último intento con el nombre, por si la IPv4 estuviera bloqueada.
    if (!$sock && $destino !== $host) {
        $sock = @stream_socket_client(
            "$esquema$host:$puerto", $errno, $errstr, 10,
            STREAM_CLIENT_CONNECT, $ctx
        );
    }
    return $sock;
}

/** Diálogo con el servidor: manda una línea y valida el código de respuesta. */
function smtpCmd($sock, ?string $linea, array $esperado): string
{
    if ($linea !== null) {
        fwrite($sock, $linea . "\r\n");
    }

    $resp = '';
    while (true) {
        $l = fgets($sock, 515);

        // Sin esta comprobación, si el servidor no contesta el fgets se
        // queda esperando y la página nunca termina de cargar.
        $meta = stream_get_meta_data($sock);
        if ($meta['timed_out']) {
            throw new RuntimeException(
                'el servidor no respondió a tiempo' .
                ($linea ? ' tras enviar: ' . explode(' ', $linea)[0] : ' al conectar'));
        }
        if ($l === false) {
            throw new RuntimeException('el servidor cortó la conexión');
        }

        $resp .= $l;
        // La última línea de una respuesta multilínea lleva espacio en la
        // cuarta posición; las intermedias llevan guión.
        if (strlen($l) < 4 || $l[3] !== '-') {
            break;
        }
    }

    $codigo = (int) substr($resp, 0, 3);
    if (!in_array($codigo, $esperado, true)) {
        throw new RuntimeException("SMTP respondió $codigo: " . trim($resp));
    }
    return $resp;
}

function enviarConfirmacion(array $datos): bool
{
    $host = getenv('SMTP_HOST');
    $user = getenv('SMTP_USER');
    $pass = getenv('SMTP_PASS');

    if (!$host || !$user || !$pass) {
        return false;   // sin configurar: el formulario anda igual
    }

    $puerto  = (int) (getenv('SMTP_PORT') ?: 587);
    $desde   = getenv('SMTP_DESDE') ?: $user;
    $nombre  = getenv('SMTP_NOMBRE') ?: 'AEDA';
    $copia   = getenv('SMTP_COPIA') ?: '';       // opcional, para el organizador
    $para    = $datos['email'];

    if (!$para || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $cuerpo = armarCuerpo($datos, $nombre);

    $limite   = '=_' . bin2hex(random_bytes(12));
    $cabecera = [
        'Date: ' . date('r'),
        'From: ' . cabeceraMime($nombre) . " <$desde>",
        "To: <$para>",
        'Subject: ' . cabeceraMime('Inscripción confirmada — Día de la Niñez'),
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$limite\"",
        'Message-ID: <' . bin2hex(random_bytes(12)) . "@$host>",
    ];
    if ($copia) {
        $cabecera[] = "Bcc: <$copia>";
    }

    $mensaje = implode("\r\n", $cabecera) . "\r\n\r\n"
             . "--$limite\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($cuerpo['texto'])) . "\r\n"
             . "--$limite\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($cuerpo['html'])) . "\r\n"
             . "--$limite--\r\n";

    // Un punto solo al principio de una línea cierra el mensaje en SMTP:
    // hay que duplicarlo para que no corte el correo por la mitad.
    $mensaje = preg_replace('/^\./m', '..', $mensaje);

    $destinos = array_filter([$para, $copia]);
    $sock = abrirSocket($host, $puerto, $errstr);

    if (!$sock) {
        error_log("smtp: no se pudo conectar a $host:$puerto — $errstr");
        return false;
    }

    try {
        stream_set_timeout($sock, 10);
        smtpCmd($sock, null, [220]);
        smtpCmd($sock, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);

        if ($puerto !== 465) {
            smtpCmd($sock, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('no se pudo activar TLS');
            }
            smtpCmd($sock, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
        }

        smtpCmd($sock, 'AUTH LOGIN', [334]);
        smtpCmd($sock, base64_encode($user), [334]);
        smtpCmd($sock, base64_encode($pass), [235]);
        smtpCmd($sock, "MAIL FROM:<$desde>", [250]);
        foreach ($destinos as $d) {
            smtpCmd($sock, "RCPT TO:<$d>", [250, 251]);
        }
        smtpCmd($sock, 'DATA', [354]);
        fwrite($sock, $mensaje . "\r\n.\r\n");
        smtpCmd($sock, null, [250]);
        smtpCmd($sock, 'QUIT', [221]);
        fclose($sock);
        return true;
    } catch (Throwable $e) {
        error_log('smtp: ' . $e->getMessage());
        @fclose($sock);
        return false;
    }
}

/** Arma las dos versiones del mensaje: texto plano y HTML. */
function armarCuerpo(array $d, string $nombre): array
{
    $fecha = getenv('FECHA_EVENTO') ?: '';
    $lugar = getenv('LUGAR_EVENTO') ?: '';
    $hora  = getenv('HORA_EVENTO') ?: '';
    $cuando = $fecha ? date('d/m/Y', strtotime($fecha)) : '';
    if ($cuando && $hora) {
        $cuando .= ', ' . $hora;
    }

    $texto = "Hola {$d['titular']},\n\n"
           . "Tu inscripción al festejo del Día de la Niñez quedó registrada.\n\n"
           . "Adultos: {$d['adultos']}\n"
           . "Chicos:  {$d['ninos']}\n"
           . ($cuando ? "Fecha:   $cuando\n" : '')
           . ($lugar  ? "Lugar:   $lugar\n"  : '')
           . "\nSi algún dato no es correcto, volvé a entrar al formulario con tu "
           . "documento y confirmá de nuevo: se actualiza sin duplicar la inscripción.\n\n"
           . "$nombre\n";

    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"></head>'
          . '<body style="margin:0;background:#eef2f7;font-family:system-ui,Segoe UI,Roboto,sans-serif;color:#233;">'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:26px 14px;">'
          . '<table role="presentation" width="100%" style="max-width:480px;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 18px rgba(20,40,80,.10);">'
          . '<tr><td style="background:#002449;padding:22px 26px;color:#fff;">'
          . '<div style="font-size:1.3rem;font-weight:700;">Día de la Niñez</div>'
          . '<div style="opacity:.9;font-size:.9rem;margin-top:3px;">Inscripción confirmada</div>'
          . '</td></tr><tr><td style="padding:24px 26px;">'
          . '<p style="margin:0 0 14px;">Hola <strong>' . $e($d['titular']) . '</strong>, tu inscripción quedó registrada.</p>'
          . '<table role="presentation" width="100%" style="background:#f3f7fc;border-radius:9px;font-size:.92rem;">'
          . '<tr><td style="padding:9px 14px;color:#6b7785;">Adultos</td><td align="right" style="padding:9px 14px;font-weight:700;">' . (int) $d['adultos'] . '</td></tr>'
          . '<tr><td style="padding:9px 14px;color:#6b7785;">Chicos</td><td align="right" style="padding:9px 14px;font-weight:700;">' . (int) $d['ninos'] . '</td></tr>'
          . ($cuando ? '<tr><td style="padding:9px 14px;color:#6b7785;">Fecha</td><td align="right" style="padding:9px 14px;font-weight:700;">' . $e($cuando) . '</td></tr>' : '')
          . ($lugar  ? '<tr><td style="padding:9px 14px;color:#6b7785;">Lugar</td><td align="right" style="padding:9px 14px;font-weight:700;">' . $e($lugar) . '</td></tr>' : '')
          . '</table>'
          . '<p style="margin:16px 0 0;font-size:.85rem;color:#6b7785;">Si algún dato no es correcto, volvé a entrar al formulario con tu documento y confirmá de nuevo: se actualiza sin duplicar la inscripción.</p>'
          . '</td></tr><tr><td style="padding:14px 26px;background:#f7f9fc;color:#6b7785;font-size:.8rem;">' . $e($nombre) . '</td></tr>'
          . '</table></td></tr></table></body></html>';

    return ['texto' => $texto, 'html' => $html];
}
