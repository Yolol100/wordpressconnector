<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

final class WooCommerceAdapter
{
    public function register(Registry $registry): void
    {
        $registry->register('woocommerce.order.list', array($this, 'orderList'), array('privileged' => true, 'sensitive' => true, 'capability' => 'manage_woocommerce', 'description' => 'List bounded WooCommerce order summaries without billing, shipping or line-item details.'));
        $registry->register('woocommerce.order.get', array($this, 'orderGet'), array('privileged' => true, 'sensitive' => true, 'capability' => 'manage_woocommerce', 'description' => 'Read one WooCommerce order summary; personal data is omitted unless explicitly requested.'));
        $registry->register('woocommerce.customer.get', array($this, 'customerGet'), array('privileged' => true, 'sensitive' => true, 'capability' => 'manage_woocommerce', 'description' => 'Read one WooCommerce customer summary; contact and address data requires an explicit per-customer request.'));
        $registry->register('woocommerce.shipping_zone.list', array($this, 'shippingZoneList'), array('privileged' => true, 'capability' => 'manage_woocommerce', 'description' => 'List WooCommerce shipping zones and geographic locations without shipping method settings.'));
        $registry->register('woocommerce.shipping_zone.get', array($this, 'shippingZoneGet'), array('privileged' => true, 'capability' => 'manage_woocommerce', 'description' => 'Read one WooCommerce shipping zone and geographic locations without shipping method settings.'));
        $registry->register('woocommerce.tax_class.list', array($this, 'taxClassList'), array('privileged' => true, 'capability' => 'manage_woocommerce', 'description' => 'List WooCommerce tax classes.'));
        $registry->register('woocommerce.tax_rate.list', array($this, 'taxRateList'), array('privileged' => true, 'capability' => 'manage_woocommerce', 'description' => 'List bounded WooCommerce tax rates for a registered tax class.'));
        $registry->register('woocommerce.product.list', array($this, 'productList'), array('description' => 'List WooCommerce products through WooCommerce CRUD.'));
        $registry->register('woocommerce.product.get', array($this, 'productGet'), array('description' => 'Read a WooCommerce product.'));
        $registry->register('woocommerce.product.create', array($this, 'productCreate'), array('mutation' => true, 'description' => 'Create a WooCommerce product.'));
        $registry->register('woocommerce.product.update', array($this, 'productUpdate'), array('mutation' => true, 'description' => 'Update a WooCommerce product.'));
        $registry->register('woocommerce.variation.list', array($this, 'variationList'), array('description' => 'List product variations.'));
        $registry->register('woocommerce.variation.get', array($this, 'variationGet'), array('description' => 'Read a product variation.'));
        $registry->register('woocommerce.variation.create', array($this, 'variationCreate'), array('mutation' => true, 'description' => 'Create a product variation.'));
        $registry->register('woocommerce.variation.update', array($this, 'variationUpdate'), array('mutation' => true, 'description' => 'Update a product variation.'));
        $registry->register('woocommerce.attribute.list', array($this, 'attributeList'), array('description' => 'List global WooCommerce product attributes.'));
        $registry->register('woocommerce.attribute.create', array($this, 'attributeCreate'), array('mutation' => true, 'description' => 'Create a global WooCommerce product attribute.'));
        $registry->register('woocommerce.attribute.update', array($this, 'attributeUpdate'), array('mutation' => true, 'description' => 'Update a global WooCommerce product attribute.'));
        $registry->register('woocommerce.attribute.delete', array($this, 'attributeDelete'), array('mutation' => true, 'privileged' => true, 'description' => 'Delete a global WooCommerce product attribute.'));
        $registry->register('woocommerce.coupon.list', array($this, 'couponList'), array('privileged' => true, 'description' => 'List WooCommerce coupons without customer/order data.'));
        $registry->register('woocommerce.coupon.get', array($this, 'couponGet'), array('privileged' => true, 'description' => 'Read a WooCommerce coupon.'));
        $registry->register('woocommerce.coupon.create', array($this, 'couponCreate'), array('mutation' => true, 'privileged' => true, 'description' => 'Create a WooCommerce coupon.'));
        $registry->register('woocommerce.coupon.update', array($this, 'couponUpdate'), array('mutation' => true, 'privileged' => true, 'description' => 'Update a WooCommerce coupon.'));
    }

    public function orderList(array $payload): array
    {
        $this->assertWoo();
        if (! function_exists('wc_get_orders')) throw new RuntimeException('WooCommerce order query API is unavailable.');
        Policy::assertReadablePostType('shop_order');
        $limit = isset($payload['per_page']) ? max(1, min(100, (int) $payload['per_page'])) : 25;
        $page = isset($payload['page']) ? max(1, (int) $payload['page']) : 1;
        $args = array('limit' => $limit, 'page' => $page, 'paginate' => true, 'return' => 'objects');
        if (isset($payload['status'])) {
            $status = sanitize_key((string) $payload['status']);
            if (strpos($status, 'wc-') !== 0) $status = 'wc-' . $status;
            $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
            if (! isset($statuses[$status])) throw new RuntimeException('Unknown WooCommerce order status.');
            $args['status'] = array($status);
        }
        if (isset($payload['customer_id'])) {
            $customerId = (int) $payload['customer_id'];
            if ($customerId < 1) throw new RuntimeException('customer_id must be a positive integer.');
            $args['customer'] = $customerId;
        }
        $query = wc_get_orders($args);
        $orders = is_object($query) && isset($query->orders) ? $query->orders : (array) $query;
        $items = array();
        foreach ($orders as $order) {
            if ($order instanceof \WC_Order) $items[] = $this->orderSnapshot($order, false, false);
        }
        return array(
            'orders' => $items,
            'page' => $page,
            'per_page' => $limit,
            'total' => is_object($query) && isset($query->total) ? (int) $query->total : count($items),
            'pages' => is_object($query) && isset($query->max_num_pages) ? (int) $query->max_num_pages : 1,
        );
    }

    public function orderGet(array $payload): array
    {
        $this->assertWoo();
        if (! function_exists('wc_get_order')) throw new RuntimeException('WooCommerce order API is unavailable.');
        Policy::assertReadablePostType('shop_order');
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        if ($id < 1) throw new RuntimeException('A positive order id is required.');
        $order = wc_get_order($id);
        if (! $order instanceof \WC_Order) throw new RuntimeException('WooCommerce order not found.');
        $includePersonalData = Input::bool($payload, 'include_personal_data', false);
        $snapshot = $this->orderSnapshot($order, $includePersonalData);
        return array('order' => $snapshot, 'fingerprint' => Fingerprint::make($snapshot));
    }

    public function customerGet(array $payload): array
    {
        $this->assertWoo();
        if (! class_exists('WC_Customer')) throw new RuntimeException('WooCommerce customer API is unavailable.');
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $user = $id > 0 ? get_user_by('id', $id) : false;
        if (! $user) throw new RuntimeException('A WooCommerce customer account id is required.');
        $customer = new \WC_Customer($id);
        if (! $customer->get_id()) throw new RuntimeException('WooCommerce customer not found.');
        $role = (string) $customer->get_role();
        $customerRoles = function_exists('apply_filters') ? apply_filters('wpc_connector_woocommerce_customer_roles', array('customer', 'subscriber')) : array('customer', 'subscriber');
        if (! is_array($customerRoles)) $customerRoles = array('customer', 'subscriber');
        $hasOrderHistory = method_exists($customer, 'get_order_count') && (int) $customer->get_order_count() > 0;
        if (! in_array($role, $customerRoles, true) && ! $hasOrderHistory) throw new RuntimeException('A WooCommerce customer account id is required.');
        $snapshot = array(
            'id' => (int) $customer->get_id(),
            'is_paying_customer' => (bool) $customer->get_is_paying_customer(),
            'order_count' => (int) $customer->get_order_count(),
            'total_spent' => (string) $customer->get_total_spent(),
        );
        if (Input::bool($payload, 'include_personal_data', false)) {
            $snapshot['email'] = (string) $customer->get_email();
            $snapshot['first_name'] = (string) $customer->get_first_name();
            $snapshot['last_name'] = (string) $customer->get_last_name();
            $snapshot['billing'] = $this->customerAddressSnapshot($customer->get_billing());
            $snapshot['shipping'] = $this->customerAddressSnapshot($customer->get_shipping());
        }
        return array('customer' => $snapshot, 'fingerprint' => Fingerprint::make($snapshot));
    }

    public function shippingZoneList(array $payload = array()): array
    {
        global $wpdb;
        $this->assertWoo();
        if (! class_exists('WC_Shipping_Zones') || ! method_exists('WC_Shipping_Zones', 'get_zone') || ! isset($wpdb) || ! is_object($wpdb) || ! method_exists($wpdb, 'prepare') || ! method_exists($wpdb, 'get_results') || ! method_exists($wpdb, 'get_var')) throw new RuntimeException('WooCommerce shipping zone API is unavailable.');
        $limit = isset($payload['per_page']) ? max(1, min(50, (int) $payload['per_page'])) : 25;
        $page = isset($payload['page']) ? max(1, min(100000, (int) $payload['page'])) : 1;
        $table = '`' . $wpdb->prefix . 'woocommerce_shipping_zones`';
        $totalValue = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE `zone_id` > %d", 0));
        if (null === $totalValue || false === $totalValue) throw new RuntimeException('WooCommerce shipping zones could not be counted.');
        $total = (int) $totalValue + 1;
        $pages = max(1, (int) ceil($total / $limit));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT `zone_id`, `zone_name`, `zone_order` FROM {$table} WHERE `zone_id` > 0 ORDER BY `zone_order` ASC, `zone_id` ASC LIMIT %d OFFSET %d",
            $limit,
            ($page - 1) * $limit
        ));
        if (! is_array($rows)) throw new RuntimeException('WooCommerce shipping zones could not be read.');
        $zones = array();
        foreach ($rows as $row) {
            if (is_object($row) && isset($row->zone_id, $row->zone_name, $row->zone_order)) {
                $zones[] = $this->shippingZoneSnapshot((int) $row->zone_id, (string) $row->zone_name, (int) $row->zone_order);
            }
        }
        if ($page === $pages && method_exists('WC_Shipping_Zones', 'get_zone')) {
            $defaultZone = \WC_Shipping_Zones::get_zone(0);
            if ($defaultZone instanceof \WC_Shipping_Zone) $zones[] = $this->shippingZoneSnapshot(0, (string) $defaultZone->get_zone_name(), (int) $defaultZone->get_zone_order());
        }
        return array('zones' => $zones, 'page' => $page, 'per_page' => $limit, 'total' => $total, 'pages' => $pages);
    }

    public function shippingZoneGet(array $payload): array
    {
        $this->assertWoo();
        $idValue = $payload['id'] ?? null;
        if (! is_int($idValue) && (! is_string($idValue) || 1 !== preg_match('/^(0|[1-9][0-9]*)$/D', $idValue))) throw new RuntimeException('A valid shipping zone id is required.');
        $validatedId = filter_var((string) $idValue, FILTER_VALIDATE_INT);
        global $wpdb;
        if (false === $validatedId || $validatedId < 0 || ! class_exists('WC_Shipping_Zones') || ! isset($wpdb) || ! is_object($wpdb) || ! method_exists($wpdb, 'prepare') || ! method_exists($wpdb, 'get_results') || ! method_exists($wpdb, 'get_var')) throw new RuntimeException('A valid shipping zone id is required.');
        $id = (int) $validatedId;
        if (0 === $id) {
            $zone = \WC_Shipping_Zones::get_zone(0);
            if (! $zone instanceof \WC_Shipping_Zone) throw new RuntimeException('WooCommerce shipping zone not found.');
            $snapshot = $this->shippingZoneSnapshot(0, (string) $zone->get_zone_name(), (int) $zone->get_zone_order());
        } else {
            $table = '`' . $wpdb->prefix . 'woocommerce_shipping_zones`';
            $rows = $wpdb->get_results($wpdb->prepare("SELECT `zone_id`, `zone_name`, `zone_order` FROM {$table} WHERE `zone_id` = %d LIMIT 1", $id));
            if (! is_array($rows) || empty($rows[0]) || ! is_object($rows[0]) || ! isset($rows[0]->zone_id, $rows[0]->zone_name, $rows[0]->zone_order)) throw new RuntimeException('WooCommerce shipping zone not found.');
            $snapshot = $this->shippingZoneSnapshot((int) $rows[0]->zone_id, (string) $rows[0]->zone_name, (int) $rows[0]->zone_order);
        }
        return array('zone' => $snapshot, 'fingerprint' => Fingerprint::make($snapshot));
    }

    public function taxClassList(): array
    {
        $this->assertWoo();
        if (! class_exists('WC_Tax') || ! method_exists('WC_Tax', 'get_tax_rate_classes')) throw new RuntimeException('WooCommerce tax class API is unavailable.');
        $classes = array();
        foreach (\WC_Tax::get_tax_rate_classes() as $class) {
            if (is_object($class)) {
                $classes[] = array('id' => isset($class->tax_rate_class_id) ? (int) $class->tax_rate_class_id : 0, 'name' => isset($class->name) ? (string) $class->name : '', 'slug' => isset($class->slug) ? (string) $class->slug : '');
            }
        }
        return array('classes' => $classes, 'standard_class' => '');
    }

    public function taxRateList(array $payload): array
    {
        global $wpdb;
        $this->assertWoo();
        if (! class_exists('WC_Tax') || ! method_exists('WC_Tax', 'get_tax_class_slugs') || ! isset($wpdb) || ! is_object($wpdb) || ! method_exists($wpdb, 'prepare') || ! method_exists($wpdb, 'get_results') || ! method_exists($wpdb, 'get_var')) throw new RuntimeException('WooCommerce tax rate API is unavailable.');
        $class = isset($payload['tax_class']) ? sanitize_title((string) $payload['tax_class']) : '';
        $validClasses = array('' => true);
        foreach (\WC_Tax::get_tax_class_slugs() as $slug) $validClasses[(string) $slug] = true;
        if (! isset($validClasses[$class])) throw new RuntimeException('Unknown WooCommerce tax class.');
        $limit = isset($payload['per_page']) ? max(1, min(50, (int) $payload['per_page'])) : 25;
        $page = isset($payload['page']) ? max(1, min(100000, (int) $payload['page'])) : 1;
        $offset = ($page - 1) * $limit;
        $ratesTable = '`' . $wpdb->prefix . 'woocommerce_tax_rates`';
        $locationsTable = '`' . $wpdb->prefix . 'woocommerce_tax_rate_locations`';
        $totalValue = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ratesTable} WHERE `tax_rate_class` = %s", $class));
        if (null === $totalValue || false === $totalValue) throw new RuntimeException('WooCommerce tax rates could not be counted.');
        $total = (int) $totalValue;
        $rateRows = $wpdb->get_results($wpdb->prepare(
            "SELECT `tax_rate_id`, `tax_rate_country`, `tax_rate_state`, `tax_rate`, `tax_rate_name`, `tax_rate_priority`, `tax_rate_compound`, `tax_rate_shipping`, `tax_rate_order` FROM {$ratesTable} WHERE `tax_rate_class` = %s ORDER BY `tax_rate_order` ASC, `tax_rate_id` ASC LIMIT %d OFFSET %d",
            $class,
            $limit,
            $offset
        ));
        if (! is_array($rateRows)) throw new RuntimeException('WooCommerce tax rates could not be read.');
        $rateIds = array();
        foreach ($rateRows as $rateRow) {
            if (is_object($rateRow) && isset($rateRow->tax_rate_id)) $rateIds[] = (int) $rateRow->tax_rate_id;
        }
        $locationCounts = array();
        if ($rateIds) {
            $placeholders = implode(',', array_fill(0, count($rateIds), '%d'));
            $countQuery = $wpdb->prepare(
                "SELECT `tax_rate_id`, COUNT(*) AS `location_count` FROM {$locationsTable} WHERE `tax_rate_id` IN ({$placeholders}) GROUP BY `tax_rate_id`",
                ...array_map('intval', $rateIds)
            );
            $countRows = $wpdb->get_results($countQuery);
            if (! is_array($countRows)) throw new RuntimeException('WooCommerce tax rate locations could not be counted.');
            foreach ($countRows as $countRow) {
                if (is_object($countRow) && isset($countRow->tax_rate_id, $countRow->location_count)) $locationCounts[(int) $countRow->tax_rate_id] = (int) $countRow->location_count;
            }
        }
        $items = array();
        foreach ($rateRows as $rate) {
            $snapshot = $this->taxRateSnapshot($rate);
            $rateId = isset($rate->tax_rate_id) ? (int) $rate->tax_rate_id : 0;
            $locationCount = $locationCounts[$rateId] ?? 0;
            $locations = $locationCount > 0 ? $wpdb->get_results($wpdb->prepare(
                "SELECT `location_type`, `location_code` FROM {$locationsTable} WHERE `tax_rate_id` = %d ORDER BY `location_type` ASC, `location_code` ASC LIMIT %d",
                $rateId,
                100
            )) : array();
            if (! is_array($locations)) throw new RuntimeException('WooCommerce tax rate locations could not be read.');
            $snapshot['locations'] = array();
            foreach ($locations as $location) {
                if (is_object($location) && isset($location->location_type, $location->location_code)) {
                    $snapshot['locations'][] = array('type' => (string) $location->location_type, 'code' => (string) $location->location_code);
                }
            }
            $snapshot['location_count'] = $locationCount;
            $snapshot['locations_truncated'] = $locationCount > count($snapshot['locations']);
            $items[] = $snapshot;
        }
        return array('tax_class' => $class, 'rates' => $items, 'page' => $page, 'per_page' => $limit, 'total' => $total, 'pages' => (int) ceil($total / $limit));
    }

    public function productList(array $payload): array
    {
        $this->assertWoo();
        $limit = isset($payload['per_page']) ? max(1, min(100, (int) $payload['per_page'])) : 50;
        $page = isset($payload['page']) ? max(1, (int) $payload['page']) : 1;
        $args = array(
            'limit' => $limit,
            'page' => $page,
            'paginate' => true,
            'status' => isset($payload['status']) ? $payload['status'] : (Policy::publicRepositoryContext() ? 'publish' : array('publish', 'draft', 'pending', 'private')),
        );
        if (isset($payload['type'])) $args['type'] = sanitize_key((string) $payload['type']);
        if (isset($payload['sku'])) $args['sku'] = (string) $payload['sku'];
        if (isset($payload['search'])) $args['s'] = sanitize_text_field((string) $payload['search']);
        if (isset($payload['category'])) $args['category'] = array_map('intval', (array) $payload['category']);

        $query = wc_get_products($args);
        $products = is_object($query) && isset($query->products) ? $query->products : (array) $query;
        $items = array();
        foreach ($products as $product) {
            if ($product instanceof \WC_Product) $items[] = $this->productSnapshot($product);
        }
        return array(
            'products' => $items,
            'page' => $page,
            'per_page' => $limit,
            'total' => is_object($query) && isset($query->total) ? (int) $query->total : count($items),
            'pages' => is_object($query) && isset($query->max_num_pages) ? (int) $query->max_num_pages : 1,
        );
    }

    public function productGet(array $payload): array
    {
        $product = $this->product($payload);
        $post = get_post($product->get_id());
        if ($post instanceof \WP_Post) {
            Policy::assertPostReadable($post);
        }
        $snapshot = $this->productSnapshot($product);
        return array('product' => $snapshot, 'fingerprint' => Fingerprint::make($snapshot));
    }

    public function productCreate(array $payload, array $context): array
    {
        $this->assertWoo();
        $type = isset($payload['type']) ? sanitize_key((string) $payload['type']) : 'simple';
        $product = $this->newProduct($type);
        $this->applyProduct($product, $payload);

        if (! empty($context['dry_run'])) {
            return array('would_create' => $this->productSnapshot($product), '_current_fingerprint' => Fingerprint::make(array('new' => true, 'type' => $type)));
        }

        $id = $product->save();
        if (! $id) throw new RuntimeException('WooCommerce product save failed.');
        $saved = wc_get_product($id);
        return array('product' => $this->productSnapshot($saved), '_rollback' => array('action' => 'post.trash', 'payload' => array('id' => (int) $id)));
    }

    public function productUpdate(array $payload, array $context): array
    {
        $product = $this->product($payload);
        $before = $this->productSnapshot($product);
        $working = clone $product;
        $this->applyProduct($working, $payload);
        $after = $this->productSnapshot($working);
        $result = array('before' => $before, 'after' => $after, '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) return $result;

        $this->applyProduct($product, $payload);
        $product->save();
        $result['after'] = $this->productSnapshot(wc_get_product($product->get_id()));
        $result['_rollback'] = array('action' => 'woocommerce.product.update', 'payload' => $this->productRollbackPayload($before));
        return $result;
    }

    public function variationList(array $payload): array
    {
        $this->assertWoo();
        $parentId = isset($payload['product_id']) ? (int) $payload['product_id'] : 0;
        $parent = wc_get_product($parentId);
        if (! $parent instanceof \WC_Product_Variable) throw new RuntimeException('Variable product not found.');
        $items = array();
        foreach ($parent->get_children() as $id) {
            $variation = wc_get_product((int) $id);
            if ($variation instanceof \WC_Product_Variation) $items[] = $this->variationSnapshot($variation);
        }
        return array('variations' => $items);
    }

    public function variationGet(array $payload): array
    {
        $variation = $this->variation($payload);
        $snapshot = $this->variationSnapshot($variation);
        return array('variation' => $snapshot, 'fingerprint' => Fingerprint::make($snapshot));
    }

    public function variationCreate(array $payload, array $context): array
    {
        $this->assertWoo();
        $parentId = isset($payload['product_id']) ? (int) $payload['product_id'] : 0;
        $parent = wc_get_product($parentId);
        if (! $parent instanceof \WC_Product_Variable) throw new RuntimeException('Variable parent product not found.');
        $variation = new \WC_Product_Variation();
        $variation->set_parent_id($parentId);
        $this->applyVariation($variation, $payload);
        if (! empty($context['dry_run'])) return array('would_create' => $this->variationSnapshot($variation), '_current_fingerprint' => Fingerprint::make(array('new' => true, 'parent_id' => $parentId)));
        $id = $variation->save();
        if (! $id) throw new RuntimeException('Variation save failed.');
        return array('variation' => $this->variationSnapshot(wc_get_product($id)), '_rollback' => array('action' => 'post.trash', 'payload' => array('id' => (int) $id)));
    }

    public function variationUpdate(array $payload, array $context): array
    {
        $variation = $this->variation($payload);
        $before = $this->variationSnapshot($variation);
        $working = clone $variation;
        $this->applyVariation($working, $payload);
        $result = array('before' => $before, 'after' => $this->variationSnapshot($working), '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) return $result;
        $this->applyVariation($variation, $payload);
        $variation->save();
        $result['after'] = $this->variationSnapshot(wc_get_product($variation->get_id()));
        $result['_rollback'] = array('action' => 'woocommerce.variation.update', 'payload' => $this->variationRollbackPayload($before));
        return $result;
    }

    public function attributeList(): array
    {
        $this->assertWoo();
        $items = array();
        foreach (wc_get_attribute_taxonomies() as $attribute) {
            $items[] = $this->attributeSnapshot($attribute);
        }
        return array('attributes' => $items);
    }

    public function attributeCreate(array $payload, array $context): array
    {
        $this->assertWoo();
        $args = $this->attributeArgs($payload, false);
        if (! empty($context['dry_run'])) return array('would_create' => $args, '_current_fingerprint' => Fingerprint::make(array('new' => true, 'slug' => $args['slug'])));
        $id = wc_create_attribute($args);
        if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
        delete_transient('wc_attribute_taxonomies');
        return array('attribute_id' => (int) $id, '_rollback' => array('action' => 'woocommerce.attribute.delete', 'payload' => array('id' => (int) $id)));
    }

    public function attributeUpdate(array $payload, array $context): array
    {
        $this->assertWoo();
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $beforeObj = $this->findAttribute($id);
        $before = $this->attributeSnapshot($beforeObj);
        $args = $this->attributeArgs($payload, true);
        $result = array('before' => $before, 'changes' => $args, '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) return $result;
        $updated = wc_update_attribute($id, $args);
        if (is_wp_error($updated)) throw new RuntimeException($updated->get_error_message());
        delete_transient('wc_attribute_taxonomies');
        $result['after'] = $this->attributeSnapshot($this->findAttribute($id));
        $result['_rollback'] = array('action' => 'woocommerce.attribute.update', 'payload' => array_merge(array('id' => $id), $before));
        return $result;
    }

    public function attributeDelete(array $payload, array $context): array
    {
        $this->assertWoo();
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $before = $this->attributeSnapshot($this->findAttribute($id));
        $result = array('before' => $before, '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) return $result;
        $deleted = wc_delete_attribute($id);
        if (is_wp_error($deleted)) throw new RuntimeException($deleted->get_error_message());
        delete_transient('wc_attribute_taxonomies');
        $result['_rollback'] = array('action' => 'woocommerce.attribute.create', 'payload' => $before);
        return $result;
    }

    public function couponList(array $payload): array
    {
        $this->assertWoo();
        $query = new \WP_Query(array(
            'post_type' => 'shop_coupon',
            'post_status' => 'any',
            'posts_per_page' => isset($payload['per_page']) ? max(1, min(100, (int) $payload['per_page'])) : 50,
            'paged' => isset($payload['page']) ? max(1, (int) $payload['page']) : 1,
            's' => isset($payload['search']) ? sanitize_text_field((string) $payload['search']) : '',
        ));
        $items = array();
        foreach ($query->posts as $post) {
            $coupon = new \WC_Coupon((int) $post->ID);
            $items[] = $this->couponSnapshot($coupon);
        }
        return array('coupons' => $items, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages);
    }

    public function couponGet(array $payload): array
    {
        $this->assertWoo();
        $coupon = new \WC_Coupon($payload['id'] ?? ($payload['code'] ?? 0));
        if (! $coupon->get_id()) throw new RuntimeException('Coupon not found.');
        $snapshot = $this->couponSnapshot($coupon);
        return array('coupon' => $snapshot, 'fingerprint' => Fingerprint::make($snapshot));
    }

    public function couponCreate(array $payload, array $context): array
    {
        $this->assertWoo();
        $coupon = new \WC_Coupon();
        $this->applyCoupon($coupon, $payload);
        if (! empty($context['dry_run'])) return array('would_create' => $this->couponSnapshot($coupon), '_current_fingerprint' => Fingerprint::make(array('new' => true)));
        $id = $coupon->save();
        return array('coupon' => $this->couponSnapshot(new \WC_Coupon($id)), '_rollback' => array('action' => 'post.trash', 'payload' => array('id' => (int) $id)));
    }

    public function couponUpdate(array $payload, array $context): array
    {
        $this->assertWoo();
        $coupon = new \WC_Coupon($payload['id'] ?? ($payload['code'] ?? 0));
        if (! $coupon->get_id()) throw new RuntimeException('Coupon not found.');
        $before = $this->couponSnapshot($coupon);
        $working = clone $coupon;
        $this->applyCoupon($working, $payload);
        $result = array('before' => $before, 'after' => $this->couponSnapshot($working), '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) return $result;
        $this->applyCoupon($coupon, $payload);
        $coupon->save();
        $result['after'] = $this->couponSnapshot(new \WC_Coupon($coupon->get_id()));
        $result['_rollback'] = array('action' => 'woocommerce.coupon.update', 'payload' => array_merge(array('id' => $coupon->get_id()), $before));
        return $result;
    }

    private function assertWoo(): void
    {
        if (! class_exists('WooCommerce') || ! function_exists('wc_get_product')) {
            throw new RuntimeException('WooCommerce is not active.');
        }
    }

    private function product(array $payload): \WC_Product
    {
        $this->assertWoo();
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $product = wc_get_product($id);
        if (! $product instanceof \WC_Product) throw new RuntimeException('Product not found.');
        return $product;
    }

    private function variation(array $payload): \WC_Product_Variation
    {
        $this->assertWoo();
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $variation = wc_get_product($id);
        if (! $variation instanceof \WC_Product_Variation) throw new RuntimeException('Variation not found.');
        return $variation;
    }

    private function newProduct(string $type): \WC_Product
    {
        switch ($type) {
            case 'variable': return new \WC_Product_Variable();
            case 'grouped': return new \WC_Product_Grouped();
            case 'external': return new \WC_Product_External();
            case 'simple': return new \WC_Product_Simple();
            default:
                $class = wc_get_product_classname(0, $type);
                if (class_exists($class) && is_subclass_of($class, 'WC_Product')) return new $class();
                throw new RuntimeException('Unsupported product type: ' . $type);
        }
    }

    private function applyProduct(\WC_Product $product, array $payload): void
    {
        $setters = array(
            'name' => 'set_name', 'slug' => 'set_slug', 'status' => 'set_status', 'featured' => 'set_featured',
            'catalog_visibility' => 'set_catalog_visibility', 'description' => 'set_description', 'short_description' => 'set_short_description',
            'sku' => 'set_sku', 'regular_price' => 'set_regular_price', 'sale_price' => 'set_sale_price', 'tax_status' => 'set_tax_status',
            'tax_class' => 'set_tax_class', 'manage_stock' => 'set_manage_stock', 'stock_quantity' => 'set_stock_quantity',
            'stock_status' => 'set_stock_status', 'backorders' => 'set_backorders', 'sold_individually' => 'set_sold_individually',
            'weight' => 'set_weight', 'length' => 'set_length', 'width' => 'set_width', 'height' => 'set_height',
            'shipping_class_id' => 'set_shipping_class_id', 'reviews_allowed' => 'set_reviews_allowed', 'purchase_note' => 'set_purchase_note',
            'menu_order' => 'set_menu_order', 'parent_id' => 'set_parent_id', 'image_id' => 'set_image_id', 'virtual' => 'set_virtual',
            'downloadable' => 'set_downloadable', 'download_limit' => 'set_download_limit', 'download_expiry' => 'set_download_expiry',
        );
        foreach ($setters as $key => $method) {
            if (array_key_exists($key, $payload) && method_exists($product, $method)) $product->{$method}($payload[$key]);
        }

        if (array_key_exists('date_on_sale_from', $payload)) $product->set_date_on_sale_from($payload['date_on_sale_from'] ?: null);
        if (array_key_exists('date_on_sale_to', $payload)) $product->set_date_on_sale_to($payload['date_on_sale_to'] ?: null);
        if (isset($payload['category_ids'])) $product->set_category_ids(array_map('intval', (array) $payload['category_ids']));
        if (isset($payload['tag_ids'])) $product->set_tag_ids(array_map('intval', (array) $payload['tag_ids']));
        if (isset($payload['gallery_image_ids'])) $product->set_gallery_image_ids(array_map('intval', (array) $payload['gallery_image_ids']));
        if (isset($payload['upsell_ids'])) $product->set_upsell_ids(array_map('intval', (array) $payload['upsell_ids']));
        if (isset($payload['cross_sell_ids'])) $product->set_cross_sell_ids(array_map('intval', (array) $payload['cross_sell_ids']));
        if (isset($payload['default_attributes']) && method_exists($product, 'set_default_attributes')) $product->set_default_attributes((array) $payload['default_attributes']);
        if (isset($payload['attributes']) && is_array($payload['attributes'])) $product->set_attributes($this->buildAttributes($payload['attributes']));
        if (isset($payload['downloads']) && is_array($payload['downloads'])) $product->set_downloads($this->buildDownloads($payload['downloads']));

        if ($product instanceof \WC_Product_External) {
            if (isset($payload['external_url'])) $product->set_product_url((string) $payload['external_url']);
            if (isset($payload['button_text'])) $product->set_button_text((string) $payload['button_text']);
        }
        if ($product instanceof \WC_Product_Grouped && isset($payload['children'])) $product->set_children(array_map('intval', (array) $payload['children']));
    }

    private function applyVariation(\WC_Product_Variation $variation, array $payload): void
    {
        $setters = array(
            'status' => 'set_status', 'sku' => 'set_sku', 'regular_price' => 'set_regular_price', 'sale_price' => 'set_sale_price',
            'tax_status' => 'set_tax_status', 'tax_class' => 'set_tax_class', 'manage_stock' => 'set_manage_stock',
            'stock_quantity' => 'set_stock_quantity', 'stock_status' => 'set_stock_status', 'backorders' => 'set_backorders',
            'weight' => 'set_weight', 'length' => 'set_length', 'width' => 'set_width', 'height' => 'set_height',
            'image_id' => 'set_image_id', 'virtual' => 'set_virtual', 'downloadable' => 'set_downloadable',
            'download_limit' => 'set_download_limit', 'download_expiry' => 'set_download_expiry', 'description' => 'set_description',
        );
        foreach ($setters as $key => $method) if (array_key_exists($key, $payload)) $variation->{$method}($payload[$key]);
        if (isset($payload['attributes'])) $variation->set_attributes((array) $payload['attributes']);
        if (isset($payload['downloads']) && is_array($payload['downloads'])) $variation->set_downloads($this->buildDownloads($payload['downloads']));
        if (array_key_exists('date_on_sale_from', $payload)) $variation->set_date_on_sale_from($payload['date_on_sale_from'] ?: null);
        if (array_key_exists('date_on_sale_to', $payload)) $variation->set_date_on_sale_to($payload['date_on_sale_to'] ?: null);
    }

    private function orderSnapshot(\WC_Order $order, bool $includePersonalData, bool $includeItems = true): array
    {
        $itemDetails = $includeItems
            ? $this->orderItemsSnapshot((int) $order->get_id())
            : array('items' => array(), 'item_count' => 0, 'items_truncated' => false);
        $snapshot = array(
            'id' => (int) $order->get_id(),
            'status' => (string) $order->get_status(),
            'currency' => (string) $order->get_currency(),
            'total' => (string) $order->get_total(),
            'total_tax' => (string) $order->get_total_tax(),
            'shipping_total' => (string) $order->get_shipping_total(),
            'discount_total' => (string) $order->get_discount_total(),
            'date_created' => $this->dateValue($order->get_date_created()),
            'date_modified' => $this->dateValue($order->get_date_modified()),
            'payment_method' => (string) $order->get_payment_method(),
            'shipping_method' => (string) $order->get_shipping_method(),
        );
        if ($includeItems) {
            $snapshot['items'] = $itemDetails['items'];
            $snapshot['item_count'] = $itemDetails['item_count'];
            $snapshot['items_truncated'] = $itemDetails['items_truncated'];
        }
        if ($includePersonalData) {
            $snapshot['customer_id'] = (int) $order->get_customer_id();
            $snapshot['billing'] = array(
                'first_name' => (string) $order->get_billing_first_name(),
                'last_name' => (string) $order->get_billing_last_name(),
                'company' => (string) $order->get_billing_company(),
                'address_1' => (string) $order->get_billing_address_1(),
                'address_2' => (string) $order->get_billing_address_2(),
                'city' => (string) $order->get_billing_city(),
                'state' => (string) $order->get_billing_state(),
                'postcode' => (string) $order->get_billing_postcode(),
                'country' => (string) $order->get_billing_country(),
                'email' => (string) $order->get_billing_email(),
                'phone' => (string) $order->get_billing_phone(),
            );
            $snapshot['shipping'] = array(
                'first_name' => (string) $order->get_shipping_first_name(),
                'last_name' => (string) $order->get_shipping_last_name(),
                'company' => (string) $order->get_shipping_company(),
                'address_1' => (string) $order->get_shipping_address_1(),
                'address_2' => (string) $order->get_shipping_address_2(),
                'city' => (string) $order->get_shipping_city(),
                'state' => (string) $order->get_shipping_state(),
                'postcode' => (string) $order->get_shipping_postcode(),
                'country' => (string) $order->get_shipping_country(),
            );
        }
        return $snapshot;
    }

    private function orderItemsSnapshot(int $orderId): array
    {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb) || ! method_exists($wpdb, 'prepare') || ! method_exists($wpdb, 'get_results') || ! method_exists($wpdb, 'get_var')) {
            throw new RuntimeException('WooCommerce order item storage is unavailable.');
        }
        $itemsTable = $wpdb->prefix . 'woocommerce_order_items';
        $metaTable = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $totalValue = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$itemsTable} WHERE order_id = %d AND order_item_type = %s",
            $orderId,
            'line_item'
        ));
        if (null === $totalValue || false === $totalValue) throw new RuntimeException('WooCommerce order items could not be counted.');
        $total = (int) $totalValue;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT order_item_id, LEFT(order_item_name, 200) AS order_item_name FROM {$itemsTable} WHERE order_id = %d AND order_item_type = %s ORDER BY order_item_id ASC LIMIT %d",
            $orderId,
            'line_item',
            51
        ));
        if (! is_array($rows)) throw new RuntimeException('WooCommerce order items could not be read.');
        $truncated = $total > 50 || count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $itemIds = array();
        foreach ($rows as $row) {
            if (is_object($row) && isset($row->order_item_id)) $itemIds[] = (int) $row->order_item_id;
        }
        $metaByItem = array();
        if ($itemIds) {
            $idPlaceholders = implode(',', array_fill(0, count($itemIds), '%d'));
            $query = $wpdb->prepare(
                "SELECT itemmeta.order_item_id, itemmeta.meta_key, LEFT(itemmeta.meta_value, 64) AS meta_value
                FROM {$metaTable} AS itemmeta
                INNER JOIN (
                    SELECT order_item_id, meta_key, MAX(meta_id) AS meta_id
                    FROM {$metaTable}
                    WHERE order_item_id IN ({$idPlaceholders})
                      AND meta_key IN ('_product_id', '_variation_id', '_qty', '_line_subtotal', '_line_total')
                    GROUP BY order_item_id, meta_key
                ) AS latest ON latest.meta_id = itemmeta.meta_id
                ORDER BY itemmeta.order_item_id ASC, itemmeta.meta_key ASC",
                ...array_map('intval', $itemIds)
            );
            $metaRows = $wpdb->get_results($query);
            if (! is_array($metaRows)) throw new RuntimeException('WooCommerce order item values could not be read.');
            foreach ($metaRows as $meta) {
                if (is_object($meta) && isset($meta->order_item_id, $meta->meta_key, $meta->meta_value)) {
                    $metaByItem[(int) $meta->order_item_id][(string) $meta->meta_key] = (string) $meta->meta_value;
                }
            }
        }
        $items = array();
        foreach ($rows as $row) {
            if (! is_object($row) || ! isset($row->order_item_id)) continue;
            $meta = $metaByItem[(int) $row->order_item_id] ?? array();
            $items[] = array(
                'product_id' => isset($meta['_product_id']) ? (int) $meta['_product_id'] : 0,
                'variation_id' => isset($meta['_variation_id']) ? (int) $meta['_variation_id'] : 0,
                'name' => isset($row->order_item_name) ? (string) $row->order_item_name : '',
                'quantity' => isset($meta['_qty']) ? (int) $meta['_qty'] : 0,
                'subtotal' => isset($meta['_line_subtotal']) ? (string) $meta['_line_subtotal'] : '0',
                'total' => isset($meta['_line_total']) ? (string) $meta['_line_total'] : '0',
            );
        }
        return array('items' => $items, 'item_count' => $total, 'items_truncated' => $truncated);
    }
    private function customerAddressSnapshot(array $address): array
    {
        $safe = array();
        foreach (array('first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone') as $key) {
            if (isset($address[$key]) && is_scalar($address[$key])) $safe[$key] = (string) $address[$key];
        }
        return $safe;
    }

    private function shippingZoneSnapshot(int $zoneId, string $zoneName, int $zoneOrder): array
    {
        global $wpdb;
        $locations = array();
        $locationCount = 0;
        if ($zoneId > 0 && isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'prepare') && method_exists($wpdb, 'get_results') && method_exists($wpdb, 'get_var')) {
            $table = '`' . $wpdb->prefix . 'woocommerce_shipping_zone_locations`';
            $countValue = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE `zone_id` = %d", $zoneId));
            if (null === $countValue || false === $countValue) throw new RuntimeException('WooCommerce shipping zone locations could not be counted.');
            $locationCount = (int) $countValue;
            if ($locationCount > 0) {
                $locationRows = $wpdb->get_results($wpdb->prepare(
                    "SELECT `location_code`, `location_type` FROM {$table} WHERE `zone_id` = %d ORDER BY `location_type` ASC, `location_code` ASC LIMIT %d",
                    $zoneId,
                    100
                ));
                if (! is_array($locationRows)) throw new RuntimeException('WooCommerce shipping zone locations could not be read.');
                foreach ($locationRows as $location) {
                    if (is_object($location) && isset($location->location_code, $location->location_type)) {
                        $locations[] = array('code' => (string) $location->location_code, 'type' => (string) $location->location_type);
                    }
                }
            }
        }
        return array(
            'id' => $zoneId,
            'name' => $zoneName,
            'order' => $zoneOrder,
            'locations' => $locations,
            'location_count' => $locationCount,
            'locations_truncated' => $locationCount > count($locations),
        );
    }

    private function taxRateSnapshot($rate): array
    {
        $values = is_object($rate) ? get_object_vars($rate) : (is_array($rate) ? $rate : array());
        $fields = array(
            'tax_rate_id' => 'id', 'tax_rate_country' => 'country', 'tax_rate_state' => 'state',
            'tax_rate' => 'rate', 'tax_rate_name' => 'name', 'tax_rate_priority' => 'priority',
            'tax_rate_compound' => 'compound', 'tax_rate_shipping' => 'shipping', 'tax_rate_order' => 'order',
        );
        $snapshot = array();
        foreach ($fields as $source => $target) {
            if (array_key_exists($source, $values) && is_scalar($values[$source])) $snapshot[$target] = $values[$source];
        }
        return $snapshot;
    }

    private function productSnapshot(\WC_Product $product): array
    {
        $data = array(
            'id' => (int) $product->get_id(), 'type' => (string) $product->get_type(), 'name' => (string) $product->get_name(),
            'slug' => (string) $product->get_slug(), 'status' => (string) $product->get_status(), 'featured' => (bool) $product->get_featured(),
            'catalog_visibility' => (string) $product->get_catalog_visibility(), 'description' => (string) $product->get_description(),
            'short_description' => (string) $product->get_short_description(), 'sku' => (string) $product->get_sku(),
            'regular_price' => (string) $product->get_regular_price('edit'), 'sale_price' => (string) $product->get_sale_price('edit'),
            'date_on_sale_from' => $this->dateValue($product->get_date_on_sale_from('edit')), 'date_on_sale_to' => $this->dateValue($product->get_date_on_sale_to('edit')),
            'tax_status' => (string) $product->get_tax_status(), 'tax_class' => (string) $product->get_tax_class(),
            'manage_stock' => (bool) $product->get_manage_stock(), 'stock_quantity' => $product->get_stock_quantity(), 'stock_status' => (string) $product->get_stock_status(),
            'backorders' => (string) $product->get_backorders(), 'sold_individually' => (bool) $product->get_sold_individually(),
            'weight' => (string) $product->get_weight(), 'length' => (string) $product->get_length(), 'width' => (string) $product->get_width(), 'height' => (string) $product->get_height(),
            'shipping_class_id' => (int) $product->get_shipping_class_id(), 'reviews_allowed' => (bool) $product->get_reviews_allowed(),
            'purchase_note' => (string) $product->get_purchase_note(), 'menu_order' => (int) $product->get_menu_order(), 'parent_id' => (int) $product->get_parent_id(),
            'category_ids' => array_map('intval', $product->get_category_ids()), 'tag_ids' => array_map('intval', $product->get_tag_ids()),
            'image_id' => (int) $product->get_image_id(), 'gallery_image_ids' => array_map('intval', $product->get_gallery_image_ids()),
            'upsell_ids' => array_map('intval', $product->get_upsell_ids()), 'cross_sell_ids' => array_map('intval', $product->get_cross_sell_ids()),
            'attributes' => $this->attributesSnapshot($product->get_attributes()), 'default_attributes' => method_exists($product, 'get_default_attributes') ? $product->get_default_attributes() : array(),
            'virtual' => (bool) $product->get_virtual(), 'downloadable' => (bool) $product->get_downloadable(),
            'downloads' => $this->downloadsSnapshot($product->get_downloads()), 'download_limit' => (int) $product->get_download_limit(), 'download_expiry' => (int) $product->get_download_expiry(),
        );
        if ($product instanceof \WC_Product_External) {
            $data['external_url'] = (string) $product->get_product_url();
            $data['button_text'] = (string) $product->get_button_text();
        }
        if ($product instanceof \WC_Product_Grouped) $data['children'] = array_map('intval', $product->get_children());
        return $data;
    }

    private function productRollbackPayload(array $snapshot): array
    {
        return $snapshot;
    }

    private function variationSnapshot(\WC_Product_Variation $variation): array
    {
        return array(
            'id' => (int) $variation->get_id(), 'product_id' => (int) $variation->get_parent_id(), 'status' => (string) $variation->get_status(),
            'sku' => (string) $variation->get_sku(), 'regular_price' => (string) $variation->get_regular_price('edit'), 'sale_price' => (string) $variation->get_sale_price('edit'),
            'date_on_sale_from' => $this->dateValue($variation->get_date_on_sale_from('edit')), 'date_on_sale_to' => $this->dateValue($variation->get_date_on_sale_to('edit')),
            'tax_status' => (string) $variation->get_tax_status(), 'tax_class' => (string) $variation->get_tax_class(), 'manage_stock' => (bool) $variation->get_manage_stock(),
            'stock_quantity' => $variation->get_stock_quantity(), 'stock_status' => (string) $variation->get_stock_status(), 'backorders' => (string) $variation->get_backorders(),
            'weight' => (string) $variation->get_weight(), 'length' => (string) $variation->get_length(), 'width' => (string) $variation->get_width(), 'height' => (string) $variation->get_height(),
            'image_id' => (int) $variation->get_image_id(), 'attributes' => $variation->get_attributes(), 'virtual' => (bool) $variation->get_virtual(),
            'downloadable' => (bool) $variation->get_downloadable(), 'downloads' => $this->downloadsSnapshot($variation->get_downloads()),
            'download_limit' => (int) $variation->get_download_limit(), 'download_expiry' => (int) $variation->get_download_expiry(), 'description' => (string) $variation->get_description(),
        );
    }

    private function variationRollbackPayload(array $snapshot): array { return $snapshot; }

    private function buildAttributes(array $input): array
    {
        $attributes = array();
        foreach ($input as $item) {
            if (! is_array($item)) continue;
            $attribute = new \WC_Product_Attribute();
            $attribute->set_id(isset($item['id']) ? (int) $item['id'] : 0);
            $attribute->set_name(isset($item['name']) ? (string) $item['name'] : '');
            $attribute->set_options(isset($item['options']) ? (array) $item['options'] : array());
            $attribute->set_position(isset($item['position']) ? (int) $item['position'] : 0);
            $attribute->set_visible(isset($item['visible']) ? (bool) $item['visible'] : true);
            $attribute->set_variation(isset($item['variation']) ? (bool) $item['variation'] : false);
            $attributes[] = $attribute;
        }
        return $attributes;
    }

    private function attributesSnapshot(array $attributes): array
    {
        $items = array();
        foreach ($attributes as $attribute) {
            if ($attribute instanceof \WC_Product_Attribute) {
                $items[] = array('id' => (int) $attribute->get_id(), 'name' => (string) $attribute->get_name(), 'options' => array_values($attribute->get_options()), 'position' => (int) $attribute->get_position(), 'visible' => (bool) $attribute->get_visible(), 'variation' => (bool) $attribute->get_variation());
            }
        }
        return $items;
    }

    private function buildDownloads(array $input): array
    {
        $downloads = array();
        foreach ($input as $item) {
            if (! is_array($item) || empty($item['file'])) continue;
            $download = new \WC_Product_Download();
            if (! empty($item['id'])) $download->set_id((string) $item['id']);
            $download->set_name(isset($item['name']) ? (string) $item['name'] : basename((string) $item['file']));
            $download->set_file((string) $item['file']);
            $downloads[$download->get_id()] = $download;
        }
        return $downloads;
    }

    private function downloadsSnapshot(array $downloads): array
    {
        $items = array();
        foreach ($downloads as $download) {
            if ($download instanceof \WC_Product_Download) $items[] = array('id' => (string) $download->get_id(), 'name' => (string) $download->get_name(), 'file' => (string) $download->get_file());
        }
        return $items;
    }

    private function attributeArgs(array $payload, bool $partial): array
    {
        $args = array();
        if (isset($payload['name'])) $args['name'] = sanitize_text_field((string) $payload['name']);
        if (isset($payload['slug'])) $args['slug'] = wc_sanitize_taxonomy_name((string) $payload['slug']);
        if (isset($payload['type'])) $args['type'] = sanitize_key((string) $payload['type']);
        if (isset($payload['order_by'])) $args['order_by'] = sanitize_key((string) $payload['order_by']);
        if (array_key_exists('has_archives', $payload)) $args['has_archives'] = Input::bool($payload, 'has_archives');
        if (! $partial && empty($args['name'])) throw new RuntimeException('Attribute name is required.');
        return $args;
    }

    private function findAttribute(int $id)
    {
        foreach (wc_get_attribute_taxonomies() as $attribute) if ((int) $attribute->attribute_id === $id) return $attribute;
        throw new RuntimeException('Attribute not found.');
    }

    private function attributeSnapshot($attribute): array
    {
        return array(
            'id' => (int) $attribute->attribute_id,
            'name' => (string) $attribute->attribute_label,
            'slug' => (string) $attribute->attribute_name,
            'type' => (string) $attribute->attribute_type,
            'order_by' => (string) $attribute->attribute_orderby,
            'has_archives' => (bool) $attribute->attribute_public,
            'taxonomy' => wc_attribute_taxonomy_name((string) $attribute->attribute_name),
        );
    }

    private function applyCoupon(\WC_Coupon $coupon, array $payload): void
    {
        $setters = array(
            'code' => 'set_code', 'description' => 'set_description', 'discount_type' => 'set_discount_type', 'amount' => 'set_amount',
            'individual_use' => 'set_individual_use', 'usage_limit' => 'set_usage_limit', 'usage_limit_per_user' => 'set_usage_limit_per_user',
            'limit_usage_to_x_items' => 'set_limit_usage_to_x_items', 'free_shipping' => 'set_free_shipping', 'exclude_sale_items' => 'set_exclude_sale_items',
            'minimum_amount' => 'set_minimum_amount', 'maximum_amount' => 'set_maximum_amount',
        );
        foreach ($setters as $key => $method) if (array_key_exists($key, $payload)) $coupon->{$method}($payload[$key]);
        if (isset($payload['product_ids'])) $coupon->set_product_ids(array_map('intval', (array) $payload['product_ids']));
        if (isset($payload['excluded_product_ids'])) $coupon->set_excluded_product_ids(array_map('intval', (array) $payload['excluded_product_ids']));
        if (isset($payload['product_categories'])) $coupon->set_product_categories(array_map('intval', (array) $payload['product_categories']));
        if (isset($payload['excluded_product_categories'])) $coupon->set_excluded_product_categories(array_map('intval', (array) $payload['excluded_product_categories']));
        if (isset($payload['email_restrictions'])) $coupon->set_email_restrictions(array_map('sanitize_email', (array) $payload['email_restrictions']));
        if (array_key_exists('date_expires', $payload)) $coupon->set_date_expires($payload['date_expires'] ?: null);
    }

    private function couponSnapshot(\WC_Coupon $coupon): array
    {
        return array(
            'id' => (int) $coupon->get_id(), 'code' => (string) $coupon->get_code(), 'description' => (string) $coupon->get_description(),
            'discount_type' => (string) $coupon->get_discount_type(), 'amount' => (string) $coupon->get_amount(), 'date_expires' => $this->dateValue($coupon->get_date_expires()),
            'individual_use' => (bool) $coupon->get_individual_use(), 'product_ids' => array_map('intval', $coupon->get_product_ids()),
            'excluded_product_ids' => array_map('intval', $coupon->get_excluded_product_ids()), 'usage_limit' => $coupon->get_usage_limit(),
            'usage_limit_per_user' => $coupon->get_usage_limit_per_user(), 'limit_usage_to_x_items' => $coupon->get_limit_usage_to_x_items(),
            'free_shipping' => (bool) $coupon->get_free_shipping(), 'product_categories' => array_map('intval', $coupon->get_product_categories()),
            'excluded_product_categories' => array_map('intval', $coupon->get_excluded_product_categories()), 'exclude_sale_items' => (bool) $coupon->get_exclude_sale_items(),
            'minimum_amount' => (string) $coupon->get_minimum_amount(), 'maximum_amount' => (string) $coupon->get_maximum_amount(),
            'email_restrictions' => $coupon->get_email_restrictions(),
        );
    }

    private function dateValue($date): ?string
    {
        return $date instanceof \WC_DateTime ? $date->date('c') : null;
    }
}
