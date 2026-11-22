#!/usr/bin/env python3
"""Publish the initial MineDev seed layer using the Home Assistant add-on CPU."""

import argparse
from pathlib import Path
import re
import subprocess
import sys
import tempfile


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--instance-id", required=True)
    parser.add_argument("--seed", required=True)
    parser.add_argument("--radius", type=int, default=32768)
    parser.add_argument("--storage", type=Path, default=Path("/share/htdocs/minedev/storage"))
    parser.add_argument("--server-version", default="")
    parser.add_argument("--replace", action="store_true", help="replace an existing seed layer")
    args = parser.parse_args()
    if not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,31}", args.instance_id):
        parser.error("ID de instância inválido")
    if not re.fullmatch(r"-?\d{1,19}", args.seed) or not -(2**63) <= int(args.seed) < 2**63:
        parser.error("seed fora do intervalo de 64 bits")
    if not 512 <= args.radius <= 32768 or args.radius % 16:
        parser.error("raio deve ser múltiplo de 16 entre 512 e 32768")
    storage = args.storage.resolve()
    if not storage.is_dir() or storage.name != "storage":
        parser.error("pasta storage do MineDev não encontrada")
    instance = Path("/share/minecraftRCON-Multi/instances") / args.instance_id
    if not instance.is_dir():
        parser.error("instância não encontrada no add-on")
    metadata = storage / f"seed-layer-{args.instance_id}.json"
    if metadata.exists() and not args.replace:
        parser.error("camada da seed já existe; use --replace para substituí-la")
    with tempfile.TemporaryDirectory(prefix="minedev-seed-base-") as temporary:
        work = Path(temporary)
        ppm, manifest, slime = (work / name for name in ("biomes.ppm", "layer.json", "slime.pgm"))
        subprocess.run(["/usr/local/bin/minedev-seed-base", args.seed, str(args.radius),
                        str(ppm), str(manifest), str(slime), "base-only"], check=True)
        subprocess.run([sys.executable, "/publish_seed_layer.py", "--instance-id", args.instance_id,
                        "--seed", args.seed, "--ppm", str(ppm), "--slime", str(slime),
                        "--json", str(manifest), "--console-root", str(storage.parent),
                        "--server-version", args.server_version], check=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
