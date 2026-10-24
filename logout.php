<?php
/**
 * FASE 3 - Cierre de sesion seguro (logout).
 * Se exige POST + token CSRF para evitar logout forzado (CSRF),
 * y se destruye por completo la sesion y su cookie.
 */
require_once __DIR__ . '/includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirigir('dashboard.php');
}

csrf_verificar($_POST['csrf_token'] ?? null);

cerrar_sesion('logout');
redirigir('login.php?aviso=logout');
