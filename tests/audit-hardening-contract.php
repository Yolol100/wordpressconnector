<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = array(
    'updater' => $root . '/plugin/wordpressconnector/includes/Adapters/ConnectorUpdateAdapter.php',
    'package adapter' => $root . '/plugin/wordpressconnector/includes/Adapters/PluginPackageAdapter.php',
    'builder' => $root . '/scripts/build-release-package.py',
    'uninstall' => $root . '/plugin/wordpressconnector/uninstall.php',
    'settings' => $root . '/plugin/wordpressconnector/includes/Admin/Settings.php',
    'ci' => $root . '/.github/workflows/ci.yml',
    'release' => $root . '/.github/workflows/release.yml',
    'agents' => $root . '/AGENTS.md',
);

$sources = array();
foreach ($files as $name => $path) {
    $source = file_get_contents($path);
    if (false === $source) {
        fwrite(STDERR, "Unable to read audit source: {$name}.\n");
        exit(1);
    }
    $sources[$name] = $source;
}

$require = static function (string $name, array $needles) use ($sources): void {
    foreach ($needles as $needle) {
        if (false === strpos($sources[$name], $needle)) {
            fwrite(STDERR, "Audit hardening contract missing in {$name}: {$needle}\n");
            exit(1);
        }
    }
};

$require('updater', array(
    "SBOM_ASSET = 'wordpressconnector.spdx.json'",
    "'capability' => 'update_plugins'",
    "'package_digest' => \$packageDigest",
    "'checksum_digest' => \$checksumDigest",
    "! empty(\$decoded['draft'])",
    "! empty(\$decoded['prerelease'])",
    "hash_equals(\$expectedAssetDigest, \$actualAssetDigest)",
    "hash_equals(\$release['package_digest'], 'sha256:' . strtolower(\$actualSha256))",
));
if (2 !== substr_count($sources['updater'], "'capability' => 'update_plugins'")) {
    fwrite(STDERR, "Both canonical connector updater actions must declare update_plugins capability.\n");
    exit(1);
}

$require('package adapter', array("'capability' => 'install_plugins'"));

$require('builder', array(
    "'algorithm': 'SHA1'",
    "'packageVerificationCode'",
    'package_verification_hashes',
    "sorted(package_verification_hashes)",
));

$require('uninstall', array(
    'WP_UNINSTALL_PLUGIN',
    'wpconnector_rest_enabled',
    'wpconnector_allow_writes',
    'wpconnector_allow_privileged',
    'wpconnector_allow_sensitive',
    'wpconnector_allow_system_updates',
    'wpconnector_allow_filesystem_writes',
    'wpconnector_mutation_lock',
    'wpconnector_snapshot_',
    'wpconnector_processed_',
    'get_sites(',
    'switch_to_blog(',
    'restore_current_blog(',
));

if (false !== strpos($sources['settings'], 'WP Agent')) {
    fwrite(STDERR, "Admin settings must not advertise the retired WP Agent preference.\n");
    exit(1);
}
$require('settings', array('guarded GitHub runtime'));

foreach (array("'7.4'", "'8.0'", "'8.1'", "'8.2'", "'8.3'", "'8.4'", "'8.5'") as $version) {
    if (false === strpos($sources['ci'], $version)) {
        fwrite(STDERR, "Declared PHP support is missing from CI matrix: {$version}.\n");
        exit(1);
    }
}
$require('ci', array(
    'WordPress/plugin-check-action@10857da14b6c2246d15402b3e69f777edcf8c12e',
    'persist-credentials: false',
    'bash scripts/run-contracts.sh',
));
$require('release', array(
    'id-token: write',
    'attestations: write',
    'actions/attest@1e69f48acb82d1966a394da916b4c1698aa569d6',
));
$require('agents', array('bash scripts/run-contracts.sh', 'full commit SHA', 'uninstall removes'));

foreach (array(
    $root . '/.github/workflows/audit-updater-apply.yml',
    $root . '/scripts/apply-updater-hardening.py',
) as $temporary) {
    if (file_exists($temporary)) {
        fwrite(STDERR, "Temporary self-writing audit helper must not remain in the repository: {$temporary}\n");
        exit(1);
    }
}

echo "audit hardening contract OK\n";
