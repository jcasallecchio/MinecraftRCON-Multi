import hmac
import ipaddress
import json
import logging
import os
import re
import hashlib
import stat
import subprocess
import tempfile
import threading
import time
import uuid
from collections import defaultdict, deque
from datetime import datetime, timezone
from logging.handlers import RotatingFileHandler
from pathlib import Path

from flask import Flask, jsonify, request
from waitress import serve
from backup_manager import BackupError, create_scheduled_backup, list_scheduled_backups, restore_backup

app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = 128 * 1024

SERVER_DIR = Path(os.environ.get("MINECRAFT_SERVER_DIR", "/share/minecraftRCON-Multi/instances/main"))
LOG_FILE = os.environ.get("RCON_LOG_FILE", str(SERVER_DIR / "rcon_commands.log"))
SERVER_LOG_FILE = SERVER_DIR / "server.log"
PLAYER_EVENTS_FILE = SERVER_DIR / "player_events.jsonl"
PLAYER_STATS_FILE = SERVER_DIR / "player_stats.json"
BACKUP_DIR = SERVER_DIR / "backups" / "scheduled"
MAINTENANCE_FILE = SERVER_DIR / ".maintenance"
OPTIONS_FILE = os.environ.get("RCON_OPTIONS_FILE", "/data/options.json")
RCON_PORT = int(os.environ.get("RCON_PORT", "19133"))
SCREEN_SESSION = os.environ.get("SCREEN_SESSION", "mc")
INSTANCE_ID = os.environ.get("MINECRAFT_INSTANCE_ID", "main")
MAX_COMMAND_LENGTH = 1024
LOG_MAX_BYTES = 2 * 1024 * 1024
LOG_BACKUP_COUNT = 5
CONSOLE_CAPTURE_SECONDS = 2.5
CONSOLE_QUIET_SECONDS = 0.35
RATE_WINDOW_SECONDS = 60
MAX_REQUESTS_PER_MINUTE = 60
MAX_COMMANDS_PER_MINUTE = 30
MAX_BACKUPS_PER_HOUR = 3
TELEMETRY_INTERVAL_SECONDS = 15
PLAYER_EVENTS_POLL_SECONDS = 1
PLAYER_EVENTS_RETENTION_SECONDS = 7 * 24 * 60 * 60
PLAYER_EVENTS_MAX_COUNT = 5000
API_VERSION = "1"

Path(LOG_FILE).parent.mkdir(parents=True, exist_ok=True)
command_logger = logging.getLogger("rcon_commands")
command_logger.setLevel(logging.INFO)
command_logger.propagate = False
command_log_handler = RotatingFileHandler(
    LOG_FILE,
    maxBytes=LOG_MAX_BYTES,
    backupCount=LOG_BACKUP_COUNT,
    encoding="utf-8",
)
command_log_handler.setFormatter(
    logging.Formatter("[%(asctime)s] IP: %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
)
command_logger.addHandler(command_log_handler)


def load_rcon_token():
    """Read the add-on secret option, with an env var fallback for local use."""
    try:
        with open(OPTIONS_FILE, encoding="utf-8") as options_file:
            options = json.load(options_file)
    except FileNotFoundError:
        options = {}

    token = options.get("rcon_token", "") if isinstance(options, dict) else ""
    if not token:
        token = os.environ.get("RCON_TOKEN", "")
    return token.strip() if isinstance(token, str) else ""


def load_options():
    try:
        with open(OPTIONS_FILE, encoding="utf-8") as options_file:
            options = json.load(options_file)
        return options if isinstance(options, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


OPTIONS = load_options()
RCON_TOKEN = load_rcon_token()
SERVER_OPERATION_LOCK = threading.RLock()
MANAGED_PROPERTIES = frozenset({
    "transport", "server-port", "server-portv6", "server-udp-ports",
    "enable-lan-visibility", "allow-list", "emit-server-telemetry", "level-name",
})
RESTORE_REQUEST_LOCK = threading.Lock()
RATE_LIMIT_LOCK = threading.Lock()
RATE_BUCKETS = defaultdict(deque)
SCHEDULER_STOP = threading.Event()
TELEMETRY_LOCK = threading.Lock()
PLAYER_EVENTS_LOCK = threading.Lock()
PLAYER_EVENTS_STOP = threading.Event()
PLAYER_STATS_LOCK = threading.Lock()
PLAYER_TELEMETRY = {
    "online_count": None,
    "max_count": None,
    "names": [],
    "updated_at": None,
}

DEATH_MESSAGE_PATTERN = re.compile(
    r"^(?P<player>.+?) (?P<message>"
    r"was slain by|was shot by|was pummeled by|was fireballed by|was blown up by|"
    r"was killed by|was sniped by|was spitballed by|was skewered by|was impaled by|"
    r"was roasted in dragon breath|was killed by magic|fell from a high place|"
    r"hit the ground too hard|tried to swim in lava|went up in flames|burned to death|"
    r"froze to death|starved to death|suffocated in a wall|pricked to death|"
    r"discovered floor was lava|experienced kinetic energy|went off with a bang|"
    r"drowned|blew up|died"
    r")(?P<cause>.*)$",
    re.IGNORECASE,
)


def load_allowed_networks(value):
    networks = []
    for item in (value or "").split(","):
        item = item.strip()
        if item:
            networks.append(ipaddress.ip_network(item, strict=False))
    return tuple(networks)


try:
    ALLOWED_NETWORKS = load_allowed_networks(OPTIONS.get("rcon_allowed_ips", ""))
except ValueError as error:
    raise SystemExit(f"[RCON] Invalid rcon_allowed_ips setting: {error}") from error


def log_command(ip, command, status, request_id=None):
    command_logger.info("%s | CMD: %s | STATUS: %s | REQUEST_ID: %s", ip or "unknown", command, status, request_id or "-")


def client_ip_allowed(ip):
    if not ALLOWED_NETWORKS:
        return True
    try:
        address = ipaddress.ip_address(ip)
    except ValueError:
        return False
    return any(address.version == network.version and address in network for network in ALLOWED_NETWORKS)


def request_rate_allowed(ip, command_request=False, backup_request=False):
    now = time.monotonic()
    if backup_request:
        limit = MAX_BACKUPS_PER_HOUR
        window = 3600
        category = "backups"
    else:
        limit = MAX_COMMANDS_PER_MINUTE if command_request else MAX_REQUESTS_PER_MINUTE
        window = RATE_WINDOW_SECONDS
        category = "commands" if command_request else "requests"
    key = (ip or "unknown", category)
    with RATE_LIMIT_LOCK:
        bucket = RATE_BUCKETS[key]
        while bucket and now - bucket[0] >= window:
            bucket.popleft()
        if len(bucket) >= limit:
            return False
        bucket.append(now)
        if len(RATE_BUCKETS) > 4096:
            for old_key in list(RATE_BUCKETS):
                if not RATE_BUCKETS[old_key] or now - RATE_BUCKETS[old_key][-1] >= 3600:
                    RATE_BUCKETS.pop(old_key, None)
        return True


def find_screen_session(session_name=None):
    """Return the exact screen session ID, if it exists."""
    session_name = session_name or SCREEN_SESSION
    try:
        result = subprocess.run(
            ["screen", "-ls"],
            check=True,
            capture_output=True,
            text=True,
            timeout=3,
        )
    except (OSError, subprocess.SubprocessError):
        return None

    session_pattern = re.compile(rf"^\s*(\d+\.{re.escape(session_name)})\s+\(")
    for line in result.stdout.splitlines():
        match = session_pattern.search(line)
        if match:
            return match.group(1)
    return None


def authorized():
    provided = request.headers.get("Authorization", "")
    expected = f"Bearer {RCON_TOKEN}"
    return len(RCON_TOKEN) >= 32 and hmac.compare_digest(
        provided.encode("utf-8"), expected.encode("utf-8")
    )


def send_console_command(command):
    session_id = find_screen_session()
    if not session_id:
        return False
    try:
        subprocess.run(
            ["screen", "-S", session_id, "-X", "stuff", f"{command}\r"],
            check=True,
            capture_output=True,
            text=True,
            timeout=3,
        )
        return True
    except (OSError, subprocess.SubprocessError):
        return False


def read_console_output(offset, timeout=CONSOLE_CAPTURE_SECONDS, quiet=CONSOLE_QUIET_SECONDS):
    deadline = time.monotonic() + timeout
    last_change = None
    output = ""
    while time.monotonic() < deadline:
        try:
            with SERVER_LOG_FILE.open("rb") as log_file:
                log_file.seek(offset)
                current = log_file.read().decode("utf-8", errors="replace")
        except OSError:
            current = ""
        if current != output:
            output = current
            last_change = time.monotonic()
        elif output and last_change is not None and time.monotonic() - last_change >= quiet:
            break
        time.sleep(0.1)
    lines = [line[:512] for line in output.splitlines() if line.strip()][-40:]
    while sum(len(line) for line in lines) > 8192:
        lines.pop(0)
    return lines


PLAYER_LIST_PATTERN = re.compile(
    r"(\d+)\s*/\s*(\d+)\s+players?\s+online\s*:?\s*(.*)",
    re.IGNORECASE,
)
BEDROCK_LOG_PREFIX = re.compile(
    r"\[\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}(?:[:.]\d+)?\s+[A-Z]+\]"
)


def _is_valid_player_name(value):
    name = str(value or "").strip()
    return (
        bool(name)
        and len(name) <= 16
        and "[" not in name
        and "]" not in name
        and any(character.isalnum() for character in name)
    )


def parse_player_list_output(lines):
    """Extract names from Bedrock's list response without leaking nearby log lines into it."""
    for index, line in enumerate(lines):
        match = PLAYER_LIST_PATTERN.search(line)
        if not match:
            continue

        online_count = int(match.group(1))
        max_count = int(match.group(2))
        if online_count == 0:
            return online_count, max_count, []

        candidates = [match.group(3), *lines[index + 1:]]
        names = []
        seen = set()
        for candidate_line in candidates:
            # A log record can be appended to the list response on the same line.
            candidate_line = BEDROCK_LOG_PREFIX.split(candidate_line, maxsplit=1)[0].strip()
            for candidate in candidate_line.split(","):
                name = candidate.strip().strip("• ")
                if not _is_valid_player_name(name):
                    continue
                key = name.casefold()
                if key not in seen:
                    names.append(name)
                    seen.add(key)
                if len(names) >= online_count:
                    return online_count, max_count, names
        return online_count, max_count, names
    return None


def sample_player_telemetry():
    """Refresh online-player data from Bedrock's native `list` console command."""
    with SERVER_OPERATION_LOCK:
        try:
            log_offset = SERVER_LOG_FILE.stat().st_size
        except OSError:
            log_offset = 0
        if not send_console_command("list"):
            return False
        output_lines = read_console_output(log_offset)

    parsed = parse_player_list_output(output_lines)
    if parsed is None:
        return False
    online_count, max_count, names = parsed
    with TELEMETRY_LOCK:
        PLAYER_TELEMETRY.update({
            "online_count": online_count,
            "max_count": max_count,
            "names": names,
            "updated_at": datetime.now(timezone.utc).isoformat(),
        })
    return True


def telemetry_scheduler():
    while not SCHEDULER_STOP.is_set():
        if find_screen_session() is not None:
            try:
                if not sample_player_telemetry():
                    app.logger.debug("Bedrock player telemetry was not available in the console output")
            except Exception:
                app.logger.exception("Could not refresh Bedrock player telemetry")
        SCHEDULER_STOP.wait(TELEMETRY_INTERVAL_SECONDS)


def _append_player_event(event_type, player, detail=""):
    player = str(player).strip()
    if not player or len(player) > 64:
        return
    now = time.time()
    event = {
        "id": int(now * 1_000_000),
        "timestamp": datetime.fromtimestamp(now, timezone.utc).isoformat(),
        "type": event_type,
        "player": player,
        "detail": str(detail).strip()[:240],
    }
    with PLAYER_EVENTS_LOCK:
        try:
            PLAYER_EVENTS_FILE.parent.mkdir(parents=True, exist_ok=True)
            events = []
            if PLAYER_EVENTS_FILE.exists():
                for line in PLAYER_EVENTS_FILE.read_text(encoding="utf-8").splitlines():
                    try:
                        item = json.loads(line)
                        if now - float(item.get("id", 0)) / 1_000_000 <= PLAYER_EVENTS_RETENTION_SECONDS:
                            events.append(item)
                    except (ValueError, TypeError, json.JSONDecodeError):
                        continue
            events.append(event)
            events = events[-PLAYER_EVENTS_MAX_COUNT:]
            temporary = PLAYER_EVENTS_FILE.with_suffix(".jsonl.tmp")
            temporary.write_text("".join(json.dumps(item, ensure_ascii=False) + "\n" for item in events), encoding="utf-8")
            temporary.replace(PLAYER_EVENTS_FILE)
            _record_player_stat_event(event)
        except OSError:
            app.logger.exception("Could not persist Bedrock player event")


def _parse_player_event(line):
    text = re.sub(r"^(?:\[[^\]]+\]\s*)+", "", line.strip())
    connected = re.search(r"\bPlayer connected:\s*(?P<player>[^,]+)", text, re.IGNORECASE)
    if connected:
        return "join", connected.group("player"), ""
    disconnected = re.search(r"\bPlayer disconnected:\s*(?P<player>[^,]+)", text, re.IGNORECASE)
    if disconnected:
        return "leave", disconnected.group("player"), ""
    death = DEATH_MESSAGE_PATTERN.match(text)
    if death:
        return "death", death.group("player"), (death.group("message") + death.group("cause")).strip()
    return None


def player_events_scheduler():
    """Tail only new Bedrock log lines, keeping a bounded seven-day event feed."""
    try:
        get_player_stats()
    except Exception:
        app.logger.exception("Could not initialize Bedrock player statistics")
    offset = 0
    try:
        offset = SERVER_LOG_FILE.stat().st_size
    except OSError:
        pass
    pending = b""
    while not PLAYER_EVENTS_STOP.is_set():
        try:
            if SERVER_LOG_FILE.exists():
                size = SERVER_LOG_FILE.stat().st_size
                if size < offset:
                    offset = 0
                    pending = b""
                if size > offset:
                    with SERVER_LOG_FILE.open("rb") as log_file:
                        log_file.seek(offset)
                        chunk = log_file.read()
                        offset = log_file.tell()
                    lines = (pending + chunk).split(b"\n")
                    pending = lines.pop()
                    for line in lines:
                        parsed = _parse_player_event(line.decode("utf-8", errors="replace"))
                        if parsed:
                            _append_player_event(*parsed)
        except OSError:
            app.logger.exception("Could not read Bedrock server log for player events")
        PLAYER_EVENTS_STOP.wait(PLAYER_EVENTS_POLL_SECONDS)


def get_player_events(after=0, limit=100):
    with PLAYER_EVENTS_LOCK:
        events = []
        try:
            for line in PLAYER_EVENTS_FILE.read_text(encoding="utf-8").splitlines():
                try:
                    event = json.loads(line)
                    if int(event.get("id", 0)) > after and _is_valid_player_name(event.get("player")):
                        events.append(event)
                except (ValueError, TypeError, json.JSONDecodeError):
                    continue
        except OSError:
            pass
    events.sort(key=lambda item: int(item.get("id", 0)))
    return events[:limit] if after else events[-limit:]


def _load_player_stats_unlocked():
    try:
        data = json.loads(PLAYER_STATS_FILE.read_text(encoding="utf-8"))
        if isinstance(data, dict) and isinstance(data.get("players"), dict):
            return data
    except (OSError, json.JSONDecodeError):
        pass
    return {"schema_version": 1, "last_event_id": 0, "players": {}}


def _save_player_stats_unlocked(stats):
    PLAYER_STATS_FILE.parent.mkdir(parents=True, exist_ok=True)
    temporary = PLAYER_STATS_FILE.with_suffix(".json.tmp")
    temporary.write_text(json.dumps(stats, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    temporary.replace(PLAYER_STATS_FILE)


def _apply_player_stat_event(stats, event):
    event_id = int(event.get("id", 0))
    if event_id <= int(stats.get("last_event_id", 0)):
        return False
    name = str(event.get("player", "")).strip()
    if not _is_valid_player_name(name):
        stats["last_event_id"] = event_id
        return True
    players = stats.setdefault("players", {})
    player = players.setdefault(name, {
        "join_count": 0,
        "leave_count": 0,
        "death_count": 0,
        "playtime_seconds": 0,
        "first_seen": event.get("timestamp"),
        "last_seen": event.get("timestamp"),
        "last_join": None,
        "online_since": None,
    })
    event_time = event_id / 1_000_000
    player["last_seen"] = event.get("timestamp")
    if event.get("type") == "join":
        player["join_count"] = int(player.get("join_count", 0)) + 1
        player["last_join"] = event.get("timestamp")
        previous_session = player.get("online_since")
        if previous_session is not None:
            player["playtime_seconds"] = int(player.get("playtime_seconds", 0)) + max(0, int(event_time - float(previous_session)))
        player["online_since"] = event_time
    elif event.get("type") == "leave":
        player["leave_count"] = int(player.get("leave_count", 0)) + 1
        online_since = player.get("online_since")
        if online_since is not None:
            player["playtime_seconds"] = int(player.get("playtime_seconds", 0)) + max(0, int(event_time - float(online_since)))
        player["online_since"] = None
    elif event.get("type") == "death":
        player["death_count"] = int(player.get("death_count", 0)) + 1
    stats["last_event_id"] = event_id
    return True


def _record_player_stat_event(event):
    with PLAYER_STATS_LOCK:
        stats = _load_player_stats_unlocked()
        if _apply_player_stat_event(stats, event):
            try:
                _save_player_stats_unlocked(stats)
            except OSError:
                app.logger.exception("Could not persist Bedrock player statistics")


def _server_started_epoch():
    try:
        return float((SERVER_DIR / ".server_started_at").read_text(encoding="ascii").strip())
    except (OSError, ValueError):
        return None


def get_player_stats():
    running = find_screen_session() is not None
    with TELEMETRY_LOCK:
        online_player_names = [str(name) for name in PLAYER_TELEMETRY.get("names", [])]
        online_names = {name.casefold() for name in online_player_names}
        sample_at = PLAYER_TELEMETRY.get("updated_at")
    try:
        sample_epoch = datetime.fromisoformat(sample_at).timestamp() if sample_at else None
    except ValueError:
        sample_epoch = None
    now = time.time()
    sample_fresh = sample_epoch is not None and now - sample_epoch <= TELEMETRY_INTERVAL_SECONDS * 3
    server_started = _server_started_epoch() if running else None
    events = []
    with PLAYER_EVENTS_LOCK:
        try:
            for line in PLAYER_EVENTS_FILE.read_text(encoding="utf-8").splitlines():
                try:
                    event = json.loads(line)
                    if _is_valid_player_name(event.get("player")):
                        events.append(event)
                except json.JSONDecodeError:
                    continue
        except OSError:
            pass
        with PLAYER_STATS_LOCK:
            stats = _load_player_stats_unlocked()
            changed = False
            saved_players = stats.setdefault("players", {})
            for name in list(saved_players):
                if not _is_valid_player_name(name):
                    del saved_players[name]
                    changed = True
            events.sort(key=lambda event: int(event.get("id", 0)))
            for event in events:
                changed = _apply_player_stat_event(stats, event) or changed
            if running and sample_fresh:
                known_names = {name.casefold() for name in stats.get("players", {})}
                sample_time = datetime.fromtimestamp(sample_epoch, timezone.utc).isoformat()
                for name in online_player_names:
                    if name.casefold() not in known_names:
                        stats["players"][name] = {
                            "join_count": 1,
                            "leave_count": 0,
                            "death_count": 0,
                            "playtime_seconds": 0,
                            "first_seen": sample_time,
                            "last_seen": sample_time,
                            "last_join": sample_time,
                            "online_since": sample_epoch,
                        }
                        known_names.add(name.casefold())
                        changed = True
            for name, player in stats.get("players", {}).items():
                online_since = player.get("online_since")
                if online_since is not None and server_started is not None and server_started > float(online_since):
                    player["playtime_seconds"] = int(player.get("playtime_seconds", 0)) + max(0, int(server_started - float(online_since)))
                    player["online_since"] = None
                    online_since = None
                    changed = True
                if not running or not sample_fresh:
                    continue
                seen_online = name.casefold() in online_names
                if seen_online and online_since is None:
                    player["join_count"] = int(player.get("join_count", 0)) + 1
                    player["online_since"] = sample_epoch
                    player["last_join"] = datetime.fromtimestamp(sample_epoch, timezone.utc).isoformat()
                    player["last_seen"] = player["last_join"]
                    changed = True
                elif not seen_online and online_since is not None and sample_epoch > float(online_since):
                    player["playtime_seconds"] = int(player.get("playtime_seconds", 0)) + max(0, int(sample_epoch - float(online_since)))
                    player["online_since"] = None
                    player["last_seen"] = datetime.fromtimestamp(sample_epoch, timezone.utc).isoformat()
                    changed = True
            if changed or not PLAYER_STATS_FILE.exists():
                try:
                    _save_player_stats_unlocked(stats)
                except OSError:
                    app.logger.exception("Could not initialize Bedrock player statistics")

    if not running or not sample_fresh:
        online_names.clear()
    players = []
    for name, player in stats.get("players", {}).items():
        if not _is_valid_player_name(name):
            continue
        online_since = player.get("online_since")
        is_online = name.casefold() in online_names
        session_seconds = max(0, int(now - float(online_since))) if is_online and online_since is not None else 0
        players.append({
            "name": name,
            "online": is_online,
            "join_count": int(player.get("join_count", 0)),
            "leave_count": int(player.get("leave_count", 0)),
            "death_count": int(player.get("death_count", 0)),
            "playtime_seconds": int(player.get("playtime_seconds", 0)) + session_seconds,
            "current_session_seconds": session_seconds,
            "first_seen": player.get("first_seen"),
            "last_seen": player.get("last_seen"),
            "last_join": player.get("last_join"),
        })
    players.sort(key=lambda player: (-player["playtime_seconds"], player["name"].casefold()))
    return {"players": players, "tracked_players": len(players), "collected_at": datetime.now(timezone.utc).isoformat()}


def _read_server_properties():
    try:
        properties = {}
        for line in (SERVER_DIR / "server.properties").read_text(encoding="utf-8-sig").splitlines():
            key, separator, value = line.partition("=")
            if separator:
                properties[key.strip()] = value.strip()
        return properties
    except OSError:
        return {}


def _server_version():
    try:
        return (SERVER_DIR / "lastversion.txt").read_text(encoding="utf-8").strip() or None
    except OSError:
        return None


def _bedrock_memory_bytes():
    """Return RSS for the Bedrock process when /proc exposes it."""
    try:
        for process_dir in Path("/proc").iterdir():
            if not process_dir.name.isdigit():
                continue
            try:
                if (process_dir / "comm").read_text(encoding="utf-8").strip() != "bedrock_server":
                    continue
                for line in (process_dir / "status").read_text(encoding="utf-8").splitlines():
                    if line.startswith("VmRSS:"):
                        return int(line.split()[1]) * 1024
            except (OSError, ValueError, IndexError):
                continue
    except OSError:
        pass
    return None


def get_telemetry():
    properties = _read_server_properties()
    maintenance = MAINTENANCE_FILE.exists()
    running = find_screen_session() is not None
    status = "maintenance" if maintenance else "running" if running else "offline"
    now = datetime.now(timezone.utc)
    started_at = None
    uptime_seconds = None
    try:
        started_at = float((SERVER_DIR / ".server_started_at").read_text(encoding="ascii").strip())
        uptime_seconds = max(0, int(time.time() - started_at)) if running else None
    except (OSError, ValueError):
        pass
    with TELEMETRY_LOCK:
        players = dict(PLAYER_TELEMETRY)
    player_sample_time = None
    if players["updated_at"]:
        try:
            player_sample_time = datetime.fromisoformat(players["updated_at"])
        except ValueError:
            pass
    player_sample_age = (now - player_sample_time).total_seconds() if player_sample_time else None
    players["stale"] = player_sample_age is None or player_sample_age > TELEMETRY_INTERVAL_SECONDS * 3
    players["sample_age_seconds"] = int(player_sample_age) if player_sample_age is not None else None
    return {
        "schema_version": f"{API_VERSION}.0",
        "collected_at": now.isoformat(),
        "server": {
            "status": status,
            "instance_id": INSTANCE_ID,
            "edition": "bedrock",
            "command_dialect": "minecraft-bedrock",
            "version": _server_version(),
            "uptime_seconds": uptime_seconds,
            "started_at": datetime.fromtimestamp(started_at, timezone.utc).isoformat() if started_at else None,
            "world_name": properties.get("level-name"),
            "game_port": _int_or_none(properties.get("server-port")),
            "game_portv6": _int_or_none(properties.get("server-portv6")),
            "game_mode": properties.get("gamemode"),
            "difficulty": properties.get("difficulty"),
            "max_players": _int_or_none(properties.get("max-players")),
        },
        "players": players,
        "performance": {"bedrock_memory_bytes": _bedrock_memory_bytes() if running else None},
    }


def _int_or_none(value):
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def api_info():
    return {
        "api_version": API_VERSION,
        "addon": "minecraft_rcon_multi",
        "instance_id": INSTANCE_ID,
        "server": {"edition": "bedrock", "command_dialect": "minecraft-bedrock", "version": _server_version()},
        "capabilities": {
            "command_execution": {"method": "POST", "path": "/api/v1/commands", "body": {"command": "string"}},
            "instance_control": {"method": "POST", "path": "/api/v1/control", "actions": ["start", "stop", "restart"]},
            "server_properties": {"read": "GET /api/v1/server-properties", "save": "PUT /api/v1/server-properties", "save_and_restart": True},
            "telemetry": {"method": "GET", "path": "/api/v1/telemetry", "refresh_seconds": TELEMETRY_INTERVAL_SECONDS},
            "player_events": {"method": "GET", "path": "/api/v1/events", "retention_days": 7},
            "player_statistics": {"method": "GET", "path": "/api/v1/player-stats", "metrics": ["playtime", "sessions", "deaths", "last_seen"]},
            "command_mode": "raw_console",
            "backups": {
                "list": {"method": "GET", "path": "/backups"},
                "create": {"method": "POST", "path": "/backups"},
                "restore": {"method": "POST", "path": "/backups/{backup_id}/restore"},
            },
        },
    }


def run_scheduled_backup():
    properties_path = SERVER_DIR / "server.properties"
    try:
        properties = {
            key.strip(): value.strip().lower()
            for key, separator, value in (
                line.partition("=") for line in properties_path.read_text(encoding="utf-8-sig").splitlines()
            )
            if separator
        }
    except OSError as error:
        raise BackupError(f"Could not read server.properties before backup: {error}") from error
    if properties.get("allow-cheats") != "true":
        raise BackupError("Safe scheduled backups require allow-cheats=true so Bedrock accepts save hold/resume")

    def save_hold():
        return send_console_command("save hold")

    def save_resume():
        return send_console_command("save resume")

    with SERVER_OPERATION_LOCK:
        return create_scheduled_backup(
            SERVER_DIR,
            BACKUP_DIR,
            max(1, min(int(OPTIONS.get("backup_keep_count", 7)), 90)),
            save_hold,
            save_resume,
        )


def backup_scheduler():
    interval_hours = int(OPTIONS.get("backup_interval_hours", 24))
    if interval_hours <= 0:
        app.logger.info("Scheduled world backups are disabled")
        return
    interval_seconds = max(3600, min(interval_hours, 720) * 3600)
    while not SCHEDULER_STOP.wait(interval_seconds):
        if find_screen_session() is None:
            app.logger.warning("Skipping scheduled backup because Bedrock is not running")
            continue
        try:
            backup_path = run_scheduled_backup()
            app.logger.info("Scheduled world backup created: %s", backup_path.name)
        except Exception:
            app.logger.exception("Scheduled world backup failed")


def restore_scheduled_backup(backup_id):
    if Path(backup_id).name != backup_id or not backup_id.startswith("scheduled-") or not backup_id.endswith(".tar.gz"):
        raise BackupError("Invalid scheduled backup ID")
    if not (BACKUP_DIR / backup_id).is_file():
        raise BackupError("Scheduled backup was not found")
    with SERVER_OPERATION_LOCK:
        if find_screen_session() is not None:
            if not send_console_command("stop"):
                raise BackupError("Could not send stop to the running Bedrock server")
            deadline = time.monotonic() + 60
            while find_screen_session() is not None and time.monotonic() < deadline:
                time.sleep(0.25)
            if find_screen_session() is not None:
                raise BackupError("Bedrock did not stop within 60 seconds; restore was cancelled")

        try:
            return restore_backup(backup_id, SERVER_DIR, BACKUP_DIR)
        except Exception:
            # A successful restore helper clears the maintenance marker itself;
            # on a rollback error keep maintenance enabled to avoid booting mixed data.
            if not (SERVER_DIR / ".restore-rollback" / "transaction.json").exists():
                MAINTENANCE_FILE.unlink(missing_ok=True)
            raise


@app.get("/health")
def health():
    if MAINTENANCE_FILE.exists():
        return jsonify({"status": "maintenance"}), 503
    if find_screen_session() is None:
        return jsonify({"status": "unavailable", "message": "Bedrock server is not running"}), 503
    return jsonify({"status": "ready"})


@app.before_request
def protect_rcon_routes():
    if request.path == "/health":
        return None
    client_ip = request.remote_addr or "unknown"
    if not client_ip_allowed(client_ip):
        return jsonify({"status": "error", "message": "Client IP is not allowed"}), 403
    is_command = request.path in {"/rcon", "/api/v1/commands"} and request.method == "POST"
    is_backup_operation = request.path == "/backups" and request.method == "POST" or request.path.endswith("/restore") and request.method == "POST"
    if not request_rate_allowed(client_ip, command_request=is_command, backup_request=is_backup_operation):
        return jsonify({"status": "error", "message": "Rate limit exceeded"}), 429
    return None


@app.route("/rcon", methods=["POST"])
@app.route("/api/v1/commands", methods=["POST"])
def rcon():
    client_ip = request.remote_addr

    if len(RCON_TOKEN) < 32:
        return jsonify({"status": "error", "message": "A valid RCON token is not configured"}), 503

    if not authorized():
        return jsonify({"status": "error", "message": "Unauthorized"}), 401

    if not request.is_json:
        log_command(client_ip, "INVALID_REQUEST", "ERROR: JSON required")
        return jsonify({"status": "error", "message": "Content-Type must be application/json"}), 400

    data = request.get_json(silent=True)
    if not isinstance(data, dict) or not isinstance(data.get("command"), str):
        log_command(client_ip, "INVALID_REQUEST", "ERROR: Missing string command")
        return jsonify({"status": "error", "message": "A string 'command' is required"}), 400

    cmd = data["command"].strip()
    if not cmd:
        log_command(client_ip, "EMPTY_COMMAND", "ERROR: Missing command")
        return jsonify({"status": "error", "message": "Missing command"}), 400

    if len(cmd) > MAX_COMMAND_LENGTH or any(ord(char) < 32 or ord(char) == 127 for char in cmd):
        log_command(client_ip, "INVALID_COMMAND", "ERROR: Invalid length or control character")
        return jsonify({"status": "error", "message": "Command is too long or contains control characters"}), 400

    with SERVER_OPERATION_LOCK:
        try:
            log_offset = SERVER_LOG_FILE.stat().st_size
        except OSError:
            log_offset = 0
        request_id = uuid.uuid4().hex
        if not send_console_command(cmd):
            log_command(client_ip, cmd, "ERROR: Failed to send command to screen", request_id)
            return jsonify({"status": "error", "message": "Failed to send command to Minecraft"}), 503
        console_output = read_console_output(log_offset)

    log_command(client_ip, cmd, "SENT_TO_SERVER_SESSION", request_id)
    return jsonify({
        "status": "ok",
        "request_id": request_id,
        "sent": True,
        "execution_confirmed": False,
        "console_output": console_output,
        "message": "Command sent. Console output is best-effort and does not confirm execution of this specific command.",
    })


@app.get("/api/v1/info")
def versioned_api_info():
    if not authorized():
        return jsonify({"status": "error", "message": "Unauthorized"}), 401
    return jsonify(api_info())


@app.get("/api/v1/telemetry")
def versioned_telemetry():
    if not authorized():
        return jsonify({"status": "error", "message": "Unauthorized"}), 401
    return jsonify(get_telemetry())


@app.get("/api/v1/events")
def versioned_player_events():
    if not authorized():
        return jsonify({"status": "error", "message": "Unauthorized"}), 401
    after = max(0, _int_or_none(request.args.get("after")) or 0)
    limit = max(1, min(200, _int_or_none(request.args.get("limit")) or 100))
    events = get_player_events(after, limit)
    cursor = max((int(event["id"]) for event in events), default=after)
    return jsonify({"events": events, "cursor": cursor, "retention_days": 7})


@app.get("/api/v1/player-stats")
def versioned_player_stats():
    if not authorized():
        return jsonify({"status": "error", "message": "Unauthorized"}), 401
    return jsonify(get_player_stats())


def _property_values(content: str) -> dict[str, str]:
    values = {}
    for number, line in enumerate(content.splitlines(), 1):
        stripped = line.strip()
        if not stripped or stripped.startswith(("#", ";")):
            continue
        key, separator, value = line.partition("=")
        key = key.strip().lower()
        if not separator or not re.fullmatch(r"[a-z0-9][a-z0-9._-]*", key):
            raise ValueError(f"Linha {number}: use chave=valor ou um comentário iniciado por #.")
        if key in values:
            raise ValueError(f"Linha {number}: a propriedade {key} está duplicada.")
        values[key] = value.strip()
    return values


@app.route("/api/v1/server-properties", methods=["GET", "PUT"])
def server_properties():
    if not authorized():
        return jsonify({"status": "error", "message": "Unauthorized"}), 401
    path = SERVER_DIR / "server.properties"
    with SERVER_OPERATION_LOCK:
        try:
            if path.is_symlink():
                return jsonify({"status": "error", "message": "O arquivo de propriedades não pode ser um link simbólico."}), 400
            current = path.read_bytes()
            original = current.decode("utf-8-sig")
        except (OSError, UnicodeError) as error:
            return jsonify({"status": "error", "message": f"Não foi possível ler server.properties: {error}"}), 503
        revision = hashlib.sha256(current).hexdigest()
        if request.method == "GET":
            return jsonify({"content": original, "revision": revision, "managed_keys": sorted(MANAGED_PROPERTIES)})
        data = request.get_json(silent=True)
        if not isinstance(data, dict) or not isinstance(data.get("content"), str) or not isinstance(data.get("revision"), str):
            return jsonify({"status": "error", "message": "Envie content e revision em JSON."}), 400
        if data["revision"] != revision:
            return jsonify({"status": "error", "message": "O arquivo mudou desde que foi aberto. Recarregue antes de salvar."}), 409
        proposed = data["content"]
        if not proposed or len(proposed.encode("utf-8")) > 64 * 1024 or any(
            ord(char) < 32 and char not in "\r\n\t" or ord(char) == 127 for char in proposed
        ):
            return jsonify({"status": "error", "message": "Arquivo vazio, grande demais ou com caracteres de controle."}), 400
        try:
            before = _property_values(original)
            after = _property_values(proposed)
        except ValueError as error:
            return jsonify({"status": "error", "message": str(error)}), 400
        changed_managed = sorted(key for key in MANAGED_PROPERTIES if before.get(key) != after.get(key))
        if changed_managed:
            return jsonify({"status": "error", "message": "Altere estas opções na configuração do add-on: " + ", ".join(changed_managed)}), 400
        restart = data.get("restart") is True
        if proposed.encode("utf-8") != current:
            temp_name = None
            try:
                with tempfile.NamedTemporaryFile(dir=SERVER_DIR, prefix=".server-properties-", delete=False) as temp:
                    temp_name = temp.name
                    temp.write(proposed.encode("utf-8"))
                    temp.flush()
                    os.fsync(temp.fileno())
                os.chmod(temp_name, stat.S_IMODE(path.stat().st_mode))
                os.replace(temp_name, path)
            except OSError as error:
                if temp_name:
                    try:
                        os.unlink(temp_name)
                    except OSError:
                        pass
                return jsonify({"status": "error", "message": f"Não foi possível salvar server.properties: {error}"}), 503
        new_revision = hashlib.sha256(path.read_bytes()).hexdigest()
        if restart:
            running = find_screen_session() is not None
            try:
                MAINTENANCE_FILE.write_text("restart requested\n", encoding="utf-8")
                if running and not send_console_command("stop"):
                    MAINTENANCE_FILE.unlink(missing_ok=True)
                    return jsonify({"status": "saved", "revision": new_revision, "message": "Arquivo salvo, mas não foi possível reiniciar a instância."})
                threading.Thread(target=_restart_after_graceful_stop, name=f"restart-{SCREEN_SESSION}", daemon=True).start()
            except OSError as error:
                return jsonify({"status": "saved", "revision": new_revision, "message": f"Arquivo salvo, mas falhou ao reiniciar: {error}"})
        return jsonify({
            "status": "restarting" if restart else "saved",
            "revision": new_revision,
            "message": "Propriedades salvas; instância reiniciando." if restart else "Propriedades salvas. Reinicie a instância para aplicá-las.",
        }), 202 if restart else 200


@app.post("/api/v1/control")
def control_instance():
    if not authorized():
        return jsonify({"status": "error", "message": "Unauthorized"}), 401
    data = request.get_json(silent=True)
    action = data.get("action") if isinstance(data, dict) else None
    if action not in {"start", "stop", "restart"}:
        return jsonify({"status": "error", "message": "action must be start, stop, or restart"}), 400

    with SERVER_OPERATION_LOCK:
        running = find_screen_session() is not None
        try:
            if action == "start":
                MAINTENANCE_FILE.unlink(missing_ok=True)
                return jsonify({"status": "starting", "message": "A instância será iniciada pelo supervisor local."}), 202

            MAINTENANCE_FILE.write_text(f"{action} requested\n", encoding="utf-8")
            if running and not send_console_command("stop"):
                MAINTENANCE_FILE.unlink(missing_ok=True)
                return jsonify({"status": "error", "message": "Não foi possível enviar stop ao servidor."}), 503
            if action == "restart":
                threading.Thread(target=_restart_after_graceful_stop, name=f"restart-{SCREEN_SESSION}", daemon=True).start()
                return jsonify({"status": "restarting", "message": "A instância está encerrando para reiniciar."}), 202
            return jsonify({"status": "stopping" if running else "stopped", "message": "A instância está encerrando com segurança." if running else "A instância foi parada."}), 202
        except OSError as error:
            return jsonify({"status": "error", "message": f"Não foi possível alterar o estado: {error}"}), 503


def _restart_after_graceful_stop():
    deadline = time.monotonic() + 60
    while find_screen_session() is not None and time.monotonic() < deadline:
        time.sleep(0.25)
    if find_screen_session() is not None:
        app.logger.error("Instance %s did not stop in time; keeping maintenance marker", SCREEN_SESSION)
        return
    MAINTENANCE_FILE.unlink(missing_ok=True)


@app.get("/backups")
def get_backups():
    if not authorized():
        return jsonify({"status": "error", "message": "Unauthorized"}), 401
    return jsonify({"status": "ok", "backups": list_scheduled_backups(BACKUP_DIR)})


@app.post("/backups")
def create_backup_endpoint():
    if not authorized():
        return jsonify({"status": "error", "message": "Unauthorized"}), 401
    try:
        path = run_scheduled_backup()
    except Exception as error:
        app.logger.exception("Manual world backup failed")
        return jsonify({"status": "error", "message": str(error)}), 503
    return jsonify({"status": "ok", "backup": path.name}), 201


@app.post("/backups/<backup_id>/restore")
def restore_backup_endpoint(backup_id):
    if not RESTORE_REQUEST_LOCK.acquire(blocking=False):
        return jsonify({"status": "error", "message": "Another restore is already in progress"}), 409
    try:
        return _restore_backup_request(backup_id)
    finally:
        RESTORE_REQUEST_LOCK.release()


def _restore_backup_request(backup_id):
    if not authorized():
        return jsonify({"status": "error", "message": "Unauthorized"}), 401
    if Path(backup_id).name != backup_id or not backup_id.startswith("scheduled-") or not backup_id.endswith(".tar.gz"):
        return jsonify({"status": "error", "message": "Invalid scheduled backup ID"}), 400
    if not (BACKUP_DIR / backup_id).is_file():
        return jsonify({"status": "error", "message": "Scheduled backup was not found"}), 404
    data = request.get_json(silent=True)
    if not isinstance(data, dict) or data.get("confirm") != f"restore {backup_id}":
        return jsonify({"status": "error", "message": "Confirmation must equal 'restore <backup_id>'"}), 400
    if not (SERVER_DIR / ".maintenance").exists():
        try:
            MAINTENANCE_FILE.write_text("restore requested\n", encoding="utf-8")
        except OSError as error:
            return jsonify({"status": "error", "message": f"Could not enter maintenance mode: {error}"}), 503
    try:
        rollback_path = restore_scheduled_backup(backup_id)
    except Exception as error:
        app.logger.exception("Scheduled world restore failed")
        if not (SERVER_DIR / ".restore-rollback" / "transaction.json").exists():
            MAINTENANCE_FILE.unlink(missing_ok=True)
        return jsonify({"status": "error", "message": str(error)}), 503
    return jsonify({"status": "ok", "restored": backup_id, "rollback_backup": rollback_path.name, "server_restart_expected": True}), 200


if __name__ == "__main__":
    if len(RCON_TOKEN) < 32:
        raise SystemExit("[RCON] Configure rcon_token (at least 32 characters) in the add-on options.")

    print(f"[RCON] Servidor RCON iniciado na porta {RCON_PORT}")
    threading.Thread(target=backup_scheduler, name="world-backup-scheduler", daemon=True).start()
    threading.Thread(target=telemetry_scheduler, name="bedrock-telemetry", daemon=True).start()
    threading.Thread(target=player_events_scheduler, name="bedrock-player-events", daemon=True).start()
    serve(app, host="0.0.0.0", port=RCON_PORT)
