<?php

declare(strict_types=1);

class WooCommerce {}
function wc_get_product(int $id) { return false; }
function wc_get_attribute_taxonomies(): array
{
    return array((object) array(
        'attribute_id' => 17,
        'attribute_label' => 'Fabric',
        'attribute_name' => 'fabric',
        'attribute_type' => 'select',
        'attribute_orderby' => 'menu_order',
        'attribute_public' => 0,
    ));
}
function wc_attribute_taxonomy_name(string $name): string { return 'pa_' . $name; }
function wc_delete_attribute(int $id): bool
{
    $GLOBALS['deleted_attribute_id'] = $id;
    return $GLOBALS['attribute_result'];
}
function delete_transient(string $name): bool
{
    $GLOBALS['deleted_transients'][] = $name;
    return true;
}
function is_wp_error($candidate): bool { return false; }

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Json.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/WooCommerceAdapter.php';

$adapter = new \Webactueel\WordPressConnector\Adapters\WooCommerceAdapter();
$GLOBALS['deleted_transients'] = array();
$GLOBALS['attribute_result'] = false;

try {
    $adapter->attributeDelete(array('id' => 17), array());
    throw new RuntimeException('REGRESSION: failed WooCommerce attribute deletion was reported as success.');
} catch (RuntimeException $error) {
    if ('WooCommerce attribute deletion failed.' !== $error->getMessage()) {
        throw $error;
    }
}
if ($GLOBALS['deleted_transients'] !== array()) {
    throw new RuntimeException('Failed WooCommerce delete must not clear the attribute cache.');
}

$GLOBALS['attribute_result'] = true;
$success = $adapter->attributeDelete(array('id' => 17), array());
if (17 !== $GLOBALS['deleted_attribute_id']
    || 'woocommerce.attribute.create' !== ($success['_rollback']['action'] ?? '')
    || count($GLOBALS['deleted_transients']) !== 1) {
    throw new RuntimeException('Successful WooCommerce deletion lost cache invalidation or rollback metadata.');
}
echo "WooCommerce attribute deletion failure and successful rollback bookkeeping OK\n";
