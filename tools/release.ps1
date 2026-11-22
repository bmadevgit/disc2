param(
    [Parameter(Mandatory)][ValidatePattern('^v[0-9][0-9A-Za-z.-]*$')][string]$Tag,
    [Parameter(Mandatory)][string]$Output
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$path = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($Output)
if ([IO.Path]::GetExtension($path) -ne '.zip' -or (Test-Path -LiteralPath $path)) {
    throw 'Choose a new .zip output path.'
}
$ref = "refs/tags/$Tag"
& git -C $root rev-parse --verify "$ref^{commit}" | Out-Null
if ($LASTEXITCODE -ne 0) { throw "Release tag $Tag does not exist." }
$files = @(& git -C $root ls-tree -r --name-only $ref)
if ($LASTEXITCODE -ne 0) { throw "Cannot read release tag $Tag." }
$app = @($files | Where-Object { $_ -match '^[a-zA-Z0-9_]+\.(php|css|js)$|^web\.config$|^migrations/[0-9]+_[a-zA-Z0-9_]+\.sql$' })
if ('settings.php' -notin $app -or 'index.php' -notin $app -or 'migrate.php' -notin $app) { throw 'Missing application entry points.' }
$temp = $path + '.' + [guid]::NewGuid().ToString('N') + '.tmp'
try {
    & git -C $root archive --format=zip "--output=$temp" $ref -- @app
    if ($LASTEXITCODE -ne 0) { throw 'Git archive failed.' }
    [IO.File]::Move($temp, $path)
} finally {
    if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp }
}
Get-FileHash -LiteralPath $path -Algorithm SHA256
