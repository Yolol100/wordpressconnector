# Requires Windows PowerShell 5.1+ or PowerShell 7, Git and Docker Engine.
# Executes only the repository's existing, offline PHP contract runner.
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[a-fA-F0-9]{40}$')]
    [string] $ExpectedSha,
    [ValidatePattern('^[A-Za-z0-9][A-Za-z0-9._/-]*(?::[A-Za-z0-9_.-]+|@sha256:[A-Fa-f0-9]{64})?$')]
    [string] $Image = 'wordpress:php8.3-apache',
    [string] $EvidenceDirectory = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$expected = $ExpectedSha.ToLowerInvariant()
if (-not (Get-Command git -ErrorAction SilentlyContinue)) { throw 'Git is unavailable.' }

$head = (& git -C $root rev-parse HEAD).Trim().ToLowerInvariant()
if ($LASTEXITCODE -ne 0 -or $head -ne $expected) {
    throw "Repository SHA mismatch: expected $expected, found $head. No Docker operation started."
}
function Assert-VerifiedSource {
    param([string] $Phase)
    $tracked = ((& git -C $root status --porcelain --untracked-files=no) -join '').Trim()
    if ($LASTEXITCODE -ne 0 -or $tracked) {
        throw "Tracked repository changes detected during $Phase; test a clean exact revision."
    }
    # The canonical runner also globs all .github/workflows/*.yml/yaml files.
    # Treat any untracked/ignored file under executable and inspected source
    # trees as untrusted input, not just PHP files.
    $inputPaths = @('plugin/wordpressconnector', 'tests', 'scripts', '.github/workflows', 'docs')
    $untracked = @(& git -C $root ls-files --others --exclude-standard -- $inputPaths)
    if ($LASTEXITCODE -ne 0) { throw "Cannot inspect untracked sources during $Phase." }
    $ignored = @(& git -C $root ls-files --others --ignored --exclude-standard -- $inputPaths)
    if ($LASTEXITCODE -ne 0) { throw "Cannot inspect ignored sources during $Phase." }
    if (@($untracked).Count -gt 0 -or @($ignored).Count -gt 0) {
        throw "Untracked or ignored source input detected during $Phase; refusing SHA-bound evidence."
    }
    # Git status trusts assume-unchanged and skip-worktree index flags.
    # Compare actual filesystem bytes against immutable HEAD, not index metadata.
    $headFiles = @(& git -C $root ls-tree -r HEAD -- $inputPaths)
    if ($LASTEXITCODE -ne 0 -or $headFiles.Count -eq 0) {
        throw "Cannot list HEAD source blobs during $Phase."
    }
    foreach ($entry in $headFiles) {
        if ($entry -notmatch '^(?:100644|100755) blob ([a-f0-9]{40})\t(.+)$') {
            throw "Unsupported tracked source entry during $Phase."
        }
        $expectedBlob = $Matches[1]
        $relative = $Matches[2]
        $absolute = Join-Path $root $relative
        if (-not (Test-Path -LiteralPath $absolute -PathType Leaf)) {
            throw "Tracked source file missing during $Phase."
        }
        $actualBlob = @(& git -C $root hash-object "--path=$relative" -- $absolute)
        if ($LASTEXITCODE -ne 0 -or $actualBlob.Count -ne 1 -or
            $actualBlob[0].Trim().ToLowerInvariant() -ne $expectedBlob) {
            throw "Source blob mismatch during $Phase; refusing SHA-bound evidence."
        }
    }
}
Assert-VerifiedSource -Phase 'preflight'
if (-not (Test-Path -LiteralPath (Join-Path $root 'scripts/run-contracts.sh'))) {
    throw 'Canonical contract runner not found.'
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Docker is unavailable.' }
if (-not (Get-Command python -ErrorAction SilentlyContinue)) { throw 'Host Python is required to run the deferred contracts.' }
if (-not (Get-Command node -ErrorAction SilentlyContinue)) { throw 'Host Node.js is required to run JavaScript contracts.' }
$dockerVersion = (& docker info --format '{{.ServerVersion}}' 2>$null).Trim()
if ($LASTEXITCODE -ne 0 -or -not $dockerVersion) { throw 'Docker Engine is unavailable.' }
$imageId = (& docker image inspect --format '{{.Id}}' $Image 2>$null).Trim()
if ($LASTEXITCODE -ne 0 -or $imageId -notmatch '^sha256:[a-fA-F0-9]{64}$') {
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
    '--label', "webactueel.wpconnector.qa-run=$runId",
    '--network', 'none', '--read-only',
    '--env', 'WPCONNECTOR_LOCAL_DOCKER_SPLIT_PYTHON=1',
    '--tmpfs', '/tmp:rw,nosuid,size=64m',
    '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
    '--mount', "type=bind,source=$root,target=/repo,readonly",
    '--workdir', '/repo', '--entrypoint', 'bash', $imageId,
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
    # A same-named pre-existing container must never be removed. Inspect
    # ownership label and delete only the exact inspected container ID.
    $priorPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $inspection = @(& docker container inspect --format '{{.Id}} {{json .Config.Labels}}' $container 2>$null)
        if ($LASTEXITCODE -eq 0 -and $inspection.Count -eq 1 -and
            $inspection[0] -match '^([a-f0-9]{64}) (.+)$') {
            $ownedContainerId = $Matches[1]
            $labels = $Matches[2] | ConvertFrom-Json
            if ($labels.'webactueel.wpconnector.qa-run' -eq $runId) {
                & docker rm --force $ownedContainerId 2>$null | Out-Null
            }
        }
    } catch { }
    $ErrorActionPreference = $priorPreference
}

# Host-only Python and Node tests complete the SHA-checked local Docker suite.
$pythonScripts = @('tests/release-supply-chain-tests.py',
    'tests/wpcs-regression-gate-tests.py',
    'tests/plan-readonly-audit-tests.py',
    'tests/plan-fleet-release-tests.py')
$pythonExitCode = 125
$nodeExitCode = 125
if ($exitCode -eq 0) {
    $deferred = @(Get-Content -LiteralPath $logPath | Where-Object { $_ -like 'LOCAL_PYTHON_DEFERRED *' })
    $expectedDeferred = @($pythonScripts | ForEach-Object { 'LOCAL_PYTHON_DEFERRED ' + $_ })
    if (($deferred -join [char]10) -ne ($expectedDeferred -join [char]10)) {
        Add-Content -LiteralPath $logPath -Value 'FAIL: deferred Python test list drift.'
        $pythonExitCode = 126
    } else {
        function Invoke-HostQATest {
            param([string] $Executable, [string[]] $Arguments)
            $prior = $ErrorActionPreference
            $ErrorActionPreference = 'Continue'
            try {
                & $Executable @Arguments 2>&1 | ForEach-Object { $_.ToString() } | Add-Content -LiteralPath $logPath
                $code = $LASTEXITCODE
            } finally {
                $ErrorActionPreference = $prior
            }
            return $code
        }
        Push-Location -LiteralPath $root
        try {
            $pythonExitCode = 0
            foreach ($path in $pythonScripts) {
                $pythonExitCode = Invoke-HostQATest -Executable 'python' -Arguments @('-I', '-B', $path)
                if ($pythonExitCode -ne 0) { break }
            }
            if ($pythonExitCode -eq 0) {
                $nodeExitCode = 0
                foreach ($path in @('plugin/wordpressconnector/assets/connection-doctor.js',
                                     'plugin/wordpressconnector/assets/admin-ui.js')) {
                    $nodeExitCode = Invoke-HostQATest -Executable 'node' -Arguments @('--check', $path)
                    if ($nodeExitCode -ne 0) { break }
                }
                if ($nodeExitCode -eq 0) {
                    foreach ($path in @('tests/mcp-admin-connection-doctor.js', 'tests/admin-ui-navigation.js')) {
                        $nodeExitCode = Invoke-HostQATest -Executable 'node' -Arguments @($path)
                        if ($nodeExitCode -ne 0) { break }
                    }
                }
            }
        } finally {
            Pop-Location
        }
    }
}
$log = Get-Content -LiteralPath $logPath -Raw
$warnings = [regex]::Matches($log, '(?m)^(?:PHP\s+)?(?:Warning|Notice|Deprecated|Fatal error):').Count
$headAfter = ((& git -C $root rev-parse HEAD 2>$null) -join '').Trim().ToLowerInvariant()
$headStable = $LASTEXITCODE -eq 0 -and $headAfter -eq $expected
$postflightOk = $false
try {
    Assert-VerifiedSource -Phase 'postflight'
    $postflightOk = $true
} catch {
    $postflightError = $_.Exception.Message
}
$pass = $exitCode -eq 0 -and $pythonExitCode -eq 0 -and $nodeExitCode -eq 0 -and $warnings -eq 0 -and $headStable -and $postflightOk
$receipt = [ordered]@{
    schema_version = '1.0'
    run_id = $runId
    started_at_utc = $started
    finished_at_utc = (Get-Date).ToUniversalTime().ToString('o')
    repository_head_sha = $head
    repository_head_after_sha = $headAfter
    repository_head_unchanged = $headStable
    docker_image = $Image
    docker_image_id = $imageId
    docker_engine_version = $dockerVersion
    evidence_level = 'controlled_runtime_contracts_only'
    result = $(if ($pass) { 'PASS' } else { 'FAIL' })
    exit_code = $exitCode
    python_exit_code = $pythonExitCode
    node_exit_code = $nodeExitCode
    php_warning_count = $warnings
    tracked_source_unchanged = $postflightOk
    postflight_error = $(if ($postflightOk) { $null } else { $postflightError })
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
