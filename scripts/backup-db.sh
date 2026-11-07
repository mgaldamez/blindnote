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

# Credenciales de la base de datos (sobrescribibles por variables de entorno).
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-seguridad_web}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-root}"

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
        log "INFO" "Modo Docker: contenedor '${DB_CONTAINER}'"
        docker exec -e MYSQL_PWD="$DB_PASS" "$DB_CONTAINER" \
            mysqldump -h127.0.0.1 -P"$DB_PORT" -u"$DB_USER" \
            "${MYSQLDUMP_OPTS[@]}" "$DB_NAME"
    else
        log "INFO" "Modo directo: ${DB_HOST}:${DB_PORT}"
        # Archivo de opciones temporal para evitar pasar la contraseña por CLI.
        local tmp_opts
        tmp_opts="$(mktemp)"
        chmod 600 "$tmp_opts"
        {
            printf '[client]\n'
            printf 'host=%s\n' "$DB_HOST"
            printf 'port=%s\n' "$DB_PORT"
            printf 'user=%s\n' "$DB_USER"
            printf 'password=%s\n' "$DB_PASS"
        } > "$tmp_opts"
        trap 'rm -f "$tmp_opts"' EXIT
        mysqldump --defaults-extra-file="$tmp_opts" "${MYSQLDUMP_OPTS[@]}" "$DB_NAME"
    fi
}

# ============================ EJECUCIÓN ====================================

main() {
    mkdir -p "$BACKUP_DIR"

    # Resolver contenedor si está en modo Docker y no se indicó uno explícito.
    if [[ -z "${DB_CONTAINER:-}" ]] && [[ "${USE_DOCKER:-0}" == "1" ]]; then
        DB_CONTAINER="$(detectar_contenedor_db)"
        if [[ -z "$DB_CONTAINER" ]]; then
            error_exit "USE_DOCKER=1 pero no se detectó ningún contenedor MySQL/MariaDB en ejecución."
        fi
        log "INFO" "Contenedor detectado automáticamente: '${DB_CONTAINER}'"
    fi

    local fecha
    fecha="$(date '+%Y%m%d_%H%M%S')"
    local archivo_base="$BACKUP_DIR/${DB_NAME}_${fecha}"
    local archivo_gz="${archivo_base}.sql.gz"

    log "INFO" "Iniciando backup de la base de datos '$DB_NAME' (proyecto: $PROJECT_ROOT)"

    if ! volcar_base_de_datos | gzip > "$archivo_gz"; then
        error_exit "El volcado de la base de datos '$DB_NAME' falló"
    fi

    if [[ ! -s "$archivo_gz" ]]; then
        error_exit "El backup generado está vacío: $archivo_gz"
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
