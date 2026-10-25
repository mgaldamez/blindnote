<?php
/**
 * Regresion del CRUD de usuarios (PHP CLI).
 *
 * Reglas verificadas (odd/tasks/usuarios-crud.md):
 *  S4. Una contrasena temporal obliga a cambiarla en el primer login: la sesion
 *      queda marcada, y al cambiarla la marca desaparece y la nueva funciona.
 *  S6. Un administrador no puede desactivarse, quitarse el rol ni dejar el
 *      sistema sin administradores activos.
 *
 * Uso:
 *     docker compose exec web php tests/usuarios-crud-test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const PREFIJO_U = 'zzusu_';   // el regex de username no admite guiones
const CLAVE_TEMP = 'Temporal123';
const CLAVE_NUEVA = 'Definitiva456';

$fallos = 0;

function limpiar_usuarios(): void
{
    // Los logs referencian usuario_id con ON DELETE SET NULL: se borran antes.
    $stmt = db()->prepare('SELECT id FROM usuarios WHERE username LIKE :p1 OR username LIKE :p2');
    $stmt->execute([':p1' => PREFIJO_U . '%', ':p2' => 'zz-usu-%']);
    $ids = array_map(static fn (array $f): int => (int) $f['id'], $stmt->fetchAll());

    if ($ids !== []) {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("DELETE FROM logs_auditoria WHERE usuario_id IN ($marcas)")->execute($ids);
        db()->prepare("DELETE FROM notas WHERE usuario_id IN ($marcas)")->execute($ids);
    }

    db()->prepare('DELETE FROM usuarios WHERE username LIKE :p1 OR username LIKE :p2')
        ->execute([':p1' => PREFIJO_U . '%', ':p2' => 'zz-usu-%']);
    db()->prepare('DELETE FROM intentos_login WHERE username LIKE :p1 OR username LIKE :p2')
        ->execute([':p1' => PREFIJO_U . '%', ':p2' => 'zz-usu-%']);
}

function crear_usuario(string $username, string $rol, int $activo, int $debeCambiar): int
{
    $hash = password_hash(CLAVE_TEMP, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    db()->prepare(
        'INSERT INTO usuarios (nombre, email, username, password_hash, rol, activo, debe_cambiar_clave)
         VALUES (:n, :e, :u, :h, :rol, :activo, :debe)'
    )->execute([
        ':n'      => 'Prueba ' . $username,
        ':e'      => $username . '@test.local',
        ':u'      => $username,
        ':h'      => $hash,
        ':rol'    => $rol,
        ':activo' => $activo,
        ':debe'   => $debeCambiar,
    ]);

    return (int) db()->lastInsertId();
}

function comprobar(string $caso, int $esperado, int $obtenido): void
{
    global $fallos;

    if ($esperado === $obtenido) {
        echo "  OK   $caso (esperado $esperado, obtenido $obtenido)\n";
        return;
    }

    $fallos++;
    echo "  FALLA $caso (esperado $esperado, obtenido $obtenido)\n";
}

limpiar_usuarios();
echo "CRUD de usuarios: contrasena temporal y guardas de administracion\n";

/* ---------------- S4: contrasena temporal ---------------- */

$temporal = PREFIJO_U . 'temporal';
$idTemporal = crear_usuario($temporal, 'usuario', 1, 1);

$r = autenticar($temporal, CLAVE_TEMP);
comprobar('el primer login con clave temporal entra', 1, $r['ok'] ? 1 : 0);
comprobar('la sesion queda marcada para cambiar la clave', 1, necesita_cambiar_clave() ? 1 : 0);

$problemas = validar_contrasena(CLAVE_NUEVA);
comprobar('la clave nueva cumple la politica', 0, count($problemas));

cambiar_clave($idTemporal, CLAVE_NUEVA);
comprobar('tras cambiarla, la sesion deja de estar marcada', 0, necesita_cambiar_clave() ? 1 : 0);

$r = autenticar($temporal, CLAVE_TEMP);
comprobar('la clave temporal ya no sirve', 1, $r['ok'] ? 0 : 1);

$r = autenticar($temporal, CLAVE_NUEVA);
comprobar('la clave nueva sirve para entrar', 1, $r['ok'] ? 1 : 0);
comprobar('y no vuelve a pedir el cambio', 0, necesita_cambiar_clave() ? 1 : 0);

// Un usuario del alta normal no queda marcado.
$normal = PREFIJO_U . 'normal';
$idNormal = crear_usuario($normal, 'usuario', 1, 0);
$r = autenticar($normal, CLAVE_TEMP);
comprobar('el alta normal no pide cambio de clave', 1, ($r['ok'] && !necesita_cambiar_clave()) ? 1 : 0);

/* ---------------- S6: guardas de administracion ---------------- */

$adminUno = PREFIJO_U . 'admin1';
$adminDos = PREFIJO_U . 'admin2';
$idAdminUno = crear_usuario($adminUno, 'admin', 1, 0);
$idAdminDos = crear_usuario($adminDos, 'admin', 1, 0);
$idUsuario  = crear_usuario(PREFIJO_U . 'comun', 'usuario', 1, 0);

// Aislamiento: la guarda es global, y en una base nueva el primer usuario
// registrado es admin (register.php). Se desactivan los demas admins activos
// y se restauran al final.
$otrosAdmins = array_values(array_filter(
    array_map(static fn (array $f): int => (int) $f['id'], db()->query(
        "SELECT id FROM usuarios WHERE rol = 'admin' AND activo = 1"
    )->fetchAll()),
    static fn (int $id): bool => !in_array($id, [$idAdminUno, $idAdminDos], true)
));
if ($otrosAdmins !== []) {
    $marcas = implode(',', array_fill(0, count($otrosAdmins), '?'));
    db()->prepare("UPDATE usuarios SET activo = 0 WHERE id IN ($marcas)")->execute($otrosAdmins);
}

comprobar('un admin no puede desactivarse a si mismo', 0, puede_desactivar($idAdminUno, $idAdminUno) ? 1 : 0);
comprobar('un admin no puede quitarse su propio rol', 0, puede_cambiar_rol($idAdminUno, $idAdminUno, 'usuario') ? 1 : 0);
comprobar('un admin si puede desactivar a un usuario comun', 1, puede_desactivar($idAdminUno, $idUsuario) ? 1 : 0);

// Con un solo admin activo, no se puede degradar ni desactivar a nadie mas.
db()->prepare('UPDATE usuarios SET activo = 0 WHERE id = :id')->execute([':id' => $idAdminDos]);
comprobar('no se puede degradar al ultimo admin activo', 0, puede_cambiar_rol($idUsuario, $idAdminUno, 'usuario') ? 1 : 0);
comprobar('no se puede desactivar al ultimo admin activo', 0, puede_desactivar($idUsuario, $idAdminUno) ? 1 : 0);

// Con dos admins activos, si se puede.
db()->prepare('UPDATE usuarios SET activo = 1 WHERE id = :id')->execute([':id' => $idAdminDos]);
comprobar('con dos admins activos se puede degradar a uno', 1, puede_cambiar_rol($idUsuario, $idAdminUno, 'usuario') ? 1 : 0);
comprobar('con dos admins activos se puede desactivar a uno', 1, puede_desactivar($idUsuario, $idAdminUno) ? 1 : 0);

/* ---------------- S2: listado paginado con busqueda y filtros ---------------- */

for ($i = 1; $i <= 12; $i++) {
    crear_usuario(
        PREFIJO_U . 'pag' . sprintf('%02d', $i),
        $i <= 2 ? 'admin' : 'usuario',
        $i === 2 ? 0 : 1,
        0
    );
}

$filtroBase = ['busqueda' => PREFIJO_U . 'pag'];
$pag1 = listar_usuarios($filtroBase, 1);
comprobar('la primera pagina trae el maximo configurado', USUARIOS_POR_PAGINA, count($pag1['usuarios']));
comprobar('el total cuenta los 12', 12, $pag1['total']);
comprobar('son 2 paginas', 2, $pag1['totalPaginas']);

$pag2 = listar_usuarios($filtroBase, 2);
comprobar('la segunda pagina trae los 2 restantes', 2, count($pag2['usuarios']));
comprobar('una pagina fuera de rango cae en la ultima', 2, listar_usuarios($filtroBase, 99)['pagina']);

comprobar('el filtro por rol acota', 2, listar_usuarios($filtroBase + ['rol' => 'admin'])['total']);
comprobar('el filtro por estado acota', 1, listar_usuarios($filtroBase + ['estado' => 'inactivos'])['total']);
comprobar('la busqueda por correo encuentra', 1, listar_usuarios(['busqueda' => PREFIJO_U . 'pag03@test.local'])['total']);
comprobar('una busqueda sin coincidencias da 0', 0, listar_usuarios(['busqueda' => 'no-existe-' . PREFIJO_U])['total']);
comprobar('no se puede inyectar por el filtro de rol', 12, listar_usuarios($filtroBase + ['rol' => "' OR 1=1 --"])['total']);

/* ---------------- S3/S4: alta de usuarios por un admin ---------------- */

$nuevo = PREFIJO_U . 'creado';
$datosAlta = [
    'nombre'   => 'Creado Uno',
    'email'    => $nuevo . '@test.local',
    'username' => $nuevo,
    'rol'      => 'usuario',
    'clave'    => CLAVE_TEMP,
    'clave2'   => CLAVE_TEMP,
];

$res = crear_usuario_admin($datosAlta, $idUsuario);
comprobar('un no-admin no puede dar de alta', 1, $res['ok'] ? 0 : 1);

$res = crear_usuario_admin($datosAlta, $idAdminUno);
comprobar('el alta valida se guarda', 1, $res['ok'] ? 1 : 0);

$stmt = db()->prepare('SELECT debe_cambiar_clave, rol, activo FROM usuarios WHERE username = :u');
$stmt->execute([':u' => $nuevo]);
$filaAlta = $stmt->fetch();
comprobar('el alta queda con clave temporal', 1, (int) ($filaAlta['debe_cambiar_clave'] ?? 0));
comprobar('el alta queda activa', 1, (int) ($filaAlta['activo'] ?? 0));

$res = crear_usuario_admin($datosAlta, $idAdminUno);
comprobar('el alta rechaza un usuario duplicado', 1, $res['ok'] ? 0 : 1);

$datosDebil = $datosAlta;
$datosDebil['username'] = PREFIJO_U . 'debil';
$datosDebil['email'] = $datosDebil['username'] . '@test.local';
$datosDebil['clave'] = $datosDebil['clave2'] = '123';
$res = crear_usuario_admin($datosDebil, $idAdminUno);
comprobar('el alta rechaza una clave debil', 1, $res['ok'] ? 0 : 1);

$datosRol = $datosAlta;
$datosRol['username'] = PREFIJO_U . 'rolraro';
$datosRol['email'] = $datosRol['username'] . '@test.local';
$datosRol['rol'] = 'superadmin';
$res = crear_usuario_admin($datosRol, $idAdminUno);
$stmt = db()->prepare('SELECT rol FROM usuarios WHERE username = :u');
$stmt->execute([':u' => $datosRol['username']]);
comprobar('un rol invalido cae en usuario', 1, (($stmt->fetch()['rol'] ?? '') === 'usuario') ? 1 : 0);

$datosMail = $datosAlta;
$datosMail['username'] = PREFIJO_U . 'mailraro';
$datosMail['email'] = 'no-es-un-correo';
$res = crear_usuario_admin($datosMail, $idAdminUno);
comprobar('el alta rechaza un correo invalido', 1, $res['ok'] ? 0 : 1);

// El usuario creado entra con la temporal y queda obligado a cambiarla.
$r = autenticar($nuevo, CLAVE_TEMP);
comprobar('el usuario creado entra con la temporal', 1, $r['ok'] ? 1 : 0);
comprobar('y queda obligado a cambiarla', 1, necesita_cambiar_clave() ? 1 : 0);

/* ---------------- S3/S6: edicion de usuarios ---------------- */

$stmt = db()->prepare('SELECT id FROM usuarios WHERE username = :u');
$stmt->execute([':u' => $nuevo]);
$idCreado = (int) $stmt->fetch()['id'];

$res = actualizar_usuario_admin([
    'nombre' => 'Creado Uno Editado', 'email' => $nuevo . '@test.local',
    'username' => $nuevo, 'rol' => 'usuario',
], $idCreado, $idUsuario);
comprobar('un no-admin no puede editar', 1, $res['ok'] ? 0 : 1);

$res = actualizar_usuario_admin([
    'nombre' => 'Creado Uno Editado', 'email' => $nuevo . '@test.local',
    'username' => $nuevo, 'rol' => 'usuario',
], $idCreado, $idAdminUno);
comprobar('la edicion valida se guarda', 1, $res['ok'] ? 1 : 0);
$stmt = db()->prepare('SELECT nombre FROM usuarios WHERE id = :id');
$stmt->execute([':id' => $idCreado]);
comprobar('el nombre quedo actualizado', 1, (($stmt->fetch()['nombre'] ?? '') === 'Creado Uno Editado') ? 1 : 0);

$res = actualizar_usuario_admin([
    'nombre' => 'Creado Uno Editado', 'email' => $nuevo . '@test.local',
    'username' => $nuevo, 'rol' => 'usuario',
], $idAdminUno, $idAdminUno);
comprobar('un admin no puede quitarse su propio rol', 1, $res['ok'] ? 0 : 1);

$res = actualizar_usuario_admin([
    'nombre' => 'Duplicado', 'email' => $nuevo . '@test.local',
    'username' => $nuevo, 'rol' => 'usuario',
], (int) $idNormal, $idAdminUno);
comprobar('la edicion rechaza un correo ya usado', 1, $res['ok'] ? 0 : 1);

// Ascender a otro usuario si se permite.
$res = actualizar_usuario_admin([
    'nombre' => 'Creado Uno Editado', 'email' => $nuevo . '@test.local',
    'username' => $nuevo, 'rol' => 'admin',
], $idCreado, $idAdminUno);
$stmt = db()->prepare('SELECT rol FROM usuarios WHERE id = :id');
$stmt->execute([':id' => $idCreado]);
comprobar('ascender a admin se permite', 1, (($stmt->fetch()['rol'] ?? '') === 'admin') ? 1 : 0);

/* ---------------- S5/S6: desactivar (soft delete) y reactivar ---------------- */

$res = desactivar_usuario($idCreado, $idNormal);
comprobar('un no-admin no puede desactivar', 1, $res['ok'] ? 0 : 1);

$res = desactivar_usuario($idAdminUno, $idAdminUno);
comprobar('un admin no puede desactivarse a si mismo (funcion)', 1, $res['ok'] ? 0 : 1);

$res = desactivar_usuario($idCreado, $idAdminUno);
comprobar('el admin desactiva a otro usuario', 1, $res['ok'] ? 1 : 0);

$stmt = db()->prepare('SELECT activo FROM usuarios WHERE id = :id');
$stmt->execute([':id' => $idCreado]);
comprobar('la fila sigue existiendo, marcada inactiva', 1, ((int) ($stmt->fetch()['activo'] ?? 1) === 0) ? 1 : 0);

db()->prepare('DELETE FROM intentos_login WHERE username LIKE :p')->execute([':p' => PREFIJO_U . '%']);
$r = autenticar($nuevo, CLAVE_TEMP);
comprobar('un usuario desactivado no puede entrar', 1, $r['ok'] ? 0 : 1);
comprobar('y el mensaje lo explica', 1, str_contains($r['mensaje'], 'deshabilitada') ? 1 : 0);

$res = activar_usuario($idCreado, $idAdminUno);
comprobar('el admin reactiva la cuenta', 1, $res['ok'] ? 1 : 0);

$r = autenticar($nuevo, CLAVE_TEMP);
comprobar('al reactivarla vuelve a poder entrar', 1, $r['ok'] ? 1 : 0);

/* ---------------- Limpieza ---------------- */

if ($otrosAdmins !== []) {
    $marcas = implode(',', array_fill(0, count($otrosAdmins), '?'));
    db()->prepare("UPDATE usuarios SET activo = 1 WHERE id IN ($marcas)")->execute($otrosAdmins);
}
limpiar_usuarios();
echo $fallos === 0 ? "TODO OK\n" : "$fallos caso(s) con falla\n";
ob_end_flush();
exit($fallos === 0 ? 0 : 1);
