<?php

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function ($severity, $message): void {
    throw new ErrorException($message, 0, $severity);
});

// Minimal WooCommerce provider double for the actual production adapter.
// The repository's separate MySQL/WordPress matrix provides full runtime proof.
class WooCommerce {}
class WC_Product {}
class WC_Product_Variable extends WC_Product {
    public function get_children(): array { return array(); }
}
class WC_Product_Variation extends WC_Product
{
    private $id = 55;
    private $parentId = 11;

    public function set_parent_id($id): void { $this->parentId = (int) $id; }
    public function get_parent_id(): int { return $this->parentId; }
    public function get_id(): int { return $this->id; }
    public function save(): int {
        $GLOBALS['variation_saved'] = true;
        return $this->id;
    }
    public function __call($method, $args) {
        if ('get_attributes' === $method || 'get_downloads' === $method) {
            return array();
        }
        return null;
    }
}

$GLOBALS['variation_saved'] = false;
$GLOBALS['readback_mode'] = 'success';
$GLOBALS['woo_id_lookups'] = array();
function wc_get_product($id)
{
    $GLOBALS['woo_id_lookups'][] = $id;
    if (11 === (int) $id) {
        return new WC_Product_Variable();
    }
    if (55 === (int) $id) {
        if (! empty($GLOBALS['variation_saved'])) {
            if ('missing' === $GLOBALS['readback_mode']) {
                return false;
            }
            if ('wrong-type' === $GLOBALS['readback_mode']) {
                return new WC_Product_Variable();
            }
        }
        return new WC_Product_Variation();
    }
    return false;
}

$root = dirname(__DIR__) . '/plugin/wordpressconnector';
require_once $root . '/includes/Support/class-json.php';
require_once $root . '/includes/Support/class-fingerprint.php';
require_once $root . '/includes/Support/class-input.php';
require_once $root . '/includes/Adapters/class-woocommerceadapter.php';
$adapter = new \Webactueel\WordPressConnector\Adapters\WooCommerceAdapter();

foreach (array('missing', 'wrong-type') as $mode) {
    foreach (array('create', 'update') as $action) {
        $GLOBALS['readback_mode'] = $mode;
        $GLOBALS['variation_saved'] = false;
        try {
            if ('create' === $action) {
                $adapter->variationCreate(array('product_id' => 11), array('dry_run' => false));
            } else {
                $adapter->variationUpdate(array('id' => 55), array('dry_run' => false));
            }
            throw new RuntimeException("{$action} accepted an invalid {$mode} WooCommerce readback.");
        } catch (RuntimeException $error) {
            $expected = 'create' === $action
                ? 'Saved WooCommerce variation could not be read back.'
                : 'Updated WooCommerce variation could not be read back.';
            if ($error->getMessage() !== $expected) {
                throw $error;
            }
        }
    }
}
foreach (array('create', 'update') as $action) {
    $GLOBALS['readback_mode'] = 'success';
    $GLOBALS['variation_saved'] = false;
    $result = 'create' === $action
        ? $adapter->variationCreate(array('product_id' => 11), array('dry_run' => false))
        : $adapter->variationUpdate(array('id' => 55), array('dry_run' => false));
    $after = 'create' === $action ? ($result['variation'] ?? null) : ($result['after'] ?? null);
    if (55 !== ($after['id'] ?? null) || ! isset($result['_rollback']['action'])) {
        throw new RuntimeException("{$action} successful readback/rollback was not preserved.");
    }
}

// Every public object target must resolve an exact positive ID before WooCommerce
// is queried. Coerced IDs could otherwise select the wrong product/variation.
$productResolver = new ReflectionMethod($adapter, 'product');
$variationResolver = new ReflectionMethod($adapter, 'variation');
if (PHP_VERSION_ID < 80100) {
    $productResolver->setAccessible(true);
    $variationResolver->setAccessible(true);
}
$invalidIds = array(true, false, 11.5, -11, 0, '0', '11junk',
    '1.1e1', ' 11', "11\\n", '999999999999999999999999', array(11), null);
foreach ($invalidIds as $invalidId) {
    $cases = array(
        array('label' => 'product', 'run' => static function () use ($productResolver, $adapter, $invalidId) {
            $productResolver->invoke($adapter, array('id' => $invalidId));
        }, 'error' => 'Product not found.'),
        array('label' => 'variation', 'run' => static function () use ($variationResolver, $adapter, $invalidId) {
            $variationResolver->invoke($adapter, array('id' => $invalidId));
        }, 'error' => 'Variation not found.'),
        array('label' => 'variation.list', 'run' => static function () use ($adapter, $invalidId) {
            $adapter->variationList(array('product_id' => $invalidId));
        }, 'error' => 'Variable product not found.'),
        array('label' => 'variation.create', 'run' => static function () use ($adapter, $invalidId) {
            $adapter->variationCreate(array('product_id' => $invalidId), array('dry_run' => true));
        }, 'error' => 'Variable parent product not found.'),
    );
    foreach ($cases as $case) {
        $beforeLookups = count($GLOBALS['woo_id_lookups']);
        $beforeSaved = $GLOBALS['variation_saved'];
        try {
            $case['run']();
            throw new RuntimeException('Malformed ID accepted for ' . $case['label']);
        } catch (RuntimeException $error) {
            if ($case['error'] !== $error->getMessage()) {
                throw $error;
            }
        }
        if (count($GLOBALS['woo_id_lookups']) !== $beforeLookups
            || $GLOBALS['variation_saved'] !== $beforeSaved) {
            throw new RuntimeException('Rejected ID reached WooCommerce: ' . $case['label']);
        }
    }
}
$GLOBALS['variation_saved'] = false;
$GLOBALS['readback_mode'] = 'success';
foreach (array(11, '11', '000011') as $id) {
    $resolved = $productResolver->invoke($adapter, array('id' => $id));
    if (! $resolved instanceof WC_Product_Variable) {
        throw new RuntimeException('Valid product ID compatibility regressed.');
    }
    $list = $adapter->variationList(array('product_id' => $id));
    if (! isset($list['variations']) || ! is_array($list['variations'])) {
        throw new RuntimeException('Valid variation list contract changed.');
    }
}
foreach (array(55, '55', '000055') as $id) {
    $resolved = $variationResolver->invoke($adapter, array('id' => $id));
    if (! $resolved instanceof WC_Product_Variation) {
        throw new RuntimeException('Valid variation ID compatibility regressed.');
    }
}
$canonicalPreview = $adapter->variationCreate(
    array('product_id' => '000011'),
    array('dry_run' => true)
);
if (11 !== ($canonicalPreview['would_create']['product_id'] ?? null)
    || $GLOBALS['variation_saved'] !== false) {
    throw new RuntimeException('Valid variation creation preview or parent ID changed.');
}
echo "WooCommerce variation CRUD readback: missing, wrong type, success, rollback OK\n";
