"""Validate an offline cubiomes preview and publish it to MineDev storage."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import struct
import tempfile
import math
from datetime import datetime, timezone
import zlib


def png_chunk(kind: bytes, data: bytes) -> bytes:
    return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data) & 0xFFFFFFFF)


def ppm_to_png(path: Path, width: int, height: int) -> bytes:
    with path.open("rb") as source:
        if source.readline() != b"P6\n":
            raise ValueError("Imagem PPM inválida.")
        if source.readline() != f"{width} {height}\n".encode():
            raise ValueError("Dimensões da imagem não correspondem à previsão.")
        if source.readline() != b"255\n":
            raise ValueError("Profundidade de cor inválida.")
        pixels = source.read()
    if len(pixels) != width * height * 3:
        raise ValueError("Imagem PPM incompleta.")
    rows = b"".join(b"\0" + pixels[y * width * 3:(y + 1) * width * 3] for y in range(height))
    return (b"\x89PNG\r\n\x1a\n"
            + png_chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0))
            + png_chunk(b"IDAT", zlib.compress(rows, 9))
            + png_chunk(b"IEND", b""))


def slime_to_png(path: Path, width: int, height: int, expected_count: int) -> bytes:
    with path.open("rb") as source:
        if source.readline() != b"P5\n" or source.readline() != f"{width} {height}\n".encode() or source.readline() != b"255\n":
            raise ValueError("Máscara de slime chunks inválida.")
        cells = source.read()
    if len(cells) != width * height or cells.count(255) != expected_count or any(value not in (0, 255) for value in cells):
        raise ValueError("Máscara de slime chunks incompleta ou inconsistente.")
    rows = []
    for y in range(height):
        row = bytearray(b"\0")
        for cell in cells[y * width:(y + 1) * width]:
            row.extend((109, 226, 202, 175 if cell else 0))
        rows.append(bytes(row))
    return (b"\x89PNG\r\n\x1a\n"
            + png_chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 6, 0, 0, 0))
            + png_chunk(b"IDAT", zlib.compress(b"".join(rows), 9))
            + png_chunk(b"IEND", b""))


def atomic_write(path: Path, data: bytes) -> None:
    handle, temp = tempfile.mkstemp(prefix=".seed-layer-", dir=path.parent)
    try:
        with os.fdopen(handle, "wb") as target:
            target.write(data)
            target.flush()
            os.fsync(target.fileno())
        os.replace(temp, path)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def expand_map_extent(path: Path, radius: int) -> None:
    if not path.is_file():
        return
    source = path.read_text(encoding="utf-8")
    expected = {"minRegionX": -radius // 512, "minRegionZ": -radius // 512,
                "maxRegionX": math.ceil(radius / 512) - 1, "maxRegionZ": math.ceil(radius / 512) - 1}
    updated = source
    for field, limit in expected.items():
        pattern = rf"(\b{field}:\s*)(-?\d+)"
        matches = list(re.finditer(pattern, updated))
        if len(matches) != 1:
            raise ValueError(f"Arquivo do mapa não contém um único campo {field}.")
        current = int(matches[0].group(2))
        value = min(current, limit) if field.startswith("min") else max(current, limit)
        updated = re.sub(pattern, lambda match: match.group(1) + str(value), updated, count=1)
    if updated != source:
        atomic_write(path, updated.encode("utf-8"))


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--instance-id", required=True)
    parser.add_argument("--seed", required=True)
    parser.add_argument("--ppm", type=Path, required=True)
    parser.add_argument("--slime", type=Path, required=True)
    parser.add_argument("--json", type=Path, required=True)
    parser.add_argument("--dungeons", type=Path)
    parser.add_argument("--console-root", type=Path, required=True)
    parser.add_argument("--server-version", default="")
    args = parser.parse_args()
    if not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,31}", args.instance_id):
        parser.error("ID de instância inválido.")
    if not re.fullmatch(r"-?\d{1,19}", args.seed) or not -(2**63) <= int(args.seed) < 2**63:
        parser.error("Seed precisa ser um inteiro de 64 bits.")
    raw_layer = args.json.read_bytes()
    info = json.loads(raw_layer)
    raw_dungeons = b""
    if args.dungeons:
        raw_dungeons = args.dungeons.read_bytes()
        dungeon_data = json.loads(raw_dungeons)
        coverage = dungeon_data.get("coverage")
        allowed = {
            "dungeon_zombie": "Masmorra · zumbi",
            "dungeon_skeleton": "Masmorra · esqueleto",
            "dungeon_spider": "Masmorra · aranha",
        }
        if (dungeon_data.get("schema_version") != 1 or dungeon_data.get("seed") != args.seed or
                not isinstance(coverage, dict) or not isinstance(dungeon_data.get("points"), list) or
                not all(isinstance(coverage.get(key), int) for key in ("center_x", "center_z", "radius")) or
                not 16 <= coverage["radius"] <= 32768 or len(dungeon_data["points"]) > 500000):
            raise ValueError("Dados de masmorras inválidos.")
        for point in dungeon_data["points"]:
            if (not isinstance(point, dict) or point.get("type") not in allowed or
                    point.get("name") != allowed[point["type"]] or
                    not all(isinstance(point.get(axis), int) for axis in ("x", "y", "z")) or
                    not -64 <= point["y"] <= 320 or
                    not coverage["center_x"] - coverage["radius"] <= point["x"] < coverage["center_x"] + coverage["radius"] or
                    not coverage["center_z"] - coverage["radius"] <= point["z"] < coverage["center_z"] + coverage["radius"]):
                raise ValueError("Masmorra fora da área calculada.")
        info["structures"].extend(dungeon_data["points"])
        info["types"].extend({"type": type_id, "name": label} for type_id, label in allowed.items())
        info["dungeon_coverage"] = coverage
    width = info.get("width")
    height = info.get("height")
    radius = -info.get("min_x", 0)
    if (info.get("schema_version") != 1 or info.get("edition") != "bedrock" or
            info.get("generator_version") != "26.60" or info.get("seed") != args.seed or
            info.get("dimension") != "overworld" or
            not isinstance(width, int) or not isinstance(height, int) or
            width != height or width < 64 or width > 4096 or radius != width * 8 or
            info.get("min_z") != -radius or info.get("step") != 16 or
            not isinstance(info.get("biomes"), list) or not isinstance(info.get("structures"), list) or
            not isinstance(info.get("slime_chunks"), int) or not 0 <= info["slime_chunks"] <= width * height or
            len(info["structures"]) > 2500000 or
            not isinstance(info.get("types"), list) or len(info["types"]) > 64):
        raise ValueError("Metadados da seed inválidos ou incompatíveis.")
    if any(not isinstance(item, dict) or not isinstance(item.get("type"), str) or
           not isinstance(item.get("name"), str) for item in info["types"]):
        raise ValueError("Catálogo de locais inválido.")
    for structure in info["structures"]:
        if (not isinstance(structure, dict) or not isinstance(structure.get("type"), str) or
                not isinstance(structure.get("name"), str) or
                not isinstance(structure.get("x"), int) or not isinstance(structure.get("z"), int) or
                abs(structure["x"]) > radius or abs(structure["z"]) > radius):
            raise ValueError("Estrutura inválida no arquivo de previsão.")
    png = ppm_to_png(args.ppm, width, height)
    slime_png = slime_to_png(args.slime, width, height, info["slime_chunks"])
    revision = hashlib.sha256(png).hexdigest()[:24]
    slime_revision = hashlib.sha256(slime_png).hexdigest()[:24]
    info.update({
        "instance_id": args.instance_id,
        "server_version": args.server_version,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "image_revision": revision,
        "slime_revision": slime_revision,
    })
    storage = args.console_root / "storage"
    if not storage.is_dir():
        raise FileNotFoundError(f"Pasta privada ausente: {storage}")
    tile_size = 2048
    tile_revision = hashlib.sha256(raw_layer + raw_dungeons).hexdigest()[:24]
    tile_root = storage / f"seed-tiles-{args.instance_id}-{tile_revision}"
    tiles: dict[tuple[int, int], list[dict]] = {}
    counts: dict[str, int] = {}
    for point in info["structures"]:
        key = (point["x"] // tile_size, point["z"] // tile_size)
        tiles.setdefault(key, []).append(point)
        counts[point["type"]] = counts.get(point["type"], 0) + 1
    tile_root.mkdir(exist_ok=True)
    for (tile_x, tile_z), points in tiles.items():
        path = tile_root / f"{tile_x}_{tile_z}.json"
        if not path.exists():
            atomic_write(path, json.dumps(points, ensure_ascii=False, separators=(",", ":")).encode("utf-8"))
    info["structure_total"] = len(info["structures"])
    info["structure_counts"] = counts
    info["tile_size"] = tile_size
    info["tile_revision"] = tile_revision
    info["structures"] = []
    image = storage / f"seed-layer-{args.instance_id}-{revision}.png"
    slime_image = storage / f"slime-layer-{args.instance_id}-{slime_revision}.png"
    metadata = storage / f"seed-layer-{args.instance_id}.json"
    if not image.exists():
        atomic_write(image, png)
    if not slime_image.exists():
        atomic_write(slime_image, slime_png)
    atomic_write(metadata, json.dumps(info, ensure_ascii=False, separators=(",", ":")).encode("utf-8"))
    expand_map_extent(storage / "maps" / args.instance_id / "unmined.map.properties.js", radius)
    print(f"Dados da seed publicados para {args.instance_id}: {len(info['biomes'])} biomas, {info['structure_total']} locais em {len(tiles)} setores, {info['slime_chunks']} slime chunks; raio {radius} blocos.")


if __name__ == "__main__":
    main()
