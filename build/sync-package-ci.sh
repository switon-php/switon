#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKFLOW_TEMPLATE="$REPO_ROOT/build/templates/package-workflow-ci.yml"

usage() {
    cat <<'EOF'
Usage:
  bash build/sync-package-ci.sh [package...]

Syncs split-repo CI assets into package subtrees so subtree splits inherit:
  - .github/workflows/ci.yml
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    usage
    exit 0
fi

sync_package() {
    local package_dir="$1"

    [[ -d "$package_dir" ]] || return 0
    [[ -f "$package_dir/composer.json" ]] || return 0

    mkdir -p "$package_dir/.github/workflows"
    cp "$WORKFLOW_TEMPLATE" "$package_dir/.github/workflows/ci.yml"
    rm -f "$package_dir/build/release-check.sh"
    rmdir "$package_dir/build" 2>/dev/null || true
}

if (($# > 0)); then
    for package in "$@"; do
        sync_package "$REPO_ROOT/packages/$package"
    done
    exit 0
fi

for package_dir in "$REPO_ROOT"/packages/*; do
    sync_package "$package_dir"
done
