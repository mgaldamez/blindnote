# BlindNote

[![CI](https://github.com/mgaldamez/blindnote/actions/workflows/ci.yml/badge.svg)](https://github.com/mgaldamez/blindnote/actions/workflows/ci.yml)

Sitio web seguro desarrollado para la asignatura **Diseño de Seguridad de Redes**
(Universidad Doctor Andrés Bello — Ingeniería en Sistemas).

Cubre la **Fase 3 (control de acceso y autenticación)**, la **Fase 4 (análisis de
vulnerabilidades)** y la **Fase 5 (política de seguridad)** del proyecto del
Tercer Parcial.

---

## 1. Requisitos

- **PHP 8.x** y **MySQL/MariaDB**.
- Un servidor web: **Apache** (Laragon, XAMPP o WAMP) o **Nginx**.
- Opcional para la Fase 4: **OWASP ZAP**, **Nikto**, **SQLMap** y
  **Mozilla Observatory**.

> El sitio incluye un lanzador con el servidor embebido de PHP para pruebas
> rápidas, pero para demostrar `.htaccess`, HTTPS y cabeceras del servidor se
> recomienda Apache o Nginx.

## 2. Instalación

### Opción A — Laragon (recomendada)

1. Copia la carpeta `seguridad-web` dentro de `C:\laragon\www\`.
2. Arranca Laragon (**Start All**).
3. Importa la base de datos (una sola vez):
   - **phpMyAdmin**: importar `database/schema.sql`, o
   - **Consola**:
     ```bash
     mysql -u root -p < database/schema.sql
     ```
4. Abre el sitio:
   - Si el puerto 80 está libre: `http://seguridad-web.test/`
   - **En este equipo** el puerto 80 lo ocupa IIS, por lo que Apache usa el
     puerto **88**: `http://seguridad-web.test:88/`

### Opción B — XAMPP / WAMP

1. Copia la carpeta dentro de `C:\xampp\htdocs\` (o del directorio `www` de WAMP).
2. Enciende **Apache** y **MySQL**.
3. Importa `database/schema.sql` desde phpMyAdmin.
4. Abre `http://localhost/seguridad-web/`.

### Opción C — Servidor embebido de PHP

1. Asegúrate de que **MySQL esté corriendo**.
2. Importa `database/schema.sql` (si es la primera vez).
3. Haz doble clic en **`iniciar-web.bat`**.
4. Abre `http://localhost:8099/`.

> Con el servidor embebido no se aplica `.htaccess`. Las cabeceras de seguridad
> principales se envían igualmente desde `includes/init.php`.

### Opción D — Docker

Requiere **Docker Desktop** (con Docker Compose). El esquema de la base de datos
se importa automáticamente la primera vez que se crea el contenedor de MySQL.

**Usar la imagen publicada** (recomendado para probar, no requiere clonar el
código ni construir nada):

```bash
docker compose up -d            # baja ghcr.io/mgaldamez/blindnote:latest
docker compose pull && docker compose up -d   # trae la última publicada
```

**Construir desde el código fuente** (desarrollo y CI, usa
`docker-compose.dev.yml` como override):

```bash
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
```

Abre `http://localhost:8080/`. Para detenerlo:

```bash
docker compose down
```

Para borrar también los datos (y que el esquema se vuelva a importar):

```bash
docker compose down -v
```

La configuración se pasa por variables de entorno (`DB_HOST`, `DB_USUARIO`,
`DB_CLAVE`, `MODO_DEBUG`, etc.) definidas en `docker-compose.yml`; `config.php`
usa esos valores y, si no existen, mantiene los de Laragon. Para fijar una
versión concreta de la imagen: `BLINDNOTE_TAG=sha-abc1234 docker compose up -d`.

#### Variables de entorno (`.env`)

Docker Compose lee automáticamente un archivo `.env` en esta carpeta y
sobreescribe los valores por defecto del compose. Creá ese archivo con las
variables que quieras cambiar (todas son opcionales):

```bash
# .env  (no se versiona)
DB_CLAVE=una_clave_mejor
WEB_PORT=8080
```

| Variable | Valor por defecto | Para qué sirve |
|---|---|---|
| `BLINDNOTE_TAG` | `latest` | Tag de la imagen de GHCR (`main`, `sha-<commit>`, `vX.Y.Z`) |
| `WEB_PORT` | `8080` | Puerto publicado del sitio |
| `DB_HOST` | `db` | Servicio MySQL del compose (fuera de Docker: `127.0.0.1`) |
| `DB_PORT` | `3306` | Puerto de MySQL |
| `DB_NOMBRE` | `seguridad_web` | Nombre de la base (`MYSQL_DATABASE`) |
| `DB_USUARIO` | `blindnote` | Usuario de la base (`MYSQL_USER`); no puede ser `root` |
| `DB_CLAVE` | `blindnote` | Contraseña de ese usuario (`MYSQL_PASSWORD`) |
| `DB_CHARSET` | `utf8mb4` | Juego de caracteres de la conexión |
| `MYSQL_ROOT_PASSWORD` | `root` | Contraseña de root **del contenedor** |
| `COOKIE_SOLO_HTTPS` | `false` | Cookie de sesión sólo por HTTPS |
| `MODO_DEBUG` | `true` | Detalle de errores (en producción: `false`) |
| `MAX_INTENTOS_POR_IP` | `20` | Fallos por IP (15 min) antes de bloquear; `0` deshabilita |

Esos valores son los que el código tiene quemados hoy: dejarlos igual reproduce
el comportamiento actual.

> `.env` está en `.gitignore` y en `.dockerignore`: nunca se versiona ni entra
> en la imagen. El repo sí incluye `.env.example` (sin secretos) como plantilla:
> `.env.*` está ignorado salvo esa excepción.
>
> El servidor embebido de PHP (`php -S`) **no** lee `.env`: en ese modo hay que
> exportar las variables en el entorno o editar `config/config.php`.

### Configuración de la base de datos

Si tu MySQL usa otro usuario, puerto o contraseña, edita `config/config.php`:

```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NOMBRE', 'seguridad_web');
define('DB_USUARIO', 'root');
define('DB_CLAVE', 'root');
```

En producción se recomienda un usuario de base de datos dedicado con privilegios
mínimos, no `root`.

## 3. Primer usuario y acceso

No se incluyen usuarios ni contraseñas por defecto. El **instalador `setup.php`
fue eliminado** por seguridad; el esquema se importa desde
`database/schema.sql`.

1. Abre `register.php`.
2. El **primer usuario registrado** obtiene el rol `admin`.
3. Los siguientes registros obtienen el rol `usuario`.
4. Inicia sesión en `login.php`.

Si ya tienes una base de datos creada por una versión anterior, conserva tus
usuarios existentes y **cambia cualquier contraseña de demostración** antes de la
presentación.

Para promover manualmente a un usuario (phpMyAdmin o consola):

```sql
UPDATE usuarios SET rol = 'admin' WHERE username = 'nombre_usuario';
```

> En un despliegue real, restringe o deshabilita `register.php` una vez creadas
> las cuentas necesarias.

## 4. Estructura

```text
seguridad-web/
├─ index.php               Portada (redirige según sesión)
├─ login.php               Fase 3: inicio de sesión con validación
├─ register.php            Fase 3: registro con política de contraseñas
├─ logout.php              Fase 3: cierre de sesión seguro (POST + CSRF)
├─ dashboard.php           Panel con medidas de seguridad activas
├─ notas.php               CRUD que demuestra mitigación de SQLi/XSS/CSRF/IDOR
├─ admin.php               Administración: usuarios paginados y auditoría
├─ usuarios_form.php       Alta y edición de usuarios (solo admin)
├─ cambiar_clave.php       Cambio de contraseña (obligatorio si es temporal)
├─ informe_fase4.php       Fase 4: informe de vulnerabilidades y soluciones
├─ INFORME_PROYECTO.md     Informe del proyecto y verificación del checklist
├─ COMO_FUNCIONA.md        Guía técnica: flujos, controles y pruebas
├─ POLITICA_SEGURIDAD.md   Fase 5: política de seguridad (borrador)
├─ iniciar-web.bat         Lanzador del servidor local (Windows)
├─ config/
│  ├─ config.php           Parámetros generales y de seguridad
│  └─ database.php         Conexión PDO (consultas preparadas)
├─ includes/
│  ├─ init.php             Arranque: sesión + cabeceras de seguridad
│  ├─ functions.php        CSRF, escape XSS, sesiones, fuerza bruta, auditoría
│  ├─ auth.php             Autenticación, RBAC y anti fijación de sesión
│  ├─ header.php / footer.php
├─ assets/css/estilos.css
├─ database/schema.sql     Esquema de la base de datos
├─ docker-compose.yml      Stack desde la imagen publicada (GHCR)
├─ docker-compose.dev.yml  Override que construye desde el Dockerfile
├─ tests/
│  ├─ fuerza-bruta-test.php  Regresión del bloqueo por cuenta e IP
│  └─ usuarios-crud-test.php Regresión del CRUD y de las guardas de admin
├─ .github/workflows/ci.yml  Integración continua: build, checks y publicación
├─ deploy/nginx.conf.example  Proxy inverso de referencia (solo 443)
└─ .htaccess               Cabeceras de seguridad y protección de carpetas
```

### Documentación del proyecto

| Archivo | Contenido |
|---|---|
| `INFORME_PROYECTO.md` | Informe completo: objetivos, arquitectura, controles, verificación del checklist y evidencias. |
| `COMO_FUNCIONA.md` | Guía técnica: flujo de peticiones, autenticación, sesiones, RBAC, CSRF, XSS, SQLi, pruebas y solución de problemas. |
| `POLITICA_SEGURIDAD.md` | Política de seguridad de la Fase 5. |

> Los entregables en Word y PDF (`INFORME_PROYECTO.docx`, `INFORME_PROYECTO.pdf`)
> y los PDF del laboratorio no se versionan: están en `.gitignore` y se generan o
> se entregan por el aula virtual. El contenido fuente es `INFORME_PROYECTO.md`.

> Los archivos `.md` están bloqueados por `.htaccess` para que no se sirvan por
> HTTP. Ábrelos desde el explorador de archivos.

## 5. Fase 3 — Control de acceso y autenticación

| Requisito de la tarea       | Implementación                                                   |
| --------------------------- | ---------------------------------------------------------------- |
| Formulario con validación   | `login.php` + validación en servidor y atributos HTML5            |
| Cifrado de contraseñas      | `password_hash()` con **bcrypt** (cost 12) y `password_verify()`  |
| Cierre de sesión seguro     | `logout.php` por **POST + token CSRF** + destrucción de cookie    |
| Control de acceso           | Sesiones + **RBAC** (`exigir_rol`) y verificación de propiedad    |
| Extras                      | Bloqueo por fuerza bruta, regeneración de ID, expiración          |
| Administración de usuarios  | Listado paginado con filtros, alta con **clave temporal**, edición, baja lógica (soft delete) y reactivación |
| Guardas de auto-bloqueo     | Un admin no puede desactivarse ni quitarse el rol; nunca se queda el sistema sin admins activos |

## 6. Fase 4 — Análisis de vulnerabilidades

El informe está en `informe_fase4.php` e incluye herramientas, hallazgos,
evidencia, severidad, soluciones y mapeo con el OWASP Top 10.

Pruebas manuales sugeridas:

- **XSS**: crea una nota con `<script>alert(1)</script>` → se muestra como texto.
- **SQLi**: en el login usa `admin' OR '1'='1` → acceso denegado.
- **CSRF**: envía un POST sin `csrf_token` → error 403.
- **IDOR**: intenta borrar la nota de otro usuario → no se elimina.
- **Cabeceras**: `curl -I http://seguridad-web.test:88/login.php`.

Para obtener la evidencia completa, ejecuta OWASP ZAP o Nikto contra el sitio y
adjunta el reporte o las capturas al informe.

Prueba automatizada del control de fuerza bruta (regresión de "todos los
usuarios quedan bloqueados"):

```bash
docker compose exec web php tests/fuerza-bruta-test.php
```

Verifica que el bloqueo sea **por cuenta e IP**: 5 fallos con nombres ajenos
desde una misma IP no bloquean a un usuario legítimo, 5 fallos propios sí lo
bloquean, los fallos de otra IP o fuera de la ventana de 15 minutos no cuentan,
el usuario y el correo de la misma cuenta comparten un único contador, y el
tope secundario por IP corta el *password spraying* sin bloquear a una cuenta
que no acumuló fallos propios.

## 7. Fase 5 — Política de seguridad

El borrador completo está en **`POLITICA_SEGURIDAD.md`**. Incluye:

- Gestión de usuarios, roles, altas, bajas y revisión de accesos.
- Política de contraseñas, bcrypt, bloqueo por fuerza bruta y sesiones.
- Copias de seguridad, retención, regla 3-2-1 y pruebas de restauración.
- Registro, auditoría y monitoreo.
- Respuesta ante incidentes: clasificación, fases, procedimientos y evidencias.
- Configuración segura del servidor, HTTPS y gestión de cambios.

## 8. HTTPS

La aplicación está preparada para HTTPS. En el servidor donde se instale el
certificado (válido o autofirmado):

1. Configura el sitio en el puerto **443** con TLS 1.2 o superior.
2. Redirige el tráfico HTTP a HTTPS.
3. Edita `config/config.php` y cambia:
   ```php
   define('COOKIE_SOLO_HTTPS', true);
   ```
4. Descomenta la cabecera HSTS en `includes/init.php`:
   ```php
   header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
   ```
   y en `.htaccess`:
   ```apache
   Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
   ```
5. Verifica el acceso con `https://tu-dominio/` y comprueba que la cookie de
   sesión tenga el atributo `Secure`.

**Proxy inverso (VPS):** con nginx/Caddy delante, publicá la app solo en
loopback (`WEB_PORT=127.0.0.1:8080`) y pasá `X-Real-IP $remote_addr` (o
`X-Forwarded-For $remote_addr`, sobrescribiendo). `ip_cliente()` confía en esa
cabecera únicamente cuando la petición viene de loopback o red privada; si el
puerto queda expuesto, la cabecera se puede falsificar y el límite por IP se
vuelve inútil. HSTS y las cabeceras de TLS van en el proxy.

> Activa HSTS únicamente cuando HTTPS funcione de forma estable.

## 9. Integración continua (GitHub Actions)

El workflow `.github/workflows/ci.yml` corre en cada **push** y **pull request**:

1. Construye la imagen con `docker compose up --build --wait` usando
   `docker-compose.yml` + `docker-compose.dev.yml` (variable `COMPOSE_FILE`),
   así valida el commit pusheado y no la imagen publicada, y espera el
   *healthcheck* de MySQL.
2. Comprueba con `curl` que `login.php` responda **HTTP 200** desde el runner
   (`web` no define healthcheck, así que `--wait` no prueba que Apache sirva la
   aplicación).
3. Ejecuta `php -l` sobre todos los archivos PHP.
4. Corre la regresión del control de fuerza bruta (`tests/fuerza-bruta-test.php`)
   contra la base real, y baja el stack al terminar.

En push a `main` (o en un tag `vX.Y.Z`) además publica la imagen en GitHub
Container Registry, con `cache` entre ejecuciones:

```bash
docker pull ghcr.io/mgaldamez/blindnote:latest
docker run --rm -p 8080:80 ghcr.io/mgaldamez/blindnote:latest
```

Tags generados: `latest` (rama por defecto), el nombre de la rama, `sha-<corto>`
y `vX.Y.Z` para tags semánticos. La imagen requiere una base MySQL accesible:
usa el `docker-compose.yml` del repositorio para levantar el par completo.

> El paquete de GHCR se crea **privado**. Para hacerlo público: perfil →
> Packages → `blindnote` → Package settings → Change visibility.

## 10. Despliegue en un VPS (Docker + nginx)

Modelo de referencia, con la app publicada **solo en loopback** y el sitio
servido **únicamente por 443** (el puerto 80 no se expone):

1. En el servidor, clonar y configurar:
   ```bash
   git clone https://github.com/mgaldamez/blindnote.git && cd blindnote
   cp .env.example .env
   ```
2. Ajustar el `.env` de producción:
   ```ini
   WEB_PORT=127.0.0.1:8080   # nunca 0.0.0.0: expondria la app sin TLS
   MODO_DEBUG=false
   COOKIE_SOLO_HTTPS=true
   DB_CLAVE=<clave fuerte>
   MYSQL_ROOT_PASSWORD=<clave fuerte>
   BLINDNOTE_TAG=v1.0.0      # tag fijo, no latest
   ```
3. Levantar (la imagen viene de GHCR, el código PHP no se copia al servidor):
   ```bash
   docker compose pull && docker compose up -d
   ```
4. Proxy y TLS: copiar **`deploy/nginx.conf.example`** a
   `/etc/nginx/sites-available/blindnote`, cambiar el dominio y recargar.
   Sin puerto 80, el certificado se renueva con **DNS-01** o TLS-ALPN-01.
5. Firewall: permitir 22 y 443; el 8080 no se abre.

Comprobaciones:

```bash
ss -ltnp | grep 8080              # debe decir 127.0.0.1:8080
curl -sI https://<dominio> | grep -iE 'strict-transport|server:|http/'
docker compose exec -T db mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" seguridad_web > respaldo.sql
```

> El esquema se importa solo la primera vez que se crea el volumen `db_data`;
> al restaurar un respaldo no se vuelve a ejecutar `database/schema.sql`.

## 11. Aviso

Proyecto **académico**. Ejecuta esta aplicación solo en un entorno local o de
demostración. No la expongas a Internet sin HTTPS, sin revisar la política de
seguridad y sin cambiar las credenciales de prueba.
