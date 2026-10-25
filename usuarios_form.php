<?php
/**
 * FASE 3 - Alta y edicion de usuarios (solo administradores).
 *
 * Sin `id` en la URL crea una cuenta con contrasena temporal; con `id` edita
 * nombre, correo, usuario y rol. Las guardas de auto-bloqueo viven en
 * actualizar_usuario_admin() y crear_usuario_admin() (defensa en profundidad).
 */
require_once __DIR__ . '/includes/init.php';
exigir_rol('admin');

$actorId   = (int) $_SESSION['usuario_id'];
$objetivoId = (int) ($_GET['id'] ?? 0);
$editando  = $objetivoId > 0;
$errores   = [];

$vals = ['nombre' => '', 'email' => '', 'username' => '', 'rol' => 'usuario'];

if ($editando) {
    $stmt = db()->prepare('SELECT id, nombre, email, username, rol FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $objetivoId]);
    $fila = $stmt->fetch();

    if ($fila === false) {
        http_response_code(404);
        die('El usuario no existe.');
    }

    $vals = [
        'nombre'   => (string) $fila['nombre'],
        'email'    => (string) $fila['email'],
        'username' => (string) $fila['username'],
        'rol'      => (string) $fila['rol'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar($_POST['csrf_token'] ?? null);

    $vals = [
        'nombre'   => post('nombre'),
        'email'    => post('email'),
        'username' => post('username'),
        'rol'      => post('rol'),
    ];

    if ($editando) {
        $resultado = actualizar_usuario_admin($vals, $objetivoId, $actorId);
    } else {
        $resultado = crear_usuario_admin(
            $vals + [
                'clave'  => (string) ($_POST['clave'] ?? ''),
                'clave2' => (string) ($_POST['clave_repetir'] ?? ''),
            ],
            $actorId
        );
    }

    if ($resultado['ok']) {
        redirigir('admin.php?ok=' . ($editando ? 'editado' : 'creado'));
    }

    $errores = $resultado['errores'];
    registrar_auditoria(
        $actorId,
        $editando ? 'USUARIO_EDICION_RECHAZADA' : 'USUARIO_ALTA_RECHAZADA',
        'objetivo=' . ($editando ? (string) $objetivoId : 'nuevo')
    );
}

$titulo = $editando ? 'Editar usuario' : 'Nuevo usuario';
require __DIR__ . '/includes/header.php';
?>
<section class="encabezado-seccion">
    <h1><?= $editando ? 'Editar usuario' : 'Nuevo usuario' ?></h1>
    <p>
        <?php if ($editando): ?>
            Cambia los datos de la cuenta. El rol y el estado se controlan por separado.
        <?php else: ?>
            La contrasena que definas es <strong>temporal</strong>: el usuario deberá
            cambiarla la primera vez que inicie sesion.
        <?php endif; ?>
    </p>
</section>

<div class="tarjeta">
    <?php if ($errores): ?>
        <div class="alerta alerta--error">
            <ul>
                <?php foreach ($errores as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post"
          action="usuarios_form.php<?= $editando ? '?id=' . (int) $objetivoId : '' ?>"
          novalidate autocomplete="off">
        <?= csrf_campo() ?>

        <div class="campo">
            <label for="nombre">Nombre completo</label>
            <input type="text" id="nombre" name="nombre" maxlength="100"
                   value="<?= e($vals['nombre']) ?>" required>
        </div>

        <div class="campo">
            <label for="email">Correo electronico</label>
            <input type="email" id="email" name="email" maxlength="150"
                   value="<?= e($vals['email']) ?>" required>
        </div>

        <div class="campo">
            <label for="username">Usuario</label>
            <input type="text" id="username" name="username" maxlength="50"
                   value="<?= e($vals['username']) ?>" required
                   pattern="[a-zA-Z0-9_.]{3,50}">
            <small>Letras, numeros, punto y guion bajo (3 a 50).</small>
        </div>

        <div class="campo">
            <label for="rol">Rol</label>
            <select id="rol" name="rol">
                <option value="usuario" <?= $vals['rol'] === 'usuario' ? 'selected' : '' ?>>usuario</option>
                <option value="admin" <?= $vals['rol'] === 'admin' ? 'selected' : '' ?>>admin</option>
            </select>
            <?php if ($editando && $objetivoId === $actorId): ?>
                <small>No podes quitarte tu propio rol de administrador.</small>
            <?php endif; ?>
        </div>

        <?php if (!$editando): ?>
            <div class="campo">
                <label for="clave">Contrasena temporal</label>
                <input type="password" id="clave" name="clave" minlength="<?= PASS_MIN_LEN ?>"
                       maxlength="128" required autocomplete="new-password">
                <small>Minimo <?= PASS_MIN_LEN ?> caracteres, con mayuscula, minuscula y numero.</small>
            </div>

            <div class="campo">
                <label for="clave_repetir">Repetir contrasena temporal</label>
                <input type="password" id="clave_repetir" name="clave_repetir"
                       minlength="<?= PASS_MIN_LEN ?>" maxlength="128" required autocomplete="new-password">
            </div>
        <?php endif; ?>

        <div class="campo campo--acciones">
            <button type="submit" class="btn btn--primario">
                <?= $editando ? 'Guardar cambios' : 'Crear usuario' ?>
            </button>
            <a class="btn" href="admin.php">Cancelar</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
