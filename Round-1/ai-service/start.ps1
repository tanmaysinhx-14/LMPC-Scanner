param(
  [int] $Port = 8000
)

$healthUrl = "http://127.0.0.1:$Port/health"
try {
  $health = Invoke-RestMethod -Uri $healthUrl -TimeoutSec 3
  Write-Host "CivicConnect AI is already running on port $Port (model ready: $($health.ok))."
  exit 0
} catch {
  # No healthy CivicConnect process responded. Check whether another process
  # owns the port before asking uvicorn to bind it.
}

$listener = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
if ($listener) {
  throw "Port $Port is already occupied by process $($listener.OwningProcess). Stop that process or choose another port; CivicConnect PHP must use the configured AI URL."
}

$serviceRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
Push-Location $serviceRoot
try {
  python -m uvicorn main:app --host 127.0.0.1 --port $Port
} finally {
  Pop-Location
}
