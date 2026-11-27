<?php

declare(strict_types=1);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

require_once __DIR__ . '/fixtures/wordpress-escape-fixture.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Support/class-json.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Support/class-fingerprint.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Runtime/class-request.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Security/class-filesystempolicy.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-siteeditoradapter.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-systemadapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-woocommerceadapter.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-acfadapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-elementoradapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-woocommercefulfillmentadapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-yoastadapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-abilitiesadapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-codesnippetsadapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Support/class-input.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Admin/class-elementorjsonexport.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-pluginpackageadapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-yoastsnippetadapter.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Admin/class-settings.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-mediaadapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Security/class-policy.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Security/class-sitepairing.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Security/class-githuboidc.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-connectorupdateadapter.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Admin/class-elementorjsonimport.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/MCP/class-controller.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/REST/class-controller.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Runtime/class-processedstore.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Security/class-oauthresourceserver.php';
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Security/class-releaseapproval.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-discoveryadapter.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-filesystemadapter.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-woocommercesettingsadapter.php';

if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/fixtures/'); }
require_once __DIR__ . '/../plugin/wordpressconnector/includes/Runtime/class-runner.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-portfoliostatsadapter.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/REST/class-assetstore.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Runtime/class-snapshotstore.php';

require_once __DIR__ . '/../plugin/wordpressconnector/includes/Adapters/class-cachemaintenanceadapter.php';

$serviceAliases = array(
    'Webactueel\WordPressConnector\Adapters\CacheMaintenanceAdapter' => array(
        'handleAssetCleanupLoopback' => 'Webactueel\WordPressConnector\Adapters\CacheMaintenanceAdapterMethods::handle_asset_cleanup_loopback',
    ),
    'Webactueel\WordPressConnector\Security\GitHubOidc' => array(
        'cleanupReplayClaims' => 'Webactueel\WordPressConnector\Security\GitHubOidcMethods::cleanup_replay_claims',
        'expectedAudience' => 'Webactueel\WordPressConnector\Security\GitHubOidcMethods::expected_audience',
    ),
    'Webactueel\WordPressConnector\Security\SitePairing' => array(
        'enableForAdministrator' => 'Webactueel\WordPressConnector\Security\SitePairingMethods::enable_for_administrator',
        'pairedAdministratorId' => 'Webactueel\WordPressConnector\Security\SitePairingMethods::paired_administrator_id',
        'approveOnActivation' => 'Webactueel\WordPressConnector\Security\SitePairingMethods::approve_on_activation',
    ),
    'Webactueel\WordPressConnector\Security\OAuthResourceServer' => array(
        'serveWellKnown' => 'Webactueel\WordPressConnector\Security\OAuthResourceServerMethods::serve_well_known',
        'isConfigured' => 'Webactueel\WordPressConnector\Security\OAuthResourceServerMethods::is_configured',
        'selfUpdateConfigured' => 'Webactueel\WordPressConnector\Security\OAuthResourceServerMethods::self_update_configured',
        'wellKnownUrl' => 'Webactueel\WordPressConnector\Security\OAuthResourceServerMethods::well_known_url',
        'registerRoutes' => 'Webactueel\WordPressConnector\Security\OAuthResourceServerMethods::register_routes',
        'resourceMetadata' => 'Webactueel\WordPressConnector\Security\OAuthResourceServerMethods::resource_metadata',
        'challengeHeader' => 'Webactueel\WordPressConnector\Security\OAuthResourceServerMethods::challenge_header',
        'canSelfUpdate' => 'Webactueel\WordPressConnector\Security\OAuthResourceServerMethods::can_self_update',
        'isAuthenticated' => 'Webactueel\WordPressConnector\Security\OAuthResourceServerMethods::is_authenticated',
    ),
    'Webactueel\WordPressConnector\Security\Policy' => array(
        'assertActionAllowed' => 'Webactueel\WordPressConnector\Security\PolicyMethods::assert_action_allowed',
        'assertPublicContentTarget' => 'Webactueel\WordPressConnector\Security\PolicyMethods::assert_public_content_target',
        'assertReadablePostType' => 'Webactueel\WordPressConnector\Security\PolicyMethods::assert_readable_post_type',
        'assertLocalAssetPath' => 'Webactueel\WordPressConnector\Security\PolicyMethods::assert_local_asset_path',
        'setPublicRepositoryContext' => 'Webactueel\WordPressConnector\Security\PolicyMethods::set_public_repository_context',
        'assertPublicActionTarget' => 'Webactueel\WordPressConnector\Security\PolicyMethods::assert_public_action_target',
        'assertPostReadable' => 'Webactueel\WordPressConnector\Security\PolicyMethods::assert_post_readable',
        'assertKeyAllowed' => 'Webactueel\WordPressConnector\Security\PolicyMethods::assert_key_allowed',
        'assertMetaKeyAllowed' => 'Webactueel\WordPressConnector\Security\PolicyMethods::assert_meta_key_allowed',
        'assertOptionKeyAllowed' => 'Webactueel\WordPressConnector\Security\PolicyMethods::assert_option_key_allowed',
        'publicRepositoryContext' => 'Webactueel\WordPressConnector\Security\PolicyMethods::public_repository_context',
    ),
    'Webactueel\WordPressConnector\Runtime\SnapshotStore' => array(
        'getRecord' => 'Webactueel\WordPressConnector\Runtime\SnapshotStoreMethods::get_record',
    ),
    'Webactueel\WordPressConnector\Runtime\ProcessedStore' => array(
        'mutationLockStatus' => 'Webactueel\WordPressConnector\Runtime\ProcessedStoreMethods::mutation_lock_status',
        'acquireMutationLock' => 'Webactueel\WordPressConnector\Runtime\ProcessedStoreMethods::acquire_mutation_lock',
        'releaseMutationLock' => 'Webactueel\WordPressConnector\Runtime\ProcessedStoreMethods::release_mutation_lock',
    ),
    'Webactueel\WordPressConnector\REST\AssetStore' => array(
        'rootForRequest' => 'Webactueel\WordPressConnector\REST\AssetStoreMethods::root_for_request',
        'cleanupExpired' => 'Webactueel\WordPressConnector\REST\AssetStoreMethods::cleanup_expired',
    ),
    'Webactueel\WordPressConnector\Support\Json' => array(
        'readFile' => 'Webactueel\WordPressConnector\Support\JsonMethods::read_file_compat',
        'writeFileAtomic' => 'Webactueel\WordPressConnector\Support\JsonMethods::write_file_atomic_compat',
    ),
    'Webactueel\WordPressConnector\Adapters\PortfolioStatsAdapter' => array(
        'updateCaseText' => 'Webactueel\WordPressConnector\Adapters\PortfolioStatsAdapterActions::update_case_text_compat',
    ),
    'Webactueel\WordPressConnector\Runtime\Runner' => array(
        'readBatch' => 'Webactueel\WordPressConnector\Runtime\RunnerMethods::read_batch_compat',
        'mutationStatus' => 'Webactueel\WordPressConnector\Runtime\RunnerMethods::mutation_status',
    ),
    'Webactueel\WordPressConnector\Adapters\WooCommerceSettingsAdapter' => array(
        'inspectSection' => 'Webactueel\WordPressConnector\Adapters\WooCommerceSettingsAdapterActions::inspect_section',
    ),
    'Webactueel\WordPressConnector\Adapters\FilesystemAdapter' => array(
        'readText' => 'Webactueel\WordPressConnector\Adapters\FilesystemAdapterActions::read_text_compat',
        'writeText' => 'Webactueel\WordPressConnector\Adapters\FilesystemAdapterActions::write_text_compat',
        'createText' => 'Webactueel\WordPressConnector\Adapters\FilesystemAdapterActions::create_text_compat',
        'deleteText' => 'Webactueel\WordPressConnector\Adapters\FilesystemAdapterActions::delete_text_compat',
        'listPath' => 'Webactueel\WordPressConnector\Adapters\FilesystemAdapterActions::list_path',
    ),
    'Webactueel\WordPressConnector\Adapters\DiscoveryAdapter' => array(
        'restCatalog' => 'Webactueel\WordPressConnector\Adapters\DiscoveryAdapterActions::rest_catalog',
    ),
    'Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapter' => array(
        'checkedNotice' => 'Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapterActions::checked_notice_compat',
        'nativeUpdateOffer' => 'Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapterActions::native_update_offer',
        'refreshNativeUpdateNoticeAction' => 'Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapterActions::refresh_native_update_notice_action',
        'refreshNativeUpdateNotice' => 'Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapterActions::refresh_native_update_notice',
        'pluginActionLinks' => 'Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapterActions::plugin_action_links',
        'forceNativeUpdateCheck' => 'Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapterActions::force_native_update_check',
        'verifiedNativeDownload' => 'Webactueel\WordPressConnector\Adapters\ConnectorUpdateAdapterActions::verified_native_download',
    ),
    'Webactueel\WordPressConnector\Admin\ElementorJsonImport' => array(
        'renderImportPage' => 'Webactueel\WordPressConnector\Admin\ElementorJsonImportMethods::render_import_page_compat',
        'handleImport' => 'Webactueel\WordPressConnector\Admin\ElementorJsonImportMethods::handle_import_compat',
        'rowActions' => 'Webactueel\WordPressConnector\Admin\ElementorJsonImportMethods::row_actions',
        'registerImportPages' => 'Webactueel\WordPressConnector\Admin\ElementorJsonImportMethods::register_import_pages',
        'renderBulkPage' => 'Webactueel\WordPressConnector\Admin\ElementorJsonImportMethods::render_bulk_page',
        'previewBulk' => 'Webactueel\WordPressConnector\Admin\ElementorJsonImportMethods::preview_bulk',
        'confirmBulk' => 'Webactueel\WordPressConnector\Admin\ElementorJsonImportMethods::confirm_bulk',
    ),
    'Webactueel\WordPressConnector\MCP\Controller' => array(
        'registerRoutes' => 'Webactueel\WordPressConnector\MCP\MCPControllerMethods::register_routes',
    ),
    'Webactueel\WordPressConnector\REST\Controller' => array(
        'allowPresence' => 'Webactueel\WordPressConnector\REST\RESTControllerMethods::allow_presence',
        'validateRequestId' => 'Webactueel\WordPressConnector\REST\RESTControllerMethods::validate_request_id',
        'registerRoutes' => 'Webactueel\WordPressConnector\REST\RESTControllerMethods::register_routes',
        'uploadAsset' => 'Webactueel\WordPressConnector\REST\RESTControllerMethods::upload_asset',
        'validateAssetPath' => 'Webactueel\WordPressConnector\REST\RESTControllerMethods::validate_asset_path',
    ),
    'Webactueel\WordPressConnector\Security\ReleaseApproval' => array(
        'renderForm' => 'Webactueel\WordPressConnector\Security\ReleaseApprovalMethods::render_form_compat',
        'idForFingerprint' => 'Webactueel\WordPressConnector\Security\ReleaseApprovalMethods::id_for_fingerprint',
    ),
    'Webactueel\WordPressConnector\Adapters\MediaAdapter' => array(
        'mediaRename' => 'Webactueel\WordPressConnector\Adapters\MediaAdapterActions::media_rename_compat',
        'mediaList' => 'Webactueel\WordPressConnector\Adapters\MediaAdapterActions::media_list',
        'mediaGet' => 'Webactueel\WordPressConnector\Adapters\MediaAdapterActions::media_get',
        'mediaImport' => 'Webactueel\WordPressConnector\Adapters\MediaAdapterActions::media_import',
        'mediaUpdate' => 'Webactueel\WordPressConnector\Adapters\MediaAdapterActions::media_update',
        'mediaAssign' => 'Webactueel\WordPressConnector\Adapters\MediaAdapterActions::media_assign',
        'mediaRegenerate' => 'Webactueel\WordPressConnector\Adapters\MediaAdapterActions::media_regenerate',
    ),
    'Webactueel\WordPressConnector\Admin\Settings' => array(
        'unpairedNotice' => 'Webactueel\WordPressConnector\Admin\SettingsMethods::unpaired_notice',
        'registerPage' => 'Webactueel\WordPressConnector\Admin\SettingsMethods::register_page',
        'enqueueConnectionDoctor' => 'Webactueel\WordPressConnector\Admin\SettingsMethods::enqueue_connection_doctor',
        'saveSitePairing' => 'Webactueel\WordPressConnector\Admin\SettingsMethods::save_site_pairing',
        'createApplicationPassword' => 'Webactueel\WordPressConnector\Admin\SettingsMethods::create_application_password',
        'renderPage' => 'Webactueel\WordPressConnector\Admin\SettingsMethods::render_page',
    ),
    'Webactueel\WordPressConnector\Adapters\AcfAdapter' => array(
        'ensureFields' => 'Webactueel\WordPressConnector\Adapters\AcfAdapterActions::ensure_fields_compat',
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
        'inspectLocations' => 'Webactueel\WordPressConnector\Adapters\WooCommerceFulfillmentAdapterActions::inspect_locations',
        'updateLocations' => 'Webactueel\WordPressConnector\Adapters\WooCommerceFulfillmentAdapterActions::update_locations',
        'restoreLocations' => 'Webactueel\WordPressConnector\Adapters\WooCommerceFulfillmentAdapterActions::restore_locations',
        'restoreCreated' => 'Webactueel\WordPressConnector\Adapters\WooCommerceFulfillmentAdapterActions::restore_created',
    ),
    'Webactueel\WordPressConnector\Adapters\YoastAdapter' => array(
        'inspectSiteRepresentation' => 'Webactueel\WordPressConnector\Adapters\YoastAdapterActions::inspect_site_representation',
        'updateSiteRepresentation' => 'Webactueel\WordPressConnector\Adapters\YoastAdapterActions::update_site_representation',
        'restoreSiteRepresentation' => 'Webactueel\WordPressConnector\Adapters\YoastAdapterActions::restore_site_representation',
    ),
    'Webactueel\WordPressConnector\Adapters\AbilitiesAdapter' => array(
        'readAbility' => 'Webactueel\WordPressConnector\Adapters\AbilitiesAdapterActions::read_ability',
        'executeAbility' => 'Webactueel\WordPressConnector\Adapters\AbilitiesAdapterActions::execute_ability',
        'executeApprovedAbility' => 'Webactueel\WordPressConnector\Adapters\AbilitiesAdapterActions::execute_approved_ability',
    ),
    'Webactueel\WordPressConnector\Adapters\CodeSnippetsAdapter' => array(
        'catalogOnlyInspect' => 'Webactueel\WordPressConnector\Adapters\CodeSnippetsAdapterActions::catalog_only_inspect',
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
        'registerThemePackage' => 'Webactueel\WordPressConnector\Adapters\PluginPackageAdapterActions::register_theme_package',
        'installThemePackage' => 'Webactueel\WordPressConnector\Adapters\PluginPackageAdapterActions::install_theme_package',
        'installPackage' => 'Webactueel\WordPressConnector\Adapters\PluginPackageAdapterActions::install_package',
    ),
    'Webactueel\WordPressConnector\Adapters\YoastSnippetAdapter' => array(
        'validateFields' => 'Webactueel\WordPressConnector\Adapters\YoastSnippetAdapterActions::validate_fields',
    ),
    'Webactueel\WordPressConnector\Adapters\SystemAdapter' => array(
        'cronRun' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::cron_run_compat',
        'pluginDirectorySearch' => 'Webactueel\WordPressConnector\Adapters\SystemAdapterActions::plugin_directory_search',
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
        'customerGet' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::customer_get_compat',
        'shippingZoneList' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::shipping_zone_list_compat',
        'shippingZoneGet' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::shipping_zone_get_compat',
        'taxRateList' => 'Webactueel\WordPressConnector\Adapters\WooCommerceAdapterActions::tax_rate_list_compat',
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
    $observedAliases = $reflection->getTraitAliases();
    ksort($observedAliases);
    ksort($aliases);
    if ($observedAliases !== $aliases) {
        throw new RuntimeException('Service method alias contract changed: '.$className);
    }
    foreach ($aliases as $legacy => $implementation) {
        $canonical = substr($implementation, strrpos($implementation, '::') + 2);
        $old = new ReflectionMethod($className, $legacy);
        $new = new ReflectionMethod($className, $canonical);
        if (substr($canonical, -7) === '_compat') {
            $implementationName = substr($canonical, 0, -7);
            $implementationMethod = new ReflectionMethod($className, $implementationName);
            if (!$new->isPrivate() || !$implementationMethod->isPublic() ||
                $new->isStatic() !== $implementationMethod->isStatic() ||
                (string)$new->getReturnType() !== (string)$implementationMethod->getReturnType() ||
                count($new->getParameters()) !== count($implementationMethod->getParameters())) {
                throw new RuntimeException('Legacy delegate does not have a canonical public implementation: '.$legacy);
            }
            foreach ($new->getParameters() as $i => $parameter) {
                $canonicalParameter = $implementationMethod->getParameters()[$i];
                if ($parameter->getName() !== $canonicalParameter->getName() ||
                    (string)$parameter->getType() !== (string)$canonicalParameter->getType() ||
                    $parameter->isOptional() !== $canonicalParameter->isOptional() ||
                    $parameter->isDefaultValueAvailable() !== $canonicalParameter->isDefaultValueAvailable() ||
                    ($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() !== $canonicalParameter->getDefaultValue())) {
                    throw new RuntimeException('Canonical delegate signature changed: '.$legacy);
                }
            }
            $lines = file($new->getFileName());
            if (!is_array($lines)) { throw new RuntimeException('Cannot inspect compatibility delegate.'); }
            $source = implode('', array_slice($lines, $new->getStartLine(), $new->getEndLine() - $new->getStartLine()));
            $args = implode(', ', array_map(static function ($p) { return '$'.$p->getName(); }, $new->getParameters()));
            $expected = ((string)$new->getReturnType() === 'void' ? '' : 'return ').
                ($new->isStatic() ? 'self::' : '$this->').$implementationName.'('.$args.');}';
            if (preg_replace('/\s+/', '', $source) !== preg_replace('/\s+/', '', $expected)) {
                throw new RuntimeException('Compatibility bridge contains non-delegating logic: '.$legacy);
            }
        }
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
