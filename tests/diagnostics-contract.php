<?php

declare(strict_types=1);

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
echo "typed diagnostics and privacy-safe retry guidance OK\n";
