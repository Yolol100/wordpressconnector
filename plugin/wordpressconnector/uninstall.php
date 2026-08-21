<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('wpconnector_processed_requests');
delete_option('wpconnector_rollback_snapshots');
