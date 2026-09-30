<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Input.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/WooCommerceAdapter.php';

class WooCommerce {}
class WC_Order
{
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
    public function get_items(string $type): array { return array(); }
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

function wc_get_product(int $id = 0) { return null; }
function wc_get_order(int $id) { return 81 === $id ? new WC_Order() : false; }
function wc_get_orders(array $args): object
{
    if (100 !== $args['limit'] || 3 !== $args['page'] || 9 !== $args['customer'] || array('wc-processing') !== $args['status']) {
        throw new RuntimeException('WooCommerce order query arguments were not bounded or mapped correctly.');
    }
    return (object) array('orders' => array(new WC_Order()), 'total' => 1, 'max_num_pages' => 1);
}
function wc_get_order_statuses(): array { return array('wc-processing' => 'Processing'); }
function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value)); }

putenv('WPCONNECTOR_ALLOW_PRIVILEGED=1');
putenv('WPCONNECTOR_ALLOW_SENSITIVE=1');
$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$adapter = new \Webactueel\WordPressConnector\Adapters\WooCommerceAdapter();
$adapter->register($registry);
foreach (array('woocommerce.order.list', 'woocommerce.order.get') as $action) {
    $descriptor = $registry->descriptor($action);
    if (! $descriptor['sensitive'] || ! $descriptor['privileged']) throw new RuntimeException('Order reads must remain privileged and sensitive.');
    \Webactueel\WordPressConnector\Security\Policy::assertActionAllowed($descriptor, false, true);
}
$list = $adapter->orderList(array('per_page' => 500, 'page' => 3, 'customer_id' => 9, 'status' => 'wc-processing'));
if (100 !== $list['per_page'] || isset($list['orders'][0]['billing']) || isset($list['orders'][0]['shipping'])) {
    throw new RuntimeException('Order list was not bounded or included personal data.');
}
$summary = $adapter->orderGet(array('id' => 81));
if (isset($summary['order']['billing']) || isset($summary['order']['shipping'])) throw new RuntimeException('Order personal data must be omitted by default.');
$detail = $adapter->orderGet(array('id' => 81, 'include_personal_data' => true));
if ('private@example.test' !== $detail['order']['billing']['email']) throw new RuntimeException('Explicit per-order personal data request failed.');
echo "WooCommerce order read contract OK\n";
