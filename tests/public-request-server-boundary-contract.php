<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/plugin/wordpressconnector/includes/Support/Json.php';
require_once $root . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once $root . '/plugin/wordpressconnector/includes/Runtime/Request.php';

use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Security\Policy;

$expectFailure = static function (array $request, string $label, string $needle = ''): void {
    try {
        Request::fromArray($request);
    } catch (RuntimeException $error) {
        if ('' !== $needle && false === strpos($error->getMessage(), $needle)) {
            fwrite(STDERR, 'Unexpected public request failure for ' . $label . ': ' . $error->getMessage() . "\n");
            exit(1);
        }
        return;
    }
    fwrite(STDERR, 'Expected public request rejection: ' . $label . ".\n");
    exit(1);
};

$base = array(
    'version' => 1,
    'request_id' => 'server-boundary-0001',
    'action' => 'connector.update.apply',
    'dry_run' => false,
    'confirm' => true,
    'payload' => array(),
);

Policy::setPublicRepositoryContext(true);

foreach (array(
    'connector.update.apply',
    'elementor.patch_element',
    'acf.schema.ensure_text_fields',
    'acf.portfolio_stats_update',
    'connector.rollback',
) as $index => $action) {
    $request = $base;
    $request['request_id'] = 'server-boundary-fp-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
    $request['action'] = $action;
    if ('elementor.patch_element' === $action) {
        $request['payload'] = array('id' => 10, 'element_id' => 'abc123', 'settings' => array('title' => 'Safe'));
    } elseif ('acf.schema.ensure_text_fields' === $action) {
        $request['payload'] = array('post_id' => 10, 'group_key' => 'group_abcdef', 'fields' => array(array('key' => 'field_abcdef', 'name' => 'example', 'label' => 'Example')));
    } elseif ('acf.portfolio_stats_update' === $action) {
        $request['payload'] = array('post_id' => 10, 'fields' => array('field_portfolio_stat_1_value' => '1'));
    } elseif ('connector.rollback' === $action) {
        $request['payload'] = array('request_id' => 'previous-request-0001');
    } else {
        $request['payload'] = array();
    }
    $expectFailure($request, $action . ' without fingerprint', 'expected_fingerprint');
    $request['expected_fingerprint'] = str_repeat('a', 64);
    $parsed = Request::fromArray($request);
    if ($parsed->expectedFingerprint() !== str_repeat('a', 64)) {
        fwrite(STDERR, 'Public fingerprint guard did not preserve expected fingerprint for ' . $action . ".\n");
        exit(1);
    }
}

$batch = array(
    'version' => 1,
    'request_id' => 'server-boundary-batch-0001',
    'action' => 'connector.batch',
    'dry_run' => true,
    'confirm' => false,
    'payload' => array('operations' => array(
        array('action' => 'elementor.patch_element', 'payload' => array('id' => 10, 'element_id' => 'abc123', 'settings' => array('title' => 'No'))),
    )),
);
$expectFailure($batch, 'non-public batch leaf', 'not allowed');

$batch['request_id'] = 'server-boundary-batch-0002';
$batch['payload']['operations'] = array(
    array('action' => 'post.update', 'payload' => array('id' => 10, 'excerpt' => 'Safe')),
    array('action' => 'acf.update', 'payload' => array('post_id' => 10, 'fields' => array('field_abcdef' => 'Safe'))),
);
$parsedBatch = Request::fromArray($batch);
if ('connector.batch' !== $parsedBatch->action() || 2 !== count($parsedBatch->payload()['operations'])) {
    fwrite(STDERR, "Public safe batch leaves were rejected or changed.\n");
    exit(1);
}

Policy::setPublicRepositoryContext(false);
$nonPublic = $base;
$nonPublic['request_id'] = 'server-boundary-private-0001';
$parsedNonPublic = Request::fromArray($nonPublic);
if ('connector.update.apply' !== $parsedNonPublic->action() || null !== $parsedNonPublic->expectedFingerprint()) {
    fwrite(STDERR, "Public fingerprint gate leaked into non-public request parsing.\n");
    exit(1);
}

$nonPublicBatch = $batch;
$nonPublicBatch['request_id'] = 'server-boundary-private-0002';
$nonPublicBatch['payload']['operations'] = array(
    array('action' => 'elementor.patch_element', 'payload' => array('id' => 10)),
);
$parsedNonPublicBatch = Request::fromArray($nonPublicBatch);
if ('elementor.patch_element' !== ($parsedNonPublicBatch->payload()['operations'][0]['action'] ?? '')) {
    fwrite(STDERR, "Public batch leaf gate leaked into non-public request parsing.\n");
    exit(1);
}

echo "public request server boundary contract OK\n";
