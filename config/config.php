<?php
/**
 * BlindNote - Configuracion general
 * Proyecto: Diseno de Seguridad de Redes - Tercer Parcial
 *
 * Ajusta los valores de la base de datos segun tu instalacion.
 * MySQL local detectado: usuario "root" con contrasena "root".
 */

declare(strict_types=1);

/**
 * Lee una variable de entorno con valor por defecto.
 * Permite cambiar la configuracion sin editar el codigo (Docker, produccion).
 */
function env_valor(string $clave, string $defecto): string
{
    $valor = getenv($clave);
    return ($valor === false || $valor === '') ? $defecto : $valor;
}

function env_booleano(string $clave, bool $defecto): bool
{
    $valor = getenv($clave);
    if ($valor === false || $valor === '') {
        return $defecto;
    }
    return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
}

/* ------------------------- Aplicacion ------------------------- */
define('APP_NOMBRE', 'BlindNote');
define('APP_DESCRIPCION', 'Portal seguro - Diseno de Seguridad de Redes');

/* ------------------------- Base de datos ---------------------- */
define('DB_HOST', env_valor('DB_HOST', '127.0.0.1'));
define('DB_PORT', env_valor('DB_PORT', '3306'));
define('DB_NOMBRE', env_valor('DB_NOMBRE', 'seguridad_web'));
define('DB_USUARIO', env_valor('DB_USUARIO', 'root'));
define('DB_CLAVE', env_valor('DB_CLAVE', 'root'));
define('DB_CHARSET', env_valor('DB_CHARSET', 'utf8mb4'));

/* ------------------------- Seguridad -------------------------- */
// Control de fuerza bruta
// Bloqueo por cuenta e IP (usuario o correo).
define('MAX_INTENTOS_FALLIDOS', 5);      // intentos antes de bloquear
// Tope secundario por IP: frena el password spraying (rotar cuentas desde una
// misma IP). 0 lo deshabilita. OJO: detras de un NAT todos comparten IP.
define('MAX_INTENTOS_POR_IP', max(0, (int) env_valor('MAX_INTENTOS_POR_IP', '20')));
define('MINUTOS_BLOQUEO', 15);           // duracion del bloqueo

// Sesiones
define('SESION_INACTIVIDAD_MIN', 20);    // minutos de inactividad antes de expirar
define('SESION_VIDA_MAX_HORAS', 8);      // vida maxima de la sesion autenticada

// Hashing de contrasenas (Fase 3)
define('BCRYPT_COST', 12);               // coste de bcrypt (10-13 recomendado)

// Politica de contrasenas
define('PASS_MIN_LEN', 8);

/* ------------------------- Entorno ---------------------------- */
// true = cookies solo por HTTPS (activalo cuando uses el certificado de Fase 2)
define('COOKIE_SOLO_HTTPS', env_booleano('COOKIE_SOLO_HTTPS', false));

// true = muestra detalles de errores (desarrollo). En produccion -> false.
define('MODO_DEBUG', env_booleano('MODO_DEBUG', true));
