# Requires Windows PowerShell 5.1+ or PowerShell 7, Git and Docker Engine.
# Executes only the repository's existing, offline PHP contract runner.
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[a-fA-F0-9]{40}$')]
    [string] $ExpectedSha,
    [ValidatePattern('^[A-Za-z0-9][A-Za-z0-9._/-]*(?::[A-Za-z0-9_.-]+|@sha256:[a-fA-F0-9]{64})?
    [string] $EvidenceDirectory = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$expected = $ExpectedSha.ToLowerInvariant()
if (-not (Get-Command git -ErrorAction SilentlyContinue)) { throw 'Git is unavailable.' }
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Docker is unavailable.' }

$head = (& git -C $root rev-parse HEAD).Trim().ToLowerInvariant()
if ($LASTEXITCODE -ne 0 -or $head -ne $expected) {
    throw "Repository SHA mismatch: expected $expected, found $head. No Docker operation started."
}
function Assert-RepositoryClean {
    param([string] $Phase)
    $currentSha = ((& git -C $root rev-parse HEAD) -join '').Trim().ToLowerInvariant()
    if ($LASTEXITCODE -ne 0 -or $currentSha -ne $expected) {
        throw "Repository SHA changed during $Phase. No result can be accepted."
    }
    $dirty = ((& git -C $root status --porcelain --untracked-files=no) -join '').Trim()
    if ($LASTEXITCODE -ne 0 -or $dirty) {
        throw "Tracked repository changes detected during $Phase."
    }
    # Contract runner recursively reads tests/, scripts/ and plugin/ PHP/JS.
    foreach ($opts in @(@('--others', '--exclude-standard'), @('--others', '--ignored', '--exclude-standard'))) {
        $files = @(& git -C $root ls-files @opts -- plugin/wordpressconnector tests scripts)
        if ($LASTEXITCODE -ne 0) { throw "Untracked source preflight unavailable during $Phase." }
        if ($files.Count -gt 0) { throw "Untracked or ignored files in executable test directories during $Phase." }
    }
}
Assert-RepositoryClean -Phase 'preflight'
if (-not (Test-Path -LiteralPath (Join-Path $root 'scripts/run-contracts.sh'))) {
    throw 'Canonical contract runner not found.'
}

$dockerVersion = (& docker info --format '{{.ServerVersion}}' 2>$null).Trim()
if ($LASTEXITCODE -ne 0 -or -not $dockerVersion) { throw 'Docker Engine is unavailable.' }
$imageId = (& docker image inspect --format '{{.Id}}' $Image 2>$null).Trim()
if ($LASTEXITCODE -ne 0 -or -not $imageId) {
    throw "Local Docker image $Image is missing. No automatic pull is allowed."
}

if (-not $EvidenceDirectory) {
    $EvidenceDirectory = Join-Path ([System.IO.Path]::GetTempPath()) 'wordpressconnector-qa'
}
$runId = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ') + '-' + [guid]::NewGuid().ToString('N').Substring(0, 8)
$runDir = Join-Path $EvidenceDirectory $runId
New-Item -ItemType Directory -Path $runDir -Force | Out-Null
$logPath = Join-Path $runDir 'docker-contracts.log'
$receiptPath = Join-Path $runDir 'receipt.json'
$container = 'wpconnector-qa-' + $runId.Substring($runId.Length - 8)
$started = (Get-Date).ToUniversalTime().ToString('o')

# No production credentials, network, privileged mode, daemon socket mount or writable source.
$arguments = @(
    'run', '--rm', '--pull', 'never', '--name', $container,
    '--network', 'none', '--read-only',
    '--tmpfs', '/tmp:rw,nosuid,size=64m',
    '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
    '--mount', "type=bind,source=$root,target=/repo,readonly",
    '--workdir', '/repo', '--entrypoint', 'bash', $Image,
    'scripts/run-contracts.sh'
)

$exitCode = 125
try {
    # Windows PowerShell can wrap native stderr as a non-terminating error.
    $priorPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & docker @arguments 2>&1 | ForEach-Object { $_.ToString() } | Set-Content -LiteralPath $logPath -Encoding UTF8
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $priorPreference
    }
} finally {
    # Name is uniquely generated for this one test; never inspect/remove unrelated containers.
    $priorPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { & docker rm --force $container 2>$null | Out-Null } catch { }
    $ErrorActionPreference = $priorPreference
}

$log = Get-Content -LiteralPath $logPath -Raw
$warnings = [regex]::Matches($log, '(?m)^(?:PHP\s+)?(?:Warning|Notice|Deprecated|Fatal error):').Count
$postflightOk = $true
try { Assert-RepositoryClean -Phase 'postflight' } catch { $postflightOk = $false }
$pass = $exitCode -eq 0 -and $warnings -eq 0 -and $postflightOk
$receipt = [ordered]@{
    schema_version = '1.0'
    run_id = $runId
    started_at_utc = $started
    finished_at_utc = (Get-Date).ToUniversalTime().ToString('o')
    repository_head_sha = $head
    docker_image = $Image
    docker_image_id = $imageId
    docker_engine_version = $dockerVersion
    evidence_level = 'controlled_runtime_contracts_only'
    result = $(if ($pass) { 'PASS' } else { 'FAIL' })
    exit_code = $exitCode
    php_warning_count = $warnings
    tracked_source_unchanged = $postflightOk
    no_production_site_or_write_validation = $true
    log_file = 'docker-contracts.log'
    log_sha256 = (Get-FileHash -LiteralPath $logPath -Algorithm SHA256).Hash.ToLowerInvariant()
}
$receipt | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $receiptPath -Encoding UTF8
Write-Output ("RESULT=" + $receipt.result)
Write-Output ("SOURCE_SHA=" + $head)
Write-Output ("LOG_SHA256=" + $receipt.log_sha256)
Write-Output ("RECEIPT=" + $receiptPath)
if (-not $pass) { exit 1 }
)]
    [string] $Image = 'wordpress:php8.3-apache',
    [string] $EvidenceDirectory = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$expected = $ExpectedSha.ToLowerInvariant()
if (-not (Get-Command git -ErrorAction SilentlyContinue)) { throw 'Git is unavailable.' }
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Docker is unavailable.' }

$head = (& git -C $root rev-parse HEAD).Trim().ToLowerInvariant()
if ($LASTEXITCODE -ne 0 -or $head -ne $expected) {
    throw "Repository SHA mismatch: expected $expected, found $head. No Docker operation started."
}
$dirty = ((& git -C $root status --porcelain --untracked-files=no) -join '').Trim()
if ($LASTEXITCODE -ne 0 -or $dirty) {
    throw 'Tracked repository changes detected; test a clean exact revision.'
}
if (-not (Test-Path -LiteralPath (Join-Path $root 'scripts/run-contracts.sh'))) {
    throw 'Canonical contract runner not found.'
}

$dockerVersion = (& docker info --format '{{.ServerVersion}}' 2>$null).Trim()
if ($LASTEXITCODE -ne 0 -or -not $dockerVersion) { throw 'Docker Engine is unavailable.' }
$imageId = (& docker image inspect --format '{{.Id}}' $Image 2>$null).Trim()
if ($LASTEXITCODE -ne 0 -or -not $imageId) {
    throw "Local Docker image $Image is missing. No automatic pull is allowed."
}

if (-not $EvidenceDirectory) {
    $EvidenceDirectory = Join-Path ([System.IO.Path]::GetTempPath()) 'wordpressconnector-qa'
}
$runId = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ') + '-' + [guid]::NewGuid().ToString('N').Substring(0, 8)
$runDir = Join-Path $EvidenceDirectory $runId
New-Item -ItemType Directory -Path $runDir -Force | Out-Null
$logPath = Join-Path $runDir 'docker-contracts.log'
$receiptPath = Join-Path $runDir 'receipt.json'
$container = 'wpconnector-qa-' + $runId.Substring($runId.Length - 8)
$started = (Get-Date).ToUniversalTime().ToString('o')

# No production credentials, network, privileged mode, daemon socket mount or writable source.
$arguments = @(
    'run', '--rm', '--pull', 'never', '--name', $container,
    '--network', 'none', '--read-only',
    '--tmpfs', '/tmp:rw,nosuid,size=64m',
    '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
    '--mount', "type=bind,source=$root,target=/repo,readonly",
    '--workdir', '/repo', '--entrypoint', 'bash', $Image,
    'scripts/run-contracts.sh'
)

$exitCode = 125
try {
    # Windows PowerShell can wrap native stderr as a non-terminating error.
    $priorPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & docker @arguments 2>&1 | ForEach-Object { $_.ToString() } | Set-Content -LiteralPath $logPath -Encoding UTF8
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $priorPreference
    }
} finally {
    # Name is uniquely generated for this one test; never inspect/remove unrelated containers.
    $priorPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { & docker rm --force $container 2>$null | Out-Null } catch { }
    $ErrorActionPreference = $priorPreference
}

$log = Get-Content -LiteralPath $logPath -Raw
$warnings = [regex]::Matches($log, '(?m)^(?:PHP\s+)?(?:Warning|Notice|Deprecated|Fatal error):').Count
$trackedAfter = ((& git -C $root status --porcelain --untracked-files=no) -join '').Trim()
$pass = $exitCode -eq 0 -and $warnings -eq 0 -and -not $trackedAfter
$receipt = [ordered]@{
    schema_version = '1.0'
    run_id = $runId
    started_at_utc = $started
    finished_at_utc = (Get-Date).ToUniversalTime().ToString('o')
    repository_head_sha = $head
    docker_image = $Image
    docker_image_id = $imageId
    docker_engine_version = $dockerVersion
    evidence_level = 'controlled_runtime_contracts_only'
    result = $(if ($pass) { 'PASS' } else { 'FAIL' })
    exit_code = $exitCode
    php_warning_count = $warnings
    tracked_source_unchanged = -not [bool] $trackedAfter
    no_production_site_or_write_validation = $true
    log_file = 'docker-contracts.log'
    log_sha256 = (Get-FileHash -LiteralPath $logPath -Algorithm SHA256).Hash.ToLowerInvariant()
}
$receipt | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $receiptPath -Encoding UTF8
Write-Output ("RESULT=" + $receipt.result)
Write-Output ("SOURCE_SHA=" + $head)
Write-Output ("LOG_SHA256=" + $receipt.log_sha256)
Write-Output ("RECEIPT=" + $receiptPath)
if (-not $pass) { exit 1 }
