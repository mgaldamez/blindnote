<?php
/**
 * Panel principal. Solo accesible con sesion iniciada (Fase 3).
 */
require_once __DIR__ . '/includes/init.php';
exigir_autenticacion();

// Cierre defensivo: si el User-Agent cambia, la sesion se considera robada.
if (!sesion_coherente()) {
    cerrar_sesion('user_agent_cambiado');
    redirigir('login.php?aviso=sesion_expirada');
}

$usuarioId = (int) $_SESSION['usuario_id'];

// Estadisticas del panel.
$stmt = db()->prepare('SELECT COUNT(*) AS c FROM notas WHERE usuario_id = :id');
$stmt->execute([':id' => $usuarioId]);
$totalNotas = (int) $stmt->fetch()['c'];

$stmt = db()->prepare('SELECT ultimo_login FROM usuarios WHERE id = :id');
$stmt->execute([':id' => $usuarioId]);
$ultimoLogin = $stmt->fetch()['ultimo_login'] ?? '—';

$titulo = 'Panel';
require __DIR__ . '/includes/header.php';
?>
<section class="encabezado-seccion">
    <h1>Hola, <?= e($_SESSION['nombre']) ?></h1>
    <p>Has iniciado sesion de forma segura. Tu rol es <strong><?= e($_SESSION['rol']) ?></strong>.</p>
</section>

<div class="grid grid--3">
    <div class="tarjeta stat">
        <span class="stat__num"><?= $totalNotas ?></span>
        <span class="stat__etiq">Notas registradas</span>
    </div>
    <div class="tarjeta stat">
        <span class="stat__num"><?= e((string) $ultimoLogin) ?></span>
        <span class="stat__etiq">Ultimo inicio de sesion</span>
    </div>
    <div class="tarjeta stat">
        <span class="stat__num"><?= e(ip_cliente()) ?></span>
        <span class="stat__etiq">Tu direccion IP</span>
    </div>
</div>

<div class="tarjeta">
    <h2>Medidas de seguridad activas</h2>
    <ul class="checklist">
        <li><span class="ok">&#10003;</span> Contrasenas cifradas con <strong>bcrypt</strong> (cost <?= BCRYPT_COST ?>).</li>
        <li><span class="ok">&#10003;</span> Consultas <strong>preparadas</strong> (PDO) &rarr; sin inyeccion SQL.</li>
        <li><span class="ok">&#10003;</span> Token <strong>CSRF</strong> en todos los formularios.</li>
        <li><span class="ok">&#10003;</span> Salida escapada con <strong>htmlspecialchars</strong> &rarr; sin XSS.</li>
        <li><span class="ok">&#10003;</span> Cookie de sesion <strong>HttpOnly</strong> + <strong>SameSite=Strict</strong>.</li>
        <li><span class="ok">&#10003;</span> Bloqueo tras <strong><?= MAX_INTENTOS_FALLIDOS ?></strong> intentos fallidos.</li>
        <li><span class="ok">&#10003;</span> Regeneracion de ID de sesion al iniciar (anti fijacion).</li>
        <li><span class="ok">&#10003;</span> Expira por inactividad a los <strong><?= SESION_INACTIVIDAD_MIN ?> min</strong>.</li>
    </ul>
</div>

<div class="grid grid--2">
    <a class="tarjeta enlace-tarjeta" href="notas.php">
        <h3>Mis notas</h3>
        <p>Prueba a guardar contenido con etiquetas HTML: veras que no se ejecutan (XSS mitigado).</p>
    </a>
    <a class="tarjeta enlace-tarjeta" href="informe_fase4.php">
        <h3>Analisis de vulnerabilidades</h3>
        <p>Informe de la Fase 4: hallazgos de OWASP ZAP / Nikto y sus soluciones.</p>
    </a>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
