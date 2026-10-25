-- =====================================================================
--  Migracion OPCIONAL: contrasena temporal con cambio obligatorio
-- ---------------------------------------------------------------------
--  Agrega usuarios.debe_cambiar_clave, que marca las contrasenas creadas
--  por un administrador y obliga a cambiarlas en el primer login.
--
--  Una instalacion nueva ya la crea (database/schema.sql). Este script sirve
--  para una base creada con el esquema anterior.
--
--  No es destructivo, pero MySQL no admite "ADD COLUMN IF EXISTS": si ya la
--  agregaste, el script falla.
--
--  Uso:
--      docker compose exec -T db mysql -uroot -proot seguridad_web < database/migracion_cambio_clave.sql
-- =====================================================================

ALTER TABLE usuarios
    ADD COLUMN debe_cambiar_clave TINYINT(1) NOT NULL DEFAULT 0;
