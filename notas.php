<?php
/**
 * Notas del usuario. Demostracion practica de la Fase 4:
 *  - Inyeccion SQL: todas las operaciones usan sentencias preparadas.
 *  - XSS almacenado: la salida se escapa con e() (htmlspecialchars).
 *  - CSRF: los formularios de crear/eliminar llevan token.
 *  - Control de acceso: cada usuario solo ve y borra SUS notas (WHERE usuario_id = sesion).
 */
require_once __DIR__ . '/includes/init.php';
exigir_autenticacion();

$usuarioId = (int) $_SESSION['usuario_id'];
$errores   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar($_POST['csrf_token'] ?? null);
    $accion = post('accion');

    if ($accion === 'crear') {
        $titulo    = post('titulo');
        $contenido = (string) ($_POST['contenido'] ?? '');

        if ($titulo === '' || mb_strlen($titulo) > 150) {
            $errores[] = 'El titulo es obligatorio (maximo 150 caracteres).';
        }
        if ($contenido === '') {
            $errores[] = 'El contenido no puede estar vacio.';
        }

        if (!$errores) {
            // Sentencia preparada -> inmune a inyeccion SQL.
            $stmt = db()->prepare(
                'INSERT INTO notas (usuario_id, titulo, contenido)
                 VALUES (:uid, :titulo, :contenido)'
            );
            $stmt->execute([':uid' => $usuarioId, ':titulo' => $titulo, ':contenido' => $contenido]);
            registrar_auditoria($usuarioId, 'NOTA_CREADA', mb_substr($titulo, 0, 100));
            redirigir('notas.php?ok=1');
        }
    } elseif ($accion === 'eliminar') {
        $id = (int) post('id');
        // Solo borra si la nota pertenece al usuario autenticado (control de acceso / IDOR).
        $stmt = db()->prepare('DELETE FROM notas WHERE id = :id AND usuario_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $usuarioId]);
        registrar_auditoria($usuarioId, 'NOTA_ELIMINADA', 'id=' . $id);
        redirigir('notas.php?ok=2');
    }
}

$stmt = db()->prepare('SELECT id, titulo, contenido, creado_en FROM notas WHERE usuario_id = :uid ORDER BY id DESC');
$stmt->execute([':uid' => $usuarioId]);
$notas = $stmt->fetchAll();

$titulo = 'Mis notas';
require __DIR__ . '/includes/header.php';
?>
<section class="encabezado-seccion">
    <h1>Mis notas</h1>
    <p>Todo el contenido se almacena y se muestra de forma segura.</p>
</section>

<?php if (isset($_GET['ok'])): ?>
    <div class="alerta alerta--ok">Operacion realizada correctamente.</div>
<?php endif; ?>

<?php if ($errores): ?>
    <div class="alerta alerta--error">
        <ul><?php foreach ($errores as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="grid grid--2">
    <div class="tarjeta">
        <h2>Nueva nota</h2>
        <form method="post" action="notas.php">
            <?= csrf_campo() ?>
            <input type="hidden" name="accion" value="crear">
            <div class="campo">
                <label for="titulo">Titulo</label>
                <input type="text" id="titulo" name="titulo" maxlength="150" required>
            </div>
            <div class="campo">
                <label for="contenido">Contenido</label>
                <textarea id="contenido" name="contenido" rows="5" required
                    placeholder="Escribe aqui el contenido de tu nota"></textarea>
            </div>
            <button type="submit" class="btn btn--primario">Guardar nota</button>
        </form>
    </div>

    <div class="tarjeta">
        <h2>Notas guardadas (<?= count($notas) ?>)</h2>
        <?php if (!$notas): ?>
            <p class="vacio">Aun no tienes notas.</p>
        <?php else: ?>
            <ul class="lista-notas">
                <?php foreach ($notas as $n): ?>
                    <li class="nota">
                        <div class="nota__cuerpo">
                            <strong><?= e($n['titulo']) ?></strong>
                            <p><?= e($n['contenido']) ?></p>
                            <span class="nota__fecha"><?= e((string) $n['creado_en']) ?></span>
                        </div>
                        <form method="post" action="notas.php"
                              onsubmit="return confirm('¿Eliminar esta nota?');">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                            <button type="submit" class="btn btn--peligro btn--sm">Eliminar</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
