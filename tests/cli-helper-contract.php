<?php

declare(strict_types=1);

// Execute the real CLI helpers against the real request parser and Runner.
// Only the WordPress CLI transport and read-only doctor adapter are fixtures.
if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (! class_exists('WP_CLI')) {
    final class WP_CLI
    {
        public static array $lines = array();

        public static function line(string $value): void
        {
            self::$lines[] = $value;
        }

        public static function halt(int $code): void
        {
            throw new RuntimeException('CLI exited unexpectedly: ' . $code);
        }
    }
}

$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes/';
foreach (array(
    'Support/Json.php',
    'Support/Fingerprint.php',
    'Security/Policy.php',
    'Runtime/Request.php',
    'Runtime/Registry.php',
    'Runtime/SnapshotStore.php',
    'Runtime/ProcessedStore.php',
    'Runtime/Result.php',
    'Runtime/Runner.php',
    'CLI/Command.php',
) as $file) {
    require_once $root . $file;
}

$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$registry->register('system.doctor', static function (array $payload, array $context): array {
    if ($payload || empty($context['dry_run'])) {
        throw new RuntimeException('Doctor must remain a read-only, empty-payload action.');
    }
    return array('ok' => true, 'runtime_probe' => true);
});
$runner = new \Webactueel\WordPressConnector\Runtime\Runner(
    $registry,
    new \Webactueel\WordPressConnector\Runtime\SnapshotStore(),
    new \Webactueel\WordPressConnector\Runtime\ProcessedStore()
);
$cli = new \Webactueel\WordPressConnector\CLI\Command($runner);
$cli->actions(array(), array());
$cli->doctor(array(), array());

if (count(WP_CLI::$lines) !== 2) {
    throw new RuntimeException('Both CLI helpers must emit exactly one result.');
}
$expected = array('connector.actions', 'system.doctor');
foreach (WP_CLI::$lines as $index => $line) {
    $response = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($response)
        || ($response['version'] ?? null) !== 1
        || ($response['ok'] ?? null) !== true
        || ($response['action'] ?? null) !== $expected[$index]
        || ($response['dry_run'] ?? null) !== true) {
        throw new RuntimeException('CLI helper result invalid: ' . $expected[$index]);
    }
    if ($index === 0 && ! isset($response['data']['actions']['connector.mutation_status'])) {
        throw new RuntimeException('CLI actions did not return the real Runner registry.');
    }
    if ($index === 1 && ($response['data']['runtime_probe'] ?? null) !== true) {
        throw new RuntimeException('CLI doctor did not execute its registered read-only action.');
    }
}
echo "WP-CLI actions and doctor versioned read-only request contracts OK\n";
