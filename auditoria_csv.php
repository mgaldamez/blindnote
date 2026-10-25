<?php
/**
 * Exportacion CSV de la auditoria (Fase 5: evidencias).
 *
 * Solo administradores. Respeta los filtros de la pantalla y exporta todas las
 * filas que coinciden (no solo la pagina visible). Los valores pasan por
 * campo_csv(), que neutraliza formulas (CSV injection) y entrecomilla lo que
 * corresponde segun RFC 4180.
 */
require_once __DIR__ . '/includes/init.php';
exigir_rol('admin');

$filtros = [
    'accion'  => (string) ($_GET['accion'] ?? ''),
    'usuario' => (string) ($_GET['usuario'] ?? ''),
    'desde'   => (string) ($_GET['desde'] ?? ''),
    'hasta'   => (string) ($_GET['hasta'] ?? ''),
];

$filas = filas_auditoria_para_csv($filtros);

// La exportacion tambien queda auditada (con los filtros usados).
registrar_auditoria(
    (int) $_SESSION['usuario_id'],
    'AUDITORIA_EXPORTADA',
    'eventos=' . count($filas)
);

// El nombre del archivo lo arma el servidor: no hay entrada del usuario en la
// cabecera, asi que no puede inyectarse nada.
$nombre = 'auditoria-' . date('Ymd-Hi') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Cache-Control: no-store');

echo linea_csv(['fecha', 'usuario', 'accion', 'detalle', 'ip']);

foreach ($filas as $fila) {
    echo linea_csv([
        $fila['fecha'],
        $fila['username'] ?? 'anonimo',
        $fila['accion'],
        $fila['detalle'],
        $fila['ip'],
    ]);
}

exit;
