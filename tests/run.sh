#!/usr/bin/env bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHPUNIT_BIN="$REPO_ROOT/vendor/bin/phpunit"

usage() {
    cat <<'EOF'
Usage:
  bash tests/run.sh <package> [unit|integration|all|--fast|--coverage|--silent] [-- <extra phpunit args>]
  bash tests/run.sh --all|-a [unit|integration|all|--fast|--coverage|--silent] [-- <extra phpunit args>]

Examples:
  bash tests/run.sh core
  bash tests/run.sh router --fast
  bash tests/run.sh http --coverage
  bash tests/run.sh db -- --filter QueryBuilderTest
  bash tests/run.sh --all --fast
  bash tests/run.sh --all --fast --silent
EOF
}

fail() {
    printf 'run-tests: %s\n' "$1" >&2
    exit 1
}

php_without_deprecations() {
    php -d xdebug.mode=off -d error_reporting=6143 "$@"
}

phpunit_version() {
    php_without_deprecations "$PHPUNIT_BIN" --version
}

has_silent_flag() {
    while (($# > 0)); do
        if [[ "$1" == "--" ]]; then
            break
        fi
        if [[ "$1" == "--silent" || "$1" == "-s" ]]; then
            return 0
        fi
        shift
    done
    return 1
}

# Run PHPUnit for one package; echoes banner when label is non-empty (e.g. monorepo --all).
run_one_package() {
    local label="${1:-}"
    local PACKAGE_NAME="$2"
    shift 2

    local PACKAGE_ROOT="$REPO_ROOT/packages/$PACKAGE_NAME"
    local TESTS_DIR="$PACKAGE_ROOT/tests"
    local PHPUNIT_XML="$TESTS_DIR/phpunit.xml.dist"

    [[ -d "$PACKAGE_ROOT" ]] || fail "package directory not found: $PACKAGE_ROOT"
    [[ -f "$PHPUNIT_XML" ]] || fail "missing PHPUnit config: $PHPUNIT_XML"

    if [[ ! -f "$PHPUNIT_BIN" ]]; then
        fail "PHPUnit not found at $PHPUNIT_BIN. Please run \"composer install\" in the repository root."
    fi

    local PHPUNIT_ARGS=(
        "--configuration" "$PHPUNIT_XML"
        "--bootstrap" "$REPO_ROOT/tests/bootstrap.php"
        "--display-deprecations"
        "--display-phpunit-notices"
    )
    local SILENT_MODE=0

    while (($# > 0)); do
        if [[ "$1" == "--" ]]; then
            shift
            break
        fi
        case "$1" in
            unit|all|integration|eval|live)
                PHPUNIT_ARGS+=("--testsuite=$1")
                shift
                ;;
            --fast|-f)
                if grep -q 'name="fast"' "$PHPUNIT_XML" 2>/dev/null; then
                    PHPUNIT_ARGS+=("--testsuite=fast" "--exclude-group=coroutine")
                else
                    PHPUNIT_ARGS+=("--testsuite=unit")
                fi
                shift
                ;;
            --coverage|-c)
                local COVERAGE_DIR="$TESTS_DIR/coverage"
                mkdir -p "$COVERAGE_DIR"
                export XDEBUG_MODE=coverage
                PHPUNIT_ARGS+=("--coverage-html" "$COVERAGE_DIR")
                shift
                ;;
            --silent|-s)
                SILENT_MODE=1
                shift
                ;;
            *)
                break
                ;;
        esac
    done

    PHPUNIT_ARGS+=("$@")

    if (( SILENT_MODE == 0 )) && [[ -n "$label" ]]; then
        printf '\n========== %s ==========\n' "$label"
    fi
    if (( SILENT_MODE == 0 )) && [[ -z "${RUN_SH_BATCH:-}" ]]; then
        printf 'Using PHPUnit: %s\n' "$(phpunit_version)"
    fi
    if (( SILENT_MODE == 0 )); then
        printf 'Package: %s\n' "$PACKAGE_NAME"
        printf 'Executing: %s %s\n\n' "$PHPUNIT_BIN" "${PHPUNIT_ARGS[*]}"
    fi

    cd "$PACKAGE_ROOT"
    if (( SILENT_MODE == 1 )); then
        local output_file
        output_file="$(mktemp "${TMPDIR:-/tmp}/switon-run-sh-${PACKAGE_NAME}.XXXXXX")"
        if [[ "${XDEBUG_MODE:-}" == "coverage" ]]; then
            if "$PHPUNIT_BIN" "${PHPUNIT_ARGS[@]}" >"$output_file" 2>&1; then
                rm -f "$output_file"
                return 0
            fi
        else
            if php_without_deprecations "$PHPUNIT_BIN" "${PHPUNIT_ARGS[@]}" >"$output_file" 2>&1; then
                rm -f "$output_file"
                return 0
            fi
        fi
        printf '[FAIL] %s\n' "$PACKAGE_NAME" >&2
        cat "$output_file" >&2
        rm -f "$output_file"
        return 1
    elif [[ "${XDEBUG_MODE:-}" == "coverage" ]]; then
        "$PHPUNIT_BIN" "${PHPUNIT_ARGS[@]}"
    else
        php_without_deprecations "$PHPUNIT_BIN" "${PHPUNIT_ARGS[@]}"
    fi
}

PACKAGE_INPUT="${1:-}"
if [[ -z "$PACKAGE_INPUT" || "$PACKAGE_INPUT" == "-h" || "$PACKAGE_INPUT" == "--help" ]]; then
    usage
    exit 0
fi
shift

if [[ "$PACKAGE_INPUT" == "--all" || "$PACKAGE_INPUT" == "-a" ]]; then
    if [[ ! -f "$PHPUNIT_BIN" ]]; then
        fail "PHPUnit not found at $PHPUNIT_BIN. Please run \"composer install\" in the repository root."
    fi
    ALL_SILENT=0
    if has_silent_flag "$@"; then
        ALL_SILENT=1
    fi
    if (( ALL_SILENT == 0 )); then
        printf 'Using PHPUnit: %s\n' "$(phpunit_version)"
        printf 'Running all packages with tests/phpunit.xml.dist\n\n'
    fi

    shopt -s nullglob
    failed=0
    failed_pkgs=()
    ok_pkgs=()
    skipped_pkgs=()
    sorted_dirs="$(printf '%s\n' "$REPO_ROOT"/packages/*/ | sort)"

    export RUN_SH_BATCH=1
    pkg_dir=""
    pkg=""
    while IFS= read -r pkg_dir; do
        [[ -n "$pkg_dir" ]] || continue
        pkg_dir="${pkg_dir%/}"
        pkg="$(basename "$pkg_dir")"
        if [[ ! -f "$pkg_dir/tests/phpunit.xml.dist" ]]; then
            skipped_pkgs+=("$pkg")
            if (( ALL_SILENT == 1 )); then
                printf '[SKIP] %s\n' "$pkg"
            fi
            continue
        fi
        if ! run_one_package "$pkg" "$pkg" "$@"; then
            failed=1
            failed_pkgs+=("$pkg")
        else
            ok_pkgs+=("$pkg")
            if (( ALL_SILENT == 1 )); then
                printf '[OK] %s\n' "$pkg"
            fi
        fi
    done <<<"$sorted_dirs"
    unset RUN_SH_BATCH

    if (( ALL_SILENT == 1 )); then
        if (( failed == 0 )); then
            printf 'run-tests: all %d package(s) passed\n' "${#ok_pkgs[@]}"
        fi
    elif ((${#skipped_pkgs[@]} > 0)); then
        printf '\nSkipped %d package(s) without tests/phpunit.xml.dist: %s\n\n' "${#skipped_pkgs[@]}" "${skipped_pkgs[*]}"
    fi

    if ((failed)); then
        printf '\nrun-tests: %d package(s) failed: %s\n' "${#failed_pkgs[@]}" "${failed_pkgs[*]}" >&2
        exit 1
    fi
    exit 0
fi

if [[ "$PACKAGE_INPUT" == packages/* ]]; then
    PACKAGE_NAME="${PACKAGE_INPUT#packages/}"
else
    PACKAGE_NAME="$PACKAGE_INPUT"
fi

run_one_package "" "$PACKAGE_NAME" "$@"
