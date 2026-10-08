<?php

declare(strict_types=1);

define('JOINCHAT_VERSION', '6.4.0');
$GLOBALS['joinchat_values'] = array(
    'telephone' => '31612345678',
    'mobile_only' => 'no',
    'button_tip' => 'Chat met ons',
    'button_delay' => 3,
    'whatsapp_web' => 'no',
    'message_text' => 'Stel gerust een vraag.',
    'message_send' => 'Hallo!',
    'message_start' => 'Open WhatsApp',
    'position' => 'right',
    'tracking' => 'yes',
    'show_brand' => 'no',
    'color' => '#25d366/100',
    'custom_css' => 'keep-exactly-this',
    'optin_check' => 'yes',
);
function jc_common() {
    return new class {
        public function defaults(): array {
            return array('telephone' => '', 'position' => 'right', 'tracking' => 'yes', 'mobile_only' => 'no');
        }
    };
}
function get_option($name, $default = null) {
    return 'joinchat' === $name ? $GLOBALS['joinchat_values'] : $default;
}
function update_option($name, $value): bool {
    if ('joinchat' !== $name || ! is_array($value)) {
        return false;
    }
    $GLOBALS['joinchat_values'] = $value;
    return true;
}
function sanitize_key($value): string {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
}
function sanitize_text_field($value): string {
    return trim(strip_tags((string) $value));
}
function wp_json_encode($value, $options = 0): string {
    return json_encode($value, $options);
}

$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes/';
require_once $root . 'Support/Json.php';
require_once $root . 'Support/Fingerprint.php';
require_once $root . 'Security/Policy.php';
require_once $root . 'Runtime/Registry.php';
require_once $root . 'Adapters/PluginSettingsAdapter.php';

use Webactueel\WordPressConnector\Adapters\PluginSettingsAdapter;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

Policy::setPublicRepositoryContext(false);
$registry = new Registry();
(new PluginSettingsAdapter())->register($registry);

$inspect = $registry->execute('plugin.settings.inspect', array('plugin' => 'joinchat'));
if ($inspect['plugin'] !== 'joinchat' || $inspect['settings']['telephone'] !== '31612345678' || isset($inspect['settings']['custom_css'])) {
    throw new RuntimeException('Joinchat inspect did not restrict provider settings.');
}

$fields = array(
    'telephone' => '+31 (6) 12345678',
    'tracking' => 'no',
    'position' => 'left',
    'button_tip' => 'Bel of app ons',
    'color' => '#ee6600/100',
);
$dry = $registry->execute('plugin.settings.update', array('plugin' => 'joinchat', 'fields' => $fields), array('dry_run' => true));
if ($dry['after']['telephone'] !== '31612345678' || $dry['after']['tracking'] !== 'no' || $GLOBALS['joinchat_values']['tracking'] !== 'yes') {
    throw new RuntimeException('Joinchat dry-run mutated settings or skipped validation.');
}
$actual = $registry->execute('plugin.settings.update', array('plugin' => 'joinchat', 'fields' => $fields), array('dry_run' => false));
$readback = $registry->execute('plugin.settings.inspect', array('plugin' => 'joinchat'));
if ($readback['settings']['tracking'] !== 'no' || $readback['settings']['position'] !== 'left' || $readback['settings']['color'] !== '#ee6600/100' || $GLOBALS['joinchat_values']['custom_css'] !== 'keep-exactly-this' || $GLOBALS['joinchat_values']['optin_check'] !== 'yes') {
    throw new RuntimeException('Joinchat update lost unmodified provider fields or failed readback.');
}
if ($actual['_rollback']['action'] !== 'plugin.settings.update') {
    throw new RuntimeException('Joinchat update must return bounded compensation.');
}
$registry->execute($actual['_rollback']['action'], $actual['_rollback']['payload'], array('dry_run' => false));
if ($GLOBALS['joinchat_values']['tracking'] !== 'yes' || $GLOBALS['joinchat_values']['position'] !== 'right' || $GLOBALS['joinchat_values']['custom_css'] !== 'keep-exactly-this') {
    throw new RuntimeException('Joinchat rollback failed.');
}

$expectFailure = static function (array $fields, string $fragment) use ($registry): void {
    try {
        $registry->execute('plugin.settings.update', array('plugin' => 'joinchat', 'fields' => $fields), array('dry_run' => true));
    } catch (RuntimeException $error) {
        if (false === strpos($error->getMessage(), $fragment)) {
            throw new RuntimeException('Unexpected validation error: ' . $error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected a rejected Joinchat update: ' . $fragment);
};
$expectFailure(array('tracking' => 'maybe'), 'yes or no');
$expectFailure(array('custom_css' => 'body{display:none}'), 'Unsupported or unavailable');
$expectFailure(array('telephone' => 'contact@example.com'), 'phone-number characters');
$expectFailure(array('telephone' => '12345'), '8-15 digits');
$expectFailure(array('button_delay' => '5'), 'integer between 0 and 30');
$expectFailure(array('button_delay' => 100), 'integer between 0 and 30');
$expectFailure(array('position' => 'top'), 'left or right');
$expectFailure(array('color' => '#red'), 'RGB hex');
$expectFailure(array('message_text' => str_repeat('x', 801)), 'length limit');
$expectFailure(array('secret_token' => 'bad'), 'Setting key not allowed');

$source = (string) file_get_contents($root . 'Adapters/PluginSettingsAdapter.php');
foreach (array(
    "'elementor-pro/elementor-pro.php'",
    "'classic-editor/classic-editor.php'",
    "'redirection/redirection.php'",
    "'under-construction-page/under-construction.php'",
    "'creame-whatsapp-me/joinchat.php'",
    "'yoast.site_representation.update'",
    "'joinchat'",
) as $needle) {
    if (false === strpos($source, $needle)) {
        throw new RuntimeException('Plugin catalog missing installed integration: ' . $needle);
    }
}
echo "Joinchat 6.4 safe settings, catalog coverage, dry-run/readback/rollback and rejection cases OK\n";
