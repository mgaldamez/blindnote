<?php
/**
 * Selector interactivo de Avatar de Perfil.
 * Permite a los usuarios elegir o cambiar su icono de seguridad.
 * No es obligatorio; se almacena de forma segura en la base de datos (campo 'avatar').
 */
require_once __DIR__ . '/includes/init.php';
exigir_autenticacion();

$usuarioId = (int) $_SESSION['usuario_id'];
$avatares = avatares_disponibles();
$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar($_POST['csrf_token'] ?? null);

    $avatarElegido = (string) post('avatar');
    if (isset($avatares[$avatarElegido])) {
        guardar_avatar($usuarioId, $avatarElegido);

        // Si la peticion proviene de AJAX / Fetch:
        $esAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
            || post('formato') === 'json';

        if ($esAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'avatar' => $avatarElegido,
                'mensaje' => 'Avatar actualizado correctamente.'
            ]);
            exit;
        }

        redirigir('dashboard.php?ok=avatar_actualizado');
    } else {
        $error = 'Avatar no valido seleccionado.';
    }
}

$avatarActual = $_SESSION['avatar'] ?? '';
$titulo = 'Personalizar Avatar';
require __DIR__ . '/includes/header.php';
?>
<section class="encabezado-seccion">
    <h1>Elige tu Avatar de Seguridad</h1>
    <p>Personaliza tu perfil con un avatar tematico de ciberseguridad. No es obligatorio y puedes cambiarlo en cualquier momento.</p>
</section>

<?php if ($error): ?>
    <div class="alerta alerta--error"><?= e($error) ?></div>
<?php endif; ?>

<div class="tarjeta">
    <div class="avatar-actual-banner">
        <div class="avatar-actual-banner__icon">
            <?= obtener_avatar_svg($avatarActual, 64) ?>
        </div>
        <div class="avatar-actual-banner__info">
            <h2>Avatar actual: <strong><?= e($avatares[$avatarActual]['nombre'] ?? 'Sin asignar (predeterminado)') ?></strong></h2>
            <p><?= e($avatares[$avatarActual]['desc'] ?? 'Haz clic en cualquiera de las opciones para activarlo.') ?></p>
        </div>
    </div>

    <div class="avatar-galeria">
        <?php foreach ($avatares as $id => $av): ?>
            <?php $esSeleccionado = ($id === $avatarActual); ?>
            <form method="post" action="avatar.php" class="avatar-tarjeta<?= $esSeleccionado ? ' avatar-tarjeta--activo' : '' ?>">
                <?= csrf_campo() ?>
                <input type="hidden" name="avatar" value="<?= e($id) ?>">
                
                <div class="avatar-tarjeta__preview">
                    <?= obtener_avatar_svg($id, 72) ?>
                </div>
                <h3 class="avatar-tarjeta__nombre"><?= e($av['nombre']) ?></h3>
                <p class="avatar-tarjeta__desc"><?= e($av['desc']) ?></p>

                <button type="submit" class="btn <?= $esSeleccionado ? 'btn--ok btn--sm' : 'btn--primario btn--sm' ?>">
                    <?= $esSeleccionado ? '&#10003; Seleccionado' : 'Elegir este' ?>
                </button>
            </form>
        <?php endforeach; ?>
    </div>

    <div style="margin-top: 1.5rem; text-align: center;">
        <a href="dashboard.php" class="btn">Volver al Panel</a>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
