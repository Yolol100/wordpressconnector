<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Json.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Input.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/WooCommerceAdapter.php';

class WooCommerce {}
class WC_Customer
{
    private $id;
    public function __construct(int $id) { $this->id = $id; }
    public function get_id(): int { return (int) $this->id; }
    public function get_is_paying_customer(): bool { return true; }
    public function get_order_count(): int { return in_array($this->id, array(18, 19), true) ? 0 : 4; }
    public function get_role(): string { return array(17 => 'customer', 18 => 'administrator', 19 => 'subscriber', 20 => 'wholesale')[$this->id] ?? 'customer'; }
    public function get_total_spent(): string { return '125.00'; }
    public function get_email(): string { return 'private@example.test'; }
    public function get_first_name(): string { return 'Ada'; }
    public function get_last_name(): string { return 'Example'; }
    public function get_billing(): array { return array('email' => 'private@example.test', 'address_1' => 'Example Street', 'secret' => 'never-return'); }
    public function get_shipping(): array { return array('country' => 'NL', 'phone' => '0612345678'); }
}
class WC_Shipping_Zone
{
    private $id;
    public function __construct(int $id = 4) { $this->id = $id; }
    public function get_id(): int { return (int) $this->id; }
    public function get_zone_name(): string { return 0 === $this->id ? 'Rest of the world' : 'Netherlands'; }
    public function get_zone_order(): int { return 2; }
    public function get_zone_locations(): array { return array((object) array('code' => 'NL', 'type' => 'country')); }
}
class WC_Shipping_Zones
{
    public static function get_shipping_zones(): array { return array(new WC_Shipping_Zone()); }
    public static function get_zone(int $id) { return 0 === $id || 4 === $id ? new WC_Shipping_Zone($id) : false; }
}
class WC_Tax
{
    public static function get_tax_rate_classes(): array { return array((object) array('tax_rate_class_id' => 8, 'name' => 'Reduced rate', 'slug' => 'reduced-rate')); }
    public static function get_tax_class_slugs(): array { return array('reduced-rate'); }
    public static function get_rates_for_tax_class(string $class): array
    {
        return array((object) array('tax_rate_id' => '12', 'tax_rate_country' => 'NL', 'tax_rate' => '9.0000', 'tax_rate_name' => 'Reduced', 'secret' => 'must-not-leak'));
    }
}
function get_user_by(string $field, int $id) { return in_array($id, array(17, 18, 19, 20), true) ? (object) array('ID' => $id, 'roles' => array('test-role')) : false; }
function wc_get_product(int $id = 0) { return null; }
function current_user_can(string $capability): bool { return 'manage_woocommerce' === $capability; }
function sanitize_title(string $value): string { return strtolower(trim(preg_replace('/[^a-z0-9-]+/i', '-', $value), '-')); }

$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$adapter = new \Webactueel\WordPressConnector\Adapters\WooCommerceAdapter();
$adapter->register($registry);
foreach (array('woocommerce.customer.get', 'woocommerce.shipping_zone.list', 'woocommerce.shipping_zone.get', 'woocommerce.tax_class.list', 'woocommerce.tax_rate.list') as $action) {
    $descriptor = $registry->descriptor($action);
    if (empty($descriptor['privileged'])) throw new RuntimeException($action . ' must remain privileged.');
    if ('manage_woocommerce' !== ($descriptor['capability'] ?? '')) throw new RuntimeException($action . ' must require WooCommerce management capability.');
}
$customerDescriptor = $registry->descriptor('woocommerce.customer.get');
if (empty($customerDescriptor['sensitive']) || 'manage_woocommerce' !== ($customerDescriptor['capability'] ?? '')) {
    throw new RuntimeException('Customer reads must remain sensitive and require WooCommerce management capability.');
}
putenv('WPCONNECTOR_ALLOW_PRIVILEGED=1');
putenv('WPCONNECTOR_ALLOW_SENSITIVE=1');
\Webactueel\WordPressConnector\Security\Policy::assertActionAllowed($customerDescriptor, false, true);
$summary = $adapter->customerGet(array('id' => 17));
if (isset($summary['customer']['email']) || isset($summary['customer']['billing'])) throw new RuntimeException('Customer personal data must be omitted by default.');
$details = $adapter->customerGet(array('id' => 17, 'include_personal_data' => true));
if ('private@example.test' !== $details['customer']['email'] || isset($details['customer']['billing']['secret'])) throw new RuntimeException('Customer detail output was not safely allowlisted.');
try {
    $adapter->customerGet(array('id' => 99, 'include_personal_data' => true));
    throw new RuntimeException('Unknown customer id was accepted.');
} catch (RuntimeException $expected) {
    if ('A WooCommerce customer account id is required.' !== $expected->getMessage()) throw $expected;
}
try {
    $adapter->customerGet(array('id' => 18));
    throw new RuntimeException('A non-customer WordPress account was accepted as a WooCommerce customer.');
} catch (RuntimeException $expected) {
    if ('A WooCommerce customer account id is required.' !== $expected->getMessage()) throw $expected;
}
$subscriber = $adapter->customerGet(array('id' => 19));
if (19 !== $subscriber['customer']['id']) throw new RuntimeException('Subscriber-role WooCommerce customer was rejected.');
$customRole = $adapter->customerGet(array('id' => 20));
if (20 !== $customRole['customer']['id']) throw new RuntimeException('Custom-role customer with WooCommerce order history was rejected.');
$zones = $adapter->shippingZoneList();
if ('NL' !== $zones['zones'][0]['locations'][0]['code'] || array_key_exists('methods', $zones['zones'][0]) || 0 !== $zones['zones'][1]['id']) throw new RuntimeException('Shipping zones must include custom and default geography without method settings.');
$zone = $adapter->shippingZoneGet(array('id' => 4));
if ('Netherlands' !== $zone['zone']['name']) throw new RuntimeException('Shipping zone lookup failed.');
try {
    $adapter->shippingZoneGet(array('id' => 99));
    throw new RuntimeException('Unknown shipping zone id was accepted.');
} catch (RuntimeException $expected) {
    if ('WooCommerce shipping zone not found.' !== $expected->getMessage()) throw $expected;
}
$classes = $adapter->taxClassList();
if ('reduced-rate' !== $classes['classes'][0]['slug'] || '' !== $classes['standard_class']) throw new RuntimeException('Tax class inventory failed.');
$rates = $adapter->taxRateList(array('tax_class' => 'reduced-rate', 'per_page' => 500));
if (100 !== $rates['per_page'] || '12' !== $rates['rates'][0]['id'] || isset($rates['rates'][0]['secret'])) throw new RuntimeException('Tax rates were not bounded and allowlisted.');
try {
    $adapter->taxRateList(array('tax_class' => 'not-a-real-class'));
    throw new RuntimeException('Unknown tax class was accepted.');
} catch (RuntimeException $expected) {
    if ('Unknown WooCommerce tax class.' !== $expected->getMessage()) throw $expected;
}
echo "WooCommerce catalog read contract OK\n";
