<?php
/**
 * FASE 3 - Formulario de inicio de sesion con validacion.
 * Mitiga: inyeccion SQL (PDO), CSRF (token), fuerza bruta (bloqueo),
 *         fijacion de sesion (regeneracion de ID), XSS (escape de salida).
 */
require_once __DIR__ . '/includes/init.php';

if (esta_autenticado()) {
    redirigir('dashboard.php');
}

$errores = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) Verificar token CSRF.
    csrf_verificar($_POST['csrf_token'] ?? null);

    // 2) Validacion del lado del servidor.
    $username = post('username');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '') {
        $errores[] = 'Ingresa tu usuario o correo.';
    }
    if ($password === '') {
        $errores[] = 'Ingresa tu contrasena.';
    }

    // 3) Autenticacion.
    if (!$errores) {
        $r = autenticar($username, $password);
        if ($r['ok']) {
            redirigir('dashboard.php');
        }
        $errores[] = $r['mensaje'];
    }
}

// Mensajes informativos por querystring.
$aviso = $_GET['aviso'] ?? '';
$mensajeAviso = match ($aviso) {
    'debes_iniciar_sesion' => 'Debes iniciar sesion para acceder a esa seccion.',
    'sesion_expirada'      => 'Tu sesion expiro por inactividad. Vuelve a iniciar sesion.',
    'registrado'           => 'Cuenta creada correctamente. Ya puedes iniciar sesion.',
    'logout'               => 'Has cerrado sesion correctamente.',
    default                => '',
};

$titulo = 'Iniciar sesion';
require __DIR__ . '/includes/header.php';
?>

<section class="auth">
    <div class="tarjeta auth__card">
        <h1 class="auth__titulo">Iniciar sesion</h1>
        <p class="auth__subtitulo">Acceso seguro al portal &mdash; <?= e(APP_NOMBRE) ?></p>

        <?php if ($mensajeAviso): ?>
            <div class="alerta alerta--info"><?= e($mensajeAviso) ?></div>
        <?php endif; ?>

        <?php if ($errores): ?>
            <div class="alerta alerta--error">
                <ul>
                    <?php foreach ($errores as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate autocomplete="off">
            <?= csrf_campo() ?>

            <div class="campo">
                <label for="username">Usuario o correo</label>
                <input type="text" id="username" name="username"
                       value="<?= e($username) ?>" maxlength="150"
                       required autofocus autocomplete="username">
            </div>

            <div class="campo">
                <label for="password">Contrasena</label>
                <input type="password" id="password" name="password"
                       minlength="<?= PASS_MIN_LEN ?>" maxlength="128"
                       required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn--primario btn--block">Entrar</button>
        </form>

        <p class="auth__pie">
            &iquest;No tienes cuenta? <a href="register.php">Registrate</a>
        </p>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
