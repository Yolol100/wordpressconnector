<?php

// Executed by WP-CLI against an ephemeral CI WordPress + WooCommerce + Code Snippets installation.
use Webactueel\WordPressConnector\Adapters\CodeSnippetsAdapter;

if (! defined('ABSPATH') || ! class_exists('WooCommerce') ||
    ! function_exists('\\Code_Snippets\\get_snippets') || ! current_user_can('manage_options')) {
    throw new RuntimeException('WooCommerce, Code Snippets and administrator permission are required.');
}
$adapter = new CodeSnippetsAdapter();
$mode = getenv('WPC_CATALOG_RUNTIME_MODE') ?: 'none';
if ('create' === $mode) {
    $before = $adapter->catalogOnlyInspect(array());
    if ($before['snippet']['present']) {
        throw new RuntimeException('Runtime test expects a fresh, unmodified snippet provider.');
    }
    $dry = $adapter->catalogOnlyEnsure(array(), array('dry_run' => true));
    if (! $dry['would_create']) {
        throw new RuntimeException('Creation dry-run was not offered.');
    }
    $result = $adapter->catalogOnlyEnsure(array(), array('dry_run' => false));
    if (! $result['readback_verified'] || ! $result['snippet']['active'] ||
        $result['snippet']['type'] !== 'php' || $result['snippet']['scope'] !== 'global') {
        throw new RuntimeException('Code Snippets provider did not activate the managed preset.');
    }
    echo "Code Snippets runtime create OK\n";
} elseif ('verify' === $mode) {
    $state = $adapter->catalogOnlyInspect(array());
    if (! $state['snippet']['present'] || ! $state['snippet']['active']) {
        throw new RuntimeException('Created snippet was not active on independent WordPress boot.');
    }
    if (true === apply_filters('woocommerce_is_purchasable', true, null) ||
        true === apply_filters('woocommerce_variation_is_purchasable', true, null) ||
        true === apply_filters('woocommerce_add_to_cart_validation', true, 1, 1)) {
        throw new RuntimeException('Product/cart purchase prevention filter was not effective.');
    }
    // Cover every documented alternate Store API order/cart entry, not only add-item.
    foreach (array(
        array('POST', '/wc/store/v1/cart/add-item'),
        array('POST', '/wc/store/v1/cart/items'),
        array('PUT', '/wc/store/v1/cart/items/example-key'),
        array('DELETE', '/wc/store/v1/cart/items/example-key'),
        array('POST', '/wc/store/v1/cart/update-item'),
        array('POST', '/wc/store/v1/batch'),
        array('POST', '/wc/store/cart/add-item'),
        array('GET', '/wc/store/v1/checkout'),
        array('HEAD', '/wc/store/v1/checkout'),
        array('POST', '/wc/store/v1/checkout'),
        array('PUT', '/wc/store/v1/checkout'),
        array('POST', '/wc/store/v1/checkout/123'),
    ) as $case) {
        $blocked = apply_filters('rest_pre_dispatch', null, rest_get_server(),
            new WP_REST_Request($case[0], $case[1]));
        if (! is_wp_error($blocked) || 403 !== (int) $blocked->get_error_data()['status']) {
            throw new RuntimeException('WooCommerce Store API route was not blocked: ' . implode(' ', $case));
        }
    }
    foreach (array(
        array('GET', '/wc/store/v1/products'),
        array('GET', '/wc/store/v1/cart'),
        array('GET', '/wc/store/v1/cart/items'),
        array('OPTIONS', '/wc/store/v1/checkout'),
    ) as $case) {
        if (null !== apply_filters('rest_pre_dispatch', null, rest_get_server(),
            new WP_REST_Request($case[0], $case[1]))) {
            throw new RuntimeException('Safe Store API route was unexpectedly blocked: ' . implode(' ', $case));
        }
    }
    if ('<span>€100</span>' !== apply_filters('woocommerce_get_price_html', '<span>€100</span>', null) ||
        false === strpos(apply_filters('woocommerce_get_price_html', '', null), 'Price on request')) {
        throw new RuntimeException('Existing/display fallback prices did not remain visible.');
    }
    echo "Code Snippets runtime checkout/cart/price verification OK\n";
} elseif ('undo' === $mode) {
    $state = $adapter->catalogOnlyInspect(array());
    $undone = $adapter->catalogOnlyUndo(array(
        'snippet_id' => $state['snippet']['id'],
        'expected_code_sha256' => $state['source_sha256'],
        'created' => true,
    ), array('dry_run' => false));
    if (! $undone['readback_verified'] || $adapter->catalogOnlyInspect(array())['snippet']['present']) {
        throw new RuntimeException('Created snippet did not uninstall safely.');
    }
    echo "Code Snippets runtime rollback OK\n";
} else {
    throw new RuntimeException('Unknown runtime test phase.');
}
