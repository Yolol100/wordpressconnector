<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/ConnectorUpdateAdapter.php');
$bootstrap = file_get_contents($root . '/plugin/wordpressconnector/wordpressconnector.php');
$plugin = file_get_contents($root . '/plugin/wordpressconnector/includes/Plugin.php');
$readme = file_get_contents($root . '/plugin/wordpressconnector/readme.txt');
$workflow = file_get_contents($root . '/.github/workflows/release.yml');
$builder = file_get_contents($root . '/scripts/build-release-package.py');
$catalog = file_get_contents($root . '/docs/ACTION-CATALOG.md');

foreach (array('adapter' => $adapter, 'bootstrap' => $bootstrap, 'plugin' => $plugin, 'readme' => $readme, 'release workflow' => $workflow, 'release builder' => $builder, 'catalog' => $catalog) as $name => $source) {
    if (false === $source) {
        fwrite(STDERR, "Unable to read connector update {$name}.\n");
        exit(1);
    }
}

$adapterRequired = array(
    "'connector.update.check'",
    "'connector.update.apply'",
    "'system_update' => true",
    "'public_repository_safe' => true",
    'https://api.github.com/repos/Yolol100/wordpressconnector/releases/latest',
    "PACKAGE_ASSET = 'wordpressconnector.zip'",
    "CHECKSUM_ASSET = 'wordpressconnector.zip.sha256'",
    "PLUGIN_FILE = 'wordpressconnector/wordpressconnector.php'",
    'wp_safe_remote_get',
    'download_url(',
    "hash_file('sha256'",
    'hash_equals($expectedSha256',
    'ZipArchive',
    'getExternalAttributesIndex',
    '0xA000',
    "current_user_can('update_plugins')",
    "overwrite_package' => true",
    'wp_clean_plugins_cache(true)',
    "rollback_supported' => false",
);
foreach ($adapterRequired as $needle) {
    if (strpos($adapter, $needle) === false) {
        fwrite(STDERR, "Missing connector update contract fragment: {$needle}\n");
        exit(1);
    }
}

foreach (array('eval(', 'shell_exec(', 'passthru(', 'proc_open(', 'popen(', 'wp_remote_get(', 'wp_remote_request(') as $forbidden) {
    if (strpos($adapter, $forbidden) !== false) {
        fwrite(STDERR, "Forbidden connector update primitive: {$forbidden}\n");
        exit(1);
    }
}

if (substr_count($adapter, "'public_repository_safe' => true") !== 2) {
    fwrite(STDERR, "Only the two canonical connector self-update actions may carry the public_repository_safe marker in ConnectorUpdateAdapter.\n");
    exit(1);
}
if (strpos($adapter, "Yolol100/wordpressconnector/releases/download/") === false) {
    fwrite(STDERR, "Connector updater is not pinned to the canonical GitHub release path.\n");
    exit(1);
}
if (strpos($bootstrap, "includes/Adapters/ConnectorUpdateAdapter.php") === false || strpos($plugin, 'new ConnectorUpdateAdapter()') === false) {
    fwrite(STDERR, "Connector updater is not bootstrapped and registered.\n");
    exit(1);
}
if (! preg_match('/^[ \t*#\/]*Version:[ \t]*([0-9]+\.[0-9]+\.[0-9]+)/mi', $bootstrap, $version) ||
    ! preg_match('/^Stable tag:[ \t]*([0-9]+\.[0-9]+\.[0-9]+)/mi', $readme, $stable) ||
    $version[1] !== $stable[1] ||
    strpos($bootstrap, "WPCONNECTOR_VERSION','" . $version[1] . "'") === false) {
    fwrite(STDERR, "Connector release metadata is not aligned.\n");
    exit(1);
}

$workflowRequired = array(
    'workflow_run:',
    'workflows: ["Connector CI"]',
    "github.event.workflow_run.conclusion == 'success'",
    "github.event.workflow_run.head_branch == 'main'",
    "github.event.workflow_run.event == 'push'",
    'contents: write',
    'id-token: write',
    'attestations: write',
    'ref: ${{ github.event.workflow_run.head_sha }}',
    'scripts/build-release-package.py',
    'wordpressconnector.zip.sha256',
    'wordpressconnector.spdx.json',
    'actions/attest@1e69f48acb82d1966a394da916b4c1698aa569d6',
    'Existing release ${tag} has different plugin bytes.',
    'cmp "$RUNNER_TEMP/wordpressconnector.zip"',
    'gh release create',
    '--target "${{ github.event.workflow_run.head_sha }}"',
    'Verify published release bytes',
    'sha256sum -c wordpressconnector.zip.sha256',
);
foreach ($workflowRequired as $needle) {
    if (strpos($workflow, $needle) === false) {
        fwrite(STDERR, "Missing hardened connector release workflow guard: {$needle}\n");
        exit(1);
    }
}
if (strpos($workflow, 'pull_request_target') !== false || strpos($workflow, 'Publish immutable GitHub release') !== false) {
    fwrite(STDERR, "Connector release workflow contains a forbidden privileged event or false immutability claim.\n");
    exit(1);
}

$builderRequired = array(
    "date_time=fixed_time",
    "fixed_time = (1980, 1, 1, 0, 0, 0)",
    "stat.S_IFREG | 0o644",
    "sort_keys=True",
    "'spdxVersion': 'SPDX-2.3'",
    "'algorithm': 'SHA256'",
    "Symlinks are forbidden in release packages",
);
foreach ($builderRequired as $needle) {
    if (strpos($builder, $needle) === false) {
        fwrite(STDERR, "Missing deterministic release-builder contract: {$needle}\n");
        exit(1);
    }
}

foreach (array(
    '| `connector.update.check` | privileged |',
    '| `connector.update.apply` | mutation, privileged, system_update |',
) as $row) {
    if (strpos($catalog, $row) === false) {
        fwrite(STDERR, "Connector update action catalog row missing: {$row}\n");
        exit(1);
    }
}

echo "connector update contract OK\n";
