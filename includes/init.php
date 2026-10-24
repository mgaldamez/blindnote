<?php
/**
 * Arranque comun: sesion + cabeceras de seguridad + dependencias.
 * Incluir SIEMPRE al inicio de cada pagina publica.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/* ---- Cabeceras de seguridad HTTP (refuerzan Fase 4) ---- */
header('X-Frame-Options: DENY');                       // evita clickjacking
header('X-Content-Type-Options: nosniff');             // evita MIME sniffing
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header("Content-Security-Policy: default-src 'self'; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
    . "script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
// Activar cuando el sitio funcione bajo HTTPS (Fase 2):
// header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

iniciar_sesion();
