<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Json.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Input.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/WooCommerceAdapter.php';

class WooCommerce {}
class WC_Order
{
    public static bool $bounded_store_available = true;
    public function get_id(): int { return 81; }
    public function get_status(): string { return 'processing'; }
    public function get_currency(): string { return 'EUR'; }
    public function get_total(): string { return '42.00'; }
    public function get_total_tax(): string { return '7.29'; }
    public function get_shipping_total(): string { return '4.00'; }
    public function get_discount_total(): string { return '0.00'; }
    public function get_date_created() { return null; }
    public function get_date_modified() { return null; }
    public function get_customer_id(): int { return 9; }
    public function get_payment_method(): string { return 'bank'; }
    public function get_shipping_method(): string { return 'flat_rate'; }
    public function get_items(string $type): array { throw new RuntimeException('Unbounded order item hydration must not be used.'); }
    public function get_data_store() { return new WooOrderDataStoreStub(); }
    public function get_billing_first_name(): string { return 'Ada'; }
    public function get_billing_last_name(): string { return 'Example'; }
    public function get_billing_company(): string { return ''; }
    public function get_billing_address_1(): string { return 'Example Street 1'; }
    public function get_billing_address_2(): string { return ''; }
    public function get_billing_city(): string { return 'Utrecht'; }
    public function get_billing_state(): string { return ''; }
    public function get_billing_postcode(): string { return '1234 AB'; }
    public function get_billing_country(): string { return 'NL'; }
    public function get_billing_email(): string { return 'private@example.test'; }
    public function get_billing_phone(): string { return '0612345678'; }
    public function get_shipping_first_name(): string { return 'Ada'; }
    public function get_shipping_last_name(): string { return 'Example'; }
    public function get_shipping_company(): string { return ''; }
    public function get_shipping_address_1(): string { return 'Example Street 1'; }
    public function get_shipping_address_2(): string { return ''; }
    public function get_shipping_city(): string { return 'Utrecht'; }
    public function get_shipping_state(): string { return ''; }
    public function get_shipping_postcode(): string { return '1234 AB'; }
    public function get_shipping_country(): string { return 'NL'; }
}
class WooOrderDataStoreStub
{
    public function has_callable(string $method): bool { return 'get_item_ids' === $method && WC_Order::$bounded_store_available; }
    public function get_item_ids(WC_Order $order, ?string $type = null): array
    {
        if (! WC_Order::$bounded_store_available || 'line_item' !== $type || 81 !== $order->get_id()) {
            throw new RuntimeException('Unexpected bounded order item query.');
        }
        return range(1, 51);
    }
}
class WC_Order_Item_Product
{
    private int $id;
    public function __construct(int $id) { $this->id = $id; }
    public function get_order_id(): int { return 81; }
    public function get_product_id(): int { return 99 + $this->id; }
    public function get_variation_id(): int { return 0; }
    public function get_name(): string { return 'Product ' . $this->id; }
    public function get_quantity(): int { return 1; }
    public function get_subtotal(): string { return '1.00'; }
    public function get_total(): string { return '1.00'; }
}
class WC_Order_Factory
{
    public static function get_order_item(int $id) { return $id >= 1 && $id <= 51 ? new WC_Order_Item_Product($id) : false; }
}
class WooOrderWpdbStub
{
    public string $prefix = 'wp_';
    public function __call(string $name, array $arguments) { throw new RuntimeException('Direct WooCommerce order-item SQL must not be used.'); }
}


function wc_get_product(int $id = 0) { return null; }
function wc_get_order(int $id) { return 81 === $id ? new WC_Order() : false; }
function wc_get_orders(array $args): object
{
    if (! in_array($args['limit'], array(25, 100), true) || 3 !== $args['page'] || 9 !== $args['customer'] || array('wc-processing') !== $args['status']) {
        throw new RuntimeException('WooCommerce order query arguments were not bounded or mapped correctly.');
    }
    return (object) array('orders' => array(new WC_Order()), 'total' => 1, 'max_num_pages' => 1);
}
function wc_get_order_statuses(): array { return array('wc-processing' => 'Processing'); }
function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value)); }
function current_user_can(string $capability): bool { return 'manage_woocommerce' === $capability; }

$wpdb = new WooOrderWpdbStub();
putenv('WPCONNECTOR_ALLOW_PRIVILEGED=1');
putenv('WPCONNECTOR_ALLOW_SENSITIVE=1');
$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$adapter = new \Webactueel\WordPressConnector\Adapters\WooCommerceAdapter();
$adapter->register($registry);
foreach (array('woocommerce.order.list', 'woocommerce.order.get') as $action) {
    $descriptor = $registry->descriptor($action);
    if (! $descriptor['sensitive'] || ! $descriptor['privileged'] || 'manage_woocommerce' !== ($descriptor['capability'] ?? '')) throw new RuntimeException('Order reads must remain privileged, sensitive and capability-gated.');
    \Webactueel\WordPressConnector\Security\Policy::assertActionAllowed($descriptor, false, true);
}
$list = $adapter->orderList(array('per_page' => 500, 'page' => 3, 'customer_id' => 9, 'status' => 'wc-processing'));
if (100 !== $list['per_page'] || isset($list['orders'][0]['billing']) || isset($list['orders'][0]['shipping']) || isset($list['orders'][0]['customer_id']) || isset($list['orders'][0]['items'])) {
    throw new RuntimeException('Order list was not bounded or omitted customer and line-item data.');
}
$listUnprefixedStatus = $adapter->orderList(array('per_page' => 25, 'page' => 3, 'customer_id' => 9, 'status' => 'processing'));
if (isset($listUnprefixedStatus['orders'][0]['items'])) throw new RuntimeException('Order list must continue omitting line-item detail.');
foreach (array(true, 9.5, '9junk', '09') as $badCustomerId) {
    try {
        $adapter->orderList(array('customer_id' => $badCustomerId));
        throw new RuntimeException('Malformed order-list customer_id was accepted.');
    } catch (RuntimeException $expected) {
        if ('customer_id must be a positive integer.' !== $expected->getMessage()) throw $expected;
    }
}
foreach (array(true, 81.5, '81junk', '081') as $badOrderId) {
    try {
        $adapter->orderGet(array('id' => $badOrderId));
        throw new RuntimeException('Malformed order id was accepted.');
    } catch (RuntimeException $expected) {
        if ('A positive order id is required.' !== $expected->getMessage()) throw $expected;
    }
}
$summary = $adapter->orderGet(array('id' => 81));
if (isset($summary['order']['billing']) || isset($summary['order']['shipping']) || isset($summary['order']['customer_id'])) throw new RuntimeException('Order personal data must be omitted by default.');
if (50 !== count($summary['order']['items']) || 51 !== $summary['order']['item_count'] || ! $summary['order']['items_truncated'] || 100 !== $summary['order']['items'][0]['product_id'] || 'Product 1' !== $summary['order']['items'][0]['name']) throw new RuntimeException('Order line items must be capped with truncation evidence.');
WC_Order::$bounded_store_available = false;
try {
    $adapter->orderGet(array('id' => 81));
    throw new RuntimeException('Order item reads without a bounded data-store API were accepted.');
} catch (RuntimeException $expected) {
    if ('WooCommerce bounded order item data store API is unavailable.' !== $expected->getMessage()) throw $expected;
}
WC_Order::$bounded_store_available = true;
$detail = $adapter->orderGet(array('id' => 81, 'include_personal_data' => true));
if ('private@example.test' !== $detail['order']['billing']['email']) throw new RuntimeException('Explicit per-order personal data request failed.');
echo "WooCommerce order read contract OK\n";
