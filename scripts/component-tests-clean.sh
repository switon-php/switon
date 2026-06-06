#!/usr/bin/env bash
# Remove Git-untracked artifacts under component packages:
#   packages/*/tests   — test trees (coverage, caches, …)
#   packages/*/vendor — per-package Composer installs (usually gitignored)
#
# Usage (from repo root):
#   bash scripts/component-tests-clean.sh                  # untracked only (ignored paths stay)
#   bash scripts/component-tests-clean.sh --dry-run         # preview (-n)
#   bash scripts/component-tests-clean.sh --all-untracked   # + ignored (coverage/, vendor/, …)
#
set -euo pipefail

usage() {
	cat >&2 <<'USAGE'
usage: bash scripts/component-tests-clean.sh [--dry-run] [--all-untracked]

  --dry-run, -n    Show what would be removed (git clean -n).
  --all-untracked, -x
                   Also remove ignored-but-present files under the scoped paths (-x),
                   e.g. tests/coverage, .phpunit.result.cache, packages/*/vendor.
  --help, -h       Show this message.
USAGE
}

dry_run=false
extra_git_clean_opts=()

while [[ $# -gt 0 ]]; do
	case "$1" in
		-n | --dry-run)
			dry_run=true
			shift
			;;
		-x | --all-untracked)
			extra_git_clean_opts+=(-x)
			shift
			;;
		-h | --help)
			usage
			exit 0
			;;
		*)
			printf '%s\n' "Unknown option: $1" >&2
			usage
			exit 2
			;;
	esac
done

if ! repo_root="$(git rev-parse --show-toplevel 2>/dev/null)"; then
	printf '%s\n' 'Not inside a Git repository.' >&2
	exit 1
fi

cd "$repo_root"

shopt -s nullglob
tests_dirs=(packages/*/tests)
vendor_dirs=(packages/*/vendor)
shopt -u nullglob

clean_paths=("${tests_dirs[@]}" "${vendor_dirs[@]}")
if [[ ${#clean_paths[@]} -eq 0 ]]; then
	printf '%s\n' 'No directories matched packages/*/tests or packages/*/vendor.' >&2
	exit 0
fi

# -ff: also delete untracked dirs that are nested Git repos (common under packages/*/vendor).
clean_args=(-ff -d)
if [[ ${#extra_git_clean_opts[@]} -gt 0 ]]; then
	clean_args+=("${extra_git_clean_opts[@]}")
fi
if [[ "$dry_run" == true ]]; then
	clean_args+=(-n)
fi

# git clean scopes to the listed paths only (never repo-wide)
git clean "${clean_args[@]}" -- "${clean_paths[@]}"
