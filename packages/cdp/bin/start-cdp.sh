#!/usr/bin/env bash
# One-click headed CDP for Switon Browser (~/.switon/chrome/headed).
# - Port open  → already up; agent attaches (exit 0).
# - Port closed → start headed Chrome with this profile + debugging port.
set -euo pipefail

PORT="${SWITON_CHROME_PORT:-59223}"
DIR="${SWITON_CHROME_USER_DATA_DIR:-${HOME}/.switon/chrome/headed}"
HOST="${SWITON_CHROME_HOST:-127.0.0.1}"

if curl -sf --max-time 1 "http://${HOST}:${PORT}/json/version" >/dev/null 2>&1; then
  echo "CDP already up on ${HOST}:${PORT} (profile: ${DIR}) — attach OK"
  exit 0
fi

if [[ -n "${SWITON_CHROME_PATH:-}" && -x "${SWITON_CHROME_PATH}" ]]; then
  CHROME="${SWITON_CHROME_PATH}"
elif [[ -x "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" ]]; then
  CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
elif [[ -x "/Applications/Chromium.app/Contents/MacOS/Chromium" ]]; then
  CHROME="/Applications/Chromium.app/Contents/MacOS/Chromium"
elif command -v google-chrome-stable >/dev/null 2>&1; then
  CHROME="$(command -v google-chrome-stable)"
elif command -v google-chrome >/dev/null 2>&1; then
  CHROME="$(command -v google-chrome)"
elif command -v chromium >/dev/null 2>&1; then
  CHROME="$(command -v chromium)"
elif command -v chromium-browser >/dev/null 2>&1; then
  CHROME="$(command -v chromium-browser)"
else
  echo "Chrome/Chromium not found. Set SWITON_CHROME_PATH." >&2
  exit 1
fi

if [[ "$(uname -s)" == "Linux" && -z "${DISPLAY:-}" && -z "${WAYLAND_DISPLAY:-}" ]]; then
  echo "Headed Chrome needs a display, but neither DISPLAY nor WAYLAND_DISPLAY is set. Start Chrome from a desktop session, or use the headless service on this host." >&2
  exit 1
fi

mkdir -p "${DIR}"
# No --remote-allow-origins: it is not needed for a client that sends no Origin header, and the
# wildcard lets any local page open a DevTools socket on this profile's cookies.
echo "Starting headed Chrome for CDP on :${PORT}"
echo "  profile: ${DIR}"
echo "  binary:  ${CHROME}"
exec "${CHROME}" \
  --remote-debugging-port="${PORT}" \
  --user-data-dir="${DIR}" \
  --no-first-run \
  --no-default-browser-check \
  "$@"
