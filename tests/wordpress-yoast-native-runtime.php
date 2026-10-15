<?php
declare(strict_types=1);

// Executed inside an isolated WP-CLI WordPress + official Yoast SEO runtime.
// No network actions, paid providers, WooCommerce products or site settings.
use Webactueel\WordPressConnector\Adapters\YoastNativeAbilitiesAdapter;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

if (! function_exists('wp_get_ability') ||
    ! defined('WPSEO_VERSION') ||
    ! current_user_can('wpseo_edit_advanced_metadata')) {
    throw new RuntimeException('Official Yoast Abilities or required capabilities are unavailable.');
}
if (! is_object(wp_get_ability('yoast-seo/get-post-seo-data')) ||
    ! is_object(wp_get_ability('yoast-seo/update-post-seo-data'))) {
    throw new RuntimeException('Official Yoast SEO post Abilities are not registered.');
}
Policy::setPublicRepositoryContext(false);
$id = wp_insert_post(array('post_title'=>'Native Yoast Connector Test',
    'post_content'=>'An isolated SEO metadata runtime test.',
    'post_status'=>'publish','post_type'=>'post'),true);
if (is_wp_error($id) || ! is_int($id) || $id < 1) {
    throw new RuntimeException('Could not create disposable Yoast SEO test post.');
}
try {
    $registry = new Registry();
    (new YoastNativeAbilitiesAdapter())->register($registry);
    $original = $registry->execute('yoast.native.post.inspect',array('post_id'=>$id));
    $fields = array(
        'seo_title'=>'Native Yoast Connector Runtime Title',
        'meta_description'=>'SEO metadata is edited through the official Yoast Abilities API.',
    );
    $preview = $registry->execute('yoast.native.post.update',
        array('post_id'=>$id,'fields'=>$fields),array('dry_run'=>true));
    if ($preview['_current_fingerprint'] !== $original['fingerprint']) {
        throw new RuntimeException('Yoast runtime fingerprint changed during dry-run.');
    }
    $result = $registry->execute('yoast.native.post.update',
        array('post_id'=>$id,'fields'=>$fields),array('dry_run'=>false));
    if (true !== ($result['readback_verified'] ?? null) ||
        ! isset($result['_rollback'])) {
        throw new RuntimeException('Official Yoast Ability write was not verified.');
    }
    $fresh = $registry->execute('yoast.native.post.inspect',array('post_id'=>$id));
    foreach ($fields as $key=>$value) {
        if ($fresh['fields'][$key] !== $value) {
            throw new RuntimeException('Yoast native runtime readback failed for ' . $key);
        }
    }
    $restore = $registry->execute('yoast.native.post.restore',
        $result['_rollback']['payload'],array('rollback_mode'=>true));
    $afterRestore = $registry->execute('yoast.native.post.inspect',array('post_id'=>$id));
    if (! $restore['restored'] || $afterRestore['fields'] !== $original['fields']) {
        throw new RuntimeException('Official Yoast Ability rollback did not restore metadata.');
    }
    echo "Official Yoast SEO native Abilities WordPress runtime, edit, readback and rollback OK\n";
} finally {
    wp_delete_post($id,true);
}
