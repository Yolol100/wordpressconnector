<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$GLOBALS['mailbox_bridge_transients'] = array();

if (! function_exists('set_transient')) {
    function set_transient($key, $value, $ttl): bool { $GLOBALS['mailbox_bridge_transients'][$key] = $value; return true; }
}
if (! function_exists('get_transient')) {
    function get_transient($key) { return $GLOBALS['mailbox_bridge_transients'][$key] ?? false; }
}
if (! function_exists('delete_transient')) {
    function delete_transient($key): bool { unset($GLOBALS['mailbox_bridge_transients'][$key]); return true; }
}
if (! function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}

require_once $root . '/plugin/wordpressconnector/includes/Runtime/MailboxBridgeStore.php';

use Webactueel\WordPressConnector\Runtime\MailboxBridgeStore;

$store = new MailboxBridgeStore();
$requestId = 'mailbox-contract-123456';
$first = $store->putRequest($requestId, array('action'=>'list_folders'), 300);
if (empty($first['created']) || !preg_match('/^[a-f0-9]{64}$/', (string)$first['sha256'])) {
    fwrite(STDERR,"Mailbox request was not stored.\n"); exit(1);
}
$replay = $store->putRequest($requestId, array('action'=>'list_folders'), 300);
if (!empty($replay['created'])) {
    fwrite(STDERR,"Identical mailbox request was not idempotent.\n"); exit(1);
}
try {
    $store->putRequest($requestId, array('action'=>'send'), 300);
    fwrite(STDERR,"Conflicting mailbox request_id was accepted.\n"); exit(1);
} catch (RuntimeException $e) {
}
$result = $store->putResult($requestId, array('ok'=>true,'result'=>array('folders'=>array('INBOX'))));
if (empty($result['created'])) {
    fwrite(STDERR,"Mailbox result was not stored.\n"); exit(1);
}
$read = $store->getResult($requestId);
if (empty($read['ready']) || ($read['result']['result']['folders'][0] ?? null) !== 'INBOX') {
    fwrite(STDERR,"Mailbox result readback failed.\n"); exit(1);
}
$store->clear($requestId);
if (!empty($store->getResult($requestId)['ready'])) {
    fwrite(STDERR,"Mailbox bridge cleanup failed.\n"); exit(1);
}

$adapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/MailboxBridgeAdapter.php');
$controller = file_get_contents($root . '/plugin/wordpressconnector/includes/REST/MailboxBridgeController.php');
$oidc = file_get_contents($root . '/plugin/wordpressconnector/includes/Security/GitHubOidc.php');
foreach (array('mailbox.bridge.request_put','mailbox.bridge.result_get','mailbox.bridge.clear','confirm_send=true','Destructive mailbox requests require confirm=true') as $needle) {
    if (strpos($adapter,$needle)===false) {
        fwrite(STDERR,"Missing mailbox adapter contract: {$needle}\n"); exit(1);
    }
}
foreach (array('/mailbox/requests/','/mailbox/results/','authenticateMailboxExecutor','MAX_RESULT_BYTES = 4194304',"current_user_can('manage_options')") as $needle) {
    if (strpos($controller,$needle)===false) {
        fwrite(STDERR,"Missing mailbox REST contract: {$needle}\n"); exit(1);
    }
}
foreach (array("MAILBOX_REPOSITORY = 'Yolol100/Leadscanner'","MAILBOX_REPOSITORY_ID = '1334704263'",'mailbox-execute.yml@refs/heads/main',"'event_name' => 'issues'","'repository_visibility' => 'public'") as $needle) {
    if (strpos($oidc,$needle)===false) {
        fwrite(STDERR,"Missing mailbox OIDC boundary: {$needle}\n"); exit(1);
    }
}
if (strpos($controller,'__return_true')!==false) {
    fwrite(STDERR,"Mailbox routes must not use __return_true.\n"); exit(1);
}
echo "mailbox bridge contract OK\n";
