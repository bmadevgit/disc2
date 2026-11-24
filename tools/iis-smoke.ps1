#Requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Site,
    [Parameter(Mandatory)][uri]$BaseUri,
    [Parameter(Mandatory)][string]$Database,
    [string]$ConfigFile = 'C:\inetpub\private\disc2-config.php',
    [string]$Php = 'php.exe',
    [string]$AccessChk = 'accesschk.exe',
    [switch]$ApplyMigrations
)
$ErrorActionPreference = 'Stop'
Import-Module WebAdministration
Add-Type -AssemblyName System.Net.Http
$root = (Get-Item -LiteralPath (Split-Path -Parent $PSScriptRoot)).FullName
$mount = '/exam/disc2'
if ($BaseUri.Scheme -notin 'https','http' -or $BaseUri.AbsolutePath.TrimEnd('/') -cne $mount -or $BaseUri.Query -or $BaseUri.Fragment) {
    throw "Use the staging URL at $mount."
}
$app = @(Get-WebApplication -Site $Site | Where-Object { $_.path -eq $mount })
if ($app.Count -ne 1 -or [IO.Path]::GetFullPath([Environment]::ExpandEnvironmentVariables($app[0].physicalPath)).TrimEnd('\') -ne (Join-Path $root 'public')) {
    throw 'Check the IIS application physical path.'
}
$pool = Get-Item (Join-Path 'IIS:\AppPools' $app[0].applicationPool)
$principal = switch ([string]$pool.processModel.identityType) {
    'ApplicationPoolIdentity' { 'IIS AppPool\' + $app[0].applicationPool }
    'SpecificUser' { $pool.processModel.userName }
    'NetworkService' { 'NT AUTHORITY\NETWORK SERVICE' }
    'LocalService' { 'NT AUTHORITY\LOCAL SERVICE' }
    'LocalSystem' { 'NT AUTHORITY\SYSTEM' }
    default { throw 'Cannot resolve the configured pool identity.' }
}
$phpPath = (Get-Command $Php -CommandType Application).Source
$cgi = Join-Path (Split-Path -Parent $phpPath) 'php-cgi.exe'
$handlers = @(Get-WebConfiguration -PSPath 'MACHINE/WEBROOT/APPHOST' -Location "$Site$mount" -Filter 'system.webServer/handlers/add' | Where-Object {
    $_.path -in '*.php','index.php' -and $_.modules -match 'FastCgiModule' -and $_.scriptProcessor -eq $cgi
})
if (!$handlers) { throw 'Select the PHP CLI sibling of the configured IIS FastCGI executable.' }
$manifest = Get-Content -Raw -LiteralPath (Join-Path $root 'release-manifest.json') | ConvertFrom-Json
$modules = @(& $cgi -m)
if ($LASTEXITCODE -ne 0) { throw 'FastCGI module inventory failed.' }
foreach ($module in $manifest.extensions) {
    if ($module -notin $modules) { throw "FastCGI is missing $module" }
}

# AccessChk lists effective account rights, including inherited and group grants.
function Read-Rights([string]$Directory, [switch]$Recursive) {
    $flags = @('-nobanner')
    if ($Recursive) { $flags += '-s' }
    $lines = @(& $AccessChk @flags $principal $Directory)
    if ($LASTEXITCODE -ne 0) { throw "AccessChk failed at $Directory" }
    foreach ($line in $lines) {
        if ($line -match '^\s*([RW ]{1,3})\s+((?:[A-Za-z]:\\|\\\\).+?)\s*$') { $rights[$matches[2]] = $matches[1].Trim() }
    }
}
function Assert-Rights([string]$Path, [bool]$Write) {
    $item = (Get-Item -LiteralPath $Path).FullName
    $permission = [string]$rights[$item]
    if (!$permission.Contains('R') -or $permission.Contains('W') -ne $Write) { throw "Check pool rights for $item" }
}
$rights = @{}
Read-Rights (Split-Path -Parent $root)
Read-Rights $root -Recursive
foreach ($item in @($root) + @(Get-ChildItem -LiteralPath $root -Recurse -Force | Where-Object {
    !$_.FullName.StartsWith((Join-Path $root 'var\'), [StringComparison]::OrdinalIgnoreCase)
} | ForEach-Object { $_.FullName })) { Assert-Rights $item $false }
foreach ($dir in 'var\cache','var\log','var\sessions') { Assert-Rights (Join-Path $root $dir) $true }
$configPath = (Get-Item -LiteralPath $ConfigFile).FullName
$dbPath = (Get-Item -LiteralPath $Database).FullName
$dbDir = Split-Path -Parent $dbPath
Read-Rights (Split-Path -Parent $configPath)
Read-Rights (Split-Path -Parent $dbDir)
Read-Rights $dbDir
Assert-Rights $configPath $false
Assert-Rights $dbPath $true
Assert-Rights $dbDir $true
& $phpPath (Join-Path $PSScriptRoot 'iis-preflight.php') $configPath $dbPath
if ($LASTEXITCODE -ne 0) { throw 'External database/configuration preflight failed.' }
if ($ApplyMigrations) {
    & $phpPath (Join-Path $root 'bin\console') app:migrate --env=prod --no-debug --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'CLI migration failed.' }
}
& $phpPath (Join-Path $root 'bin\console') cache:warmup --env=prod --no-debug
if ($LASTEXITCODE -ne 0) { throw 'Production cache warmup failed.' }

$handler = New-Object System.Net.Http.HttpClientHandler
$handler.AllowAutoRedirect = $false
$client = New-Object System.Net.Http.HttpClient($handler)
$client.BaseAddress = [uri]($BaseUri.AbsoluteUri.TrimEnd('/') + '/')
function Request([string]$Path, [int[]]$Statuses, [string]$PostBody) {
    if ($PostBody) {
        $body = New-Object System.Net.Http.StringContent($PostBody, [Text.Encoding]::UTF8, 'application/x-www-form-urlencoded')
        try { $reply = $client.PostAsync($Path, $body).GetAwaiter().GetResult() } finally { $body.Dispose() }
    } else { $reply = $client.GetAsync($Path).GetAwaiter().GetResult() }
    try {
        if ([int]$reply.StatusCode -notin $Statuses) { throw "Unexpected HTTP $([int]$reply.StatusCode) at $Path" }
        if ([int]$reply.StatusCode -eq 302) {
            $location = [uri]::new($client.BaseAddress, $reply.Headers.Location)
            if ($location.AbsoluteUri -cne ([uri]::new($client.BaseAddress, 'login.php')).AbsoluteUri) { throw 'Login redirect escaped the mount.' }
        }
        return $reply.Content.ReadAsStringAsync().GetAwaiter().GetResult()
    } finally { $reply.Dispose() }
}
try {
    $login = Request 'login.php?login_type=admin' @(200)
    if ($login -notmatch 'name="csrf" value="([^"]+)"' -or !$login.Contains('id="form-admin"') -or !$login.Contains("$mount/assets/login.css")) { throw 'Production login page or asset base failed.' }
    $token = [regex]::Match($login, 'name="csrf" value="([^"]+)"').Groups[1].Value
    $cookie = $handler.CookieContainer.GetCookies($client.BaseAddress)['DISC2SFSESSID']
    if (!$cookie -or $cookie.Path -cne $mount -or !$cookie.HttpOnly -or $cookie.Secure -ne ($BaseUri.Scheme -eq 'https')) { throw 'Check session cookie scope and flags.' }
    $null = Request 'case.php?id=1' @(302)
    $null = Request 'logout.php' @(419) 'csrf=invalid'
    $null = Request 'logout.php' @(302) ('csrf=' + [uri]::EscapeDataString($token))
    foreach ($asset in 'login.css','login.js','uat.css','workflow.js') {
        $response = $client.GetAsync("assets/$asset").GetAwaiter().GetResult()
        try {
            $bytes = $response.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult()
            $sha = [Security.Cryptography.SHA256]::Create()
            try { $digest = [BitConverter]::ToString($sha.ComputeHash($bytes)).Replace('-','').ToLowerInvariant() } finally { $sha.Dispose() }
            if ([int]$response.StatusCode -ne 200 -or $digest -ne $manifest.files."public/assets/$asset") { throw "Static asset mismatch: $asset" }
        } finally { $response.Dispose() }
    }
    foreach ($private in 'vendor/autoload.php','config/services.yaml','templates/base.html.twig','tools/iis-preflight.php','runtime/disc2-dev.sqlite','.env','var/log/prod.log','migrations/001_uat.sql','web.config') {
        $null = Request $private @(403,404)
    }
} finally { $client.Dispose() }
Write-Output 'IIS smoke passed.'
