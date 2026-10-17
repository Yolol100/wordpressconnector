<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security;

use RuntimeException;

/**
 * Each WordPress site/blog must explicitly opt into the pinned GitHub OIDC
 * control plane. A cloned DB or a changed WordPress URL loses its grant.
 * Direct authenticated WordPress REST/MCP is intentionally unaffected.
 */
final class SitePairing
{
    private const OPTION = 'wpconnector_github_oidc_site_pairing_v1';
    private const NAMESPACE = 'webactueel-wordpress-connector/v1';

    public static function audience(): string
    {
        return rtrim((string) rest_url(self::NAMESPACE), '/');
    }

    private static function fingerprint(): string
    {
        return hash('sha256', self::audience());
    }

    public static function pairedAdministratorId(): int
    {
        $state = get_option(self::OPTION, null);
        if (! is_array($state) ||
            1 !== ($state['enabled'] ?? null) ||
            ! is_string($state['audience_sha256'] ?? null) ||
            ! preg_match('/^[a-f0-9]{64}\z/', $state['audience_sha256']) ||
            ! hash_equals(self::fingerprint(), $state['audience_sha256'])) {
            return 0;
        }
        $id = $state['administrator_id'] ?? null;
        if (! is_int($id) || $id <= 0 || ! user_can($id, 'manage_options')) {
            return 0;
        }
        return $id;
    }

    public static function enableForAdministrator(int $administratorId): void
    {
        if ($administratorId <= 0 || ! user_can($administratorId, 'manage_options')) {
            throw new RuntimeException('Only a current site administrator may approve GitHub OIDC.');
        }
        $state = array(
            'enabled' => 1,
            'audience_sha256' => self::fingerprint(),
            'administrator_id' => $administratorId,
        );
        if (! update_option(self::OPTION, $state, false)) {
            // WordPress returns false for unchanged values: confirm the saved
            // state rather than treating that harmless no-op as a failure.
            if (self::pairedAdministratorId() !== $administratorId) {
                throw new RuntimeException('Could not store site-specific GitHub OIDC approval.');
            }
        }
    }

    public static function disable(): void
    {
        delete_option(self::OPTION);
    }
}
