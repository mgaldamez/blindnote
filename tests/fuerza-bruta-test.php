<?php
/**
 * Regresion del control de fuerza bruta (PHP CLI).
 *
 * Reglas verificadas (POLITICA_SEGURIDAD.md):
 *  S1. El bloqueo es de 5 intentos fallidos en 15 minutos POR USUARIO **E** IP
 *      (conjuncion): los fallos de otros usuarios desde la misma IP no afectan
 *      a un usuario legitimo que comparte esa IP (ZAP / NAT / proxy inverso).
 *  S2. El contador es POR CUENTA, no por el texto tipeado: el usuario y el
 *      correo de la misma cuenta comparten un unico balde, asi que alternar
 *      identificadores no duplica los intentos ni esquiva el bloqueo.
 *
 * Uso (dentro del contenedor, para tener acceso a la base de datos):
 *
 *     docker compose exec web php tests/fuerza-bruta-test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// La salida se bufferiza: autenticar() regenera el ID de sesion y en CLI eso
// avisa si ya se imprimio algo.
ob_start();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const PREFIJO = 'zz-test-';
const IP_A     = '203.0.113.10';
const IP_B     = '198.51.100.20';
const CLAVE    = 'Secreta123';

$usuario  = PREFIJO . 'ana';
$correo   = $usuario . '@test.local';
$intrusos = [PREFIJO . 'admin', PREFIJO . 'root', PREFIJO . 'zap', PREFIJO . 'otro', PREFIJO . 'x'];
$fallos   = 0;

function limpiar(): void
{
    db()->prepare('DELETE FROM intentos_login WHERE username LIKE :prefijo')
        ->execute([':prefijo' => PREFIJO . '%']);
}

function limpiar_usuario(string $username): void
{
    db()->prepare('DELETE FROM usuarios WHERE username = :u')->execute([':u' => $username]);
}

function sembrar(string $username, string $ip, bool $exitoso, int $minutosAtras = 1): void
{
    db()->prepare(
        'INSERT INTO intentos_login (username, ip, exitoso, user_agent, fecha)
         VALUES (:username, :ip, :exitoso, :ua, NOW() - INTERVAL :minutos MINUTE)'
    )->execute([
        ':username' => $username,
        ':ip'       => $ip,
        ':exitoso'  => $exitoso ? 1 : 0,
        ':ua'       => 'php-cli-test',
        ':minutos'  => $minutosAtras,
    ]);
}

function comprobar(string $caso, int $esperado, int $obtenido): void
{
    global $fallos;

    if ($esperado === $obtenido) {
        echo "  OK   $caso (esperado $esperado, obtenido $obtenido)\n";
        return;
    }

    $fallos++;
    echo "  FALLA $caso (esperado $esperado, obtenido $obtenido)\n";
}

limpiar();
limpiar_usuario($usuario);

echo "Control de fuerza bruta por cuenta e IP\n";

/* ---------------- S1: por usuario e IP (conjuncion) ---------------- */

// Fallos de otros usuarios desde mi misma IP no me bloquean.
foreach ($intrusos as $intruso) {
    sembrar($intruso, IP_A, false);
}
comprobar(
    'fallos de otros usuarios desde la misma IP no cuentan',
    0,
    intentos_fallidos_recientes([$usuario], IP_A)
);

// Mis propios fallos desde esa IP si cuentan.
for ($i = 0; $i < 5; $i++) {
    sembrar($usuario, IP_A, false);
}
comprobar(
    'cinco fallos propios desde la misma IP bloquean',
    MAX_INTENTOS_FALLIDOS,
    intentos_fallidos_recientes([$usuario], IP_A)
);

// Los fallos desde otra IP no me bloquean en esta IP.
limpiar();
for ($i = 0; $i < 5; $i++) {
    sembrar($usuario, IP_B, false);
}
comprobar(
    'fallos desde otra IP no cuentan en esta IP',
    0,
    intentos_fallidos_recientes([$usuario], IP_A)
);

// Solo cuenta la ventana de MINUTOS_BLOQUEO.
limpiar();
for ($i = 0; $i < 5; $i++) {
    sembrar($usuario, IP_A, false, MINUTOS_BLOQUEO + 5);
}
comprobar(
    'fallos fuera de la ventana de bloqueo no cuentan',
    0,
    intentos_fallidos_recientes([$usuario], IP_A)
);

// Los intentos exitosos no cuentan como fallos.
limpiar();
for ($i = 0; $i < 5; $i++) {
    sembrar($usuario, IP_A, true);
}
comprobar(
    'los intentos exitosos no cuentan',
    0,
    intentos_fallidos_recientes([$usuario], IP_A)
);

/* ---------------- S2: por cuenta (usuario y correo) ---------------- */

// Cinco fallos con el usuario alcanzan para bloquear tambien el correo.
limpiar();
for ($i = 0; $i < 5; $i++) {
    sembrar($usuario, IP_A, false);
}
comprobar(
    'lo fallado con el usuario cuenta al consultar por la cuenta',
    MAX_INTENTOS_FALLIDOS,
    intentos_fallidos_recientes([$usuario, $correo], IP_A)
);

// Repartir los fallos entre usuario y correo no duplica el balde.
limpiar();
for ($i = 0; $i < 3; $i++) {
    sembrar($usuario, IP_A, false);
    sembrar($correo, IP_A, false);
}
comprobar(
    'los fallos por usuario y por correo se suman en un solo balde',
    6,
    intentos_fallidos_recientes([$usuario, $correo], IP_A)
);

// Los intentos exitosos por correo tampoco cuentan.
limpiar();
for ($i = 0; $i < 5; $i++) {
    sembrar($correo, IP_A, true);
}
comprobar(
    'los ingresos correctos por correo no cuentan',
    0,
    intentos_fallidos_recientes([$usuario, $correo], IP_A)
);

/* ---------------- Flujo real: autenticar() ---------------- */

$hash = password_hash(CLAVE, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
db()->prepare(
    'INSERT INTO usuarios (nombre, email, username, password_hash, rol)
     VALUES (:n, :e, :u, :h, :rol)'
)->execute([
    ':n'   => 'Test Ana',
    ':e'   => $correo,
    ':u'   => $usuario,
    ':h'   => $hash,
    ':rol' => 'usuario',
]);

// Con 4 fallos previos por usuario, el correo todavia puede entrar.
limpiar();
for ($i = 0; $i < 4; $i++) {
    sembrar($usuario, '0.0.0.0', false);
}
$r = autenticar($correo, CLAVE);
comprobar('con 4 fallos por usuario el correo todavia entra', 1, $r['ok'] ? 1 : 0);

// Con 5 fallos por usuario, el correo queda bloqueado: misma cuenta.
limpiar();
for ($i = 0; $i < 5; $i++) {
    sembrar($usuario, '0.0.0.0', false);
}
$r = autenticar($correo, CLAVE);
comprobar('con 5 fallos por usuario el correo queda bloqueado', 1, $r['ok'] ? 0 : 1);
comprobar(
    'el mensaje de bloqueo es el esperado',
    1,
    str_contains($r['mensaje'], 'Demasiados intentos fallidos') ? 1 : 0
);

// Y el bloqueo alcanza al usuario escrito de la otra forma.
$r = autenticar($usuario, CLAVE);
comprobar('el usuario escrito tambien queda bloqueado', 1, $r['ok'] ? 0 : 1);

/* ---------------- S3: tope secundario por IP ---------------- */

// Un login correcto sigue registrando ultimo_login (la actualizacion se toco
// al retirar las columnas muertas).
limpiar();
db()->prepare('UPDATE usuarios SET ultimo_login = NULL WHERE username = :u')->execute([':u' => $usuario]);
$r = autenticar($usuario, CLAVE);
comprobar('un login correcto actualiza ultimo_login', 1, ($r['ok'] && db()->query(
    "SELECT ultimo_login FROM usuarios WHERE username = " . db()->quote($usuario)
)->fetch()['ultimo_login'] !== null) ? 1 : 0);

if (MAX_INTENTOS_POR_IP > 0) {
    // Fallos repartidos en cuentas distintas desde la misma IP alcanzan el tope.
    limpiar();
    for ($i = 0; $i < MAX_INTENTOS_POR_IP; $i++) {
        sembrar(PREFIJO . 'spray' . $i, IP_A, false);
    }
    comprobar(
        'el tope por IP suma fallos de cuentas distintas',
        MAX_INTENTOS_POR_IP,
        intentos_fallidos_por_ip(IP_A)
    );
    comprobar(
        'los fallos de otra IP no cuentan para el tope',
        0,
        intentos_fallidos_por_ip(IP_B)
    );

    // Con el tope alcanzado, una cuenta sin fallos propios tampoco entra.
    limpiar();
    for ($i = 0; $i < MAX_INTENTOS_POR_IP; $i++) {
        sembrar(PREFIJO . 'spray' . $i, '0.0.0.0', false);
    }
    $r = autenticar($correo, CLAVE);
    comprobar('el tope por IP bloquea aunque la cuenta no tenga fallos', 1, $r['ok'] ? 0 : 1);
    comprobar(
        'el mensaje sigue siendo el generico de bloqueo',
        1,
        str_contains($r['mensaje'], 'Demasiados intentos fallidos') ? 1 : 0
    );

    // Un fallo menos que el tope todavia deja entrar.
    limpiar();
    for ($i = 0; $i < MAX_INTENTOS_POR_IP - 1; $i++) {
        sembrar(PREFIJO . 'spray' . $i, '0.0.0.0', false);
    }
    $r = autenticar($correo, CLAVE);
    comprobar('con un fallo menos que el tope la cuenta entra', 1, $r['ok'] ? 1 : 0);
} else {
    echo "  SKIP tope por IP deshabilitado (MAX_INTENTOS_POR_IP = 0)\n";
}

/* ---------------- Limpieza ---------------- */

limpiar();
limpiar_usuario($usuario);
echo $fallos === 0 ? "TODO OK\n" : "$fallos caso(s) con falla\n";
ob_end_flush();
exit($fallos === 0 ? 0 : 1);
