#!/usr/bin/env bash
set -eo pipefail

# --- Configurações ---
SERVER_DIR="${MINECRAFT_SERVER_DIR:-/share/minecraftRCON-Multi/instances/main}"
RCON_PORT="${RCON_PORT:-19133}"
SCREEN_SESSION="${SCREEN_SESSION:-mc}"
BEDROCK_PORT="${BEDROCK_PORT:-19132}"
BEDROCK_IPV6_PORT="${BEDROCK_IPV6_PORT:-$((BEDROCK_PORT + 1))}"
BEDROCK_WORLD_NAME="${BEDROCK_WORLD_NAME:-}"
BACKUP_DIR="$SERVER_DIR/backups"
LAST_VERSION_FILE="$SERVER_DIR/lastversion.txt"
PACKS_VERSION_FILE="$SERVER_DIR/official_packs_version.txt"
SERVER_BIN="$SERVER_DIR/bedrock_server"
DEBUG=${DEBUG:-false}
EULA=${EULA:-true}
PACKAGE_BACKUP_KEEP=${PACKAGE_BACKUP_KEEP:-2}

# Cores para log
INTRO='\033[0;42m\033[1;37m'
WHITE='\033[1;37m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
RESET='\033[0m'


log() {
  local color="$1"; shift
  echo -e "[$(date '+%Y-%m-%d %H:%M:%S')] ${color}$*${RESET}"
}

isTrue() {
  [[ "${1,,}" =~ ^(true|on|1)$ ]]
}

if isTrue "$DEBUG"; then
  set -x
fi

log $WHITE "."
log $WHITE ".."
log $WHITE "..."
log $WHITE "...."
log $WHITE "....."
log $INTRO "=========== Iniciando Minecraft Bedrock RCON ==========="

mkdir -p "$SERVER_DIR" "$BACKUP_DIR"

if ! python3 -c 'import os, sys; sys.path.insert(0, "/"); from pathlib import Path; from backup_manager import recover_restore; recover_restore(Path(os.environ.get("MINECRAFT_SERVER_DIR", "/share/minecraftRCON-Multi/instances/main")))'; then
  log $RED "Não foi possível recuperar com segurança uma restauração interrompida. O servidor não será iniciado."
  exit 1
fi
if ! python3 /update_transaction.py recover --server-dir "$SERVER_DIR"; then
  log $RED "Não foi possível recuperar com segurança uma atualização interrompida. O servidor não será iniciado."
  exit 1
fi

if ! isTrue "$EULA"; then
  log $RED "EULA não aceita. Configure EULA=true para continuar."
  exit 1
fi

# The token is optional in the Supervisor schema so upgrades can complete.
# Refuse to start the game or expose an unauthenticated RCON endpoint when it
# has not been configured.
if ! python3 -c '
import json
import sys

try:
    with open("/data/options.json", encoding="utf-8") as options_file:
        options = json.load(options_file)
    token = options.get("rcon_token", "") if isinstance(options, dict) else ""
except (OSError, json.JSONDecodeError):
    token = ""

sys.exit(0 if isinstance(token, str) and len(token.strip()) >= 32 else 1)
'; then
  log $RED "Token RCON ausente ou inválido. Configure rcon_token com pelo menos 32 caracteres nas opções do addon. Encerrando sem iniciar o servidor."
  exit 1
fi

# --- Consulta a API oficial para obter versão e URL de download ---
lookupVersion() {
  local platform="$1"
  local api_response
  local version_info

  if [[ "$platform" != "serverBedrockLinux" && "$platform" != "serverBedrockPreviewLinux" ]]; then
    log $RED "Plataforma inválida: $platform"
    exit 2
  fi

  log $YELLOW "Consultando a API oficial do Minecraft Bedrock..."
  if ! api_response=$(curl -fsSL --retry 3 --retry-delay 2 --retry-connrefused "https://net-secondary.web.minecraft-services.net/api/v1.0/download/links"); then
    log $RED "Falha ao consultar a API oficial de downloads do Minecraft."
    return 1
  fi

  if ! version_info=$(printf '%s' "$api_response" | python3 -c '
import json
import re
import sys

platform = sys.argv[1]
expected_path = {
    "serverBedrockLinux": "bin-linux",
    "serverBedrockPreviewLinux": "bin-linux-preview",
}.get(platform)

try:
    payload = json.load(sys.stdin)
    link = next(
        item for item in payload["result"]["links"]
        if item["downloadType"] == platform
    )
    download_url = link["downloadUrl"]
    pattern = (
        rf"https://www\.minecraft\.net/bedrockdedicatedserver/"
        rf"{re.escape(expected_path)}/bedrock-server-"
        r"([0-9]+(?:\.[0-9]+)*)\.zip"
    )
    match = re.fullmatch(pattern, download_url)
    if match is None:
        raise ValueError("Unexpected download URL")
    print(match.group(1) + "|" + download_url)
except (KeyError, TypeError, StopIteration, ValueError, json.JSONDecodeError) as error:
    print("Invalid download API response: " + str(error), file=sys.stderr)
    sys.exit(1)
' "$platform"); then
    log $RED "A API retornou uma resposta inválida para a plataforma $platform."
    return 1
  fi

  VERSION="${version_info%%|*}"
  DOWNLOAD_URL="${version_info#*|}"
  if [[ -z "$VERSION" || -z "$DOWNLOAD_URL" ]]; then
    log $RED "A API não retornou versão e URL de download válidas."
    return 1
  fi
}

# --- Verifica versão instalada (se houver) ---
INSTALLED_VERSION=""
if [[ -f "$LAST_VERSION_FILE" ]]; then
  INSTALLED_VERSION=$(<"$LAST_VERSION_FILE")
fi
INSTALLED_PACKS_VERSION=""
if [[ -f "$PACKS_VERSION_FILE" ]]; then
  INSTALLED_PACKS_VERSION=$(<"$PACKS_VERSION_FILE")
fi

# --- Busca última versão disponível online; mantém a instalada em caso de falha ---
API_AVAILABLE=true
if ! lookupVersion serverBedrockLinux; then
  API_AVAILABLE=false
  if [[ -s "$SERVER_BIN" ]]; then
    VERSION="$INSTALLED_VERSION"
    log $YELLOW "Não foi possível consultar a versão online. Iniciando a versão instalada ${VERSION:-desconhecida}; uma nova tentativa ocorrerá na próxima reinicialização."
  else
    log $RED "Não foi possível consultar a API e não há servidor instalado para iniciar."
    exit 2
  fi
else
  log $GREEN "Versão disponível online: $VERSION"
fi
log $GREEN "Versão instalada localmente: ${INSTALLED_VERSION:-nenhuma}"
log $GREEN "Versão dos pacotes oficiais sincronizada: ${INSTALLED_PACKS_VERSION:-nenhuma}"

# --- Decide se precisa atualizar ---
NEED_UPDATE=false
if [[ "$API_AVAILABLE" == true ]]; then
  NEED_UPDATE=true
  if [[ "$VERSION" == "$INSTALLED_VERSION" ]] && [[ -s "$SERVER_BIN" ]] && [[ "$VERSION" == "$INSTALLED_PACKS_VERSION" ]]; then
    log $GREEN "Servidor já está na última versão, não será atualizado!"
    NEED_UPDATE=false
  else
    log $YELLOW "Servidor ou pacotes oficiais precisam ser sincronizados com a versão $VERSION, atualizando..."
    log $GREEN "URL para download: $DOWNLOAD_URL"
  fi
fi

# --- Backup e restauração ---
BACKUP_TARGET=""

pack_is_official() {
  python3 - "$1" "$2" <<'PY'
import json
import sys
from pathlib import Path

candidate = Path(sys.argv[1]) / "manifest.json"
official_root = Path(sys.argv[2])

def header_uuid(manifest_path):
    try:
        with manifest_path.open(encoding="utf-8") as manifest_file:
            payload = json.load(manifest_file)
        value = payload.get("header", {}).get("uuid")
        return value.lower() if isinstance(value, str) else None
    except (OSError, json.JSONDecodeError, AttributeError):
        return None

candidate_uuid = header_uuid(candidate)
if not candidate_uuid:
    raise SystemExit(1)

for manifest_path in official_root.glob("*/manifest.json"):
    if header_uuid(manifest_path) == candidate_uuid:
        raise SystemExit(0)
raise SystemExit(1)
PY
}

do_backup() {
  local timestamp
  timestamp=$(date '+%Y%m%d-%H%M%S')
  BACKUP_TARGET="$BACKUP_DIR/backup-$timestamp"

  log $YELLOW "Criando backup seletivo em $BACKUP_TARGET..."
  mkdir -p "$BACKUP_TARGET"

  for item in worlds structures server.properties permissions.json allowlist.json; do
    if [[ -e "$SERVER_DIR/$item" ]]; then
      log $YELLOW "Salvando $item..."
      cp -r "$SERVER_DIR/$item" "$BACKUP_TARGET/"
    fi
  done

  for item in behavior_packs resource_packs; do
    local source_dir="$SERVER_DIR/$item"
    [[ -d "$source_dir" ]] || continue
    while IFS= read -r -d '' pack_source; do
      local pack_name="${pack_source##*/}"
      if pack_is_official "$pack_source" "$STAGING_DIR/$item"; then
        log $YELLOW "Não copiando pacote oficial para o backup: $item/$pack_name"
        continue
      fi
      log $YELLOW "Salvando pacote personalizado: $item/$pack_name"
      mkdir -p "$BACKUP_TARGET/$item"
      cp -r "$pack_source" "$BACKUP_TARGET/$item/"
    done < <(find "$source_dir" -mindepth 1 -maxdepth 1 -type d -print0)
  done

  log $YELLOW "Removendo backups antigos, mantendo os $PACKAGE_BACKUP_KEEP mais recentes..."
  find "$BACKUP_DIR" -maxdepth 1 -type d -name "backup-*" | sort -r | tail -n +$((PACKAGE_BACKUP_KEEP + 1)) | xargs -r rm -rf
}

stage_custom_packs() {
  local item backup_dir pack_source target_dir pack_name pack_temp

  # Keep the packs shipped with the new server. Add custom packs to staging
  # so they are covered by the same rollback transaction as the server files.
  for item in behavior_packs resource_packs; do
    backup_dir="$BACKUP_TARGET/$item"
    target_dir="$STAGING_DIR/$item"

    [[ -d "$backup_dir" ]] || continue
    mkdir -p "$target_dir"

    while IFS= read -r -d '' pack_source; do
      pack_name="${pack_source##*/}"

      if pack_is_official "$pack_source" "$STAGING_DIR/$item"; then
        log $YELLOW "Ignorando cópia antiga de pacote oficial (manifesto): $item/$pack_name"
        continue
      fi

      if [[ ! -e "$target_dir/$pack_name" ]]; then
        log $YELLOW "Preparando pacote personalizado no staging: $item/$pack_name"
        pack_temp="$target_dir/.restore-${pack_name}-$$"
        rm -rf "$pack_temp"
        cp -r "$pack_source" "$pack_temp"
        mv "$pack_temp" "$target_dir/$pack_name"
      else
        log $YELLOW "Mantendo pacote já fornecido pela nova versão: $item/$pack_name"
      fi
    done < <(find "$backup_dir" -mindepth 1 -maxdepth 1 -type d -print0)
  done
}

preserve_existing_user_data() {
  local item
  for item in worlds structures server.properties permissions.json allowlist.json; do
    if [[ -e "$SERVER_DIR/$item" && -e "$STAGING_DIR/$item" ]]; then
      log $YELLOW "Preservando dados existentes: $item"
      rm -rf "$STAGING_DIR/$item"
    fi
  done
}

# --- Executa atualização se necessário ---
if $NEED_UPDATE; then
  TMP_ZIP="$SERVER_DIR/.server-$VERSION-$$.zip.part"
  STAGING_DIR="$SERVER_DIR/.server-$VERSION-$$.staging"
  trap 'rm -f "${TMP_ZIP:-}"; rm -rf "${STAGING_DIR:-}"' EXIT
  UPDATE_ARTIFACTS_VALID=true
  log $YELLOW "Baixando servidor Bedrock versão $VERSION..."
  if ! curl -fsSL --retry 3 --retry-delay 2 --retry-connrefused -o "$TMP_ZIP" -A "itzg/minecraft-bedrock-server" "$DOWNLOAD_URL"; then
    log $RED "Falha no download da versão $VERSION."
    UPDATE_ARTIFACTS_VALID=false
  elif ! unzip -tq "$TMP_ZIP" >/dev/null; then
    log $RED "O ZIP baixado está incompleto ou inválido."
    UPDATE_ARTIFACTS_VALID=false
  elif ! python3 - "$TMP_ZIP" <<'PY'
import sys
import zipfile

archive_path = sys.argv[1]
try:
    with zipfile.ZipFile(archive_path) as archive:
        names = archive.namelist()
        if "bedrock_server" not in names:
            raise ValueError("bedrock_server is missing from archive root")
        for name in names:
            if name.startswith("/") or ".." in name.split("/"):
                raise ValueError("unsafe archive path")
except (OSError, zipfile.BadZipFile, ValueError) as error:
    print(f"Invalid Bedrock server archive: {error}", file=sys.stderr)
    sys.exit(1)
PY
  then
    log $RED "O ZIP não contém uma distribuição Bedrock segura e completa."
    UPDATE_ARTIFACTS_VALID=false
  fi

  if [[ "$UPDATE_ARTIFACTS_VALID" == true ]]; then
    if ! mkdir -p "$STAGING_DIR" || ! unzip -q "$TMP_ZIP" -d "$STAGING_DIR" || [[ ! -s "$STAGING_DIR/bedrock_server" ]]; then
      log $RED "Não foi possível extrair e validar bedrock_server."
      UPDATE_ARTIFACTS_VALID=false
    fi
  fi

  if [[ "$UPDATE_ARTIFACTS_VALID" == true ]]; then
    preserve_existing_user_data
  fi

  if [[ "$UPDATE_ARTIFACTS_VALID" != true ]]; then
    rm -f "$TMP_ZIP"
    rm -rf "$STAGING_DIR"
    trap - EXIT
    if [[ -s "$SERVER_BIN" ]]; then
      NEED_UPDATE=false
      VERSION="$INSTALLED_VERSION"
      log $YELLOW "A atualização não foi concluída. Iniciando a versão instalada ${VERSION:-desconhecida}; uma nova tentativa ocorrerá na próxima reinicialização."
    else
      log $RED "A atualização falhou e não há um binário instalado válido para iniciar."
      exit 1
    fi
  fi

  if $NEED_UPDATE; then
    do_backup
    stage_custom_packs

    log $YELLOW "Extração concluída em ${SECONDS}s! Preparando dados e pacotes personalizados..."

    # Replace files with rollback protection so a power loss cannot leave a
    # mixed installation. User worlds and settings were excluded from staging.
    if python3 /update_transaction.py apply --server-dir "$SERVER_DIR" --staging-dir "$STAGING_DIR"; then
      UPDATE_APPLIED=true
    else
      APPLY_STATUS=$?
      if [[ "$APPLY_STATUS" -eq 20 ]]; then
        log $RED "A atualização falhou e o rollback também não pôde ser confirmado; o add-on não iniciará o servidor."
        exit 1
      fi
      if [[ -s "$SERVER_BIN" ]]; then
        NEED_UPDATE=false
        UPDATE_APPLIED=false
        VERSION="$INSTALLED_VERSION"
        rm -f "$TMP_ZIP"
        rm -rf "$STAGING_DIR"
        trap - EXIT
        log $YELLOW "Falha ao instalar a atualização. A versão anterior foi restaurada e será iniciada."
      else
        log $RED "A instalação falhou e não há uma versão anterior válida para iniciar."
        exit 1
      fi
    fi

    if [[ "$UPDATE_APPLIED" == true ]]; then
      if [[ ! -s "$SERVER_BIN" ]]; then
        log $RED "O binário do servidor não foi instalado corretamente."
        exit 1
      fi
      chmod +x "$SERVER_BIN"

      if ! python3 /update_transaction.py commit --server-dir "$SERVER_DIR"; then
        log $RED "Não foi possível confirmar a instalação; o rollback será recuperado no próximo boot."
        exit 1
      fi
      echo "$VERSION" > "$LAST_VERSION_FILE"
      echo "$VERSION" > "$PACKS_VERSION_FILE"

      rm -f "$TMP_ZIP"
      rm -rf "$STAGING_DIR"
      trap - EXIT

      log $GREEN "Atualização concluída para a versão $VERSION."
    fi
  fi
fi

# --- Inicia servidor ---
transport_result=$(python3 - "$SERVER_DIR/server.properties" "$BEDROCK_PORT" "$BEDROCK_IPV6_PORT" "$BEDROCK_WORLD_NAME" "${BEDROCK_ALLOW_LIST:-false}" "${BEDROCK_EMIT_SERVER_TELEMETRY:-true}" "${BEDROCK_PUBLIC_IP:--}" <<'PY'
import os
import stat
import sys
from pathlib import Path

properties_path = Path(sys.argv[1])
settings = {
    "transport": "nethernet",
    "server-port": sys.argv[2],
    "server-portv6": sys.argv[3],
    "server-udp-ports": f"{sys.argv[7]}:{sys.argv[2]}:{sys.argv[2]}" if sys.argv[7] != "-" else sys.argv[2],
    "enable-lan-visibility": "false",
    "allow-list": sys.argv[5],
    "emit-server-telemetry": sys.argv[6],
}
if sys.argv[4]:
    settings["level-name"] = sys.argv[4]
try:
    exists = properties_path.exists()
    original = properties_path.read_bytes() if exists else b""
    newline = b"\r\n" if b"\r\n" in original else b"\n"
    lines = original.splitlines()
    updated = []
    changed = not exists
    seen = set()

    for line in lines:
        key = line.split(b"=", 1)[0].strip().decode("ascii", errors="ignore").lower()
        if key in settings:
            if key in seen:
                changed = True
                continue
            seen.add(key)
            replacement = f"{key}={settings[key]}".encode()
            updated.append(replacement)
            changed = changed or line.strip() != replacement
            continue
        updated.append(line)

    for key, value in settings.items():
        if key not in seen:
            updated.append(f"{key}={value}".encode())
            changed = True

    content = newline.join(updated) + newline
    temp_path = properties_path.with_name(properties_path.name + ".tmp")
    temp_path.write_bytes(content)
    if exists:
        os.chmod(temp_path, stat.S_IMODE(properties_path.stat().st_mode))
    os.replace(temp_path, properties_path)
    print("Propriedades da instância configuradas" if changed else "Propriedades da instância já estavam configuradas")
except OSError as error:
    print(f"Não foi possível configurar transport=nethernet: {error}", file=sys.stderr)
    sys.exit(1)
PY
) || { log $RED "Falha ao garantir o transporte NetherNet no server.properties."; exit 1; }
log $YELLOW "$transport_result"
cd "$SERVER_DIR"

# A host-network proxy or another service can occupy the signaling port while
# Bedrock still prints "Server started.". Fail before claiming the instance is
# ready so the real port conflict is visible in the add-on log.
if ! python3 - "$BEDROCK_PORT" <<'PY'
import socket
import sys

port = int(sys.argv[1])
for family, address in ((socket.AF_INET6, "::"), (socket.AF_INET, "0.0.0.0")):
    for kind, label in ((socket.SOCK_STREAM, "TCP"), (socket.SOCK_DGRAM, "UDP")):
        try:
            with socket.socket(family, kind) as probe:
                if family == socket.AF_INET6:
                    probe.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
                probe.bind((address, port))
        except OSError as error:
            print(f"Porta {label} {port} indisponível em {address}: {error}", file=sys.stderr)
            sys.exit(1)
PY
then
  log $RED "A porta do jogo está ocupada ou indisponível. Libere-a antes de iniciar esta instância."
  exit 1
fi

server_session_exists() {
  screen -ls | grep -Eq "^[[:space:]]*[0-9]+\\.${SCREEN_SESSION}[[:space:]]+\\("
}

rotate_server_log() {
  local size_bytes
  [[ -f "$SERVER_DIR/server.log" ]] || return 0
  size_bytes=$(wc -c < "$SERVER_DIR/server.log")
  (( size_bytes < 20971520 )) && return 0
  rm -f "$SERVER_DIR/server.log.3"
  [[ ! -f "$SERVER_DIR/server.log.2" ]] || mv "$SERVER_DIR/server.log.2" "$SERVER_DIR/server.log.3"
  [[ ! -f "$SERVER_DIR/server.log.1" ]] || mv "$SERVER_DIR/server.log.1" "$SERVER_DIR/server.log.2"
  mv "$SERVER_DIR/server.log" "$SERVER_DIR/server.log.1"
}

start_and_wait_server() {
  local old_line_count attempt server_ready=false startup_log
  rotate_server_log
  touch "$SERVER_DIR/server.log"
  old_line_count=$(wc -l < "$SERVER_DIR/server.log" 2>/dev/null || printf '0')
  log $YELLOW "Iniciando servidor Minecraft Bedrock..."
  screen -dmS "$SCREEN_SESSION" bash -c "./bedrock_server | tee -a '$SERVER_DIR/server.log' /proc/1/fd/1"

  log $YELLOW "Aguardando o servidor Minecraft ficar pronto..."
  for attempt in {1..60}; do
    if ! server_session_exists; then
      log $RED "A sessão do servidor Minecraft encerrou durante a inicialização."
      tail -n 40 "$SERVER_DIR/server.log" || true
      return 1
    fi
    startup_log=$(tail -n +$((old_line_count + 1)) "$SERVER_DIR/server.log")
    if grep -Fq "Server started." <<< "$startup_log" && grep -Fq "Accepting clients on" <<< "$startup_log"; then
      server_ready=true
      break
    fi
    sleep 1
  done

  if [[ "$server_ready" != true ]]; then
    log $RED "O servidor Minecraft não confirmou a abertura da porta e a inicialização após 60 segundos."
    tail -n 40 "$SERVER_DIR/server.log" || true
    return 1
  fi
  printf '%s\n' "$(date +%s)" > "$SERVER_DIR/.server_started_at.tmp"
  mv "$SERVER_DIR/.server_started_at.tmp" "$SERVER_DIR/.server_started_at"
  log $GREEN "Servidor Minecraft pronto."
}

if ! start_and_wait_server; then
  exit 1
fi

log $YELLOW "Iniciando RCON personalizado..."
MINECRAFT_SERVER_DIR="$SERVER_DIR" RCON_PORT="$RCON_PORT" SCREEN_SESSION="$SCREEN_SESSION" python3 /rcon_server.py &
RCON_PID=$!

rcon_ready=false
for attempt in {1..30}; do
  if ! kill -0 "$RCON_PID" 2>/dev/null; then
    log $RED "O processo do RCON encerrou durante a inicialização. Verifique o token nas opções do addon."
    exit 1
  fi

  if curl -fsS --max-time 2 "http://127.0.0.1:${RCON_PORT}/health" >/dev/null; then
    rcon_ready=true
    break
  fi
  sleep 1
done

if [[ "$rcon_ready" != true ]]; then
  log $RED "O endpoint RCON não ficou pronto após 30 segundos."
  exit 1
fi

log $GREEN "Servidor Minecraft e endpoint RCON prontos."

stop_server_gracefully() {
  local session_id attempt
  session_id=$(screen -ls | sed -n "s/^[[:space:]]*\\([0-9]*\\.${SCREEN_SESSION}\\)[[:space:]].*/\\1/p" | head -n 1)
  [[ -n "$session_id" ]] || return 0
  log $YELLOW "Solicitando encerramento seguro do servidor Bedrock..."
  screen -S "$session_id" -X stuff $'stop\r' || true
  for attempt in {1..60}; do
    server_session_exists || break
    sleep 1
  done
}

shutdown_addon() {
  stop_server_gracefully
  kill -TERM "$RCON_PID" 2>/dev/null || true
}
trap shutdown_addon TERM INT

restart_delay=5
last_server_started=$SECONDS
while kill -0 "$RCON_PID" 2>/dev/null; do
  if [[ -e "$SERVER_DIR/.maintenance" ]]; then
    sleep 1
    continue
  fi
  if ! server_session_exists; then
    if (( SECONDS - last_server_started >= 300 )); then
      restart_delay=5
    fi
    log $RED "A sessão do Bedrock parou após a inicialização. Tentarei reiniciá-la em ${restart_delay}s."
    next_restart_delay=$restart_delay
    restart_delay=$((restart_delay * 2))
    (( restart_delay > 60 )) && restart_delay=60
    sleep "$next_restart_delay"
    if [[ -e "$SERVER_DIR/.maintenance" ]]; then
      continue
    fi
    if start_and_wait_server; then
      last_server_started=$SECONDS
      log $GREEN "Servidor Bedrock recuperado pelo watchdog."
    fi
  fi
  sleep 2
done

log $RED "O processo RCON encerrou. Encerrando o addon para permitir recuperação pelo Supervisor."
exit 1
