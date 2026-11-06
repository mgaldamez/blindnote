<?php
/**
 * Panel principal interactivo. Solo accesible con sesion iniciada (Fase 3).
 * Incluye widgets con graficas interactivas, metricas de notas y perfil de seguridad.
 */
require_once __DIR__ . '/includes/init.php';
exigir_autenticacion();

// Cierre defensivo: si el User-Agent cambia, la sesion se considera robada.
if (!sesion_coherente()) {
    cerrar_sesion('user_agent_cambiado');
    redirigir('login.php?aviso=sesion_expirada');
}

$usuarioId = (int) $_SESSION['usuario_id'];

// Estadisticas del usuario.
$stmt = db()->prepare('SELECT COUNT(*) AS c FROM notas WHERE usuario_id = :id');
$stmt->execute([':id' => $usuarioId]);
$totalNotas = (int) $stmt->fetch()['c'];

$contadores = contar_notas_por_estado($usuarioId);
$pendientes  = (int) $contadores['pendientes'];
$completadas = (int) $contadores['completadas'];
$fijadas     = (int) ($contadores['fijadas'] ?? 0);

$stmt = db()->prepare('SELECT ultimo_login FROM usuarios WHERE id = :id');
$stmt->execute([':id' => $usuarioId]);
$ultimoLogin = $stmt->fetch()['ultimo_login'] ?? '—';

$avatarActual = $_SESSION['avatar'] ?? null;
$avatares = avatares_disponibles();
$nombreAvatar = $avatares[$avatarActual]['nombre'] ?? 'Sin asignar';

$titulo = 'Panel';
require __DIR__ . '/includes/header.php';
?>

<?php if (empty($avatarActual)): ?>
    <div class="avatar-banner-sugerencia" id="avatar-sugerencia">
        <div class="avatar-banner-sugerencia__icono">
            <?= obtener_avatar_svg(null, 44) ?>
        </div>
        <div class="avatar-banner-sugerencia__texto">
            <strong>Personaliza tu perfil: Elige un avatar de ciberseguridad</strong>
            <p>Configura tu identidad visual dentro de BlindNote. No es obligatorio y puedes elegir tu favorito.</p>
        </div>
        <div class="avatar-banner-sugerencia__botones">
            <a class="btn btn--primario btn--sm" href="avatar.php">Elegir Avatar</a>
            <button type="button" class="btn btn--sm" data-cerrar-sugerencia>Mas tarde</button>
        </div>
    </div>
<?php endif; ?>

<section class="encabezado-seccion">
    <div class="perfil-resumen">
        <div class="perfil-resumen__avatar">
            <?= obtener_avatar_svg($avatarActual, 64) ?>
            <a class="perfil-resumen__avatar-btn" href="avatar.php" title="Cambiar avatar">&#9998;</a>
        </div>
        <div class="perfil-resumen__datos">
            <h1>Hola, <?= e($_SESSION['nombre']) ?></h1>
            <p>Sesion iniciada de forma segura con rol <span class="badge badge--<?= e($_SESSION['rol']) ?>"><?= e($_SESSION['rol']) ?></span> &middot; Avatar activo: <strong><?= e($nombreAvatar) ?></strong></p>
        </div>
    </div>
</section>

<!-- Widgets de Estadisticas Rapidas -->
<div class="grid grid--4">
    <div class="tarjeta stat">
        <span class="stat__num"><?= $totalNotas ?></span>
        <span class="stat__etiq">Total de Notas</span>
    </div>
    <div class="tarjeta stat stat--pendientes">
        <span class="stat__num" style="color: #2563eb;"><?= $pendientes ?></span>
        <span class="stat__etiq">Notas Pendientes</span>
    </div>
    <div class="tarjeta stat stat--completadas">
        <span class="stat__num" style="color: #16a34a;"><?= $completadas ?></span>
        <span class="stat__etiq">Notas Completadas</span>
    </div>
    <div class="tarjeta stat stat--fijadas">
        <span class="stat__num" style="color: #d97706;"><?= $fijadas ?></span>
        <span class="stat__etiq">&#128204; Notas Fijadas</span>
    </div>
</div>

<!-- Widgets Interactivos con Graficas SVG -->
<div class="grid grid--2" style="margin-top: 1.5rem;">
    <!-- Grafica Circular / Donut -->
    <div class="tarjeta widget-grafica">
        <h2>Distribucion de Notas</h2>
        <p class="widget-grafica__sub">Visualizacion interactiva del estado actual de tu espacio de trabajo.</p>

        <?php if ($totalNotas === 0): ?>
            <div class="vacio" style="padding: 2rem 0; text-align: center;">
                No tienes notas todavia. <a href="nota_form.php">Crea tu primera nota</a> para ver la grafica interactiva.
            </div>
        <?php else: ?>
            <?php
            $pctPendientes = round(($pendientes / $totalNotas) * 100);
            $pctCompletadas = round(($completadas / $totalNotas) * 100);
            $pctFijadas = round(($fijadas / $totalNotas) * 100);

            // Calculo de circunferencia: C = 2 * PI * r (con r = 40 => C = 251.32)
            $circunferencia = 251.32;
            $offsetPendientes = 0;
            $longPendientes = ($pendientes / $totalNotas) * $circunferencia;
            $longCompletadas = ($completadas / $totalNotas) * $circunferencia;
            $offsetCompletadas = $longPendientes;
            ?>
            <div class="donut-container">
                <svg class="donut-svg" width="160" height="160" viewBox="0 0 100 100" aria-label="Grafico de notas">
                    <!-- Fondo circular -->
                    <circle cx="50" cy="50" r="40" fill="transparent" stroke="var(--borde)" stroke-width="14"></circle>
                    <!-- Segmento Pendientes -->
                    <?php if ($pendientes > 0): ?>
                        <circle cx="50" cy="50" r="40" fill="transparent" stroke="#2563eb" stroke-width="14"
                                stroke-dasharray="<?= number_format($longPendientes, 2, '.', '') ?> <?= number_format($circunferencia, 2, '.', '') ?>"
                                stroke-dashoffset="0"
                                transform="rotate(-90 50 50)" class="donut-segment"></circle>
                    <?php endif; ?>
                    <!-- Segmento Completadas -->
                    <?php if ($completadas > 0): ?>
                        <circle cx="50" cy="50" r="40" fill="transparent" stroke="#16a34a" stroke-width="14"
                                stroke-dasharray="<?= number_format($longCompletadas, 2, '.', '') ?> <?= number_format($circunferencia, 2, '.', '') ?>"
                                stroke-dashoffset="-<?= number_format($offsetCompletadas, 2, '.', '') ?>"
                                transform="rotate(-90 50 50)" class="donut-segment"></circle>
                    <?php endif; ?>
                    <text x="50" y="47" text-anchor="middle" font-size="14" font-weight="700" fill="var(--texto-fuerte)"><?= $totalNotas ?></text>
                    <text x="50" y="60" text-anchor="middle" font-size="7.5" fill="var(--texto-suave)">TOTAL</text>
                </svg>

                <div class="donut-leyenda">
                    <div class="leyenda-item">
                        <span class="leyenda-color" style="background: #2563eb;"></span>
                        <span class="leyenda-texto">Pendientes: <strong><?= $pendientes ?></strong> (<?= $pctPendientes ?>%)</span>
                    </div>
                    <div class="leyenda-item">
                        <span class="leyenda-color" style="background: #16a34a;"></span>
                        <span class="leyenda-texto">Completadas: <strong><?= $completadas ?></strong> (<?= $pctCompletadas ?>%)</span>
                    </div>
                    <div class="leyenda-item">
                        <span class="leyenda-color" style="background: #d97706;"></span>
                        <span class="leyenda-texto">Fijadas: <strong><?= $fijadas ?></strong> (<?= $pctFijadas ?>%)</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Grafica de Barras / Resumen de Seguridad y Sesion -->
    <div class="tarjeta widget-grafica">
        <h2>Metricas de Seguridad y Sesion</h2>
        <p class="widget-grafica__sub">Indicadores de proteccion activa para tu cuenta.</p>

        <div class="barras-metricas">
            <div class="barra-metrica">
                <div class="barra-metrica__cabecera">
                    <span>Robustez Criptografica (bcrypt cost <?= BCRYPT_COST ?>)</span>
                    <strong style="color: #16a34a;">100%</strong>
                </div>
                <div class="barra-metrica__pista">
                    <div class="barra-metrica__progreso" style="width: 100%; background: #16a34a;"></div>
                </div>
            </div>

            <div class="barra-metrica">
                <div class="barra-metrica__cabecera">
                    <span>Mitigacion Inyeccion SQL (PDO Preparadas)</span>
                    <strong style="color: #16a34a;">100%</strong>
                </div>
                <div class="barra-metrica__pista">
                    <div class="barra-metrica__progreso" style="width: 100%; background: #16a34a;"></div>
                </div>
            </div>

            <div class="barra-metrica">
                <div class="barra-metrica__cabecera">
                    <span>Tasa de Notas Finalizadas</span>
                    <strong><?= $totalNotas > 0 ? round(($completadas / $totalNotas) * 100) : 0 ?>%</strong>
                </div>
                <div class="barra-metrica__pista">
                    <div class="barra-metrica__progreso" style="width: <?= $totalNotas > 0 ? round(($completadas / $totalNotas) * 100) : 0 ?>%; background: #2563eb;"></div>
                </div>
            </div>

            <div class="barra-metrica-info">
                <small>&middot; Ultimo login: <strong><?= e((string)$ultimoLogin) ?></strong></small><br>
                <small>&middot; Tu IP actual: <strong><?= e(ip_cliente()) ?></strong></small>
            </div>
        </div>
    </div>
</div>

<!-- Accesos Rapidos Interactivos -->
<div class="grid grid--3" style="margin-top: 1.5rem;">
    <a class="tarjeta enlace-tarjeta enlace-tarjeta--accion" href="notas.php?vista=kanban">
        <div class="enlace-tarjeta__icono">&#128202;</div>
        <h3>Tablero Kanban</h3>
        <p>Arrastra y suelta tus notas interactivamente entre columnas.</p>
    </a>
    <a class="tarjeta enlace-tarjeta enlace-tarjeta--accion" href="nota_form.php">
        <div class="enlace-tarjeta__icono">&#9997;</div>
        <h3>Nueva Nota Segura</h3>
        <p>Crea notas con proteccion XSS, anti-CSRF y fijalas al inicio.</p>
    </a>
    <a class="tarjeta enlace-tarjeta enlace-tarjeta--accion" href="avatar.php">
        <div class="enlace-tarjeta__icono">&#127912;</div>
        <h3>Personalizar Avatar</h3>
        <p>Cambia el avatar de ciberseguridad para tu cuenta.</p>
    </a>
</div>

<div class="tarjeta" style="margin-top: 1.5rem;">
    <h2>Medidas de seguridad activas</h2>
    <ul class="checklist">
        <li><span class="ok">&#10003;</span> Contrasenas cifradas con <strong>bcrypt</strong> (cost <?= BCRYPT_COST ?>).</li>
        <li><span class="ok">&#10003;</span> Consultas <strong>preparadas</strong> (PDO) &rarr; sin inyeccion SQL.</li>
        <li><span class="ok">&#10003;</span> Token <strong>CSRF</strong> en todos los formularios y operaciones AJAX.</li>
        <li><span class="ok">&#10003;</span> Salida escapada con <strong>htmlspecialchars</strong> &rarr; sin XSS.</li>
        <li><span class="ok">&#10003;</span> Cookie de sesion <strong>HttpOnly</strong> + <strong>SameSite=Strict</strong>.</li>
        <li><span class="ok">&#10003;</span> Bloqueo tras <strong><?= MAX_INTENTOS_FALLIDOS ?></strong> intentos fallidos.</li>
        <li><span class="ok">&#10003;</span> Regeneracion de ID de sesion al iniciar (anti fijacion).</li>
        <li><span class="ok">&#10003;</span> Expira por inactividad a los <strong><?= SESION_INACTIVIDAD_MIN ?> min</strong>.</li>
    </ul>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
