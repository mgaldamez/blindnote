# Feature: CRUD de usuarios con paginación

Feature de administración de usuarios para BlindNote (Fase 3: control de acceso).

## Specs

S1. Pedido original (textual): "me gustaria agregar paginacion a los usuarios y
    tambien completar el crud, veo que ya se usa el soft delete, asi que sigue
    igual."

S2. La lista de usuarios se pagina de a **10 por página**, con buscador por
    nombre, usuario o correo, y filtros por rol (`admin`/`usuario`) y por estado
    (`activos`/`inactivos`/`todos`).

S3. El CRUD vive en dos archivos: `admin.php` (lista, paginación, filtros y
    acciones) y `usuarios_form.php` (alta y edición). Toda acción de escritura
    es POST con token CSRF.

S4. Al crear un usuario, el administrador define una **contraseña temporal**; el
    usuario debe **cambiarla obligatoriamente en su primer login** antes de
    acceder a cualquier otra sección.

S5. El borrado de usuarios es **soft delete** (`usuarios.activo = 0`), nunca
    `DELETE`: se conservan las filas y su historial de auditoría. La operación
    inversa (reactivar) existe.

S6. Guardas de auto-bloqueo: un administrador no puede desactivar su propia
    cuenta ni quitarse el rol `admin`; y no se puede dejar el sistema sin
    ningún administrador activo.

S7. Cada cambio (alta, edición, activación, desactivación, cambio de contraseña)
    se registra en `logs_auditoria`; todas las consultas usan sentencias
    preparadas y toda salida se escapa con `e()`.

S8. Un usuario desactivado no puede iniciar sesión (comportamiento ya existente
    en `autenticar()`), y al reactivarlo vuelve a poder.

## Tasks

T1. `database/schema.sql` + migración: columna `debe_cambiar_clave` en `usuarios`. (S4)
T2. Flujo de cambio obligatorio: `cambiar_clave.php` + guarda en `exigir_autenticacion()`. (S4)
T3. Lista paginada con búsqueda y filtros en `admin.php`. (S2)
T4. Alta de usuario con contraseña temporal en `usuarios_form.php`. (S3, S4, S7)
T5. Edición de usuario en `usuarios_form.php` (nombre, correo, usuario, rol). (S3, S6, S7)
T6. Desactivar/reactivar desde `admin.php` con guardas de auto-bloqueo. (S5, S6, S7)
T7. Documentación (README, COMO_FUNCIONA, POLITICA) y verificación final. (S1-S8)

## Log

L1. "me gustaria agregar paginacion a los usuarios y tambien completar el crud,
    veo que ya se usa el soft delete, asi que sigue igual."

L2. Decisiones del usuario: contraseña temporal con cambio obligatorio al primer
    login; paginación de 10 con búsqueda y filtros; lista en `admin.php` y
    formulario en un archivo aparte.

L3. Precisión sobre la premisa de L1: `usuarios.activo` se lee en `autenticar()`
    pero ninguna acción lo escribía, así que el soft delete estaba a medias. Se
    completa en T6.
