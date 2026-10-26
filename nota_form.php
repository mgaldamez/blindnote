<?php
/**
 * Formulario de nota: crea (sin `id`) o edita (con `id`).
 *
 * Toda la validacion y el control de propiedad viven en includes/functions.php
 * (crear_nota / actualizar_nota), que filtran por el usuario de la sesion.
 */
require_once __DIR__ . '/includes/init.php';
exigir_autenticacion();

$usuarioId = (int) $_SESSION['usuario_id'];
$notaId    = (int) ($_GET['id'] ?? 0);
$editando  = $notaId > 0;
$errores   = [];

$vals = ['titulo' => '', 'contenido' => ''];

if ($editando) {
    $nota = obtener_nota($usuarioId, $notaId);
    if ($nota === null) {
        http_response_code(404);
        die('La nota no existe.');
    }

    $vals = [
        'titulo'    => (string) $nota['titulo'],
        'contenido' => (string) $nota['contenido'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar($_POST['csrf_token'] ?? null);

    $vals = [
        'titulo'    => post('titulo'),
        'contenido' => (string) ($_POST['contenido'] ?? ''),
    ];

    $resultado = $editando
        ? actualizar_nota($usuarioId, $notaId, $vals['titulo'], $vals['contenido'])
        : crear_nota($usuarioId, $vals['titulo'], $vals['contenido']);

    if ($resultado['ok']) {
        redirigir('notas.php?ok=' . ($editando ? 'editada' : 'creada'));
    }

    $errores = $resultado['errores'];
}

$titulo = $editando ? 'Editar nota' : 'Nueva nota';
require __DIR__ . '/includes/header.php';
?>
<section class="encabezado-seccion">
    <h1><?= $editando ? 'Editar nota' : 'Nueva nota' ?></h1>
    <p><?= $editando ? 'Modifica el titulo o el contenido.' : 'El contenido se guarda y se muestra escapado.' ?></p>
</section>

<div class="tarjeta">
    <?php if ($errores): ?>
        <div class="alerta alerta--error">
            <ul><?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="post" action="nota_form.php<?= $editando ? '?id=' . (int) $notaId : '' ?>">
        <?= csrf_campo() ?>

        <div class="campo">
            <label for="titulo">Titulo</label>
            <input type="text" id="titulo" name="titulo" maxlength="150"
                   value="<?= e($vals['titulo']) ?>" required>
        </div>

        <div class="campo">
            <label for="contenido">Contenido</label>
            <textarea id="contenido" name="contenido" rows="8" required
                      placeholder="Escribe aqui el contenido de tu nota"><?= e($vals['contenido']) ?></textarea>
        </div>

        <div class="campo campo--acciones">
            <button type="submit" class="btn btn--primario">
                <?= $editando ? 'Guardar cambios' : 'Crear nota' ?>
            </button>
            <a class="btn" href="notas.php">Cancelar</a>
        </div>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
