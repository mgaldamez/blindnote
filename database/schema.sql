-- =====================================================================
--  BlindNote - Esquema de base de datos
--  Proyecto: Diseno de Seguridad de Redes - Tercer Parcial
--  FASE 3: Control de acceso y autenticacion
-- =====================================================================
--  Ejecutar en phpMyAdmin o por consola:
--      mysql -u root -p < database/schema.sql
--  (Tambien se crea automaticamente desde setup.php)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS seguridad_web
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE seguridad_web;

-- ---------------------------------------------------------------------
--  Usuarios: las contrasenas NUNCA se guardan en texto plano.
--  La columna password_hash almacena el resultado de password_hash()
--  con el algoritmo BCRYPT (Fase 3 - cifrado de contrasenas).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre            VARCHAR(100)  NOT NULL,
    email             VARCHAR(150)  NOT NULL,
    username          VARCHAR(50)   NOT NULL,
    password_hash     VARCHAR(255)  NOT NULL,
    -- 1 = la contrasena es temporal y debe cambiarse en el proximo login.
    debe_cambiar_clave TINYINT(1)   NOT NULL DEFAULT 0,
    rol               ENUM('admin','usuario') NOT NULL DEFAULT 'usuario',
    activo            TINYINT(1)    NOT NULL DEFAULT 1,
    ultimo_login      DATETIME      NULL,
    creado_en         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email    (email),
    UNIQUE KEY uq_usuarios_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Bitacora de intentos de login: soporta el control de fuerza bruta
--  (bloqueo temporal tras N intentos fallidos).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS intentos_login (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username   VARCHAR(150) NOT NULL,
    ip         VARCHAR(45)  NOT NULL,
    exitoso    TINYINT(1)   NOT NULL DEFAULT 0,
    user_agent VARCHAR(255) NULL,
    fecha      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_intentos_username (username),
    KEY idx_intentos_ip       (ip),
    KEY idx_intentos_fecha    (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Log de auditoria: registro de acciones relevantes (login, logout,
--  altas, borrados, accesos denegados...).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS logs_auditoria (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NULL,
    accion     VARCHAR(100) NOT NULL,
    detalle    VARCHAR(255) NULL,
    ip         VARCHAR(45)  NULL,
    fecha      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_logs_usuario (usuario_id),
    KEY idx_logs_fecha   (fecha),
    CONSTRAINT fk_logs_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Notas: contenido creado por el usuario. Se usa para demostrar que la
--  salida se escapa (htmlspecialchars) y se evita XSS almacenado.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notas (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NOT NULL,
    titulo     VARCHAR(150) NOT NULL,
    contenido  TEXT         NOT NULL,
    creado_en  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notas_usuario (usuario_id),
    CONSTRAINT fk_notas_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
