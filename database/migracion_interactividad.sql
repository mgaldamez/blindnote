-- =====================================================================
-- Migracion: funciones interactivas (avatar de perfil y notas fijadas)
-- ---------------------------------------------------------------------
-- Las instalaciones nuevas ya traen las dos columnas en schema.sql; esto
-- es solo para una base que ya existia antes de la feature.
--
-- Se puede ejecutar sobre una base con datos y repetir sin error: cada
-- columna se agrega unicamente si todavia no existe. Las filas existentes
-- quedan con avatar NULL (silueta por defecto) y fijada = 0.
--
-- Uso (la base se pasa al comando, no va escrita aca, porque el nombre es
-- configurable con DB_NOMBRE):
--     mysql -u root -p seguridad_web < database/migracion_interactividad.sql
--
-- Con docker compose (la contrasena se pide por teclado, no se escribe en el comando):
--     docker compose exec -T db mysql -u root -p -D seguridad_web \
--         < database/migracion_interactividad.sql
-- =====================================================================

-- 1. usuarios.avatar: avatar elegido, opcional.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'usuarios'
       AND COLUMN_NAME  = 'avatar'
);
SET @sql := IF(@existe = 0,
    'ALTER TABLE usuarios ADD COLUMN avatar VARCHAR(30) NULL DEFAULT NULL AFTER activo',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. notas.fijada: 0 por defecto, asi las notas existentes no se fijan solas.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'notas'
       AND COLUMN_NAME  = 'fijada'
);
SET @sql := IF(@existe = 0,
    'ALTER TABLE notas ADD COLUMN fijada TINYINT(1) NOT NULL DEFAULT 0 AFTER completada_en',
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
