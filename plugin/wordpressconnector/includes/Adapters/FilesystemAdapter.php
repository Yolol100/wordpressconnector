<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use ParseError;
use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\FilesystemPolicy;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class FilesystemAdapter
{
	private const MAX_TEXT_BYTES = 131072;
	private const MAX_LIST_ENTRIES = 200;

	public function register(Registry $registry): void
	{
		$registry->register('filesystem.inspect', array($this, 'inspect'), array(
			'privileged' => true,
			'description' => 'Inspect safe WordPress filesystem capabilities and WP File Manager integration state without exposing absolute paths.',
		));
		$registry->register('filesystem.list', array($this, 'listPath'), array(
			'privileged' => true,
			'description' => 'List a bounded directory inside the WordPress root while hiding secret and dotfile paths.',
		));
		$registry->register('filesystem.read_text', array($this, 'readText'), array(
			'privileged' => true,
			'description' => 'Read a bounded non-secret text file inside the WordPress root.',
		));
		$registry->register('filesystem.write_text', array($this, 'writeText'), array(
			'mutation' => true,
			'privileged' => true,
			'description' => 'Replace an existing plugin/theme text file with checksum guard, syntax validation, readback and rollback.',
		));
		$registry->register('filesystem.create_text', array($this, 'createText'), array(
			'mutation' => true,
			'privileged' => true,
			'description' => 'Create a new non-PHP plugin/theme text asset in an existing directory with dry-run, readback and rollback.',
		));
		$registry->register('filesystem.delete_text', array($this, 'deleteText'), array(
			'mutation' => true,
			'privileged' => true,
			'description' => 'Delete a guarded non-PHP plugin/theme text asset with exact SHA-256, readback and rollback.',
		));
	}

	public function inspect(array $payload = array(), array $context = array()): array
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugins = get_plugins();
		$fileManagerFile = 'wp-file-manager/file_folder_manager.php';
		$fileManager = isset($plugins[$fileManagerFile]) ? $plugins[$fileManagerFile] : array();
		$settings = get_option('wp_file_manager_settings', array());
		$configuredPath = is_array($settings) && isset($settings['public_path']) ? (string) $settings['public_path'] : '';

		return array(
			'root' => 'wordpress_root',
			'filesystem_method' => (string) get_filesystem_method(array(), ABSPATH),
			'write_gate' => Policy::flag('WPCONNECTOR_ALLOW_FILESYSTEM_WRITES'),
			'read' => array(
				'root_listing' => true,
				'text_max_bytes' => self::MAX_TEXT_BYTES,
				'secret_files' => 'blocked',
				'managed_data_paths' => 'use dedicated WordPress/media/system actions',
			),
			'write' => array(
				'scope' => array('replace existing wp-content/plugins/* and wp-content/themes/* text files', 'create/delete non-PHP text assets in existing plugin/theme directories'),
				'core' => false,
				'uploads' => false,
				'connector_self_update' => false,
				'requires' => array('privileged gate', 'writes gate', 'filesystem-writes gate', 'confirm=true', 'expected_sha256 for delete/replace', 'no existing file for create'),
				'parser_validation' => array('php', 'inc', 'json'),
				'readback' => 'sha256',
				'rollback' => true,
			),
			'wp_file_manager' => array(
				'installed' => ! empty($fileManager),
				'active' => function_exists('is_plugin_active') && is_plugin_active($fileManagerFile),
				'version' => isset($fileManager['Version']) ? (string) $fileManager['Version'] : null,
				'configured_root_mode' => $this->fileManagerRootMode($configuredPath),
				'connector_transport' => 'WP_Filesystem',
				'private_ajax_reused' => false,
			),
		);
	}

	public function listPath(array $payload, array $context = array()): array
	{
		$relative = FilesystemPolicy::assertListable(isset($payload['path']) ? (string) $payload['path'] : '.');
		$absolute = $this->resolveExisting($relative, true);
		if (! is_dir($absolute)) {
			throw new RuntimeException('Filesystem path is not a directory.');
		}

		$names = scandir($absolute);
		if (false === $names) {
			throw new RuntimeException('Could not list filesystem directory.');
		}
		natcasesort($names);
		$items = array();
		$truncated = false;
		foreach ($names as $name) {
			if ('.' === $name || '..' === $name) {
				continue;
			}
			$child = '.' === $relative ? $name : $relative . '/' . $name;
			try {
				$child = FilesystemPolicy::assertListable($child);
				$childAbsolute = $this->resolveExisting($child, true);
			} catch (RuntimeException $error) {
				continue;
			}
			if (count($items) >= self::MAX_LIST_ENTRIES) {
				$truncated = true;
				break;
			}
			$items[] = array(
				'name' => $name,
				'path' => $child,
				'type' => is_dir($childAbsolute) ? 'directory' : 'file',
				'bytes' => is_file($childAbsolute) ? (int) filesize($childAbsolute) : null,
				'modified_gmt' => $this->modifiedGmt($childAbsolute),
				'writable_text_scope' => is_file($childAbsolute) && FilesystemPolicy::isWritableText($child),
			);
		}

		return array(
			'path' => $relative,
			'entries' => $items,
			'truncated' => $truncated,
			'max_entries' => self::MAX_LIST_ENTRIES,
		);
	}

	public function readText(array $payload, array $context = array()): array
	{
		$relative = FilesystemPolicy::assertReadableText(isset($payload['path']) ? (string) $payload['path'] : '');
		$absolute = $this->resolveExisting($relative, false);
		if (! is_file($absolute)) {
			throw new RuntimeException('Filesystem path is not a regular file.');
		}
		$size = filesize($absolute);
		if (false === $size || $size < 0 || $size > self::MAX_TEXT_BYTES) {
			throw new RuntimeException('Text file exceeds the connector read limit.');
		}
		$content = file_get_contents($absolute, false, null, 0, self::MAX_TEXT_BYTES + 1);
		if (false === $content || ! $this->isUtf8($content)) {
			throw new RuntimeException('Filesystem file is not readable UTF-8 text.');
		}
		// Recheck the bytes actually read. filesize() can be stale if another
		// process grows the file between stat() and file_get_contents().
		if (strlen($content) > self::MAX_TEXT_BYTES) {
			throw new RuntimeException('Text file exceeds the connector read limit.');
		}
		$this->assertNoEmbeddedSecrets($content);
		$state = $this->state($relative, $content, $absolute);

		return array(
			'file' => $state,
			'content' => $content,
			'fingerprint' => Fingerprint::make($state),
		);
	}

	public function writeText(array $payload, array $context): array
	{
		if (empty($context['dry_run']) && ! Policy::flag('WPCONNECTOR_ALLOW_FILESYSTEM_WRITES')) {
			throw new RuntimeException('Filesystem writes are disabled. Enable the dedicated filesystem-writes gate first.');
		}

		$relative = FilesystemPolicy::assertWritableText(isset($payload['path']) ? (string) $payload['path'] : '');
		$activeContent = FilesystemPolicy::affectsBrowserExecution($relative);
		$requiredCapability = 0 === strpos($relative, 'wp-content/plugins/') ? 'edit_plugins' : 'edit_themes';
		if (! current_user_can($requiredCapability)) {
			throw new RuntimeException('Current user lacks the required filesystem capability: ' . $requiredCapability . '.');
		}
		$absolute = $this->resolveExisting($relative, false);
		if (! is_file($absolute)) {
			throw new RuntimeException('filesystem.write_text only replaces existing regular files.');
		}
		$before = file_get_contents($absolute, false, null, 0, self::MAX_TEXT_BYTES + 1);
		if (false === $before || ! $this->isUtf8($before)) {
			throw new RuntimeException('Existing file is not readable UTF-8 text.');
		}
		if (strlen($before) > self::MAX_TEXT_BYTES) {
			throw new RuntimeException('Existing file exceeds the connector write limit.');
		}
		$this->assertNoEmbeddedSecrets($before);
		if (! array_key_exists('content', $payload) || ! is_string($payload['content'])) {
			throw new RuntimeException('filesystem.write_text requires payload.content as UTF-8 text.');
		}
		$content = $payload['content'];
		if (strlen($content) > self::MAX_TEXT_BYTES || ! $this->isUtf8($content)) {
			throw new RuntimeException('Replacement content must be UTF-8 text within the connector write limit.');
		}

		$this->validateText($relative, $content);
		$this->assertNoEmbeddedSecrets($content);
		$beforeState = $this->state($relative, $before, $absolute);
		$afterState = array(
			'path' => $relative,
			'bytes' => strlen($content),
			'sha256' => hash('sha256', $content),
		);
		$result = array(
			'before' => $beforeState,
			'after' => $afterState,
			'_current_fingerprint' => Fingerprint::make($beforeState),
			'requires_active_content_acknowledgement' => $activeContent,
		);

		if (! empty($context['dry_run'])) {
			return $result;
		}

		if ($activeContent && ($payload['acknowledge_active_content'] ?? null) !== true) {
			throw new RuntimeException('Browser-active HTML, JavaScript and CSS file replacements require acknowledge_active_content=true.');
		}
		if (function_exists('wp_is_file_mod_allowed') && ! wp_is_file_mod_allowed('connector_filesystem')) {
			throw new RuntimeException('WordPress or a policy plugin disallows file modifications; check DISALLOW_FILE_MODS and file_mod_allowed.');
		}

		$expectedSha = isset($payload['expected_sha256']) ? strtolower((string) $payload['expected_sha256']) : '';
		if (! preg_match('/^[a-f0-9]{64}$/', $expectedSha)) {
			throw new RuntimeException('Non-dry-run filesystem writes require payload.expected_sha256.');
		}
		if (! hash_equals($expectedSha, $beforeState['sha256'])) {
			throw new RuntimeException('Stale filesystem target: expected_sha256 does not match the current file.');
		}

		$filesystem = $this->filesystem();
		$mode = defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644;
		try {
			if (! $filesystem->put_contents($absolute, $content, $mode)) {
				throw new RuntimeException('WordPress Filesystem API could not write the target file.');
			}

			clearstatcache(true, $absolute);
			$readback = file_get_contents($absolute, false, null, 0, self::MAX_TEXT_BYTES + 1);
			if (false === $readback || ! hash_equals(hash('sha256', $content), hash('sha256', $readback))) {
				throw new RuntimeException('Filesystem write readback verification failed.');
			}
		} catch (Throwable $error) {
			// A failed write can still truncate the file. Recover before the
			// Runner loses this local before-image when the action fails.
			try {
				$filesystem->put_contents($absolute, $before, $mode);
			} catch (Throwable $recoveryError) {
				// Readback below is authoritative even if the backend throws
				// after writing all original bytes.
			}
			clearstatcache(true, $absolute);
			$restored = @file_get_contents($absolute, false, null, 0, self::MAX_TEXT_BYTES + 1);
			if (! is_string($restored) || ! hash_equals($beforeState['sha256'], hash('sha256', $restored))) {
				throw new RuntimeException($error->getMessage() . ' Recovery failed; original file could not be restored; manual recovery required.', 0, $error);
			}
			throw new RuntimeException($error->getMessage() . ' Recovery complete; original file restored and verified.', 0, $error);
		}

		$result['after'] = $this->state($relative, $readback, $absolute);
		$result['_rollback'] = array(
			'action' => 'filesystem.write_text',
			'payload' => array(
				'path' => $relative,
				'content' => $before,
				'expected_sha256' => hash('sha256', $readback),
				'acknowledge_active_content' => $activeContent,
			),
		);
		return $result;
	}

	public function createText(array $payload, array $context): array
	{
		$this->assertLifecyclePermission($payload, $context);
		$relative = FilesystemPolicy::assertLifecycleText((string) ($payload['path'] ?? ''));
		if (! isset($payload['content']) || ! is_string($payload['content'])) {
			throw new RuntimeException('filesystem.create_text requires UTF-8 content.');
		}
		$content = $payload['content'];
		if (strlen($content) > self::MAX_TEXT_BYTES || ! $this->isUtf8($content)) {
			throw new RuntimeException('New text asset exceeds the UTF-8 text limit.');
		}
		$this->assertNoEmbeddedSecrets($content);
		$this->validateText($relative, $content);
		$absolute = $this->resolveNewTextPath($relative);
		$expectedState = array('path' => $relative, 'exists' => false);
		$result = array(
			'before' => $expectedState,
			'after' => array('path' => $relative, 'bytes' => strlen($content), 'sha256' => hash('sha256', $content)),
			'requires_active_content_acknowledgement' => FilesystemPolicy::affectsBrowserExecution($relative),
			'_current_fingerprint' => Fingerprint::make($expectedState),
		);
		if (! empty($context['dry_run'])) {
			return $result;
		}

		$this->filesystem();
		// Exclusive create: a concurrent file or symlink must not be overwritten.
		$handle = @fopen($absolute, 'x');
		if (false === $handle) {
			throw new RuntimeException('New file could not be created exclusively; target may now exist or filesystem permissions may block it.');
		}
		$ok = true;
		$offset = 0;
		$size = strlen($content);
		while ($offset < $size) {
			$written = @fwrite($handle, substr($content, $offset));
			if (false === $written || 0 === $written) {
				$ok = false;
				break;
			}
			$offset += $written;
		}
		if (! @fflush($handle)) {
			$ok = false;
		}
		$closed = @fclose($handle);
		// A successful write is not safe to report unless the new file is
		// also closed and its requested WordPress permissions are applied.
		$secured = $ok && $closed && $offset === $size
			&& @chmod($absolute, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
		if (! $secured) {
			if (! @unlink($absolute)) {
				throw new RuntimeException('New text file write or permissions failed; cleanup failed and manual recovery required.');
			}
			throw new RuntimeException('New text file write or permissions failed; incomplete or unsafe file was removed.');
		}
		clearstatcache(true, $absolute);
		$readback = @file_get_contents($absolute, false, null, 0, self::MAX_TEXT_BYTES + 1);
		if (! is_string($readback) || ! hash_equals(hash('sha256', $content), hash('sha256', $readback))) {
			if (! @unlink($absolute)) {
				throw new RuntimeException('New file readback failed; cleanup failed and manual recovery required.');
			}
			throw new RuntimeException('New file readback failed; incomplete file was removed.');
		}
		$result['after'] = $this->state($relative, $readback, $absolute);
		$result['_rollback'] = array('action' => 'filesystem.delete_text', 'payload' => array(
			'path' => $relative, 'expected_sha256' => hash('sha256', $readback),
			'acknowledge_active_content' => FilesystemPolicy::affectsBrowserExecution($relative),
		));
		return $result;
	}

	public function deleteText(array $payload, array $context): array
	{
		$this->assertLifecyclePermission($payload, $context);
		$relative = FilesystemPolicy::assertLifecycleText((string) ($payload['path'] ?? ''));
		$absolute = $this->resolveExisting($relative, false);
		if (! is_file($absolute) || is_link($absolute)) {
			throw new RuntimeException('Text deletion requires a regular non-symlink file.');
		}
		$before = @file_get_contents($absolute, false, null, 0, self::MAX_TEXT_BYTES + 1);
		if (! is_string($before) || strlen($before) > self::MAX_TEXT_BYTES || ! $this->isUtf8($before)) {
			throw new RuntimeException('Existing file is unreadable or exceeds safe text limit.');
		}
		$this->assertNoEmbeddedSecrets($before);
		$beforeState = $this->state($relative, $before, $absolute);
		$result = array(
			'before' => $beforeState,
			'after' => array('path' => $relative, 'exists' => false),
			'_current_fingerprint' => Fingerprint::make($beforeState),
			'requires_active_content_acknowledgement' => FilesystemPolicy::affectsBrowserExecution($relative),
		);
		if (! empty($context['dry_run'])) {
			return $result;
		}
		$expected = $payload['expected_sha256'] ?? null;
		if (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}\z/', $expected) ||
			! hash_equals($beforeState['sha256'], $expected)) {
			throw new RuntimeException('Delete refused: exact current file SHA-256 is required.');
		}
		$filesystem = $this->filesystem();
		clearstatcache(true, $absolute);
		if (! is_file($absolute) || is_link($absolute) ||
			! hash_equals($expected, (string) @hash_file('sha256', $absolute))) {
			throw new RuntimeException('Delete target changed before filesystem action.');
		}
		if (! $filesystem->delete($absolute, false, 'f')) {
			throw new RuntimeException('WordPress Filesystem refused deletion; check permissions and security policies.');
		}
		clearstatcache(true, $absolute);
		if (file_exists($absolute) || is_link($absolute)) {
			throw new RuntimeException('File still exists after attempted deletion.');
		}
		$result['_rollback'] = array('action' => 'filesystem.create_text', 'payload' => array(
			'path' => $relative, 'content' => $before,
			'acknowledge_active_content' => FilesystemPolicy::affectsBrowserExecution($relative),
		));
		return $result;
	}

	private function assertLifecyclePermission(array $payload, array $context): void
	{
		if (array_diff(array_keys($payload), array('path','content','expected_sha256','acknowledge_active_content'))) {
			throw new RuntimeException('Filesystem lifecycle request contains unsupported fields.');
		}
		if (empty($context['dry_run']) && ! Policy::flag('WPCONNECTOR_ALLOW_FILESYSTEM_WRITES')) {
			throw new RuntimeException('Filesystem writes are disabled by server policy.');
		}
		$relative = FilesystemPolicy::assertLifecycleText((string) ($payload['path'] ?? ''));
		if (empty($context['dry_run']) && FilesystemPolicy::affectsBrowserExecution($relative) &&
			($payload['acknowledge_active_content'] ?? null) !== true) {
			throw new RuntimeException('Browser-active HTML, JavaScript and CSS assets require acknowledge_active_content=true and normal confirmed change approval.');
		}
		$capability = 0 === strpos($relative, 'wp-content/plugins/') ? 'edit_plugins' : 'edit_themes';
		if (! current_user_can($capability)) {
			throw new RuntimeException('WordPress denied capability ' . $capability . ' (file editor or server policy may be disabled).');
		}
		if (function_exists('wp_is_file_mod_allowed') && ! wp_is_file_mod_allowed('connector_filesystem')) {
			throw new RuntimeException('WordPress or a policy plugin disallows file modifications; check DISALLOW_FILE_MODS and file_mod_allowed.');
		}
	}

	private function resolveNewTextPath(string $relative): string
	{
		$parent = dirname($relative);
		$parentPath = $this->resolveExisting($parent, true);
		if (! is_dir($parentPath)) {
			throw new RuntimeException('Parent directory must already exist.');
		}
		$candidate = $parentPath . DIRECTORY_SEPARATOR . basename($relative);
		if (file_exists($candidate) || is_link($candidate)) {
			throw new RuntimeException('File already exists; use guarded filesystem.write_text.');
		}
		return $candidate;
	}

	private function filesystem(): \WP_Filesystem_Base
	{
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$method = (string) get_filesystem_method(array(), ABSPATH);
		if ('direct' !== $method) {
			throw new RuntimeException('Automated filesystem writes require WordPress direct filesystem mode; interactive FTP/SSH credentials are never accepted through GitHub.');
		}
		if (! WP_Filesystem(false, ABSPATH)) {
			throw new RuntimeException('WordPress Filesystem API could not initialize without interactive filesystem credentials.');
		}
		global $wp_filesystem;
		if (! $wp_filesystem instanceof \WP_Filesystem_Base) {
			throw new RuntimeException('WordPress Filesystem API is unavailable.');
		}
		return $wp_filesystem;
	}

	private function resolveExisting(string $relative, bool $directoryAllowed): string
	{
		$root = realpath(ABSPATH);
		if (false === $root) {
			throw new RuntimeException('WordPress root could not be resolved.');
		}
		$root = rtrim($root, DIRECTORY_SEPARATOR);
		$candidate = '.' === $relative ? $root : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
		$real = realpath($candidate);
		if (false === $real) {
			throw new RuntimeException('Filesystem path does not exist.');
		}
		if ($real !== $root && 0 !== strpos($real, $root . DIRECTORY_SEPARATOR)) {
			throw new RuntimeException('Filesystem path escapes the WordPress root.');
		}
		$this->assertNoSymlinkComponents($candidate, $root);
		if (! $directoryAllowed && is_dir($real)) {
			throw new RuntimeException('A file path is required.');
		}
		return $real;
	}

	private function assertNoSymlinkComponents(string $candidate, string $root): void
	{
		if ($candidate === $root) {
			return;
		}
		$relative = ltrim(substr($candidate, strlen($root)), DIRECTORY_SEPARATOR);
		$cursor = $root;
		foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
			$cursor .= DIRECTORY_SEPARATOR . $segment;
			if (is_link($cursor)) {
				throw new RuntimeException('Symlink filesystem paths are not exposed through the connector.');
			}
		}
	}

	private function state(string $relative, string $content, string $absolute): array
	{
		return array(
			'path' => $relative,
			'bytes' => strlen($content),
			'sha256' => hash('sha256', $content),
			'modified_gmt' => $this->modifiedGmt($absolute),
			'writable_text_scope' => FilesystemPolicy::isWritableText($relative),
		);
	}

	private function modifiedGmt(string $absolute): ?string
	{
		$modified = @filemtime($absolute);
		return false === $modified ? null : gmdate('c', (int) $modified);
	}

	private function validateText(string $relative, string $content): void
	{
		$extension = strtolower((string) pathinfo($relative, PATHINFO_EXTENSION));
		if ('php' === $extension || 'inc' === $extension) {
			try {
				token_get_all($content, TOKEN_PARSE);
			} catch (ParseError $error) {
				throw new RuntimeException('Replacement PHP failed parser validation: ' . $error->getMessage());
			}
		}
		if ('json' === $extension) {
			try {
				json_decode($content, true, 512, JSON_THROW_ON_ERROR);
			} catch (\JsonException $error) {
				throw new RuntimeException('Replacement JSON failed validation: ' . $error->getMessage());
			}
		}
	}

	private function assertNoEmbeddedSecrets(string $content): void
	{
		$patterns = array(
			'/[\"\'](?:password|passwd|secret|token|api[_-]?key|client[_-]?secret|private[_-]?key|authorization)[\"\']\s*(?:=>|:)\s*[\"\']([^\"\']{8,})[\"\']/i',
			'/\$(?:password|passwd|secret|token|api[_-]?key|client[_-]?secret|private[_-]?key)\s*=\s*[\"\']([^\"\']{8,})[\"\']/i',
			'/define\(\s*[\"\'][A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY|PRIVATE_KEY)[A-Z0-9_]*[\"\']\s*,\s*[\"\']([^\"\']{8,})[\"\']/i',
		);
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $content, $matches)) {
				$value = strtolower(trim((string) ($matches[1] ?? '')));
				if (! preg_match('/^(your|example|sample|dummy|changeme|replace|placeholder|test|xxxx|none|null)/', $value)) {
					throw new RuntimeException('File appears to contain an embedded credential or secret literal and cannot be exported or rewritten through the connector.');
				}
			}
		}
	}

	private function isUtf8(string $value): bool
	{
		return 1 === preg_match('//u', $value);
	}

	private function fileManagerRootMode(string $configuredPath): string
	{
		if ('' === trim($configuredPath)) {
			return 'wordpress_root';
		}
		$root = realpath(ABSPATH);
		$configured = realpath($configuredPath);
		if (false === $root || false === $configured) {
			return 'custom_unresolved_ignored';
		}
		$root = rtrim($root, DIRECTORY_SEPARATOR);
		if ($configured === $root || 0 === strpos($configured, $root . DIRECTORY_SEPARATOR)) {
			return 'custom_within_wordpress_root';
		}
		return 'outside_wordpress_root_ignored';
	}
}
