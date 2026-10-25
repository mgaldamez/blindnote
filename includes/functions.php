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

/**
 * Indica si una direccion es un proxy confiable (loopback o red privada).
 *
 * Detras de nginx/Caddy el contenedor ve la IP del proxy, no la del visitante.
 * Solo en ese caso se acepta la cabecera que trae la IP real: si la peticion
 * llega directo desde Internet, la cabecera es falsificable y se ignora.
 */
function es_proxy_confiable(string $ip): bool
{
    if ($ip === '::1') {
        return true;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }

    return str_starts_with($ip, '127.')
        || str_starts_with($ip, '10.')
        || str_starts_with($ip, '192.168.')
        || preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip) === 1;
}

/**
 * IP del cliente, respetando el proxy inverso cuando corresponde.
 *
 * El despliegue publica la app solo en loopback y nginx hace de proxy: ahi
 * REMOTE_ADDR es el proxy y la IP real llega en X-Real-IP (o en el ultimo
 * elemento de X-Forwarded-For, el que agrega el proxy). Sin esta lectura el
 * control de fuerza bruta por IP ve una sola IP para todo el sitio.
 *
 * Requisito: el puerto de la app NO debe estar expuesto al publico, porque
 * cualquiera que la alcance directo desde una red privada podria falsificar
 * la cabecera y saltear el limite por IP.
 */
function ip_cliente(): string
{
    $remota = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (filter_var($remota, FILTER_VALIDATE_IP) === false) {
        $remota = '0.0.0.0';
    }

    if (!es_proxy_confiable($remota)) {
        return $remota;
    }

    // X-Real-IP lo escribe el proxy con $remote_addr: no es falsificable.
    $real = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    if (is_string($real) && filter_var(trim($real), FILTER_VALIDATE_IP) !== false) {
        return trim($real);
    }

    // X-Forwarded-For puede ser una lista: el ultimo elemento es el que agrega
    // el proxy (los anteriores los puede inyectar el cliente).
    $cadena = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($cadena) && $cadena !== '') {
        $partes = explode(',', $cadena);
        $ultima = trim((string) end($partes));
        if (filter_var($ultima, FILTER_VALIDATE_IP) !== false) {
            return $ultima;
        }
    }

    return $remota;
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
 * Lista usuarios con busqueda, filtros y paginacion.
 *
 * Devuelve array{total:int, usuarios:list<array>, pagina:int, porPagina:int,
 * totalPaginas:int}. Los filtros y la pagina ya vienen saneados por el
 * llamador; aca solo se construye la consulta con sentencias preparadas.
 *
 * @param array{busqueda?:string, rol?:string, estado?:string} $filtros
 * @return array{total:int, usuarios:list<array<string,mixed>>, pagina:int, porPagina:int, totalPaginas:int}
 */
function listar_usuarios(array $filtros, int $pagina = 1, int $porPagina = USUARIOS_POR_PAGINA): array
{
    $porPagina = max(1, $porPagina);

    $condiciones = [];
    $parametros  = [];

    $busqueda = trim((string) ($filtros['busqueda'] ?? ''));
    if ($busqueda !== '') {
        // MySQL nativo (EMULATE_PREPARES=false) exige un placeholder por
        // aparicion: no se puede repetir el mismo nombre tres veces.
        $condiciones[] = '(nombre LIKE :q1 OR username LIKE :q2 OR email LIKE :q3)';
        $parametros[':q1'] = '%' . $busqueda . '%';
        $parametros[':q2'] = '%' . $busqueda . '%';
        $parametros[':q3'] = '%' . $busqueda . '%';
    }

    $rol = (string) ($filtros['rol'] ?? '');
    if (in_array($rol, ['admin', 'usuario'], true)) {
        $condiciones[] = 'rol = :rol';
        $parametros[':rol'] = $rol;
    }

    $estado = (string) ($filtros['estado'] ?? 'todos');
    if ($estado === 'activos') {
        $condiciones[] = 'activo = 1';
    } elseif ($estado === 'inactivos') {
        $condiciones[] = 'activo = 0';
    }

    $where = $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones);

    $stmt = db()->prepare('SELECT COUNT(*) AS total FROM usuarios' . $where);
    $stmt->execute($parametros);
    $total = (int) ($stmt->fetch()['total'] ?? 0);

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min(max(1, $pagina), $totalPaginas);
    $desplazamiento = ($pagina - 1) * $porPagina;

    $stmt = db()->prepare(
        'SELECT id, nombre, username, email, rol, activo, ultimo_login, creado_en
           FROM usuarios' . $where . '
          ORDER BY id
          LIMIT :limite OFFSET :desplazamiento'
    );
    foreach ($parametros as $clave => $valor) {
        $stmt->bindValue($clave, $valor);
    }
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'total'        => $total,
        'usuarios'     => $stmt->fetchAll(),
        'pagina'       => $pagina,
        'porPagina'    => $porPagina,
        'totalPaginas' => $totalPaginas,
    ];
}

/**
 * Devuelve la fecha en formato Y-m-d si el valor es una fecha valida.
 * Se usa para los filtros de fecha y evita inyectar valores raros.
 */
function fecha_valida(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') {
        return null;
    }

    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

    return ($fecha !== false && $fecha->format('Y-m-d') === $valor) ? $valor : null;
}

/**
 * Construye el WHERE de la auditoria a partir de los filtros.
 * Los valores invalidos se ignoran y todo se pasa como parametro ligado.
 *
 * @param array{accion?:string, usuario?:string, desde?:string, hasta?:string} $filtros
 * @return array{where:string, parametros:array<string,string>}
 */
function condiciones_auditoria(array $filtros): array
{
    $condiciones = [];
    $parametros  = [];

    $accion = trim((string) ($filtros['accion'] ?? ''));
    if ($accion !== '') {
        $condiciones[] = 'l.accion = :accion';
        $parametros[':accion'] = $accion;
    }

    $usuario = trim((string) ($filtros['usuario'] ?? ''));
    if ($usuario === '__anonimo__') {
        $condiciones[] = 'l.usuario_id IS NULL';
    } elseif ($usuario !== '') {
        $condiciones[] = 'u.username = :usuario';
        $parametros[':usuario'] = $usuario;
    }

    $desde = fecha_valida((string) ($filtros['desde'] ?? ''));
    if ($desde !== null) {
        $condiciones[] = 'l.fecha >= :desde';
        $parametros[':desde'] = $desde . ' 00:00:00';
    }

    $hasta = fecha_valida((string) ($filtros['hasta'] ?? ''));
    if ($hasta !== null) {
        $condiciones[] = 'l.fecha <= :hasta';
        $parametros[':hasta'] = $hasta . ' 23:59:59';
    }

    return [
        'where'      => $condiciones === [] ? '' : ' WHERE ' . implode(' AND ', $condiciones),
        'parametros' => $parametros,
    ];
}

/**
 * Prepara una celda para CSV.
 *
 * Neutraliza las formulas: una celda que empieza con =, +, -, @, tab o CR puede
 * ser ejecutada por la planilla (CSV injection), asi que se antepone un
 * apostrofo. Un numero negativo tambien lo lleva: es el precio de no poder
 * distinguirlo de una formula.
 * Entrecomilla cuando el valor trae coma, comilla o salto de linea, y duplica
 * las comillas internas (RFC 4180).
 */
function campo_csv(string $valor): string
{
    if ($valor !== '' && strpbrk($valor[0], "=+-@\t\r") !== false) {
        $valor = "'" . $valor;
    }

    if (strpbrk($valor, ",\"\n\r") !== false) {
        $valor = '"' . str_replace('"', '""', $valor) . '"';
    }

    return $valor;
}

/**
 * Arma una linea CSV (con salto de linea final) a partir de los campos.
 *
 * @param list<string|int|null> $campos
 */
function linea_csv(array $campos): string
{
    $celdas = array_map(
        static fn ($valor): string => campo_csv((string) ($valor ?? '')),
        $campos
    );

    return implode(',', $celdas) . "\n";
}

/**
 * Filas de auditoria que el filtro selecciona, sin paginar, para exportar.
 *
 * @param array{accion?:string, usuario?:string, desde?:string, hasta?:string} $filtros
 * @return list<array<string,mixed>>
 */
function filas_auditoria_para_csv(array $filtros): array
{
    ['where' => $where, 'parametros' => $parametros] = condiciones_auditoria($filtros);

    $stmt = db()->prepare(
        'SELECT l.fecha, u.username, l.accion, l.detalle, l.ip
           FROM logs_auditoria l LEFT JOIN usuarios u ON u.id = l.usuario_id'
        . $where
        . ' ORDER BY l.id DESC'
    );
    $stmt->execute($parametros);

    return $stmt->fetchAll();
}

/**
 * Lista la auditoria con filtros y paginacion.
 *
 * Filtros: accion (coincidencia exacta), usuario (nombre exacto o
 * `__anonimo__` para los eventos sin usuario) y desde/hasta (fechas Y-m-d,
 * ambas inclusive). Los valores invalidos se ignoran.
 *
 * @param array{accion?:string, usuario?:string, desde?:string, hasta?:string} $filtros
 * @return array{total:int, filas:list<array<string,mixed>>, pagina:int, porPagina:int, totalPaginas:int}
 */
function listar_auditoria(array $filtros, int $pagina = 1, int $porPagina = AUDITORIA_POR_PAGINA): array
{
    $porPagina = max(1, $porPagina);
    ['where' => $where, 'parametros' => $parametros] = condiciones_auditoria($filtros);
    $tablas = ' FROM logs_auditoria l LEFT JOIN usuarios u ON u.id = l.usuario_id';

    $stmt = db()->prepare('SELECT COUNT(*) AS total' . $tablas . $where);
    $stmt->execute($parametros);
    $total = (int) ($stmt->fetch()['total'] ?? 0);

    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min(max(1, $pagina), $totalPaginas);
    $desplazamiento = ($pagina - 1) * $porPagina;

    $stmt = db()->prepare(
        'SELECT l.id, l.fecha, l.accion, l.detalle, l.ip, u.username' . $tablas . $where
        . ' ORDER BY l.id DESC LIMIT :limite OFFSET :desplazamiento'
    );
    foreach ($parametros as $clave => $valor) {
        $stmt->bindValue($clave, $valor);
    }
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':desplazamiento', $desplazamiento, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'total'        => $total,
        'filas'        => $stmt->fetchAll(),
        'pagina'       => $pagina,
        'porPagina'    => $porPagina,
        'totalPaginas' => $totalPaginas,
    ];
}

/**
 * Acciones registradas en la auditoria, para poblar el filtro.
 *
 * @return list<string>
 */
function acciones_auditoria(int $limite = 200): array
{
    $stmt = db()->prepare('SELECT DISTINCT accion FROM logs_auditoria ORDER BY accion LIMIT :limite');
    $stmt->bindValue(':limite', max(1, $limite), PDO::PARAM_INT);
    $stmt->execute();

    return array_map(static fn (array $f): string => (string) $f['accion'], $stmt->fetchAll());
}

/**
 * Usuarios que aparecen en la auditoria, para poblar el filtro.
 *
 * @return list<string>
 */
function usuarios_auditoria(int $limite = 200): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT u.username
           FROM logs_auditoria l
           JOIN usuarios u ON u.id = l.usuario_id
          WHERE u.username IS NOT NULL
          ORDER BY u.username
          LIMIT :limite'
    );
    $stmt->bindValue(':limite', max(1, $limite), PDO::PARAM_INT);
    $stmt->execute();

    return array_map(static fn (array $f): string => (string) $f['username'], $stmt->fetchAll());
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

/**
 * Cuenta TODOS los fallos recientes de una IP, sin importar la cuenta.
 *
 * Es el tope secundario contra password spraying: si el limite fuera solo por
 * cuenta, un atacante podria probar la misma contrasena contra infinitos
 * usuarios desde una sola IP sin gastar el balde de ninguno.
 *
 * El umbral (MAX_INTENTOS_POR_IP) es mucho mas alto que el de cuenta para no
 * volver al problema de bloquear a todos los usuarios detras de un NAT.
 */
function intentos_fallidos_por_ip(string $ip): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS total
           FROM intentos_login
          WHERE ip = :ip
            AND exitoso = 0
            AND fecha > (NOW() - INTERVAL :minutos MINUTE)'
    );
    $stmt->bindValue(':ip', $ip);
    $stmt->bindValue(':minutos', MINUTOS_BLOQUEO, PDO::PARAM_INT);
    $stmt->execute();

    return (int) ($stmt->fetch()['total'] ?? 0);
}
