#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

usage() {
    cat <<'EOF'
Usage:
  bash build/release-check.sh [--fast] [-- <extra test args>]

Examples:
  bash build/release-check.sh
  bash build/release-check.sh --fast
  bash build/release-check.sh -- --filter SomeTest

Checks:
  - composer.json exists
  - composer metadata validates
  - package tests run via PHPUnit config (ordinary packages)
EOF
}

fail() {
    printf 'release-check: %s\n' "$1" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command not found: $1"
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    usage
    exit 0
fi

TEST_ARGS=("$@")

PACKAGE_COMPOSER="$REPO_ROOT/composer.json"
TESTS_DIR="$REPO_ROOT/tests"
PHPUNIT_BIN="$REPO_ROOT/vendor/bin/phpunit"
PHPUNIT_CONFIG="$TESTS_DIR/phpunit.xml.dist"

[[ -f "$PACKAGE_COMPOSER" ]] || fail "missing composer.json in $REPO_ROOT"

require_command composer
require_command bash
require_command php

PACKAGE_NAME="$(
    php -r '
        $file = $argv[1];
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            fwrite(STDERR, "invalid composer.json\n");
            exit(1);
        }
        $name = $data["name"] ?? "";
        if (is_string($name) && str_contains($name, "/")) {
            $parts = explode("/", $name, 2);
            echo $parts[1];
            exit(0);
        }
        $cwd = dirname($file);
        echo basename($cwd);
    ' "$PACKAGE_COMPOSER"
)"

printf '==> Release check: %s\n' "$PACKAGE_NAME"
printf '    Package: %s\n' "$REPO_ROOT"
printf '    Tests:   %s\n' "$PHPUNIT_CONFIG"
printf '\n'

printf '==> Composer validate\n'
if [[ "$PACKAGE_NAME" == "skeleton" ]]; then
    (
        cd "$REPO_ROOT"
        composer validate --no-check-lock --no-plugins
    )
else
    (
        cd "$REPO_ROOT"
        composer validate --strict --no-check-lock --no-plugins
    )
fi
printf '\n'

if [[ "$PACKAGE_NAME" == "skeleton" ]]; then
    printf '==> Distribution package\n'
    printf '    %s\n' 'switon/skeleton uses the framework distribution release flow.'
    printf '    %s\n' 'Package test scaffold checks are skipped to avoid polluting the application skeleton.'
    exit 0
fi

[[ -d "$TESTS_DIR" ]] || fail "missing tests directory: $TESTS_DIR"
[[ -f "$PHPUNIT_CONFIG" ]] || fail "missing PHPUnit config: $PHPUNIT_CONFIG"
[[ -f "$PHPUNIT_BIN" ]] || fail "missing PHPUnit binary: $PHPUNIT_BIN"

printf '==> Package tests\n'
(
    cd "$REPO_ROOT"
    "$PHPUNIT_BIN" --configuration "$PHPUNIT_CONFIG" --display-deprecations --display-phpunit-notices "${TEST_ARGS[@]}"
)
