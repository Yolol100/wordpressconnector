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

putenv('WPCONNECTOR_PUBLIC_REPOSITORY=1');
putenv('WPCONNECTOR_ALLOW_PRIVILEGED=1');
putenv('WPCONNECTOR_ALLOW_SYSTEM_UPDATES=1');
putenv('WPCONNECTOR_ALLOW_WRITES=1');

Policy::assertActionAllowed(array(
    'mutation' => true,
    'privileged' => true,
    'system_update' => true,
    'public_repository_safe' => true,
), false, true);

$failed = false;
try {
    Policy::assertActionAllowed(array('privileged' => true), true, false);
} catch (RuntimeException $error) {
    $failed = true;
}
if (! $failed) {
    fwrite(STDERR, "Public mode accepted an unmarked privileged action.\n");
    exit(1);
}

$failed = false;
try {
    Policy::assertActionAllowed(array('privileged' => true, 'sensitive' => true, 'public_repository_safe' => true), true, false);
} catch (RuntimeException $error) {
    $failed = true;
}
if (! $failed) {
    fwrite(STDERR, "Public mode accepted a sensitive action.\n");
    exit(1);
}

foreach (array('WPCONNECTOR_PUBLIC_REPOSITORY', 'WPCONNECTOR_ALLOW_PRIVILEGED', 'WPCONNECTOR_ALLOW_SYSTEM_UPDATES', 'WPCONNECTOR_ALLOW_WRITES') as $name) {
    putenv($name);
}

echo "policy contract OK\n";
