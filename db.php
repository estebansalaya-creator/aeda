<?php
/**
 * Conexión a MySQL.
 *
 * Railway publica las credenciales de dos formas según cómo se haya
 * enlazado el servicio: una URL completa en MYSQL_URL, o las variables
 * sueltas MYSQLHOST / MYSQLUSER / MYSQLPASSWORD / MYSQLDATABASE / MYSQLPORT.
 * Se aceptan las dos para que ande sin tener que tocar nada al desplegar.
 */

declare(strict_types=1);

// Para probar en la máquina local, sin tener que definir variables de
// entorno en Windows: si existe config.local.php, lo que declare ahí se
// carga como si Railway lo hubiera inyectado. El archivo está en el
// .gitignore, así que no se sube.
$_local = __DIR__ . '/config.local.php';
if (is_file($_local)) {
    foreach ((require $_local) as $k => $v) {
        if (getenv($k) === false) {
            putenv("$k=$v");
        }
    }
}

function conexion(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $url = getenv('MYSQL_URL') ?: getenv('DATABASE_URL');

    if ($url) {
        $p    = parse_url($url);
        $host = $p['host'] ?? 'localhost';
        $port = $p['port'] ?? 3306;
        $user = urldecode($p['user'] ?? '');
        $pass = urldecode($p['pass'] ?? '');
        $base = ltrim($p['path'] ?? '', '/');
    } else {
        $host = getenv('MYSQLHOST')     ?: 'localhost';
        $port = getenv('MYSQLPORT')     ?: 3306;
        $user = getenv('MYSQLUSER')     ?: 'root';
        $pass = getenv('MYSQLPASSWORD') ?: '';
        $base = getenv('MYSQLDATABASE') ?: 'sindicato';
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                   $host, (int) $port, $base);

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/**
 * Fecha del evento. Con ella se calcula la edad de los chicos, así que un
 * valor equivocado deja gente afuera o de más.
 *
 * El valor por omisión es la fecha real del festejo, para que la aplicación
 * muestre algo correcto aunque falte definir la variable en el entorno.
 */
function fechaCorte(): string
{
    return getenv('FECHA_EVENTO') ?: '2026-09-19';
}

/** Edad tope, inclusive: 16 significa "hasta 16 años cumplidos". */
function edadTope(): int
{
    return (int) (getenv('EDAD_TOPE') ?: 16);
}
