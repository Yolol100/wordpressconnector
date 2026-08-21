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
            if (self::publicRepositoryContext()) {
                throw new RuntimeException('Privileged actions are blocked in public-repository mode.');
            }
            if (! self::flag('WPCONNECTOR_ALLOW_PRIVILEGED')) {
                throw new RuntimeException('Privileged actions are disabled.');
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
            throw new RuntimeException('Writes are disabled. Set WPCONNECTOR_ALLOW_WRITES=1 in the runner environment or wp-config.php.');
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
        if (defined($name) && true === constant($name)) {
            return true;
        }

        $value = getenv($name);
        return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on'), true);
    }

    private static function sensitiveExportAllowed(): bool
    {
        return self::flag('WPCONNECTOR_ALLOW_SENSITIVE') && ! self::publicRepositoryContext();
    }
}
