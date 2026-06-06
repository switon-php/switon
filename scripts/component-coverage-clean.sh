#!/usr/bin/env bash
# Remove PHPUnit coverage output under packages/*/tests/coverage (gitignored).
#
# Usage (from repo root):
#   bash scripts/component-coverage-clean.sh
#   bash scripts/component-coverage-clean.sh --dry-run
#
set -euo pipefail

usage() {
	cat >&2 <<'USAGE'
usage: bash scripts/component-coverage-clean.sh [--dry-run]

  --dry-run, -n    Show what would be removed (git clean -n).
  --help, -h       Show this message.
USAGE
}

dry_run=false

while [[ $# -gt 0 ]]; do
	case "$1" in
		-n | --dry-run)
			dry_run=true
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
coverage_dirs=(packages/*/tests/coverage)
shopt -u nullglob

if [[ ${#coverage_dirs[@]} -eq 0 ]]; then
	printf '%s\n' 'No directories matched packages/*/tests/coverage.' >&2
	exit 0
fi

clean_args=(-ff -d -x)
if [[ "$dry_run" == true ]]; then
	clean_args+=(-n)
fi

git clean "${clean_args[@]}" -- "${coverage_dirs[@]}"
