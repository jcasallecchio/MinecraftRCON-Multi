#!/bin/sh
set -eu

target=/share/htdocs/minedev
mkdir -p "$target"
cp -a /opt/minedev/. "$target/"

storage="$target/storage"
mkdir -p "$storage"
chown www-data:www-data "$storage"
chmod 0700 "$storage"

if [ -f "$target/.env" ]; then
  chgrp www-data "$target/.env"
  chmod 0640 "$target/.env"
else
  echo "[minedev-web] Login ainda não configurado. Execute php tools/initialize.php no contêiner e configure o .env."
fi

echo "[minedev-web] Servindo /minedev/ na porta HTTP 80 do contêiner."
exec apache2-foreground
