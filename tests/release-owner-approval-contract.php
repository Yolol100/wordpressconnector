<?php
/**
 * Synthetic WordPress option-store test for the one-time consent consumed by
 * OAuth Connector updates. No real admin cookie, GitHub or network activity.
 */
declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security {
    final class OAuthResourceServer {
        public static bool $enabled = true;
        public static function selfUpdateConfigured(): bool { return self::$enabled; }
    }
}
namespace {
    $GLOBALS['owner_options'] = array();
    $GLOBALS['owner_user_id'] = 7;
    $GLOBALS['owner_can_update'] = true;
    $GLOBALS['owner_session'] = true;
    function is_user_logged_in(): bool { return $GLOBALS['owner_session']; }
    function current_user_can(string $capability): bool {
        return 'update_plugins' === $capability && $GLOBALS['owner_can_update'];
    }
    function get_current_user_id(): int { return $GLOBALS['owner_user_id']; }
    function get_option(string $key, $default = false) {
        return $GLOBALS['owner_options'][$key] ?? $default;
    }
    function add_option(string $key, $value, string $deprecated = '', string $autoload = 'yes'): bool {
        if (array_key_exists($key, $GLOBALS['owner_options'])) return false;
        $GLOBALS['owner_options'][$key] = $value;
        return true;
    }
    function delete_option(string $key): bool {
        if (! array_key_exists($key, $GLOBALS['owner_options'])) return false;
        unset($GLOBALS['owner_options'][$key]);
        return true;
    }

    require dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/ReleaseApproval.php';
    use Webactueel\WordPressConnector\Security\ReleaseApproval;
    use Webactueel\WordPressConnector\Security\OAuthResourceServer;

    function ensure(bool $ok, string $reason): void {
        if (! $ok) { fwrite(STDERR, 'FAIL: ' . $reason . "\n"); exit(1); }
    }
    $fingerprint = hash('sha256', 'synthetic canonical release sha and site version');
    $key = 'wpconnector_oauth_release_approval_v1';
    $make = static function(string $id, int $owner, int $issued, int $expires) use ($fingerprint): array {
        return array(
            'id' => $id,
            'fingerprint' => $fingerprint,
            'owner' => $owner,
            'issued' => $issued,
            'expires' => $expires,
        );
    };
    $now = time();
    $record = $make(str_repeat('a', 32), 7, $now-30, $now+570);
    ensure(! ReleaseApproval::consume($fingerprint), 'No wp-admin approval exists initially.');
    $GLOBALS['owner_options'][$key] = $record;
    ensure(! ReleaseApproval::consume(str_repeat('b', 64)), 'Another fingerprint cannot borrow approval.');
    ensure(ReleaseApproval::consume($fingerprint), 'One exact, current owner approval may be claimed.');
    ensure(! ReleaseApproval::consume($fingerprint), 'Used approval must be denied even when replayed.');
    ensure(isset($GLOBALS['owner_options']['wpconnector_oauth_release_claim_' . str_repeat('a', 32)]),
        'Atomic single-use claim must be durably recorded.');

    $GLOBALS['owner_options'][$key] = $record;
    ensure(! ReleaseApproval::consume($fingerprint), 'Replaying a previously claimed approval id must be denied.');
    $GLOBALS['owner_options'][$key] = $make(str_repeat('b', 32), 7, $now-601, $now-1);
    ensure(! ReleaseApproval::consume($fingerprint), 'Expired approval must be denied.');
    $GLOBALS['owner_options'][$key] = $make(str_repeat('c', 32), 999, $now-30, $now+570);
    ensure(! ReleaseApproval::consume($fingerprint), 'Another WordPress owner cannot authorize this account.');
    $GLOBALS['owner_options'][$key] = $make(str_repeat('d', 32), 7, $now-30, $now+570);
    $GLOBALS['owner_can_update'] = false;
    ensure(! ReleaseApproval::consume($fingerprint), 'WordPress update_plugins revocation must invalidate approval.');
    $GLOBALS['owner_can_update'] = true;
    OAuthResourceServer::$enabled = false;
    ensure(! ReleaseApproval::consume($fingerprint), 'Removing the owner opt-in must revoke approval.');
    OAuthResourceServer::$enabled = true;
    $GLOBALS['owner_session'] = false;
    ensure(! ReleaseApproval::consume($fingerprint), 'An unauthenticated request must never consume approval.');
    $GLOBALS['owner_session'] = true;
    $GLOBALS['owner_options'][$key] = $make(str_repeat('e', 32), 7, $now+10, $now+610);
    ensure(! ReleaseApproval::consume($fingerprint), 'Future-issued approval must be denied.');

    $source = file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/ReleaseApproval.php');
    foreach (array(
        "check_admin_referer( self::NONCE_ACTION )",
        "wp_get_session_token()",
        "current_user_can( 'manage_options' )",
        "current_user_can( 'update_plugins' )",
        "wp_generate_uuid4()",
        "hash_equals( \$release['fingerprint'], \$requested )",
        "add_option( self::CLAIM_PREFIX",
    ) as $guard) {
        ensure(is_string($source) && strpos($source, $guard) !== false, 'Owner approval guard missing: ' . $guard);
    }
    echo "OAuth one-time browser owner approval and replay denial contract OK\n";
}
