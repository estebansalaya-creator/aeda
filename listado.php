<?php
/**
 * Listado de inscriptos, para uso interno.
 *
 * Se protege con un token que viaja en la URL y sale de la variable de
 * entorno TOKEN_LISTADO. No es un sistema de usuarios: alcanza para que la
 * dirección no quede a la vista de cualquiera que pruebe /listado.php.
 * Si el evento crece, esto hay que reemplazarlo por un login de verdad.
 *
 *   /listado.php?t=EL_TOKEN
 *   /listado.php?t=EL_TOKEN&csv=1   -> descarga la planilla
 */

declare(strict_types=1);

require __DIR__ . '/db.php';

$token = getenv('TOKEN_LISTADO') ?: '';
if ($token === '' || !hash_equals($token, (string) ($_GET['t'] ?? ''))) {
    http_response_code(404);
    exit('No encontrado');
}

$pdo = conexion();
$filas = $pdo->query(
    "SELECT nro_doc, nombre_titular, empresa, telefono, email,
            cantidad_adultos, cantidad_ninos, ninos_habilitados, creado
     FROM   inscripciones_dia_del_nino
     ORDER  BY creado DESC"
)->fetchAll();

$tot = $pdo->query(
    "SELECT COUNT(*) AS familias,
            COALESCE(SUM(cantidad_adultos),0) AS adultos,
            COALESCE(SUM(cantidad_ninos),0)   AS ninos
     FROM   inscripciones_dia_del_nino"
)->fetch();

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=inscriptos_dia_del_nino.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));   // BOM, para que Excel respete los acentos
    fputcsv($out, ['Documento','Titular','Empresa','Telefono','Mail',
                   'Adultos','Ninos','Ninos habilitados','Fecha']);
    foreach ($filas as $f) {
        fputcsv($out, array_values($f));
    }
    exit;
}

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inscriptos — Día del Niño</title>
<style>
  body { font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; margin:0;
         background:#eef2f7; color:#233; padding:22px; }
  h1 { font-size:1.2rem; margin:0 0 4px; }
  .tot { color:#6b7785; font-size:.88rem; margin-bottom:16px; }
  .tot strong { color:#1565c0; }
  table { width:100%; border-collapse:collapse; background:#fff; border-radius:10px;
          overflow:hidden; box-shadow:0 3px 14px rgba(20,40,80,.08); font-size:.84rem; }
  th { background:#1565c0; color:#fff; text-align:left; padding:9px 11px; font-weight:600; }
  td { padding:8px 11px; border-top:1px solid #eef1f5; }
  tr:hover td { background:#f7fafd; }
  .num { text-align:center; }
  a.csv { display:inline-block; margin-bottom:14px; background:#1565c0; color:#fff;
          padding:7px 14px; border-radius:7px; text-decoration:none; font-size:.84rem; }
</style>
</head>
<body>
  <h1>Inscriptos — Día del Niño</h1>
  <div class="tot">
    <strong><?= (int) $tot['familias'] ?></strong> familias ·
    <strong><?= (int) $tot['adultos'] ?></strong> adultos ·
    <strong><?= (int) $tot['ninos'] ?></strong> chicos
  </div>
  <a class="csv" href="?t=<?= urlencode($_GET['t']) ?>&csv=1">Descargar CSV</a>
  <table>
    <thead>
      <tr>
        <th>Documento</th><th>Titular</th><th>Empresa</th><th>Teléfono</th><th>Mail</th>
        <th class="num">Ad.</th><th class="num">Niños</th><th>Fecha</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($filas as $f): ?>
      <tr>
        <td><?= (int) $f['nro_doc'] ?></td>
        <td><?= h($f['nombre_titular']) ?></td>
        <td><?= h($f['empresa']) ?></td>
        <td><?= h($f['telefono']) ?></td>
        <td><?= h($f['email']) ?></td>
        <td class="num"><?= (int) $f['cantidad_adultos'] ?></td>
        <td class="num"><?= (int) $f['cantidad_ninos'] ?> / <?= (int) $f['ninos_habilitados'] ?></td>
        <td><?= h(substr((string) $f['creado'], 0, 16)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$filas): ?>
      <tr><td colspan="8" style="text-align:center;color:#6b7785;padding:22px;">
        Todavía no hay inscriptos.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
