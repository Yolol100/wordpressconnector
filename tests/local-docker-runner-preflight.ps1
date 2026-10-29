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
    catch { $blocked = $_.Exception.Message -match 'Untracked or ignored source input' }
    if (-not $blocked) { throw 'Untracked PHP must be rejected before Docker.' }
} finally {
    Remove-Item -LiteralPath $probe -Force -ErrorAction SilentlyContinue
}
# The stricter source boundary must reject non-PHP files too.
$probe = Join-Path $root 'tests/__local_docker_untracked_negative.js'
if (Test-Path -LiteralPath $probe) { throw 'QA non-PHP probe already exists.' }
try {
    [System.IO.File]::WriteAllText($probe, '/* temporary untracked QA input */')
    $blocked = $false
    try { & $runner -ExpectedSha $sha | Out-Null }
    catch { $blocked = $_.Exception.Message -match 'Untracked or ignored source input' }
    if (-not $blocked) { throw 'Untracked non-PHP input must be rejected before Docker.' }
} finally {
    Remove-Item -LiteralPath $probe -Force -ErrorAction SilentlyContinue
}
# Reproduce the index-flag bypass: git status reports clean, but HEAD bytes differ.
$trackedRelative = 'tests/local-docker-contract-runner-contract.php'
$trackedFile = Join-Path $root $trackedRelative
$originalBytes = [System.IO.File]::ReadAllBytes($trackedFile)
$flagSet = $false
try {
    & git -C $root update-index --assume-unchanged -- $trackedRelative | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Cannot prepare index-flag regression.' }
    $flagSet = $true
    $extra = [System.Text.Encoding]::UTF8.GetBytes("hidden local change")
    [System.IO.File]::WriteAllBytes($trackedFile, [byte[]]($originalBytes + $extra))
    $reported = ((& git -C $root status --porcelain --untracked-files=no) -join '').Trim()
    if ($LASTEXITCODE -ne 0 -or $reported) { throw 'Index-flag fixture did not hide the modified file.' }
    $blocked = $false
    try { & $runner -ExpectedSha $sha | Out-Null }
    catch { $blocked = $_.Exception.Message -match 'Source blob mismatch' }
    if (-not $blocked) { throw 'Modified tracked bytes must be rejected despite assume-unchanged.' }
} finally {
    [System.IO.File]::WriteAllBytes($trackedFile, $originalBytes)
    if ($flagSet) { & git -C $root update-index --no-assume-unchanged -- $trackedRelative | Out-Null }
}
Write-Output 'Windows Docker parser, SHA-bound source blobs and negative input regressions OK'
