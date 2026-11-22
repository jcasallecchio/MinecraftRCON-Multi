#!/usr/bin/env python3
"""Keep the optional web add-on's packaged console equal to tracked console/."""

import argparse
import hashlib
from pathlib import Path
import shutil
import subprocess
import sys


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--write", action="store_true", help="synchronize tracked console files")
    args = parser.parse_args()
    root = Path(__file__).resolve().parent.parent
    source = root / "console"
    target = root / "MineDevWeb" / "console"
    if target.resolve() != (root / "MineDevWeb" / "console").resolve():
        raise RuntimeError("Unexpected destination path")
    tracked = subprocess.check_output(["git", "ls-files", "-z", "--", "console"], cwd=root)
    names = {Path(value.decode("utf-8")).relative_to("console") for value in tracked.split(b"\0") if value}
    if args.write:
        target.mkdir(parents=True, exist_ok=True)
        for relative in names:
            destination = target / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(source / relative, destination)
        for extra in target.rglob("*"):
            if extra.is_file() and extra.relative_to(target) not in names:
                extra.unlink()
        for directory in sorted((p for p in target.rglob("*") if p.is_dir()), key=lambda p: len(p.parts), reverse=True):
            if not any(directory.iterdir()):
                directory.rmdir()
    actual = {path.relative_to(target) for path in target.rglob("*") if path.is_file()} if target.exists() else set()
    if names != actual:
        print(f"Console copy differs: missing={sorted(names - actual)}, extra={sorted(actual - names)}", file=sys.stderr)
        return 1
    for relative in sorted(names):
        digest_source = hashlib.sha256((source / relative).read_bytes()).digest()
        digest_target = hashlib.sha256((target / relative).read_bytes()).digest()
        if digest_source != digest_target:
            print(f"Console copy differs: {relative}", file=sys.stderr)
            return 1
    print(f"MineDev Web copy matches {len(names)} tracked console files.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
