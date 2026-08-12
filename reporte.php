<?php
/**
 * Reporte diario de inscripciones.
 *
 * Arma una planilla con todas las inscripciones cargadas hasta el momento y
 * la manda por mail como adjunto. Pensado para correr desde el cron de
 * Railway una vez por día:
 *
 *     php reporte.php
 *
 * También se puede disparar a mano desde el navegador, con el mismo token
 * que protege el listado, para probar que la configuración quedó bien:
 *
 *     /reporte.php?t=EL_TOKEN
 *
 * Variables de entorno propias:
 *   REPORTE_PARA    destinatarios, separados por coma. Sin esto no manda nada.
 *   REPORTE_ASUNTO  opcional, para cambiarle el título al correo.
 *
 * El remitente y las credenciales son los mismos de siempre (SMTP_DESDE,
 * SMTP_USER, SMTP_PASS): la casilla que firma los mails es la que se
 * configure ahí.
 */

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/xlsx.php';

// El contenedor corre en UTC. Sin esto, un reporte que sale a las 6 de la
// mañana diría que es del día anterior.
date_default_timezone_set(getenv('TZ') ?: 'America/Argentina/Buenos_Aires');

const REPORTE_COLUMNAS = [
    'nro_doc'           => 'Documento',
    'nombre_titular'    => 'Titular',
    'empresa'           => 'Empresa',
    'telefono'          => 'Teléfono',
    'email'             => 'Mail',
    'cantidad_adultos'  => 'Adultos',
    'cantidad_ninos'    => 'Chicos',
    'ninos_habilitados' => 'Chicos habilitados',
    'creado'            => 'Se inscribió',
    'actualizado'       => 'Última modificación',
];

/** Trae las inscripciones y los totales en una sola pasada por la base. */
function datosDelReporte(PDO $pdo): array
{
    $filas = $pdo->query(
        "SELECT nro_doc, nombre_titular, empresa, telefono, email,
                cantidad_adultos, cantidad_ninos, ninos_habilitados,
                creado, actualizado
         FROM   inscripciones_dia_del_nino
         ORDER  BY creado DESC"
    )->fetchAll();

    $tot = $pdo->query(
        "SELECT COUNT(*) AS familias,
                COALESCE(SUM(cantidad_adultos), 0) AS adultos,
                COALESCE(SUM(cantidad_ninos), 0)   AS ninos,
                COALESCE(SUM(creado >= NOW() - INTERVAL 1 DAY), 0) AS nuevas
         FROM   inscripciones_dia_del_nino"
    )->fetch() ?: ['familias' => 0, 'adultos' => 0, 'ninos' => 0, 'nuevas' => 0];

    return [$filas, $tot];
}

/** Pasa las filas de la base al formato que espera el generador de planillas. */
function filasParaPlanilla(array $filas): array
{
    $salida = [];
    foreach ($filas as $f) {
        $fila = [];
        // Se recorre REPORTE_COLUMNAS y no un orden escrito a mano: así los
        // encabezados y los datos no se pueden desalinear al tocar el SELECT.
        foreach (array_keys(REPORTE_COLUMNAS) as $col) {
            $v = $f[$col] ?? null;

            if ($col === 'nro_doc') {
                // Como texto: es un número que nadie va a sumar y así Excel no
                // lo muestra en notación científica ni le come los ceros.
                $fila[] = (string) $v;
            } elseif (in_array($col, ['cantidad_adultos', 'cantidad_ninos', 'ninos_habilitados'], true)) {
                $fila[] = (int) $v;
            } elseif (in_array($col, ['creado', 'actualizado'], true)) {
                $ts = strtotime((string) $v);
                $fila[] = $ts ? date('d/m/Y H:i', $ts) : '';
            } else {
                $fila[] = (string) ($v ?? '');
            }
        }
        $salida[] = $fila;
    }
    return $salida;
}

/** Cuerpo del mail: el resumen que se lee sin abrir el adjunto. */
function cuerpoDelReporte(array $tot, string $archivo): array
{
    // FECHA_EVENTO se define a mano en el entorno: si viene en un formato que
    // strtotime() no entiende, el reporte sale igual pero sin la fecha.
    $ts      = strtotime(fechaCorte());
    $cuando  = $ts ? date('d/m/Y', $ts) : '';
    $faltan  = $ts ? (int) floor(($ts - strtotime(date('Y-m-d'))) / 86400) : 0;
    $personas = (int) $tot['adultos'] + (int) $tot['ninos'];

    $lineas = [
        'Grupos familiares inscriptos' => (int) $tot['familias'],
        'Adultos'                      => (int) $tot['adultos'],
        'Chicos'                       => (int) $tot['ninos'],
        'Total de personas'            => $personas,
        'Inscripciones últimas 24 h'   => (int) $tot['nuevas'],
    ];

    $texto = "Inscripciones al Día de la Niñez — " . date('d/m/Y') . "\n\n";
    foreach ($lineas as $k => $v) {
        $texto .= str_pad($k, 30) . $v . "\n";
    }
    $texto .= ($cuando ? "\nEvento: $cuando" . ($faltan > 0 ? " (faltan $faltan días)" : '') : '')
            . "\n\nEl detalle completo va adjunto en $archivo.\n";

    $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    $celdas = '';
    foreach ($lineas as $k => $v) {
        $celdas .= '<tr><td style="padding:9px 14px;color:#6b7785;">' . $e($k) . '</td>'
                 . '<td align="right" style="padding:9px 14px;font-weight:700;">' . $v . '</td></tr>';
    }

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"></head>'
          . '<body style="margin:0;background:#eef2f7;font-family:system-ui,Segoe UI,Roboto,sans-serif;color:#233;">'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:26px 14px;">'
          . '<table role="presentation" width="100%" style="max-width:480px;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 18px rgba(20,40,80,.10);">'
          . '<tr><td style="background:#002449;padding:22px 26px;color:#fff;">'
          . '<div style="font-size:1.3rem;font-weight:700;">Inscripciones al Día de la Niñez</div>'
          . '<div style="opacity:.9;font-size:.9rem;margin-top:3px;">Reporte del ' . $e(date('d/m/Y')) . '</div>'
          . '</td></tr><tr><td style="padding:24px 26px;">'
          . '<table role="presentation" width="100%" style="background:#f3f7fc;border-radius:9px;font-size:.92rem;">'
          . $celdas
          . '</table>'
          . '<p style="margin:16px 0 0;font-size:.85rem;color:#6b7785;">El detalle completo está en la planilla adjunta '
          . '(<strong>' . $e($archivo) . '</strong>).'
          . ($cuando ? ' El evento es el ' . $e($cuando)
                     . ($faltan > 0 ? ': faltan ' . $faltan . ' días.' : '.') : '')
          . '</p>'
          . '</td></tr></table></td></tr></table></body></html>';

    return ['texto' => $texto, 'html' => $html];
}

/** Hace todo el trabajo. Devuelve un mensaje para el log o la pantalla. */
function generarYEnviar(): array
{
    $destino = array_values(array_filter(
        array_map('trim', explode(',', getenv('REPORTE_PARA') ?: '')),
        fn($d) => (bool) filter_var($d, FILTER_VALIDATE_EMAIL)
    ));
    if (!$destino) {
        return [false, 'Falta definir REPORTE_PARA con al menos una dirección válida.'];
    }

    [$filas, $tot] = datosDelReporte(conexion());

    $archivo = 'inscriptos_dia_del_nino_' . date('Y-m-d') . '.xlsx';
    $planilla = xlsxArmar(
        'Inscriptos',
        array_values(REPORTE_COLUMNAS),
        filasParaPlanilla($filas)
    );

    $cuerpo = cuerpoDelReporte($tot, $archivo);
    $asunto = (getenv('REPORTE_ASUNTO') ?: 'Inscripciones Día de la Niñez')
            . ' — ' . date('d/m/Y');

    $ok = enviarMail([
        'para'     => $destino,
        'copia'    => '',          // el reporte ya va a quien corresponde
        'asunto'   => $asunto,
        'texto'    => $cuerpo['texto'],
        'html'     => $cuerpo['html'],
        'adjuntos' => [[
            'nombre' => $archivo,
            'tipo'   => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'datos'  => $planilla,
        ]],
    ]);

    $resumen = count($filas) . ' inscripciones, ' . strlen($planilla) . ' bytes de planilla';

    return $ok
        ? [true,  "Reporte enviado a " . implode(', ', $destino) . " ($resumen)."]
        : [false, "No se pudo enviar el reporte ($resumen). Mirá el log del servicio."];
}

// ── Punto de entrada ───────────────────────────────────────────────────────
// Por consola corre siempre; por web pide el token, así la dirección no
// queda expuesta a que cualquiera dispare correos.
$porConsola = PHP_SAPI === 'cli';

if (!$porConsola) {
    $token = getenv('TOKEN_LISTADO') ?: '';
    if ($token === '' || !hash_equals($token, (string) ($_GET['t'] ?? ''))) {
        http_response_code(404);
        exit('No encontrado');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

try {
    [$ok, $mensaje] = generarYEnviar();
} catch (Throwable $e) {
    error_log('reporte: ' . $e->getMessage());
    $ok = false;
    // Por consola el detalle es justo lo que uno necesita; por web, no: el
    // mensaje de PDO cuenta el host y la base.
    $mensaje = ($porConsola || getenv('DEBUG'))
        ? 'Error al generar el reporte: ' . $e->getMessage()
        : 'Error al generar el reporte. Mirá el log del servicio.';

echo $mensaje . "\n";

// El código de salida le sirve a Railway para marcar la ejecución como
// fallida y que se vea en el historial del cron.
if ($porConsola) {
    exit($ok ? 0 : 1);
}
