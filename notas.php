<?php
/**
 * Notas del usuario. Demostracion practica de la Fase 4:
 *  - Inyeccion SQL: todas las operaciones usan sentencias preparadas.
 *  - XSS almacenado: la salida se escapa con e() (htmlspecialchars).
 *  - CSRF: cada formulario (crear, editar, completar, reabrir, eliminar) lleva token.
 *  - Control de acceso: cada usuario solo ve y modifica SUS notas
 *    (todas las consultas filtran por usuario_id de la sesion).
 *
 * La lista se separa por estado: Pendientes y Completadas.
 */
require_once __DIR__ . '/includes/init.php';
exigir_autenticacion();

$usuarioId = (int) $_SESSION['usuario_id'];
$errores   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar($_POST['csrf_token'] ?? null);

    $accion = post('accion');
    $notaId = (int) post('id');

    if ($accion === 'eliminar') {
        // Solo borra si la nota pertenece al usuario autenticado (control de acceso / IDOR).
        db()->prepare('DELETE FROM notas WHERE id = :id AND usuario_id = :uid')
            ->execute([':id' => $notaId, ':uid' => $usuarioId]);
        registrar_auditoria($usuarioId, 'NOTA_ELIMINADA', 'id=' . $notaId);
        redirigir('notas.php?ok=eliminada');
    }

    if ($accion === 'completar' || $accion === 'reabrir') {
        $resultado = cambiar_estado_nota($usuarioId, $notaId, $accion === 'completar');

        if ($resultado['ok']) {
            $destino = $accion === 'completar' ? 'completadas' : 'pendientes';
            redirigir('notas.php?ok=' . ($accion === 'completar' ? 'completada' : 'reabierta') . '&ver=' . $destino);
        }

        $errores = $resultado['errores'];
    }
}

$ver = (string) ($_GET['ver'] ?? 'pendientes');
if (!in_array($ver, ['pendientes', 'completadas'], true)) {
    $ver = 'pendientes';
}

$contadores = contar_notas_por_estado($usuarioId);
$notas      = listar_notas($usuarioId, $ver === 'completadas');
$viendoCompletadas = $ver === 'completadas';

$aviso = (string) ($_GET['ok'] ?? '');
$mensajesOk = [
    'creada'     => 'Nota creada.',
    'editada'    => 'Nota actualizada.',
    'eliminada'  => 'Nota eliminada.',
    'completada' => 'Nota marcada como completada.',
    'reabierta'  => 'Nota reabierta: vuelve a la lista de pendientes.',
];

$titulo = 'Mis notas';
require __DIR__ . '/includes/header.php';
?>
<section class="encabezado-seccion">
    <h1>Mis notas</h1>
    <p>Todo el contenido se almacena y se muestra de forma segura.</p>
</section>

<?php if (isset($mensajesOk[$aviso])): ?>
    <div class="alerta alerta--ok"><?= e($mensajesOk[$aviso]) ?></div>
<?php endif; ?>

<?php if ($errores): ?>
    <div class="alerta alerta--error">
        <ul><?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<nav class="pestanas" aria-label="Estado de las notas">
    <a class="pestana<?= $viendoCompletadas ? '' : ' pestana--activa' ?>"
       href="notas.php?ver=pendientes"<?= $viendoCompletadas ? '' : ' aria-current="page"' ?>>
        Pendientes (<?= (int) $contadores['pendientes'] ?>)
    </a>
    <a class="pestana<?= $viendoCompletadas ? ' pestana--activa' : '' ?>"
       href="notas.php?ver=completadas"<?= $viendoCompletadas ? ' aria-current="page"' : '' ?>>
        Completadas (<?= (int) $contadores['completadas'] ?>)
    </a>
</nav>

<div class="tarjeta">
    <div class="tarjeta__cabecera">
        <h2><?= $viendoCompletadas ? 'Completadas' : 'Pendientes' ?> (<?= count($notas) ?>)</h2>
        <a class="btn btn--primario" href="nota_form.php">Nueva nota</a>
    </div>

    <?php if (!$notas): ?>
        <p class="vacio">
            <?= $viendoCompletadas ? 'Todavia no completaste ninguna nota.' : 'No tenes notas pendientes.' ?>
        </p>
    <?php else: ?>
        <ul class="lista-notas">
            <?php foreach ($notas as $n): ?>
                <?php $estaCompletada = (int) $n['completada'] === 1; ?>
                <li class="nota<?= $estaCompletada ? ' nota--completada' : '' ?>">
                    <div class="nota__cuerpo">
                        <strong><?= e($n['titulo']) ?></strong>
                        <p><?= e($n['contenido']) ?></p>
                        <span class="nota__fecha">
                            <?= e((string) $n['creado_en']) ?>
                            <?php if ($estaCompletada && !empty($n['completada_en'])): ?>
                                &middot; completada el <?= e((string) $n['completada_en']) ?>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="nota__acciones">
                        <a class="btn btn--sm" href="nota_form.php?id=<?= (int) $n['id'] ?>">Editar</a>

                        <form method="post" action="notas.php">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <?php if ($estaCompletada): ?>
                                <input type="hidden" name="accion" value="reabrir">
                                <button type="submit" class="btn btn--sm">Reabrir</button>
                            <?php else: ?>
                                <input type="hidden" name="accion" value="completar">
                                <button type="submit" class="btn btn--sm">Completar</button>
                            <?php endif; ?>
                        </form>

                        <form method="post" action="notas.php" data-confirmar="¿Eliminar esta nota?">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" class="btn btn--peligro btn--sm">Eliminar</button>
                        </form>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
