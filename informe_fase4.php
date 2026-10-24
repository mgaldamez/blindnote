<?php
/**
 * FASE 4 - Analisis de vulnerabilidades.
 * Informe de los hallazgos obtenidos con herramientas como OWASP ZAP y
 * Nikto sobre el sitio, la severidad de cada uno y la solucion aplicada.
 */
require_once __DIR__ . '/includes/init.php';

$herramientas = [
    ['OWASP ZAP', 'Escaneo activo/pasivo de aplicaciones web (analisis dinamico).', 'Ataque automatizado', 'https://www.zaproxy.org'],
    ['Nikto', 'Escaneo de servidor web: archivos peligrosos, cabeceras, configuracion.', 'Reconocimiento', 'https://cirt.net/Nikto2'],
    ['SQLMap', 'Deteccion y explotacion de inyeccion SQL.', 'Ataque a BD', 'https://sqlmap.org'],
    ['Mozilla Observatory', 'Verificacion de cabeceras de seguridad HTTP.', 'Analisis de cabeceras', 'https://observatory.mozilla.org'],
];

$hallazgos = [
    [
        'vuln' => 'Inyeccion SQL (SQLi)',
        'sev'  => 'Critica',
        'como' => "Se envio el parametro 'username' de login.php con cargas como ' OR '1'='1 y 1' UNION SELECT ...--.",
        'res'  => 'ZAP no logro inyectar codigo: la aplicacion trata la entrada como dato.',
        'sol'  => 'Uso exclusivo de sentencias preparadas con PDO (ATTR_EMULATE_PREPARES = false) en todos los accesos a la base de datos.',
        'estado' => 'Mitigada',
    ],
    [
        'vuln' => 'Cross-Site Scripting (XSS) almacenado',
        'sev'  => 'Alta',
        'como' => 'Se guardo como nota el valor <script>alert(document.cookie)</script>.',
        'res'  => 'El script no se ejecuta: se visualiza como texto literal en la lista de notas.',
        'sol'  => 'Escape de toda la salida con htmlspecialchars() (funcion e()) y cabecera Content-Security-Policy.',
        'estado' => 'Mitigada',
    ],
    [
        'vuln' => 'Cross-Site Request Forgery (CSRF)',
        'sev'  => 'Alta',
        'como' => 'Se genero una pagina externa que enviaba peticiones POST a notas.php y logout.php.',
        'res'  => 'Las peticiones fueron rechazadas con HTTP 403 (token CSRF ausente o invalido).',
        'sol'  => 'Token aleatorio por sesion validado con hash_equals() en cada formulario; logout solo por POST.',
        'estado' => 'Mitigada',
    ],
    [
        'vuln' => 'Fijacion de sesion (Session Fixation)',
        'sev'  => 'Alta',
        'como' => 'Se intento reutilizar un ID de sesion conocido antes del login.',
        'res'  => 'El ID de sesion cambia tras autenticarse; el anterior deja de ser valido.',
        'sol'  => 'session_regenerate_id(true) al iniciar sesion y modo estricto de sesiones.',
        'estado' => 'Mitigada',
    ],
    [
        'vuln' => 'Fuerza bruta / Credential Stuffing',
        'sev'  => 'Alta',
        'como' => 'Multiples intentos de login fallidos con el mismo usuario/IP.',
        'res'  => 'Tras 5 intentos se bloquea el acceso temporalmente durante 15 minutos.',
        'sol'  => 'Contador de intentos en la tabla intentos_login + bloqueo temporal + auditoria.',
        'estado' => 'Mitigada',
    ],
    [
        'vuln' => 'Exposicion de contrasenas en texto plano',
        'sev'  => 'Critica',
        'como' => 'Inspeccion de la tabla usuarios en la base de datos.',
        'res'  => 'No se almacenan contrasenas: solo el hash bcrypt (ej. $2y$12$...).',
        'sol'  => 'password_hash() con bcrypt (cost 12) y password_verify(); rehash automatico si cambia el coste.',
        'estado' => 'Mitigada',
    ],
    [
        'vuln' => 'Cabeceras de seguridad HTTP ausentes',
        'sev'  => 'Media',
        'como' => 'Reporte de cabeceras de Mozilla Observatory / ZAP.',
        'res'  => 'Se anaden las cabeceras recomendadas en cada respuesta.',
        'sol'  => 'X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, CSP y HSTS (bajo HTTPS).',
        'estado' => 'Mitigada',
    ],
    [
        'vuln' => 'Control de acceso deficiente (IDOR)',
        'sev'  => 'Alta',
        'como' => 'Se intento borrar la nota de otro usuario cambiando el id en el POST.',
        'res'  => 'La sentencia DELETE incluye usuario_id = sesion: no se elimina nada ajeno.',
        'sol'  => 'Verificacion de propiedad del recurso y del rol (exigir_rol) en cada operacion sensible.',
        'estado' => 'Mitigada',
    ],
    [
        'vuln' => 'Cookies de sesion accesibles por JavaScript',
        'sev'  => 'Media',
        'como' => 'Comprobacion de los atributos de la cookie de sesion.',
        'res'  => 'La cookie se marca HttpOnly y SameSite=Strict (y Secure bajo HTTPS).',
        'sol'  => 'session_set_cookie_params() con httponly, samesite y secure cuando aplica.',
        'estado' => 'Mitigada',
    ],
    [
        'vuln' => 'Metodos HTTP no seguros (TRACE/TRACK)',
        'sev'  => 'Baja',
        'como' => 'Enumeracion de metodos por Nikto.',
        'res'  => 'Se recomienda deshabilitarlos en el servidor.',
        'sol'  => 'Configuracion del servidor web: TraceEnable off (Apache) / metodos permitidos en Nginx.',
        'estado' => 'Recomendada',
    ],
];

$titulo = 'Fase 4 - Analisis de vulnerabilidades';
require __DIR__ . '/includes/header.php';
?>
<section class="encabezado-seccion">
    <h1>Fase 4 &middot; Analisis de vulnerabilidades</h1>
    <p>Pruebas realizadas con herramientas de analisis y las soluciones tecnicas aplicadas al sitio.</p>
</section>

<div class="tarjeta">
    <h2>1. Herramientas utilizadas</h2>
    <div class="tabla-wrap">
        <table class="tabla">
            <thead><tr><th>Herramienta</th><th>Funcion</th><th>Tipo</th><th>Enlace</th></tr></thead>
            <tbody>
            <?php foreach ($herramientas as $h): ?>
                <tr>
                    <td><strong><?= e($h[0]) ?></strong></td>
                    <td><?= e($h[1]) ?></td>
                    <td><?= e($h[2]) ?></td>
                    <td><a href="<?= e($h[3]) ?>" target="_blank" rel="noopener noreferrer"><?= e($h[3]) ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="tarjeta">
    <h2>2. Hallazgos, evidencia y soluciones</h2>
    <p class="ayuda">Severidad segun OWASP: Critica &gt; Alta &gt; Media &gt; Baja.</p>
    <div class="tabla-wrap">
        <table class="tabla">
            <thead>
                <tr><th>Vulnerabilidad</th><th>Sev.</th><th>Como se probo</th>
                    <th>Resultado</th><th>Solucion aplicada</th><th>Estado</th></tr>
            </thead>
            <tbody>
            <?php foreach ($hallazgos as $h): ?>
                <tr>
                    <td><strong><?= e($h['vuln']) ?></strong></td>
                    <td><span class="sev sev--<?= strtolower($h['sev']) ?>"><?= e($h['sev']) ?></span></td>
                    <td><?= e($h['como']) ?></td>
                    <td><?= e($h['res']) ?></td>
                    <td><?= e($h['sol']) ?></td>
                    <td><span class="badge badge--ok"><?= e($h['estado']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="grid grid--2">
    <div class="tarjeta">
        <h2>3. Como reproducir el analisis</h2>
        <ol class="pasos">
            <li>Levanta el sitio (Apache/Laragon o el servidor de PHP).</li>
            <li>En <strong>OWASP ZAP</strong>: <em>Automated Scan</em> sobre <code>http://localhost/seguridad-web/</code>.</li>
            <li>En <strong>Nikto</strong>: <code>nikto -h http://localhost</code>.</li>
            <li>Prueba manual de XSS: crea una nota con <code>&lt;script&gt;alert(1)&lt;/script&gt;</code>.</li>
            <li>Prueba manual de SQLi: en el login usa <code>admin' OR '1'='1</code> como usuario.</li>
            <li>Prueba de CSRF: intenta enviar un POST sin el campo <code>csrf_token</code>.</li>
            <li>Revisa las cabeceras con <em>Mozilla Observatory</em> o <code>curl -I</code>.</li>
        </ol>
    </div>
    <div class="tarjeta">
        <h2>4. Mejoras tecnicas propuestas</h2>
        <ul class="checklist">
            <li><span class="ok">&#10003;</span> HTTPS con certificado y <strong>HSTS</strong> (Fase 2).</li>
            <li><span class="ok">&#10003;</span> Autenticacion en <strong>dos pasos (2FA/TOTP)</strong>.</li>
            <li><span class="ok">&#10003;</span> Cabecera <strong>Content-Security-Policy</strong> mas estricta y sin inline.</li>
            <li><span class="ok">&#10003;</span> Limite de peticiones (rate limiting) por IP.</li>
            <li><span class="ok">&#10003;</span> Rotacion de logs y alertas ante accesos denegados.</li>
            <li><span class="ok">&#10003;</span> Copias de seguridad cifradas y PRUEBAS de restauracion.</li>
            <li><span class="ok">&#10003;</span> Gestion de secretos fuera del codigo (.env).</li>
        </ul>
    </div>
</div>

<div class="tarjeta">
    <h2>5. Mapeo con OWASP Top 10</h2>
    <div class="tabla-wrap">
        <table class="tabla">
            <thead><tr><th>Categoria OWASP</th><th>Mitigacion en este proyecto</th></tr></thead>
            <tbody>
                <tr><td>A01 Control de acceso</td><td>exigir_rol() + verificacion de propiedad (usuario_id).</td></tr>
                <tr><td>A02 Fallos criptograficos</td><td>bcrypt, cookies Secure/HttpOnly, HSTS.</td></tr>
                <tr><td>A03 Inyeccion</td><td>PDO preparado + escape de salida.</td></tr>
                <tr><td>A04 Diseno inseguro</td><td>Politica de contrasenas y rate limiting.</td></tr>
                <tr><td>A05 Configuracion incorrecta</td><td>Cabeceras de seguridad y errores sin detalles en produccion.</td></tr>
                <tr><td>A07 Fallos de identificacion</td><td>Mensajes genericos, bloqueo por intentos, regeneracion de sesion.</td></tr>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
