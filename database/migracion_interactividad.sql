-- =====================================================================
-- Migracion: Nuevas caracteristicas interactivas (Avatar y Notas Fijadas)
-- =====================================================================

USE seguridad_web;

-- 1. Agregar campo avatar a la tabla usuarios (opcional, null por defecto)
ALTER TABLE usuarios ADD COLUMN avatar VARCHAR(30) NULL DEFAULT NULL AFTER activo;

-- 2. Agregar campo fijada a la tabla notas (0 por defecto)
ALTER TABLE notas ADD COLUMN fijada TINYINT(1) NOT NULL DEFAULT 0 AFTER completada_en;
