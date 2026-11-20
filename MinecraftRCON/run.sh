#!/usr/bin/env bash
set -Eeuo pipefail

log() { printf '[%s] [manager] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
OPTIONS_FILE=/data/options.json
INSTANCE_ROOT=/share/minecraftRCON-Multi/instances
mkdir -p "$INSTANCE_ROOT"

if ! python3 - "$OPTIONS_FILE" <<'PY' > /tmp/minecraft-instances.tsv
import json
import re
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as stream:
        options = json.load(stream)
except (OSError, json.JSONDecodeError) as exc:
    raise SystemExit(f"[manager] Não foi possível ler as opções do add-on: {exc}")

token = options.get("rcon_token", "")
configured_instances = options.get("instances")
if isinstance(configured_instances, list) and configured_instances:
    instances = configured_instances
elif isinstance(options.get("instances_json"), str) and options["instances_json"].strip():
    # Compatibilidade com configurações criadas na versão 0.1.0.
    try:
        instances = json.loads(options["instances_json"])
    except (TypeError, json.JSONDecodeError) as exc:
        raise SystemExit(f"[manager] A configuração antiga instances_json não contém uma lista JSON válida: {exc}")
elif isinstance(configured_instances, list):
    instances = configured_instances
else:
    instances = []

if not isinstance(instances, list) or len(instances) > 5:
    raise SystemExit("[manager] Configure de 1 a 5 instâncias no formulário do add-on.")
if not instances:
    sys.exit(0)
if not isinstance(token, str) or len(token.strip()) < 32:
    raise SystemExit("[manager] Configure rcon_token com pelo menos 32 caracteres antes de iniciar as instâncias.")

allow_list = options.get("allow_list", False)
emit_telemetry = options.get("emit_server_telemetry", True)
if not isinstance(allow_list, bool) or not isinstance(emit_telemetry, bool):
    raise SystemExit("[manager] allow_list e emit_server_telemetry precisam ser opções booleanas.")

seen_ids, seen_ports = set(), set()
for index, item in enumerate(instances):
    if not isinstance(item, dict):
        raise SystemExit(f"[manager] A instância {index + 1} precisa ser um objeto JSON.")
    instance_id = item.get("id", "")
    name = item.get("name", instance_id)
    world_name = item.get("world_name", name)
    port = item.get("udp_port", 19132 + index * 2)
    if not isinstance(instance_id, str) or not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,31}", instance_id):
        raise SystemExit(f"[manager] ID inválido na instância {index + 1}; use letras minúsculas, números, _ ou -.")
    if instance_id in seen_ids:
        raise SystemExit(f"[manager] ID duplicado: {instance_id}")
    if not isinstance(name, str) or not name.strip() or any(c in name for c in "\t\r\n"):
        raise SystemExit(f"[manager] Nome inválido na instância {instance_id}.")
    if not isinstance(world_name, str) or not world_name.strip() or any(c in world_name for c in "\t\r\n"):
        raise SystemExit(f"[manager] world_name inválido na instância {instance_id}.")
    if isinstance(port, bool) or not isinstance(port, int) or not 1024 <= port <= 65533:
        raise SystemExit(f"[manager] Porta UDP inválida na instância {instance_id}; use 1024–65533.")
    api_ports = set(range(19233, 19238))
    if port in seen_ports or port + 1 in seen_ports or port in api_ports or port + 1 in api_ports:
        raise SystemExit(f"[manager] A faixa de portas {port}-{port + 1} conflita com outra instância ou com uma API TCP.")
    seen_ids.add(instance_id)
    seen_ports.update((port, port + 1))
    print("\t".join((instance_id, name.strip(), world_name.strip(), str(port), str(19233 + index), str(allow_list).lower(), str(emit_telemetry).lower())))
PY
then
  log "Configuração inválida. Revise a lista de instâncias nas opções do add-on."
  exit 1
fi

declare -a INSTANCE_PIDS=()
shutdown() {
  log "Encerrando todas as instâncias..."
  for pid in "${INSTANCE_PIDS[@]}"; do kill -TERM "$pid" 2>/dev/null || true; done
  for pid in "${INSTANCE_PIDS[@]}"; do wait "$pid" 2>/dev/null || true; done
}
trap 'shutdown; exit 0' TERM INT

while IFS=$'\t' read -r instance_id display_name world_name udp_port api_port allow_list emit_telemetry; do
  [[ -n "$instance_id" ]] || continue
  instance_dir="$INSTANCE_ROOT/$instance_id"
  mkdir -p "$instance_dir"
  log "Iniciando '$display_name' (id=$instance_id, jogo TCP $udp_port / UDP $udp_port, API TCP $api_port)."
  MINECRAFT_INSTANCE_ID="$instance_id" \
  MINECRAFT_SERVER_DIR="$instance_dir" \
  SCREEN_SESSION="bedrock_${instance_id}" \
  BEDROCK_PORT="$udp_port" \
  BEDROCK_IPV6_PORT="$((udp_port + 1))" \
  BEDROCK_WORLD_NAME="$world_name" \
  BEDROCK_ALLOW_LIST="$allow_list" \
  BEDROCK_EMIT_SERVER_TELEMETRY="$emit_telemetry" \
  RCON_PORT="$api_port" \
    /instance.sh &
  INSTANCE_PIDS+=("$!")
done < /tmp/minecraft-instances.tsv

if ((${#INSTANCE_PIDS[@]} == 0)); then
  log "Nenhuma instância configurada. Adicione um mundo na configuração do add-on e inicie-o novamente."
  exit 0
fi

log "Gerenciador ativo com ${#INSTANCE_PIDS[@]} instância(s)."
while true; do
  for pid in "${INSTANCE_PIDS[@]}"; do
    if ! kill -0 "$pid" 2>/dev/null; then
      wait "$pid" || status=$?
      log "Uma instância encerrou inesperadamente (PID $pid, código ${status:-1}); encerrando para recuperação do Supervisor."
      exit 1
    fi
  done
  sleep 3
done
