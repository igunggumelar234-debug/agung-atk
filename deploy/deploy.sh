#!/usr/bin/env bash
# =============================================================================
# Deploy produksi Agung ATK — dijalankan DI VPS (manual atau via SSH/CI)
# =============================================================================
# Pemakaian:
#   sudo -u deploy ./deploy/deploy.sh [/var/www/agung-atk] [branch]
#
# Langkah (gagal cepat bila salah satu gagal — set -e):
#   1. Ambil kode terbaru (bila folder adalah clone git; dilewati bila CI
#      mengirim berkas lewat rsync tanpa .git)
#   2. composer install --no-dev
#   3. php artisan migrate --force
#   4. optimize + storage:link
#   5. Hak akses storage/ & bootstrap/cache untuk user PHP-FPM
#   6. Nyalakan ulang worker & scheduler (bila unit systemd terpasang)
#
# Kredensial/rahasia TIDAK pernah disentuh skrip ini — .env sudah berada
# di server dan tidak ikut diperbarui oleh deploy.
# =============================================================================
set -euo pipefail

APP_DIR="${1:-/var/www/agung-atk}"
BRANCH="${2:-main}"
PHP_USER="${DEPLOY_PHP_USER:-www-data}"
UNIT_WORKER="agung-atk-worker"
UNIT_SCHEDULER="agung-atk-scheduler"

cd "${APP_DIR}"

echo "== 1/6 Kode terbaru (${BRANCH}) =="
if [ -d .git ]; then
    git fetch origin "${BRANCH}"
    git checkout "${BRANCH}"
    git pull --ff-only origin "${BRANCH}"
else
    echo "   Bukan clone git (arsip dari CI) — kode dianggap terbaru."
fi

echo "== 2/6 Dependensi PHP =="
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

echo "== 3/6 Migrasi database =="
php artisan migrate --force

echo "== 4/6 Optimasi =="
php artisan optimize:clear
php artisan optimize
php artisan storage:link >/dev/null 2>&1 || true

echo "== 5/6 Hak akses penyimpanan (user PHP-FPM) =="
chown -R "${PHP_USER}:${PHP_USER}" storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

echo "== 6/6 Layanan =="
if systemctl list-unit-files "${UNIT_WORKER}.service" >/dev/null 2>&1 \
    && systemctl is-enabled "${UNIT_WORKER}" >/dev/null 2>&1; then
    systemctl restart "${UNIT_WORKER}" "${UNIT_SCHEDULER}"
    echo "   Worker & scheduler dijalankan ulang."
else
    echo "   Unit systemd belum terpasang — jalankan manual:"
    echo "   php artisan queue:work --tries=3 --sleep=3"
fi

echo "Deploy SELESAI: ${APP_DIR} (${BRANCH})"
