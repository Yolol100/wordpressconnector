<?php

declare(strict_types=1);

if (! defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes/';
require_once $root . 'Runtime/Diagnostics.php';

use Webactueel\WordPressConnector\Runtime\Diagnostics;

$examples = array(
    array('GitHub OIDC authentication was rejected.', 'authentication_required'),
    array('HTTP 403 from the upstream proxy', 'permission_denied'),
    array('Current user lacks the required WordPress capability: edit_themes.', 'permission_denied'),
    array('Fingerprint does not match the current saved state.', 'stale_state'),
    array('Another connector mutation is already in progress.', 'mutation_busy'),
    array('request_id was already used for a different mutation.', 'conflict'),
    array('A provider request timed out.', 'transient_transport'),
    array('Plugin is not active.', 'dependency_unavailable'),
    array('Unsupported field foo.', 'invalid_request'),
    array('GitHub OIDC is not approved for this WordPress site.', 'site_approval_required'),
    array('GitHub OIDC site approval is unavailable or was revoked.', 'site_approval_required'),
    array('GitHub OIDC audience is invalid for this WordPress site.', 'site_identity_mismatch'),
    array('GitHub OIDC authentication requires the PHP OpenSSL extension.', 'runtime_incompatible'),
    array('PHP ZipArchive is required for connector release verification.', 'runtime_incompatible'),
    array('HTTP 403 GitHub API rate limit exceeded.', 'github_rate_limited'),
    array('HTTP 403 hosting WAF blocked the request.', 'security_policy_blocked'),
    array('Outbound network blocked by hosting policy.', 'outbound_network_blocked'),
    array('WordPress Filesystem API could not initialize without interactive filesystem credentials.', 'filesystem_unavailable'),
    array('WordPress plugin installation failed; check filesystem permissions.', 'plugin_operation_failed'),
    array('Plugin update failed or no update is available.', 'plugin_operation_failed'),
    array('Plugin activation did not persist; check plugin requirements.', 'persistence_unverified'),
    array('Filesystem write readback verification failed.', 'persistence_unverified'),
    array('Connector update readback failed and automatic recovery was not verified.', 'persistence_unverified'),
    array('Unexpected internal code error.', 'unknown_failure'),
);
foreach ($examples as $example) {
    $classified = Diagnostics::classify($example[0]);
    if (($classified['code'] ?? '') !== $example[1]
        || empty($classified['next_step'])
        || ! array_key_exists('reconcile_before_retry', $classified)
        || ! array_key_exists('retryable_read', $classified)) {
        throw new RuntimeException('Wrong diagnostic classification for: ' . $example[1]);
    }
}
$read = Diagnostics::classify('WordPress transport timed out for https://secret.example/api?token=SENSITIVE-DO-NOT-LOG', false);
$mutation = Diagnostics::classify('WordPress transport timed out for https://secret.example/api?token=SENSITIVE-DO-NOT-LOG', true);
if (! $read['retryable_read'] || $read['reconcile_before_retry']
    || $mutation['retryable_read'] || ! $mutation['reconcile_before_retry']
    || false !== strpos(json_encode($mutation), 'secret.example')
    || false !== strpos(json_encode($mutation), 'SENSITIVE-DO-NOT-LOG')) {
    throw new RuntimeException('Diagnostic metadata must be secret-free and mutation-safe.');
}
$rateRead = Diagnostics::classify('GitHub API rate limit exceeded for token=SENSITIVE-DO-NOT-LOG', false);
$rateWrite = Diagnostics::classify('GitHub API rate limit exceeded for token=SENSITIVE-DO-NOT-LOG', true);
if (! $rateRead['retryable_read'] || $rateRead['reconcile_before_retry']
    || $rateWrite['retryable_read'] || ! $rateWrite['reconcile_before_retry']
    || false !== strpos(json_encode($rateRead), 'SENSITIVE-DO-NOT-LOG')
    || false !== strpos(json_encode($rateWrite), 'SENSITIVE-DO-NOT-LOG')) {
    throw new RuntimeException('Rate-limit diagnostics must be secret-free and mutation-safe.');
}
echo "typed diagnostics and privacy-safe retry guidance OK\n";
