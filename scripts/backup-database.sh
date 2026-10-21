#!/usr/bin/env bash
# =============================================================================
# Backup database Agung ATK (MySQL/MariaDB) -> file .sql.gz siap restore.
# Pemakaian:
#   ./scripts/backup-database.sh                 # baca kredensial dari .env
#   ./scripts/backup-database.sh /path/backup    # folder tujuan (opsional)
# Kembalikan (restore):
#   gunzip -c backup_20261017_070000.sql.gz | mysql -u USER -p DATABASE
# =============================================================================
set -euo pipefail

cd "$(dirname "$0")/.."

ENV_FILE=".env"
[ -f "$ENV_FILE" ] || { echo "ERROR: .env tidak ditemukan di $(pwd)"; exit 1; }

# Baca nilai dari .env tanpa menampilkan isinya
env_val() { grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true; }

DB_HOST="${DB_HOST:-$(env_val DB_HOST)}"
DB_PORT="${DB_PORT:-$(env_val DB_PORT)}"
DB_DATABASE="${DB_DATABASE:-$(env_val DB_DATABASE)}"
DB_USERNAME="${DB_USERNAME:-$(env_val DB_USERNAME)}"
DB_PASSWORD="${DB_PASSWORD:-$(env_val DB_PASSWORD)}"
DB_CONNECTION="${DB_CONNECTION:-$(env_val DB_CONNECTION)}"

if [ "$DB_CONNECTION" = "sqlite" ]; then
    # SQLite: cukup salin file database
    DB_PATH="${DB_DATABASE:-database/database.sqlite}"
    [ -f "$DB_PATH" ] || { echo "ERROR: file SQLite $DB_PATH tidak ditemukan"; exit 1; }
    OUT_DIR="${1:-storage/backups}"
    mkdir -p "$OUT_DIR"
    OUT="$OUT_DIR/sqlite_$(date +%Y%m%d_%H%M%S).sqlite"
    cp "$DB_PATH" "$OUT"
    echo "Backup SQLite selesai: $OUT"
    exit 0
fi

[ -n "$DB_DATABASE" ] || { echo "ERROR: DB_DATABASE belum diisi di .env"; exit 1; }

OUT_DIR="${1:-storage/backups}"
mkdir -p "$OUT_DIR"
OUT="$OUT_DIR/agungatk_$(date +%Y%m%d_%H%M%S).sql.gz"

export MYSQL_PWD="$DB_PASSWORD"
mysqldump --single-transaction --routines --triggers \
    -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" -u "$DB_USERNAME" \
    "$DB_DATABASE" | gzip > "$OUT"

echo "Backup database selesai: $OUT ($(du -h "$OUT" | cut -f1))"
echo "Catatan: simpan file ini di lokasi TERPISAH dari server (off-site/cloud)."
