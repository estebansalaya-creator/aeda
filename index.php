<?php
/**
 * Inscripción al evento del Día del Niño.
 *
 * Son dos pasos en una sola página:
 *
 *   1. La persona ingresa el DNI del titular. Se busca en `padron` y se
 *      cuentan sus hijos menores de la edad tope a la fecha del evento.
 *   2. Si tiene al menos uno, se muestra el formulario con los datos ya
 *      cargados desde el padrón para que sólo confirme o corrija.
 *
 * El paso 1 no es un login: es una verificación de que la familia está en
 * el padrón y le corresponde el evento.
 */

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/mail.php';

session_start();

const MAX_ADULTOS = 6;

$paso      = 'dni';
$error     = null;
$aviso     = null;
$titular   = null;
$ninos     = [];
$enviado   = false;
$form      = ['adultos' => 2, 'ninos' => null, 'telefono' => '', 'email' => '', 'empresa' => ''];

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function limpio(string $k): string
{
    return trim((string) ($_POST[$k] ?? ''));
}

/** Busca al titular y a sus hijos habilitados. Devuelve [titular, ninos]. */
function buscarFamilia(PDO $pdo, string $dni): array
{
    $sql = "SELECT nro_doc, cuil_titular, apellido_nombres, empresa, telefono, email
            FROM   padron
            WHERE  nro_doc = :dni AND parentesco = 'Titular'
            LIMIT  1";
    $st = $pdo->prepare($sql);
    $st->execute([':dni' => $dni]);
    $tit = $st->fetch();

    if (!$tit) {
        // Puede haber puesto el documento de un familiar. Conviene decirlo
        // con precisión en vez de un "no figura" que no ayuda a nadie.
        $st = $pdo->prepare("SELECT titular FROM padron WHERE nro_doc = :dni LIMIT 1");
        $st->execute([':dni' => $dni]);
        $otro = $st->fetch();
        return [null, [], $otro ? $otro['titular'] : null];
    }

    // Hijos e hijastros, más los menores bajo guarda o tutela: todos los
    // parentescos del padrón que representan a un chico a cargo.
    $sql = "SELECT apellido_nombres, fecha_nacimiento,
                   TIMESTAMPDIFF(YEAR, fecha_nacimiento, :corte) AS edad
            FROM   padron
            WHERE  cuil_titular = :cuil
              AND  fecha_nacimiento IS NOT NULL
              AND  (parentesco LIKE 'Hijo%' OR parentesco LIKE 'Menor bajo guarda%')
            HAVING edad < :tope
            ORDER  BY edad";
    $st = $pdo->prepare($sql);
    $st->execute([
        ':corte' => fechaCorte(),
        ':cuil'  => $tit['cuil_titular'],
        ':tope'  => edadTope(),
    ]);

    return [$tit, $st->fetchAll(), null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'La sesión expiró. Volvé a empezar.';
    } else {
        try {
            $pdo = conexion();
            $dni = preg_replace('/\D/', '', limpio('dni'));

            if (strlen($dni) < 6 || strlen($dni) > 9) {
                $error = 'Ingresá un número de documento válido, sin puntos.';
            } else {
                [$titular, $ninos, $otroTitular] = buscarFamilia($pdo, $dni);

                if (!$titular) {
                    $error = $otroTitular
                        ? 'Ese documento figura como familiar. Ingresá el del titular: ' . $otroTitular . '.'
                        : 'No encontramos ese documento en el padrón.';
                } elseif (!$ninos) {
                    $error = 'El evento es para menores de ' . edadTope()
                           . ' años y no figuran chicos de esa edad en tu grupo familiar.';
                } else {
                    $paso = 'form';
                    $form['ninos']    = count($ninos);
                    $form['telefono'] = $titular['telefono'] ?? '';
                    $form['email']    = $titular['email'] ?? '';
                    $form['empresa']  = $titular['empresa'] ?? '';

                    // ── Confirmación del formulario ──────────────────────
                    if (limpio('accion') === 'confirmar') {
                        $adultos  = (int) limpio('cantidad_adultos');
                        $cantidad = (int) limpio('cantidad_ninos');
                        $tel      = limpio('telefono');
                        $mail     = limpio('email');
                        $empresa  = limpio('empresa');

                        $form['adultos']  = $adultos;
                        $form['ninos']    = $cantidad;
                        $form['telefono'] = $tel;
                        $form['email']    = $mail;
                        $form['empresa']  = $empresa;

                        if ($adultos < 1 || $adultos > MAX_ADULTOS) {
                            $error = 'La cantidad de adultos tiene que estar entre 1 y ' . MAX_ADULTOS . '.';
                        } elseif ($cantidad < 1 || $cantidad > count($ninos)) {
                            $error = 'Podés anotar hasta ' . count($ninos)
                                   . ' chico(s), que son los que figuran en tu grupo familiar.';
                        } elseif ($tel === '') {
                            $error = 'Dejanos un teléfono de contacto.';
                        } elseif ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                            $error = 'Revisá el correo, no parece válido.';
                        } else {
                            $st = $pdo->prepare(
                                "INSERT INTO inscripciones_dia_del_nino
                                    (nro_doc, cuil_titular, nombre_titular, empresa,
                                     telefono, email, cantidad_adultos, cantidad_ninos,
                                     ninos_habilitados, ip)
                                 VALUES (:doc, :cuil, :nom, :emp, :tel, :mail,
                                         :ad, :ni, :hab, :ip)
                                 ON DUPLICATE KEY UPDATE
                                     cantidad_adultos = VALUES(cantidad_adultos),
                                     cantidad_ninos   = VALUES(cantidad_ninos),
                                     telefono         = VALUES(telefono),
                                     email            = VALUES(email),
                                     empresa          = VALUES(empresa)"
                            );
                            $st->execute([
                                ':doc'  => $dni,
                                ':cuil' => $titular['cuil_titular'],
                                ':nom'  => $titular['apellido_nombres'],
                                ':emp'  => $empresa ?: null,
                                ':tel'  => $tel,
                                ':mail' => $mail ?: null,
                                ':ad'   => $adultos,
                                ':ni'   => $cantidad,
                                ':hab'  => count($ninos),
                                ':ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
                            ]);
                            $paso  = 'listo';
                            $aviso = $titular['apellido_nombres'];

                            // El mail va después de guardar y su resultado no
                            // afecta a la inscripción: si el servidor de correo
                            // no responde, la persona igual quedó anotada.
                            if ($mail !== '') {
                                $enviado = enviarConfirmacion([
                                    'titular' => $titular['apellido_nombres'],
                                    'email'   => $mail,
                                    'adultos' => $adultos,
                                    'ninos'   => $cantidad,
                                ]);
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('inscripcion: ' . $e->getMessage());
            // Al público no se le muestra el detalle del error: no aporta
            // nada y expone cómo está armada la base. Para probar en local
            // se enciende DEBUG en config.local.php.
            $error = getenv('DEBUG')
                ? 'ERROR: ' . $e->getMessage()
                : 'Hubo un problema al procesar la inscripción. Probá de nuevo en un rato.';
        }
    }
}

function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** "Sábado 19 de septiembre" a partir de la fecha configurada. */
function fechaEnPalabras(): string
{
    $t = strtotime(fechaCorte());
    if (!$t) {
        return '';
    }
    $dias  = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'];
    $meses = ['','enero','febrero','marzo','abril','mayo','junio','julio',
              'agosto','septiembre','octubre','noviembre','diciembre'];
    return $dias[(int) date('w', $t)] . ' ' . (int) date('j', $t)
         . ' de ' . $meses[(int) date('n', $t)];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inscripción — Día del Niño</title>
<style>
  /* Paleta tomada del logo y del flyer del evento: azul institucional,
     verde para la acción y coral para los avisos. */
  :root { --azul:#002449; --verde:#00a170; --coral:#f2867a;
          --texto:#233; --gris:#6b7785; --borde:#dde3ea; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
         background:#eef2f7; color:var(--texto); line-height:1.5;
         display:flex; align-items:flex-start; justify-content:center; padding:28px 16px; }
  .caja { background:#fff; border-radius:14px; box-shadow:0 6px 28px rgba(20,40,80,.10);
          width:100%; max-width:520px; overflow:hidden; }
  .cab { background:linear-gradient(135deg,#002449,#0a4a86); color:#fff; padding:20px 26px 22px; }
  .cab img { height:26px; display:block; margin-bottom:14px; }
  .cab h1 { margin:0; font-size:1.35rem; }
  .cab p  { margin:5px 0 0; opacity:.9; font-size:.88rem; }
  .cuando { margin-top:13px; padding-top:12px; border-top:1px solid rgba(255,255,255,.22);
            font-size:.82rem; line-height:1.7; opacity:.95; }
  .cuando span { display:inline-block; width:19px; }
  .cuerpo { padding:24px 26px 28px; }
  label { display:block; font-size:.82rem; font-weight:600; margin:0 0 5px; }
  input, select { width:100%; padding:10px 12px; border:1px solid var(--borde);
                  border-radius:8px; font-size:.95rem; font-family:inherit; }
  input:focus, select:focus { outline:2px solid #90caf9; border-color:var(--azul); }
  input[readonly] { background:#f5f7fa; color:var(--gris); }
  .campo { margin-bottom:15px; }
  .fila { display:flex; gap:12px; }
  .fila .campo { flex:1; }
  .btn { width:100%; padding:12px; border:none; border-radius:8px; background:var(--verde);
         color:#fff; font-size:1rem; font-weight:700; cursor:pointer; margin-top:6px; }
  .btn:hover { background:#008a5f; }
  .error { background:#fdecea; color:#b3261e; padding:11px 14px; border-radius:8px;
           font-size:.87rem; margin-bottom:16px; }
  .ok { background:#e8f5e9; color:#1b5e20; padding:14px; border-radius:8px; text-align:center; }
  .ayuda { font-size:.78rem; color:var(--gris); margin-top:4px; }
  .chicos { background:#f3f7fc; border-radius:9px; padding:12px 14px; margin-bottom:16px;
            font-size:.85rem; }
  .chicos ul { margin:6px 0 0; padding-left:18px; }
  a.volver { display:block; text-align:center; margin-top:16px; color:var(--azul);
             font-size:.85rem; text-decoration:none; }
</style>
</head>
<body>
<div class="caja">
  <div class="cab">
    <img src="assets/logo-blanco.svg" alt="AEDA — Asociación de Empleados de Despachantes de Aduana">
    <h1>Día de la Niñez</h1>
    <?php if ($paso === 'listo'): ?><p>¡Nos vemos en el festejo!</p><?php endif; ?>
    <div class="cuando">
      <span>&#128197;</span><?= h(fechaEnPalabras()) ?><?php
        if (getenv('HORA_EVENTO')): ?>, <?= h((string) getenv('HORA_EVENTO')) ?><?php endif; ?><br>
      <?php if (getenv('LUGAR_EVENTO')): ?>
        <span>&#128205;</span><?= h((string) getenv('LUGAR_EVENTO')) ?>
      <?php endif; ?>
    </div>
  </div>
  <div class="cuerpo">

    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

    <?php if ($paso === 'listo'): ?>
      <div class="ok">
        <strong><?= h($aviso) ?></strong><br>
        Tu inscripción quedó registrada.<br>
        <span style="font-size:.85rem;">
          <?= (int) $form['adultos'] ?> adulto(s) y <?= (int) $form['ninos'] ?> chico(s).
        </span>
        <?php if ($enviado): ?>
          <div style="font-size:.82rem;margin-top:8px;opacity:.85;">
            Te mandamos la confirmación a <?= h($form['email']) ?>.
          </div>
        <?php endif; ?>
      </div>
      <a class="volver" href="./">Inscribir otro grupo familiar</a>

    <?php elseif ($paso === 'form'): ?>
      <div class="chicos">
        <strong>Chicos habilitados en tu grupo familiar:</strong>
        <ul>
          <?php foreach ($ninos as $n): ?>
            <li><?= h($n['apellido_nombres']) ?> — <?= (int) $n['edad'] ?> años</li>
          <?php endforeach; ?>
        </ul>
      </div>

      <form method="post" autocomplete="on">
        <input type="hidden" name="csrf"   value="<?= h($_SESSION['csrf']) ?>">
        <input type="hidden" name="accion" value="confirmar">
        <input type="hidden" name="dni"    value="<?= h((string) $titular['nro_doc']) ?>">

        <div class="campo">
          <label>Nombre y apellido del titular</label>
          <input type="text" value="<?= h($titular['apellido_nombres']) ?>" readonly>
        </div>

        <div class="fila">
          <div class="campo">
            <label for="ad">Cantidad de adultos</label>
            <select name="cantidad_adultos" id="ad">
              <?php for ($i = 1; $i <= MAX_ADULTOS; $i++): ?>
                <option value="<?= $i ?>" <?= $i === (int) $form['adultos'] ? 'selected' : '' ?>><?= $i ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="campo">
            <label for="ni">Cantidad de niños</label>
            <select name="cantidad_ninos" id="ni">
              <?php for ($i = 1; $i <= count($ninos); $i++): ?>
                <option value="<?= $i ?>" <?= $i === (int) $form['ninos'] ? 'selected' : '' ?>><?= $i ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div class="campo">
          <label for="em">Empresa</label>
          <input type="text" name="empresa" id="em" value="<?= h($form['empresa']) ?>" maxlength="60">
        </div>

        <div class="campo">
          <label for="te">Teléfono</label>
          <input type="tel" name="telefono" id="te" value="<?= h($form['telefono']) ?>" maxlength="40" required>
        </div>

        <div class="campo">
          <label for="ma">Mail</label>
          <input type="email" name="email" id="ma" value="<?= h($form['email']) ?>" maxlength="80">
        </div>

        <button class="btn" type="submit">Confirmar inscripción</button>
      </form>
      <a class="volver" href="./">Usar otro documento</a>

    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
        <div class="campo">
          <label for="dni">Documento del titular</label>
          <input type="text" name="dni" id="dni" inputmode="numeric" maxlength="9"
                 placeholder="Sin puntos" autofocus required>
          <div class="ayuda">
            Buscamos tu grupo familiar en el padrón. El evento es para
            menores de <?= edadTope() ?> años.
          </div>
        </div>
        <button class="btn" type="submit">Continuar</button>
      </form>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
