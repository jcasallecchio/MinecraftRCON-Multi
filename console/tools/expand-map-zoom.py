"""Add crisp 2:1, 4:1 and 8:1 levels to an existing 1:1 uNmINeD export.

This preserves the current snapshot. Future snapshots should be rendered with
uNmINeD's --zoomin=3 for native high-resolution tiles.
"""

import argparse
import os
from pathlib import Path
import re
import shutil
import tempfile
import uuid

from PIL import Image


TILE_NAME = re.compile(r"tile\.(-?\d+)\.(-?\d+)\.png\Z")
MAX_ZOOM = re.compile(r"(?m)^(\s*maxZoom:\s*)0(\s*,\s*)$")


def source_tiles(map_dir: Path) -> list[tuple[Path, int, int]]:
    root = map_dir / "tiles" / "zoom.0"
    tiles = []
    for path in root.rglob("tile.*.png"):
        match = TILE_NAME.fullmatch(path.name)
        if not match:
            raise ValueError(f"Nome de tile inesperado: {path.name}")
        tiles.append((path, int(match[1]), int(match[2])))
    if not tiles:
        raise ValueError("Não há tiles de zoom 1:1 neste mapa.")
    return tiles


def write_levels(stage: Path, tiles: list[tuple[Path, int, int]]) -> dict[int, int]:
    counts = {1: 0, 2: 0, 3: 0}
    for source, tile_x, tile_z in tiles:
        with Image.open(source) as image:
            if image.size != (256, 256):
                raise ValueError(f"Tile não tem 256 × 256 pixels: {source}")
            image = image.convert("RGBA")
            for zoom in (1, 2, 3):
                scale = 1 << zoom
                blocks_per_tile = 256 // scale
                for dz in range(scale):
                    for dx in range(scale):
                        x = tile_x * scale + dx
                        z = tile_z * scale + dz
                        target = stage / f"zoom.{zoom}" / str(x // 10) / str(z // 10) / f"tile.{x}.{z}.png"
                        target.parent.mkdir(parents=True, exist_ok=True)
                        region = image.crop((dx * blocks_per_tile, dz * blocks_per_tile,
                                             (dx + 1) * blocks_per_tile, (dz + 1) * blocks_per_tile))
                        region.resize((256, 256), Image.Resampling.NEAREST).save(target, optimize=True)
                        counts[zoom] += 1
    return counts


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("map_dir", type=Path)
    args = parser.parse_args()
    map_dir = args.map_dir.resolve(strict=True)
    properties = map_dir / "unmined.map.properties.js"
    text = properties.read_text(encoding="utf-8")
    if len(MAX_ZOOM.findall(text)) != 1:
        raise ValueError("Este mapa precisa ter exatamente maxZoom: 0.")
    tiles = source_tiles(map_dir)
    tile_root = map_dir / "tiles"
    for zoom in (1, 2, 3):
        if (tile_root / f"zoom.{zoom}").exists():
            raise FileExistsError(f"Nível zoom.{zoom} já existe; nenhum arquivo foi alterado.")

    with tempfile.TemporaryDirectory(prefix="minedev-zoom-") as temporary:
        stage = Path(temporary)
        counts = write_levels(stage, tiles)
        transfer = tile_root / f".minedev-zoom-{uuid.uuid4().hex}"
        shutil.copytree(stage, transfer)
        for zoom in (1, 2, 3):
            actual = sum(1 for _ in (transfer / f"zoom.{zoom}").rglob("tile.*.png"))
            if actual != counts[zoom]:
                raise IOError(f"Transferência incompleta de zoom.{zoom}: {actual}/{counts[zoom]}")
        for zoom in (1, 2, 3):
            (transfer / f"zoom.{zoom}").rename(tile_root / f"zoom.{zoom}")
        transfer.rmdir()

    updated = MAX_ZOOM.sub(r"\g<1>3\g<2>", text)
    temporary_properties = properties.with_name(properties.name + f".{uuid.uuid4().hex}.tmp")
    temporary_properties.write_text(updated, encoding="utf-8", newline="\n")
    os.replace(temporary_properties, properties)
    print(f"Zoom 8:1 publicado em {map_dir}: " + ", ".join(f"z{zoom}={count}" for zoom, count in counts.items()))


if __name__ == "__main__":
    main()
