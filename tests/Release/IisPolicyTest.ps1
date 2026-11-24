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
Write-Output 'Binding and storage policy fixtures passed.'
