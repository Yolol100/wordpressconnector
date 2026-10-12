<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/wpc-plugin-audit-' . getmypid();
if (! is_dir($root . '/wp-admin/includes') && ! mkdir($root . '/wp-admin/includes', 0700, true)) {
    throw new RuntimeException('Could not create WordPress plugin audit fixture.');
}
register_shutdown_function(static function () use ($root): void {
    @unlink($root . '/wp-admin/includes/plugin.php');
    @rmdir($root . '/wp-admin/includes');
    @rmdir($root . '/wp-admin');
    @rmdir($root);
});
file_put_contents($root . '/wp-admin/includes/plugin.php', "<?php\n");
define('ABSPATH', $root . '/');

$GLOBALS['audit_role'] = true;
$GLOBALS['audit_plugins'] = array(
    'woocommerce/woocommerce.php' => array('Name' => 'WooCommerce', 'Version' => '11.2.1'),
    'wordpress-seo/wp-seo.php' => array('Name' => 'Yoast SEO', 'Version' => '28.6'),
    'gtranslate/gtranslate.php' => array('Name' => 'GTranslate', 'Version' => '5.0.1'),
    'creame-whatsapp-me/joinchat.php' => array('Name' => 'Joinchat', 'Version' => '6.4.1'),
    'wp-rocket/wp-rocket.php' => array('Name' => 'WP Rocket', 'Version' => '3.23.5.1'),
    'ewww-image-optimizer/ewww-image-optimizer.php' => array('Name' => 'EWWW', 'Version' => '8.8.4'),
    'wp-asset-clean-up/wpacu.php' => array('Name' => 'Asset CleanUp', 'Version' => '1.4.0.6'),
    'wp-mail-smtp/wp_mail_smtp.php' => array('Name' => 'WP Mail SMTP', 'Version' => '4.0.0'),
    'all-in-one-wp-security-and-firewall/wp-security.php' => array('Name' => 'AIOS', 'Version' => '5.4'),
    'some-unknown-plugin/private.php' => array('Name' => 'Special Secret Plugin', 'Version' => 'SECRET-NO-LEAK'),
    'duplicate-page/duplicatepage.php' => array('Name' => 'Duplicate Page', 'Version' => '1.0'),
);
$GLOBALS['audit_inactive'] = array('duplicate-page/duplicatepage.php');
$GLOBALS['audit_options'] = array(
    'woocommerce_enable_guest_checkout' => 'yes',
    'woocommerce_manage_stock' => 'yes',
    'woocommerce_cart_page_id' => '0',
    'woocommerce_checkout_page_id' => '0',
    'blogname' => 'ISOHULK',
    'wpseo_titles' => array('company_name' => 'ISOHULK', 'org-description' => 'English company description', 'company_logo_id' => 0),
    'GTranslate' => array('default_language' => 'en', 'incl_langs' => array('nl','en'), 'fincl_langs' => array('nl','en'), 'private_token' => 'DO-NOT-LEAK-TRANSLATION'),
    'joinchat' => array('message_text' => 'Welcome!', 'button_tip' => 'Contact us', 'telephone' => 'PRIVATE-PHONE'),
    'wp_mail_smtp' => array('smtp' => array('password' => 'DO-NOT-LEAK-SMTP', 'host' => 'internal.private.local')),
    'wpassetcleanup_settings' => array('secret' => 'DO-NOT-LEAK-CACHE'),
);
$GLOBALS['audit_writes'] = 0;
function get_plugins(): array { return $GLOBALS['audit_plugins']; }
function is_plugin_active($file): bool { return ! in_array($file, $GLOBALS['audit_inactive'], true); }
function current_user_can($capability): bool { return $GLOBALS['audit_role'] === true && $capability === 'manage_options'; }
function sanitize_key($value): string { return strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $value)); }
function get_option($name, $default = null) { return $GLOBALS['audit_options'][$name] ?? $default; }
function update_option($name, $value): bool { ++$GLOBALS['audit_writes']; throw new RuntimeException('Audit may not write options.'); }
function get_rocket_option($name, $default = null) {
    return array('lazyload' => 0, 'remove_unused_css' => 1)[$name] ?? $default;
}
function ewww_image_optimizer_get_option($name, $default = null) {
    return array('ewww_image_optimizer_lazy_load' => 1, 'ewww_image_optimizer_add_missing_dims' => 1)[$name] ?? $default;
}
$prefix = dirname(__DIR__) . '/plugin/wordpressconnector/includes/';
require_once $prefix . 'Security/Policy.php';
require_once $prefix . 'Runtime/Registry.php';
require_once $prefix . 'Adapters/PluginSettingsAdapter.php';

use Webactueel\WordPressConnector\Adapters\PluginSettingsAdapter;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

Policy::setPublicRepositoryContext(true);
$registry = new Registry();
$adapter = new PluginSettingsAdapter();
$adapter->register($registry);
foreach (array('woocommerce.settings.inspect', 'gtranslate.settings.inspect', 'yoast.inspect', 'optimizer.settings.inspect') as $name) {
    $registry->register($name, static function (): array { return array(); }, array('privileged' => true));
}
Policy::assertActionAllowed($registry->descriptor('plugin.audit.inspect'), true, false);
$report = $registry->execute('plugin.audit.inspect', array(), array(
    'dry_run' => true, 'confirm' => false, 'registry' => $registry,
));
$indexed = array();
foreach ($report['plugins'] as $item) {
    $indexed[$item['id']] = $item;
}
if ($report['installed_count'] !== 11 || $report['active_count'] !== 10
    || $report['inactive_count'] !== 1 || $report['unmodeled_count'] !== 1
    || $report['private_settings_exported'] !== false
    || $report['checkout_qa'] !== 'not_run' || $report['smtp_delivery_qa'] !== 'not_run') {
    throw new RuntimeException('All-plugin inventory totals or safeguards are incorrect.');
}
if (($indexed['unmodeled_1']['coverage'] ?? null) !== 'unmodeled'
    || ! isset($indexed['duplicate_page']) || $indexed['duplicate_page']['active'] !== false
    || ($indexed['woocommerce']['checks']['guest_checkout'] ?? null) !== 'enabled'
    || ($indexed['woocommerce']['checks']['stock_management'] ?? null) !== 'enabled'
    || ($indexed['woocommerce']['checks']['cart_page'] ?? null) !== 'missing'
    || ($indexed['yoast']['checks']['organization_name'] ?? null) !== 'matches'
    || ($indexed['gtranslate']['checks']['nl_en_languages'] ?? null) !== 'matches'
    || ($indexed['joinchat']['checks']['message_saved'] ?? null) !== 'present'
    || ($indexed['wp_mail_smtp']['checks']['delivery'] ?? null) !== 'unverified'
    || ($indexed['ewww_image_optimizer']['checks']['image_lazyload'] ?? null) !== 'enabled'
    || ($indexed['wp_rocket']['checks']['image_lazyload'] ?? null) !== 'disabled'
    || ($report['image_lazyload_overlap'] ?? null) !== 'no_overlap_observed'
    || $GLOBALS['audit_writes'] !== 0) {
    throw new RuntimeException('Specific plugin checks, overlap status or strict read-only behavior failed.');
}
$encoded = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
foreach (array('PRIVATE-PHONE','DO-NOT-LEAK-SMTP','DO-NOT-LEAK-TRANSLATION',
    'DO-NOT-LEAK-CACHE','SECRET-NO-LEAK','internal.private.local','Special Secret Plugin') as $private) {
    if (strpos($encoded, $private) !== false) {
        throw new RuntimeException('Plugin audit exported a secret or unchecked provider header.');
    }
}
foreach (array(
    array('payload' => array('option' => 'wp_mail_smtp')),
    array('context' => array('dry_run' => false, 'confirm' => true)),
) as $bad) {
    $blocked = false;
    try {
        $adapter->audit($bad['payload'] ?? array(), $bad['context'] ?? array('dry_run' => true));
    } catch (RuntimeException $error) {
        $blocked = true;
    }
    if (! $blocked) throw new RuntimeException('Unsafe plugin audit request was accepted.');
}
$GLOBALS['audit_role'] = false;
try {
    $adapter->audit(array(), array('dry_run' => true));
    throw new RuntimeException('Unauthorized plugin audit was accepted.');
} catch (RuntimeException $error) {
    if (strpos($error->getMessage(), 'requires manage_options') === false) throw $error;
}
$GLOBALS['audit_role'] = true;
$GLOBALS['audit_plugins']['ewww-image-optimizer/ewww-image-optimizer.php']['Version'] = '9.0.0';
$unsupported = $adapter->audit(array(), array('dry_run' => true, 'registry' => $registry));
foreach ($unsupported['plugins'] as $item) {
    if ($item['id'] === 'ewww_image_optimizer'
        && ($item['coverage'] !== 'restricted' || $item['checks'] !== array())) {
        throw new RuntimeException('Unverified optimizer version was given provider capability.');
    }
}
for ($i = 0; $i < 151; ++$i) {
    $GLOBALS['audit_plugins']['unknown-' . $i . '/unknown.php'] = array('Name'=>'X' . $i,'Version'=>'1.0');
}
try {
    $adapter->audit(array(), array('dry_run' => true, 'registry' => $registry));
    throw new RuntimeException('Unbounded plugin list was accepted.');
} catch (RuntimeException $error) {
    if (strpos($error->getMessage(), 'bounded audit limit') === false) throw $error;
}
if ($GLOBALS['audit_writes'] !== 0) {
    throw new RuntimeException('Audit caused WordPress option mutations.');
}
echo "All-plugin read-only inventory, provider flags, secret withholding and scope gates OK\n";
