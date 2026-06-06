#!/usr/bin/env python3
"""
Hard gate: English docs vs a locale tree — full path parity and ordered {#...} anchor parity.

Exit 0 only if every English .md has a locale counterpart and anchor id sequences match exactly.
No sampling: every paired file is checked.

Usage (from repository root):
  python3 scripts/docs/l10n_anchor_parity.py --locale es
  python3 scripts/docs/l10n_anchor_parity.py --locale de --en-subdir docs/en
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ANCHOR_RE = re.compile(r"\{#([^}]+)\}")


def _read(p: Path) -> str:
    return p.read_text(encoding="utf-8", errors="replace")


def _anchors(text: str) -> list[str]:
    return ANCHOR_RE.findall(text)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument(
        "--repo-root",
        type=Path,
        default=Path("."),
        help="Repository root (default: current directory)",
    )
    ap.add_argument(
        "--en-subdir",
        default="docs/en",
        help="English tree relative to repo root (default: docs/en)",
    )
    ap.add_argument(
        "--locale",
        required=True,
        help="Locale segment, e.g. es → docs/es",
    )
    ap.add_argument(
        "--allow-extra-locale",
        action="store_true",
        help="Do not fail if locale has .md files with no English counterpart",
    )
    args = ap.parse_args()

    root = args.repo_root.resolve()
    en_root = (root / args.en_subdir).resolve()
    loc_root = (root / "docs" / args.locale).resolve()

    errors: list[str] = []

    if not en_root.is_dir():
        print(f"ERROR: English tree not found: {en_root}", file=sys.stderr)
        return 2
    if not loc_root.is_dir():
        print(f"ERROR: Locale tree not found: {loc_root}", file=sys.stderr)
        return 2

    en_files = sorted(en_root.rglob("*.md"))
    loc_files = sorted(loc_root.rglob("*.md"))

    en_rel = {p.relative_to(en_root) for p in en_files}
    loc_rel = {p.relative_to(loc_root) for p in loc_files}

    missing = sorted(en_rel - loc_rel)
    for rel in missing:
        errors.append(f"MISSING in locale: {args.locale}/{rel.as_posix()}")

    if not args.allow_extra_locale:
        extra = sorted(loc_rel - en_rel)
        for rel in extra:
            errors.append(f"EXTRA in locale (no English file): {args.locale}/{rel.as_posix()}")

    for rel in sorted(en_rel & loc_rel):
        en_path = en_root / rel
        loc_path = loc_root / rel
        en_a = _anchors(_read(en_path))
        loc_a = _anchors(_read(loc_path))
        if en_a != loc_a:
            errors.append(
                f"ANCHOR_MISMATCH: {rel.as_posix()}\n"
                f"  en ({len(en_a)}): {en_a[:8]}{'...' if len(en_a) > 8 else ''}\n"
                f"  {args.locale} ({len(loc_a)}): {loc_a[:8]}{'...' if len(loc_a) > 8 else ''}"
            )

    if errors:
        print(f"l10n_anchor_parity: FAIL ({len(errors)} issue(s))", file=sys.stderr)
        for e in errors:
            print(e, file=sys.stderr)
        return 1

    print(
        f"l10n_anchor_parity: OK — {len(en_files)} files, locale={args.locale!r}, "
        f"anchors checked pairwise"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
