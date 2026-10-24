<?php
/**
 * FASE 3 - Control de acceso por roles (RBAC).
 * Solo los administradores pueden entrar aqui. Se demuestra la defensa
 * en profundidad: aunque alguien fuerce la URL, exigir_rol() lo bloquea.
 */
require_once __DIR__ . '/includes/init.php';
exigir_rol('admin');

$usuarios = db()->query(
    'SELECT id, nombre, username, email, rol, activo, ultimo_login, creado_en
       FROM usuarios ORDER BY id'
)->fetchAll();

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

<div class="tarjeta">
    <h2>Usuarios (<?= count($usuarios) ?>)</h2>
    <div class="tabla-wrap">
        <table class="tabla">
            <thead>
                <tr>
                    <th>#</th><th>Nombre</th><th>Usuario</th><th>Correo</th>
                    <th>Rol</th><th>Activo</th><th>Ultimo login</th>
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
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
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
