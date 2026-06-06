#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

usage() {
    cat <<'EOF'
Usage:
  bash build/release-check.sh <package> [--fast] [-- <extra test args>]

Examples:
  bash build/release-check.sh router
  bash build/release-check.sh packages/router --fast
  bash build/release-check.sh http -- --filter RequestHandlerTest

Checks:
  - package directory exists
  - package composer.json exists
  - composer metadata validates
  - package PHPStan runs when configured
  - package tests run through the monorepo test entry (ordinary packages)
EOF
}

fail() {
    printf 'release-check: %s\n' "$1" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command not found: $1"
}

composer_without_deprecations() {
    php -d xdebug.mode=off -d error_reporting=0 -d display_errors=0 "$(command -v composer)" "$@"
}

php_without_deprecations() {
    php -d xdebug.mode=off -d error_reporting=6143 "$@"
}

resolve_phpstan_bin() {
    if [[ -x "$REPO_ROOT/vendor/bin/phpstan" ]]; then
        printf '%s\n' "$REPO_ROOT/vendor/bin/phpstan"
        return 0
    fi

    local candidate
    for candidate in "$REPO_ROOT"/packages/*/vendor/bin/phpstan; do
        if [[ -x "$candidate" ]]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done

    return 1
}

normalize_package_dir() {
    local input="$1"

    if [[ "$input" == packages/* ]]; then
        printf '%s/%s\n' "$REPO_ROOT" "$input"
        return
    fi

    printf '%s/packages/%s\n' "$REPO_ROOT" "$input"
}

PACKAGE_INPUT="${1:-}"
if [[ -z "$PACKAGE_INPUT" ]]; then
    usage
    exit 1
fi

if [[ "$PACKAGE_INPUT" == "-h" || "$PACKAGE_INPUT" == "--help" ]]; then
    usage
    exit 0
fi

shift

TEST_ARGS=()
while (($# > 0)); do
    case "$1" in
        --)
            shift
            while (($# > 0)); do
                TEST_ARGS+=("$1")
                shift
            done
            ;;
        *)
            TEST_ARGS+=("$1")
            shift
            ;;
    esac
done

PACKAGE_DIR="$(normalize_package_dir "$PACKAGE_INPUT")"
PACKAGE_DIR="${PACKAGE_DIR%/}"

[[ -d "$PACKAGE_DIR" ]] || fail "package directory not found: $PACKAGE_DIR"

PACKAGE_NAME="$(basename "$PACKAGE_DIR")"
PACKAGE_COMPOSER="$PACKAGE_DIR/composer.json"
TESTS_DIR="$PACKAGE_DIR/tests"
TEST_RUNNER="$REPO_ROOT/tests/run.sh"
PHPUNIT_CONFIG="$TESTS_DIR/phpunit.xml.dist"

[[ -f "$PACKAGE_COMPOSER" ]] || fail "package is not release-ready: missing composer.json in $PACKAGE_DIR"

require_command composer
require_command bash

printf '==> Release check: %s\n' "$PACKAGE_NAME"
printf '    Package: %s\n' "$PACKAGE_DIR"
printf '    Tests:   %s\n' "$TEST_RUNNER"
printf '\n'

printf '==> Composer validate\n'
if [[ "$PACKAGE_NAME" == "skeleton" ]]; then
    (
        cd "$PACKAGE_DIR"
        composer_without_deprecations validate --no-check-lock --no-plugins
    )
else
    (
        cd "$PACKAGE_DIR"
        composer_without_deprecations validate --strict --no-check-lock --no-plugins
    )
fi
printf '\n'

printf '==> PHPStan\n'
PHPSTAN_CONFIG="$PACKAGE_DIR/phpstan.neon"
if [[ ! -f "$PHPSTAN_CONFIG" ]]; then
    printf '    Skipped: no phpstan.neon\n\n'
else
    HAS_PHPSTAN_SCRIPT="$(
        php -r '
            $data = json_decode(file_get_contents($argv[1]), true);
            exit(is_array($data) && isset($data["scripts"]["phpstan"]) ? 0 : 1);
        ' "$PACKAGE_COMPOSER"
    )" || HAS_PHPSTAN_SCRIPT=1

    if [[ "$HAS_PHPSTAN_SCRIPT" -ne 0 ]]; then
        printf '    Skipped: composer.json has no phpstan script\n\n'
    else
        PHPSTAN_BIN="$(resolve_phpstan_bin)" || fail 'PHPStan not found. Run "composer install" at the repository root.'
        (
            cd "$REPO_ROOT"
            php_without_deprecations "$PHPSTAN_BIN" analyse --configuration="$PHPSTAN_CONFIG" --memory-limit=512M
        )
        printf '\n'
    fi
fi

if [[ "$PACKAGE_NAME" == "skeleton" ]]; then
    printf '==> Distribution package\n'
    printf '    %s\n' 'switon/skeleton uses the framework distribution release flow.'
    printf '    %s\n' 'Package test scaffold checks are skipped to avoid polluting the application skeleton.'
    exit 0
fi

[[ -d "$TESTS_DIR" ]] || fail "missing tests directory: $TESTS_DIR"
[[ -f "$TEST_RUNNER" ]] || fail "missing monorepo test entry: $TEST_RUNNER"
[[ -x "$TEST_RUNNER" ]] || fail "monorepo test entry is not executable: $TEST_RUNNER"
[[ -f "$PHPUNIT_CONFIG" ]] || fail "missing PHPUnit config: $PHPUNIT_CONFIG"

printf '==> Package tests\n'
if ((${#TEST_ARGS[@]} > 0)); then
    bash "$TEST_RUNNER" "$PACKAGE_NAME" "${TEST_ARGS[@]}"
else
    bash "$TEST_RUNNER" "$PACKAGE_NAME"
fi
