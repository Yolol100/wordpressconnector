<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/ConnectorUpdateAdapter.php');
$bootstrap = file_get_contents($root . '/plugin/wordpressconnector/wordpressconnector.php');
$plugin = file_get_contents($root . '/plugin/wordpressconnector/includes/Plugin.php');
$readme = file_get_contents($root . '/plugin/wordpressconnector/readme.txt');
$workflow = file_get_contents($root . '/.github/workflows/release.yml');
$catalog = file_get_contents($root . '/docs/ACTION-CATALOG.md');

foreach (array('adapter' => $adapter, 'bootstrap' => $bootstrap, 'plugin' => $plugin, 'readme' => $readme, 'release workflow' => $workflow, 'catalog' => $catalog) as $name => $source) {
    if (false === $source) {
        fwrite(STDERR, "Unable to read connector update {$name}.\n");
        exit(1);
    }
}

$adapterRequired = array(
    "'connector.update.check'",
    "'connector.update.apply'",
    "'system_update' => true",
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
    'permissions:',
    'contents: write',
    'ref: ${{ github.event.workflow_run.head_sha }}',
    'wordpressconnector.zip.sha256',
    'sha256sum',
    'gh release create',
    '--target "${{ github.event.workflow_run.head_sha }}"',
);
foreach ($workflowRequired as $needle) {
    if (strpos($workflow, $needle) === false) {
        fwrite(STDERR, "Missing connector release workflow guard: {$needle}\n");
        exit(1);
    }
}
if (strpos($workflow, 'pull_request_target') !== false) {
    fwrite(STDERR, "Connector release workflow must not use pull_request_target.\n");
    exit(1);
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
