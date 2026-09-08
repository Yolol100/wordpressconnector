<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/plugin/wordpressconnector/includes/Security/FilesystemPolicy.php';
use Webactueel\WordPressConnector\Security\FilesystemPolicy;
$mustContain = array(
    'plugin/wordpressconnector/wordpressconnector.php' => array('includes/Security/FilesystemPolicy.php', 'includes/Adapters/FilesystemAdapter.php'),
    'plugin/wordpressconnector/includes/Plugin.php' => array('new FilesystemAdapter()'),
    'plugin/wordpressconnector/includes/Security/Policy.php' => array('WPCONNECTOR_ALLOW_FILESYSTEM_WRITES', 'wpconnector_allow_filesystem_writes'),
    'plugin/wordpressconnector/includes/Admin/Settings.php' => array('wpconnector_allow_filesystem_writes', 'Allow controlled plugin/theme file writes'),
    'plugin/wordpressconnector/includes/REST/Controller.php' => array('filesystem_writes', 'WPCONNECTOR_ALLOW_FILESYSTEM_WRITES'),
    'plugin/wordpressconnector/includes/Adapters/FilesystemAdapter.php' => array('filesystem.inspect', 'filesystem.list', 'filesystem.read_text', 'filesystem.write_text', 'WP_Filesystem', "'direct' !== \$method", 'expected_sha256', 'TOKEN_PARSE', 'JSON_THROW_ON_ERROR', 'readback verification failed', "'_rollback'", 'assertNoEmbeddedSecrets', "'private_ajax_reused' => false"),
    'plugin/wordpressconnector/includes/Adapters/PluginSettingsAdapter.php' => array('shared_filesystem_interface', 'filesystem.inspect', 'filesystem.write_text', 'bounded_filesystem_bridge'),
    'docs/PLUGIN-CONTROL.md' => array('WP File Manager and controlled filesystem access', 'WP_Filesystem', 'expected_sha256', 'WordPress core'),
);
foreach ($mustContain as $relative => $needles) { $source = file_get_contents($root . '/' . $relative); if ($source === false) { fwrite(STDERR, "Unable to read {$relative}.\n"); exit(1); } foreach ($needles as $needle) { if (strpos($source, $needle) === false) { fwrite(STDERR, "Missing filesystem contract fragment in {$relative}: {$needle}\n"); exit(1); } } }
$writable = array('wp-content/plugins/example-plugin/example.php', 'wp-content/themes/example-theme/style.css');
foreach ($writable as $path) { if (FilesystemPolicy::assertWritableText($path) !== $path) { fwrite(STDERR, "Expected writable filesystem path rejected: {$path}\n"); exit(1); } }
$blockedWrites = array('../wp-config.php','wp-config.php','wp-admin/includes/file.php','wp-includes/load.php','wp-content/uploads/private.txt','wp-content/cache/cache.txt','wp-content/wflogs/attack-data.php','wp-content/updraft/backup.txt','wp-content/plugins/wordpressconnector/wordpressconnector.php','wp-content/plugins/example-plugin/.env','wp-content/plugins/example-plugin/private.pem','wp-content/plugins/example-plugin/image.png','/etc/passwd','wp-content/plugins/example-plugin/../other.php');
foreach ($blockedWrites as $path) { try { FilesystemPolicy::assertWritableText($path); fwrite(STDERR, "Unsafe filesystem write path accepted: {$path}\n"); exit(1); } catch (RuntimeException $error) {} }
$blockedListings = array('wp-content/uploads','wp-content/cache','wp-content/wflogs','wp-content/updraft');
foreach ($blockedListings as $path) { try { FilesystemPolicy::assertListable($path); fwrite(STDERR, "Managed data path unexpectedly listable: {$path}\n"); exit(1); } catch (RuntimeException $error) {} }
if (FilesystemPolicy::assertListable('.') !== '.') { fwrite(STDERR, "WordPress root listing contract failed.\n"); exit(1); }
if (FilesystemPolicy::assertReadableText('wp-admin/includes/file.php') !== 'wp-admin/includes/file.php') { fwrite(STDERR, "Read-only core inspection contract failed.\n"); exit(1); }
$adapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/FilesystemAdapter.php');
if ($adapter === false) { exit(1); }
foreach (array('mk_file_folder_manager_action_callback','elFinderConnector','shell_exec(','exec(','passthru(','proc_open(','popen(') as $forbidden) { if (strpos($adapter, $forbidden) !== false) { fwrite(STDERR, "Forbidden filesystem adapter primitive/protocol found: {$forbidden}\n"); exit(1); } }
$docs = file_get_contents($root . '/docs/PLUGIN-CONTROL.md'); if ($docs === false) { exit(1); }
foreach (array('AndrewBaeten.nl','Observed version','observed on 2026-') as $residue) { if (stripos($docs, $residue) !== false) { fwrite(STDERR, "Client/runtime residue remains in generic plugin-control docs: {$residue}\n"); exit(1); } }
echo "filesystem control contract OK\n";
