<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wordpress-escape-fixture.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Support/Json.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Support/class-fingerprint.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Runtime/class-request.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Security/class-filesystempolicy.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-siteeditoradapter.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/SystemAdapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/WooCommerceAdapter.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/AcfAdapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/ElementorAdapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/WooCommerceFulfillmentAdapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/YoastAdapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/AbilitiesAdapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/CodeSnippetsAdapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Support/Input.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Admin/ElementorJsonExport.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/PluginPackageAdapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/YoastSnippetAdapter.php';

$serviceAliases = array(
    'Webactueel\WordPressConnector\Adapters\AcfAdapter' => array(
        'fieldGroups' => 'Webactueel\WordPressConnector\Adapters\AcfAdapterActions::field_groups',
        'ensureTextFields' => 'Webactueel\WordPressConnector\Adapters\AcfAdapterActions::ensure_text_fields',
        'removeTextFields' => 'Webactueel\WordPressConnector\Adapters\AcfAdapterActions::remove_text_fields',
        'removeFields' => 'Webactueel\WordPressConnector\Adapters\AcfAdapterActions::remove_fields',
        'createFieldGroup' => 'Webactueel\WordPressConnector\Adapters\AcfAdapterActions::create_field_group',
        'deleteFieldGroup' => 'Webactueel\WordPressConnector\Adapters\AcfAdapterActions::delete_field_group',
    ),
    'Webactueel\WordPressConnector\Adapters\ElementorAdapter' => array(
        'createDocument' => 'Webactueel\WordPressConnector\Adapters\ElementorAdapterActions::create_document',
        'replaceDocument' => 'Webactueel\WordPressConnector\Adapters\ElementorAdapterActions::replace_document',
        'patchElement' => 'Webactueel\WordPressConnector\Adapters\ElementorAdapterActions::patch_element',
        'inspectHeaderLinks' => 'Webactueel\WordPressConnector\Adapters\ElementorAdapterActions::inspect_header_links',
        'replaceHeaderLinks' => 'Webactueel\WordPressConnector\Adapters\ElementorAdapterActions::replace_header_links',
    ),
    'Webactueel\WordPressConnector\Adapters\WooCommerceFulfillmentAdapter' => array(
        'updateLocations' => 'Webactueel\WordPressConnector\Adapters\WooCommerceFulfillmentAdapterActions::update_locations',
        'restoreLocations' => 'Webactueel\WordPressConnector\Adapters\WooCommerceFulfillmentAdapterActions::restore_locations',
        'restoreCreated' => 'Webactueel\WordPressConnector\Adapters\WooCommerceFulfillmentAdapterActions::restore_created',
    ),
    'Webactueel\WordPressConnector\Adapters\YoastAdapter' => array(
        'updateSiteRepresentation' => 'Webactueel\WordPressConnector\Adapters\YoastAdapterActions::update_site_representation',
        'restoreSiteRepresentation' => 'Webactueel\WordPressConnector\Adapters\YoastAdapterActions::restore_site_representation',
    ),
    'Webactueel\WordPressConnector\Adapters\AbilitiesAdapter' => array(
        'executeAbility' => 'Webactueel\WordPressConnector\Adapters\AbilitiesAdapterActions::execute_ability',
        'executeApprovedAbility' => 'Webactueel\WordPressConnector\Adapters\AbilitiesAdapterActions::execute_approved_ability',
    ),
    'Webactueel\WordPressConnector\Adapters\CodeSnippetsAdapter' => array(
        'catalogOnlyEnsure' => 'Webactueel\WordPressConnector\Adapters\CodeSnippetsAdapterActions::catalog_only_ensure',
        'catalogOnlyUndo' => 'Webactueel\WordPressConnector\Adapters\CodeSnippetsAdapterActions::catalog_only_undo',
        'restoreCode' => 'Webactueel\WordPressConnector\Adapters\CodeSnippetsAdapterActions::restore_code',
    ),
    'Webactueel\WordPressConnector\Support\Input' => array(
        'boolValue' => 'Webactueel\WordPressConnector\Support\InputMethods::bool_value',
    ),
    'Webactueel\WordPressConnector\Admin\ElementorJsonExport' => array(
        'rowActions' => 'Webactueel\WordPressConnector\Admin\ElementorJsonExportMethods::row_actions',
    ),
    'Webactueel\WordPressConnector\Adapters\PluginPackageAdapter' => array(
        'installThemePackage' => 'Webactueel\WordPressConnector\Adapters\PluginPackageAdapterActions::install_theme_package',
        'installPackage' => 'Webactueel\WordPressConnector\Adapters\PluginPackageAdapterActions::install_package',
    ),
    'Webactueel\WordPressConnector\Adapters\YoastSnippetAdapter' => array(
        'validateFields' => 'Webactueel\WordPressConnector\Adapters\YoastSnippetAdapterActions::validate_fields',
    ),
    'Webactueel\WordPressConnector\Adapters\SystemAdapter' => array(
        'userList' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::user_list',
        'userGet' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::user_get',
        'userCreate' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::user_create',
        'userUpdate' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::user_update',
        'userForcePasswordReset' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::user_force_password_reset',
        'userDelete' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::user_delete',
        'roleList' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::role_list',
        'roleCreate' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::role_create',
        'roleUpdate' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::role_update',
        'roleDelete' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::role_delete',
        'pluginList' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::plugin_list',
        'pluginActivate' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::plugin_activate',
        'pluginDeactivate' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::plugin_deactivate',
        'pluginInstall' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::plugin_install',
        'pluginUpdate' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::plugin_update',
        'pluginDelete' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::plugin_delete',
        'themeList' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::theme_list',
        'themeSwitch' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::theme_switch',
        'themeInstall' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::theme_install',
        'themeUpdate' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::theme_update',
        'themeDelete' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::theme_delete',
        'coreCheckUpdates' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::core_check_updates',
        'coreUpdate' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::core_update',
        'cronList' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::cron_list',
        'cronDelete' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::cron_delete',
        'cronSchedule' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::cron_schedule',
        'rewriteFlush' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::rewrite_flush',
        'cacheFlush' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::cache_flush',
        'siteList' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::site_list',
        'siteCreate' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::site_create',
        'siteUpdate' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::site_update',
        'siteDelete' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::site_delete',
    ),
    'Webactueel\WordPressConnector\Adapters\WooCommerceAdapter' => array(
        'orderList' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::order_list',
        'orderGet' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::order_get',
        'taxClassList' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::tax_class_list',
        'productList' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::product_list',
        'productGet' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::product_get',
        'productCreate' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::product_create',
        'productUpdate' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::product_update',
        'variationList' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::variation_list',
        'variationGet' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::variation_get',
        'variationCreate' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::variation_create',
        'variationUpdate' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::variation_update',
        'attributeList' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::attribute_list',
        'attributeCreate' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::attribute_create',
        'attributeUpdate' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::attribute_update',
        'attributeDelete' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::attribute_delete',
        'couponList' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::coupon_list',
        'couponGet' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::coupon_get',
        'couponCreate' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::coupon_create',
        'couponUpdate' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::coupon_update',
    ),
    'Webactueel\WordPressConnector\Adapters\SiteEditorAdapter' => array(
        'listTemplates' => 'Webactueel\WordPressConnector\Adapters\SiteEditorActions::list_templates',
        'globalStyles' => 'Webactueel\WordPressConnector\Adapters\SiteEditorActions::global_styles',
    ),
    'Webactueel\WordPressConnector\Runtime\Request' => array(
        'fromFile' => 'Webactueel\WordPressConnector\Runtime\RequestMethods::from_file',
        'fromArray' => 'Webactueel\WordPressConnector\Runtime\RequestMethods::from_array',
        'dryRun' => 'Webactueel\WordPressConnector\Runtime\RequestMethods::dry_run',
        'expectedFingerprint' => 'Webactueel\WordPressConnector\Runtime\RequestMethods::expected_fingerprint',
        'expectedStateToken' => 'Webactueel\WordPressConnector\Runtime\RequestMethods::expected_state_token',
    ),
    'Webactueel\WordPressConnector\Security\FilesystemPolicy' => array(
        'assertListable' => 'Webactueel\WordPressConnector\Security\FilesystemPolicyMethods::assert_listable',
        'assertReadableText' => 'Webactueel\WordPressConnector\Security\FilesystemPolicyMethods::assert_readable_text',
        'assertWritableText' => 'Webactueel\WordPressConnector\Security\FilesystemPolicyMethods::assert_writable_text',
        'assertLifecycleText' => 'Webactueel\WordPressConnector\Security\FilesystemPolicyMethods::assert_lifecycle_text',
        'affectsBrowserExecution' => 'Webactueel\WordPressConnector\Security\FilesystemPolicyMethods::affects_browser_execution',
        'isWritableText' => 'Webactueel\WordPressConnector\Security\FilesystemPolicyMethods::is_writable_text',
    ),
    'Webactueel\WordPressConnector\Support\Fingerprint' => array(
        'siteToken' => 'Webactueel\WordPressConnector\Support\FingerprintMethods::site_token',
        'siteTokenFromFingerprint' => 'Webactueel\WordPressConnector\Support\FingerprintMethods::site_token_from_fingerprint',
    ),
);

foreach ($serviceAliases as $className => $aliases) {
    $reflection = new ReflectionClass($className);
    if ($reflection->getTraitAliases() !== $aliases) {
        throw new RuntimeException('Service method alias contract changed: '.$className);
    }
    foreach ($aliases as $legacy => $implementation) {
        $canonical = substr($implementation, strrpos($implementation, '::') + 2);
        $old = new ReflectionMethod($className, $legacy);
        $new = new ReflectionMethod($className, $canonical);
        // PHP 7.4 reflects the original trait method name for native aliases.
        $reflectedName = PHP_VERSION_ID < 80000 ? $canonical : $legacy;
        if (!method_exists($className, $legacy) || !method_exists($className, $canonical) ||
            !$old->isPublic() || $old->getName() !== $reflectedName ||
            $old->getDeclaringClass()->getName() !== $className ||
            $old->isStatic() !== $new->isStatic() ||
            (string)$old->getReturnType() !== (string)$new->getReturnType() ||
            count($old->getParameters()) !== count($new->getParameters())) {
            throw new RuntimeException('Service method signature changed: '.$className.'::'.$legacy);
        }
        foreach ($old->getParameters() as $index => $parameter) {
            $other = $new->getParameters()[$index];
            if ($parameter->getName() !== $other->getName() ||
                (string)$parameter->getType() !== (string)$other->getType() ||
                $parameter->isOptional() !== $other->isOptional() ||
                $parameter->isPassedByReference() !== $other->isPassedByReference() ||
                $parameter->isDefaultValueAvailable() !== $other->isDefaultValueAvailable() ||
                ($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() !== $other->getDefaultValue())) {
                throw new RuntimeException('Service named argument contract changed: '.$legacy);
            }
        }
    }
}


use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Support\Fingerprint;

$fingerprint = Fingerprint::make(array('post_id' => 10, 'data' => array('x' => 1)));
$token = Fingerprint::siteTokenFromFingerprint($fingerprint);
if (! preg_match('/^[a-f0-9]{64}\z/', $token)) {
    fwrite(STDERR, "Site state token is not a SHA-256 HMAC.\n");
    exit(1);
}

$request = Request::fromArray(array(
    'version' => 1,
    'request_id' => 'state-token-001',
    'action' => 'elementor.replace_document',
    'payload' => array('id' => 10),
    'dry_run' => false,
    'confirm' => true,
    'expected_fingerprint' => $fingerprint,
    'expected_state_token' => $token,
));
if ($request->expectedStateToken() !== $token || $request->expectedFingerprint() !== $fingerprint) {
    fwrite(STDERR, "Request state guards were not preserved.\n");
    exit(1);
}

try {
    Request::fromArray(array(
        'version' => 1,
        'request_id' => 'state-token-002',
        'action' => 'post.update',
        'payload' => array('id' => 10),
        'dry_run' => true,
        'confirm' => false,
        'expected_state_token' => 'bad',
    ));
    fwrite(STDERR, "Invalid expected_state_token was accepted.\n");
    exit(1);
} catch (RuntimeException $error) {
}

echo "state token contract OK\n";
