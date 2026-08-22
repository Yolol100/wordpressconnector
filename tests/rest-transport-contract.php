<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/plugin/wordpressconnector/includes/REST/Controller.php');
$assetStore = file_get_contents($root . '/plugin/wordpressconnector/includes/REST/AssetStore.php');
$policy = file_get_contents($root . '/plugin/wordpressconnector/includes/Security/Policy.php');
$bootstrap = file_get_contents($root . '/plugin/wordpressconnector/wordpressconnector.php');

foreach (array('controller' => $controller, 'asset store' => $assetStore, 'policy' => $policy, 'bootstrap' => $bootstrap) as $name => $contents) {
    if (false === $contents) {
        fwrite(STDERR, "Unable to read REST {$name}.\n");
        exit(1);
    }
}

$controllerRequired = array(
    "register_rest_route(self::NAMESPACE, '/health'",
    "register_rest_route(self::NAMESPACE, '/assets'",
    "register_rest_route(self::NAMESPACE, '/execute'",
    "'permission_callback' => array(\$this, 'authorize')",
    "current_user_can('manage_options')",
    'is_ssl()',
    "get_option('wpconnector_rest_enabled', true)",
    'get_json_params()',
    'strlen($body) > self::MAX_REQUEST_BYTES',
    'Request::fromArray($data)',
    "'transport' => 'rest'",
    "'asset_root' => \$this->assets->rootForRequest",
);
foreach ($controllerRequired as $needle) {
    if (strpos($controller, $needle) === false) {
        fwrite(STDERR, "Missing REST controller security contract: {$needle}\n");
        exit(1);
    }
}

$assetRequired = array(
    'is_uploaded_file($tmpName)',
    'move_uploaded_file($tmpName, $destination)',
    'MAX_FILES = 10',
    'MAX_TOTAL_BYTES = 26214400',
    "'.' === \$segment || '..' === \$segment",
    'realpath($root)',
    'Refusing unsafe connector asset cleanup path.',
);
foreach ($assetRequired as $needle) {
    if (strpos($assetStore, $needle) === false) {
        fwrite(STDERR, "Missing REST asset security contract: {$needle}\n");
        exit(1);
    }
}

$policyRequired = array(
    "'WPCONNECTOR_ALLOW_WRITES' => 'wpconnector_allow_writes'",
    "'WPCONNECTOR_ALLOW_PRIVILEGED' => 'wpconnector_allow_privileged'",
    "'WPCONNECTOR_ALLOW_SENSITIVE' => 'wpconnector_allow_sensitive'",
    "'WPCONNECTOR_ALLOW_SYSTEM_UPDATES' => 'wpconnector_allow_system_updates'",
);
foreach ($policyRequired as $needle) {
    if (strpos($policy, $needle) === false) {
        fwrite(STDERR, "Missing WordPress-side policy gate: {$needle}\n");
        exit(1);
    }
}

if (strpos($controller, '__return_true') !== false) {
    fwrite(STDERR, "Sensitive REST routes must not use __return_true permissions.\n");
    exit(1);
}

foreach (array('eval(', 'shell_exec(', 'passthru(', 'proc_open(', 'popen(') as $primitive) {
    if (strpos($controller, $primitive) !== false || strpos($assetStore, $primitive) !== false) {
        fwrite(STDERR, "Forbidden execution primitive in REST transport: {$primitive}\n");
        exit(1);
    }
}

if (strpos($bootstrap, "'includes/REST/Controller.php'") === false || strpos($bootstrap, "'includes/REST/AssetStore.php'") === false) {
    fwrite(STDERR, "REST transport files are not loaded by plugin bootstrap.\n");
    exit(1);
}

echo "REST transport contract OK\n";
