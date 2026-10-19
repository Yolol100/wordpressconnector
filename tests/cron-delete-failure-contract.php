<?php

declare(strict_types=1);

// Simulated WordPress Core cron return values, including failure from a scheduler provider.
final class WP_Error
{
    private string $message;
    public function __construct(string $message) { $this->message = $message; }
    public function get_error_message(): string { return $this->message; }
}
function sanitize_key(string $value): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value));
}
function is_wp_error($candidate): bool { return $candidate instanceof WP_Error; }
function wp_clear_scheduled_hook(string $hook, array $args = [], bool $wp_error = false)
{
    $GLOBALS['cron_args'] = array('hook' => $hook, 'args' => $args, 'wp_error' => $wp_error);
    // This mimics Core converting a failed unschedule to WP_Error when requested.
    return false === $GLOBALS['cron_result'] && $wp_error
        ? new WP_Error('Could not clear scheduled hook.')
        : $GLOBALS['cron_result'];
}

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/SystemAdapter.php';
$adapter = new \Webactueel\WordPressConnector\Adapters\SystemAdapter();
$payload = array('hook' => 'webactueel_test_hook', 'args' => array('job' => 17));

$GLOBALS['cron_result'] = false;
try {
    $adapter->cronDelete($payload, array());
    throw new RuntimeException('REGRESSION: failed scheduled hook deletion was reported as a success.');
} catch (RuntimeException $error) {
    if ('Could not clear scheduled hook.' !== $error->getMessage()) {
        throw $error;
    }
}
if (true !== ($GLOBALS['cron_args']['wp_error'] ?? null)) {
    throw new RuntimeException('Cron deletion must request error-aware WordPress Core behavior.');
}

$GLOBALS['cron_result'] = new WP_Error('Blocked by another cron provider.');
try {
    $adapter->cronDelete($payload, array());
    throw new RuntimeException('REGRESSION: WP_Error from cron provider was accepted as success.');
} catch (RuntimeException $error) {
    if ('Blocked by another cron provider.' !== $error->getMessage()) {
        throw $error;
    }
}
foreach (array(0, 2) as $expected) {
    $GLOBALS['cron_result'] = $expected;
    $result = $adapter->cronDelete($payload, array());
    if ($result['cleared'] !== $expected
        || true !== ($GLOBALS['cron_args']['wp_error'] ?? null)
        || $GLOBALS['cron_args']['args'] !== array('job' => 17)) {
        throw new RuntimeException('Cron deletion lost exact arguments or success count.');
    }
}
echo "Cron deletion failure propagation and success counts OK\n";
