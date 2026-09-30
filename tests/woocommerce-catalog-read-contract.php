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
    public static function get_shipping_zones(?array $zoneIds = null): array
    {
        if (empty($zoneIds)) return array();
        $zones = array();
        foreach ($zoneIds as $id) if (4 === (int) $id) $zones[$id] = new WC_Shipping_Zone((int) $id);
        return $zones;
    }
    public static function get_zone(int $id) { return 0 === $id || 4 === $id ? new WC_Shipping_Zone($id) : false; }
}
class WC_Tax
{
    public static function get_tax_rate_classes(): array { return array((object) array('tax_rate_class_id' => 8, 'name' => 'Reduced rate', 'slug' => 'reduced-rate')); }
    public static function get_tax_class_slugs(): array { return array('reduced-rate'); }
}
class WooTaxWpdbStub
{
    public $prefix = 'wp_';
    public function prepare(string $query, ...$values): string
    {
        foreach ($values as $value) {
            $replacement = is_int($value) ? (string) $value : "'" . addslashes((string) $value) . "'";
            $query = preg_replace('/%[sd]/', $replacement, $query, 1);
        }
        return $query;
    }
    public function get_var(string $query)
    {
        if (false !== strpos($query, 'COUNT(*)') && false !== strpos($query, 'woocommerce_tax_rates')) return 101;
        if (false !== strpos($query, 'COUNT(*)') && false !== strpos($query, 'woocommerce_shipping_zones')) return 1;
        if (false !== strpos($query, 'COUNT(*)') && false !== strpos($query, 'woocommerce_shipping_zone_locations')) return 101;
        throw new RuntimeException('Unexpected tax scalar query: ' . $query);
    }
    public function get_results(string $query): array
    {
        if (false !== strpos($query, 'SELECT `zone_id`, `zone_name`, `zone_order` FROM `wp_woocommerce_shipping_zones`') && false !== strpos($query, 'WHERE `zone_id` > 0')) {
            preg_match('/LIMIT (\d+) OFFSET (\d+)/', $query, $matches);
            if (empty($matches) || (int) $matches[1] > 50) throw new RuntimeException('Shipping zone list must be paginated.');
            if ((int) $matches[2] > 0) return array();
            return array((object) array('zone_id' => 4, 'zone_name' => 'Netherlands', 'zone_order' => 2));
        }
        if (false !== strpos($query, 'SELECT `zone_id`, `zone_name`, `zone_order` FROM `wp_woocommerce_shipping_zones`') && false !== strpos($query, 'WHERE `zone_id` =')) {
            return false !== strpos($query, 'WHERE `zone_id` = 4') ? array((object) array('zone_id' => 4, 'zone_name' => 'Netherlands', 'zone_order' => 2)) : array();
        }
        if (false !== strpos($query, 'SELECT `location_code`, `location_type`') && false !== strpos($query, 'woocommerce_shipping_zone_locations')) {
            if (false === strpos($query, 'LIMIT 100')) throw new RuntimeException('Shipping zone locations must have a hard result bound.');
            $locations = array();
            for ($i = 1; $i <= 100; $i++) $locations[] = (object) array('location_code' => '12345-' . (12345 + $i), 'location_type' => 'postcode');
            return $locations;
        }
        if (false !== strpos($query, 'SELECT `tax_rate_id`, `tax_rate_country`')) {
            if (false === strpos($query, 'LIMIT 50')) throw new RuntimeException('Tax rates must use bounded SQL pagination.');
            preg_match('/OFFSET (\d+)/', $query, $matches);
            $offset = isset($matches[1]) ? (int) $matches[1] : 0;
            return array((object) array('tax_rate_id' => (string) (12 + $offset), 'tax_rate_country' => 'NL', 'tax_rate' => '9.0000', 'tax_rate_name' => 'Reduced', 'secret' => 'must-not-leak'));
        }
        if (false !== strpos($query, 'COUNT(*) AS `location_count`')) {
            return array((object) array('tax_rate_id' => 12, 'location_count' => 101));
        }
        if (false !== strpos($query, 'SELECT `location_type`, `location_code`')) {
            if (false === strpos($query, 'LIMIT 100')) throw new RuntimeException('Tax rate locations must have a hard result bound.');
            $locations = array();
            for ($i = 1; $i <= 100; $i++) $locations[] = (object) array('location_type' => 'postcode', 'location_code' => '12345-' . (12345 + $i));
            return $locations;
        }
        throw new RuntimeException('Unexpected tax result query: ' . $query);
    }
}
function get_user_by(string $field, int $id) { return in_array($id, array(17, 18, 19, 20), true) ? (object) array('ID' => $id, 'roles' => array('test-role')) : false; }
function wc_get_product(int $id = 0) { return null; }
function current_user_can(string $capability): bool { return 'manage_woocommerce' === $capability; }
function sanitize_title(string $value): string { return strtolower(trim(preg_replace('/[^a-z0-9-]+/i', '-', $value), '-')); }
$wpdb = new WooTaxWpdbStub();

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
foreach (array(true, 17.9, '17junk', '017') as $badCustomerId) {
    try {
        $adapter->customerGet(array('id' => $badCustomerId, 'include_personal_data' => true));
        throw new RuntimeException('Malformed customer id was accepted.');
    } catch (RuntimeException $expected) {
        if ('A WooCommerce customer account id is required.' !== $expected->getMessage()) throw $expected;
    }
}
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
$zones = $adapter->shippingZoneList(array('per_page' => 500));
if (50 !== $zones['per_page'] || 2 !== $zones['total'] || 100 !== count($zones['zones'][0]['locations']) || 101 !== $zones['zones'][0]['location_count'] || ! $zones['zones'][0]['locations_truncated'] || array_key_exists('methods', $zones['zones'][0]) || 0 !== $zones['zones'][1]['id']) throw new RuntimeException('Shipping zones must be paginated, include the default zone and bound location details.');
$firstZonePage = $adapter->shippingZoneList(array('per_page' => 1, 'page' => 1));
$lastZonePage = $adapter->shippingZoneList(array('per_page' => 1, 'page' => 2));
if (1 !== $firstZonePage['page'] || 2 !== $firstZonePage['pages'] || 4 !== $firstZonePage['zones'][0]['id'] || 0 !== $lastZonePage['zones'][0]['id']) throw new RuntimeException('Shipping zone pagination did not place the default zone at the end.');
$zone = $adapter->shippingZoneGet(array('id' => 4));
if ('Netherlands' !== $zone['zone']['name']) throw new RuntimeException('Shipping zone lookup failed.');
$defaultZone = $adapter->shippingZoneGet(array('id' => '0'));
if (0 !== $defaultZone['zone']['id']) throw new RuntimeException('The default shipping zone must be addressable with canonical zero.');
try {
    $adapter->shippingZoneGet(array('id' => 'unknown'));
    throw new RuntimeException('Nonnumeric shipping zone id was accepted as the default zone.');
} catch (RuntimeException $expected) {
    if ('A valid shipping zone id is required.' !== $expected->getMessage()) throw $expected;
}
try {
    $adapter->shippingZoneGet(array('id' => 99));
    throw new RuntimeException('Unknown shipping zone id was accepted.');
} catch (RuntimeException $expected) {
    if ('WooCommerce shipping zone not found.' !== $expected->getMessage()) throw $expected;
}
$classes = $adapter->taxClassList();
if ('reduced-rate' !== $classes['classes'][0]['slug'] || '' !== $classes['standard_class']) throw new RuntimeException('Tax class inventory failed.');
$rates = $adapter->taxRateList(array('tax_class' => 'reduced-rate', 'per_page' => 500));
if (50 !== $rates['per_page'] || 101 !== $rates['total'] || 3 !== $rates['pages'] || '12' !== $rates['rates'][0]['id'] || isset($rates['rates'][0]['secret'])) throw new RuntimeException('Tax rates were not bounded and allowlisted.');
if (100 !== count($rates['rates'][0]['locations']) || 101 !== $rates['rates'][0]['location_count'] || ! $rates['rates'][0]['locations_truncated'] || 'postcode' !== $rates['rates'][0]['locations'][0]['type']) throw new RuntimeException('Tax rate city/postcode locations were not returned with a hard bound and truncation evidence.');
$nextRates = $adapter->taxRateList(array('tax_class' => 'reduced-rate', 'per_page' => 500, 'page' => 2));
if ('62' !== $nextRates['rates'][0]['id'] || 2 !== $nextRates['page']) throw new RuntimeException('Tax rate SQL pagination did not map page and offset correctly.');
try {
    $adapter->taxRateList(array('tax_class' => 'not-a-real-class'));
    throw new RuntimeException('Unknown tax class was accepted.');
} catch (RuntimeException $expected) {
    if ('Unknown WooCommerce tax class.' !== $expected->getMessage()) throw $expected;
}
echo "WooCommerce catalog read contract OK\n";
