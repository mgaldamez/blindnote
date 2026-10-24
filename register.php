<?php
/**
 * FASE 3 - Registro de usuarios.
 * Mitiga: inyeccion SQL (PDO preparado), CSRF, XSS (escape),
 *         contrasenas debiles (politica) y almacenamiento inseguro (bcrypt).
 */
require_once __DIR__ . '/includes/init.php';

if (esta_autenticado()) {
    redirigir('dashboard.php');
}

$errores = [];
$vals = ['nombre' => '', 'email' => '', 'username' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar($_POST['csrf_token'] ?? null);

    $vals['nombre']   = post('nombre');
    $vals['email']    = post('email');
    $vals['username'] = post('username');
    $password         = (string) ($_POST['password'] ?? '');
    $password2        = (string) ($_POST['password2'] ?? '');

    if ($vals['nombre'] === '' || mb_strlen($vals['nombre']) < 3) {
        $errores[] = 'El nombre debe tener al menos 3 caracteres.';
    }
    if (!filter_var($vals['email'], FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo electronico no es valido.';
    }
    if (!preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $vals['username'])) {
        $errores[] = 'El usuario solo admite letras, numeros, punto y guion bajo (3-50).';
    }

    foreach (validar_contrasena($password) as $e) {
        $errores[] = $e;
    }
    if ($password !== $password2) {
        $errores[] = 'Las contrasenas no coinciden.';
    }

    // Comprobar duplicados con consultas preparadas.
    if (!$errores) {
        $stmt = db()->prepare('SELECT id FROM usuarios WHERE username = :u OR email = :e LIMIT 1');
        $stmt->execute([':u' => $vals['username'], ':e' => $vals['email']]);
        if ($stmt->fetch()) {
            $errores[] = 'Ese usuario o correo ya esta registrado.';
        }
    }

    // Alta segura.
    if (!$errores) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $stmt = db()->prepare(
            'INSERT INTO usuarios (nombre, email, username, password_hash, rol)
             VALUES (:nombre, :email, :username, :hash, :rol)'
        );
        $stmt->execute([
            ':nombre'   => $vals['nombre'],
            ':email'    => $vals['email'],
            ':username' => $vals['username'],
            ':hash'     => $hash,
            // El primer usuario registrado es admin; el resto, usuario.
            ':rol'      => (int) db()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0 ? 'admin' : 'usuario',
        ]);
        registrar_auditoria((int) db()->lastInsertId(), 'REGISTRO', 'Nuevo usuario: ' . $vals['username']);
        redirigir('login.php?aviso=registrado');
    }
}

$titulo = 'Crear cuenta';
require __DIR__ . '/includes/header.php';
?>
<section class="auth">
    <div class="tarjeta auth__card">
        <h1 class="auth__titulo">Crear cuenta</h1>
        <p class="auth__subtitulo">La contrasena se guarda cifrada con bcrypt</p>

        <?php if ($errores): ?>
            <div class="alerta alerta--error">
                <ul>
                    <?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="register.php" novalidate autocomplete="off">
            <?= csrf_campo() ?>
            <div class="campo">
                <label for="nombre">Nombre completo</label>
                <input type="text" id="nombre" name="nombre" value="<?= e($vals['nombre']) ?>" maxlength="100" required>
            </div>
            <div class="campo">
                <label for="email">Correo electronico</label>
                <input type="email" id="email" name="email" value="<?= e($vals['email']) ?>" maxlength="150" required>
            </div>
            <div class="campo">
                <label for="username">Nombre de usuario</label>
                <input type="text" id="username" name="username" value="<?= e($vals['username']) ?>" maxlength="50" required>
            </div>
            <div class="campo">
                <label for="password">Contrasena</label>
                <input type="password" id="password" name="password" minlength="<?= PASS_MIN_LEN ?>" maxlength="128" required>
                <small class="ayuda">Minimo <?= PASS_MIN_LEN ?> caracteres, con mayuscula, minuscula y numero.</small>
            </div>
            <div class="campo">
                <label for="password2">Repetir contrasena</label>
                <input type="password" id="password2" name="password2" minlength="<?= PASS_MIN_LEN ?>" maxlength="128" required>
            </div>
            <button type="submit" class="btn btn--primario btn--block">Registrarme</button>
        </form>

        <p class="auth__pie">&iquest;Ya tienes cuenta? <a href="login.php">Inicia sesion</a></p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
