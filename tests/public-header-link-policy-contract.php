<?php

declare(strict_types=1);

use Webactueel\WordPressConnector\Runtime\Runner;

function home_url($path = '/')
{
    return 'https://example.test' . $path;
}

function wp_parse_url($url, $component = -1)
{
    return parse_url($url, $component);
}

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Runner.php';

$reflection = new ReflectionClass(Runner::class);
$runner = $reflection->newInstanceWithoutConstructor();
$descriptorMethod = $reflection->getMethod('publicDescriptor');
$descriptorMethod->setAccessible(true);
$descriptor = array('privileged' => true, 'public_repository_safe' => true);
$check = array(
    'post_id' => 42,
    'checks' => array(array('element_id' => 'widget_123', 'from' => 'https://example.test/product/')),
);
$change = array(
    'post_id' => 42,
    'changes' => array(array(
        'element_id' => 'widget_123',
        'from' => 'https://example.test/old/',
        'to' => 'https://example.test/new/',
    )),
);
$invoke = static function (string $action, array $payload, bool $dryRun, ?string $fingerprint = null) use ($descriptorMethod, $runner, $descriptor): array {
    return $descriptorMethod->invoke($runner, $action, $payload, $descriptor, $dryRun, $fingerprint);
};
$mustReject = static function (callable $callback): void {
    try {
        $callback();
    } catch (RuntimeException $error) {
        return;
    }
    throw new RuntimeException('An unsafe public Elementor header action was accepted.');
};

$inspection = $invoke('elementor.header_links.inspect', $check, true);
if ($inspection['privileged'] !== false || $inspection['public_repository_safe'] !== false) {
    throw new RuntimeException('Public inspection descriptor lost its permission boundary.');
}
$mustReject(static function () use ($invoke, $check): void {
    $invoke('elementor.header_links.inspect', $check, false);
});
$badCheck = $check;
$badCheck['checks'][0]['from'] = 'https://offsite.example/product/';
$mustReject(static function () use ($invoke, $badCheck): void {
    $invoke('elementor.header_links.inspect', $badCheck, true);
});
$badCheck = $check;
$badCheck['checks'][] = $check['checks'][0];
$mustReject(static function () use ($invoke, $badCheck): void {
    $invoke('elementor.header_links.inspect', $badCheck, true);
});

$preview = $invoke('elementor.header_links.replace', $change, true);
if ($preview['privileged'] !== false || $preview['public_repository_safe'] !== false) {
    throw new RuntimeException('Public header replacement descriptor lost its permission boundary.');
}
$mustReject(static function () use ($invoke, $change): void {
    $invoke('elementor.header_links.replace', $change, false);
});
$confirmed = $invoke('elementor.header_links.replace', $change, false, str_repeat('a', 64));
if ($confirmed !== $preview) {
    throw new RuntimeException('Confirmed header descriptor differs from dry-run descriptor.');
}
$badChange = $change;
$badChange['changes'][0]['to'] = 'https://offsite.example/new/';
$mustReject(static function () use ($invoke, $badChange): void {
    $invoke('elementor.header_links.replace', $badChange, true);
});
$badChange = $change;
$badChange['changes'][0]['to'] .= '?token=private';
$mustReject(static function () use ($invoke, $badChange): void {
    $invoke('elementor.header_links.replace', $badChange, true);
});
$badChange = $change;
$badChange['changes'][0]['to'] = $badChange['changes'][0]['from'];
$mustReject(static function () use ($invoke, $badChange): void {
    $invoke('elementor.header_links.replace', $badChange, true);
});

echo "Public Elementor header policy read/write guards OK\n";
