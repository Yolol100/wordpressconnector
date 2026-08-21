<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';

use Webactueel\WordPressConnector\Security\Policy;

$descriptor = array('mutation' => false, 'privileged' => false, 'sensitive' => false, 'system_update' => false);
Policy::assertActionAllowed($descriptor, true, false);

$failed = false;
try {
    Policy::assertKeyAllowed('api_key');
} catch (RuntimeException $error) {
    $failed = true;
}
if (! $failed) {
    fwrite(STDERR, "Secret-like key contract failed.\n");
    exit(1);
}

echo "policy contract OK\n";
