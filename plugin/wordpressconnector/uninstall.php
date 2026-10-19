<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$wpconnector_cleanup_current_site = static function (): void {
    foreach (array(
        'wpconnector_rest_enabled',
        'wpconnector_allow_writes',
        'wpconnector_allow_privileged',
        'wpconnector_allow_sensitive',
        'wpconnector_allow_system_updates',
        'wpconnector_allow_filesystem_writes',
        'wpconnector_mutation_lock',
        'wpconnector_github_oidc_site_pairing_v1',
    ) as $option) {
        delete_option($option);
    }

    delete_transient('wpconnector_github_oidc_jwks_v1');

    global $wpdb;
    foreach (array(
        'wpconnector_snapshot_',
        'wpconnector_processed_',
        '_transient_wpconnector_oidc_jti_',
        '_transient_timeout_wpconnector_oidc_jti_',
    ) as $prefix) {
        $like = $wpdb->esc_like($prefix) . '%';
        $names = $wpdb->get_col($wpdb->prepare('SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $like));
        foreach ((array) $names as $name) {
            if (is_string($name) && 0 === strpos($name, $prefix)) {
                delete_option($name);
            }
        }
    }
};

if (is_multisite() && function_exists('get_sites')) {
    $wpconnector_offset = 0;
    do {
        $wpconnector_site_ids = get_sites(array(
            'fields' => 'ids',
            'number' => 100,
            'offset' => $wpconnector_offset,
            'orderby' => 'id',
            'order' => 'ASC',
        ));
        foreach ((array) $wpconnector_site_ids as $wpconnector_site_id) {
            switch_to_blog((int) $wpconnector_site_id);
            $wpconnector_cleanup_current_site();
            restore_current_blog();
        }
        $wpconnector_count = count((array) $wpconnector_site_ids);
        $wpconnector_offset += $wpconnector_count;
    } while (100 === $wpconnector_count);
} else {
    $wpconnector_cleanup_current_site();
}
