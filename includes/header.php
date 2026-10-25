<?php
/** Encabezado comun con navegacion. */
if (!defined('APP_NOMBRE')) {
    require_once __DIR__ . '/init.php';
}
$paginaActual = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($titulo ?? APP_NOMBRE) ?> | <?= e(APP_NOMBRE) ?></title>
    <?php $cssVersion = @filemtime(__DIR__ . '/../assets/css/estilos.css') ?: 1; ?>
    <link rel="stylesheet" href="assets/css/estilos.css?v=<?= (int) $cssVersion ?>">
</head>
<body>
<header class="topbar">
    <div class="contenedor topbar__inner">
        <a class="marca" href="index.php">
            <span class="marca__logo">B</span>
            <span><?= e(APP_NOMBRE) ?></span>
        </a>
        <nav class="nav">
            <?php if (esta_autenticado()): ?>
                <a class="<?= $paginaActual === 'dashboard.php' ? 'activo' : '' ?>" href="dashboard.php">Panel</a>
                <a class="<?= $paginaActual === 'notas.php' ? 'activo' : '' ?>" href="notas.php">Notas</a>
                <?php if (($_SESSION['rol'] ?? '') === 'admin'): ?>
                    <a class="<?= $paginaActual === 'admin.php' ? 'activo' : '' ?>" href="admin.php">Admin</a>
                <?php endif; ?>
                <a class="<?= $paginaActual === 'informe_fase4.php' ? 'activo' : '' ?>" href="informe_fase4.php">Fase 4</a>
                <span class="nav__usuario"><?= e($_SESSION['nombre'] ?? '') ?>
                    <em>(<?= e($_SESSION['rol'] ?? '') ?>)</em></span>
                <form action="logout.php" method="post" class="inline">
                    <?= csrf_campo() ?>
                    <button type="submit" class="btn btn--peligro btn--sm">Salir</button>
                </form>
            <?php else: ?>
                <a class="<?= $paginaActual === 'login.php' ? 'activo' : '' ?>" href="login.php">Iniciar sesion</a>
                <a class="btn btn--primario btn--sm" href="register.php">Crear cuenta</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="contenedor">
