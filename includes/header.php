<?php
/**
 * Encabezado comun: barra superior + navegacion lateral por secciones.
 *
 * El menu se agrupa en Principal / Administracion / Proyecto. En escritorio se
 * puede colapsar a iconos (la eleccion se recuerda en el navegador); en
 * pantallas chicas funciona como panel deslizable.
 */
if (!defined('APP_NOMBRE')) {
    require_once __DIR__ . '/init.php';
}

$paginaActual = basename($_SERVER['PHP_SELF'] ?? '');
$autenticado  = esta_autenticado();
$esAdmin      = ($_SESSION['rol'] ?? '') === 'admin';

/** Iconos en linea (no hay fuente de iconos: la CSP bloquea origenes externos). */
$iconos = [
    'panel' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V21h13V9.5"/>',
    'notas' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 12h6M9 16h6"/>',
    'usuarios' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/>'
        . '<circle cx="17.2" cy="9" r="2.4"/><path d="M21 20c0-2.4-1.6-4.4-3.8-5"/>',
    'auditoria' => '<path d="M8 4h8v3H8z"/><path d="M6 6H5v15h14V6h-1"/>'
        . '<path d="M9 11h6M9 15h6M9 19h3"/>',
    'proyecto' => '<path d="M12 3l7 3v5.5c0 4.4-3 7.6-7 9.5-4-1.9-7-5.1-7-9.5V6z"/>'
        . '<path d="M9.2 11.8l2 2 3.6-3.8"/>',
    'entrar' => '<path d="M10 4H5v16h5"/><path d="M15 8l4 4-4 4"/><path d="M19 12H9"/>',
    'registro' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/>'
        . '<path d="M18 8v6M15 11h6"/>',
];

/** Imprime un enlace del menu lateral con su icono. */
$enlaceNav = static function (string $archivo, string $texto, string $claveIcono) use ($paginaActual, $iconos): void {
    $activo = $paginaActual === $archivo;

    echo '<a class="nav-enlace' . ($activo ? ' activo' : '') . '" href="' . e($archivo) . '"'
        . ' title="' . e($texto) . '"' . ($activo ? ' aria-current="page"' : '') . '>'
        . '<svg class="nav-enlace__icono" viewBox="0 0 24 24" width="20" height="20" fill="none"'
        . ' stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true">' . $iconos[$claveIcono] . '</svg>'
        . '<span class="nav-enlace__texto">' . e($texto) . '</span>'
        . '</a>' . "\n";
};

$cssVersion = @filemtime(__DIR__ . '/../assets/css/estilos.css') ?: 1;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($titulo ?? APP_NOMBRE) ?> | <?= e(APP_NOMBRE) ?></title>
    <link rel="stylesheet" href="assets/css/estilos.css?v=<?= (int) $cssVersion ?>">
</head>
<body>
<a class="salto" href="#contenido">Saltar al contenido</a>

<header class="topbar">
    <div class="topbar__inner">
        <a class="marca" href="index.php">
            <span class="marca__logo">B</span>
            <span><?= e(APP_NOMBRE) ?></span>
        </a>
        <?php if ($autenticado): ?>
            <button class="nav-toggle" type="button" data-nav-toggle
                    aria-controls="navegacion" aria-expanded="true" title="Mostrar u ocultar el menu">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                    <path d="M4 7h16M4 12h16M4 17h16"/>
                </svg>
                <span class="nav-toggle__texto">Menu</span>
            </button>
        <?php endif; ?>


        <?php if ($autenticado): ?>
            <span class="topbar__usuario" title="<?= e($_SESSION['nombre'] ?? '') ?>">
                <?= e($_SESSION['nombre'] ?? '') ?>
            </span>
        <?php endif; ?>
    </div>
</header>

<div class="layout<?= $autenticado ? '' : ' layout--simple' ?>">
<?php if ($autenticado): ?>
    <aside class="sidebar" id="navegacion" aria-label="Navegacion principal">
        <div class="sidebar__interior">
            <nav class="nav-secciones">
                <div class="nav-seccion">
                    <p class="nav-seccion__titulo">Principal</p>
                    <?php
                    $enlaceNav('dashboard.php', 'Panel', 'panel');
                    $enlaceNav('notas.php', 'Notas', 'notas');
                    ?>
                </div>

                <?php if ($esAdmin): ?>
                    <div class="nav-seccion">
                        <p class="nav-seccion__titulo">Administracion</p>
                        <?php
                        $enlaceNav('admin.php', 'Usuarios', 'usuarios');
                        $enlaceNav('auditoria.php', 'Auditoria', 'auditoria');
                        ?>
                    </div>
                <?php endif; ?>

                <div class="nav-seccion">
                    <p class="nav-seccion__titulo">Proyecto</p>
                    <?php $enlaceNav('informe_fase4.php', 'Fase 4', 'proyecto'); ?>
                </div>
            </nav>

            <div class="sidebar__pie">
                <span class="sidebar__usuario">
                    <?= e($_SESSION['nombre'] ?? '') ?>
                    <em>(<?= e($_SESSION['rol'] ?? '') ?>)</em>
                </span>
                <form action="logout.php" method="post" class="inline">
                    <?= csrf_campo() ?>
                    <button type="submit" class="btn btn--peligro btn--sm">Salir</button>
                </form>
            </div>
        </div>
    </aside>

    <div class="velo" data-nav-cerrar aria-hidden="true"></div>
<?php endif; ?>

    <main class="contenedor" id="contenido">
