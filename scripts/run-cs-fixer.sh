#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP_ADMIN_SCOPE='app-admin'

resolve_cs_fixer_bin() {
    if [[ -x "$ROOT/vendor/bin/php-cs-fixer" ]]; then
        printf '%s\n' "$ROOT/vendor/bin/php-cs-fixer"
        return 0
    fi

    return 1
}

usage() {
    cat <<'EOF'
Usage:
  bash scripts/run-cs-fixer.sh [--fix] [--verbose] <package|app-admin>
  bash scripts/run-cs-fixer.sh [--fix] [--verbose] --all|-a

Examples:
  bash scripts/run-cs-fixer.sh core
  bash scripts/run-cs-fixer.sh app-admin
  bash scripts/run-cs-fixer.sh --fix app-admin
  bash scripts/run-cs-fixer.sh --all

Set CS_FIXER_VERBOSE=1 for raw PHP-CS-Fixer output (progress, legend, headers).
EOF
}

fail() {
    printf 'cs-fixer: %s\n' "$1" >&2
    exit 1
}

package_paths() {
    local package="$1"
    local package_root="$ROOT/packages/$package"

    [[ -d "$package_root" ]] || fail "package directory not found: $package_root"

    if [[ -d "$package_root/src" ]]; then
        printf '%s\n' "$package_root/src"
    fi
    if [[ -d "$package_root/tests" ]]; then
        printf '%s\n' "$package_root/tests"
    fi
}

app_admin_paths() {
    local app_admin_root="$ROOT/app-admin"

    [[ -d "$app_admin_root" ]] || fail "app-admin directory not found: $app_admin_root"

    if [[ -d "$app_admin_root/app" ]]; then
        printf '%s\n' "$app_admin_root/app"
    fi
    if [[ -d "$app_admin_root/config" ]]; then
        printf '%s\n' "$app_admin_root/config"
    fi
}

scope_paths() {
    local scope="$1"

    if [[ "$scope" == "$APP_ADMIN_SCOPE" ]]; then
        app_admin_paths
        return 0
    fi

    package_paths "$scope"
}

report_cs_fixer_json() {
    local scope="$1"
    local fix_mode="$2"
    local json="$3"
    local count

    count="$(CS_FIXER_JSON="$json" php -d xdebug.mode=off -r '
        $data = json_decode(getenv("CS_FIXER_JSON") ?: "", true);
        echo is_array($data) && isset($data["files"]) ? count($data["files"]) : 0;
    ')"

    if ((count == 0)); then
        printf '%-18s ok\n' "$scope"
        return 0
    fi

    if [[ "$fix_mode" == "fix" ]]; then
        printf '%-18s fixed %d file(s)\n' "$scope" "$count"
    else
        printf '%-18s %d file(s) need formatting\n' "$scope" "$count"
    fi

    CS_FIXER_JSON="$json" php -d xdebug.mode=off -r '
        $data = json_decode(getenv("CS_FIXER_JSON") ?: "", true);
        if (!is_array($data) || !isset($data["files"])) {
            exit(0);
        }
        foreach ($data["files"] as $file) {
            if (!is_array($file) || !isset($file["name"])) {
                continue;
            }
            echo "  ", $file["name"], PHP_EOL;
        }
    '

    return 1
}

run_cs_fixer() {
    local scope="$1"
    local fix_mode="$2"
    shift 2

    local cs_fixer_bin
    cs_fixer_bin="$(resolve_cs_fixer_bin)" || fail 'PHP-CS-Fixer not found. Run "composer install" at the repository root.'

    local args=(fix --config="$ROOT/.php-cs-fixer.php" --allow-risky=yes --using-cache=no --show-progress=none --format=json)
    if [[ "$fix_mode" == "check" ]]; then
        args+=(--dry-run)
    fi
    args+=("$@")

    cd "$ROOT"

    if [[ "${CS_FIXER_VERBOSE:-0}" == "1" || "${verbose:-0}" == "1" ]]; then
        php -d xdebug.mode=off "$cs_fixer_bin" "${args[@]}"
        return $?
    fi

    local json
    local status=0
    json="$(php -d xdebug.mode=off "$cs_fixer_bin" "${args[@]}" 2>/dev/null)" || status=$?

    if [[ -z "$json" ]]; then
        fail "PHP-CS-Fixer produced no output for $scope (try CS_FIXER_VERBOSE=1)"
    fi

    report_cs_fixer_json "$scope" "$fix_mode" "$json" || status=1

    if [[ "$status" -ne 0 && "$status" -ne 1 && "$status" -ne 8 ]]; then
        return "$status"
    fi

    if ((status == 8)); then
        return 1
    fi

    return 0
}

run_one_scope() {
    local fix_mode="$1"
    local scope="$2"
    local paths=()
    local path

    while IFS= read -r path; do
        [[ -n "$path" ]] || continue
        paths+=("$path")
    done < <(scope_paths "$scope")

    ((${#paths[@]} > 0)) || {
        if [[ "$scope" == "$APP_ADMIN_SCOPE" ]]; then
            fail "no app/ or config/ directory under app-admin"
        fi
        fail "no src/ or tests/ directory under packages/$scope"
    }

    run_cs_fixer "$scope" "$fix_mode" "${paths[@]}"
}

fix_mode="check"
scope_input=""
verbose=0

while (($# > 0)); do
    case "$1" in
        --fix)
            fix_mode="fix"
            shift
            ;;
        --verbose)
            verbose=1
            shift
            ;;
        -h | --help)
            usage
            exit 0
            ;;
        *)
            if [[ -n "$scope_input" ]]; then
                fail "unexpected argument: $1"
            fi
            scope_input="$1"
            shift
            ;;
    esac
done

if [[ -z "$scope_input" ]]; then
    usage
    exit 0
fi

if [[ "$scope_input" == packages/* ]]; then
    scope_input="${scope_input#packages/}"
fi

if [[ "$scope_input" == "--all" || "$scope_input" == "-a" ]]; then
    failed=0
    failed_scopes=()
    package_total=0

    while IFS= read -r package_root; do
        pkg="$(basename "$package_root")"
        ((++package_total))
        if ! run_one_scope "$fix_mode" "$pkg"; then
            failed=1
            failed_scopes+=("$pkg")
        fi
    done < <(find "$ROOT/packages" -mindepth 1 -maxdepth 1 -type d | sort)

    if ! run_one_scope "$fix_mode" "$APP_ADMIN_SCOPE"; then
        failed=1
        failed_scopes+=("$APP_ADMIN_SCOPE")
    fi

    printf '\n'
    if ((failed)); then
        fail "${#failed_scopes[@]} scope(s) need attention: ${failed_scopes[*]}"
    fi
    printf 'All %d packages and app-admin passed.\n' "$package_total"
    exit 0
fi

run_one_scope "$fix_mode" "$scope_input"
