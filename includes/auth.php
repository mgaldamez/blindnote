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
        'SELECT id, nombre, username, email, password_hash, rol, activo
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
 * Valida que el User-Agent no haya cambiado bruscamente (posible robo de sesion).
 */
function sesion_coherente(): bool
{
    if (!esta_autenticado()) {
        return false;
    }
    return hash_equals($_SESSION['user_agent'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '');
}
