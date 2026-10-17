#!/usr/bin/env bash
# Обновление бота на сервере из GitHub.
# Запуск:  sudo bash /var/www/visa-bot/deploy/update.sh
set -euo pipefail

DIR="${1:-/var/www/visa-bot}"
cd "$DIR"

echo "→ Забираю изменения из GitHub…"
git fetch --quiet origin
git reset --hard origin/main   # config.php и data/ не трогаются — они в .gitignore

echo "→ Права…"
mkdir -p data
chown -R www-data:www-data data
chmod 770 data
[ -f config.php ] && chown root:www-data config.php && chmod 640 config.php

echo "→ Проверка синтаксиса PHP…"
find lib public bin -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null

echo "→ Перезапуск PHP-FPM (сброс кеша OPcache)…"
systemctl reload php8.3-fpm

if systemctl is-enabled --quiet visabot-b24 2>/dev/null; then
    echo "→ Перезапуск сборщика событий Битрикс24…"
    systemctl restart visabot-b24
fi

echo "✓ Готово: $(git log -1 --format='%h %s')"
