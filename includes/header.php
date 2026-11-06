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
    'avatar' => '<circle cx="12" cy="8" r="4"/><path d="M5 20c0-3.3 3.1-6 7-6s7 2.7 7 6"/>',
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

        <div class="topbar__acciones">
            <button class="tema-toggle" type="button" data-tema-toggle title="Cambiar tema claro/oscuro" aria-label="Cambiar tema">
                <svg class="tema-toggle__icono tema-toggle__icono--sol" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="5"></circle>
                    <line x1="12" y1="1" x2="12" y2="3"></line>
                    <line x1="12" y1="21" x2="12" y2="23"></line>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                    <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                    <line x1="1" y1="12" x2="3" y2="12"></line>
                    <line x1="21" y1="12" x2="23" y2="12"></line>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                    <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                </svg>
                <svg class="tema-toggle__icono tema-toggle__icono--luna" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                </svg>
            </button>

            <?php if ($autenticado): ?>
                <a class="topbar__perfil" href="avatar.php" title="Personalizar avatar de perfil">
                    <span class="topbar__avatar"><?= obtener_avatar_svg($_SESSION['avatar'] ?? null, 28) ?></span>
                    <span class="topbar__usuario"><?= e($_SESSION['nombre'] ?? '') ?></span>
                </a>
            <?php endif; ?>
        </div>
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
                    $enlaceNav('avatar.php', 'Mi Avatar', 'avatar');
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
                <div class="sidebar__usuario-card">
                    <a href="avatar.php" class="sidebar__avatar-link" title="Cambiar mi avatar">
                        <?= obtener_avatar_svg($_SESSION['avatar'] ?? null, 36) ?>
                    </a>
                    <div class="sidebar__usuario-meta">
                        <span class="sidebar__usuario-nombre"><?= e($_SESSION['nombre'] ?? '') ?></span>
                        <span class="sidebar__usuario-rol badge badge--<?= e($_SESSION['rol'] ?? 'usuario') ?>"><?= e($_SESSION['rol'] ?? '') ?></span>
                    </div>
                </div>
                <form action="logout.php" method="post">
                    <?= csrf_campo() ?>
                    <button type="submit" class="btn btn--peligro btn--sm" title="Cerrar sesion de forma segura">Salir</button>
                </form>
            </div>
        </div>
    </aside>

    <div class="velo" data-nav-cerrar aria-hidden="true"></div>
<?php endif; ?>

    <main class="contenedor" id="contenido">
