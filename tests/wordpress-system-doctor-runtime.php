<?php

declare(strict_types=1);

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
