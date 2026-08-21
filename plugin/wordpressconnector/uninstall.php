<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Runtime snapshots and idempotency records are intentionally retained unless explicitly cleaned up before uninstall.
