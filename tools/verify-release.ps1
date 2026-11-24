[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Archive,
    [Parameter(Mandatory)][ValidatePattern('^[a-fA-F0-9]{64}$')][string]$ExpectedSha256
)
$ErrorActionPreference = 'Stop'
& php (Join-Path $PSScriptRoot 'release-cli.php') verify $Archive $ExpectedSha256
exit $LASTEXITCODE
