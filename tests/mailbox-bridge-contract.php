<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$GLOBALS['mailbox_bridge_transients'] = array();
$GLOBALS['mailbox_bridge_options'] = array();

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
if (! function_exists('add_option')) {
    function add_option($key, $value, $deprecated = '', $autoload = false): bool {
        if (array_key_exists($key, $GLOBALS['mailbox_bridge_options'])) { return false; }
        $GLOBALS['mailbox_bridge_options'][$key] = $value; return true;
    }
}
if (! function_exists('get_option')) {
    function get_option($key, $default = false) { return $GLOBALS['mailbox_bridge_options'][$key] ?? $default; }
}
if (! function_exists('delete_option')) {
    function delete_option($key): bool { unset($GLOBALS['mailbox_bridge_options'][$key]); return true; }
}
if (! function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, $group = ''): bool { return true; }
}
final class MailboxBridgeWpdb {
    public string $options = 'wp_options';
    public function update($table, $data, $where, $format = null, $whereFormat = null): int {
        $key=(string)($where['option_name'] ?? '');
        $expected=(string)($where['option_value'] ?? '');
        if (!isset($GLOBALS['mailbox_bridge_options'][$key]) || $GLOBALS['mailbox_bridge_options'][$key] !== $expected) { return 0; }
        $GLOBALS['mailbox_bridge_options'][$key]=(string)$data['option_value']; return 1;
    }
    public function delete($table, $where, $whereFormat = null): int {
        $key=(string)($where['option_name'] ?? '');
        $expected=(string)($where['option_value'] ?? '');
        if (!isset($GLOBALS['mailbox_bridge_options'][$key]) || $GLOBALS['mailbox_bridge_options'][$key] !== $expected) { return 0; }
        unset($GLOBALS['mailbox_bridge_options'][$key]); return 1;
    }
}
$GLOBALS['wpdb'] = new MailboxBridgeWpdb();

require_once $root . '/plugin/wordpressconnector/includes/Runtime/MailboxBridgeStore.php';

use Webactueel\WordPressConnector\Runtime\MailboxBridgeStore;

$store = new MailboxBridgeStore();
try {
    $store->validateRequestId('bad id');
    fwrite(STDERR,"Malformed mailbox request_id passed validation.\n"); exit(1);
} catch (RuntimeException $e) {
}
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
$lockId = 'mailbox-lock-123456';
$lockFirst = $store->putRequest($lockId, array('action'=>'list_folders'), 300);
$lockKey = 'wpconnector_mailbox_lock_' . hash('sha256', $lockId);
$GLOBALS['mailbox_bridge_options'][$lockKey] = json_encode(array('token'=>'other','created_at'=>time()));
try {
    $store->putResult($lockId, array('ok'=>true), (string)$lockFirst['sha256']);
    fwrite(STDERR,"Concurrent mailbox result write was not blocked.\n"); exit(1);
} catch (RuntimeException $e) {
    if (false === strpos($e->getMessage(),'busy')) { throw $e; }
}
unset($GLOBALS['mailbox_bridge_options'][$lockKey]);
$result = $store->putResult($requestId, array('ok'=>true,'result'=>array('folders'=>array('INBOX'))), (string)$first['sha256']);
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
$staleId = 'mailbox-stale-123456';
$staleFirst = $store->putRequest($staleId, array('action'=>'list_folders'), 300);
$store->clear($staleId);
$staleSecond = $store->putRequest($staleId, array('action'=>'list_messages','folder'=>'INBOX'), 300);
try {
    $store->putResult($staleId, array('ok'=>true), (string)$staleFirst['sha256']);
    fwrite(STDERR,"Stale mailbox result hash was accepted.\n"); exit(1);
} catch (RuntimeException $e) {
    if (false === strpos($e->getMessage(),'changed')) { throw $e; }
}
$store->clear($staleId);

$adapter = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/MailboxBridgeAdapter.php');
$controller = file_get_contents($root . '/plugin/wordpressconnector/includes/REST/MailboxBridgeController.php');
$oidc = file_get_contents($root . '/plugin/wordpressconnector/includes/Security/GitHubOidc.php');
$uninstall = file_get_contents($root . '/plugin/wordpressconnector/uninstall.php');
$policy = file_get_contents($root . '/plugin/wordpressconnector/includes/Security/Policy.php');
foreach (array('mailbox.bridge.request_put','mailbox.bridge.result_get','mailbox.bridge.clear','confirm_send=true','Destructive mailbox requests require confirm=true') as $needle) {
    if (strpos($adapter,$needle)===false) {
        fwrite(STDERR,"Missing mailbox adapter contract: {$needle}\n"); exit(1);
    }
}
foreach (array('/mailbox/requests/','/mailbox/results/','authenticateMailboxExecutor','MAX_RESULT_BYTES = 4194304',"current_user_can('manage_options')",'is_object($shape)',"get_header('x-webactueel-mailbox-request-sha256')") as $needle) {
    if (strpos($controller,$needle)===false) {
        fwrite(STDERR,"Missing mailbox REST contract: {$needle}\n"); exit(1);
    }
}
foreach (array("MAILBOX_REPOSITORY = 'Yolol100/Leadscanner'","MAILBOX_REPOSITORY_ID = '1334704263'",'mailbox-execute.yml@refs/heads/main',"'event_name' => 'issues'","'repository_visibility' => 'public'","'sha' => $this->mailboxMainSha()") as $needle) {
    if (strpos($oidc,$needle)===false) {
        fwrite(STDERR,"Missing mailbox OIDC boundary: {$needle}\n"); exit(1);
    }
}
foreach (array('_transient_wpconnector_mailbox_request_','_transient_timeout_wpconnector_mailbox_request_','_transient_wpconnector_mailbox_result_','_transient_timeout_wpconnector_mailbox_result_','wpconnector_mailbox_lock_') as $needle) {
    if (strpos($uninstall,$needle)===false) {
        fwrite(STDERR,"Missing mailbox uninstall cleanup: {$needle}\n"); exit(1);
    }
}
if (strpos($policy,'wpconnector_mailbox_')===false) {
    fwrite(STDERR,"Mailbox option namespace is not reserved by policy.\n"); exit(1);
}
if (strpos($controller,'__return_true')!==false) {
    fwrite(STDERR,"Mailbox routes must not use __return_true.\n"); exit(1);
}
echo "mailbox bridge contract OK\n";
