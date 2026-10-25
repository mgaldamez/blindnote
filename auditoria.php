<?php
/**
 * Auditoria del sistema (Fase 5: registro y monitoreo).
 *
 * Solo administradores. Listado paginado con filtros por accion, usuario y
 * rango de fechas; todos los filtros se sanean y la consulta se arma con
 * sentencias preparadas.
 */
require_once __DIR__ . '/includes/init.php';
exigir_rol('admin');

$filtros = [
    'accion'  => (string) ($_GET['accion'] ?? ''),
    'usuario' => (string) ($_GET['usuario'] ?? ''),
    'desde'   => (string) ($_GET['desde'] ?? ''),
    'hasta'   => (string) ($_GET['hasta'] ?? ''),
];
$paginaSolicitada = max(1, (int) ($_GET['pagina'] ?? 1));

$listado      = listar_auditoria($filtros, $paginaSolicitada);
$filas        = $listado['filas'];
$total        = $listado['total'];
$pagina       = $listado['pagina'];
$totalPaginas = $listado['totalPaginas'];
$desde        = $total === 0 ? 0 : (($pagina - 1) * $listado['porPagina']) + 1;
$hasta        = $desde === 0 ? 0 : $desde + count($filas) - 1;

/**
 * Enlace que conserva los filtros activos.
 */
$enlace = static function (array $extra = []) use ($filtros): string {
    $parametros = array_filter(
        $filtros + $extra,
        static fn (string $valor): bool => $valor !== ''
    );

    return 'auditoria.php' . ($parametros === [] ? '' : '?' . http_build_query($parametros));
};

$enlacePagina = static fn (int $numero): string => $enlace(['pagina' => (string) $numero]);

$acciones = acciones_auditoria();
$usuariosAuditados = usuarios_auditoria();

$titulo = 'Auditoria';
require __DIR__ . '/includes/header.php';
?>
<section class="encabezado-seccion">
    <h1>Auditoria</h1>
    <p>Registro de eventos del sistema. Solo visible para administradores.</p>
</section>

<div class="tarjeta">
    <div class="tarjeta__cabecera">
        <h2>Eventos (<?= (int) $total ?>)</h2>
    </div>

    <form method="get" action="auditoria.php" class="filtros">
        <div class="campo">
            <label for="accion">Accion</label>
            <select id="accion" name="accion">
                <option value="">Todas</option>
                <?php foreach ($acciones as $accion): ?>
                    <option value="<?= e($accion) ?>" <?= $filtros['accion'] === $accion ? 'selected' : '' ?>>
                        <?= e($accion) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="campo">
            <label for="usuario">Usuario</label>
            <select id="usuario" name="usuario">
                <option value="">Todos</option>
                <option value="__anonimo__" <?= $filtros['usuario'] === '__anonimo__' ? 'selected' : '' ?>>
                    (sin usuario)
                </option>
                <?php foreach ($usuariosAuditados as $usuario): ?>
                    <option value="<?= e($usuario) ?>" <?= $filtros['usuario'] === $usuario ? 'selected' : '' ?>>
                        <?= e($usuario) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="campo">
            <label for="desde">Desde</label>
            <input type="date" id="desde" name="desde" value="<?= e($filtros['desde']) ?>">
        </div>

        <div class="campo">
            <label for="hasta">Hasta</label>
            <input type="date" id="hasta" name="hasta" value="<?= e($filtros['hasta']) ?>">
        </div>

        <div class="campo campo--acciones">
            <button type="submit" class="btn btn--primario">Filtrar</button>
            <a class="btn" href="auditoria.php">Limpiar</a>
        </div>
    </form>

    <?php if (!$filas): ?>
        <div class="alerta alerta--info">No hay eventos que coincidan con el filtro.</div>
    <?php else: ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr><th>Fecha</th><th>Usuario</th><th>Accion</th><th>Detalle</th><th>IP</th></tr>
                </thead>
                <tbody>
                <?php foreach ($filas as $fila): ?>
                    <tr>
                        <td><?= e((string) $fila['fecha']) ?></td>
                        <td><?= e((string) ($fila['username'] ?? 'anonimo')) ?></td>
                        <td><?= e($fila['accion']) ?></td>
                        <td><?= e((string) $fila['detalle']) ?></td>
                        <td><?= e((string) $fila['ip']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <nav class="paginacion" aria-label="Paginacion de auditoria">
            <span class="paginacion__resumen">
                Mostrando <?= (int) $desde ?>&ndash;<?= (int) $hasta ?> de <?= (int) $total ?>
                (pagina <?= (int) $pagina ?> de <?= (int) $totalPaginas ?>)
            </span>

            <?php if ($totalPaginas > 1): ?>
                <span class="paginacion__enlaces">
                    <?php if ($pagina > 1): ?>
                        <a class="btn" href="<?= e($enlacePagina($pagina - 1)) ?>" rel="prev">&laquo; Anterior</a>
                    <?php endif; ?>

                    <?php for ($n = 1; $n <= $totalPaginas; $n++): ?>
                        <?php if ($n === $pagina): ?>
                            <span class="paginacion__actual" aria-current="page"><?= $n ?></span>
                        <?php else: ?>
                            <a class="btn" href="<?= e($enlacePagina($n)) ?>"><?= $n ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($pagina < $totalPaginas): ?>
                        <a class="btn" href="<?= e($enlacePagina($pagina + 1)) ?>" rel="next">Siguiente &raquo;</a>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
