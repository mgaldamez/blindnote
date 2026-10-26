# Feature: notas editables y completadas

Gestión de notas del usuario: edición, marcado como completada y listas separadas.
Rama: `feat/notas-editar-completadas`.

## Specs

S1. Pedido original (textual): "el siguiente feat lo trabajaremos en otra rama, las
    notas se deben poder editar y completar y mostrar en otra lista como
    completadas, algo asi."

S2. Una nota se marca como completada con un booleano `completada` y guarda la
    fecha en `completada_en`. Al reabrirla, la fecha se limpia.

S3. Una nota completada puede volver a pendiente (reabrir). No hay estados
    intermedios.

S4. La edición (título y contenido) se hace en un formulario aparte,
    `nota_form.php`, que sirve tanto para crear como para editar.

S5. `notas.php` muestra dos pestañas con contador: **Pendientes** y
    **Completadas**. Las completadas se listan de la más reciente a la más
    antigua según `completada_en`.

S6. Cada acción de escritura (crear, editar, completar, reabrir, eliminar) se
    hace por POST con token CSRF y queda registrada en `logs_auditoria`.

S7. Control de acceso: toda operación filtra por `usuario_id` de la sesión
    (anti-IDOR). Una nota ajena no se puede leer, editar, completar ni borrar:
    se responde como si no existiera.

S8. Toda salida se escapa con `e()` y todas las consultas usan sentencias
    preparadas.

## Tasks

- [x] T1. Esquema (`completada`, `completada_en` + migración) y funciones de
      notas en `includes/functions.php`, con tests unitarios. (S2, S3, S7) — `b68c242`
- [x] T2. `nota_form.php` para crear y editar, enlazado desde la lista. (S4, S6, S7) — `b401e66`
- [x] T3. Pestañas Pendientes/Completadas en `notas.php` con las acciones
      completar y reabrir. (S2, S3, S5, S6) — `8bfb436`
- [x] T4. Documentación y verificación final. (S1-S8) — commit del slice 3

## Log

L1. "el siguiente feat lo trabajaremos en otra rama, las notas se deben poder
    editar y completar y mostrar en otra lista como completadas, algo asi."

L2. Decisiones: booleano + fecha de completado; formulario aparte para editar;
    pestañas Pendientes/Completadas en `notas.php`; reabrir permitido.

L3. Slices de revisión (el primer commit ya superó las ~400 líneas por los
    tests). slice 1 = T1 (esquema, migración, funciones y 27 casos) → `b68c242`;
    slice 2 = T2 (formulario de alta/edición y limpieza del alta en línea) → `b401e66`;
    slice 3 = T3+T4 (pestañas, acciones de estado y documentación) → `8bfb436`
    más el commit de docs.

L4. Verificación del cierre: base recreada desde `database/schema.sql` (las
    columnas nuevas se crean de cero), lint de 24 archivos PHP, cuatro suites en
    verde (fuerza bruta, usuarios, auditoría, notas) y cuatro flujos E2E
    (formulario de notas, pestañas/estado, paginación de usuarios, cambio de
    clave obligatorio). El CI corre la suite de notas junto a las otras tres.

L5. Hallazgo al pasar: `notas.php` usaba `onsubmit="confirm(...)"` en línea, que
    la CSP (`script-src 'self'`) bloquea: la confirmación de borrado nunca se
    mostraba. Ahora los formularios declaran `data-confirmar` y `app.js` pregunta
    antes de enviar. Un test E2E verifica que no quede JS en línea.

L6. Lección de tests E2E: el token CSRF es por sesion, asi que hay que tomarlo de
    una pagina del mismo usuario que hace el POST; usar el de otra sesion hace
    que la app rechace la accion (correctamente) y el test falle por la razon
    equivocada.
