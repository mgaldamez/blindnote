<?php
/** Portada: redirige al panel si hay sesion, o al login si no. */
require_once __DIR__ . '/includes/init.php';

if (esta_autenticado()) {
    redirigir('dashboard.php');
}
redirigir('login.php');
