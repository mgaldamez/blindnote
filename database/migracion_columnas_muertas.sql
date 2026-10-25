-- =====================================================================
--  Migracion OPCIONAL: retira las columnas de fuerza bruta sin uso
-- ---------------------------------------------------------------------
--  El control de fuerza bruta vive solo en la tabla intentos_login.
--  Las columnas usuarios.intentos_fallidos y usuarios.bloqueado_hasta
--  nunca se incrementaron: solo se reiniciaban en cada login correcto.
--
--  Una instalacion nueva ya no las crea (database/schema.sql). Este script
--  sirve para limpiar una base creada con el esquema viejo.
--
--  ANTES DE EJECUTAR:
--    1) Hacer respaldo (mysqldump seguridad_web > respaldo.sql).
--    2) Confirmar que nadie mas escribe esas columnas.
--
--  Es destructivo y no se puede deshacer. MySQL no admite
--  "DROP COLUMN IF EXISTS": si ya las retiraste, el script fallara.
--
--  Uso:
--      docker compose exec -T db mysql -uroot -proot seguridad_web < database/migracion_columnas_muertas.sql
-- =====================================================================

ALTER TABLE usuarios
    DROP COLUMN intentos_fallidos,
    DROP COLUMN bloqueado_hasta;
