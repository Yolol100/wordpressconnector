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
    define('WPCONNECTOR_OAUTH_ATTESTED_RELEASE_SHA256', 'sha256:' . str_repeat('a', 64));
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
    // Simulate the actual database UNIQUE option_name index across PHP workers.
    final class OwnerApprovalDatabase {
        public string $options = 'wp_options';
        public bool $failInsert = false;
        public bool $replaceApprovalOnClaim = false;
        public function insert(string $table, array $values, array $formats) {
            if ($this->failInsert || $table !== $this->options || $formats !== array('%s', '%s', '%s')
                || ($values['autoload'] ?? null) !== 'no' || ! isset($values['option_name'])) return false;
            $key = $values['option_name'];
            if (array_key_exists($key, $GLOBALS['owner_options'])) return false;
            $GLOBALS['owner_options'][$key] = $values['option_value'];
            if ($this->replaceApprovalOnClaim) {
                $this->replaceApprovalOnClaim = false;
                $GLOBALS['owner_options']['wpconnector_oauth_release_approval_v1'] = $GLOBALS['replacement_approval'];
            }
            return 1;
        }
    }
    $GLOBALS['wpdb'] = new OwnerApprovalDatabase();
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
            'digest' => WPCONNECTOR_OAUTH_ATTESTED_RELEASE_SHA256,
            'owner' => $owner,
            'issued' => $issued,
            'expires' => $expires,
        );
    };
    // Every claimed consent must use the ID bound to the earlier preflight.
    $consume = static function (string $fp) use ($key): bool {
        $record = $GLOBALS['owner_options'][$key] ?? null;
        $id = is_array($record) && is_string($record['id'] ?? null) ? $record['id'] : str_repeat('f', 32);
        return ReleaseApproval::consume($fp, $id);
    };
    $now = time();
    $record = $make(str_repeat('a', 32), 7, $now-30, $now+570);
    ensure(! ReleaseApproval::available($fingerprint), 'No wp-admin approval may pass preflight initially.');
    ensure(! $consume($fingerprint), 'No wp-admin approval exists initially.');
    $GLOBALS['owner_options'][$key] = $record;
    ensure(ReleaseApproval::available($fingerprint), 'A valid approval passes preflight without consuming it.');
    ensure(ReleaseApproval::available($fingerprint), 'Repeating read-only preflight must not claim consent.');
    ensure(! $consume(str_repeat('b', 64)), 'Another fingerprint cannot borrow approval.');
    ensure($consume($fingerprint), 'One exact, current owner approval may be claimed.');
    ensure(! ReleaseApproval::available($fingerprint), 'Used approval is absent after the claim.');
    ensure(! $consume($fingerprint), 'Used approval must be denied even when replayed.');
    ensure(isset($GLOBALS['owner_options']['wpconnector_oauth_release_claim_' . str_repeat('a', 32)]),
        'Atomic single-use claim must be durably recorded.');

    $GLOBALS['owner_options'][$key] = $record;
    ensure(! $consume($fingerprint), 'Replaying a previously claimed approval id must be denied.');
    // Simulate a second owner browser approval replacing the shared slot while
    // the first request atomically claims its own immutable ID.
    $old = $make(str_repeat('9', 32), 7, $now-30, $now+570);
    $replacement = $make(str_repeat('8', 32), 7, $now-30, $now+570);
    $GLOBALS['owner_options'][$key] = $old;
    $GLOBALS['replacement_approval'] = $replacement;
    $GLOBALS['wpdb']->replaceApprovalOnClaim = true;
    ensure($consume($fingerprint), 'Old approval may be claimed once.');
    ensure(($GLOBALS['owner_options'][$key]['id'] ?? null) === $replacement['id'],
        'An atomic claim must not delete a newer replacement owner approval.');
    ensure(ReleaseApproval::available($fingerprint), 'New replacement approval remains available.');
    ensure($consume($fingerprint), 'The replacement approval is independently claimable.');
    ensure(! ReleaseApproval::available($fingerprint), 'Claimed replacement consent cannot be reused.');
    // Replacement B after locked preflight must not be consumed by attempt A.
    $preflightRecord = $make(str_repeat('1', 32), 7, $now-30, $now+570);
    $replacementRecord = $make(str_repeat('2', 32), 7, $now-30, $now+570);
    $GLOBALS['owner_options'][$key] = $preflightRecord;
    $preflightId = ReleaseApproval::idForFingerprint($fingerprint);
    ensure($preflightId === $preflightRecord['id'], 'Preflight must expose the exact owner consent ID.');
    $GLOBALS['owner_options'][$key] = $replacementRecord;
    ensure(! ReleaseApproval::consume($fingerprint, $preflightId),
        'An in-flight update may not claim replacement approval B using preflighted A.');
    ensure(ReleaseApproval::idForFingerprint($fingerprint) === $replacementRecord['id'],
        'New owner approval B must remain available after stale A is refused.');
    ensure($consume($fingerprint), 'Replacement B remains independently claimable.');
    // A database write error must not burn consent or accept an unrecorded claim.
    $GLOBALS['owner_options'][$key] = $make(str_repeat('b', 32), 7, $now-30, $now+570);
    $GLOBALS['wpdb']->failInsert = true;
    ensure(! $consume($fingerprint), 'Database claim failure must fail closed.');
    ensure(isset($GLOBALS['owner_options'][$key]), 'Failed database claim must preserve owner consent.');
    $GLOBALS['wpdb']->failInsert = false;
    $GLOBALS['owner_options'][$key] = $make(str_repeat('b', 32), 7, $now-601, $now-1);
    ensure(! $consume($fingerprint), 'Expired approval must be denied.');
    $wrongDigest = $make(str_repeat('c', 32), 7, $now-30, $now+570);
    $wrongDigest['digest'] = 'sha256:' . str_repeat('f', 64);
    $GLOBALS['owner_options'][$key] = $wrongDigest;
    ensure(! $consume($fingerprint), 'Attestation pin must reject a different package digest.');
    $GLOBALS['owner_options'][$key] = $make(str_repeat('c', 32), 999, $now-30, $now+570);
    ensure(! $consume($fingerprint), 'Another WordPress owner cannot authorize this account.');
    $GLOBALS['owner_options'][$key] = $make(str_repeat('d', 32), 7, $now-30, $now+570);
    $GLOBALS['owner_can_update'] = false;
    ensure(! $consume($fingerprint), 'WordPress update_plugins revocation must invalidate approval.');
    $GLOBALS['owner_can_update'] = true;
    OAuthResourceServer::$enabled = false;
    ensure(! $consume($fingerprint), 'Removing the owner opt-in must revoke approval.');
    OAuthResourceServer::$enabled = true;
    $GLOBALS['owner_session'] = false;
    ensure(! $consume($fingerprint), 'An unauthenticated request must never consume approval.');
    $GLOBALS['owner_session'] = true;
    $GLOBALS['owner_options'][$key] = $make(str_repeat('e', 32), 7, $now+10, $now+610);
    ensure(! $consume($fingerprint), 'Future-issued approval must be denied.');

    $source = file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/ReleaseApproval.php');
    ensure(strpos($source, 'wpconnector_review_release') !== false
        && strpos($source, 'wp_verify_nonce( $nonce, self::NONCE_ACTION )') !== false
        && strpos($source, 'wp_nonce_url(') !== false,
        'The ordinary Plugins page must defer release downloads to owner-initiated nonce-guarded review.');
    ensure(strpos($source, 'delete_option( self::OPTION )') === false
        && strpos($source, "get_option( self::CLAIM_PREFIX . \$record['id'], false )") !== false,
        'Claiming an old approval must not delete a replacement and claimed records must be unavailable.');
    foreach (array(
        "check_admin_referer( self::NONCE_ACTION )",
        "wp_get_session_token()",
        "current_user_can( 'manage_options' )",
        "current_user_can( 'update_plugins' )",
        "wp_generate_uuid4()",
        "hash_equals( \$release['fingerprint'], \$requested )",
        "\$wpdb->insert(",
        "self::CLAIM_PREFIX . \$record['id']",
        "WPCONNECTOR_OAUTH_ATTESTED_RELEASE_SHA256",
    ) as $guard) {
        ensure(is_string($source) && strpos($source, $guard) !== false, 'Owner approval guard missing: ' . $guard);
    }
    $runner = file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Runner.php');
    $adapter = file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/ConnectorUpdateAdapter.php');
    $controller = file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/MCP/Controller.php');
    ensure(is_string($runner) && strpos($runner, 'ReleaseApproval::idForFingerprint(') !== false
        && strpos($runner, 'ReleaseApproval::idForFingerprint(') < strpos($runner, '$this->processed->begin('),
        'Owner preflight must run before reserving a mutation request ID.');
    ensure(is_string($adapter)
        && strpos($adapter, 'ReleaseApproval::consume(') !== false
        && strpos($adapter, '$package = $this->download_protected_source_package( $release );') !== false
        && strpos($adapter, '$package = $this->download_protected_source_package( $release );') < strpos($adapter, 'ReleaseApproval::consume(')
        && strpos($adapter, 'private function download_protected_source_package(') !== false
        && strpos($adapter, '$this->verify_protected_source( $release, $package );') !== false,
        'Only an already protected-source-verified package may reach owner approval consumption.');
    ensure(is_string($controller) && strpos($controller, 'ReleaseApproval::consume(') === false
        && 1 === preg_match( '/\x27require_owner_approval\x27\s*=>\s*\$require_owner_approval/', $controller ),
        'MCP transport must defer owner consent until the guarded runtime.');
    echo "OAuth one-time browser owner approval, preflight and replay denial contract OK\n";
}
