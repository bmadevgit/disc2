param(
    [ValidateRange(1024, 65535)][int]$Port = 8760,
    [string]$Php = 'php'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$sessions = Join-Path $root 'runtime\sessions'
if (-not (Test-Path -LiteralPath (Join-Path $root 'runtime\disc2-dev.sqlite'))) {
    throw 'Run php tools/init.php before starting the site.'
}
New-Item -ItemType Directory -Force -Path $sessions | Out-Null
$dateZone = 'date.timezone=Asia/Bangkok'
$previousDevMode = [Environment]::GetEnvironmentVariable('DISC2_DEV', 'Process')
try {
    $env:DISC2_DEV = '1'
    & $Php -d $dateZone -d "session.save_path=$sessions" -S "127.0.0.1:$Port" -t $root (Join-Path $PSScriptRoot 'router.php')
    if ($LASTEXITCODE -ne 0) { throw "PHP server exited ($LASTEXITCODE)." }
} finally {
    [Environment]::SetEnvironmentVariable('DISC2_DEV', $previousDevMode, 'Process')
}
