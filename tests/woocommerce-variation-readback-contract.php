<?php

declare(strict_types=1);

error_reporting(E_ALL);
set_error_handler(static function ($severity, $message): void {
    throw new ErrorException($message, 0, $severity);
});

// Minimal WooCommerce provider double for the actual production adapter.
// The repository's separate MySQL/WordPress matrix provides full runtime proof.
class WooCommerce {}
class WC_Product_Variable {}
class WC_Product_Variation
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
function wc_get_product($id)
{
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
require_once $root . '/includes/Support/Json.php';
require_once $root . '/includes/Support/Fingerprint.php';
require_once $root . '/includes/Adapters/WooCommerceAdapter.php';
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

echo "WooCommerce variation CRUD readback: missing, wrong type, success, rollback OK\n";
