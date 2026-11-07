#!/usr/bin/env bash
#
# backup-db.sh - Backup completo de la base de datos MySQL/MariaDB
#
# Genera un volcado completo (estructura + datos), lo comprime con gzip y lo
# guarda con marca de tiempo. Incluye rotación automática de copias antiguas.
#
# Soporta dos modos de conexión:
#  1) Directo a MySQL en host:puerto (cuando el puerto está expuesto).
#  2) Vía Docker (docker exec) contra el contenedor de la base de datos, que es
#     el caso típico cuando la DB corre en Compose y su puerto no está expuesto.
#
# Uso manual:
#   ./scripts/backup-db.sh
#
# Crontab (todos los días a las 00:01), en el VPS:
#   1 0 * * * USE_DOCKER=1 /opt/aplicaciones/seguridad-web/scripts/backup-db.sh
#
set -euo pipefail

# ============================ RUTAS ========================================

# Raíz del proyecto. Por defecto es la ruta del VPS; si el script se ejecuta
# desde otra ubicación (por ejemplo un clon local), se puede sobrescribir con
# la variable de entorno PROJECT_ROOT.
PROJECT_ROOT="${PROJECT_ROOT:-/opt/aplicaciones/seguridad-web}"

# Si PROJECT_ROOT no existe, deducirla desde la ubicación del propio script
# (scripts/backup-db.sh -> raíz del proyecto). Así el script es portable.
if [[ ! -d "$PROJECT_ROOT" ]]; then
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    PROJECT_ROOT="$(cd "$script_dir/.." && pwd)"
fi

# ============================ CONFIGURACIÓN ================================

# Modo de conexión: USE_DOCKER=1 usa `docker exec` contra el contenedor.
USE_DOCKER="${USE_DOCKER:-0}"

# Archivo .env del proyecto. En modo directo de ahí salen las credenciales.
ENV_FILE="${ENV_FILE:-$PROJECT_ROOT/.env}"

# Credenciales de la base de datos. NO llevan valor por defecto aquí: si lo
# llevaran, el valor del .env nunca se aplicaría, porque `DB_USER:-root` ya
# estaría definido. Los defaults reales se resuelven en main(), después de
# leer el .env.
# En modo Docker no se usan: la contraseña la resuelve el propio contenedor.
DB_HOST="${DB_HOST:-}"
DB_PORT="${DB_PORT:-}"
DB_NAME="${DB_NAME:-}"
DB_USER="${DB_USER:-}"
DB_PASS="${DB_PASS:-}"

# Directorio donde se guardan los backups.
BACKUP_DIR="${BACKUP_DIR:-$PROJECT_ROOT/backups}"

# Archivo de log.
LOG_DIR="${LOG_DIR:-$PROJECT_ROOT/scripts/logs}"
LOG_FILE="${LOG_FILE:-$LOG_DIR/backup-db.log}"

# Días de retención: se borran backups más antiguos que este valor.
RETENTION_DAYS="${RETENTION_DAYS:-7}"

# Contenedor Docker de la base de datos (vacío = conexión directa).
# Si se deja vacío, el script intenta detectar automáticamente un contenedor
# MySQL/MariaDB en ejecución.
DB_CONTAINER="${DB_CONTAINER:-}"

# Opciones de mysqldump. --single-transaction es seguro para tablas InnoDB
# (no bloquea tablas ni interrumpe lecturas/escrituras en producción).
MYSQLDUMP_OPTS=(
    --single-transaction
    --routines
    --triggers
    --events
    --quick
    --no-tablespaces
    --default-character-set=utf8mb4
)

# ============================ FUNCIONES ====================================

log() {
    local nivel="$1"; shift
    local msg="$*"
    local ts
    ts="$(date '+%Y-%m-%d %H:%M:%S')"
    mkdir -p "$LOG_DIR"
    printf '[%s] [%s] %s\n' "$ts" "$nivel" "$msg" | tee -a "$LOG_FILE" >/dev/null
}

error_exit() {
    log "ERROR" "$*"
    exit 1
}

# Lee una clave del .env y la imprime sin comillas. Nunca falla: si la clave
# no existe (o el archivo tampoco) imprime una cadena vacía.
# Se parsea el archivo en vez de hacer `source`: el .env es datos de
# configuración, no código, y no debe ejecutarse como shell.
leer_env() {
    local clave="$1" linea valor=""
    [[ -f "$ENV_FILE" ]] || return 0
    linea="$(grep -E "^[[:space:]]*${clave}[[:space:]]*=" "$ENV_FILE" | tail -n1 || true)"
    [[ -n "$linea" ]] || return 0

    # Recorta espacios de los extremos del valor.
    valor="$(printf '%s' "${linea#*=}" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"

    case "$valor" in
        \"*\"|\'*\')
            # Entre comillas: se respeta todo el contenido, incluso un #.
            valor="${valor#?}"
            valor="${valor%?}"
            ;;
        *)
            # Sin comillas: un " #" inicia un comentario en línea.
            valor="$(printf '%s' "$valor" \
                | sed -e 's/[[:space:]]#.*$//' -e 's/[[:space:]]*$//')"
            ;;
    esac

    printf '%s' "$valor"
}

# Detecta automáticamente un contenedor MySQL/MariaDB en ejecución.
detectar_contenedor_db() {
    local nombre
    nombre="$(docker ps --format '{{.Names}}' 2>/dev/null \
        | grep -iE 'mysql|mariadb|-db-|_db$|database' | head -n1)"
    printf '%s' "$nombre"
}

# Ejecuta mysqldump y devuelve el volcado en stdout.
# Modo docker: usa MYSQL_PWD (variable de entorno del contenedor) para no
# exponer la contraseña en la línea de comandos.
volcar_base_de_datos() {
    if [[ -n "${DB_CONTAINER:-}" ]]; then
        log "INFO" "Modo Docker: contenedor '${DB_CONTAINER}' (credenciales del contenedor)"
        # La contraseña se resuelve DENTRO del contenedor con sus propias
        # variables (MYSQL_ROOT_PASSWORD / MYSQL_PASSWORD), así nunca viaja
        # por el host ni aparece en argumentos.
        # Los parámetros de mysqldump se pasan como argv ("$@") para que un
        # nombre de base con caracteres especiales no se interprete como shell.
        docker exec -e BACKUP_USER="$DB_USER" "$DB_CONTAINER" sh -c '
            case "$BACKUP_USER" in
                root) MYSQL_PWD="${MYSQL_ROOT_PASSWORD:-}" ;;
                *)    MYSQL_PWD="${MYSQL_PASSWORD:-}" ;;
            esac
            export MYSQL_PWD
            exec mysqldump "$@"
        ' sh -h127.0.0.1 -P"$DB_PORT" -u"$DB_USER" \
            "${MYSQLDUMP_OPTS[@]}" "$DB_NAME"
    else
        log "INFO" "Modo directo: ${DB_HOST}:${DB_PORT}"
        # Archivo de opciones temporal (0600) para evitar pasar la contraseña
        # por línea de comandos.
        local tmp_opts salida=0
        tmp_opts="$(mktemp)"
        chmod 600 "$tmp_opts"
        {
            printf '[client]\n'
            printf 'host=%s\n' "$DB_HOST"
            printf 'port=%s\n' "$DB_PORT"
            printf 'user=%s\n' "$DB_USER"
            printf 'password=%s\n' "$DB_PASS"
        } > "$tmp_opts"
        mysqldump --defaults-extra-file="$tmp_opts" \
            "${MYSQLDUMP_OPTS[@]}" "$DB_NAME" || salida=$?
        rm -f "$tmp_opts"
        return "$salida"
    fi
}

# ============================ EJECUCIÓN ====================================

main() {
    # Resolver contenedor si está en modo Docker y no se indicó uno explícito.
    if [[ "$USE_DOCKER" == "1" ]] && [[ -z "${DB_CONTAINER:-}" ]]; then
        DB_CONTAINER="$(detectar_contenedor_db)"
        if [[ -z "$DB_CONTAINER" ]]; then
            error_exit "USE_DOCKER=1 pero no se detectó ningún contenedor MySQL/MariaDB en ejecución."
        fi
        log "INFO" "Contenedor detectado automáticamente: '${DB_CONTAINER}'"
    fi

    # En modo directo, el .env es la fuente de configuración. Las variables ya
    # presentes en el entorno tienen prioridad y no se pisan.
    if [[ -z "${DB_CONTAINER:-}" ]] && [[ -f "$ENV_FILE" ]]; then
        DB_HOST="${DB_HOST:-$(leer_env DB_HOST)}"
        DB_PORT="${DB_PORT:-$(leer_env DB_PORT)}"
        DB_NAME="${DB_NAME:-$(leer_env DB_NOMBRE)}"
        DB_USER="${DB_USER:-$(leer_env DB_USUARIO)}"
        DB_PASS="${DB_PASS:-$(leer_env DB_CLAVE)}"
        DB_PASS="${DB_PASS:-$(leer_env MYSQL_ROOT_PASSWORD)}"
        log "INFO" "Configuración leída de ${ENV_FILE}"
    fi

    # Valores por defecto, aplicados solo si ni el entorno ni el .env los dieron.
    DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_PORT="${DB_PORT:-3306}"
    DB_NAME="${DB_NAME:-seguridad_web}"
    DB_USER="${DB_USER:-root}"
    DB_PASS="${DB_PASS:-root}"

    mkdir -p "$BACKUP_DIR"

    local fecha
    fecha="$(date '+%Y%m%d_%H%M%S')"
    local archivo_base="$BACKUP_DIR/${DB_NAME}_${fecha}"
    local archivo_gz="${archivo_base}.sql.gz"

    log "INFO" "Iniciando backup de la base de datos '$DB_NAME' (proyecto: $PROJECT_ROOT)"

    if ! volcar_base_de_datos | gzip > "$archivo_gz"; then
        rm -f "$archivo_gz"
        error_exit "El volcado de la base de datos '$DB_NAME' falló"
    fi

    # Un .sql.gz vacío, o solo con cabecera gzip, es un backup inútil.
    if [[ ! -s "$archivo_gz" ]] || [[ "$(gzip -dc "$archivo_gz" 2>/dev/null | wc -c)" -lt 1024 ]]; then
        rm -f "$archivo_gz"
        error_exit "El backup generado está vacío o es demasiado chico para ser válido: $archivo_gz"
    fi

    local tam
    tam="$(du -h "$archivo_gz" | awk '{print $1}')"
    log "INFO" "Backup completado: $archivo_gz ($tam)"

    # Rotación: elimina backups más antiguos que RETENTION_DAYS.
    local eliminados
    eliminados="$(find "$BACKUP_DIR" -maxdepth 1 -type f -name "${DB_NAME}_*.sql.gz" -mtime +"$RETENTION_DAYS" -delete -print | wc -l)"
    if [[ "$eliminados" -gt 0 ]]; then
        log "INFO" "Rotación: se eliminaron $eliminados backup(s) con más de $RETENTION_DAYS día(s)"
    fi

    log "INFO" "Backup finalizado correctamente"
}

main "$@"
