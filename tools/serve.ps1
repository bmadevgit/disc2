param(
    [ValidateRange(1024, 65535)][int]$Port = 8760,
    [string]$Php = 'php'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$public = Join-Path $root 'public'
if (-not (Test-Path -LiteralPath (Join-Path $root 'vendor/autoload_runtime.php'))) {
    throw 'Run composer install before starting the site.'
}
$previousEnv = [Environment]::GetEnvironmentVariable('APP_ENV', 'Process')
try {
    $env:APP_ENV = 'dev'
    & $Php -d 'date.timezone=Asia/Bangkok' -S "127.0.0.1:$Port" -t $public (Join-Path $PSScriptRoot 'dev-router.php')
    if ($LASTEXITCODE -ne 0) { throw "PHP server exited ($LASTEXITCODE)." }
} finally {
    [Environment]::SetEnvironmentVariable('APP_ENV', $previousEnv, 'Process')
}
