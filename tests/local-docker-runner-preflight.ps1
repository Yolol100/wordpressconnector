# Optional live PowerShell parser and negative-preflight regressions.
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$runner = Join-Path $root 'scripts/local-docker-contracts.ps1'
$tokens = $null
$parseErrors = $null
[void] [System.Management.Automation.Language.Parser]::ParseFile($runner, [ref] $tokens, [ref] $parseErrors)
if ($parseErrors.Count -ne 0) { throw 'Windows Docker runner has PowerShell syntax errors.' }
$sha = ((& git -C $root rev-parse HEAD) -join '').Trim()
if ($LASTEXITCODE -ne 0 -or $sha -notmatch '^[a-fA-F0-9]{40}$') { throw 'Cannot resolve Git SHA.' }
$blocked = $false
try { & $runner -ExpectedSha ('0' * 40) | Out-Null }
catch { $blocked = $_.Exception.Message -match 'Repository SHA mismatch' }
if (-not $blocked) { throw 'Wrong SHA must be rejected before Docker.' }
$blocked = $false
try { & $runner -ExpectedSha $sha -Image '--help' | Out-Null }
catch { $blocked = $true }
if (-not $blocked) { throw 'Option-like Docker image must be rejected.' }

$probe = Join-Path $root 'tests/__local_docker_untracked_negative.php'
if (Test-Path -LiteralPath $probe) { throw 'QA probe location already exists.' }
try {
    [System.IO.File]::WriteAllText($probe, '<?php // temporary untracked QA input')
    $blocked = $false
    try { & $runner -ExpectedSha $sha | Out-Null }
    catch { $blocked = $_.Exception.Message -match 'Untracked PHP source input' }
    if (-not $blocked) { throw 'Untracked PHP must be rejected before Docker.' }
} finally {
    Remove-Item -LiteralPath $probe -Force -ErrorAction SilentlyContinue
}
Write-Output 'Windows Docker parser, wrong SHA, image option and untracked PHP regressions OK'
