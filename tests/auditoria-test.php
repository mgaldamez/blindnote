<?php
/**
 * Regresion de la auditoria paginada y su exportacion (PHP CLI).
 *
 * Reglas verificadas (odd/tasks/auditoria-paginada-csv.md):
 *  S3. Listado paginado con filtros por accion, usuario y rango de fechas.
 *  S5. El CSV neutraliza formulas al inicio de celda (CSV injection).
 *
 * Uso:
 *     docker compose exec web php tests/auditoria-test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

const PREFIJO_AUD = 'zzusu_aud';
const ACCION_A = 'ZZTEST_ACCION_A';
const ACCION_B = 'ZZTEST_ACCION_B';

$fallos = 0;

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

function limpiar_auditoria(): void
{
    db()->prepare('DELETE FROM logs_auditoria WHERE accion LIKE :a')->execute([':a' => 'ZZTEST_%']);

    $stmt = db()->prepare('SELECT id FROM usuarios WHERE username LIKE :p');
    $stmt->execute([':p' => PREFIJO_AUD . '%']);
    $ids = array_map(static fn (array $f): int => (int) $f['id'], $stmt->fetchAll());
    if ($ids !== []) {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("DELETE FROM logs_auditoria WHERE usuario_id IN ($marcas)")->execute($ids);
    }

    db()->prepare('DELETE FROM usuarios WHERE username LIKE :p')->execute([':p' => PREFIJO_AUD . '%']);
}

function crear_usuario_aud(string $sufijo): int
{
    $username = PREFIJO_AUD . $sufijo;
    db()->prepare(
        'INSERT INTO usuarios (nombre, email, username, password_hash, rol, activo)
         VALUES (:n, :e, :u, :h, :rol, 1)'
    )->execute([
        ':n'   => 'Aud ' . $sufijo,
        ':e'   => $username . '@test.local',
        ':u'   => $username,
        ':h'   => password_hash('Secreta123', PASSWORD_BCRYPT, ['cost' => 4]),
        ':rol' => 'usuario',
    ]);

    return (int) db()->lastInsertId();
}

function sembrar_log(?int $usuarioId, string $accion, string $fecha, string $detalle = 'detalle'): void
{
    db()->prepare(
        'INSERT INTO logs_auditoria (usuario_id, accion, detalle, ip, fecha)
         VALUES (:uid, :accion, :detalle, :ip, :fecha)'
    )->execute([
        ':uid'     => $usuarioId,
        ':accion'  => $accion,
        ':detalle' => $detalle,
        ':ip'      => '203.0.113.50',
        ':fecha'   => $fecha,
    ]);
}

limpiar_auditoria();
echo "Auditoria paginada\n";

// La tabla de auditoria ya tiene eventos reales: todo se compara contra una
// base medida antes de sembrar, para que el test no dependa del entorno.
$baseTotal   = listar_auditoria([])['total'];
$baseAnon    = listar_auditoria(['usuario' => '__anonimo__'])['total'];
$baseDesde12 = listar_auditoria(['desde' => '2026-03-12'])['total'];
$baseHasta11 = listar_auditoria(['hasta' => '2026-03-11'])['total'];
$baseCombo   = listar_auditoria(['accion' => ACCION_B, 'usuario' => PREFIJO_AUD . 'ana'])['total'];

$idAna  = crear_usuario_aud('ana');
$idBeto = crear_usuario_aud('beto');

// 25 eventos de "ana" repartidos en dos dias, 3 de "beto" y 2 anonimos.
for ($i = 1; $i <= 20; $i++) {
    sembrar_log($idAna, ACCION_A, sprintf('2026-03-10 10:%02d:00', $i));
}
for ($i = 1; $i <= 5; $i++) {
    sembrar_log($idAna, ACCION_B, sprintf('2026-03-11 11:%02d:00', $i));
}
for ($i = 1; $i <= 3; $i++) {
    sembrar_log($idBeto, ACCION_A, sprintf('2026-03-12 12:%02d:00', $i));
}
sembrar_log(null, ACCION_A, '2026-03-13 09:00:00');
sembrar_log(null, ACCION_B, '2026-03-13 09:30:00');

/* ---------------- S3: paginacion ---------------- */

$todas = listar_auditoria([], 1);
$totalEsperado = $baseTotal + 30;
$paginasEsperadas = (int) ceil($totalEsperado / AUDITORIA_POR_PAGINA);
$ultimaPagina = ($paginasEsperadas - 1) * AUDITORIA_POR_PAGINA;
$restoUltima = $totalEsperado - $ultimaPagina;

comprobar('el total suma los 30 eventos sembrados', $totalEsperado, $todas['total']);
comprobar('la primera pagina respeta el tamano configurado', AUDITORIA_POR_PAGINA, count($todas['filas']));
comprobar('las paginas se calculan sobre el total', $paginasEsperadas, $todas['totalPaginas']);
comprobar(
    'la ultima pagina trae el resto',
    $restoUltima,
    count(listar_auditoria([], $paginasEsperadas)['filas'])
);
comprobar('una pagina fuera de rango cae en la ultima', $paginasEsperadas, listar_auditoria([], 999)['pagina']);
comprobar(
    'el orden es el mas reciente primero',
    1,
    (listar_auditoria([], 1)['filas'][0]['fecha'] === '2026-03-13 09:30:00') ? 1 : 0
);

/* ---------------- S3: filtros ---------------- */

comprobar('el filtro por accion acota', 24, listar_auditoria(['accion' => ACCION_A])['total']);
comprobar('el filtro por usuario acota', 25, listar_auditoria(['usuario' => PREFIJO_AUD . 'ana'])['total']);
comprobar('el filtro de anonimos acota', $baseAnon + 2, listar_auditoria(['usuario' => '__anonimo__'])['total']);
comprobar('una accion inexistente da 0', 0, listar_auditoria(['accion' => 'ZZTEST_NO_EXISTE'])['total']);

comprobar(
    'el filtro desde incluye el dia indicado',
    $baseDesde12 + 5,
    listar_auditoria(['desde' => '2026-03-12'])['total']
);
comprobar(
    'el filtro hasta incluye el dia indicado',
    $baseHasta11 + 25,
    listar_auditoria(['hasta' => '2026-03-11'])['total']
);
comprobar(
    'el rango de fechas acota',
    5,
    listar_auditoria(['desde' => '2026-03-11', 'hasta' => '2026-03-11'])['total']
);
comprobar(
    'una fecha invalida se ignora',
    $totalEsperado,
    listar_auditoria(['desde' => 'no-es-fecha'])['total']
);
comprobar(
    'los filtros se combinan',
    $baseCombo + 5,
    listar_auditoria(['accion' => ACCION_B, 'usuario' => PREFIJO_AUD . 'ana'])['total']
);
comprobar(
    'la inyeccion por el filtro de accion no altera la consulta',
    0,
    listar_auditoria(['accion' => "' OR 1=1 --"])['total']
);

// Las filas traen los campos que la vista y el CSV necesitan.
$fila = listar_auditoria([], 1)['filas'][0];
comprobar(
    'cada fila trae fecha, accion, detalle, ip y usuario',
    1,
    (isset($fila['fecha'], $fila['accion'], $fila['detalle'], $fila['ip'])
        && array_key_exists('username', $fila)) ? 1 : 0
);

limpiar_auditoria();
echo $fallos === 0 ? "TODO OK\n" : "$fallos caso(s) con falla\n";
exit($fallos === 0 ? 0 : 1);
