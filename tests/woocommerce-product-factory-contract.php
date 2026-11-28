<?php

declare(strict_types=1);

use Webactueel\WordPressConnector\Adapters\WooCommerceAdapter;

class WC_Product
{
    public function get_type(): string { return 'base'; }
}
class WC_Product_Simple extends WC_Product
{
    public function get_type(): string { return 'simple'; }
}
class WC_Product_Variable extends WC_Product
{
    public function get_type(): string { return 'variable'; }
}
class WC_Product_Grouped extends WC_Product
{
    public function get_type(): string { return 'grouped'; }
}
class WC_Product_External extends WC_Product
{
    public function get_type(): string { return 'external'; }
}
class WC_Product_Variation extends WC_Product
{
    public function get_type(): string { return 'variation'; }
}
final class WPC_Custom_Variation extends WC_Product_Variation
{
    public function get_type(): string { return 'wpc-custom-variation'; }
}
final class WPC_Custom_Product extends WC_Product_Simple
{
    public function get_type(): string { return 'wpc-custom'; }
}
final class WC_Product_Factory
{
    public static $calls = array();

    public static function get_product_classname(int $id, string $type): string
    {
        self::$calls[] = array($id, $type);
        return array(
            'wpc-custom' => WPC_Custom_Product::class,
            'wpc-wrong' => WC_Product_Variable::class,
            'wpc-base' => WC_Product::class,
            'wpc-not-product' => stdClass::class,
            'variation' => WC_Product_Variation::class,
            'wpc-custom-variation' => WPC_Custom_Variation::class,
        )[$type] ?? WC_Product_Simple::class;
    }
}

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/WooCommerceAdapter.php';

$adapter = new WooCommerceAdapter();
$method = new ReflectionMethod($adapter, 'new_product');
$method->setAccessible(true);

foreach (array('simple', 'variable', 'grouped', 'external') as $type) {
    $product = $method->invoke($adapter, $type);
    if (!$product instanceof WC_Product || $type !== $product->get_type()) {
        throw new RuntimeException('Built-in WooCommerce product construction changed.');
    }
}
if (array() !== WC_Product_Factory::$calls) {
    throw new RuntimeException('Built-in products must retain their existing constructors.');
}
if (!$method->invoke($adapter, 'wpc-custom') instanceof WPC_Custom_Product) {
    throw new RuntimeException('Registered custom product type was not resolved by the factory.');
}
foreach (array('wpc-unknown', 'wpc-wrong', 'wpc-base', 'wpc-not-product', 'variation', 'wpc-custom-variation') as $type) {
    $rejected = false;
    try {
        $method->invoke($adapter, $type);
    } catch (RuntimeException $error) {
        if ('Unsupported product type.' !== $error->getMessage()) {
            throw $error;
        }
        $rejected = true;
    }
    if (!$rejected) {
        throw new RuntimeException('Invalid or mismatched product class was accepted.');
    }
}
if (array(
    array(0, 'wpc-custom'), array(0, 'wpc-unknown'), array(0, 'wpc-wrong'),
    array(0, 'wpc-base'), array(0, 'wpc-not-product'), array(0, 'wpc-custom-variation'),
) !== WC_Product_Factory::$calls) {
    throw new RuntimeException('Custom product factory calls changed.');
}
echo "WooCommerce product factory contract OK\n";
