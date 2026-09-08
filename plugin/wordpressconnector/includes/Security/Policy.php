<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security;

use RuntimeException;

final class Policy
{
    private const SENSITIVE_POST_TYPES = array(
        'shop_order',
        'shop_order_refund',
        'shop_subscription',
        'wp_user_request',
    );

    private const BLOCKED_OPTION_KEYS = array(
        'active_plugins',
        'active_sitewide_plugins',
        'allowedthemes',
        'cron',
        'current_theme',
        'default_role',
        'home',
        'recently_activated',
        'rewrite_rules',
        'site_admins',
        'siteurl',
        'stylesheet',
        'template',
        'uninstall_plugins',
        'upload_path',
        'upload_url_path',
        'users_can_register',
        'wp_user_roles',
    );

    private const OPTION_FLAGS = array(
        'WPCONNECTOR_ALLOW_WRITES' => 'wpconnector_allow_writes',
        'WPCONNECTOR_ALLOW_PRIVILEGED' => 'wpconnector_allow_privileged',
        'WPCONNECTOR_ALLOW_SENSITIVE' => 'wpconnector_allow_sensitive',
        'WPCONNECTOR_ALLOW_SYSTEM_UPDATES' => 'wpconnector_allow_system_updates',
        'WPCONNECTOR_ALLOW_FILESYSTEM_WRITES' => 'wpconnector_allow_filesystem_writes',
    );

    public static function assertActionAllowed(array $descriptor, bool $dryRun, bool $confirm): void
    {
        if (! empty($descriptor['sensitive'])) {
            if (self::publicRepositoryContext()) {
                throw new RuntimeException('Sensitive actions are blocked in public-repository mode.');
            }
            if (! self::flag('WPCONNECTOR_ALLOW_SENSITIVE')) {
                throw new RuntimeException('Sensitive actions are disabled.');
            }
        }

        if (! empty($descriptor['privileged'])) {
            if (self::publicRepositoryContext() && empty($descriptor['public_repository_safe'])) {
                throw new RuntimeException('Privileged actions are blocked in public-repository mode unless explicitly marked public_repository_safe.');
            }
            if (! self::flag('WPCONNECTOR_ALLOW_PRIVILEGED')) {
                throw new RuntimeException('Privileged actions are disabled.');
            }
        }

        if (! empty($descriptor['capability'])) {
            $capability = (string) $descriptor['capability'];
            if (! function_exists('current_user_can') || ! current_user_can($capability)) {
                throw new RuntimeException('Current user lacks the required WordPress capability: ' . $capability . '.');
            }
        }

        if (! empty($descriptor['system_update']) && ! self::flag('WPCONNECTOR_ALLOW_SYSTEM_UPDATES')) {
            throw new RuntimeException('System update actions are disabled.');
        }

        if (empty($descriptor['mutation']) || $dryRun) {
            return;
        }

        if (! $confirm) {
            throw new RuntimeException('Mutation requires confirm=true.');
        }

        if (! self::flag('WPCONNECTOR_ALLOW_WRITES')) {
            throw new RuntimeException('Writes are disabled. Enable the WordPress Connector write gate or set WPCONNECTOR_ALLOW_WRITES=1.');
        }
    }

    public static function assertPostReadable(\WP_Post $post): void
    {
        self::assertReadablePostType((string) $post->post_type);

        if (! self::publicRepositoryContext()) {
            return;
        }

        if ('attachment' === $post->post_type) {
            if ((int) $post->post_parent > 0) {
                $parent = get_post((int) $post->post_parent);
                if ($parent instanceof \WP_Post && 'publish' !== $parent->post_status) {
                    throw new RuntimeException('Attachment parent is not public.');
                }
            }
            return;
        }

        $postType = get_post_type_object((string) $post->post_type);
        if (! $postType || ! $postType->public) {
            throw new RuntimeException('Non-public post types cannot be exported in public-repository mode.');
        }

        if ('publish' !== $post->post_status || '' !== (string) $post->post_password) {
            throw new RuntimeException('Only public, non-password-protected content may be exported in public-repository mode.');
        }
    }

    public static function assertReadablePostType(string $postType): void
    {
        if (in_array($postType, self::SENSITIVE_POST_TYPES, true) && ! self::sensitiveExportAllowed()) {
            throw new RuntimeException('Sensitive post type export is disabled.');
        }

        if ((bool) preg_match('/(medical|patient|intake|consult|questionnaire|prescription|submission|order_request)/i', $postType) && ! self::sensitiveExportAllowed()) {
            throw new RuntimeException('Potentially sensitive post type export is disabled.');
        }
    }

    public static function assertKeyAllowed(string $key): void
    {
        if ((bool) preg_match('/(password|passwd|secret|token|api[_-]?key|private[_-]?key|consumer_secret|authorization|cookie|auth_key|secure_auth|logged_in_key|nonce_key|salt)/i', $key)) {
            throw new RuntimeException('Secret-like keys cannot be read or written through the connector.');
        }
    }

    public static function assertMetaKeyAllowed(string $key): void
    {
        self::assertKeyAllowed($key);
        if (isset($key[0]) && '_' === $key[0]) {
            throw new RuntimeException('Protected/internal metadata must use its dedicated semantic adapter.');
        }
    }

    public static function assertOptionKeyAllowed(string $key): void
    {
        self::assertKeyAllowed($key);
        if (in_array(strtolower($key), self::BLOCKED_OPTION_KEYS, true)) {
            throw new RuntimeException('System-owned option must use its dedicated semantic action instead of generic option access.');
        }
    }

    public static function assertLocalAssetPath(string $path, string $assetRoot): string
    {
        $root = realpath($assetRoot);
        $real = realpath($path);

        if (false === $root || false === $real) {
            throw new RuntimeException('Asset path does not exist.');
        }

        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if ($real !== $root && 0 !== strpos($real, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Asset path escapes the allowed asset root.');
        }

        if (is_link($real)) {
            throw new RuntimeException('Symlink assets are not allowed.');
        }

        return $real;
    }

    public static function redact($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $redacted = array();
        foreach ($value as $key => $item) {
            $name = (string) $key;
            if ((bool) preg_match('/(password|passwd|secret|token|api[_-]?key|private[_-]?key|consumer_secret|authorization|cookie)/i', $name)) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            $redacted[$key] = self::redact($item);
        }

        return $redacted;
    }

    public static function publicRepositoryContext(): bool
    {
        return self::flag('WPCONNECTOR_PUBLIC_REPOSITORY');
    }

    public static function flag(string $name): bool
    {
        if (defined($name)) {
            return self::truthy(constant($name));
        }

        $environment = getenv($name);
        if (false !== $environment && '' !== (string) $environment) {
            return self::truthy($environment);
        }

        if (isset(self::OPTION_FLAGS[$name]) && function_exists('get_option')) {
            return self::truthy(get_option(self::OPTION_FLAGS[$name], false));
        }

        return false;
    }

    private static function truthy($value): bool
    {
        if (true === $value || 1 === $value) {
            return true;
        }
        return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on'), true);
    }

    private static function sensitiveExportAllowed(): bool
    {
        return self::flag('WPCONNECTOR_ALLOW_SENSITIVE') && ! self::publicRepositoryContext();
    }
}
