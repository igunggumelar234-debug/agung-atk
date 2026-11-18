#!/usr/bin/env bash
# =============================================================================
# Provisioning VPS Ubuntu 22.04/24.04 — Agung ATK
# =============================================================================
# Memasang: paket dasar, PHP 8.3 + ekstensi, Nginx, MySQL, Composer,
# certbot, firewall (ufw), user deploy, dan folder aplikasi.
# IDEMPOTENT: aman dijalankan ulang; TIDAK menghapus/menimpa data yang
# sudah ada (database & site yang sudah terpasang dibiarkan).
#
# Pemakaian (sebagai root di VPS):
#   chmod +x deploy/setup-vps.sh
#   sudo ./deploy/setup-vps.sh [nama_database] [user_database]
#   -> mencatat password user database SEKALI di layar (simpan aman).
# =============================================================================
set -euo pipefail

DB_NAME="${1:-agung_atk}"
DB_USER="${2:-agung_atk}"
APP_DIR="/var/www/agung-atk"
NGINX_TEMPLATE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/nginx/agung-atk.conf"

export DEBIAN_FRONTEND=noninteractive

echo "== 1/7 Paket dasar & repositori PHP =="
apt-get update -y
apt-get install -y software-properties-common ca-certificates curl unzip git ufw openssl
add-apt-repository -y ppa:ondrej/php || true
apt-get update -y

echo "== 2/7 PHP 8.3, Nginx, MySQL, Composer, certbot =="
apt-get install -y nginx \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-xml php8.3-mbstring \
    php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl \
    mysql-server composer certbot python3-certbot-nginx

echo "== 3/7 Firewall (ufw) =="
ufw --force enable
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp

echo "== 4/7 Database MySQL =="
systemctl enable --now mysql
DB_PASS="$(openssl rand -base64 30 | tr -dc 'A-Za-z0-9' | head -c 24)"
mysql -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
echo "   Database : ${DB_NAME}"
echo "   User     : ${DB_USER}"
echo "   Password : ${DB_PASS}   <-- CATAT SEKARANG (tidak ditampilkan lagi)"

echo "== 5/7 User deploy & folder aplikasi =="
id -u deploy >/dev/null 2>&1 || useradd -m -s /bin/bash deploy
mkdir -p "${APP_DIR}"
chown -R deploy:deploy "${APP_DIR}"

echo "== 6/7 Nginx =="
if [ ! -e /etc/nginx/sites-enabled/agung-atk ]; then
    cp "${NGINX_TEMPLATE}" /etc/nginx/sites-available/agung-atk
    ln -sf /etc/nginx/sites-available/agung-atk /etc/nginx/sites-enabled/
    rm -f /etc/nginx/sites-enabled/default
    echo "   Site dipasang — EDIT /etc/nginx/sites-available/agung-atk (domain & sertifikat)."
else
    echo "   Site sudah ada — tidak ditimpa."
fi
nginx -t
systemctl reload nginx

echo "== 7/7 PHP-FPM =="
systemctl enable --now php8.3-fpm
systemctl restart php8.3-fpm

cat <<'EOF'

Provisioning SELESAI. Langkah berikutnya:
  1. Clone/arsipkan repo aplikasi ke /var/www/agung-atk (user: deploy).
  2. cp .env.production.example .env  -> isi kredensial + php artisan key:generate
  3. composer install --no-dev --optimize-autoloader
  4. php artisan migrate --force && php artisan optimize
  5. chown -R www-data:www-data storage bootstrap/cache
  6. Edit server_name di /etc/nginx/sites-available/agung-atk, lalu
     certbot --nginx -d domain-anda.example
  7. Pasang unit systemd: deploy/systemd/agung-atk-worker.service &
     agung-atk-scheduler.service (systemctl enable --now ...).
EOF
