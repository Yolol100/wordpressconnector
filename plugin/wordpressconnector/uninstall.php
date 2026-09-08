<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$cleanupCurrentSite = static function (): void {
    foreach (array(
        'wpconnector_rest_enabled',
        'wpconnector_allow_writes',
        'wpconnector_allow_privileged',
        'wpconnector_allow_sensitive',
        'wpconnector_allow_system_updates',
        'wpconnector_allow_filesystem_writes',
        'wpconnector_mutation_lock',
    ) as $option) {
        delete_option($option);
    }

    global $wpdb;
    foreach (array('wpconnector_snapshot_', 'wpconnector_processed_') as $prefix) {
        $like = $wpdb->esc_like($prefix) . '%';
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
        foreach ((array) $names as $name) {
            if (is_string($name) && 0 === strpos($name, $prefix)) {
                delete_option($name);
            }
        }
    }
};

if (is_multisite() && function_exists('get_sites')) {
    $offset = 0;
    do {
        $siteIds = get_sites(array(
            'fields' => 'ids',
            'number' => 100,
            'offset' => $offset,
            'orderby' => 'id',
            'order' => 'ASC',
        ));
        foreach ((array) $siteIds as $siteId) {
            switch_to_blog((int) $siteId);
            $cleanupCurrentSite();
            restore_current_blog();
        }
        $count = count((array) $siteIds);
        $offset += $count;
    } while (100 === $count);
} else {
    $cleanupCurrentSite();
}
