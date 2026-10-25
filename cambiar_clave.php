<?php
/**
 * Cambio de contrasena (Fase 3).
 *
 * Se llega aca de dos formas:
 *  - Obligatorio: la cuenta tiene una contrasena temporal (`debe_cambiar_clave`)
 *    y `exigir_clave_al_dia()` redirige a esta pantalla en cada intento de
 *    entrar a otra seccion.
 *  - Voluntario: el usuario decide cambiarla.
 *
 * Mitiga: CSRF (token), fuerza bruta (politica de contrasenas), sesion fijada
 * (se regenera el ID tras el cambio) y auditoria del evento.
 */
require_once __DIR__ . '/includes/init.php';
exigir_autenticacion();

$usuarioId = (int) $_SESSION['usuario_id'];
$obligatorio = necesita_cambiar_clave();
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar($_POST['csrf_token'] ?? null);

    $actual = (string) ($_POST['clave_actual'] ?? '');
    $nueva  = (string) ($_POST['clave_nueva'] ?? '');
    $repetir = (string) ($_POST['clave_repetir'] ?? '');

    $stmt = db()->prepare('SELECT password_hash FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $usuarioId]);
    $hashActual = (string) ($stmt->fetch()['password_hash'] ?? '');

    if ($actual === '') {
        $errores[] = 'Ingresa tu contrasena actual.';
    } elseif ($hashActual === '' || !password_verify($actual, $hashActual)) {
        $errores[] = 'La contrasena actual no es correcta.';
        registrar_auditoria($usuarioId, 'CLAVE_FALLIDA', 'contrasena actual incorrecta');
    }

    foreach (validar_contrasena($nueva) as $e) {
        $errores[] = $e;
    }
    if ($nueva !== $repetir) {
        $errores[] = 'Las contrasenas nuevas no coinciden.';
    }
    if ($nueva !== '' && password_verify($nueva, $hashActual)) {
        $errores[] = 'La contrasena nueva debe ser distinta de la actual.';
    }

    if (!$errores) {
        cambiar_clave($usuarioId, $nueva);
        session_regenerate_id(true);
        redirigir('dashboard.php?aviso=clave_cambiada');
    }
}

$aviso = (string) ($_GET['aviso'] ?? '');
$titulo = 'Cambiar contrasena';
require __DIR__ . '/includes/header.php';
?>
<section class="auth">
    <div class="tarjeta auth__card">
        <h1 class="auth__titulo">Cambiar contrasena</h1>

        <?php if ($obligatorio || $aviso === 'obligatorio'): ?>
            <div class="alerta alerta--info">
                Tu cuenta tiene una contrasena temporal. Elegi una nueva para continuar.
            </div>
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

        <form method="post" action="cambiar_clave.php" novalidate autocomplete="off">
            <?= csrf_campo() ?>

            <div class="campo">
                <label for="clave_actual">Contrasena actual</label>
                <input type="password" id="clave_actual" name="clave_actual"
                       required maxlength="128" autocomplete="current-password">
            </div>

            <div class="campo">
                <label for="clave_nueva">Contrasena nueva</label>
                <input type="password" id="clave_nueva" name="clave_nueva"
                       minlength="<?= PASS_MIN_LEN ?>" maxlength="128"
                       required autocomplete="new-password">
                <small>Minimo <?= PASS_MIN_LEN ?> caracteres, con mayuscula, minuscula y numero.</small>
            </div>

            <div class="campo">
                <label for="clave_repetir">Repetir contrasena nueva</label>
                <input type="password" id="clave_repetir" name="clave_repetir"
                       minlength="<?= PASS_MIN_LEN ?>" maxlength="128"
                       required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn--primario btn--block">Guardar contrasena</button>
        </form>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
