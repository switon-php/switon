# One-click headed CDP for Switon Browser (~\.switon\chrome\headed).
# - Port open  -> already up; agent attaches (exit 0).
# - Port closed -> start headed Chrome with this profile + debugging port.
$ErrorActionPreference = 'Stop'

$Port = if ($env:SWITON_CHROME_PORT) { $env:SWITON_CHROME_PORT } else { '59223' }
$Dir = if ($env:SWITON_CHROME_USER_DATA_DIR) { $env:SWITON_CHROME_USER_DATA_DIR } else { Join-Path $HOME '.switon\chrome\headed' }
$HostName = if ($env:SWITON_CHROME_HOST) { $env:SWITON_CHROME_HOST } else { '127.0.0.1' }

try {
    $null = Invoke-WebRequest -Uri "http://${HostName}:${Port}/json/version" -TimeoutSec 1 -UseBasicParsing
    Write-Output "CDP already up on ${HostName}:${Port} (profile: ${Dir}) - attach OK"
    exit 0
} catch {
    # Port closed: fall through and start Chrome.
}

$candidates = @()
if ($env:SWITON_CHROME_PATH) { $candidates += $env:SWITON_CHROME_PATH }
if ($env:ProgramFiles) { $candidates += (Join-Path $env:ProgramFiles 'Google\Chrome\Application\chrome.exe') }
if (${env:ProgramFiles(x86)}) { $candidates += (Join-Path ${env:ProgramFiles(x86)} 'Google\Chrome\Application\chrome.exe') }
if ($env:LOCALAPPDATA) { $candidates += (Join-Path $env:LOCALAPPDATA 'Google\Chrome\Application\chrome.exe') }

$Chrome = $candidates | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1

if (-not $Chrome) {
    Write-Error 'Chrome/Chromium not found. Set SWITON_CHROME_PATH.'
    exit 1
}

New-Item -ItemType Directory -Force -Path $Dir | Out-Null
# No --remote-allow-origins: this client sends no Origin header, and the wildcard would let any
# local page open a DevTools socket on this profile's cookies.
Write-Output "Starting headed Chrome for CDP on :${Port}"
Write-Output "  profile: ${Dir}"
Write-Output "  binary:  ${Chrome}"
& $Chrome `
    "--remote-debugging-port=${Port}" `
    "--user-data-dir=${Dir}" `
    '--no-first-run' `
    '--no-default-browser-check' `
    @args
