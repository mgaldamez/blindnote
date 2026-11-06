<?php
/**
 * Notas del usuario. Demostracion practica de la Fase 4:
 *  - Inyeccion SQL: todas las operaciones usan sentencias preparadas.
 *  - XSS almacenado: la salida se escapa con e() (htmlspecialchars).
 *  - CSRF: cada formulario y llamada AJAX lleva token de proteccion.
 *  - Control de acceso: cada usuario solo ve y modifica SUS notas (anti-IDOR).
 *
 * Incluye:
 *  - Vista de Lista tradicional y Tablero Kanban interactivo (arrastrar y soltar).
 *  - Fijar notas importantes al inicio (Pin to Top).
 *  - Copiar contenido al portapapeles con un clic.
 */
require_once __DIR__ . '/includes/init.php';
exigir_autenticacion();

$usuarioId = (int) $_SESSION['usuario_id'];
$errores   = [];

$vista = (string) ($_GET['vista'] ?? 'lista');
if (!in_array($vista, ['lista', 'kanban'], true)) {
    $vista = 'lista';
}

$ver = (string) ($_GET['ver'] ?? 'pendientes');
if (!in_array($ver, ['pendientes', 'completadas'], true)) {
    $ver = 'pendientes';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar($_POST['csrf_token'] ?? null);

    $accion = post('accion');
    $notaId = (int) post('id');
    $vistaRetorno = post('vista', $vista);
    $verRetorno = post('ver', $ver);

    $esAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || post('formato') === 'json';

    if ($accion === 'eliminar') {
        db()->prepare('DELETE FROM notas WHERE id = :id AND usuario_id = :uid')
            ->execute([':id' => $notaId, ':uid' => $usuarioId]);
        registrar_auditoria($usuarioId, 'NOTA_ELIMINADA', 'id=' . $notaId);

        if ($esAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'id' => $notaId]);
            exit;
        }
        redirigir('notas.php?ok=eliminada&vista=' . urlencode($vistaRetorno) . '&ver=' . urlencode($verRetorno));
    }

    if ($accion === 'fijar') {
        $resultado = fijar_nota($usuarioId, $notaId);

        if ($esAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($resultado);
            exit;
        }

        redirigir('notas.php?ok=' . ($resultado['fijada'] ? 'fijada' : 'desfijada') . '&vista=' . urlencode($vistaRetorno) . '&ver=' . urlencode($verRetorno));
    }

    if ($accion === 'completar' || $accion === 'reabrir') {
        $resultado = cambiar_estado_nota($usuarioId, $notaId, $accion === 'completar');

        if ($esAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => $resultado['ok'],
                'completada' => ($accion === 'completar'),
                'id' => $notaId,
                'errores' => $resultado['errores'],
            ]);
            exit;
        }

        if ($resultado['ok']) {
            $destino = $accion === 'completar' ? 'completadas' : 'pendientes';
            redirigir('notas.php?ok=' . ($accion === 'completar' ? 'completada' : 'reabierta') . '&ver=' . $destino . '&vista=' . urlencode($vistaRetorno));
        }

        $errores = $resultado['errores'];
    }
}

$contadores = contar_notas_por_estado($usuarioId);
$viendoCompletadas = ($ver === 'completadas');

// Si es vista Kanban traemos ambas listas
$notasPendientes  = listar_notas($usuarioId, false);
$notasCompletadas = listar_notas($usuarioId, true);

// Para vista lista traemos la activa
$notas = $viendoCompletadas ? $notasCompletadas : $notasPendientes;

$aviso = (string) ($_GET['ok'] ?? '');
$mensajesOk = [
    'creada'     => 'Nota creada.',
    'editada'    => 'Nota actualizada.',
    'eliminada'  => 'Nota eliminada.',
    'completada' => 'Nota marcada como completada.',
    'reabierta'  => 'Nota reabierta: vuelve a la lista de pendientes.',
    'fijada'     => 'Nota fijada en la parte superior.',
    'desfijada'  => 'Nota desfijada.',
];

$titulo = 'Mis notas';
require __DIR__ . '/includes/header.php';
?>
<section class="encabezado-seccion encabezado-seccion--flex">
    <div>
        <h1>Mis notas</h1>
        <p>Almacenamiento seguro, tablero Kanban interactivo y fijado de notas importantes.</p>
    </div>
    <div class="vista-selector">
        <a class="btn <?= $vista === 'lista' ? 'btn--primario' : '' ?> btn--sm" href="notas.php?vista=lista&ver=<?= e($ver) ?>">
            &#128221; Vista Lista
        </a>
        <a class="btn <?= $vista === 'kanban' ? 'btn--primario' : '' ?> btn--sm" href="notas.php?vista=kanban">
            &#128202; Tablero Kanban
        </a>
        <a class="btn btn--primario btn--sm" href="nota_form.php" style="margin-left: .5rem;">+ Nueva nota</a>
    </div>
</section>

<?php if (isset($mensajesOk[$aviso])): ?>
    <div class="alerta alerta--ok"><?= e($mensajesOk[$aviso]) ?></div>
<?php endif; ?>

<?php if ($errores): ?>
    <div class="alerta alerta--error">
        <ul><?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if ($vista === 'kanban'): ?>
    <!-- ===================================================================
         VISTA TABLERO KANBAN INTERACTIVO (Arrastrar y Soltar)
         =================================================================== -->
    <div class="kanban-instrucciones">
        <span>&#128073; <em>Tip interactivo:</em> Puedes <strong>arrastrar y soltar</strong> las tarjetas entre columnas para cambiar su estado instantaneamente.</span>
    </div>

    <div class="kanban-tablero" data-csrf-token="<?= e(csrf_token()) ?>">
        <!-- Columna Pendientes -->
        <div class="kanban-columna" data-columna="pendientes">
            <div class="kanban-columna__cabecera kanban-columna__cabecera--pendientes">
                <h3>&#128203; Pendientes</h3>
                <span class="badge" data-contador-pendientes><?= count($notasPendientes) ?></span>
            </div>

            <div class="kanban-columna__zona" data-zona-soltar="pendientes">
                <?php if (!$notasPendientes): ?>
                    <p class="kanban-vacio">Sin notas pendientes. Arrastra una aqui o crea una nueva.</p>
                <?php endif; ?>

                <?php foreach ($notasPendientes as $n): ?>
                    <?php
                    $esFijada = !empty($n['fijada']);
                    $textoCopiar = $n['titulo'] . "

" . $n['contenido'];
                    ?>
                    <div class="kanban-tarjeta<?= $esFijada ? ' kanban-tarjeta--fijada' : '' ?>"
                         draggable="true"
                         data-kanban-tarjeta
                         data-nota-id="<?= (int) $n['id'] ?>"
                         data-estado="pendientes">
                        
                        <div class="kanban-tarjeta__top">
                            <?php if ($esFijada): ?>
                                <span class="badge badge--fijada" title="Nota fijada al inicio">&#128204; Fijada</span>
                            <?php endif; ?>
                            
                            <div class="kanban-tarjeta__herramientas">
                                <form method="post" action="notas.php" class="inline" data-form-fijar>
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="fijar">
                                    <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                    <input type="hidden" name="vista" value="kanban">
                                    <button type="submit" class="btn-icono btn-fijar<?= $esFijada ? ' btn-fijar--activa' : '' ?>" title="<?= $esFijada ? 'Desfijar nota' : 'Fijar nota al inicio' ?>" data-btn-fijar>
                                        &#128204;
                                    </button>
                                </form>

                                <button type="button" class="btn-icono btn-copiar" title="Copiar contenido al portapapeles" data-copiar-nota="<?= e($textoCopiar) ?>">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                </button>
                            </div>
                        </div>

                        <h4 class="kanban-tarjeta__titulo"><?= e($n['titulo']) ?></h4>
                        <p class="kanban-tarjeta__cuerpo"><?= e($n['contenido']) ?></p>

                        <div class="kanban-tarjeta__pie">
                            <span class="kanban-tarjeta__fecha"><?= e((string)$n['creado_en']) ?></span>
                            
                            <div class="kanban-tarjeta__acciones">
                                <a class="btn btn--sm" href="nota_form.php?id=<?= (int) $n['id'] ?>" title="Editar">Editar</a>
                                
                                <form method="post" action="notas.php" class="inline">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                    <input type="hidden" name="vista" value="kanban">
                                    <input type="hidden" name="accion" value="completar">
                                    <button type="submit" class="btn btn--sm btn--ok-suave" title="Marcar como completada">&#10003;</button>
                                </form>

                                <form method="post" action="notas.php" class="inline" data-confirmar="¿Eliminar esta nota?">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                    <input type="hidden" name="vista" value="kanban">
                                    <button type="submit" class="btn btn--peligro btn--sm" title="Eliminar">&times;</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Columna Completadas -->
        <div class="kanban-columna" data-columna="completadas">
            <div class="kanban-columna__cabecera kanban-columna__cabecera--completadas">
                <h3>&#9989; Completadas</h3>
                <span class="badge" data-contador-completadas><?= count($notasCompletadas) ?></span>
            </div>

            <div class="kanban-columna__zona" data-zona-soltar="completadas">
                <?php if (!$notasCompletadas): ?>
                    <p class="kanban-vacio">No has completado notas todavia. Arrastra una aqui para completarla.</p>
                <?php endif; ?>

                <?php foreach ($notasCompletadas as $n): ?>
                    <?php
                    $esFijada = !empty($n['fijada']);
                    $textoCopiar = $n['titulo'] . "

" . $n['contenido'];
                    ?>
                    <div class="kanban-tarjeta kanban-tarjeta--completada<?= $esFijada ? ' kanban-tarjeta--fijada' : '' ?>"
                         draggable="true"
                         data-kanban-tarjeta
                         data-nota-id="<?= (int) $n['id'] ?>"
                         data-estado="completadas">
                        
                        <div class="kanban-tarjeta__top">
                            <?php if ($esFijada): ?>
                                <span class="badge badge--fijada" title="Nota fijada">&#128204; Fijada</span>
                            <?php endif; ?>

                            <div class="kanban-tarjeta__herramientas">
                                <form method="post" action="notas.php" class="inline" data-form-fijar>
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="fijar">
                                    <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                    <input type="hidden" name="vista" value="kanban">
                                    <button type="submit" class="btn-icono btn-fijar<?= $esFijada ? ' btn-fijar--activa' : '' ?>" title="<?= $esFijada ? 'Desfijar nota' : 'Fijar nota al inicio' ?>" data-btn-fijar>
                                        &#128204;
                                    </button>
                                </form>

                                <button type="button" class="btn-icono btn-copiar" title="Copiar contenido al portapapeles" data-copiar-nota="<?= e($textoCopiar) ?>">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                </button>
                            </div>
                        </div>

                        <h4 class="kanban-tarjeta__titulo"><?= e($n['titulo']) ?></h4>
                        <p class="kanban-tarjeta__cuerpo"><?= e($n['contenido']) ?></p>

                        <div class="kanban-tarjeta__pie">
                            <span class="kanban-tarjeta__fecha">
                                &#10003; <?= e((string)($n['completada_en'] ?? $n['creado_en'])) ?>
                            </span>

                            <div class="kanban-tarjeta__acciones">
                                <a class="btn btn--sm" href="nota_form.php?id=<?= (int) $n['id'] ?>" title="Editar">Editar</a>

                                <form method="post" action="notas.php" class="inline">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                    <input type="hidden" name="vista" value="kanban">
                                    <input type="hidden" name="accion" value="reabrir">
                                    <button type="submit" class="btn btn--sm" title="Reabrir nota">&#8634; Reabrir</button>
                                </form>

                                <form method="post" action="notas.php" class="inline" data-confirmar="¿Eliminar esta nota?">
                                    <?= csrf_campo() ?>
                                    <input type="hidden" name="accion" value="eliminar">
                                    <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                    <input type="hidden" name="vista" value="kanban">
                                    <button type="submit" class="btn btn--peligro btn--sm" title="Eliminar">&times;</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ===================================================================
         VISTA LISTA TRADICIONAL MEJORADA CON FIJADO Y COPIADO
         =================================================================== -->
    <nav class="pestanas" aria-label="Estado de las notas">
        <a class="pestana<?= $viendoCompletadas ? '' : ' pestana--activa' ?>"
           href="notas.php?vista=lista&ver=pendientes"<?= $viendoCompletadas ? '' : ' aria-current="page"' ?>>
            Pendientes (<?= (int) $contadores['pendientes'] ?>)
        </a>
        <a class="pestana<?= $viendoCompletadas ? ' pestana--activa' : '' ?>"
           href="notas.php?vista=lista&ver=completadas"<?= $viendoCompletadas ? ' aria-current="page"' : '' ?>>
            Completadas (<?= (int) $contadores['completadas'] ?>)
        </a>
    </nav>

    <div class="tarjeta">
        <div class="tarjeta__cabecera">
            <h2><?= $viendoCompletadas ? 'Completadas' : 'Pendientes' ?> (<?= count($notas) ?>)</h2>
            <div class="tarjeta__cabecera-acciones">
                <a class="btn btn--primario btn--sm" href="nota_form.php">Nueva nota</a>
            </div>
        </div>

        <?php if (!$notas): ?>
            <p class="vacio">
                <?= $viendoCompletadas ? 'Todavia no completaste ninguna nota.' : 'No tenes notas pendientes.' ?>
            </p>
        <?php else: ?>
            <ul class="lista-notas">
                <?php foreach ($notas as $n): ?>
                    <?php
                    $estaCompletada = (int) $n['completada'] === 1;
                    $esFijada = !empty($n['fijada']);
                    $textoCopiar = $n['titulo'] . "

" . $n['contenido'];
                    ?>
                    <li class="nota<?= $estaCompletada ? ' nota--completada' : '' ?><?= $esFijada ? ' nota--fijada' : '' ?>" data-nota-id="<?= (int)$n['id'] ?>">
                        <div class="nota__cuerpo">
                            <div class="nota__titulo-wrap">
                                <?php if ($esFijada): ?>
                                    <span class="badge badge--fijada" title="Nota fijada al inicio">&#128204; Fijada</span>
                                <?php endif; ?>
                                <strong><?= e($n['titulo']) ?></strong>
                            </div>
                            <p><?= e($n['contenido']) ?></p>
                            <span class="nota__fecha">
                                Creada: <?= e((string) $n['creado_en']) ?>
                                <?php if ($estaCompletada && !empty($n['completada_en'])): ?>
                                    &middot; completada el <?= e((string) $n['completada_en']) ?>
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="nota__acciones">
                            <!-- Boton Fijar al Inicio -->
                            <form method="post" action="notas.php" class="inline" data-form-fijar>
                                <?= csrf_campo() ?>
                                <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                <input type="hidden" name="accion" value="fijar">
                                <input type="hidden" name="vista" value="lista">
                                <input type="hidden" name="ver" value="<?= e($ver) ?>">
                                <button type="submit" class="btn btn--sm btn-fijar<?= $esFijada ? ' btn-fijar--activa' : '' ?>"
                                        title="<?= $esFijada ? 'Desfijar de la parte superior' : 'Fijar en la parte superior' ?>" data-btn-fijar>
                                    &#128204; <?= $esFijada ? 'Fijada' : 'Fijar' ?>
                                </button>
                            </form>

                            <!-- Boton Copiar al Portapapeles con un Clic -->
                            <button type="button" class="btn btn--sm btn-copiar"
                                    title="Copiar texto de la nota al portapapeles"
                                    data-copiar-nota="<?= e($textoCopiar) ?>">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align: -2px; margin-right: 2px;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                                Copiar
                            </button>

                            <a class="btn btn--sm" href="nota_form.php?id=<?= (int) $n['id'] ?>">Editar</a>

                            <form method="post" action="notas.php" class="inline">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                <input type="hidden" name="vista" value="lista">
                                <input type="hidden" name="ver" value="<?= e($ver) ?>">
                                <?php if ($estaCompletada): ?>
                                    <input type="hidden" name="accion" value="reabrir">
                                    <button type="submit" class="btn btn--sm">Reabrir</button>
                                <?php else: ?>
                                    <input type="hidden" name="accion" value="completar">
                                    <button type="submit" class="btn btn--sm btn--ok-suave">Completar</button>
                                <?php endif; ?>
                            </form>

                            <form method="post" action="notas.php" class="inline" data-confirmar="¿Eliminar esta nota?">
                                <?= csrf_campo() ?>
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                                <input type="hidden" name="vista" value="lista">
                                <input type="hidden" name="ver" value="<?= e($ver) ?>">
                                <button type="submit" class="btn btn--peligro btn--sm">Eliminar</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
