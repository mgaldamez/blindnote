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

const PREFIJO_U = 'zz-usu-';
const CLAVE_TEMP = 'Temporal123';
const CLAVE_NUEVA = 'Definitiva456';

$fallos = 0;

function limpiar_usuarios(): void
{
    // Los logs referencian usuario_id con ON DELETE SET NULL: se borran antes.
    $stmt = db()->prepare('SELECT id FROM usuarios WHERE username LIKE :p');
    $stmt->execute([':p' => PREFIJO_U . '%']);
    $ids = array_map(static fn (array $f): int => (int) $f['id'], $stmt->fetchAll());

    if ($ids !== []) {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("DELETE FROM logs_auditoria WHERE usuario_id IN ($marcas)")->execute($ids);
        db()->prepare("DELETE FROM notas WHERE usuario_id IN ($marcas)")->execute($ids);
    }

    db()->prepare('DELETE FROM usuarios WHERE username LIKE :p')->execute([':p' => PREFIJO_U . '%']);
    db()->prepare('DELETE FROM intentos_login WHERE username LIKE :p')->execute([':p' => PREFIJO_U . '%']);
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

/* ---------------- Limpieza ---------------- */

if ($otrosAdmins !== []) {
    $marcas = implode(',', array_fill(0, count($otrosAdmins), '?'));
    db()->prepare("UPDATE usuarios SET activo = 1 WHERE id IN ($marcas)")->execute($otrosAdmins);
}
limpiar_usuarios();
echo $fallos === 0 ? "TODO OK\n" : "$fallos caso(s) con falla\n";
ob_end_flush();
exit($fallos === 0 ? 0 : 1);
