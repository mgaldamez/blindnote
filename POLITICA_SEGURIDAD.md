# Política de Seguridad de la Información — BlindNote

| Campo | Valor |
|---|---|
| Código | PS-SW-007 |
| Versión | 1.0 (borrador para revisión) |
| Fecha | Octubre de 2026 |
| Universidad | Universidad Doctor Andrés Bello |
| Facultad / Carrera | Tecnología e Innovación — Ingeniería en Sistemas |
| Asignatura | Diseño de Seguridad de Redes |
| Proyecto | Implementación integral de medidas de seguridad informática en sitios web |
| Responsable | Equipo del proyecto (ver Anexo B) |
| Clasificación | Uso académico / interno |

---

## 1. Objetivo

Establecer las reglas, responsabilidades y controles mínimos para proteger la
información y la infraestructura del sitio **BlindNote**, de forma que se
garanticen su confidencialidad, integridad y disponibilidad. Esta política sirve
como borrador de la **Fase 5** del proyecto y complementa las medidas técnicas
ya implementadas en el código (autenticación, control de acceso, mitigación de
SQLi/XSS/CSRF, auditoría y cabeceras de seguridad).

## 2. Alcance

Aplica a:

- La aplicación web BlindNote (código PHP, base de datos MySQL/MariaDB,
  servidor web Apache/Nginx y certificados TLS).
- Los datos almacenados: usuarios, notas, intentos de inicio de sesión y
  registros de auditoría.
- Todo el personal que desarrolla, administra o utiliza el sitio, incluidos los
  integrantes del equipo, el docente y los usuarios de prueba.

Queda fuera del alcance la infraestructura de red de la universidad, que se rige
por las políticas institucionales correspondientes.

## 3. Definiciones

| Término | Significado |
|---|---|
| Activo | Recurso con valor: aplicación, base de datos, código, logs, certificados. |
| Usuario | Persona con una cuenta nominativa en el sitio. |
| Administrador | Usuario con rol `admin`, encargado de la gestión y supervisión. |
| Incidente | Evento que compromete o intenta comprometer la seguridad de la información. |
| Copia de seguridad | Respaldo íntegro y recuperable de datos y configuración. |
| RPO | Máxima pérdida de datos aceptable, expresada en tiempo. |
| RTO | Tiempo máximo aceptable para restablecer el servicio. |
| MFA/2FA | Autenticación multifactor o de dos factores. |

## 4. Principios rectores

1. **Privilegio mínimo:** cada cuenta tiene solo los permisos necesarios.
2. **Cuentas nominativas:** prohibido compartir usuarios o contraseñas.
3. **Defensa en profundidad:** controles técnicos, organizativos y de monitoreo.
4. **Seguridad desde el diseño:** validación, cifrado y auditoría en cada cambio.
5. **Trazabilidad:** toda acción sensible debe quedar registrada.
6. **Mejora continua:** revisión periódica y aprendizaje tras cada incidente.

## 5. Roles y responsabilidades

| Rol | Responsabilidades |
|---|---|
| Docente / patrocinador | Aprobar la política, asignar recursos y validar el avance académico. |
| Responsable de seguridad | Mantener y revisar la política, supervisar logs, coordinar incidentes y autorizar accesos. |
| Administrador del sistema | Configurar servidor, HTTPS, base de datos, copias de seguridad y parches. |
| Desarrollador | Escribir código seguro, revisar dependencias, ejecutar pruebas y corregir vulnerabilidades. |
| Usuario | Cumplir la política, proteger sus credenciales y reportar anomalías. |

Toda persona con acceso debe conocer esta política antes de usar el sistema.

## 6. Gestión de usuarios y control de acceso

### 6.1 Alta de usuarios

- Las cuentas se crean desde `register.php` o por el administrador.
- El **primer usuario registrado** obtiene el rol `admin`; los siguientes
  obtienen el rol `usuario`.
- En producción, el registro público debe restringirse o deshabilitarse una vez
  creadas las cuentas necesarias.
- No se permiten cuentas genéricas, compartidas ni de prueba con contraseñas
  conocidas.

### 6.2 Modificación y bajas

- Solo un administrador puede cambiar roles o deshabilitar cuentas.
- El cambio de rol debe registrarse en `logs_auditoria`.
- Al dar de baja a una persona se debe: deshabilitar la cuenta (`activo = 0`),
  invalidar sus sesiones, cambiar contraseñas compartidas si existieran y
  conservar los logs según el periodo de retención.
- Las cuentas inactivas durante **90 días** deben deshabilitarse.

### 6.3 Revisión de accesos

- Revisión trimestral de la lista de usuarios y sus roles.
- Revisión inmediata tras cualquier incidente o cambio de integrantes.

### 6.4 Roles del sistema

| Rol | Permisos |
|---|---|
| `admin` | Acceso a `admin.php`, listado de usuarios, auditoría y funciones de administración. |
| `usuario` | Acceso a su panel y a sus propias notas. No puede ver ni borrar datos de otros. |

La verificación se realiza en el servidor mediante `exigir_autenticacion()` y
`exigir_rol()`. Ocultar un enlace en la interfaz no se considera un control de
seguridad.

### 6.5 Sesiones

- Expiración por inactividad: **20 minutos**.
- Vida máxima de la sesión autenticada: **8 horas**.
- Regeneración del identificador de sesión al iniciar sesión.
- Cookies `HttpOnly` y `SameSite=Strict`; `Secure` obligatorio bajo HTTPS.
- Cierre de sesión mediante `POST` con token CSRF.
- Prohibido enviar el identificador de sesión por URL o mensajería.

## 7. Gestión de contraseñas

### 7.1 Requisitos mínimos

- Longitud mínima: **8 caracteres** (se recomienda 12 o más).
- Debe incluir al menos una mayúscula, una minúscula y un número.
- No debe contener el nombre de usuario, el correo ni datos obvios del proyecto.
- No se permite reutilizar contraseñas de otros servicios.
- Se recomienda el uso de un gestor de contraseñas.

### 7.2 Almacenamiento y verificación

- Las contraseñas se almacenan **únicamente** como hash **bcrypt** con coste 12
  mediante `password_hash()`; se verifican con `password_verify()`.
- Prohibido almacenar contraseñas en texto plano, cifrado reversible, archivos
  de configuración, repositorios o capturas de pantalla.
- Prohibido registrar contraseñas, tokens CSRF o datos sensibles en logs.
- Si cambia el algoritmo o el coste, se aplica rehash automático al iniciar
  sesión.

### 7.3 Credenciales iniciales y de demostración

- No se distribuyen credenciales por defecto.
- Cualquier contraseña de demostración debe cambiarse antes de la presentación
  o del despliegue.
- Las credenciales se entregan por un canal seguro y nunca por correo masivo ni
  chat grupal.

### 7.4 Protección contra fuerza bruta

- Bloqueo temporal tras **5 intentos fallidos** durante **15 minutos** por
  usuario e IP.
- Mensajes de error genéricos para no revelar si la cuenta existe.
- Registro de cada intento en `intentos_login` y de cada bloqueo en
  `logs_auditoria`.
- Se recomienda **2FA** para el rol administrador.

### 7.5 Recuperación de contraseña

- Si se implementa, el enlace o token debe ser de un solo uso, aleatorio y con
  vigencia máxima de 30 minutos.
- No se debe revelar si un correo está registrado.
- El restablecimiento debe quedar auditado.

## 8. Copias de seguridad y recuperación

### 8.1 Alcance

- Base de datos `seguridad_web` (usuarios, notas, logs).
- Código fuente y configuración de la aplicación.
- Certificados TLS y archivos de configuración del servidor.
- Los secretos (credenciales de base de datos) se resguardan por separado y
  cifrados; no se incluyen en repositorios.

### 8.2 Frecuencia y retención

| Tipo | Frecuencia | Retención |
|---|---|---|
| Completa | Semanal | 8 semanas |
| Diferencial / incremental | Diaria | 30 días |
| Antes de cambios mayores | Puntual | Hasta validar el cambio |
| Copia mensual | Mensual | 6 meses |

- Se aplica la regla **3-2-1**: tres copias, en dos medios distintos y una fuera
  del sitio principal.
- RPO objetivo: **24 horas**. RTO objetivo: **4 horas**.

### 8.3 Protección

- Copias cifradas en reposo y en tránsito.
- Acceso restringido al responsable de backups y al administrador.
- Prohibido almacenar copias dentro de la carpeta pública del sitio
  (`public_html`, `www` o similar).
- Verificar la integridad de cada copia y registrar el resultado.

### 8.4 Restauración y pruebas

- Prueba de restauración al menos **una vez al mes** en un entorno aislado.
- Documentar fecha, responsable, resultado y tiempo de restauración.
- Una copia que no se ha probado no se considera una copia válida.

Ejemplo de referencia (sin escribir la contraseña en la línea de comandos):

```bash
mysqldump --defaults-extra-file=/ruta/mysql-client.cnf \
  --single-transaction --routines --triggers seguridad_web \
  > /ruta/segura/seguridad_web_$(date +%F).sql
```

## 9. Registro, monitoreo y auditoría

- Se registran como mínimo: inicios de sesión exitosos y fallidos, bloqueos,
  cierres de sesión, registros de usuarios, creación/eliminación de notas,
  accesos denegados y errores CSRF.
- Los logs se almacenan en `logs_auditoria` e `intentos_login`.
- Retención mínima: **90 días** en laboratorio y **1 año** en producción, salvo
  obligación legal distinta.
- Los logs deben protegerse contra lectura, modificación y borrado no
  autorizados; solo el administrador y el responsable de seguridad acceden.
- Revisión semanal de eventos y alertas ante: 5 intentos fallidos, múltiples
  respuestas 403, cambios de rol, fallos de backup o errores repetidos.
- Sincronizar la hora del servidor para que los registros sean consistentes.

## 10. Respuesta ante incidentes

### 10.1 Clasificación

| Severidad | Ejemplo | Tiempo de contención objetivo |
|---|---|---|
| Crítica | Acceso no autorizado a la base de datos o fuga de datos. | 2 horas |
| Alta | Cuenta de administrador comprometida, defacement, ransomware. | 4 horas |
| Media | Fuerza bruta sostenida, intento de SQLi/XSS bloqueado. | 24 horas |
| Baja | Error de configuración sin exposición de datos. | 5 días |

### 10.2 Fases

1. **Preparación:** roles, contactos, copias y procedimientos probados.
2. **Identificación:** detectar y confirmar el incidente; registrar hora y
   evidencias.
3. **Contención:** aislar el servicio o la cuenta afectada sin destruir
   evidencias.
4. **Erradicación:** eliminar la causa (parche, cambio de credenciales, regla de
   firewall).
5. **Recuperación:** restaurar desde copias verificadas y validar el servicio.
6. **Lecciones aprendidas:** informe posterior y acciones correctivas.

### 10.3 Procedimientos mínimos

- **Fuerza bruta:** bloquear usuario/IP, revisar `intentos_login`, cambiar la
  contraseña afectada y reforzar 2FA.
- **Cuenta comprometida:** deshabilitar la cuenta, invalidar sesiones, cambiar
  credenciales y revisar acciones realizadas.
- **Intento de SQLi/XSS/CSRF:** conservar logs, confirmar el bloqueo, revisar el
  código y repetir el escaneo con OWASP ZAP/Nikto.
- **Fuga de datos:** preservar evidencias, evaluar alcance, notificar al docente
  y a los afectados, y documentar las medidas.
- **Fallo de backup o ransomware:** aislar, no pagar rescate, restaurar desde una
  copia limpia y verificar la integridad.
- **Pérdida de dispositivo o credenciales:** revocar accesos y rotar secretos.

### 10.4 Evidencias y comunicación

- No borrar logs ni archivos implicados.
- Guardar capturas, fechas, direcciones IP y acciones realizadas.
- Mantener una cadena de custodia básica de las evidencias.
- Designar un único portavoz; no especular ni publicar detalles sin validación.
- Notificar al docente responsable en un plazo máximo de **24 horas** para
  incidentes altos o críticos.

### 10.5 Cierre

- Informe posterior en un plazo de **5 días hábiles**.
- Incluir causa raíz, impacto, acciones correctivas, responsables y fechas.
- Actualizar esta política y los controles técnicos si corresponde.

## 11. Desarrollo seguro y gestión de cambios

- Revisar el código antes de integrar cambios, especialmente consultas SQL,
  salidas HTML y formularios.
- Usar consultas preparadas y escapar toda salida.
- Validar entradas en el servidor, no solo en el navegador.
- Mantener actualizados PHP, Apache/Nginx, MySQL/MariaDB y dependencias.
- Ejecutar OWASP ZAP o Nikto antes de cada entrega o despliegue.
- En producción: `MODO_DEBUG = false`, eliminar instaladores (`setup.php`),
  deshabilitar listado de directorios y restringir el registro público.
- No subir secretos ni credenciales al repositorio; usar variables de entorno o
  archivos fuera del código.
- Registrar cada cambio relevante con fecha, autor y motivo.

## 12. Configuración segura del servidor y HTTPS

- Usar HTTPS con certificado válido (Let's Encrypt o, en laboratorio,
  autofirmado) y TLS 1.2 o superior.
- Redirigir todo el tráfico HTTP a HTTPS.
- Activar HSTS solo después de verificar que HTTPS funciona correctamente.
- Con HTTPS activo, establecer `COOKIE_SOLO_HTTPS = true` en
  `config/config.php` y descomentar la cabecera HSTS en `includes/init.php` y
  `.htaccess`.
- Deshabilitar métodos HTTP innecesarios (TRACE/TRACK) y ocultar la versión del
  servidor.
- Usar un usuario de base de datos dedicado con privilegios mínimos; no usar
  `root` en producción.
- Restringir el acceso administrativo por red o VPN cuando sea posible.
- Mantener firewall activo y solo los puertos necesarios expuestos.

> Nota: la demostración de HTTPS puede realizarse en un servidor distinto al de
> desarrollo. En ese caso, la aplicación ya está preparada para activar cookies
> `Secure` y HSTS mediante configuración, sin cambios de código.

## 13. Uso aceptable y protección de datos

- El sitio es académico: no debe almacenar datos personales reales de terceros.
- Los usuarios no deben introducir información sensible en las notas.
- Los datos se usan únicamente para la demostración y evaluación del proyecto.
- Se debe atender cualquier solicitud de eliminación de datos de prueba.
- No se permite usar el sitio para actividades ilícitas, spam o ataques.

## 14. Incumplimiento

El incumplimiento de esta política puede dar lugar a:

1. Llamado de atención y refuerzo de capacitación.
2. Revocación temporal o definitiva de accesos.
3. Reporte al docente y aplicación de las normas académicas correspondientes.
4. Acciones disciplinarias o legales según la gravedad.

## 15. Revisión y vigencia

- Revisión ordinaria cada **6 meses**.
- Revisión extraordinaria tras un incidente grave o un cambio mayor.
- El responsable de seguridad mantiene el historial de versiones.
- La política entra en vigencia al ser aprobada por el docente.

## Anexo A. Lista de verificación rápida

- [ ] Cuentas nominativas y con privilegio mínimo.
- [ ] Contraseñas con bcrypt y sin texto plano en el repositorio.
- [ ] Registro público restringido en producción.
- [ ] Bloqueo por fuerza bruta y mensajes genéricos.
- [ ] Sesiones con expiración, regeneración y cookies seguras.
- [ ] HTTPS activo, redirección desde HTTP y HSTS.
- [ ] Copias de seguridad cifradas, fuera del sitio y probadas.
- [ ] Logs de auditoría protegidos y revisados.
- [ ] Procedimiento de incidentes conocido por el equipo.
- [ ] `setup.php` e instaladores eliminados.
- [ ] `MODO_DEBUG = false` en producción.
- [ ] Escaneo ZAP/Nikto realizado y documentado.

## Anexo B. Contactos y responsables

| Rol | Nombre | Correo / teléfono |
|---|---|---|
| Docente | ____________________ | ____________________ |
| Responsable de seguridad | ____________________ | ____________________ |
| Administrador del sistema | ____________________ | ____________________ |
| Integrante 1 | ____________________ | ____________________ |
| Integrante 2 | ____________________ | ____________________ |
| Integrante 3 | ____________________ | ____________________ |
| Integrante 4 | ____________________ | ____________________ |

## Anexo C. Referencias

- OWASP Top 10 y OWASP ASVS.
- OWASP ZAP y Nikto (pruebas de seguridad).
- NIST SP 800-61 (respuesta a incidentes).
- ISO/IEC 27001 (gestión de seguridad de la información).
- Buenas prácticas de copias de seguridad 3-2-1.

## Anexo D. Historial de versiones y aprobación

| Versión | Fecha | Autor | Cambio |
|---|---|---|---|
| 1.0 | Octubre de 2026 | Equipo del proyecto | Borrador inicial. |
|  |  |  |  |
|  |  |  |  |

| Aprobación | Nombre | Firma | Fecha |
|---|---|---|---|
| Docente | ____________________ | ______________ | __________ |
| Responsable de seguridad | ____________________ | ______________ | __________ |
