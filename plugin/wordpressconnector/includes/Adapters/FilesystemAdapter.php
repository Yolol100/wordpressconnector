<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use ParseError;
use RuntimeException;
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
                'scope' => array('existing wp-content/plugins/* text files', 'existing wp-content/themes/* text files'),
                'core' => false,
                'uploads' => false,
                'connector_self_update' => false,
                'requires' => array('privileged gate', 'writes gate', 'filesystem-writes gate', 'confirm=true', 'expected_sha256'),
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
        $content = file_get_contents($absolute);
        if (false === $content || ! $this->isUtf8($content)) {
            throw new RuntimeException('Filesystem file is not readable UTF-8 text.');
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
        $absolute = $this->resolveExisting($relative, false);
        if (! is_file($absolute)) {
            throw new RuntimeException('filesystem.write_text only replaces existing regular files.');
        }
        $before = file_get_contents($absolute);
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
        );

        if (! empty($context['dry_run'])) {
            return $result;
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
        if (! $filesystem->put_contents($absolute, $content, $mode)) {
            throw new RuntimeException('WordPress Filesystem API could not write the target file.');
        }

        clearstatcache(true, $absolute);
        $readback = file_get_contents($absolute);
        if (false === $readback || ! hash_equals(hash('sha256', $content), hash('sha256', $readback))) {
            throw new RuntimeException('Filesystem write readback verification failed.');
        }

        $result['after'] = $this->state($relative, $readback, $absolute);
        $result['_rollback'] = array(
            'action' => 'filesystem.write_text',
            'payload' => array(
                'path' => $relative,
                'content' => $before,
                'expected_sha256' => hash('sha256', $readback),
            ),
        );
        return $result;
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
