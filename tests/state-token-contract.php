<?php

declare(strict_types=1);

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Support/Json.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Support/Fingerprint.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Runtime/Request.php';

use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Support\Fingerprint;

$fingerprint = Fingerprint::make(array('post_id' => 10, 'data' => array('x' => 1)));
$token = Fingerprint::siteTokenFromFingerprint($fingerprint);
if (! preg_match('/^[a-f0-9]{64}\z/', $token)) {
    fwrite(STDERR, "Site state token is not a SHA-256 HMAC.\n");
    exit(1);
}

$request = Request::fromArray(array(
    'version' => 1,
    'request_id' => 'state-token-001',
    'action' => 'elementor.replace_document',
    'payload' => array('id' => 10),
    'dry_run' => false,
    'confirm' => true,
    'expected_fingerprint' => $fingerprint,
    'expected_state_token' => $token,
));
if ($request->expectedStateToken() !== $token || $request->expectedFingerprint() !== $fingerprint) {
    fwrite(STDERR, "Request state guards were not preserved.\n");
    exit(1);
}

try {
    Request::fromArray(array(
        'version' => 1,
        'request_id' => 'state-token-002',
        'action' => 'post.update',
        'payload' => array('id' => 10),
        'dry_run' => true,
        'confirm' => false,
        'expected_state_token' => 'bad',
    ));
    fwrite(STDERR, "Invalid expected_state_token was accepted.\n");
    exit(1);
} catch (RuntimeException $error) {
}

echo "state token contract OK\n";
