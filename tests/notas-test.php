<?php
/**
 * Regresion de las notas: edicion, completado y listas separadas (PHP CLI).
 *
 * Reglas verificadas (odd/tasks/notas-editar-completadas.md):
 *  S2. Completar guarda la fecha; reabrir la limpia.
 *  S5. Cada lista trae solo su estado, con las completadas ordenadas por fecha.
 *  S7. Toda operacion se limita al dueno de la nota (anti-IDOR).
 *
 *  Fijado (pin): se alterna con fijar_nota, una nota fijada encabeza la lista
 *  aunque sea mas vieja, y fijar al crear o editar afecta solo a la nota
 *  guardada y nunca a una nota ajena.
 *
 * Uso:
 *     docker compose exec web php tests/notas-test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

const PREFIJO_N = 'zzusu_not';
const TITULO_A  = 'Nota de prueba A';
const TITULO_B  = 'Nota de prueba B';

$fallos = 0;

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

function limpiar_notas(): void
{
    $stmt = db()->prepare('SELECT id FROM usuarios WHERE username LIKE :p');
    $stmt->execute([':p' => PREFIJO_N . '%']);
    $ids = array_map(static fn (array $f): int => (int) $f['id'], $stmt->fetchAll());

    if ($ids !== []) {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        db()->prepare("DELETE FROM notas WHERE usuario_id IN ($marcas)")->execute($ids);
        db()->prepare("DELETE FROM logs_auditoria WHERE usuario_id IN ($marcas)")->execute($ids);
    }

    db()->prepare('DELETE FROM usuarios WHERE username LIKE :p')->execute([':p' => PREFIJO_N . '%']);
}

function crear_dueno(string $sufijo): int
{
    $username = PREFIJO_N . $sufijo;
    db()->prepare(
        'INSERT INTO usuarios (nombre, email, username, password_hash, rol, activo)
         VALUES (:n, :e, :u, :h, :rol, 1)'
    )->execute([
        ':n'   => 'Notas ' . $sufijo,
        ':e'   => $username . '@test.local',
        ':u'   => $username,
        ':h'   => password_hash('Secreta123', PASSWORD_BCRYPT, ['cost' => 4]),
        ':rol' => 'usuario',
    ]);

    return (int) db()->lastInsertId();
}

limpiar_notas();
echo "Notas: edicion, completado y listas\n";

$ana  = crear_dueno('ana');
$beto = crear_dueno('beto');

/* ---------------- Crear ---------------- */

$r = crear_nota($ana, '', 'contenido');
comprobar('rechaza el titulo vacio', 1, $r['ok'] ? 0 : 1);

$r = crear_nota($ana, str_repeat('x', 151), 'contenido');
comprobar('rechaza un titulo de mas de 150', 1, $r['ok'] ? 0 : 1);

$r = crear_nota($ana, TITULO_A, '   ');
comprobar('rechaza el contenido vacio', 1, $r['ok'] ? 0 : 1);

$r = crear_nota($ana, TITULO_A, 'contenido A');
comprobar('crea una nota valida', 1, $r['ok'] ? 1 : 0);
$notaAna = $r['id'];

$r = crear_nota($ana, TITULO_B, 'contenido B');
$notaAna2 = $r['id'];
$r = crear_nota($beto, 'Nota de Beto', 'contenido de beto');
$notaBeto = $r['id'];

/* ---------------- Leer (anti-IDOR) ---------------- */

comprobar('el dueno lee su nota', 1, obtener_nota($ana, $notaAna) !== null ? 1 : 0);
comprobar('no se lee la nota ajena', 1, obtener_nota($ana, $notaBeto) === null ? 1 : 0);
comprobar('una nota inexistente devuelve null', 1, obtener_nota($ana, 999999) === null ? 1 : 0);

/* ---------------- Editar ---------------- */

$r = actualizar_nota($beto, $notaAna, 'Intento ajeno', 'x');
comprobar('no se puede editar una nota ajena', 1, $r['ok'] ? 0 : 1);

$fila = db()->prepare('SELECT titulo FROM notas WHERE id = :id');
$fila->execute([':id' => $notaAna]);
comprobar('la nota ajena quedo intacta', 1, (($fila->fetch()['titulo'] ?? '') === TITULO_A) ? 1 : 0);

$r = actualizar_nota($ana, $notaAna, '', 'x');
comprobar('la edicion valida el titulo', 1, $r['ok'] ? 0 : 1);

$r = actualizar_nota($ana, $notaAna, 'Titulo editado', 'contenido editado');
comprobar('el dueno edita su nota', 1, $r['ok'] ? 1 : 0);

$fila = db()->prepare('SELECT titulo, contenido FROM notas WHERE id = :id');
$fila->execute([':id' => $notaAna]);
$editada = $fila->fetch();
comprobar(
    'los cambios quedaron guardados',
    1,
    ($editada['titulo'] === 'Titulo editado' && $editada['contenido'] === 'contenido editado') ? 1 : 0
);

/* ---------------- Completar y reabrir ---------------- */

$r = cambiar_estado_nota($beto, $notaAna, true);
comprobar('no se puede completar una nota ajena', 1, $r['ok'] ? 0 : 1);

$r = cambiar_estado_nota($ana, $notaAna, true);
comprobar('el dueno completa la nota', 1, $r['ok'] ? 1 : 0);

$fila = db()->prepare('SELECT completada, completada_en FROM notas WHERE id = :id');
$fila->execute([':id' => $notaAna]);
$completada = $fila->fetch();
comprobar('la nota queda marcada como completada', 1, (int) $completada['completada']);
comprobar('y guarda la fecha de completado', 1, $completada['completada_en'] !== null ? 1 : 0);

$r = cambiar_estado_nota($ana, $notaAna, false);
comprobar('reabrir vuelve a pendiente', 1, $r['ok'] ? 1 : 0);

$fila = db()->prepare('SELECT completada, completada_en FROM notas WHERE id = :id');
$fila->execute([':id' => $notaAna]);
$reabierta = $fila->fetch();
comprobar('al reabrir se limpia el estado', 0, (int) $reabierta['completada']);
comprobar('y se limpia la fecha', 1, $reabierta['completada_en'] === null ? 1 : 0);

/* ---------------- Listas y contadores ---------------- */

cambiar_estado_nota($ana, $notaAna2, true);

$pendientes = listar_notas($ana, false);
$completadas = listar_notas($ana, true);

comprobar('quedan 1 pendiente', 1, count($pendientes));
comprobar('queda 1 completada', 1, count($completadas));
comprobar('la lista de pendientes solo trae pendientes', 1, ((int) $pendientes[0]['completada'] === 0) ? 1 : 0);
comprobar('la lista de completadas solo trae completadas', 1, ((int) $completadas[0]['completada'] === 1) ? 1 : 0);
comprobar('las listas no mezclan usuarios', 0, count(listar_notas($beto, false)) === 1 ? 0 : 1);

$contadores = contar_notas_por_estado($ana);
comprobar('el contador marca 1 pendiente', 1, $contadores['pendientes']);
comprobar('el contador marca 1 completada', 1, $contadores['completadas']);

/* ---------------- Fijar (pin) notas ---------------- */

$r = crear_nota($ana, 'Nota fija', 'contenido fijo');
$notaFija = $r['id'];

comprobar('una nota nueva no nace fijada', 0, (int) (obtener_nota($ana, $notaFija)['fijada'] ?? 0));

$r = fijar_nota($ana, $notaFija);
comprobar('el dueno fija su nota', 1, ($r['ok'] && $r['fijada']) ? 1 : 0);
comprobar('y el pin queda guardado', 1, (int) (obtener_nota($ana, $notaFija)['fijada'] ?? 0));

$r = fijar_nota($ana, $notaFija);
comprobar('volver a fijar la desfija', 0, ($r['ok'] && $r['fijada']) ? 1 : 0);

$fijadasAntes = contar_notas_por_estado($ana)['fijadas'];

$r = fijar_nota($beto, $notaFija);
comprobar('no se puede fijar una nota ajena', 1, $r['ok'] ? 0 : 1);
comprobar('la nota ajena sigue sin fijar', 0, (int) (obtener_nota($ana, $notaFija)['fijada'] ?? 0));
comprobar('el contador de fijadas no se movio', $fijadasAntes, contar_notas_por_estado($ana)['fijadas']);

$r = establecer_fijada($ana, $notaFija, true);
comprobar('establecer_fijada fija la nota', 1, ($r['ok'] && $r['fijada']) ? 1 : 0);

$r = crear_nota($ana, 'Nota mas nueva sin fijar', 'contenido nuevo');
comprobar('una nota fijada encabeza la lista aunque sea mas vieja', $notaFija, (int) listar_notas($ana, false)[0]['id']);

$r = establecer_fijada($ana, $notaFija, false);
comprobar('establecer_fijada desfija la nota', 0, ($r['ok'] && $r['fijada']) ? 1 : 0);

/* ---- Fijar al guardar desde el formulario (crear / editar) ---- */

$fijadasAntes = contar_notas_por_estado($ana)['fijadas'];

$r = guardar_nota_form($ana, null, 'Creada fijada', 'cuerpo creado', true);
comprobar('crear una nota fijada devuelve ok', 1, $r['ok'] ? 1 : 0);
$creadaFijada = (int) $r['id'];

comprobar('la nota recien creada queda fijada', 1, (int) (obtener_nota($ana, $creadaFijada)['fijada'] ?? 0));
comprobar('crear fijada suma exactamente una fijada', $fijadasAntes + 1, contar_notas_por_estado($ana)['fijadas']);

$r = guardar_nota_form($ana, $creadaFijada, 'Creada fijada', 'cuerpo editado', false);
comprobar('editar sin fijar desfija la misma nota', 0, (int) (obtener_nota($ana, $creadaFijada)['fijada'] ?? 0));
comprobar('editar sin fijar no toca otras notas', $fijadasAntes, contar_notas_por_estado($ana)['fijadas']);

$r = guardar_nota_form($beto, $notaFija, 'Intento ajeno', 'x', true);
comprobar('no se guarda sobre una nota ajena', 1, $r['ok'] ? 0 : 1);

$r = guardar_nota_form($ana, null, '', 'sin titulo', true);
comprobar('un titulo vacio no crea ni fija nada', 1, $r['ok'] ? 0 : 1);
comprobar('y el contador de fijadas sigue igual', $fijadasAntes, contar_notas_por_estado($ana)['fijadas']);

limpiar_notas();
echo $fallos === 0 ? "TODO OK\n" : "$fallos caso(s) con falla\n";
exit($fallos === 0 ? 0 : 1);
