<?php
/**
 * Regresion del control de fuerza bruta (PHP CLI).
 *
 * Regla verificada (POLITICA_SEGURIDAD.md): el bloqueo es de 5 intentos
 * fallidos en 15 minutos POR USUARIO **E** IP (conjuncion). Los intentos
 * fallidos de otros usuarios desde la misma IP no deben afectar a un
 * usuario legitimo que comparte esa IP (caso ZAP / NAT / proxy inverso).
 *
 * Uso (dentro del contenedor, para tener acceso a la base de datos):
 *
 *     docker compose exec web php tests/fuerza-bruta-test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

const PREFIJO = 'zz-test-';
const IP_A     = '203.0.113.10';
const IP_B     = '198.51.100.20';

$usuario  = PREFIJO . 'ana';
$intrusos = [PREFIJO . 'admin', PREFIJO . 'root', PREFIJO . 'zap', PREFIJO . 'otro', PREFIJO . 'x'];
$fallos   = 0;

function limpiar(): void
{
    db()->prepare('DELETE FROM intentos_login WHERE username LIKE :prefijo')
        ->execute([':prefijo' => PREFIJO . '%']);
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
        ':ua'       => 'phpunit-cli',
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
echo "Control de fuerza bruta por usuario + IP\n";

// Caso 1: fallos de otros usuarios desde mi misma IP no me bloquean.
foreach ($intrusos as $intruso) {
    sembrar($intruso, IP_A, false);
}
comprobar(
    'fallos de otros usuarios desde la misma IP no cuentan',
    0,
    intentos_fallidos_recientes($usuario, IP_A)
);

// Caso 2: mis propios fallos desde esa IP si cuentan.
for ($i = 0; $i < 5; $i++) {
    sembrar($usuario, IP_A, false);
}
comprobar(
    'cinco fallos propios desde la misma IP bloquean',
    MAX_INTENTOS_FALLIDOS,
    intentos_fallidos_recientes($usuario, IP_A)
);

// Caso 3: los fallos desde otra IP no me bloquean en esta IP.
limpiar();
for ($i = 0; $i < 5; $i++) {
    sembrar($usuario, IP_B, false);
}
comprobar(
    'fallos desde otra IP no cuentan en esta IP',
    0,
    intentos_fallidos_recientes($usuario, IP_A)
);

// Caso 4: solo cuenta la ventana de MINUTOS_BLOQUEO.
limpiar();
for ($i = 0; $i < 5; $i++) {
    sembrar($usuario, IP_A, false, MINUTOS_BLOQUEO + 5);
}
comprobar(
    'fallos fuera de la ventana de bloqueo no cuentan',
    0,
    intentos_fallidos_recientes($usuario, IP_A)
);

// Caso 5: los intentos exitosos no cuentan como fallos.
limpiar();
for ($i = 0; $i < 5; $i++) {
    sembrar($usuario, IP_A, true);
}
comprobar(
    'los intentos exitosos no cuentan',
    0,
    intentos_fallidos_recientes($usuario, IP_A)
);

limpiar();
echo $fallos === 0 ? "TODO OK\n" : "$fallos caso(s) con falla\n";
exit($fallos === 0 ? 0 : 1);
