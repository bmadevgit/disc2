$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '../../tools/iis-policy.ps1')
function Must-Fail([scriptblock]$Check) {
    try { & $Check | Out-Null } catch { return }; throw 'Unsafe fixture was accepted.'
}
$ip = '192.0.2.15'; $uri = [uri]'https://disc-stage.example:8443/exam/disc2'
$bindings = @(@{Site='Staging';Info='*:8443:disc-stage.example'}, @{Site='Default';Info='*:8443:'})
if ((Resolve-Disc2Binding $uri 'Staging' $bindings @('192.0.2.16',$ip) @($ip)) -ne $ip) { throw 'Selected different endpoint' }
Must-Fail { Resolve-Disc2Binding $uri 'Default' $bindings @($ip) @($ip) }
Must-Fail { Resolve-Disc2Binding 'http://disc-stage.example:8443/exam/disc2' 'Staging' $bindings @($ip) @($ip) }
Must-Fail { Resolve-Disc2Binding $uri 'Staging' $bindings @('192.0.2.16') @($ip) }
Must-Fail { Resolve-Disc2Binding $uri 'Staging' @(@{Site='Staging';Info='192.0.2.16:8443:disc-stage.example'}) @($ip) @($ip) }
Must-Fail { Resolve-Disc2Binding $uri 'Staging' @(@{Site='Staging';Info='*:443:disc-stage.example'}) @($ip) @($ip) }
Must-Fail { Resolve-Disc2Binding $uri 'Staging' @(@{Site='Staging';Info='*:8443:other.example'}) @($ip) @($ip) }
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
Write-Output 'Binding and storage policy fixtures passed.'
