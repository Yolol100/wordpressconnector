<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

foreach (array(
    'wpconnector_rest_enabled',
    'wpconnector_allow_writes',
    'wpconnector_allow_privileged',
    'wpconnector_allow_sensitive',
    'wpconnector_allow_system_updates',
) as $option) {
    delete_option($option);
}

// Runtime snapshots and idempotency records are intentionally retained unless explicitly cleaned up before uninstall.
