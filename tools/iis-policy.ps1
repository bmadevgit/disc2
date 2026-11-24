# Functions shared by the IIS operator script and its portable policy fixtures.
function Resolve-Disc2Binding([uri]$Uri, [string]$Site, [array]$Bindings, [array]$Resolved, [array]$Local) {
    if (!$Uri.IsAbsoluteUri -or $Uri.Scheme -cne 'https' -or $Uri.UserInfo -or $Uri.Query -or $Uri.Fragment -or $Uri.AbsolutePath.TrimEnd('/') -cne '/exam/disc2') {
        throw 'Select an HTTPS IIS binding at /exam/disc2.'
    }
    $running = @($Bindings | Where-Object { $_.State -eq 'Started' })
    if (!@($running | Where-Object { $_.Site -eq $Site })) { throw 'The requested IIS site must be Started and have an HTTPS binding.' }
    foreach ($addr in $Resolved) {
        if ($addr -notin $Local) { continue }
        $candidates = @(
            foreach ($binding in $running) {
                if ($binding.Info -notmatch '^(.*):([0-9]+):([^:]*)$') { throw 'Unrecognized IIS binding format.' }
                $ip = $matches[1].Trim('[',']'); $port = [int]$matches[2]; $hostName = $matches[3]
                if ($port -ne $Uri.Port) { continue }
                $specificIP = $ip -notin '*','','0.0.0.0','::'
                if ($specificIP -and [IPAddress]::Parse($ip).ToString() -ne $addr) { continue }
                if ($hostName -and $Uri.IdnHost -notlike $hostName) { continue }
                # Host-specific bindings beat a catch-all. Keep equal fits ambiguous across sites.
                $hostRank = if (!$hostName -or $hostName -eq '*') { 0 } elseif ($hostName.Contains('*')) { 1 } else { 2 }
                [pscustomobject]@{ Site=$binding.Site; HostRank=$hostRank; SpecificIP=[int]$specificIP }
            }
        )
        foreach ($candidate in @($candidates | Where-Object { $_.Site -eq $Site })) {
            $other = @($candidates | Where-Object {
                $_.Site -ne $Site -and !($candidate.HostRank -ge $_.HostRank -and $candidate.SpecificIP -ge $_.SpecificIP -and
                    ($candidate.HostRank -gt $_.HostRank -or $candidate.SpecificIP -gt $_.SpecificIP))
            })
            if (!$other) { return $addr }
        }
    }
    throw 'The HTTPS host, port and a local resolved address must uniquely select the requested IIS site.'
}

function Get-Disc2AccessFlags([string[]]$Lines, [string]$Path) {
    $selected = $false
    $flags = @(
        foreach ($line in $Lines) {
            if ($line -match '^\s*[RW ]{0,3}\s*((?:[a-zA-Z]:\\|\\\\).*?)\s*$') {
                $reported = $matches[1] -replace '\\\.$','\'
                $selected = $reported.TrimEnd('\') -eq $Path.TrimEnd('\')
            } elseif ($selected -and $line -cmatch '^\s+([A-Z][A-Z_]+)\s*$') { $matches[1] }
        }
    )
    if (!$flags) { throw "AccessChk did not supply detailed rights for $Path" }
    return $flags
}

function Assert-Disc2StorageRights([string]$ConfigDir, [string]$DatabaseDir, [array]$ConfigFlags, [array]$ParentFlags, [array]$DatabaseFlags) {
    $cfg = $ConfigDir.TrimEnd('\') + '\'; $db = $DatabaseDir.TrimEnd('\') + '\'
    if ($cfg.StartsWith($db, [StringComparison]::OrdinalIgnoreCase) -or $db.StartsWith($cfg, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Keep external configuration and SQLite data in separate private directory trees.'
    }
    $protected = 'DELETE','WRITE_DAC','WRITE_OWNER','FILE_DELETE_CHILD','FILE_ADD_FILE','FILE_ADD_SUBDIRECTORY','FILE_WRITE_DATA','FILE_APPEND_DATA',
        'FILE_WRITE_ATTRIBUTES','FILE_WRITE_EA','FILE_GENERIC_WRITE','GENERIC_WRITE','FILE_ALL_ACCESS','GENERIC_ALL'
    foreach ($flags in @(@{ f=$ConfigFlags },@{ f=$ParentFlags })) {
        if (!@($flags.f | Where-Object { $_ -in 'FILE_READ_DATA','FILE_LIST_DIRECTORY','FILE_GENERIC_READ','GENERIC_READ' }) -or
            @($flags.f | Where-Object { $_ -in $protected })) { throw 'Pool can replace or change the configuration, or its effective read rights are unverified.' }
    }
    $create = @($DatabaseFlags | Where-Object { $_ -in 'FILE_ADD_FILE','FILE_WRITE_DATA','FILE_GENERIC_WRITE','GENERIC_WRITE','FILE_ALL_ACCESS','GENERIC_ALL' })
    $remove = @($DatabaseFlags | Where-Object { $_ -in 'FILE_DELETE_CHILD','FILE_ALL_ACCESS','GENERIC_ALL' })
    if (!$create -or !$remove) { throw 'The database directory must allow sidecar creation and deletion of child files.' }
}

function Assert-Disc2AncestorRights([array]$Chain) {
    if (!$Chain) { throw 'Missing configuration ancestor rights.' }
    foreach ($ancestor in $Chain) {
        # Creating an unrelated child at the volume root cannot replace an existing protected directory.
        $verified = @($ancestor.Flags | Where-Object { $_ -in 'FILE_TRAVERSE','FILE_EXECUTE','FILE_GENERIC_EXECUTE','GENERIC_EXECUTE',
            'FILE_LIST_DIRECTORY','FILE_READ_DATA','FILE_READ_ATTRIBUTES','FILE_GENERIC_READ','GENERIC_READ','READ_CONTROL' })
        $canReplace = @($ancestor.Flags | Where-Object { $_ -in 'DELETE','FILE_DELETE_CHILD','WRITE_DAC','WRITE_OWNER','GENERIC_ALL','FILE_ALL_ACCESS' })
        if (!$verified -or $canReplace) { throw "Configuration ancestor has replacement rights or unverified ACLs: $($ancestor.Path)" }
    }
}
