<?php

// WP-CLI eval-file prepends code, so strict_types cannot be declared here.

if (! defined('ABSPATH') || ! defined('WPCONNECTOR_VERSION')) {
    throw new RuntimeException('WordPress Connector was not loaded into WordPress.');
}

$doctor = new \Webactueel\WordPressConnector\Adapters\SystemAdapter();
$checks = $doctor->doctor();
if (true !== ($checks['wordpress_bootstrap'] ?? null)
    || true !== ($checks['database'] ?? null)
    || true !== ($checks['ok'] ?? null)
    || ! is_string($checks['wordpress_version'] ?? null)
    || '' === (string) $checks['wordpress_version']) {
    throw new RuntimeException('WordPress system.doctor must report an available database and live runtime.');
}

echo 'WordPress system.doctor real-runtime database readiness OK (' . WPCONNECTOR_VERSION . ").\n";

// Real WordPress Core must convert failed cron unscheduling to WP_Error.
// The prior implementation silently cast false to zero and claimed success.
$hook = 'wpconnector_cron_probe_' . substr(md5(wp_generate_uuid4()), 0, 10);
$args = array('probe' => 17);
$scheduled = wp_schedule_single_event(time() + 3600, $hook, $args, true);
if (true !== $scheduled) {
    throw new RuntimeException('WordPress Core could not schedule cron negative-test fixture.');
}
$block = static function ($pre, $candidateHook) use ($hook) {
    return $hook === $candidateHook ? false : $pre;
};
try {
    add_filter('pre_clear_scheduled_hook', $block, 10, 2);
    $blocked = false;
    try {
        $doctor->cronDelete(array('hook' => $hook, 'args' => $args), array());
    } catch (RuntimeException $error) {
        $blocked = true;
    } finally {
        remove_filter('pre_clear_scheduled_hook', $block, 10);
    }
    if (! $blocked || false === wp_next_scheduled($hook, $args)) {
        throw new RuntimeException('Failed WP-Cron deletion was reported as success or removed a blocked job.');
    }
    $removed = $doctor->cronDelete(array('hook' => $hook, 'args' => $args), array());
    if (1 !== ($removed['cleared'] ?? null) || false !== wp_next_scheduled($hook, $args)) {
        throw new RuntimeException('Successful WP-Cron deletion was not read back.');
    }
} finally {
    remove_filter('pre_clear_scheduled_hook', $block, 10);
    wp_clear_scheduled_hook($hook, $args, true);
}
echo "WordPress system.cron.delete real-runtime success and blocked-provider behavior OK\n";

