# Cómo funciona BlindNote

Guía técnica y funcional del sitio. Explica qué hace cada parte, cómo fluye una
petición, dónde están los controles de seguridad y cómo probarlos.

---

## 1. Vista general

BlindNote es una aplicación web monolítica en PHP con estas capas:

```text
Navegador
   │  HTTP / HTTPS
   ▼
Apache / Nginx  ──►  .htaccess (cabeceras, bloqueos)
   │
   ▼
PHP (login.php, dashboard.php, notas.php, admin.php...)
   │  includes/init.php  → sesión + cabeceras + funciones
   │  includes/auth.php  → autenticación y roles
   │  includes/functions.php → CSRF, XSS, auditoría, validaciones
   ▼
PDO  ──►  MySQL (usuarios, notas, intentos_login, logs_auditoria)
```

La aplicación no usa frameworks ni dependencias externas: todo el flujo está en
PHP, HTML y CSS. Esto facilita la revisión y la demostración académica.

## 2. Puesta en marcha rápida

### 2.1 Requisitos

- PHP 8.x
- MySQL 8 / MariaDB
- Apache, Nginx o el servidor embebido de PHP

### 2.2 Instalación

1. Copiar la carpeta del proyecto al directorio web.
2. Crear la base de datos importando `database/schema.sql`:
   ```bash
   mysql -u root -p < database/schema.sql
   ```
   También se puede importar desde phpMyAdmin.
3. Revisar las credenciales de MySQL en `config/config.php`.
4. Abrir el sitio y registrar el primer usuario en `register.php`.
   El primer usuario obtiene el rol `admin`; los siguientes, `usuario`.

### 2.3 URLs del entorno

| Entorno | URL |
|---|---|
| Laragon con puerto 80 libre | `http://seguridad-web.test/` |
| Laragon en este equipo (IIS ocupa el 80, Apache usa el 88) | `http://seguridad-web.test:88/` |
| XAMPP / WAMP | `http://localhost/seguridad-web/` |
| Servidor embebido (`iniciar-web.bat`) | `http://localhost:8099/` |
| Servidor con certificado | `https://tu-dominio/` |

> Con el servidor embebido de PHP no se aplica `.htaccess`; las cabeceras
> principales se envían igualmente desde `includes/init.php`.

## 3. Mapa de archivos

| Archivo | Responsabilidad |
|---|---|
| `index.php` | Redirige al panel si hay sesión o al login si no. |
| `login.php` | Formulario y proceso de inicio de sesión. |
| `register.php` | Registro con validación y política de contraseñas. |
| `logout.php` | Cierre de sesión por POST + CSRF. |
| `dashboard.php` | Panel del usuario autenticado. |
| `notas.php` | CRUD de notas personales con controles anti-IDOR/XSS/CSRF. |
| `admin.php` | Panel de administración y auditoría (solo `admin`). |
| `informe_fase4.php` | Informe de herramientas, hallazgos y soluciones. |
| `config/config.php` | Parámetros de aplicación, BD y seguridad. |
| `config/database.php` | Conexión PDO con consultas preparadas. |
| `includes/init.php` | Arranque: cabeceras, sesión y dependencias. |
| `includes/functions.php` | CSRF, escape XSS, sesiones, fuerza bruta, auditoría. |
| `includes/auth.php` | Autenticación, RBAC y coherencia de sesión. |
| `includes/header.php` / `footer.php` | Estructura visual común. |
| `database/schema.sql` | Esquema de la base de datos. |
| `POLITICA_SEGURIDAD.md` | Política de seguridad (Fase 5). |
| `INFORME_PROYECTO.md` | Informe del proyecto y verificación del checklist. |
| `.htaccess` | Cabeceras, bloqueo de archivos y carpetas internas. |

## 4. Ciclo de una petición

Ejemplo: el usuario abre `dashboard.php`.

1. Apache recibe la petición y aplica `.htaccess` (cabeceras y bloqueos).
2. `dashboard.php` incluye `includes/init.php`.
3. `init.php`:
   - define las cabeceras de seguridad;
   - carga `functions.php` y `auth.php`;
   - inicia la sesión con parámetros endurecidos.
4. `dashboard.php` llama a `exigir_autenticacion()`:
   - si no hay sesión, redirige al login;
   - si hay sesión, verifica inactividad y vida máxima.
5. Se consulta la base de datos con PDO y consultas preparadas.
6. Se renderiza el HTML. Toda salida de datos del usuario pasa por `e()`.
7. El navegador recibe la página con las cabeceras de seguridad.

## 5. Autenticación paso a paso

### 5.1 Formulario de login

`login.php`:

1. Si ya hay sesión, redirige al panel.
2. En un POST, verifica primero el token CSRF.
3. Recoge `username` y `password`, y valida que no estén vacíos.
4. Llama a `autenticar($username, $password)` en `includes/auth.php`.
5. Si la autenticación falla, muestra un mensaje genérico.
6. Si es correcta, redirige a `dashboard.php`.

### 5.2 Función `autenticar()`

Ubicada en `includes/auth.php`:

1. Busca al usuario con una consulta preparada:
   ```sql
   SELECT ... FROM usuarios
   WHERE username = :username OR email = :email
   LIMIT 1
   ```
2. Resuelve los identificadores de esa cuenta (usuario y correo); si el
   identificador no corresponde a ninguna cuenta, usa el texto tipeado.
3. Cuenta los fallos recientes **por cuenta e IP** y, aparte, **por IP** (tope
   secundario contra *password spraying*).
4. Si supera cualquiera de los dos límites, bloquea temporalmente y registra
   en auditoría qué límite se alcanzó (al usuario se le responde siempre lo
   mismo).
5. Si no existe, ejecuta un hash ficticio para igualar tiempos y evitar
   enumeración por temporización.
6. Verifica la contraseña con `password_verify()`.
7. Si el hash necesita actualización, aplica `password_hash()` de nuevo.
8. Regenera el ID de sesión con `session_regenerate_id(true)`.
9. Guarda en la sesión: id, nombre, usuario, correo, rol, hora de inicio,
   última actividad, User-Agent e IP.
10. Actualiza `ultimo_login` y registra auditoría. El conteo de fuerza bruta
    vive solo en `intentos_login`, no en la fila del usuario.

### 5.3 Mensajes y bloqueo

- Todos los fallos de credenciales muestran el mismo texto:
  “Usuario o contraseña incorrectos.”
- `ip_cliente()` respeta el proxy inverso: si la petición viene de loopback o de
  una red privada (el proxy), toma la IP real de `X-Real-IP` o del último
  elemento de `X-Forwarded-For`. Si llega directo desde Internet usa
  `REMOTE_ADDR`, porque la cabecera es falsificable. **Requisito:** publicar el
  puerto de la app solo en `127.0.0.1`, nunca en `0.0.0.0`.
- Tras 5 intentos fallidos en 15 minutos **para esa cuenta y esa IP** se
  bloquea el acceso de esa combinación.
- `intentos_fallidos_recientes()` cuenta con una condición conjuntiva
  (`username IN (...) AND ip = :ip`): los fallos de otras cuentas desde la
  misma IP no bloquean a un usuario legítimo (caso NAT, proxy inverso o ZAP
  corriendo en el mismo host).
- El conteo es **por cuenta**: recibe el usuario y el correo del titular y
  suma los fallos tipeados con cualquiera de los dos, así que alternar
  identificadores no duplica los intentos ni esquiva el bloqueo.
- Los intentos se guardan en `intentos_login` y los bloqueos en
  `logs_auditoria`.

### 5.4 Administración de usuarios

Solo para el rol `admin` (`admin.php` exige el rol; las funciones de escritura
lo vuelven a comprobar). Pantallas: `admin.php` (listado y acciones) y
`usuarios_form.php` (alta y edición).

- **Listado**: 10 filas por página con buscador (nombre, usuario, correo) y
  filtros por rol y por estado. La consulta vive en `listar_usuarios()`
  (`includes/functions.php`) y usa sentencias preparadas; los enlaces de
  paginación conservan los filtros.
- **Alta**: el administrador define una contraseña temporal; la fila se crea con
  `debe_cambiar_clave = 1` y el usuario es redirigido a `cambiar_clave.php` en
  su primer login (`exigir_clave_al_dia()` dentro de `exigir_autenticacion()`).
- **Edición**: nombre, correo, usuario y rol. Si un administrador se edita a sí
  mismo, se refrescan los datos de la sesión.
- **Baja**: soft delete (`activo = 0`) y reactivación. La cuenta desactivada no
  puede iniciar sesión y, al reactivarse, vuelve a poder.
- **Guardas** (`puede_desactivar()`, `puede_cambiar_rol()`,
  `contar_admins_activos()`): nadie se desactiva a sí mismo, nadie se quita su
  rol `admin` y no se puede dejar el sistema sin administradores activos. Se
  aplican en la función, no solo en la interfaz.
- **Auditoría**: `USUARIO_CREADO`, `USUARIO_EDITADO`, `USUARIO_DESACTIVADO`,
  `USUARIO_ACTIVADO`, `CLAVE_CAMBIADA` y los rechazos
  (`USUARIO_ALTA_RECHAZADA`, `USUARIO_EDICION_RECHAZADA`,
  `USUARIO_ACCION_RECHAZADA`).

## 6. Registro y política de contraseñas

`register.php` valida:

| Campo | Regla |
|---|---|
| Nombre | Mínimo 3 caracteres. |
| Correo | Formato válido con `FILTER_VALIDATE_EMAIL`. |
| Usuario | 3 a 50 caracteres: letras, números, punto y guion bajo. |
| Contraseña | Mínimo 8 caracteres, mayúscula, minúscula y número. |
| Confirmación | Debe coincidir con la contraseña. |
| Duplicados | Consulta preparada por usuario o correo. |

Si todo es válido:

1. Se genera el hash con bcrypt coste 12.
2. Se inserta el usuario con una consulta preparada.
3. El primer usuario recibe rol `admin`; los demás, `usuario`.
4. Se registra el alta en la auditoría.
5. Se redirige al login con un aviso.

## 7. Sesiones

La configuración está en `iniciar_sesion()` dentro de
`includes/functions.php`:

| Parámetro | Valor | Motivo |
|---|---|---|
| `session.use_strict_mode` | `1` | Rechaza IDs de sesión inventados. |
| `cookie_httponly` | `1` | JavaScript no puede leer la cookie. |
| `cookie_samesite` | `Strict` | Reduce CSRF entre sitios. |
| `cookie_secure` | `COOKIE_SOLO_HTTPS` | Solo por HTTPS cuando está activo. |
| Expiración por inactividad | 20 minutos | Evita sesiones abandonadas. |
| Vida máxima | 8 horas | Limita el tiempo total de la sesión. |
| Regeneración | Al iniciar sesión | Evita fijación de sesión. |

Además, `dashboard.php` comprueba que el User-Agent no haya cambiado. Si cambia,
cierra la sesión y redirige al login.

## 8. Control de acceso (RBAC)

`includes/auth.php` expone dos funciones:

- `exigir_autenticacion()`: si no hay sesión, redirige al login.
- `exigir_rol('admin')`: si el rol no coincide, devuelve HTTP 403 y registra el
  intento en la auditoría.

Páginas protegidas:

| Página | Requisito |
|---|---|
| `dashboard.php` | Sesión iniciada. |
| `notas.php` | Sesión iniciada; cada usuario solo ve sus notas. |
| `admin.php` | Rol `admin`. |
| `informe_fase4.php` | Sesión iniciada. |

Aunque un usuario escriba la URL de `admin.php`, el servidor vuelve a comprobar
el rol. Ocultar el enlace en el menú no se considera un control suficiente.

### 8.4 Auditoría y exportación CSV

- `auditoria.php` (solo `admin`): 20 eventos por página con filtros por acción,
  usuario (incluye los eventos sin usuario) y rango de fechas inclusivo. La
  consulta vive en `listar_auditoria()` y se arma con sentencias preparadas.
- `auditoria_csv.php` exporta **todas** las filas del filtro (no solo la página)
  con `Content-Type: text/csv`, `Content-Disposition: attachment` y un nombre de
  archivo generado por el servidor (`auditoria-AAAAMMDD-HHMM.csv`).
- `campo_csv()` evita *CSV injection*: si una celda empieza con `=`, `+`, `-`,
  `@`, tab o CR se antepone un apóstrofo, y se entrecomilla según RFC 4180 cuando
  trae coma, comilla o salto de línea. El costo: un número negativo también
  queda con apóstrofo.
- La exportación se registra como `AUDITORIA_EXPORTADA`, así queda evidencia de
  quién bajó el log y con qué filtros.

## 9. Notas personales y anti-IDOR

`notas.php` permite crear y eliminar notas. Cada operación usa el ID de la
sesión, nunca un ID enviado por el usuario para decidir la propiedad:

```sql
-- Listar
SELECT ... FROM notas WHERE usuario_id = :uid ORDER BY id DESC;

-- Eliminar
DELETE FROM notas WHERE id = :id AND usuario_id = :uid;
```

Si un atacante cambia el `id` de la nota en el POST, la condición
`usuario_id = :uid` impide borrar notas ajenas. Esto mitiga IDOR (Insecure
Direct Object Reference).

El contenido se muestra con `e()`:

```php
function e(?string $valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

Por eso `<script>alert(1)</script>` se ve como texto y no se ejecuta.

## 10. CSRF

Cada formulario incluye un campo oculto con un token aleatorio de 32 bytes:

```php
csrf_campo(); // <input type="hidden" name="csrf_token" value="...">
```

En cada POST se llama a `csrf_verificar()`:

- Si el token no existe o no coincide con `hash_equals()`, responde HTTP 403.
- El intento se registra en la auditoría.
- El token es por sesión y no se reutiliza entre usuarios.

Formularios protegidos: login, registro, creación/eliminación de notas y logout.

## 11. Inyección SQL

Todas las consultas usan PDO con parámetros:

```php
$stmt = db()->prepare('SELECT ... WHERE username = :username LIMIT 1');
$stmt->execute([':username' => $username]);
```

La conexión configura:

```php
PDO::ATTR_EMULATE_PREPARES => false
```

Esto obliga a MySQL a usar consultas preparadas reales en el servidor. La entrada
del usuario se trata como dato, no como código SQL. Una carga como
`admin' OR '1'='1` no altera la consulta y el login falla.

## 12. XSS

Hay dos capas:

1. **Escape de salida:** todo dato dinámico se imprime con `e()`.
2. **Content-Security-Policy:** `script-src 'self'` bloquea scripts inline y de
   orígenes no autorizados.

El formulario de notas permite escribir HTML a propósito, para demostrar que el
contenido se muestra como texto y no se ejecuta.

## 13. Fuerza bruta

Flujo:

1. Cada intento se registra en `intentos_login` con usuario, IP, resultado,
   User-Agent y fecha.
2. Antes de autenticar, se cuentan los fallos de los últimos 15 minutos por
   usuario o IP.
3. Si el total es mayor o igual a 5, se bloquea y se registra
   `CUENTA_BLOQUEADA`.
4. Al iniciar sesión correctamente, se reinicia el contador del usuario.

Configuración en `config/config.php`:

```php
define('MAX_INTENTOS_FALLIDOS', 5);
define('MINUTOS_BLOQUEO', 15);
```

## 14. Auditoría

`logs_auditoria` registra, entre otros:

- `LOGIN_OK`, `LOGIN_FALLIDO`, `LOGOUT`, `SESION_EXPIRADA`.
- `CUENTA_BLOQUEADA`, `CSRF_BLOQUEADO`, `ACCESO_DENEGADO`.
- `REGISTRO`, `NOTA_CREADA`, `NOTA_ELIMINADA`.

La función `registrar_auditoria()` nunca interrumpe la aplicación si falla el
inserto: la auditoría es importante, pero no debe tumbar el sitio.

`admin.php` muestra los últimos 15 eventos con usuario, acción, detalle, IP y
fecha.

## 15. Cabeceras de seguridad

Se envían desde `includes/init.php` y se refuerzan en `.htaccess`:

| Cabecera | Valor | Protección |
|---|---|---|
| `X-Frame-Options` | `DENY` | Clickjacking. |
| `X-Content-Type-Options` | `nosniff` | MIME sniffing. |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Fuga de referencias. |
| `Permissions-Policy` | geolocation/microphone/camera off | APIs del navegador. |
| `Content-Security-Policy` | `default-src 'self'...` | XSS y recursos no autorizados. |
| `Strict-Transport-Security` | Comentada | Se activa bajo HTTPS. |

## 16. Protección de archivos y carpetas

`.htaccess`:

- Deshabilita el listado de directorios (`Options -Indexes`).
- Bloquea archivos `.sql`, `.md`, `.log`, `.ini` y `.json`.
- Redirige a 403 el acceso directo a `config/`, `includes/` y `database/`.
- Oculta la firma del servidor (`ServerSignature Off`).

Esto evita que se descarguen el esquema de la base de datos, la política o
archivos de configuración.

## 17. Base de datos

```text
usuarios
  id, nombre, email, username, password_hash, rol, activo,
  ultimo_login,
  creado_en, actualizado_en

notas
  id, usuario_id (FK), titulo, contenido, creado_en

intentos_login
  id, username, ip, exitoso, user_agent, fecha

logs_auditoria
  id, usuario_id (FK), accion, detalle, ip, fecha
```

Relaciones:

- `notas.usuario_id` → `usuarios.id` con `ON DELETE CASCADE`.
- `logs_auditoria.usuario_id` → `usuarios.id` con `ON DELETE SET NULL`.

## 18. Flujo de demostración recomendado

1. Abrir `login.php` y mostrar el formulario.
2. Enviar un POST sin token CSRF (por ejemplo con `curl`) y mostrar el 403.
3. Iniciar sesión como administrador.
4. Mostrar el panel con las medidas activas.
5. Crear una nota con `<script>alert(1)</script>` y comprobar que se muestra
   como texto.
6. Cerrar sesión e iniciar con un usuario normal.
7. Intentar abrir `admin.php` y mostrar el 403.
8. Volver a entrar como admin y mostrar usuarios y auditoría.
9. Abrir `informe_fase4.php` y explicar herramientas y hallazgos.
10. Mostrar `POLITICA_SEGURIDAD.md`.
11. En el servidor con HTTPS, abrir `https://` y mostrar candado, cookie
    `Secure` y HSTS.

## 19. Pruebas rápidas con curl

```bash
# Cabeceras del login
curl -I http://seguridad-web.test:88/login.php

# POST sin token CSRF (debe dar 403)
curl -i -d "username=x&password=y" http://seguridad-web.test:88/login.php

# Página inexistente
curl -I http://seguridad-web.test:88/setup.php

# Verificar que un archivo interno está bloqueado
curl -I http://seguridad-web.test:88/POLITICA_SEGURIDAD.md
```

Pruebas manuales:

- **SQLi:** usuario `admin' OR '1'='1` → acceso denegado.
- **XSS:** nota con `<script>alert(1)</script>` → texto escapado.
- **IDOR:** cambiar el `id` de una nota ajena → no se elimina.
- **Fuerza bruta:** 5 fallos → bloqueo temporal.
- **RBAC:** usuario normal en `admin.php` → 403.

## 20. Activar HTTPS

En el servidor con certificado:

1. Configurar el sitio en el puerto 443 con TLS 1.2 o superior.
2. Redirigir HTTP a HTTPS.
3. En `config/config.php`:
   ```php
   define('COOKIE_SOLO_HTTPS', true);
   ```
4. Descomentar HSTS en `includes/init.php` y `.htaccess`.
5. Verificar:
   - URL `https://`.
   - Candado del navegador.
   - Cookie `PHPSESSID` con atributo `Secure`.
   - Cabecera `Strict-Transport-Security`.

## 21. Limitaciones y mejoras futuras

El proyecto es académico. Limitaciones actuales:

- El registro público está abierto; en producción debe restringirse.
- No hay recuperación de contraseña ni 2FA.
- El usuario de MySQL configurado es `root`; en producción debe ser uno
  dedicado con privilegios mínimos.
- `MODO_DEBUG` está activo en desarrollo; en producción debe ser `false`.
- No hay rate limiting a nivel de servidor ni WAF.
- Las copias de seguridad están documentadas en la política, pero no
  automatizadas en el laboratorio.

Mejoras recomendadas:

- 2FA/TOTP para administradores.
- Recuperación de contraseña con token de un solo uso.
- Registro de cambios y revisión de dependencias.
- Rotación de logs y alertas.
- Escaneo ZAP/Nikto antes de cada entrega.

## 22. Solución de problemas

| Problema | Causa probable | Solución |
|---|---|---|
| `404` en `http://seguridad-web.test/` | IIS u otro servicio ocupa el puerto 80. | Usar `http://seguridad-web.test:88/` o liberar el puerto 80. |
| `No se pudo conectar con la base de datos` | MySQL apagado o credenciales incorrectas. | Iniciar MySQL y revisar `config/config.php`. |
| `setup.php` da 404 | Fue eliminado por seguridad. | Importar `database/schema.sql` y registrar el primer usuario. |
| Error 403 al enviar un formulario | Token CSRF ausente o vencido. | Recargar la página y volver a enviar. |
| La sesión expira rápido | Inactividad mayor a 20 minutos. | Volver a iniciar sesión. |
| HTTPS no responde | Certificado o vhost SSL no configurado. | Configurar el certificado y el puerto 443 en el servidor. |
| Estilos no cargan | Ruta incorrecta o CSP. | Verificar `assets/css/estilos.css` y la consola del navegador. |

## 23. Resumen de seguridad

| Amenaza | Control |
|---|---|
| Robo de contraseñas | bcrypt + HTTPS + cookies seguras. |
| Inyección SQL | PDO preparado sin emulación. |
| XSS | Escape de salida + CSP. |
| CSRF | Token por sesión + `hash_equals`. |
| Fijación de sesión | Regeneración de ID + modo estricto. |
| Fuerza bruta | Bloqueo por intentos + auditoría. |
| IDOR | Filtro por `usuario_id` de la sesión. |
| Escalada de privilegios | RBAC en servidor. |
| Exposición de archivos | `.htaccess` + carpetas internas bloqueadas. |
| Falta de trazabilidad | `logs_auditoria` e `intentos_login`. |
