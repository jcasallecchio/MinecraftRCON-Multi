#!/bin/sh
set -eu

console_dir=$(CDPATH= cd -P "$(dirname "$0")/.." && pwd)
storage_dir="$console_dir/storage"
apache_user=${1:-www-data}
apache_group=${2:-$apache_user}

if [ ! -d "$storage_dir" ] || [ -L "$storage_dir" ]; then
  printf '%s\n' "Diretório storage/ ausente ou inválido: $storage_dir" >&2
  exit 1
fi

find "$storage_dir" -type d -exec chown "$apache_user:$apache_group" {} + -exec chmod 0700 {} +
find "$storage_dir" -type f -exec chown "$apache_user:$apache_group" {} + -exec chmod 0600 {} +
