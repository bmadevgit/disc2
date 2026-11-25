[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$Tag,
    [Parameter(Mandatory)][string]$SignerFingerprint,
    [Parameter(Mandatory)][string]$Output,
    [string]$ComposerPhar
)
$ErrorActionPreference = 'Stop'
if (!$ComposerPhar) {
    $composer = Get-Command composer -CommandType Application -ErrorAction Stop
    if ($composer.Source -match '\.(bat|cmd)$') {
        $ComposerPhar = Join-Path (Split-Path -Parent $composer.Source) 'composer.phar'
    }
}
$arguments = @((Join-Path $PSScriptRoot 'release-cli.php'), 'build', $Tag, $SignerFingerprint, $Output)
if ($ComposerPhar) { $arguments += $ComposerPhar }
& php @arguments
exit $LASTEXITCODE
