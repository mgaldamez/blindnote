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
 * Valida el titulo y el contenido de una nota.
 *
 * @return list<string> mensajes de error; vacio si esta todo bien
 */
function validar_nota(string $titulo, string $contenido): array
{
    $errores = [];

    if (trim($titulo) === '') {
        $errores[] = 'El titulo es obligatorio.';
    } elseif (mb_strlen(trim($titulo)) > 150) {
        $errores[] = 'El titulo no puede superar los 150 caracteres.';
    }

    if (trim($contenido) === '') {
        $errores[] = 'El contenido no puede estar vacio.';
    }

    return $errores;
}

/**
 * Crea una nota del usuario indicado.
 *
 * @return array{ok:bool, errores:list<string>, id:int}
 */
function crear_nota(int $usuarioId, string $titulo, string $contenido): array
{
    $errores = validar_nota($titulo, $contenido);
    if ($errores !== []) {
        return ['ok' => false, 'errores' => $errores, 'id' => 0];
    }

    $titulo = trim($titulo);
    db()->prepare(
        'INSERT INTO notas (usuario_id, titulo, contenido) VALUES (:uid, :titulo, :contenido)'
    )->execute([':uid' => $usuarioId, ':titulo' => $titulo, ':contenido' => $contenido]);

    $id = (int) db()->lastInsertId();
    registrar_auditoria($usuarioId, 'NOTA_CREADA', mb_substr($titulo, 0, 100));

    return ['ok' => true, 'errores' => [], 'id' => $id];
}

/**
 * Devuelve una nota solo si pertenece al usuario (anti-IDOR).
 *
 * @return array<string,mixed>|null
 */
function obtener_nota(int $usuarioId, int $notaId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, titulo, contenido, completada, completada_en, fijada, creado_en
           FROM notas
          WHERE id = :id AND usuario_id = :uid'
    );
    $stmt->execute([':id' => $notaId, ':uid' => $usuarioId]);

    return $stmt->fetch() ?: null;
}

/**
 * Actualiza titulo y contenido de una nota propia.
 *
 * @return array{ok:bool, errores:list<string>}
 */
function actualizar_nota(int $usuarioId, int $notaId, string $titulo, string $contenido): array
{
    $actual = obtener_nota($usuarioId, $notaId);
    if ($actual === null) {
        return ['ok' => false, 'errores' => ['La nota no existe.']];
    }

    $errores = validar_nota($titulo, $contenido);
    if ($errores !== []) {
        return ['ok' => false, 'errores' => $errores];
    }

    $titulo = trim($titulo);
    db()->prepare(
        'UPDATE notas SET titulo = :titulo, contenido = :contenido
          WHERE id = :id AND usuario_id = :uid'
    )->execute([
        ':titulo'    => $titulo,
        ':contenido' => $contenido,
        ':id'        => $notaId,
        ':uid'       => $usuarioId,
    ]);

    registrar_auditoria($usuarioId, 'NOTA_EDITADA', 'id=' . $notaId);

    return ['ok' => true, 'errores' => []];
}

/**
 * Marca o desmarca una nota propia como completada.
 * Al reabrir se limpia la fecha de completado.
 *
 * @return array{ok:bool, errores:list<string>}
 */
function cambiar_estado_nota(int $usuarioId, int $notaId, bool $completada): array
{
    if (obtener_nota($usuarioId, $notaId) === null) {
        return ['ok' => false, 'errores' => ['La nota no existe.']];
    }

    db()->prepare(
        'UPDATE notas
            SET completada = :completada,
                completada_en = ' . ($completada ? 'NOW()' : 'NULL') . '
          WHERE id = :id AND usuario_id = :uid'
    )->execute([
        ':completada' => $completada ? 1 : 0,
        ':id'         => $notaId,
        ':uid'        => $usuarioId,
    ]);

    registrar_auditoria(
        $usuarioId,
        $completada ? 'NOTA_COMPLETADA' : 'NOTA_REABIERTA',
        'id=' . $notaId
    );

    return ['ok' => true, 'errores' => []];
}

/**
 * Lista las notas del usuario segun su estado.
 * Las pendientes van por creacion y las completadas por fecha de completado.
 *
 * @return list<array<string,mixed>>
 */
function listar_notas(int $usuarioId, bool $completadas): array
{
    $orden = $completadas ? 'fijada DESC, completada_en DESC, id DESC' : 'fijada DESC, id DESC';
    $stmt = db()->prepare(
        'SELECT id, titulo, contenido, completada, completada_en, fijada, creado_en
           FROM notas
          WHERE usuario_id = :uid AND completada = :completada
          ORDER BY ' . $orden
    );
    $stmt->execute([':uid' => $usuarioId, ':completada' => $completadas ? 1 : 0]);

    return $stmt->fetchAll();
}

/**
 * Cantidad de notas pendientes y completadas del usuario.
 *
 * @return array{pendientes:int, completadas:int}
 */
function contar_notas_por_estado(int $usuarioId): array
{
    $stmt = db()->prepare(
        'SELECT SUM(completada = 0) AS pendientes,
                SUM(completada = 1) AS completadas,
                SUM(fijada = 1) AS fijadas
           FROM notas WHERE usuario_id = :uid'
    );
    $stmt->execute([':uid' => $usuarioId]);
    $fila = $stmt->fetch() ?: [];

    return [
        'pendientes'  => (int) ($fila['pendientes'] ?? 0),
        'completadas' => (int) ($fila['completadas'] ?? 0),
        'fijadas'     => (int) ($fila['fijadas'] ?? 0),
    ];
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


/**
 * Alterna el estado fijada (pin) de una nota propia.
 *
 * @return array{ok:bool, fijada:bool, errores:list<string>}
 */
function fijar_nota(int $usuarioId, int $notaId): array
{
    $nota = obtener_nota($usuarioId, $notaId);
    if ($nota === null) {
        return ['ok' => false, 'fijada' => false, 'errores' => ['La nota no existe.']];
    }

    return establecer_fijada($usuarioId, $notaId, empty($nota['fijada']));
}

/**
 * Deja la nota en el estado de fijada pedido, sin alternar.
 *
 * Solo actua sobre notas del usuario (anti-IDOR) y registra la auditoria.
 *
 * @return array{ok:bool, fijada:bool, errores:list<string>}
 */
function establecer_fijada(int $usuarioId, int $notaId, bool $fijada): array
{
    $nota = obtener_nota($usuarioId, $notaId);
    if ($nota === null) {
        return ['ok' => false, 'fijada' => false, 'errores' => ['La nota no existe.']];
    }

    $valor = $fijada ? 1 : 0;
    if ((int) $nota['fijada'] === $valor) {
        return ['ok' => true, 'fijada' => $fijada, 'errores' => []];
    }

    db()->prepare('UPDATE notas SET fijada = :f WHERE id = :id AND usuario_id = :uid')
        ->execute([':f' => $valor, ':id' => $notaId, ':uid' => $usuarioId]);

    registrar_auditoria(
        $usuarioId,
        $valor === 1 ? 'NOTA_FIJADA' : 'NOTA_DESFIJADA',
        'id=' . $notaId
    );

    return ['ok' => true, 'fijada' => $fijada, 'errores' => []];
}

/**
 * Guarda una nota desde el formulario (crear o editar) y aplica el pin.
 *
 * El controlador nunca calcula el id: crear_nota() lo devuelve y ademas
 * escribe en logs_auditoria, asi que un db()->lastInsertId() posterior en la
 * pagina apuntaria al ultimo log y fijaria la nota equivocada.
 *
 * @param int|null $notaId null para crear, id de la nota para editar.
 * @return array{ok:bool, errores:list<string>, id:int}
 */
function guardar_nota_form(
    int $usuarioId,
    ?int $notaId,
    string $titulo,
    string $contenido,
    bool $fijada
): array {
    if ($notaId === null) {
        $resultado = crear_nota($usuarioId, $titulo, $contenido);
        if (!$resultado['ok']) {
            return ['ok' => false, 'errores' => $resultado['errores'], 'id' => 0];
        }

        $id = (int) $resultado['id'];
        if ($fijada) {
            establecer_fijada($usuarioId, $id, true);
        }

        return ['ok' => true, 'errores' => [], 'id' => $id];
    }

    $resultado = actualizar_nota($usuarioId, $notaId, $titulo, $contenido);
    if (!$resultado['ok']) {
        return ['ok' => false, 'errores' => $resultado['errores'], 'id' => $notaId];
    }

    establecer_fijada($usuarioId, $notaId, $fijada);

    return ['ok' => true, 'errores' => [], 'id' => $notaId];
}

/**
 * Catalogo de avatares interactivos con temas de ciberseguridad.
 *
 * @return array<string, array{id:string, nombre:string, desc:string, color:string, bg:string}>
 */
function avatares_disponibles(): array
{
    return [
        'avatar-shield'    => ['id' => 'avatar-shield',    'nombre' => 'Escudo Protector',    'desc' => 'Defensa perimetral y cifrado', 'color' => '#2563eb', 'bg' => '#dbeafe'],
        'avatar-ninja'     => ['id' => 'avatar-ninja',     'nombre' => 'Ninja de Seguridad',  'desc' => 'Sigilo, monitoreo e intrusion', 'color' => '#7c3aed', 'bg' => '#ede9fe'],
        'avatar-hacker'    => ['id' => 'avatar-hacker',    'nombre' => 'Hacker Etico',        'desc' => 'Pentesting y analisis de fallas', 'color' => '#059669', 'bg' => '#d1fae5'],
        'avatar-detective' => ['id' => 'avatar-detective', 'nombre' => 'Auditor Forense',     'desc' => 'Inspeccion de logs e investigacion', 'color' => '#d97706', 'bg' => '#fef3c7'],
        'avatar-key'       => ['id' => 'avatar-key',       'nombre' => 'Guardian Cripto',     'desc' => 'Gestion segura de credenciales', 'color' => '#0891b2', 'bg' => '#cffafe'],
        'avatar-robot'     => ['id' => 'avatar-robot',     'nombre' => 'Bot Centinela',       'desc' => 'Automatizacion y alertas tempranas', 'color' => '#475569', 'bg' => '#e2e8f0'],
        'avatar-cat'       => ['id' => 'avatar-cat',       'nombre' => 'Gato Coder',          'desc' => 'Desarrollo seguro y testing', 'color' => '#db2777', 'bg' => '#fce7f3'],
        'avatar-astro'     => ['id' => 'avatar-astro',     'nombre' => 'Explorador Cloud',    'desc' => 'Seguridad en la nube y DevOps', 'color' => '#4f46e5', 'bg' => '#e0e7ff'],
    ];
}

/**
 * Devuelve el SVG vectorial del avatar solicitado o uno neutro por defecto.
 */
function obtener_avatar_svg(?string $avatarId, int $size = 36, string $claseExtra = ''): string
{
    $avatares = avatares_disponibles();
    $info = $avatares[$avatarId ?? ''] ?? null;

    if ($info === null) {
        // Avatar generico seguro (silueta de usuario con acento)
        return '<svg class="avatar-svg ' . e($claseExtra) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 36 36" fill="none" aria-hidden="true">'
            . '<circle cx="18" cy="18" r="18" fill="#e2e8f0"/>'
            . '<circle cx="18" cy="13" r="5.5" fill="#64748b"/>'
            . '<path d="M7 31c0-6 5-9.5 11-9.5s11 3.5 11 9.5" fill="#64748b"/>'
            . '</svg>';
    }

    $bg = $info['bg'];
    $color = $info['color'];

    $iconos = [
        'avatar-shield' => '<path d="M18 7l9 4v7c0 5.5-3.8 10.7-9 12-5.2-1.3-9-6.5-9-12V11l9-4z" fill="' . $color . '" opacity="0.2"/>'
            . '<path d="M18 7l9 4v7c0 5.5-3.8 10.7-9 12-5.2-1.3-9-6.5-9-12V11l9-4z" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<path d="M15 18l2 2 4-4" stroke="' . $color . '" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>',

        'avatar-ninja' => '<circle cx="18" cy="18" r="11" fill="' . $color . '" opacity="0.2"/>'
            . '<path d="M9 16c2-3 16-3 18 0" stroke="' . $color . '" stroke-width="2" stroke-linecap="round"/>'
            . '<path d="M11 20h14" stroke="' . $color . '" stroke-width="2" stroke-linecap="round"/>'
            . '<circle cx="14" cy="18" r="1.5" fill="' . $color . '"/>'
            . '<circle cx="22" cy="18" r="1.5" fill="' . $color . '"/>'
            . '<path d="M15 24c1.5 1 4.5 1 6 0" stroke="' . $color . '" stroke-width="1.8" stroke-linecap="round"/>',

        'avatar-hacker' => '<rect x="8" y="10" width="20" height="16" rx="3" fill="' . $color . '" opacity="0.18"/>'
            . '<rect x="8" y="10" width="20" height="16" rx="3" stroke="' . $color . '" stroke-width="2"/>'
            . '<path d="M12 15l3 3-3 3M17 21h5" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>',

        'avatar-detective' => '<circle cx="16" cy="16" r="6" stroke="' . $color . '" stroke-width="2.2" fill="' . $color . '" opacity="0.15"/>'
            . '<path d="M20.5 20.5L27 27" stroke="' . $color . '" stroke-width="2.5" stroke-linecap="round"/>'
            . '<circle cx="16" cy="16" r="2.5" fill="' . $color . '"/>',

        'avatar-key' => '<circle cx="15" cy="16" r="5" stroke="' . $color . '" stroke-width="2" fill="' . $color . '" opacity="0.2"/>'
            . '<path d="M19 18l7 7m-3-1l2 2m-1-4l2 2" stroke="' . $color . '" stroke-width="2" stroke-linecap="round"/>',

        'avatar-robot' => '<rect x="10" y="12" width="16" height="14" rx="3" fill="' . $color . '" opacity="0.2"/>'
            . '<rect x="10" y="12" width="16" height="14" rx="3" stroke="' . $color . '" stroke-width="2"/>'
            . '<circle cx="14" cy="17" r="1.8" fill="' . $color . '"/>'
            . '<circle cx="22" cy="17" r="1.8" fill="' . $color . '"/>'
            . '<path d="M14 22h8M18 7v5M16 7h4" stroke="' . $color . '" stroke-width="1.8" stroke-linecap="round"/>',

        'avatar-cat' => '<path d="M11 11l2 5h10l2-5-4 2h-6l-4-2z" fill="' . $color . '" opacity="0.25"/>'
            . '<circle cx="18" cy="19" r="8" stroke="' . $color . '" stroke-width="2"/>'
            . '<path d="M11 14l2-4 3 2M25 14l-2-4-3 2" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<circle cx="15" cy="19" r="1.3" fill="' . $color . '"/>'
            . '<circle cx="21" cy="19" r="1.3" fill="' . $color . '"/>'
            . '<path d="M16 22l2 1.2 2-1.2" stroke="' . $color . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>',

        'avatar-astro' => '<circle cx="18" cy="17" r="7.5" fill="' . $color . '" opacity="0.2"/>'
            . '<circle cx="18" cy="17" r="7.5" stroke="' . $color . '" stroke-width="2"/>'
            . '<path d="M13 17a5 3.5 0 0 1 10 0" fill="' . $color . '"/>'
            . '<path d="M10 26c2-2.5 5-3.5 8-3.5s6 1 8 3.5" stroke="' . $color . '" stroke-width="2" stroke-linecap="round"/>',
    ];

    $inner = $iconos[$info['id']] ?? '';

    return '<svg class="avatar-svg ' . e($claseExtra) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 36 36" fill="none" aria-label="' . e($info['nombre']) . '">'
        . '<circle cx="18" cy="18" r="18" fill="' . $bg . '"/>'
        . $inner
        . '</svg>';
}

/**
 * Guarda la seleccion de avatar para el usuario.
 */
function guardar_avatar(int $usuarioId, string $avatarId): bool
{
    $avatares = avatares_disponibles();
    if (!isset($avatares[$avatarId])) {
        return false;
    }

    $stmt = db()->prepare('UPDATE usuarios SET avatar = :av WHERE id = :id');
    $stmt->execute([':av' => $avatarId, ':id' => $usuarioId]);

    $_SESSION['avatar'] = $avatarId;
    registrar_auditoria($usuarioId, 'AVATAR_ACTUALIZADO', 'avatar=' . $avatarId);

    return true;
}
