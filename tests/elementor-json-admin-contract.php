<?php

declare(strict_types=1);

$GLOBALS['wpconnector_admin_test_hooks'] = array();
$GLOBALS['wpconnector_admin_test_nonce_checks'] = 0;

function is_admin(): bool { return true; }
function add_filter(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool { $GLOBALS['wpconnector_admin_test_hooks'][$hook] = array($callback, $priority, $acceptedArgs); return true; }
function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool { $GLOBALS['wpconnector_admin_test_hooks'][$hook] = array($callback, $priority, $acceptedArgs); return true; }
function __(string $text, string $domain = ''): string { return $text; }
function check_admin_referer(string $action): int { ++$GLOBALS['wpconnector_admin_test_nonce_checks']; return 1; }
function absint($value): int { return abs((int) $value); }
function add_query_arg($key, $value = null, $url = null): string
{
    if (is_array($key)) { $args = $key; $base = (string) $value; }
    else { $args = array((string) $key => $value); $base = (string) $url; }
    return $base . (false === strpos($base, '?') ? '?' : '&') . http_build_query($args, '', '&');
}

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Admin/ElementorJsonExport.php';

$assert = static function (bool $condition, string $message): void {
    if (! $condition) { fwrite(STDERR, $message . "\n"); exit(1); }
};

$export = new \Webactueel\WordPressConnector\Admin\ElementorJsonExport();
$export->register();

$expectedHooks = array(
    'page_row_actions',
    'post_row_actions',
    'admin_post_wpconnector_export_elementor_json',
    'bulk_actions-edit-page',
    'handle_bulk_actions-edit-page',
    'bulk_actions-edit-post',
    'handle_bulk_actions-edit-post',
    'bulk_actions-edit-elementor_library',
    'handle_bulk_actions-edit-elementor_library',
    'admin_notices',
);
foreach ($expectedHooks as $hook) {
    $assert(isset($GLOBALS['wpconnector_admin_test_hooks'][$hook]), 'Missing Elementor JSON admin hook: ' . $hook);
}

$actions = $export->bulkActions(array('trash' => 'Trash'));
$assert(isset($actions['wpconnector_bulk_export_elementor_json']), 'Bulk Elementor JSON export action is missing.');
$assert('Trash' === $actions['trash'], 'Bulk action registration must preserve existing actions.');

$redirect = 'https://example.test/wp-admin/edit.php?post_type=page';
$unchanged = $export->handleBulkAction($redirect, 'trash', array(1));
$assert($redirect === $unchanged, 'Unrelated bulk actions must pass through unchanged.');
$assert(0 === $GLOBALS['wpconnector_admin_test_nonce_checks'], 'Unrelated bulk actions must not trigger the export nonce check.');

$empty = $export->handleBulkAction($redirect, 'wpconnector_bulk_export_elementor_json', array());
$assert(false !== strpos($empty, 'wpconnector_elementor_bulk_export=none'), 'Empty bulk export must return a clear no-items status.');
$assert(1 === $GLOBALS['wpconnector_admin_test_nonce_checks'], 'Bulk export must validate the WordPress bulk nonce.');

$tooMany = $export->handleBulkAction($redirect, 'wpconnector_bulk_export_elementor_json', range(1, 101));
$assert(false !== strpos($tooMany, 'wpconnector_elementor_bulk_export=too_many'), 'Bulk export must fail closed above its item limit.');
$assert(false !== strpos($tooMany, 'wpconnector_elementor_bulk_count=101'), 'Bulk export limit response must report the selected count.');
$assert(2 === $GLOBALS['wpconnector_admin_test_nonce_checks'], 'Each requested bulk export must validate the nonce.');

$source = file_get_contents(dirname(__DIR__) . '/plugin/wordpressconnector/includes/Admin/ElementorJsonExport.php');
$assert(is_string($source), 'Unable to read ElementorJsonExport.php.');
foreach (array('MAX_BULK_BYTES', 'wordpressconnector/elementor-bulk-export', 'manifest.json', 'ZipArchive', 'PclZip', 'too_large') as $needle) {
    $assert(false !== strpos($source, $needle), 'Missing bulk export safety marker: ' . $needle);
}

echo "elementor JSON admin runtime contract OK\n";
