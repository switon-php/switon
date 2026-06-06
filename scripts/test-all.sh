#!/bin/bash

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

exec bash "$REPO_ROOT/tests/run.sh" --all "$@"
