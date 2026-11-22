#!/usr/bin/env python3
"""Home Assistant worker for MineDev's private seed-tile queue."""

from __future__ import annotations

import json
import logging
import os
import re
import signal
import subprocess
import tempfile
import threading
import time
from pathlib import Path

LOG = logging.getLogger("minedev-seed-worker")
INSTANCE_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,31}$")
REVISION_RE = re.compile(r"^[a-f0-9]{24}$")
stop_event = threading.Event()


def stop_worker(signum: int, _frame: object) -> None:
    LOG.info("Sinal %s recebido; encerrando worker.", signum)
    stop_event.set()


def atomic_json(path: Path, value: object) -> None:
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            json.dump(value, stream, ensure_ascii=False, separators=(",", ":"))
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass


def generate(binary: Path, seed: str, radius: int, tile_x: int, tile_z: int, dungeons: bool) -> list[dict]:
    with tempfile.TemporaryDirectory(prefix="minedev-seed-") as temporary_dir:
        output = Path(temporary_dir) / "tile.json"
        command = [str(binary), seed, str(radius), str(tile_x), str(tile_z), str(output)]
        if dungeons:
            command.append("dungeons")
        subprocess.run(command, check=True, timeout=3600, stdout=subprocess.DEVNULL)
        with output.open("r", encoding="utf-8") as stream:
            points = json.load(stream)
    if not isinstance(points, list) or len(points) > 100_000:
        raise ValueError("O gerador retornou uma lista de pontos inválida.")
    for point in points:
        if not isinstance(point, dict) or not isinstance(point.get("type"), str):
            raise ValueError("O gerador retornou um ponto inválido.")
        if not all(isinstance(point.get(axis), int) for axis in (("x", "y", "z") if dungeons else ("x", "z"))):
            raise ValueError("O gerador retornou coordenadas inválidas.")
        if dungeons != point["type"].startswith("dungeon_"):
            raise ValueError("O setor contém tipos inesperados.")
    return points


def recover_interrupted_jobs(queue: Path) -> None:
    cutoff = time.time() - 300
    for claimed in queue.glob("*.json.working"):
        try:
            if claimed.stat().st_mtime < cutoff:
                queued = claimed.with_name(claimed.name.removesuffix(".working"))
                if not queued.exists():
                    os.replace(claimed, queued)
                    LOG.warning("Trabalho interrompido voltou à fila: %s", queued.name)
        except OSError as exc:
            LOG.warning("Não foi possível recuperar %s: %s", claimed.name, exc)


def generate_features(binary: Path, seed: str, tile_x: int, tile_z: int) -> list[dict]:
    with tempfile.TemporaryDirectory(prefix="minedev-features-") as temporary_dir:
        output = Path(temporary_dir) / "features.json"
        subprocess.run([str(binary), seed, str(tile_x), str(tile_z), str(output)],
                       check=True, timeout=3600, stdout=subprocess.DEVNULL)
        with output.open("r", encoding="utf-8") as stream:
            points = json.load(stream)
    if not isinstance(points, list) or len(points) > 100_000:
        raise ValueError("O gerador Bedrock retornou uma lista de recursos inválida.")
    allowed = {"fossil", "cave", "ore_vein"}
    for point in points:
        if (not isinstance(point, dict) or point.get("type") not in allowed or
                not isinstance(point.get("name"), str) or
                not all(isinstance(point.get(axis), int) for axis in ("x", "y", "z"))):
            raise ValueError("O gerador Bedrock retornou um recurso inválido.")
    return points


def process_job(job_path: Path, storage: Path, binary: Path, feature_binary: Path) -> None:
    claimed = job_path.with_name(job_path.name + ".working")
    try:
        os.replace(job_path, claimed)
    except FileNotFoundError:
        return
    try:
        with claimed.open("r", encoding="utf-8") as stream:
            job = json.load(stream)
        if not isinstance(job, dict):
            raise ValueError("O trabalho não é um objeto JSON.")
        instance_id = job.get("instance_id")
        revision = job.get("revision")
        tile_x, tile_z = job.get("x"), job.get("z")
        wants_dungeons = job.get("dungeons", False)
        wants_features = job.get("features", False)
        if (not isinstance(instance_id, str) or not INSTANCE_RE.fullmatch(instance_id) or
                not isinstance(revision, str) or not REVISION_RE.fullmatch(revision) or
                isinstance(tile_x, bool) or not isinstance(tile_x, int) or not -16 <= tile_x <= 15 or
                isinstance(tile_z, bool) or not isinstance(tile_z, int) or not -16 <= tile_z <= 15 or
                not isinstance(wants_dungeons, bool) or not isinstance(wants_features, bool)):
            raise ValueError("Parâmetros do setor inválidos.")

        metadata_path = storage / f"seed-layer-{instance_id}.json"
        with metadata_path.open("r", encoding="utf-8-sig") as stream:
            metadata = json.load(stream)
        if metadata.get("instance_id") != instance_id or metadata.get("tile_revision") != revision:
            raise ValueError("A seed ou sua revisão mudou antes do cálculo.")
        seed = metadata.get("seed")
        if not isinstance(seed, str) or not re.fullmatch(r"-?\d{1,19}", seed):
            raise ValueError("A seed do manifesto é inválida.")
        seed_value = int(seed)
        if not -(2**63) <= seed_value < 2**63:
            raise ValueError("A seed está fora do intervalo de 64 bits.")
        radius = -metadata.get("min_x", 0)
        if isinstance(radius, bool) or not isinstance(radius, int) or not 512 <= radius <= 32768:
            raise ValueError("O raio do manifesto é inválido.")

        base_path = storage / f"seed-tiles-{instance_id}-{revision}" / f"{tile_x}_{tile_z}.json"
        dungeon_path = storage / f"seed-dungeons-{instance_id}-{revision}" / f"{tile_x}_{tile_z}.json"
        feature_path = storage / f"seed-features-{instance_id}-{revision}" / f"{tile_x}_{tile_z}.json"
        if not base_path.is_file():
            points = generate(binary, seed, radius, tile_x, tile_z, dungeons=False)
            atomic_json(base_path, points)
        if wants_dungeons and not dungeon_path.is_file():
            points = generate(binary, seed, radius, tile_x, tile_z, dungeons=True)
            atomic_json(dungeon_path, points)
        if wants_features and not feature_path.is_file():
            points = generate_features(feature_binary, seed, tile_x, tile_z)
            atomic_json(feature_path, points)
        claimed.unlink(missing_ok=True)
        LOG.info("Setor pronto: instância=%s x=%s z=%s masmorras=%s recursos=%s", instance_id, tile_x, tile_z, wants_dungeons, wants_features)
    except Exception as exc:  # a bad job must not stop Bedrock or the rest of the queue
        failed = claimed.with_name(claimed.name + ".failed")
        try:
            os.replace(claimed, failed)
        except OSError:
            pass
        LOG.exception("Falha no trabalho %s: %s", job_path.name, exc)


def main() -> int:
    logging.basicConfig(level=logging.INFO, format="[%(asctime)s] [seed-worker] %(message)s", datefmt="%Y-%m-%d %H:%M:%S")
    storage = Path(os.environ.get("MINDEV_SEED_STORAGE", "/share/htdocs/minedev/storage")).resolve()
    binary = Path(os.environ.get("MINDEV_SEED_BINARY", "/usr/local/bin/minedev-seed-tile"))
    feature_binary = Path(os.environ.get("MINDEV_SEED_FEATURE_BINARY", "/usr/local/bin/minedev-seed-features"))
    if not binary.is_file() or not os.access(binary, os.X_OK):
        LOG.error("Gerador de setores não está instalado: %s", binary)
        return 1
    if not feature_binary.is_file() or not os.access(feature_binary, os.X_OK):
        LOG.error("Gerador de recursos Bedrock não está instalado: %s", feature_binary)
        return 1
    if not storage.is_dir():
        LOG.info("Aguardando instalação do MineDev em %s", storage)
    while not storage.is_dir():
        if stop_event.wait(10):
            return 0
    queue = storage / "seed-jobs"
    queue.mkdir(mode=0o700, parents=True, exist_ok=True)
    recover_interrupted_jobs(queue)
    signal.signal(signal.SIGTERM, stop_worker)
    signal.signal(signal.SIGINT, stop_worker)
    LOG.info("Ativo no Home Assistant. Armazenamento: %s", storage)
    while not stop_event.is_set():
        jobs = sorted(queue.glob("*.json"), key=lambda path: path.stat().st_mtime)
        if jobs:
            process_job(jobs[0], storage, binary, feature_binary)
        else:
            stop_event.wait(2)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
