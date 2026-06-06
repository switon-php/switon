#!/usr/bin/env python3
"""
Audit Markdown links in one or more documentation trees.

Checks local relative links, fragment-only links, and optional external HTTP(S) links.
Exit 0 only if no broken links are found.

Usage (from repository root):
  python3 scripts/docs/markdown_link_audit.py --tree docs/en
  python3 scripts/docs/markdown_link_audit.py --tree docs/ja
  python3 scripts/docs/markdown_link_audit.py --tree docs --check-external
"""

from __future__ import annotations

import argparse
import html
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from pathlib import Path

COMMENT_RE = re.compile(r"<!--.*?-->", re.S)
INLINE_CODE_RE = re.compile(r"`[^`\n]*`")
EXPLICIT_ANCHOR_RE = re.compile(r"\{#([^}]+)\}")
HEADING_RE = re.compile(r"^(#{1,6})\s+(.*?)\s*$", re.M)
TAG_RE = re.compile(r"<[^>]+>")
LINK_TEXT_MARKUP_RE = re.compile(r"[*_~]+")
MARKDOWN_LINK_IN_TEXT_RE = re.compile(r"\[([^\]]+)\]\([^)]*\)")
FENCE_START_RE = re.compile(r"^([`~]{3,})")
ALLOWED_SKIP_SCHEMES = {"mailto", "tel"}
ALLOWED_EXTERNAL_SCHEMES = {"http", "https"}


@dataclass(frozen=True)
class LinkRef:
    source: Path
    line: int
    target: str
    raw: str


@dataclass(frozen=True)
class Issue:
    code: str
    source: Path
    line: int
    target: str
    details: str

    def render(self, repo_root: Path) -> str:
        rel_source = _rel(self.source, repo_root)
        base = f"{self.code}: {rel_source}:{self.line} -> {self.target}"
        if self.details:
            return f"{base}\n  {self.details}"
        return base


class AnchorIndex:
    def __init__(self) -> None:
        self._cache: dict[Path, set[str]] = {}

    def get(self, path: Path) -> set[str]:
        path = path.resolve()
        if path not in self._cache:
            self._cache[path] = _collect_anchors(path)
        return self._cache[path]


def _read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


def _rel(path: Path, repo_root: Path) -> str:
    try:
        return path.resolve().relative_to(repo_root.resolve()).as_posix()
    except ValueError:
        return path.resolve().as_posix()


def _sanitize_markdown(text: str) -> str:
    text = COMMENT_RE.sub("", text)
    text = INLINE_CODE_RE.sub("", text)

    lines: list[str] = []
    in_fence = False
    fence_char = ""
    fence_len = 0

    for line in text.splitlines(keepends=True):
        stripped = line.lstrip()
        match = FENCE_START_RE.match(stripped)
        if match:
            marker = match.group(1)
            if not in_fence:
                in_fence = True
                fence_char = marker[0]
                fence_len = len(marker)
                lines.append("\n" if line.endswith("\n") else "")
                continue
            if marker[0] == fence_char and len(marker) >= fence_len:
                in_fence = False
                fence_char = ""
                fence_len = 0
                lines.append("\n" if line.endswith("\n") else "")
                continue

        if in_fence:
            lines.append("\n" if line.endswith("\n") else "")
        else:
            lines.append(line)

    return "".join(lines)


def _extract_link_target(raw_inside: str) -> str | None:
    inner = raw_inside.strip()
    if not inner:
        return None

    if inner.startswith("<"):
        end = inner.find(">")
        if end == -1:
            return None
        return inner[1:end].strip() or None

    quote = None
    result_chars: list[str] = []
    for ch in inner:
        if quote:
            if ch == quote:
                quote = None
            result_chars.append(ch)
            continue
        if ch in {"'", '"'}:
            quote = ch
            result_chars.append(ch)
            continue
        if ch.isspace():
            break
        result_chars.append(ch)

    target = "".join(result_chars).strip()
    return target or None


def _find_closing_bracket(text: str, start: int) -> int:
    escaped = False
    for i in range(start, len(text)):
        ch = text[i]
        if escaped:
            escaped = False
            continue
        if ch == "\\":
            escaped = True
            continue
        if ch == "]":
            return i
    return -1


def _find_matching_paren(text: str, start: int) -> int:
    depth = 1
    escaped = False
    for i in range(start, len(text)):
        ch = text[i]
        if escaped:
            escaped = False
            continue
        if ch == "\\":
            escaped = True
            continue
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
            if depth == 0:
                return i
    return -1


def _iter_links(path: Path, include_images: bool) -> list[LinkRef]:
    text = _sanitize_markdown(_read(path))
    out: list[LinkRef] = []
    i = 0
    while i < len(text):
        if text[i] != "[":
            i += 1
            continue

        image = i > 0 and text[i - 1] == "!"
        closing = _find_closing_bracket(text, i + 1)
        if closing == -1 or closing + 1 >= len(text) or text[closing + 1] != "(":
            i += 1
            continue

        end = _find_matching_paren(text, closing + 2)
        if end == -1:
            i += 1
            continue

        if not image or include_images:
            target = _extract_link_target(text[closing + 2 : end])
            if target is not None:
                out.append(
                    LinkRef(
                        source=path,
                        line=text.count("\n", 0, i) + 1,
                        target=target,
                        raw=text[i : end + 1],
                    )
                )
        i = end + 1

    return out


def _normalize_heading_text(text: str) -> str:
    text = EXPLICIT_ANCHOR_RE.sub("", text)
    text = MARKDOWN_LINK_IN_TEXT_RE.sub(r"\1", text)
    text = TAG_RE.sub("", text)
    text = text.replace("\\", "")
    text = LINK_TEXT_MARKUP_RE.sub("", text)
    text = html.unescape(text)
    return " ".join(text.strip().split())


def _slugify_heading(text: str) -> str:
    normalized = _normalize_heading_text(text).lower()
    chars: list[str] = []
    for ch in normalized:
        if ch.isalnum() or ch in {"-", "_", " ", "\t"}:
            chars.append(ch)
    slug = "".join(chars).strip().replace(" ", "-").replace("\t", "-")
    slug = re.sub(r"-+", lambda m: "-" * len(m.group(0)), slug)
    return slug.strip("-")


def _collect_anchors(path: Path) -> set[str]:
    text = _read(path)
    anchors: set[str] = set(EXPLICIT_ANCHOR_RE.findall(text))
    seen: dict[str, int] = {}

    for match in HEADING_RE.finditer(text):
        slug = _slugify_heading(match.group(2))
        if not slug:
            continue
        idx = seen.get(slug, 0)
        seen[slug] = idx + 1
        anchors.add(slug if idx == 0 else f"{slug}-{idx}")

    return anchors


def _resolve_local_path(source: Path, raw_target: str, repo_root: Path) -> tuple[Path, str | None]:
    path_part, hash_mark, fragment = raw_target.partition("#")
    fragment = urllib.parse.unquote(fragment) if hash_mark else None
    decoded_path = urllib.parse.unquote(path_part)

    if not decoded_path:
        return source.resolve(), fragment

    if decoded_path.startswith("/en/"):
        candidate = (repo_root.resolve() / "docs" / decoded_path.lstrip("/")).resolve()
    elif decoded_path == "/en":
        candidate = (repo_root.resolve() / "docs" / "en").resolve()
    elif decoded_path.startswith("/"):
        candidate = (repo_root.resolve() / decoded_path.lstrip("/")).resolve()
    else:
        candidate = (source.parent.resolve() / decoded_path).resolve()

    if candidate.is_dir():
        readme = candidate / "README.md"
        if readme.exists():
            candidate = readme.resolve()

    return candidate, fragment


def _check_external(url: str, timeout: float) -> str | None:
    for method in ("HEAD", "GET"):
        try:
            request = urllib.request.Request(url, method=method)
            with urllib.request.urlopen(request, timeout=timeout) as response:
                status = getattr(response, "status", 200)
                if status >= 400:
                    return f"HTTP {status}"
                return None
        except urllib.error.HTTPError as exc:
            if method == "HEAD" and exc.code in {403, 405, 429}:
                continue
            return f"HTTP {exc.code}"
        except urllib.error.URLError as exc:
            return str(exc.reason)
        except Exception as exc:  # pragma: no cover - defensive surface
            return str(exc)
    return None


def _audit_local_link(
    link: LinkRef,
    repo_root: Path,
    anchor_index: AnchorIndex,
) -> list[Issue]:
    issues: list[Issue] = []
    target_path, fragment = _resolve_local_path(link.source, link.target, repo_root)

    if not target_path.exists():
        issues.append(
            Issue(
                code="MISSING_FILE",
                source=link.source,
                line=link.line,
                target=link.target,
                details=f"resolved path: {_rel(target_path, repo_root)}",
            )
        )
        return issues

    if target_path.is_dir():
        issues.append(
            Issue(
                code="TARGET_IS_DIRECTORY",
                source=link.source,
                line=link.line,
                target=link.target,
                details=f"resolved path: {_rel(target_path, repo_root)}",
            )
        )
        return issues

    if fragment:
        anchors = anchor_index.get(target_path)
        if fragment not in anchors:
            preview = ", ".join(sorted(anchors)[:8])
            if len(anchors) > 8:
                preview += ", ..."
            issues.append(
                Issue(
                    code="MISSING_ANCHOR",
                    source=link.source,
                    line=link.line,
                    target=link.target,
                    details=(
                        f"resolved file: {_rel(target_path, repo_root)}; "
                        f"available anchors: [{preview}]"
                    ),
                )
            )

    return issues


def _iter_markdown_files(tree: Path) -> list[Path]:
    if tree.is_file():
        return [tree.resolve()] if tree.suffix.lower() == ".md" else []
    return sorted(p.resolve() for p in tree.rglob("*.md"))


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument(
        "--repo-root",
        type=Path,
        default=Path("."),
        help="Repository root (default: current directory)",
    )
    ap.add_argument(
        "--tree",
        action="append",
        dest="trees",
        help="Markdown tree or file relative to repo root; repeatable (default: docs)",
    )
    ap.add_argument(
        "--include-images",
        action="store_true",
        help="Also audit Markdown image links",
    )
    ap.add_argument(
        "--check-external",
        action="store_true",
        help="Also check external HTTP(S) links with network requests",
    )
    ap.add_argument(
        "--external-timeout",
        type=float,
        default=5.0,
        help="Timeout in seconds for external HTTP(S) checks (default: 5.0)",
    )
    args = ap.parse_args()

    repo_root = args.repo_root.resolve()
    tree_args = args.trees or ["docs"]
    trees = [(repo_root / tree).resolve() for tree in tree_args]

    errors: list[Issue] = []
    files: list[Path] = []
    for tree in trees:
        if not tree.exists():
            print(f"ERROR: tree not found: {tree}", file=sys.stderr)
            return 2
        files.extend(_iter_markdown_files(tree))

    files = sorted(set(files))
    anchor_index = AnchorIndex()
    external_cache: dict[str, str | None] = {}
    total_links = 0
    skipped_external = 0

    for file_path in files:
        for link in _iter_links(file_path, include_images=args.include_images):
            total_links += 1
            target = link.target.strip()
            parsed = urllib.parse.urlsplit(target)
            scheme = parsed.scheme.lower()

            if scheme in ALLOWED_SKIP_SCHEMES:
                continue
            if scheme in ALLOWED_EXTERNAL_SCHEMES or target.startswith("//"):
                if args.check_external:
                    external_target = target if scheme else f"https:{target}"
                    result = external_cache.get(external_target)
                    if result is None and external_target not in external_cache:
                        result = _check_external(external_target, args.external_timeout)
                        external_cache[external_target] = result
                    if result:
                        errors.append(
                            Issue(
                                code="BROKEN_EXTERNAL",
                                source=link.source,
                                line=link.line,
                                target=target,
                                details=result,
                            )
                        )
                else:
                    skipped_external += 1
                continue
            if scheme and scheme not in ALLOWED_SKIP_SCHEMES:
                errors.append(
                    Issue(
                        code="UNSUPPORTED_SCHEME",
                        source=link.source,
                        line=link.line,
                        target=target,
                        details=f"scheme={scheme}",
                    )
                )
                continue

            errors.extend(_audit_local_link(link, repo_root, anchor_index))

    if errors:
        print(
            f"markdown_link_audit: FAIL ({len(errors)} issue(s), {len(files)} file(s), {total_links} link(s))",
            file=sys.stderr,
        )
        for issue in errors:
            print(issue.render(repo_root), file=sys.stderr)
        return 1

    summary = (
        f"markdown_link_audit: OK — {len(files)} file(s), {total_links} link(s), "
        f"external skipped={skipped_external}"
    )
    if args.check_external:
        summary += f", external checked={len(external_cache)}"
    print(summary)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
