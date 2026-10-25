<?php
/**
 * FASE 3 - Control de acceso por roles (RBAC) y administracion de usuarios.
 * Solo los administradores pueden entrar aqui. Se demuestra la defensa
 * en profundidad: aunque alguien fuerce la URL, exigir_rol() lo bloquea.
 *
 * Listado paginado con busqueda y filtros: todos los valores de entrada se
 * sanean y la consulta se arma con sentencias preparadas.
 */
require_once __DIR__ . '/includes/init.php';
exigir_rol('admin');

// ------------------------- Filtros y paginacion -------------------------
$busqueda = trim((string) ($_GET['q'] ?? ''));

$rolFiltro = (string) ($_GET['rol'] ?? '');
if (!in_array($rolFiltro, ['admin', 'usuario'], true)) {
    $rolFiltro = '';
}

$estadoFiltro = (string) ($_GET['estado'] ?? 'todos');
if (!in_array($estadoFiltro, ['activos', 'inactivos', 'todos'], true)) {
    $estadoFiltro = 'todos';
}

$paginaSolicitada = max(1, (int) ($_GET['pagina'] ?? 1));

$listado = listar_usuarios(
    ['busqueda' => $busqueda, 'rol' => $rolFiltro, 'estado' => $estadoFiltro],
    $paginaSolicitada
);
$usuarios     = $listado['usuarios'];
$total        = $listado['total'];
$pagina       = $listado['pagina'];
$totalPaginas = $listado['totalPaginas'];
$desde        = $total === 0 ? 0 : (($pagina - 1) * $listado['porPagina']) + 1;
$hasta        = $desde === 0 ? 0 : $desde + count($usuarios) - 1;

/**
 * Construye el enlace de una pagina conservando los filtros activos.
 */
$enlacePagina = static function (int $numero) use ($busqueda, $rolFiltro, $estadoFiltro): string {
    $parametros = ['pagina' => $numero];
    if ($busqueda !== '') {
        $parametros['q'] = $busqueda;
    }
    if ($rolFiltro !== '') {
        $parametros['rol'] = $rolFiltro;
    }
    if ($estadoFiltro !== 'todos') {
        $parametros['estado'] = $estadoFiltro;
    }

    return 'admin.php?' . http_build_query($parametros);
};

$logs = db()->query(
    'SELECT l.fecha, l.accion, l.detalle, l.ip, u.username
       FROM logs_auditoria l
       LEFT JOIN usuarios u ON u.id = l.usuario_id
      ORDER BY l.id DESC
      LIMIT 15'
)->fetchAll();

$titulo = 'Administracion';
require __DIR__ . '/includes/header.php';
?>
<section class="encabezado-seccion">
    <h1>Administracion</h1>
    <p>Area restringida a usuarios con rol <strong>admin</strong>.</p>
</section>

<?php
$aviso = (string) ($_GET['ok'] ?? '');
$mensajesAviso = [
    'creado'   => 'Usuario creado. Tendra que cambiar la contrasena en su primer login.',
    'editado'  => 'Usuario actualizado.',
    'activado' => 'Usuario reactivado.',
    'desactivado' => 'Usuario desactivado (soft delete): conserva su historial.',
];
?>
<?php if (isset($mensajesAviso[$aviso])): ?>
    <div class="alerta alerta--info"><?= e($mensajesAviso[$aviso]) ?></div>
<?php endif; ?>

<div class="tarjeta">
    <div class="tarjeta__cabecera">
        <h2>Usuarios (<?= (int) $total ?>)</h2>
        <a class="btn btn--primario" href="usuarios_form.php">Nuevo usuario</a>
    </div>

    <form method="get" action="admin.php" class="filtros">
        <div class="campo">
            <label for="q">Buscar</label>
            <input type="search" id="q" name="q" maxlength="150"
                   value="<?= e($busqueda) ?>" placeholder="nombre, usuario o correo">
        </div>
        <div class="campo">
            <label for="rol">Rol</label>
            <select id="rol" name="rol">
                <option value="" <?= $rolFiltro === '' ? 'selected' : '' ?>>Todos</option>
                <option value="admin" <?= $rolFiltro === 'admin' ? 'selected' : '' ?>>admin</option>
                <option value="usuario" <?= $rolFiltro === 'usuario' ? 'selected' : '' ?>>usuario</option>
            </select>
        </div>
        <div class="campo">
            <label for="estado">Estado</label>
            <select id="estado" name="estado">
                <option value="todos" <?= $estadoFiltro === 'todos' ? 'selected' : '' ?>>Todos</option>
                <option value="activos" <?= $estadoFiltro === 'activos' ? 'selected' : '' ?>>Activos</option>
                <option value="inactivos" <?= $estadoFiltro === 'inactivos' ? 'selected' : '' ?>>Inactivos</option>
            </select>
        </div>
        <div class="campo campo--acciones">
            <button type="submit" class="btn btn--primario">Filtrar</button>
            <a class="btn" href="admin.php">Limpiar</a>
        </div>
    </form>

    <?php if (!$usuarios): ?>
        <div class="alerta alerta--info">No hay usuarios que coincidan con el filtro.</div>
    <?php else: ?>
        <div class="tabla-wrap">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>#</th><th>Nombre</th><th>Usuario</th><th>Correo</th>
                        <th>Rol</th><th>Activo</th><th>Ultimo login</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td><?= (int) $u['id'] ?></td>
                        <td><?= e($u['nombre']) ?></td>
                        <td><?= e($u['username']) ?></td>
                        <td><?= e($u['email']) ?></td>
                        <td><span class="badge badge--<?= e($u['rol']) ?>"><?= e($u['rol']) ?></span></td>
                        <td><?= ((int) $u['activo'] === 1) ? 'Si' : 'No' ?></td>
                        <td><?= e((string) ($u['ultimo_login'] ?? '—')) ?></td>
                        <td class="tabla__acciones">
                            <a class="btn" href="usuarios_form.php?id=<?= (int) $u['id'] ?>">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <nav class="paginacion" aria-label="Paginacion de usuarios">
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

<div class="tarjeta">
    <h2>Auditoria reciente</h2>
    <div class="tabla-wrap">
        <table class="tabla">
            <thead>
                <tr><th>Fecha</th><th>Usuario</th><th>Accion</th><th>Detalle</th><th>IP</th></tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td><?= e((string) $l['fecha']) ?></td>
                    <td><?= e((string) ($l['username'] ?? 'anonimo')) ?></td>
                    <td><?= e($l['accion']) ?></td>
                    <td><?= e((string) $l['detalle']) ?></td>
                    <td><?= e((string) $l['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
