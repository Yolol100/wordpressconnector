<?php

declare(strict_types=1);

define('WPSEO_VERSION', '28.6');

$GLOBALS['yoast_org_options'] = array(
    'company_or_person' => 'company',
    'company_name' => 'Example Driving School',
    'company_logo' => '',
    'company_logo_id' => 0,
    'company_logo_meta' => false,
    'org-description' => '',
    'unrelated_theme_title' => 'Keep this unchanged',
);
$GLOBALS['yoast_org_caps'] = array();
$GLOBALS['yoast_org_media'] = array(
    7 => array('mime' => 'image/png', 'url' => 'https://example.test/uploads/logo.png', 'width' => 1200, 'height' => 400),
    8 => array('mime' => 'image/png', 'url' => 'https://example.test/uploads/tiny.png', 'width' => 64, 'height' => 64),
    9 => array('mime' => 'text/plain', 'url' => 'https://example.test/uploads/notes.txt', 'width' => 1200, 'height' => 400),
    10 => array('mime' => 'image/png', 'url' => 'http://example.test/uploads/insecure.png', 'width' => 1200, 'height' => 400),
);
class WP_Post { public $post_type = 'attachment'; }
function get_option($key, $default = null) { return 'wpseo_titles' === $key ? $GLOBALS['yoast_org_options'] : $default; }
function update_option($key, $value): bool { if ('wpseo_titles' !== $key) { return false; } $GLOBALS['yoast_org_options'] = $value; return true; }
function current_user_can($capability): bool { return in_array($capability, $GLOBALS['yoast_org_caps'], true); }
function get_post($id) { return isset($GLOBALS['yoast_org_media'][$id]) ? new WP_Post() : null; }
function wp_attachment_is_image($id): bool { return isset($GLOBALS['yoast_org_media'][$id]) && 0 === strpos($GLOBALS['yoast_org_media'][$id]['mime'], 'image/'); }
function wp_get_attachment_metadata($id) { return isset($GLOBALS['yoast_org_media'][$id]) ? $GLOBALS['yoast_org_media'][$id] : false; }
function wp_get_attachment_url($id) { return $GLOBALS['yoast_org_media'][$id]['url'] ?? false; }
function esc_url_raw($url): string { return $url; }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }

$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes/';
require $root . 'Support/Json.php';
require $root . 'Support/Fingerprint.php';
require $root . 'Security/Policy.php';
require $root . 'Runtime/Registry.php';
require $root . 'Adapters/YoastAdapter.php';

use Webactueel\WordPressConnector\Adapters\YoastAdapter;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

$registry = new Registry();
(new YoastAdapter())->register($registry);
$inspect = $registry->descriptor('yoast.site_representation.inspect');
$update = $registry->descriptor('yoast.site_representation.update');
$restore = $registry->descriptor('yoast.site_representation.restore');
if ($inspect['capability'] !== 'manage_options' || ! $update['privileged'] || ! $update['mutation'] || $update['capability'] !== 'manage_options' || ! $restore['mutation']) {
    throw new RuntimeException('Organization operations must be managed under capability gates.');
}
Policy::setPublicRepositoryContext(false);
try {
    Policy::assertActionAllowed($update, false, true);
    throw new RuntimeException('Unauthorized role unexpectedly allowed to update Yoast.');
} catch (RuntimeException $exception) {
    if (false === strpos($exception->getMessage(), 'lacks the required WordPress capability')) {
        throw $exception;
    }
}
$GLOBALS['yoast_org_caps'] = array('manage_options');
Policy::assertActionAllowed($update, false, true);
$expectFailure = static function (callable $callback, string $fragment): void {
    try {
        $callback();
    } catch (RuntimeException $exception) {
        if (false === strpos($exception->getMessage(), $fragment)) {
            throw new RuntimeException('Wrong error: ' . $exception->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected operation failure: ' . $fragment);
};

$initial = $registry->execute('yoast.site_representation.inspect', array());
if ($initial['fields']['company_name'] !== 'Example Driving School' || $initial['fields']['company_logo_id'] !== 0) {
    throw new RuntimeException('Site representation baseline incorrect.');
}

$fields = array(
    'logo_attachment_id' => 7,
    'company_name' => 'Vroom Rijscholen',
    'org_description' => 'Persoonlijke rijlessen en theorievoorbereiding.',
);
$dry = $registry->execute('yoast.site_representation.update', array('fields' => $fields), array('dry_run' => true));
if ($dry['after']['company_logo_id'] !== 7 || $dry['after']['company_logo'] !== 'https://example.test/uploads/logo.png' || $GLOBALS['yoast_org_options']['company_logo_id'] !== 0) {
    throw new RuntimeException('Dry-run has wrong preview or persisted a mutation.');
}
$apply = $registry->execute('yoast.site_representation.update', array('fields' => $fields), array('dry_run' => false));
$after = $registry->execute('yoast.site_representation.inspect', array());
if ($after['fields']['company_name'] !== 'Vroom Rijscholen' || $after['fields']['company_logo_id'] !== 7 || $after['fields']['org_description'] !== $fields['org_description'] || $after['fields']['company_logo_meta'] !== false || $GLOBALS['yoast_org_options']['unrelated_theme_title'] !== 'Keep this unchanged') {
    throw new RuntimeException('Site representation write lost data or failed readback.');
}
if ($apply['_rollback']['action'] !== 'yoast.site_representation.restore') {
    throw new RuntimeException('Organization update must return a targeted rollback.');
}
$expectFailure(static function () use ($registry, $apply): void {
    $registry->execute('yoast.site_representation.restore', $apply['_rollback']['payload'], array('dry_run' => false));
}, 'reserved for connector rollback');

$reverted = $registry->execute('yoast.site_representation.restore', $apply['_rollback']['payload'], array('rollback_mode' => true));
if (! $reverted['restored'] || $GLOBALS['yoast_org_options']['company_logo_id'] !== 0 || $GLOBALS['yoast_org_options']['company_name'] !== 'Example Driving School' || $GLOBALS['yoast_org_options']['unrelated_theme_title'] !== 'Keep this unchanged') {
    throw new RuntimeException('Organization rollback failed to preserve existing option state.');
}
$expectFailure(static function () use ($registry, $apply): void {
    $registry->execute('yoast.site_representation.restore', $apply['_rollback']['payload'], array('rollback_mode' => true));
}, 'rollback is unsafe');

foreach (array(
    array('logo_attachment_id' => 0, 'error' => 'positive WordPress media attachment'),
    array('logo_attachment_id' => '7', 'error' => 'positive WordPress media attachment'),
    array('logo_attachment_id' => 999, 'error' => 'WordPress image attachment'),
    array('logo_attachment_id' => 9, 'error' => 'WordPress image attachment'),
    array('logo_attachment_id' => 8, 'error' => '112x112'),
    array('logo_attachment_id' => 10, 'error' => 'HTTPS'),
    array('unauthorized_setting' => 'anything', 'error' => 'Unsupported site representation'),
    array('company_name' => '', 'error' => 'nonempty string'),
    array('org_description' => array('not', 'text'), 'error' => 'nonempty string'),
) as $test) {
    $expected = $test['error'];
    unset($test['error']);
    $expectFailure(static function () use ($registry, $test): void {
        $registry->execute('yoast.site_representation.update', array('fields' => $test), array('dry_run' => true));
    }, $expected);
}
$GLOBALS['yoast_org_options']['company_or_person'] = 'person';
$expectFailure(static function () use ($registry): void {
    $registry->execute('yoast.site_representation.update', array('fields' => array('logo_attachment_id' => 7)), array('dry_run' => true));
}, 'not set to Organization');

echo "Yoast site representation allowlist, capability, dry-run, image guards, readback and rollback OK\n";
