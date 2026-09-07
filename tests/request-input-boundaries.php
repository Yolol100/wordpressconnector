<?php
declare(strict_types=1);

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Runtime/Request.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/REST/Controller.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/REST/AssetStore.php';

use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\REST\Controller;
use Webactueel\WordPressConnector\REST\AssetStore;

function check(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, $message . "\n"); exit(1); }
}
$controller = (new ReflectionClass(Controller::class))->newInstanceWithoutConstructor();
$valid = array('request_id' => 'boundary-001', 'action' => 'post.get', 'expected_fingerprint' => str_repeat('a', 64), 'expected_state_token' => str_repeat('b', 64));
Request::fromArray($valid);
check($controller->validateRequestId('boundary-001'), 'Valid request ID rejected.');
check($controller->validateAssetPath('media/photo.jpg'), 'Valid asset path rejected.');
foreach (array("\n", "\r", "\r\n", "\0", "\t") as $suffix) {
    foreach ($valid as $key => $value) {
        $input = $valid;
        $input[$key] = $value . $suffix;
        $rejected = false;
        try { Request::fromArray($input); } catch (RuntimeException $error) { $rejected = true; }
        check($rejected, 'Control character accepted in ' . $key);
    }
    check(!$controller->validateRequestId('boundary-001' . $suffix), 'REST accepted invalid ID.');
    check(!$controller->validateAssetPath('media/photo.jpg' . $suffix), 'REST accepted invalid asset path.');
}
echo "Request input boundaries OK\n";
