#!/bin/bash

# Script to run all package tests from monorepo root
# Usage: ./scripts/test-full.sh [package] [options]
#   package: Package name (if first argument doesn't start with --)
# Options: same as PHPUnit options, plus:
#   --jobs=N      Run packages in parallel (package-level, default: 8)
#   --failed      Only run tests for packages that failed last time
#   --package=NAME Run tests for specific package only
#
# When run as test-summary.sh, shows only summary lines (no detailed output)

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

# Determine mode based on script name
SCRIPT_NAME=$(basename "$0")
SUMMARY_MODE=false
if [ "$SCRIPT_NAME" = "test-summary.sh" ]; then
    SUMMARY_MODE=true
fi

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Set -e only in non-summary mode (summary mode needs to continue on errors)
if [ "$SUMMARY_MODE" = false ]; then
    set -e
fi

# Parse arguments
FAILED_ONLY=false
SPECIFIC_PACKAGE=""
PHPUNIT_ARGS=()
JOBS=8

# If first argument doesn't start with --, treat it as package name
# Exception: "unit" and "integration" are common testsuite names, not package names
if [[ $# -gt 0 ]] && [[ ! "$1" =~ ^-- ]] && [[ "$1" != "unit" ]] && [[ "$1" != "integration" ]]; then
    SPECIFIC_PACKAGE="$1"
    shift
fi

# Parse remaining arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --failed)
            FAILED_ONLY=true
            shift
            ;;
        --jobs=*)
            JOBS="${1#*=}"
            shift
            ;;
        --package=*)
            SPECIFIC_PACKAGE="${1#*=}"
            shift
            ;;
        *)
            PHPUNIT_ARGS+=("$1")
            shift
            ;;
    esac
done

if [ "$SUMMARY_MODE" = true ]; then
    SUMMARY_ARGS=()
    if [ -n "$SPECIFIC_PACKAGE" ]; then
        SUMMARY_ARGS+=("$SPECIFIC_PACKAGE")
    else
        SUMMARY_ARGS+=("--all")
    fi
    SUMMARY_ARGS+=("${PHPUNIT_ARGS[@]}")
    SUMMARY_ARGS+=("--silent")
    exec bash "$REPO_ROOT/tests/run.sh" "${SUMMARY_ARGS[@]}"
fi

# Find all packages with tests
PACKAGES=()
while IFS= read -r -d '' package_dir; do
    package_name=$(basename "$package_dir")
    if [ -f "$package_dir/tests/phpunit.xml.dist" ]; then
        PACKAGES+=("$package_name")
    fi
done < <(find packages -maxdepth 1 -mindepth 1 -type d -print0 | sort -z)

if [ ${#PACKAGES[@]} -eq 0 ]; then
    echo "No packages with tests found."
    exit 1
fi

# Filter packages if needed
if [ -n "$SPECIFIC_PACKAGE" ]; then
    if [[ ! " ${PACKAGES[@]} " =~ " ${SPECIFIC_PACKAGE} " ]]; then
        echo -e "${RED}Error: Package '$SPECIFIC_PACKAGE' not found or has no tests.${NC}"
        echo "Available packages: ${PACKAGES[*]}"
        exit 1
    fi
    PACKAGES=("$SPECIFIC_PACKAGE")
fi

if [ "$SUMMARY_MODE" = true ]; then
    echo -e "${BLUE}Running tests for ${#PACKAGES[@]} package(s)...${NC}"
    echo ""
else
    echo -e "${BLUE}Found ${#PACKAGES[@]} package(s) with tests:${NC} ${PACKAGES[*]}"
    echo ""
fi

# Shared PHPUnit path used by both summary and full modes.
SHARED_TESTS_DIR="$REPO_ROOT/packages/testing"
PHPUNIT_PATH="$SHARED_TESTS_DIR/vendor/bin/phpunit"

if [ ! -f "$PHPUNIT_PATH" ]; then
    echo "Installing PHPUnit in shared tests package..."
    (cd "$SHARED_TESTS_DIR" && composer install --quiet)
    if [ ! -f "$PHPUNIT_PATH" ]; then
        echo -e "${RED}Error: Failed to install PHPUnit${NC}"
        exit 1
    fi
fi

# Track results
FAILED_PACKAGES=()
PASSED_PACKAGES=()
# Determine if we should run packages with jobs (package-level)
USE_JOBS=false
if [ "$JOBS" -gt 1 ] && [ ${#PACKAGES[@]} -gt 1 ] && [ -z "$SPECIFIC_PACKAGE" ]; then
    USE_JOBS=true
fi

extract_summary() {
    local output="$1"
    local plain

    plain=$(printf '%s\n' "$output" | sed -E 's/\x1B\[[0-9;]*[[:alpha:]]//g')

    local summary
    summary=$(printf '%s\n' "$plain" | grep -E "^(OK|FAILURES|\[OK\]|\[FAIL\]|\[FAILURES\])" | tail -1)
    if [ -n "$summary" ]; then
        printf '%s\n' "$summary"
        return 0
    fi

    summary=$(printf '%s\n' "$plain" | grep -E "^Tests:" | tail -1)
    if [ -n "$summary" ]; then
        printf '%s\n' "$summary"
        return 0
    fi

    return 1
}

# Package execution (jobs, package-level)
if [ "$USE_JOBS" = true ]; then
    LOG_DIR="$REPO_ROOT/scripts/.logs/parallel"
    rm -rf "$LOG_DIR"
    mkdir -p "$LOG_DIR"
    echo -e "${YELLOW}Running tests with jobs=$JOBS...${NC}"
    echo ""

    run_package() {
        local package="$1"
        local PACKAGE_DIR="$REPO_ROOT/packages/$package"
        local TESTS_DIR="$PACKAGE_DIR/tests"
        local LOG_FILE="$LOG_DIR/$package.log"
        local EXIT_FILE="$LOG_DIR/$package.exit"

        if [ ! -d "$TESTS_DIR" ] || [ ! -f "$TESTS_DIR/phpunit.xml.dist" ]; then
            echo "Skipping $package: no tests directory" >"$LOG_FILE"
            echo 0 >"$EXIT_FILE"
            return
        fi

        if [ -f "$TESTS_DIR/run.sh" ] && [ -x "$TESTS_DIR/run.sh" ]; then
            (cd "$TESTS_DIR" && ./run.sh "${PHPUNIT_ARGS[@]}") >"$LOG_FILE" 2>&1
            echo $? >"$EXIT_FILE"
        else
            if [ -n "$PHPUNIT_PATH" ]; then
                (cd "$TESTS_DIR" && "$PHPUNIT_PATH" --configuration "$TESTS_DIR/phpunit.xml.dist" "${PHPUNIT_ARGS[@]}") >"$LOG_FILE" 2>&1
                echo $? >"$EXIT_FILE"
            else
                echo "Skipping $package: no run.sh" >"$LOG_FILE"
                echo 0 >"$EXIT_FILE"
            fi
        fi
    }

    for package in "${PACKAGES[@]}"; do
        run_package "$package" &
        while [ "$(jobs -rp | wc -l)" -ge "$JOBS" ]; do
            sleep 0.1
        done
    done

    wait

    for package in "${PACKAGES[@]}"; do
        LOG_FILE="$LOG_DIR/$package.log"
        EXIT_FILE="$LOG_DIR/$package.exit"
        EXIT_CODE=$(cat "$EXIT_FILE" 2>/dev/null || echo 1)
        OUTPUT=$(cat "$LOG_FILE" 2>/dev/null)

        if [ "$SUMMARY_MODE" = true ]; then
            SUMMARY=$(extract_summary "$OUTPUT" || true)
            if [ $EXIT_CODE -eq 0 ] && [ -n "$SUMMARY" ] && echo "$SUMMARY" | grep -q "^OK"; then
                printf "%-30s %s\n" "$package" "$SUMMARY"
                PASSED_PACKAGES+=("$package")
            else
                if [ -z "$SUMMARY" ]; then
                    SUMMARY="FAILURES"
                fi
                printf "%-30s ${RED}%s${NC}\n" "$package" "$SUMMARY"
                FAILED_PACKAGES+=("$package")
            fi
        else
            if [ $EXIT_CODE -eq 0 ]; then
                printf "%-30s %s\n" "$package" "OK"
                PASSED_PACKAGES+=("$package")
            else
                printf "%-30s ${RED}%s${NC}\n" "$package" "FAILURES"
                FAILED_PACKAGES+=("$package")
                echo ""
                echo -e "${RED}---- $package (last 200 lines) ----${NC}"
                tail -n 200 "$LOG_FILE" || true
                echo ""
            fi
        fi
    done

    rm -rf "$LOG_DIR"
fi

# Run tests for each package
if [ "$USE_JOBS" = false ]; then
for package in "${PACKAGES[@]}"; do
    PACKAGE_DIR="$REPO_ROOT/packages/$package"
    TESTS_DIR="$PACKAGE_DIR/tests"
    
    if [ ! -d "$TESTS_DIR" ] || [ ! -f "$TESTS_DIR/phpunit.xml.dist" ]; then
        echo -e "${YELLOW}Skipping $package: no tests directory${NC}"
        continue
    fi
    
    cd "$TESTS_DIR"
    
    if [ "$SUMMARY_MODE" = true ]; then
        # Summary mode: capture output and show only summary line
        if [ -f "$TESTS_DIR/run.sh" ] && [ -x "$TESTS_DIR/run.sh" ]; then
            if [ "$package" = "http-client" ]; then
                TMP_OUTPUT=$(mktemp)
                ./run.sh "${PHPUNIT_ARGS[@]}" >"$TMP_OUTPUT" 2>&1 &
                RUN_PID=$!
                wait $RUN_PID
                EXIT_CODE=$?
                OUTPUT=$(cat "$TMP_OUTPUT")
                rm -f "$TMP_OUTPUT"
            else
                OUTPUT=$(./run.sh "${PHPUNIT_ARGS[@]}" 2>&1)
                EXIT_CODE=$?
            fi
            SUMMARY=$(extract_summary "$OUTPUT" || true)
            
            if [ $EXIT_CODE -eq 0 ] && [ -n "$SUMMARY" ] && echo "$SUMMARY" | grep -q "^OK"; then
                # Format: package name (padded to 30 chars) + summary
                printf "%-30s %s\n" "$package" "$SUMMARY"
                PASSED_PACKAGES+=("$package")
            else
                if [ -z "$SUMMARY" ]; then
                    SUMMARY="FAILURES"
                fi
                printf "%-30s ${RED}%s${NC}\n" "$package" "$SUMMARY"
                FAILED_PACKAGES+=("$package")
            fi
        else
            if [ -n "$PHPUNIT_PATH" ]; then
                OUTPUT=$("$PHPUNIT_PATH" --configuration "$TESTS_DIR/phpunit.xml.dist" "${PHPUNIT_ARGS[@]}" 2>&1)
                EXIT_CODE=$?
                SUMMARY=$(extract_summary "$OUTPUT" || true)

                if [ $EXIT_CODE -eq 0 ] && [ -n "$SUMMARY" ] && echo "$SUMMARY" | grep -q "^OK"; then
                    printf "%-30s %s\n" "$package" "$SUMMARY"
                    PASSED_PACKAGES+=("$package")
                else
                    if [ -z "$SUMMARY" ]; then
                        SUMMARY="FAILURES"
                    fi
                    printf "%-30s ${RED}%s${NC}\n" "$package" "$SUMMARY"
                    FAILED_PACKAGES+=("$package")
                fi
            else
                echo -e "${YELLOW}Skipping $package: no run.sh${NC}"
            fi
        fi
    else
        # Full mode: show detailed output
        echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
        echo -e "${BLUE}Running tests for: ${GREEN}$package${NC}"
        echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
        
        # Use package's run.sh if available, otherwise use PHPUnit directly
        if [ -f "$TESTS_DIR/run.sh" ] && [ -x "$TESTS_DIR/run.sh" ]; then
            # Use package's run.sh script (recommended - handles all setup automatically)
            if ./run.sh "${PHPUNIT_ARGS[@]}" 2>&1; then
                PASSED_PACKAGES+=("$package")
            else
                FAILED_PACKAGES+=("$package")
            fi
        else
            # Fallback: use the repo-standard runner so flags like --fast keep working.
            if bash "$REPO_ROOT/tests/run.sh" "$package" "${PHPUNIT_ARGS[@]}" 2>&1; then
                PASSED_PACKAGES+=("$package")
            else
                FAILED_PACKAGES+=("$package")
            fi
        fi
        
        echo ""
    fi
done
fi

# Summary
if [ "$SUMMARY_MODE" = true ]; then
    echo ""
else
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}Test Summary${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    
    for package in "${PACKAGES[@]}"; do
        if [[ " ${PASSED_PACKAGES[@]} " =~ " ${package} " ]]; then
            echo -e "  ${GREEN}✓${NC} $package"
        else
            echo -e "  ${RED}✗${NC} $package"
        fi
    done
    
    echo ""
fi

if [ ${#FAILED_PACKAGES[@]} -eq 0 ]; then
    echo -e "${GREEN}All packages passed!${NC}"
    exit 0
else
    echo -e "${RED}Failed packages: ${FAILED_PACKAGES[*]}${NC}"
    echo -e "${GREEN}Passed packages: ${PASSED_PACKAGES[*]}${NC}"
    exit 1
fi
