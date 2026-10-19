<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support {
    final class Fingerprint {
        public static function make($value): string { return hash('sha256', json_encode($value)); }
    }
}

namespace {
    class WooCommerce {}
    class WC_Product_Variable {}
    class WC_Product_Variation {
        private $id;
        private $parentId = 0;
        public function __construct(int $id = 0) { $this->id = $id; }
        public function set_parent_id(int $parentId): void { $this->parentId = $parentId; }
        public function get_parent_id(): int { return $this->parentId; }
        public function get_id(): int { return $this->id; }
        public function save(): int { $this->id = 100; return 100; }
        public function __call(string $method, array $args) {
            if ('get_attributes' === $method || 'get_downloads' === $method) return array();
            if ('get_stock_quantity' === $method) return null;
            if ('get_date_on_sale_from' === $method || 'get_date_on_sale_to' === $method) return null;
            return '';
        }
    }

    $GLOBALS['wpcVariationReadbackMode'] = 'good';
    $GLOBALS['wpcVariationReadCalls'] = 0;
    function wc_get_product(int $id) {
        if (20 === $id) return new WC_Product_Variable();
        if (100 === $id) {
            ++$GLOBALS['wpcVariationReadCalls'];
            if ('missing' === $GLOBALS['wpcVariationReadbackMode']) return false;
            if ('wrong-type' === $GLOBALS['wpcVariationReadbackMode']) return new WC_Product_Variable();
            if ('second-missing' === $GLOBALS['wpcVariationReadbackMode'] && $GLOBALS['wpcVariationReadCalls'] > 1) return false;
            return new WC_Product_Variation(100);
        }
        return false;
    }

    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/WooCommerceAdapter.php';
    $subject = new \Webactueel\WordPressConnector\Adapters\WooCommerceAdapter();

    $create = $subject->variationCreate(array('product_id' => 20), array('dry_run' => false));
    if (100 !== ($create['variation']['id'] ?? null) || 'post.trash' !== ($create['_rollback']['action'] ?? null)) {
        throw new \RuntimeException('Variation create successful path changed.');
    }

    foreach (array('missing', 'wrong-type') as $mode) {
        $GLOBALS['wpcVariationReadbackMode'] = $mode;
        $GLOBALS['wpcVariationReadCalls'] = 0;
        try {
            $subject->variationCreate(array('product_id' => 20), array());
        } catch (\RuntimeException $e) {
            if (false === strpos($e->getMessage(), 'could not be verified')) {
                throw new \RuntimeException('Create readback failure message changed.');
            }
            continue;
        }
        throw new \RuntimeException('Create returned success with unverified variation.');
    }

    $GLOBALS['wpcVariationReadbackMode'] = 'good';
    $GLOBALS['wpcVariationReadCalls'] = 0;
    $update = $subject->variationUpdate(array('id' => 100), array('dry_run' => false));
    if (100 !== ($update['after']['id'] ?? null)
        || 'woocommerce.variation.update' !== ($update['_rollback']['action'] ?? null)) {
        throw new \RuntimeException('Variation update successful path changed.');
    }

    $GLOBALS['wpcVariationReadbackMode'] = 'second-missing';
    $GLOBALS['wpcVariationReadCalls'] = 0;
    try {
        $subject->variationUpdate(array('id' => 100), array('dry_run' => false));
    } catch (\RuntimeException $e) {
        if (false === strpos($e->getMessage(), 'could not be verified')) {
            throw new \RuntimeException('Update readback failure message changed.');
        }
        echo "WooCommerce variation readback negative/positive contract OK\n";
        exit(0);
    }
    throw new \RuntimeException('Update returned success with unverified variation.');
}
