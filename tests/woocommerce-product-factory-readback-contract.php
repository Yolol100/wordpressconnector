<?php
declare(strict_types=1);

// Load the real product adapter with minimal provider doubles to check failure paths.
class WooCommerce {}
class WC_Product
{
    protected $id;
    public function __construct(int $id = 55) { $this->id = $id; }
    public function get_id(): int { return $this->id; }
    public function get_type(): string { return 'simple'; }
    public function save(): int { return $this->id; }
    public function __call($name, $arguments)
    {
        if (in_array($name, array(
            'get_category_ids', 'get_tag_ids', 'get_gallery_image_ids', 'get_upsell_ids',
            'get_cross_sell_ids', 'get_attributes', 'get_downloads', 'get_default_attributes',
        ), true)) {
            return array();
        }
        if (in_array($name, array('get_date_on_sale_from', 'get_date_on_sale_to'), true)) {
            return null;
        }
        return '';
    }
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Custom extends WC_Product
{
    public function get_type(): string { return 'custom'; }
}
class WC_Product_Factory
{
    public static function get_product_classname($id, $type): string
    {
        // WooCommerce falls back to simple when no type class is registered.
        return 'custom' === $type ? WC_Product_Custom::class : WC_Product_Simple::class;
    }
}
function wc_get_product_types(): array
{
    return array('simple' => 'Simple', 'custom' => 'Custom', 'phantom' => 'Phantom');
}
function sanitize_key($value): string
{
    return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value));
}
$GLOBALS['product_readback_mode'] = 'success';
$GLOBALS['product_readback_calls'] = 0;
function wc_get_product($id)
{
    ++$GLOBALS['product_readback_calls'];
    $mode = $GLOBALS['product_readback_mode'];
    if (0 === strpos($mode, 'update-') && 1 === $GLOBALS['product_readback_calls']) {
        return new WC_Product_Custom(55);
    }
    if ('create-missing' === $mode || 'update-missing' === $mode) {
        return false;
    }
    if ('create-wrong-type' === $mode || 'update-wrong-type' === $mode) {
        return new stdClass();
    }
    return new WC_Product_Custom(55);
}

$root = dirname(__DIR__) . '/plugin/wordpressconnector';
require_once $root . '/includes/Support/Json.php';
require_once $root . '/includes/Support/Fingerprint.php';
require_once $root . '/includes/Adapters/WooCommerceAdapter.php';

$adapter = new \Webactueel\WordPressConnector\Adapters\WooCommerceAdapter();
$method = new ReflectionMethod($adapter, 'newProduct');
$method->setAccessible(true);
$custom = $method->invoke($adapter, 'custom');
if (! $custom instanceof WC_Product_Custom) {
    throw new RuntimeException('Registered custom WooCommerce type cannot be created.');
}
foreach (array('phantom', 'unregistered') as $type) {
    try {
        $method->invoke($adapter, $type);
        throw new RuntimeException('Unknown WooCommerce type silently became a simple product.');
    } catch (RuntimeException $expected) {
        if ('Unsupported product type: ' . $type !== $expected->getMessage()) {
            throw $expected;
        }
    }
}
foreach (array('create', 'update') as $operation) {
    foreach (array('missing', 'wrong-type') as $failure) {
        $GLOBALS['product_readback_mode'] = $operation . '-' . $failure;
        $GLOBALS['product_readback_calls'] = 0;
        try {
            if ('create' === $operation) {
                $adapter->productCreate(array('type' => 'custom'), array('dry_run' => false));
            } else {
                $adapter->productUpdate(array('id' => 55), array('dry_run' => false));
            }
            throw new RuntimeException('Invalid product readback was accepted.');
        } catch (RuntimeException $expected) {
            $message = 'create' === $operation
                ? 'Saved WooCommerce product could not be read back.'
                : 'Updated WooCommerce product could not be read back.';
            if ($expected->getMessage() !== $message) {
                throw $expected;
            }
        }
    }

    $GLOBALS['product_readback_mode'] = 'success';
    $GLOBALS['product_readback_calls'] = 0;
    $result = 'create' === $operation
        ? $adapter->productCreate(array('type' => 'custom'), array('dry_run' => false))
        : $adapter->productUpdate(array('id' => 55), array('dry_run' => false));
    $after = 'create' === $operation ? ($result['product'] ?? null) : ($result['after'] ?? null);
    if (55 !== ($after['id'] ?? null) || 'custom' !== ($after['type'] ?? null) ||
        ! is_array($result['_rollback'] ?? null)) {
        throw new RuntimeException('Product write lost a successful readback or rollback contract.');
    }
}
echo "WooCommerce product factory, negative readback and rollback OK\n";
