#Requires -Version 5.1
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Site,
    [Parameter(Mandatory)][uri]$BaseUri,
    [Parameter(Mandatory)][string]$Database,
    [Parameter(Mandatory)][string]$ExpectedBmaEndpoint,
    [switch]$Production,
    [string]$ConfigFile = 'C:\inetpub\private\disc2-config.php',
    [string]$Php = 'php.exe',
    [string]$AccessChk = 'accesschk.exe',
    [switch]$ApplyMigrations
)
$ErrorActionPreference = 'Stop'
Import-Module WebAdministration
. (Join-Path $PSScriptRoot 'iis-policy.ps1')
$root = (Get-Item -LiteralPath (Split-Path -Parent $PSScriptRoot)).FullName
$mount = '/exam/disc2'
$bindings = @(foreach ($webSite in Get-Website) {
    foreach ($binding in Get-WebBinding -Name $webSite.name -Protocol https) {
        [pscustomobject]@{Site=$webSite.name; Info=$binding.bindingInformation}
    }
})
$localAddresses = @([Net.NetworkInformation.NetworkInterface]::GetAllNetworkInterfaces() | ForEach-Object {
    $_.GetIPProperties().UnicastAddresses | ForEach-Object { $_.Address.ToString() }
})
$resolvedAddresses = @([Net.Dns]::GetHostAddresses($BaseUri.DnsSafeHost) | ForEach-Object { $_.ToString() })
$probeAddress = Resolve-Disc2Binding $BaseUri $Site $bindings $resolvedAddresses $localAddresses
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
$cfgDir = Split-Path -Parent $configPath
foreach ($path in $configPath,$dbPath) {
    $entry = Get-Item -LiteralPath $path
    while ($entry) {
        if ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Select the direct external paths for physical ACL checks.' }
        $entry = if ($entry -is [IO.FileInfo]) { $entry.Directory } else { $entry.Parent }
    }
}
function Get-DetailedRights([string]$Path) {
    $parent = Split-Path -Parent $Path
    $lines = @(& $AccessChk -nobanner -v $principal $parent)
    if ($LASTEXITCODE -ne 0) { throw "AccessChk failed at $parent" }
    return Get-Disc2AccessFlags $lines $Path
}
$configFlags = @(Get-DetailedRights $configPath)
$configParentFlags = @(Get-DetailedRights $cfgDir)
$databaseFlags = @(Get-DetailedRights $dbDir)
Assert-Disc2StorageRights $cfgDir $dbDir $configFlags $configParentFlags $databaseFlags
Read-Rights (Split-Path -Parent $cfgDir)
Read-Rights $cfgDir
Read-Rights (Split-Path -Parent $dbDir)
Read-Rights $dbDir
Assert-Rights $configPath $false
Assert-Rights $cfgDir $false
Assert-Rights $dbPath $true
Assert-Rights $dbDir $true
foreach ($suffix in '-wal','-shm') {
    if (Test-Path -LiteralPath ($dbPath+$suffix)) { Assert-Rights ($dbPath+$suffix) $true }
}
$deployment = if ($Production) { 'production' } else { 'staging' }
& $phpPath (Join-Path $PSScriptRoot 'iis-preflight.php') $configPath $dbPath $ExpectedBmaEndpoint $deployment
if ($LASTEXITCODE -ne 0) { throw 'External database/configuration preflight failed.' }
if ($ApplyMigrations) {
    & $phpPath (Join-Path $root 'bin\console') app:migrate --env=prod --no-debug --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'CLI migration failed.' }
}
& $phpPath (Join-Path $root 'bin\console') cache:warmup --env=prod --no-debug
if ($LASTEXITCODE -ne 0) { throw 'Production cache warmup failed.' }

& $phpPath (Join-Path $PSScriptRoot 'iis-http.php') $BaseUri.AbsoluteUri $probeAddress
if ($LASTEXITCODE -ne 0) { throw 'Bound HTTPS smoke failed.' }
Write-Output 'IIS smoke passed.'
