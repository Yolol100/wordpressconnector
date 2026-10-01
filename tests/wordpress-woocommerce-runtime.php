<?php

use Webactueel\WordPressConnector\Adapters\WooCommerceAdapter;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

if (! defined('WPCONNECTOR_VERSION') || ! class_exists('WooCommerce') || ! function_exists('wc_create_order')) {
    throw new RuntimeException('Required WordPress Connector or WooCommerce runtime is not loaded.');
}

wp_set_current_user(1);
if (! current_user_can('manage_woocommerce')) {
    throw new RuntimeException('Runtime administrator lacks manage_woocommerce.');
}

$expectHpos = 'yes' === getenv('WPC_RUNTIME_EXPECT_HPOS');
$product = new WC_Product_Simple();
$product->set_name('Connector runtime product');
$product->set_status('publish');
$product->set_regular_price('1.00');
$productId = $product->save();
if ($productId < 1) {
    throw new RuntimeException('Runtime product creation failed.');
}

$order = wc_create_order();
if (! $order instanceof WC_Order) {
    throw new RuntimeException('Runtime order creation failed.');
}

try {
    for ($i = 0; $i < 51; $i++) {
        $order->add_product($product, 1);
    }
    $order->calculate_totals();
    $order->save();

    $fresh = wc_get_order($order->get_id());
    if (! $fresh instanceof WC_Order) {
        throw new RuntimeException('Runtime order readback failed.');
    }
    $dataStore = $fresh->get_data_store();
    if (! is_object($dataStore) || ! method_exists($dataStore, 'get_current_class_name')) {
        throw new RuntimeException('Active WooCommerce order data store cannot be identified.');
    }
    $storeClass = ltrim((string) $dataStore->get_current_class_name(), '\\');
    $isHposStore = 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\OrdersTableDataStore' === $storeClass;
    if ($expectHpos !== $isHposStore) {
        throw new RuntimeException('Unexpected WooCommerce authoritative order store: ' . $storeClass);
    }

    putenv('WPCONNECTOR_ALLOW_PRIVILEGED=1');
    putenv('WPCONNECTOR_ALLOW_SENSITIVE=1');
    $registry = new Registry();
    $adapter = new WooCommerceAdapter();
    $adapter->register($registry);
    Policy::assertActionAllowed($registry->descriptor('woocommerce.order.get'), false, true);

    $detail = $adapter->orderGet(array('id' => (int) $fresh->get_id()));
    if (51 !== ($detail['order']['item_count'] ?? null) ||
        true !== ($detail['order']['items_truncated'] ?? null) ||
        50 !== count($detail['order']['items'] ?? array())) {
        throw new RuntimeException('Bounded WooCommerce order detail runtime contract failed.');
    }
    if (isset($detail['order']['billing']) || isset($detail['order']['shipping']) || isset($detail['order']['customer_id'])) {
        throw new RuntimeException('Order detail exposed personal data without explicit opt-in.');
    }

    $list = $adapter->orderList(array('per_page' => 5, 'page' => 1));
    foreach ($list['orders'] ?? array() as $summary) {
        if (isset($summary['items']) || isset($summary['billing']) || isset($summary['shipping']) || isset($summary['customer_id'])) {
            throw new RuntimeException('Order list exposed bounded-detail or personal-data fields.');
        }
    }

    echo wp_json_encode(array(
        'ok' => true,
        'wordpress' => get_bloginfo('version'),
        'woocommerce' => defined('WC_VERSION') ? WC_VERSION : '',
        'store' => $storeClass,
        'hpos' => $isHposStore,
        'item_count' => $detail['order']['item_count'],
        'returned_items' => count($detail['order']['items']),
    ), JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $storedOrder = wc_get_order($order->get_id());
    if ($storedOrder instanceof WC_Order) {
        $storedOrder->delete(true);
    }
    $storedProduct = wc_get_product($productId);
    if ($storedProduct instanceof WC_Product) {
        $storedProduct->delete(true);
    }
}
