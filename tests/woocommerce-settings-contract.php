<?php

declare(strict_types=1);

/** Provider-owned REST contract fixture: no WordPress/WooCommerce installation required. */
$GLOBALS['woocommerce_settings_user_caps'] = array();
$GLOBALS['woocommerce_settings_options'] = array(
    'general' => array(
        'woocommerce_currency_pos' => array('id' => 'woocommerce_currency_pos', 'label' => 'Currency position', 'type' => 'select', 'value' => 'left', 'options' => array('left' => 'Left', 'right' => 'Right')),
        'woocommerce_api_secret' => array('id' => 'woocommerce_api_secret', 'label' => 'Private key', 'type' => 'password', 'value' => 'DONT_EXPOSE_THIS_SECRET'),
        'woocommerce_enable_coupons' => array('id' => 'woocommerce_enable_coupons', 'label' => 'Coupons', 'type' => 'checkbox', 'value' => 'yes'),
    ),
    'products' => array(
        'woocommerce_weight_unit' => array('id' => 'woocommerce_weight_unit', 'label' => 'Weight unit', 'type' => 'select', 'value' => 'lbs', 'options' => array('lbs' => 'Pounds', 'kg' => 'Kilograms')),
        'woocommerce_enable_reviews' => array('id' => 'woocommerce_enable_reviews', 'label' => 'Reviews', 'type' => 'checkbox', 'value' => 'yes'),
    ),
    'checkout' => array(
        'woocommerce_mollie_api_key' => array('id' => 'woocommerce_mollie_api_key', 'label' => 'Mollie key', 'type' => 'password', 'value' => 'SECRET_GATEWAY_KEY'),
    ),
);
$GLOBALS['woocommerce_settings_put_count'] = 0;
$GLOBALS['woocommerce_settings_force_fail'] = false;

function current_user_can($capability): bool
{
    return in_array($capability, $GLOBALS['woocommerce_settings_user_caps'], true);
}
function get_option($id, $default = null) { return $GLOBALS['woocommerce_settings_options']['products'][$id]['value'] ?? $default; }
function sanitize_text_field($value): string
{
    return trim(strip_tags((string) $value));
}
class WP_REST_Request
{
    public $method;
    public $route;
    public $params = array();
    public function __construct($method, $route) { $this->method = $method; $this->route = $route; }
    public function set_param($name, $value) { $this->params[$name] = $value; }
}
class FakeWooResponse
{
    private $status;
    private $data;
    public function __construct($status, $data) { $this->status = $status; $this->data = $data; }
    public function get_status() { return $this->status; }
    public function get_data() { return $this->data; }
}
class FakeSettingsPage
{
    private $id;
    private $label;
    private $sections;
    public function __construct($id, $label, $sections) { $this->id=$id; $this->label=$label; $this->sections=$sections; }
    public function get_id() { return $this->id; }
    public function get_label() { return $this->label; }
    public function get_sections() { return $this->sections; }
    public function get_settings_for_section($section) {
        if ($this->id !== 'products') return array();
        if ($section === 'inventory') {
            return array(
                array('id'=>'stock_info', 'title'=>'Voorraad', 'type'=>'title'),
                array('id'=>'woocommerce_manage_stock', 'title'=>'Voorraad beheren', 'type'=>'checkbox', 'default'=>'no'),
                array('id'=>'stock_end', 'type'=>'sectionend'),
            );
        }
        return array(
            array('id'=>'product_info', 'title'=>'Producten', 'type'=>'title'),
            array('id'=>'woocommerce_weight_unit', 'title'=>'Gewichtseenheid', 'type'=>'select', 'default'=>'kg'),
            array('id'=>'woocommerce_api_secret', 'title'=>'Credential', 'type'=>'password', 'default'=>''),
        );
    }
}
class WC_Admin_Settings
{
    public static function get_settings_pages() {
        return array(
            new FakeSettingsPage('products', 'Producten', array('' => 'Algemeen', 'inventory' => 'Voorraad', 'downloadable' => 'Downloadbare producten', 'approved_download_directories' => 'Goedgekeurde download folders', 'advanced' => 'Geavanceerd')),
            new FakeSettingsPage('mollie', 'Mollie instellingen', array('' => 'Algemeen')),
        );
    }
}
function rest_do_request($request)
{
    $path = explode('/', trim($request->route, '/'));
    if (count($path) < 3 || $path[0] !== 'wc' || $path[1] !== 'v3' || $path[2] !== 'settings') {
        return new FakeWooResponse(404, array('error' => 'not found'));
    }
    $store = &$GLOBALS['woocommerce_settings_options'];
    if (count($path) === 3) {
        return new FakeWooResponse(200, array(
            array('id' => 'general', 'label' => 'General', 'parent_id' => '', 'sub_groups' => array()),
            array('id' => 'products', 'label' => 'Products', 'parent_id' => '', 'sub_groups' => array('inventory', 'downloadable')),
            array('id' => 'checkout', 'label' => 'Payments', 'parent_id' => '', 'sub_groups' => array()),
        ));
    }
    $group = $path[3];
    if (!isset($store[$group])) return new FakeWooResponse(404, array('error' => 'invalid group'));
    if (count($path) === 4) return new FakeWooResponse(200, array_values($store[$group]));
    $id = $path[4];
    if (!isset($store[$group][$id])) return new FakeWooResponse(404, array('error' => 'invalid setting'));
    if ($request->method === 'PUT') {
        $GLOBALS['woocommerce_settings_put_count']++;
        if (! $GLOBALS['woocommerce_settings_force_fail']) {
            $store[$group][$id]['value'] = $request->params['value'];
        }
    }
    return new FakeWooResponse(200, $store[$group][$id]);
}
$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes/';
foreach (array('Support/Json.php', 'Support/Fingerprint.php', 'Security/Policy.php', 'Runtime/Registry.php', 'Adapters/WooCommerceSettingsAdapter.php') as $path) require $root . $path;

$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
(new \Webactueel\WordPressConnector\Adapters\WooCommerceSettingsAdapter())->register($registry);
$policy = 'Webactueel\\WordPressConnector\\Security\\Policy';
$policy::setPublicRepositoryContext(false);
$update = $registry->descriptor('woocommerce.settings.update');
if (!$update['mutation'] || !$update['privileged'] || $update['capability'] !== 'manage_woocommerce') {
    throw new RuntimeException('WooCommerce settings permissions not registered.');
}
$expectError = static function (callable $action, string $part): void {
    try {
        $action();
    } catch (RuntimeException $error) {
        if (strpos($error->getMessage(), $part) !== false) return;
        throw new RuntimeException('Unexpected error: ' . $error->getMessage());
    }
    throw new RuntimeException('Expected error containing ' . $part);
};
$expectError(static function () use ($update, $policy) {
    $policy::assertActionAllowed($update, false, true);
}, 'lacks the required WordPress capability');
$GLOBALS['woocommerce_settings_user_caps'] = array('manage_woocommerce');
$policy::assertActionAllowed($update, false, true);

$catalog = $registry->execute('woocommerce.settings.catalog', array());
if (count($catalog['groups']) !== 3 || !$catalog['admin_navigation']['available']
    || $catalog['admin_navigation']['tabs'][0]['subtabs'][1]['label'] !== 'Voorraad'
    || $catalog['admin_navigation']['tabs'][1]['provider'] !== 'extension_or_custom') {
    throw new RuntimeException('Settings group/tab/subtab inventory did not reflect provider data.');
}
$general = $registry->execute('woocommerce.settings.inspect', array('group'=>'general', 'offset'=>0, 'limit'=>2));
if ($general['total'] !== 3 || !$general['has_more'] || $general['fields'][0]['mode'] !== 'editable'
    || $general['fields'][1]['mode'] !== 'secret_blocked'
    || isset($general['fields'][1]['value']) || strpos(json_encode($general), 'DONT_EXPOSE') !== false) {
    throw new RuntimeException('Settings pagination, secret isolation or allowlist failed.');
}
$section = $registry->execute('woocommerce.settings.section.inspect', array('tab'=>'products', 'section'=>'inventory'));
if ($section['total'] !== 3 || $section['fields'][1]['id'] !== 'woocommerce_manage_stock'
    || $section['fields'][1]['mode'] !== 'read_only_provider_specific') {
    throw new RuntimeException('WooCommerce product inventory subtab missing from provider inventory.');
}
$defaultSection = $registry->execute('woocommerce.settings.section.inspect', array('tab'=>'products', 'section'=>''));
if ($defaultSection['fields'][1]['mode'] !== 'editable_via_rest'
    || $defaultSection['fields'][1]['value'] !== 'lbs'
    || $defaultSection['fields'][2]['mode'] !== 'secret_blocked'
    || isset($defaultSection['fields'][2]['value'])) {
    throw new RuntimeException('WooCommerce admin subtab readback leaked a secret or missed an editable field.');
}
$expectError(static function () use ($registry) {
    $registry->execute('woocommerce.settings.section.inspect', array('tab'=>'products', 'section'=>'not_a_section'));
}, 'not registered');
$checkout = $registry->execute('woocommerce.settings.inspect', array('group'=>'checkout'));
if ($checkout['fields'][0]['mode'] !== 'secret_blocked' || isset($checkout['fields'][0]['value'])) {
    throw new RuntimeException('Payment credential exposure detected.');
}
$expectError(static function () use ($registry) {
    $registry->execute('woocommerce.settings.inspect', array('group'=>'../../users'));
}, 'canonical ID');
$expectError(static function () use ($registry) {
    $registry->execute('woocommerce.settings.inspect', array('group'=>'general', 'limit'=>200));
}, 'outside its bounds');
$expectError(static function () use ($registry) {
    $registry->execute('woocommerce.settings.inspect', array('group'=>'unknown'));
}, 'not registered');
$expectError(static function () use ($registry) {
    $registry->execute('woocommerce.settings.update', array('group'=>'checkout', 'id'=>'woocommerce_mollie_api_key', 'value'=>'BAD'), array('dry_run'=>true));
}, 'separate guarded provider');
$expectError(static function () use ($registry) {
    $registry->execute('woocommerce.settings.update', array('group'=>'general', 'id'=>'woocommerce_enable_coupons', 'value'=>'no'), array('dry_run'=>true));
}, 'separate guarded provider');
$expectError(static function () use ($registry) {
    $registry->execute('woocommerce.settings.update', array('group'=>'products', 'id'=>'woocommerce_weight_unit', 'value'=>'invalid'), array('dry_run'=>true));
}, 'provider options');

$payload = array('group'=>'products', 'id'=>'woocommerce_weight_unit', 'value'=>'kg');
$dry = $registry->execute('woocommerce.settings.update', $payload, array('dry_run'=>true));
if ($dry['before'] !== 'lbs' || $dry['after'] !== 'kg'
    || $GLOBALS['woocommerce_settings_options']['products']['woocommerce_weight_unit']['value'] !== 'lbs'
    || $GLOBALS['woocommerce_settings_put_count'] !== 0) {
    throw new RuntimeException('WooCommerce settings dry-run mutated state.');
}
$applied = $registry->execute('woocommerce.settings.update', $payload, array('dry_run'=>false));
if ($applied['after'] !== 'kg' || $GLOBALS['woocommerce_settings_put_count'] !== 1
    || $applied['_rollback']['action'] !== 'woocommerce.settings.restore') {
    throw new RuntimeException('WooCommerce provider write/readback/rollback failed.');
}
$expectError(static function () use ($registry,$applied) {
    $registry->execute('woocommerce.settings.restore', $applied['_rollback']['payload'], array('dry_run'=>false));
}, 'rollback-only');
$restored = $registry->execute('woocommerce.settings.restore', $applied['_rollback']['payload'], array('rollback_mode'=>true));
if (!$restored['restored'] || $GLOBALS['woocommerce_settings_options']['products']['woocommerce_weight_unit']['value'] !== 'lbs') {
    throw new RuntimeException('WooCommerce setting rollback failed.');
}
$expectError(static function () use ($registry,$applied) {
    $registry->execute('woocommerce.settings.restore', $applied['_rollback']['payload'], array('rollback_mode'=>true));
}, 'changed since write');

$GLOBALS['woocommerce_settings_force_fail'] = true;
$expectError(static function () use ($registry,$payload) {
    $registry->execute('woocommerce.settings.update', $payload, array('dry_run'=>false));
}, 'previous state restored');
$GLOBALS['woocommerce_settings_force_fail'] = false;
if ($GLOBALS['woocommerce_settings_options']['products']['woocommerce_weight_unit']['value'] !== 'lbs') {
    throw new RuntimeException('Unaccepted WooCommerce write leaked a changed value.');
}
echo "WooCommerce settings group, tab, subtab, redaction, type, permissions, dry-run, write, rollback OK\n";
