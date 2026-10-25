<?php
/**
 * Autenticacion y control de acceso basado en roles (RBAC).
 * Fase 3: control de acceso.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Exige que haya una sesion iniciada; si no, redirige al login.
 */
function exigir_autenticacion(): void
{
    if (!esta_autenticado()) {
        redirigir('login.php?aviso=debes_iniciar_sesion');
    }
    verificar_expiracion_sesion();
    exigir_clave_al_dia();
}

/**
 * Si la contrasena es temporal, obliga a cambiarla antes de seguir.
 * Las unicas pantallas permitidas mientras tanto son cambiar_clave.php y
 * logout.php (si no, habria un bucle de redirecciones).
 */
function exigir_clave_al_dia(): void
{
    if (!necesita_cambiar_clave()) {
        return;
    }

    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['cambiar_clave.php', 'logout.php'], true)) {
        return;
    }

    redirigir('cambiar_clave.php?aviso=obligatorio');
}

/**
 * La sesion quedo marcada con una contrasena temporal.
 */
function necesita_cambiar_clave(): bool
{
    return !empty($_SESSION['debe_cambiar_clave']);
}

/**
 * Guarda la contrasena definitiva y retira la marca de temporal.
 * El llamador valida la politica y la identidad del usuario.
 */
function cambiar_clave(int $usuarioId, string $claveNueva): void
{
    $hash = password_hash($claveNueva, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    db()->prepare('UPDATE usuarios SET password_hash = :h, debe_cambiar_clave = 0 WHERE id = :id')
        ->execute([':h' => $hash, ':id' => $usuarioId]);

    $_SESSION['debe_cambiar_clave'] = false;
    registrar_auditoria($usuarioId, 'CLAVE_CAMBIADA', 'el usuario cambio su contrasena');
}

/**
 * Exige un rol concreto. Si el usuario no lo tiene, deniega (403).
 */
function exigir_rol(string ...$roles): void
{
    exigir_autenticacion();
    $rolActual = $_SESSION['rol'] ?? 'usuario';
    if (!in_array($rolActual, $roles, true)) {
        registrar_auditoria(
            $_SESSION['usuario_id'] ?? null,
            'ACCESO_DENEGADO',
            'Rol "' . $rolActual . '" intento acceder a ' . ($_SERVER['REQUEST_URI'] ?? '?')
        );
        http_response_code(403);
        die('Acceso denegado: no tienes permisos para esta seccion.');
    }
}

/**
 * Intenta autenticar al usuario.
 *
 * @return array{ok:bool, mensaje:string}
 */
function autenticar(string $username, string $password): array
{
    $username = trim($username);

    // 1) Busqueda con consulta preparada (mitiga inyeccion SQL). Se resuelve
    //    la cuenta antes de contar: el control de fuerza bruta cuenta por
    //    cuenta, no por el texto tipeado, y usuario y correo son la misma.
    $stmt = db()->prepare(
        'SELECT id, nombre, username, email, password_hash, rol, activo, debe_cambiar_clave
           FROM usuarios
          WHERE username = :username
             OR email = :email
          LIMIT 1'
    );
    $stmt->execute([':username' => $username, ':email' => $username]);
    $usuario = $stmt->fetch();

    // 2) Control de fuerza bruta ANTES de comprobar credenciales, agrupando
    //    todos los identificadores de la cuenta (usuario y correo) y con un
    //    tope secundario por IP contra el password spraying.
    $identificadores = $usuario
        ? [(string) $usuario['username'], (string) $usuario['email']]
        : [$username];
    $fallidosCuenta = intentos_fallidos_recientes($identificadores, ip_cliente());
    $fallidosIp = MAX_INTENTOS_POR_IP > 0 ? intentos_fallidos_por_ip(ip_cliente()) : 0;

    $bloqueadoPorCuenta = $fallidosCuenta >= MAX_INTENTOS_FALLIDOS;
    $bloqueadoPorIp = MAX_INTENTOS_POR_IP > 0 && $fallidosIp >= MAX_INTENTOS_POR_IP;

    if ($bloqueadoPorCuenta || $bloqueadoPorIp) {
        // El detalle interno dice cual limite se alcanzo; al usuario se le
        // responde siempre lo mismo.
        $motivo = $bloqueadoPorCuenta ? 'limite por cuenta' : 'limite por IP';
        registrar_auditoria(null, 'CUENTA_BLOQUEADA', "Demasiados intentos para: $username ($motivo)");
        return [
            'ok' => false,
            'mensaje' => 'Demasiados intentos fallidos. Intentalo de nuevo en '
                . MINUTOS_BLOQUEO . ' minutos.',
        ];
    }

    // 3) Mensaje de error GENERICO (no revela si el usuario existe).
    $mensajeGenerico = 'Usuario o contrasena incorrectos.';

    if (!$usuario) {
        // Ejecutamos un hash "dummy" para igualar tiempos (evita enumeracion por timing).
        password_verify($password, '$2y$12$usesomesillystringforanddummyhashvaluexxxxxxxxxxxxxxxxxx');
        registrar_intento_login($username, false);
        return ['ok' => false, 'mensaje' => $mensajeGenerico];
    }

    if ((int) $usuario['activo'] !== 1) {
        registrar_intento_login($username, false);
        return ['ok' => false, 'mensaje' => 'La cuenta esta deshabilitada.'];
    }

    // 4) Verificacion con bcrypt.
    if (!password_verify($password, $usuario['password_hash'])) {
        registrar_intento_login($username, false);
        registrar_auditoria((int) $usuario['id'], 'LOGIN_FALLIDO', 'Contrasena incorrecta');
        return ['ok' => false, 'mensaje' => $mensajeGenerico];
    }

    // 5) Rehash transparente si el coste/algortimo cambio.
    if (password_needs_rehash($usuario['password_hash'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST])) {
        $nuevo = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $up = db()->prepare('UPDATE usuarios SET password_hash = :h WHERE id = :id');
        $up->execute([':h' => $nuevo, ':id' => $usuario['id']]);
    }

    // 6) Exito: regenerar el ID de sesion (evita fijacion de sesion) y guardar datos.
    session_regenerate_id(true);

    $ahora = time();
    $_SESSION['usuario_id']       = (int) $usuario['id'];
    $_SESSION['nombre']           = $usuario['nombre'];
    $_SESSION['username']         = $usuario['username'];
    $_SESSION['email']            = $usuario['email'];
    $_SESSION['rol']              = $usuario['rol'];
    $_SESSION['debe_cambiar_clave'] = ((int) $usuario['debe_cambiar_clave'] === 1);
    $_SESSION['inicio_sesion']    = $ahora;
    $_SESSION['ultima_actividad'] = $ahora;
    $_SESSION['user_agent']       = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $_SESSION['ip']               = ip_cliente();

    // 7) Registros: ultimo login + auditoria + intento exitoso. El conteo de
    //    fuerza bruta vive solo en intentos_login.
    db()->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = :id')
        ->execute([':id' => $usuario['id']]);
    registrar_intento_login($username, true);
    registrar_auditoria((int) $usuario['id'], 'LOGIN_OK', 'Inicio de sesion correcto');

    return ['ok' => true, 'mensaje' => 'Bienvenido.'];
}

/**
 * Cantidad de administradores activos: protege contra quedarse sin acceso.
 */
function contar_admins_activos(): int
{
    return (int) db()->query("SELECT COUNT(*) AS c FROM usuarios WHERE rol = 'admin' AND activo = 1")
        ->fetch()['c'];
}

function es_admin_activo(int $usuarioId): bool
{
    $stmt = db()->prepare("SELECT 1 FROM usuarios WHERE id = :id AND rol = 'admin' AND activo = 1");
    $stmt->execute([':id' => $usuarioId]);

    return $stmt->fetch() !== false;
}

/**
 * Valida los datos de un usuario (alta y edicion).
 *
 * @param array<string,mixed> $datos
 * @return list<string> mensajes de error; vacio si esta todo bien
 */
function validar_datos_usuario(array $datos, ?int $idExcluir = null): array
{
    $errores  = [];
    $nombre   = trim((string) ($datos['nombre'] ?? ''));
    $email    = trim((string) ($datos['email'] ?? ''));
    $username = trim((string) ($datos['username'] ?? ''));

    if (mb_strlen($nombre) < 3) {
        $errores[] = 'El nombre debe tener al menos 3 caracteres.';
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errores[] = 'El correo electronico no es valido.';
    }
    if (preg_match('/^[a-zA-Z0-9_.]{3,50}$/', $username) !== 1) {
        $errores[] = 'El usuario solo admite letras, numeros, punto y guion bajo (3-50).';
    }

    if ($errores === []) {
        $sql = 'SELECT id FROM usuarios WHERE (username = :u OR email = :e)';
        $parametros = [':u' => $username, ':e' => $email];
        if ($idExcluir !== null) {
            $sql .= ' AND id <> :id';
            $parametros[':id'] = $idExcluir;
        }
        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute($parametros);
        if ($stmt->fetch() !== false) {
            $errores[] = 'Ese usuario o correo ya esta registrado.';
        }
    }

    return $errores;
}

/**
 * Da de alta un usuario con contrasena temporal.
 * Solo puede hacerlo un administrador activo (defensa en profundidad: la
 * pantalla ya exige el rol, la funcion lo vuelve a comprobar).
 *
 * @param array<string,mixed> $datos nombre, email, username, rol, clave, clave2
 * @return array{ok:bool, errores:list<string>, id:int}
 */
function crear_usuario_admin(array $datos, int $actorId): array
{
    if (!es_admin_activo($actorId)) {
        return ['ok' => false, 'errores' => ['Solo un administrador activo puede crear usuarios.'], 'id' => 0];
    }

    $errores = validar_datos_usuario($datos);

    $clave = (string) ($datos['clave'] ?? '');
    foreach (validar_contrasena($clave) as $error) {
        $errores[] = $error;
    }
    if (array_key_exists('clave2', $datos) && (string) $datos['clave2'] !== $clave) {
        $errores[] = 'Las contrasenas no coinciden.';
    }

    if ($errores !== []) {
        return ['ok' => false, 'errores' => $errores, 'id' => 0];
    }

    $rol = in_array((string) ($datos['rol'] ?? ''), ['admin', 'usuario'], true)
        ? (string) $datos['rol']
        : 'usuario';

    $username = trim((string) $datos['username']);
    $stmt = db()->prepare(
        'INSERT INTO usuarios (nombre, email, username, password_hash, rol, activo, debe_cambiar_clave)
         VALUES (:nombre, :email, :username, :hash, :rol, 1, 1)'
    );
    $stmt->execute([
        ':nombre'   => trim((string) $datos['nombre']),
        ':email'    => trim((string) $datos['email']),
        ':username' => $username,
        ':hash'     => password_hash($clave, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]),
        ':rol'      => $rol,
    ]);

    $id = (int) db()->lastInsertId();
    registrar_auditoria($actorId, 'USUARIO_CREADO', 'id=' . $id . ' usuario=' . $username);

    return ['ok' => true, 'errores' => [], 'id' => $id];
}

/**
 * Actualiza nombre, correo, usuario y rol de una cuenta.
 * Respeta las guardas de auto-bloqueo: nadie se quita su propio rol admin ni
 * deja el sistema sin administradores activos.
 *
 * @param array<string,mixed> $datos
 * @return array{ok:bool, errores:list<string>}
 */
function actualizar_usuario_admin(array $datos, int $objetivoId, int $actorId): array
{
    if (!es_admin_activo($actorId)) {
        return ['ok' => false, 'errores' => ['Solo un administrador activo puede editar usuarios.']];
    }

    $stmt = db()->prepare('SELECT id, nombre, username, email, rol, activo FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $objetivoId]);
    $objetivo = $stmt->fetch();
    if ($objetivo === false) {
        return ['ok' => false, 'errores' => ['El usuario no existe.']];
    }

    $errores = validar_datos_usuario($datos, $objetivoId);

    $rolSolicitado = (string) ($datos['rol'] ?? '');
    $rol = in_array($rolSolicitado, ['admin', 'usuario'], true) ? $rolSolicitado : (string) $objetivo['rol'];

    if ($rol !== $objetivo['rol'] && !puede_cambiar_rol($actorId, $objetivoId, $rol)) {
        $errores[] = 'No se puede cambiar ese rol: nadie puede quitarse el suyo y el sistema '
            . 'no puede quedar sin administradores activos.';
    }

    if ($errores !== []) {
        return ['ok' => false, 'errores' => $errores];
    }

    db()->prepare(
        'UPDATE usuarios SET nombre = :nombre, email = :email, username = :username, rol = :rol
          WHERE id = :id'
    )->execute([
        ':nombre'   => trim((string) $datos['nombre']),
        ':email'    => trim((string) $datos['email']),
        ':username' => trim((string) $datos['username']),
        ':rol'      => $rol,
        ':id'       => $objetivoId,
    ]);

    // Si el admin se edita a si mismo, la sesion no puede quedar con datos viejos.
    if ($objetivoId === $actorId) {
        $_SESSION['nombre']   = trim((string) $datos['nombre']);
        $_SESSION['username'] = trim((string) $datos['username']);
        $_SESSION['email']    = trim((string) $datos['email']);
    }

    registrar_auditoria($actorId, 'USUARIO_EDITADO', 'id=' . $objetivoId . ' rol=' . $rol);

    return ['ok' => true, 'errores' => []];
}

/**
 * Decide si el actor puede desactivar (soft delete) al usuario objetivo.
 * Nadie se desactiva a si mismo y el sistema no puede quedar sin admins.
 */
function puede_desactivar(int $actorId, int $objetivoId): bool
{
    if ($actorId === $objetivoId) {
        return false;
    }

    if (es_admin_activo($objetivoId) && contar_admins_activos() <= 1) {
        return false;
    }

    return true;
}

/**
 * Decide si el actor puede dejar al objetivo con el rol indicado.
 * Nadie se quita su propio rol admin y el ultimo admin activo no se degrada.
 */
function puede_cambiar_rol(int $actorId, int $objetivoId, string $rolNuevo): bool
{
    if ($rolNuevo === 'admin') {
        return true;
    }

    if ($actorId === $objetivoId) {
        return false;
    }

    if (es_admin_activo($objetivoId) && contar_admins_activos() <= 1) {
        return false;
    }

    return true;
}

/**
 * Valida que el User-Agent no haya cambiado bruscamente (posible robo de sesion).
 */
function sesion_coherente(): bool
{
    if (!esta_autenticado()) {
        return false;
    }
    return hash_equals($_SESSION['user_agent'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '');
}
