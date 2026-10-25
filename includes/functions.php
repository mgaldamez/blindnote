<?php
/**
 * Funciones de apoyo y utilidades de seguridad.
 * Fase 3 (autenticacion) + Fase 4 (mitigacion de SQLi, XSS, CSRF).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/* =====================================================================
 *  SESIONES SEGURAS
 * ===================================================================== */

/**
 * Inicia la sesion con parametros endurecidos.
 * Debe llamarse una sola vez, antes de cualquier salida.
 */
function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // No aceptar IDs de sesion inventados por el cliente.
    ini_set('session.use_strict_mode', '1');
    // La cookie de sesion no es accesible desde JavaScript (mitiga robo via XSS).
    ini_set('session.cookie_httponly', '1');
    // Enviar la cookie solo por HTTPS cuando este disponible.
    ini_set('session.cookie_samesite', 'Strict');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        // Secure debe ser true bajo HTTPS (Fase 2). Configurable.
        'secure'   => COOKIE_SOLO_HTTPS,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}

/**
 * Expira la sesion por inactividad y por vida maxima.
 * Si expira, limpia y obliga a volver a iniciar sesion.
 */
function verificar_expiracion_sesion(): void
{
    if (empty($_SESSION['usuario_id'])) {
        return;
    }

    $ahora = time();

    if (isset($_SESSION['ultima_actividad'])
        && ($ahora - $_SESSION['ultima_actividad']) > SESION_INACTIVIDAD_MIN * 60) {
        cerrar_sesion('sesion_expirada');
        redirigir('login.php?aviso=sesion_expirada');
    }

    if (isset($_SESSION['inicio_sesion'])
        && ($ahora - $_SESSION['inicio_sesion']) > SESION_VIDA_MAX_HORAS * 3600) {
        cerrar_sesion('sesion_expirada');
        redirigir('login.php?aviso=sesion_expirada');
    }

    $_SESSION['ultima_actividad'] = $ahora;
}

/**
 * Destruye la sesion y limpia la cookie.
 */
function cerrar_sesion(string $motivo = 'logout'): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        iniciar_sesion();
    }

    $usuarioId = $_SESSION['usuario_id'] ?? null;
    if ($usuarioId !== null) {
        registrar_auditoria((int) $usuarioId, $motivo === 'logout' ? 'LOGOUT' : 'SESION_EXPIRADA');
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Strict',
            ]
        );
    }

    session_destroy();
}

/* =====================================================================
 *  CSRF (Cross-Site Request Forgery)
 * ===================================================================== */

/**
 * Devuelve (creando si hace falta) el token CSRF de la sesion.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Devuelve el campo oculto listo para insertar en un formulario.
 */
function csrf_campo(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Valida el token recibido. Termina la peticion si no coincide.
 */
function csrf_verificar(?string $token): void
{
    if (empty($_SESSION['csrf_token']) || !is_string($token)
        || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        registrar_auditoria($_SESSION['usuario_id'] ?? null, 'CSRF_BLOQUEADO', 'Token invalido en ' . ($_SERVER['REQUEST_URI'] ?? '?'));
        die('Solicitud rechazada: token CSRF invalido o expirado.');
    }
}

/* =====================================================================
 *  XSS (Cross-Site Scripting)
 * ===================================================================== */

/**
 * Escapa texto para mostrarlo de forma segura en HTML.
 * TODA salida de datos del usuario debe pasar por esta funcion.
 */
function e(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* =====================================================================
 *  HELPERS GENERALES
 * ===================================================================== */

function redirigir(string $ruta): never
{
    header('Location: ' . $ruta);
    exit;
}

function ip_cliente(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function post(string $campo, string $default = ''): string
{
    return trim((string) ($_POST[$campo] ?? $default));
}

function esta_autenticado(): bool
{
    return !empty($_SESSION['usuario_id']);
}

/**
 * Bloquea doble envio y peticiones que no sean POST.
 */
function exigir_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        die('Metodo no permitido. Esta accion requiere POST.');
    }
}

/* =====================================================================
 *  POLITICA DE CONTRASENAS
 * ===================================================================== */

/**
 * Valida la fortaleza de la contrasena y devuelve la lista de errores.
 * @return string[]
 */
function validar_contrasena(string $pass): array
{
    $errores = [];
    if (strlen($pass) < PASS_MIN_LEN) {
        $errores[] = 'Debe tener al menos ' . PASS_MIN_LEN . ' caracteres.';
    }
    if (!preg_match('/[A-Z]/', $pass)) {
        $errores[] = 'Debe incluir al menos una letra mayuscula.';
    }
    if (!preg_match('/[a-z]/', $pass)) {
        $errores[] = 'Debe incluir al menos una letra minuscula.';
    }
    if (!preg_match('/[0-9]/', $pass)) {
        $errores[] = 'Debe incluir al menos un numero.';
    }
    return $errores;
}

/* =====================================================================
 *  AUDITORIA Y CONTROL DE FUERZA BRUTA
 * ===================================================================== */

function registrar_auditoria(?int $usuarioId, string $accion, ?string $detalle = null): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO logs_auditoria (usuario_id, accion, detalle, ip)
             VALUES (:usuario_id, :accion, :detalle, :ip)'
        );
        $stmt->execute([
            ':usuario_id' => $usuarioId,
            ':accion'     => $accion,
            ':detalle'    => $detalle,
            ':ip'         => ip_cliente(),
        ]);
    } catch (Throwable $e) {
        // La auditoria nunca debe tumbar la app.
    }
}

function registrar_intento_login(string $username, bool $exitoso): void
{
    $stmt = db()->prepare(
        'INSERT INTO intentos_login (username, ip, exitoso, user_agent)
         VALUES (:username, :ip, :exitoso, :ua)'
    );
    $stmt->execute([
        ':username' => mb_substr($username, 0, 150),
        ':ip'       => ip_cliente(),
        ':exitoso'  => $exitoso ? 1 : 0,
        ':ua'       => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);
}

/**
 * Cuenta intentos fallidos recientes para aplicar el bloqueo temporal.
 *
 * Politica (POLITICA_SEGURIDAD.md): 5 intentos fallidos en 15 minutos
 * POR CUENTA **E** IP. La condicion es conjuntiva (AND): los fallos de
 * otras cuentas desde la misma IP no deben bloquear a un usuario legitimo
 * que comparte esa IP (NAT, proxy inverso, laboratorio con ZAP en el mismo
 * host). Con OR, cualquier IP acumulaba un unico balde global y cinco fallos
 * de nombres ajenos bloqueaban a todos los usuarios de esa IP.
 *
 * El conteo recibe TODOS los identificadores de la cuenta (usuario y correo)
 * porque la tabla guarda el texto que se tipeo: sin agruparlos, alternar
 * "ana" y "ana@ejemplo.com" duplicaria los intentos y el bloqueo no se
 * trasladaria de un alias al otro.
 *
 * @param list<string> $identificadores
 */
function intentos_fallidos_recientes(array $identificadores, string $ip): int
{
    $valores = [];
    foreach ($identificadores as $identificador) {
        $valor = trim((string) $identificador);
        if ($valor !== '' && !in_array($valor, $valores, true)) {
            $valores[] = $valor;
        }
    }

    if ($valores === []) {
        return 0;
    }

    $marcas = [];
    foreach (array_keys($valores) as $indice) {
        $marcas[] = ':identificador' . $indice;
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*) AS total
           FROM intentos_login
          WHERE username IN (' . implode(', ', $marcas) . ')
            AND ip = :ip
            AND exitoso = 0
            AND fecha > (NOW() - INTERVAL :minutos MINUTE)'
    );
    foreach ($valores as $indice => $valor) {
        $stmt->bindValue(':identificador' . $indice, $valor);
    }
    $stmt->bindValue(':ip', $ip);
    $stmt->bindValue(':minutos', MINUTOS_BLOQUEO, PDO::PARAM_INT);
    $stmt->execute();

    return (int) ($stmt->fetch()['total'] ?? 0);
}
