#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

resolve_phpstan_bin() {
    if [[ -x "$ROOT/vendor/bin/phpstan" ]]; then
        printf '%s\n' "$ROOT/vendor/bin/phpstan"
        return 0
    fi

    local candidate
    for candidate in "$ROOT"/packages/*/vendor/bin/phpstan; do
        if [[ -x "$candidate" ]]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done

    return 1
}

usage() {
    cat <<'EOF'
Usage:
  bash scripts/run-phpstan.sh <package>
  bash scripts/run-phpstan.sh --all|-a

Examples:
  bash scripts/run-phpstan.sh core
  bash scripts/run-phpstan.sh event
  bash scripts/run-phpstan.sh --all
EOF
}

fail() {
    printf 'phpstan: %s\n' "$1" >&2
    exit 1
}

run_one_package() {
    local package="$1"
    local package_root="$ROOT/packages/$package"
    local config="$package_root/phpstan.neon"

    [[ -d "$package_root" ]] || fail "package directory not found: $package_root"
    [[ -f "$config" ]] || fail "missing PHPStan config: $config"
    local phpstan_bin
    phpstan_bin="$(resolve_phpstan_bin)" || fail 'PHPStan not found. Run "composer install" at the repository root, or "composer install" in a package that requires phpstan/phpstan (e.g. packages/composer-extra).'

    [[ -f "$phpstan_bin" ]] || fail "PHPStan not found at $phpstan_bin."

    printf 'Package: %s\n' "$package"
    printf 'Executing: %s analyse --configuration %s\n\n' "$phpstan_bin" "$config"

    cd "$ROOT"
    php -d xdebug.mode=off "$phpstan_bin" analyse --configuration="$config" --memory-limit=512M
}

package_input="${1:-}"
if [[ -z "$package_input" || "$package_input" == "-h" || "$package_input" == "--help" ]]; then
    usage
    exit 0
fi
shift || true

if [[ "$package_input" == "--all" || "$package_input" == "-a" ]]; then
    failed=0
    failed_pkgs=()
    while IFS= read -r config; do
        pkg="$(basename "$(dirname "$config")")"
        if ! run_one_package "$pkg"; then
            failed=1
            failed_pkgs+=("$pkg")
        fi
        printf '\n'
    done < <(find "$ROOT/packages" -maxdepth 2 -name phpstan.neon | sort)

    if ((failed)); then
        fail "${#failed_pkgs[@]} package(s) failed: ${failed_pkgs[*]}"
    fi
    exit 0
fi

if [[ "$package_input" == packages/* ]]; then
    package_input="${package_input#packages/}"
fi

run_one_package "$package_input"
