-- =====================================================================
--  Migracion OPCIONAL: notas editables y completadas
-- ---------------------------------------------------------------------
--  Agrega el estado de completado a las notas:
--    completada    = 0/1
--    completada_en = fecha en que se completo (NULL si esta pendiente)
--
--  Una instalacion nueva ya la crea (database/schema.sql). Este script sirve
--  para una base creada con el esquema anterior.
--
--  No es destructivo, pero MySQL no admite "ADD COLUMN IF EXISTS": si ya
--  agregaste las columnas, el script falla.
--
--  Uso:
--      docker compose exec -T db mysql -uroot -proot seguridad_web < database/migracion_notas_completadas.sql
-- =====================================================================

ALTER TABLE notas
    ADD COLUMN completada TINYINT(1) NOT NULL DEFAULT 0 AFTER contenido,
    ADD COLUMN completada_en DATETIME NULL AFTER completada,
    ADD KEY idx_notas_estado (usuario_id, completada);
