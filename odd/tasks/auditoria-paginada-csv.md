# Feature: auditoría paginada con exportación CSV

Administración del registro de auditoría de BlindNote (Fase 5: monitoreo).
Rama: `feat/auditoria-paginada-csv`.

## Specs

S1. Pedido original (textual): "ahora en una nueva rama trabajemos la paginacion
    de la auditoria reciente y que se pueda generar un csv. usa odd"

S2. La auditoría vive en una página propia, `auditoria.php`, accesible **solo
    para el rol `admin`** y enlazada desde el menú y desde `admin.php`.

S3. El listado se pagina (20 filas por página) y se puede filtrar por **acción**,
    **usuario** y **rango de fechas** (`desde` / `hasta`). Los filtros se
    conservan al navegar entre páginas y una página fuera de rango cae en la
    última.

S4. El botón de exportación descarga un **CSV** con las filas que el filtro
    selecciona (todas, no solo la página visible), con separador `,` y sin BOM,
    `Content-Type: text/csv; charset=utf-8` y nombre de archivo
    `auditoria-<AAAAMMDD-HHMM>.csv`.

S5. Seguridad de la exportación: exige rol `admin`, usa sentencias preparadas,
    neutraliza las fórmulas al inicio de celda (`=`, `+`, `-`, `@`, tab y CR)
    para evitar *CSV injection*, y registra el evento en la propia auditoría.

S6. `admin.php` conserva un resumen de los últimos 15 eventos con un enlace a la
    página completa, sin romper lo que ya existe.

## Tasks

- [ ] T1. `listar_auditoria()` en `includes/functions.php` + tests unitarios de
      paginación, filtros y saneamiento. (S3)
- [ ] T2. `auditoria.php`: listado paginado con filtros, navegación y resumen.
      (S2, S3)
- [ ] T3. Exportación CSV: `campo_csv()`, `filas_para_csv()` y el endpoint de
      descarga, con escape anti-fórmula y auditoría del evento. (S4, S5)
- [ ] T4. Enlace en el menú y en `admin.php`, documentación y verificación
      final. (S2, S6)

## Log

L1. "ahora en una nueva rama trabajemos la paginacion de la auditoria reciente y
    que se pueda generar un csv. usa odd"

L2. Decisiones: página aparte `auditoria.php`; paginación con filtros por acción,
    usuario y fechas; CSV de lo filtrado con separador `,` y sin BOM.
