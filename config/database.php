<?php
/**
 * Conexion a la base de datos mediante PDO.
 * Todo acceso usa consultas preparadas (Fase 4 - prevencion de inyeccion SQL).
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_PORT,
        DB_NOMBRE,
        DB_CHARSET
    );

    $opciones = [
        // Lanza excepciones ante errores (nada de fallos silenciosos)
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        // Devuelve arreglos asociativos
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Usa consultas preparadas REALES en el servidor MySQL.
        // Esto es la mitigacion principal contra inyeccion SQL.
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, DB_USUARIO, DB_CLAVE, $opciones);
    } catch (PDOException $e) {
        if (MODO_DEBUG) {
            die('Error de conexion a la base de datos: ' . $e->getMessage());
        }
        die('No se pudo conectar con la base de datos.');
    }

    return $pdo;
}
