$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '../../tools/iis-policy.ps1')
function Must-Fail([scriptblock]$Check) {
    try { & $Check | Out-Null } catch { return }; throw 'Unsafe fixture was accepted.'
}
$ip = '192.0.2.15'; $uri = [uri]'https://disc-stage.example:8443/exam/disc2'
$bindings = @(@{Site='Staging';State='Started';Info='*:8443:disc-stage.example'}, @{Site='Default';State='Started';Info='*:8443:'})
if ((Resolve-Disc2Binding $uri 'Staging' $bindings @('192.0.2.16',$ip) @($ip)) -ne $ip) { throw 'Selected different endpoint' }
Must-Fail { Resolve-Disc2Binding $uri 'Default' $bindings @($ip) @($ip) }
Must-Fail { Resolve-Disc2Binding 'http://disc-stage.example:8443/exam/disc2' 'Staging' $bindings @($ip) @($ip) }
Must-Fail { Resolve-Disc2Binding $uri 'Staging' $bindings @('192.0.2.16') @($ip) }
Must-Fail { Resolve-Disc2Binding $uri 'Staging' @(@{Site='Staging';State='Started';Info='192.0.2.16:8443:disc-stage.example'}) @($ip) @($ip) }
Must-Fail { Resolve-Disc2Binding $uri 'Staging' @(@{Site='Staging';State='Started';Info='*:443:disc-stage.example'}) @($ip) @($ip) }
Must-Fail { Resolve-Disc2Binding $uri 'Staging' @(@{Site='Staging';State='Started';Info='*:8443:other.example'}) @($ip) @($ip) }
$stoppedExact = @(@{Site='Staging';State='Stopped';Info='*:8443:disc-stage.example'}, @{Site='Default';State='Started';Info='*:8443:'})
Must-Fail { Resolve-Disc2Binding $uri 'Staging' $stoppedExact @($ip) @($ip) }
if ((Resolve-Disc2Binding $uri 'Default' $stoppedExact @($ip) @($ip)) -ne $ip) { throw 'Stopped site shadowed a running binding.' }
$read = @('FILE_GENERIC_READ'); $write = @('FILE_ADD_FILE','FILE_DELETE_CHILD')
Assert-Disc2StorageRights 'D:\private\config' 'D:\private\data' $read $read $write
Must-Fail { Assert-Disc2StorageRights 'D:\private\data' 'D:\private\data' $read $read $write }
Must-Fail { Assert-Disc2StorageRights 'D:\private\data\conf' 'D:\private\data' $read $read $write }
Must-Fail { Assert-Disc2StorageRights 'D:\private\config' 'D:\private\data' $read ($read + 'FILE_DELETE_CHILD') $write }
Must-Fail { Assert-Disc2StorageRights 'D:\private\config' 'D:\private\data' ($read + 'WRITE_DAC') $read $write }
Must-Fail { Assert-Disc2StorageRights 'D:\private\config' 'D:\private\data' $read $read @('FILE_GENERIC_WRITE') }
Must-Fail { Assert-Disc2StorageRights 'D:\private\config' 'D:\private\data' $read $read @('FILE_DELETE_CHILD') }
$flags = Get-Disc2AccessFlags @(' RW D:\private\data', '       FILE_ADD_FILE', '       FILE_DELETE_CHILD',' R  D:\private\config','       FILE_GENERIC_READ') 'D:\private\config'
if (@($flags).Count -ne 1 -or $flags -ne 'FILE_GENERIC_READ') { throw 'Rights from another object were used' }
Must-Fail { Get-Disc2AccessFlags @(' R  D:\private\config') 'D:\private\config' }
$chain = @(
    @{Path='D:\locked\private'; Flags=$read},
    @{Path='D:\locked'; Flags=@('FILE_TRAVERSE')},
    @{Path='D:\'; Flags=@('FILE_GENERIC_READ','FILE_ADD_SUBDIRECTORY')}
)
Assert-Disc2AncestorRights $chain
foreach ($mutation in 'DELETE','FILE_DELETE_CHILD','WRITE_DAC','WRITE_OWNER','FILE_ALL_ACCESS') {
    $unsafe = @($chain[0], @{Path='D:\locked'; Flags=($read + $mutation)}, $chain[2])
    Must-Fail { Assert-Disc2AncestorRights $unsafe }
}
Must-Fail { Assert-Disc2AncestorRights @($chain[0], $chain[1], @{Path='D:\';Flags=($read + 'FILE_DELETE_CHILD')}) }
Must-Fail { Assert-Disc2AncestorRights @(@{Path='D:\';Flags=@()}) }
$rootRights = @(Get-Disc2AccessFlags @(' R  D:\','       FILE_GENERIC_READ',' RW D:\unrelated','       FILE_ALL_ACCESS') 'D:\')
if ($rootRights.Count -ne 1 -or $rootRights[0] -ne 'FILE_GENERIC_READ') { throw 'Volume root rights were not isolated' }
$fixture = Join-Path ([IO.Path]::GetTempPath()) ([Guid]::NewGuid().ToString())
$cfg = Join-Path $fixture 'config'; $data = Join-Path $fixture 'data'; $recovery = Join-Path $fixture 'recovery'
try {
    foreach ($dir in $cfg,$data,$recovery) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $file = Join-Path $cfg 'ai.php'; $copy = Join-Path $recovery 'ai.php'
    if (Resolve-Disc2AiFiles '' '' $file) { throw 'Absent default unexpectedly enabled AI.' }
    Must-Fail { Resolve-Disc2AiFiles $file '' $file }
    Set-Content -LiteralPath $file -Value 'synthetic' -NoNewline
    Must-Fail { Resolve-Disc2AiFiles '' '' $file }
    Copy-Item -LiteralPath $file -Destination $copy
    $files = Resolve-Disc2AiFiles $file $copy $file
    if ($files.Config -ne $file -or $files.Recovery -ne $copy) { throw 'Wrong AI file selection.' }
    Must-Fail { Resolve-Disc2AiFiles $file $file $file }
    $seen = [Collections.Generic.List[string]]::new()
    Assert-Disc2ConfigAcl $file $data $write { param($path) $seen.Add($path); $read }
    $entry = Get-Item -LiteralPath $file
    while ($entry) {
        if (!$seen.Contains($entry.FullName)) { throw 'Skipped configuration file or ancestor.' }
        $entry = if ($entry -is [IO.FileInfo]) { $entry.Directory } else { $entry.Parent }
    }
    foreach ($unsafePath in $file,$cfg,$fixture) {
        Must-Fail { Assert-Disc2ConfigAcl $file $data $write { param($path)
            if ($path -eq $unsafePath) { return $read + 'WRITE_DAC' }; $read
        } }
    }
    Set-Content -LiteralPath $copy -Value 'wrong'
    Must-Fail { Resolve-Disc2AiFiles $file $copy $file }
} finally {
    if (Test-Path -LiteralPath $fixture) { Remove-Item -LiteralPath $fixture -Recurse -Force }
}
Write-Output 'Binding, configuration recovery and storage policy fixtures passed.'
