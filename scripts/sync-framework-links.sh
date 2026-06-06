#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

exec php -d xdebug.mode=off "$ROOT/scripts/sync-framework-links.php" "$@"
