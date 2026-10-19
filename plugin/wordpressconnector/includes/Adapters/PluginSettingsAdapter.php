<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class PluginSettingsAdapter
{
	private const WP_ROCKET_FIELDS = array(
		'emoji' => 'bool',
		'minify_google_fonts' => 'bool',
		'cache_mobile' => 'bool',
		'do_caching_mobile_files' => 'bool',
		'cache_logged_user' => 'bool',
		'purge_cron_interval' => 'positive_int',
		'purge_cron_unit' => 'rocket_cron_unit',
		'minify_css' => 'bool',
		'async_css' => 'bool',
		'remove_unused_css' => 'bool',
		'minify_js' => 'bool',
		'minify_concatenate_js' => 'bool',
		'defer_all_js' => 'bool',
		'delay_js' => 'bool',
		'lazyload' => 'bool',
		'lazyload_iframes' => 'bool',
		'lazyload_css_bg_img' => 'bool',
		'lazyload_youtube' => 'bool',
		'image_dimensions' => 'bool',
		'manual_preload' => 'bool',
		'preload_links' => 'bool',
		'cdn' => 'bool',
		'cdn_cnames' => 'hostname_list',
		'varnish_auto_purge' => 'bool',
		'cache_webp' => 'bool',
	);

	private const JOINCHAT_FIELDS = array(
		'telephone' => 'phone',
		'mobile_only' => 'yes_no',
		'button_tip' => 'short_text',
		'button_delay' => 'delay',
		'whatsapp_web' => 'yes_no',
		'message_text' => 'message_text',
		'message_send' => 'message_text',
		'message_start' => 'short_text',
		'position' => 'position',
		'tracking' => 'yes_no',
		'show_brand' => 'yes_no',
		'color' => 'color',
	);

	private const INTEGRATIONS = array(
		'acf-content-analysis-for-yoast-seo/yoast-acf-analysis.php' => array('id' => 'acf_yoast_analysis', 'mode' => 'covered_by_acf_and_yoast', 'actions' => array('acf.get', 'acf.update', 'yoast.inspect', 'yoast.update')),
		'acf-page-text-manager/acf-page-text-manager.php' => array('id' => 'acf_page_text_manager', 'mode' => 'project_specific', 'actions' => array('acf.get', 'acf.update')),
		'advanced-custom-fields/acf.php' => array('id' => 'acf', 'mode' => 'native_adapter', 'actions' => array('acf.field_groups', 'acf.get', 'acf.update')),
		'all-in-one-wp-security-and-firewall/wp-security.php' => array('id' => 'aios', 'mode' => 'security_provider_contract_needed', 'actions' => array(), 'note' => 'AIOS firewall, login protection and 2FA require version-specific safe APIs and lockout recovery before settings writes.'),
		'woocommerce/woocommerce.php' => array('id' => 'woocommerce', 'mode' => 'native_risk_gated', 'actions' => array('woocommerce.product.list', 'woocommerce.product.get', 'woocommerce.product.create', 'woocommerce.product.update', 'woocommerce.variation.list', 'woocommerce.variation.create', 'woocommerce.settings.catalog', 'woocommerce.settings.inspect', 'woocommerce.settings.section.inspect', 'woocommerce.settings.update', 'woocommerce.operations.list', 'woocommerce.operations.inspect', 'woocommerce.operations.update', 'woocommerce.fulfillment.locations.inspect', 'woocommerce.fulfillment.locations.update', 'woocommerce.fulfillment.create'), 'note' => 'Installed connector version determines availability. High-risk shipping, tax and payment writes require tested restore and site-local gates.'),
		'mollie-payments-for-woocommerce/mollie-payments-for-woocommerce.php' => array('id' => 'mollie', 'mode' => 'woocommerce_gateway_limited', 'actions' => array('woocommerce.operations.list', 'woocommerce.operations.inspect', 'woocommerce.operations.update'), 'note' => 'Only WooCommerce-exposed gateway fields; live/test mode, payment credentials and actual payments require provider-native authenticated setup and sandbox verification.'),
		'ewww-image-optimizer/ewww-image-optimizer.php' => array('id' => 'ewww_image_optimizer', 'mode' => 'guarded_settings', 'actions' => array('optimizer.settings.inspect', 'optimizer.settings.update'), 'note' => 'EWWW 8.x lazy loading and image conversion cannot be safely changed through arbitrary options. Avoid duplicate lazy loading with WP Rocket.'),
		'broken-link-checker-seo/aioseo-broken-link-checker.php' => array('id' => 'aioseo_broken_link_checker', 'mode' => 'external_account_bound', 'actions' => array(), 'note' => 'AIOSEO Broken Link Checker scans through the vendor service; it is not a local-only link scanner and should not be connected when local-only processing is required.'),

		'all-in-one-wp-migration/all-in-one-wp-migration.php' => array('id' => 'all_in_one_wp_migration', 'mode' => 'system_operation', 'actions' => array(), 'note' => 'Backup/import/export operations require a separate high-risk staging-first contract.'),
		'wp-asset-clean-up/wpacu.php' => array('id' => 'asset_cleanup', 'mode' => 'guarded_settings', 'actions' => array('optimizer.settings.inspect', 'optimizer.settings.update', 'maintenance.cache_capabilities', 'maintenance.cache_flush'), 'note' => 'Cache maintenance is supported; asset unload/settings writes require an installed-version and browser regression contract.'),
		'auto-image-attributes-from-filename-with-bulk-updater/iaff_image-attributes-from-filename.php' => array('id' => 'auto_image_attributes', 'mode' => 'native_allowlist', 'actions' => array('auto_image_attributes.inspect', 'auto_image_attributes.update', 'media.get', 'media.update')),
		'broken-link-checker/broken-link-checker.php' => array('id' => 'broken_link_checker', 'mode' => 'version_bound', 'actions' => array(), 'note' => 'Cloud/local engines and account-bound state require an exact installed-version contract before writes are exposed.'),
		'code-snippets/code-snippets.php' => array('id' => 'code_snippets', 'mode' => 'bounded_exact_patch_and_catalog_preset', 'actions' => array('code_snippets.patch', 'code_snippets.catalog_only.inspect', 'code_snippets.catalog_only.ensure', 'code_snippets.catalog_only.undo'), 'note' => 'Caller-supplied arbitrary PHP creation remains blocked. One fixed vetted WooCommerce catalogue-only preset supports inspection, guarded creation/activation and rollback; existing snippets support bounded exact replacements.'),
		'content-sync-manager/content-sync-manager.php' => array('id' => 'content_sync_manager', 'mode' => 'project_specific', 'actions' => array()),
		'classic-editor/classic-editor.php' => array('id' => 'classic_editor', 'mode' => 'core_editor_option', 'actions' => array(), 'note' => 'A dedicated editor-preference contract is required; no raw options are exposed.'),
		'duplicate-page/duplicatepage.php' => array('id' => 'duplicate_page', 'mode' => 'no_special_adapter_needed', 'actions' => array('post.get', 'post.create')),
		'elementor/elementor.php' => array('id' => 'elementor', 'mode' => 'native_adapter', 'actions' => array('elementor.capabilities', 'elementor.inspect', 'elementor.inventory', 'elementor.patch_element', 'elementor.replace_document')),
		'elementor-pro/elementor-pro.php' => array('id' => 'elementor_pro', 'mode' => 'native_adapter', 'actions' => array('elementor.capabilities', 'elementor.inspect', 'elementor.inventory', 'elementor.patch_element', 'elementor.replace_document', 'elementor.form_inspect', 'elementor.form_upsert')),
		'elementor-pro-2/elementor-pro.php' => array('id' => 'elementor_pro', 'mode' => 'native_adapter', 'actions' => array('elementor.capabilities', 'elementor.inspect', 'elementor.inventory', 'elementor.patch_element', 'elementor.replace_document', 'elementor.form_inspect', 'elementor.form_upsert')),
		'gtranslate/gtranslate.php' => array('id' => 'gtranslate', 'mode' => 'native_allowlist', 'actions' => array('gtranslate.settings.inspect', 'gtranslate.settings.update'), 'note' => 'Only GTranslate 5.0.x NL/EN language selection and browser detection; preserves unrelated premium, CSS and domain options.'),
		'hostinger-ai-assistant/hostinger-ai-assistant.php' => array('id' => 'hostinger_ai', 'mode' => 'external_account_bound', 'actions' => array(), 'note' => 'Hostinger AI provider features, AI requests and account credentials require separately verified authenticated interfaces; audit is inventory-only.'),
		'hostinger-easy-onboarding/hostinger-easy-onboarding.php' => array('id' => 'hostinger_easy_onboarding', 'mode' => 'onboarding_observation_only', 'actions' => array(), 'note' => 'Hostinger onboarding is detected only; readiness and vendor progress state cannot be inferred from plugin activation.'),
		'hostinger-reach/hostinger-reach.php' => array('id' => 'hostinger_reach', 'mode' => 'external_account_bound', 'actions' => array(), 'note' => 'Hostinger Reach email-marketing integration requires a vendor account and consent-aware end-to-end verification. Never export contacts, tokens or campaign settings.'),
		'hostinger/hostinger.php' => array('id' => 'hostinger_tools', 'mode' => 'security_provider_contract_needed', 'actions' => array(), 'note' => 'Hostinger Tools maintenance, cache, XML-RPC, HTTPS redirects and hosting service state are not safe to mutate without provider-backed inspection and lockout recovery.'),
		'imagify/imagify.php' => array('id' => 'imagify', 'mode' => 'wordpress_ability', 'actions' => array('plugin.settings.inspect', 'plugin.settings.update')),
		'creame-whatsapp-me/joinchat.php' => array('id' => 'joinchat', 'mode' => 'native_allowlist', 'actions' => array('plugin.settings.inspect', 'plugin.settings.update'), 'note' => 'Only a bounded set of nonsecret options for Joinchat 6.4.x; tracking, phone and button/message settings only.'),
		'litespeed-cache/litespeed-cache.php' => array('id' => 'litespeed_cache', 'mode' => 'inactive_or_version_bound', 'actions' => array()),
		'loco-translate/loco.php' => array('id' => 'loco_translate', 'mode' => 'filesystem_bound', 'actions' => array(), 'note' => 'Translation-file authoring needs its own translation-aware validation rather than raw file writes.'),
		'redirection/redirection.php' => array('id' => 'redirection', 'mode' => 'provider_api_required', 'actions' => array(), 'note' => 'Redirect writes require a dedicated provider-owned API contract and redirect-loop validation.'),
		'under-construction-page/under-construction.php' => array('id' => 'under_construction', 'mode' => 'maintenance_gate', 'actions' => array(), 'note' => 'Maintenance settings and public REST visibility require a dedicated security-reviewed contract; never bypass upstream HTTP 403.'),
		'really-simple-ssl/rlrsssl-really-simple-ssl.php' => array('id' => 'really_simple_security', 'mode' => 'native_settings_api', 'actions' => array('plugin.settings.inspect', 'plugin.settings.update')),
		'google-site-kit/google-site-kit.php' => array('id' => 'site_kit', 'mode' => 'oauth_bound', 'actions' => array(), 'note' => 'OAuth tokens, service connections and encrypted settings are intentionally not exported through GitHub.'),
		'wordfence/wordfence.php' => array('id' => 'wordfence', 'mode' => 'limited_stable_api', 'actions' => array(), 'note' => 'Broad settings writes are withheld until stable public interfaces and safe allowlists are proven.'),
		'wordpressconnector/wordpressconnector.php' => array('id' => 'wordpress_connector', 'mode' => 'canonical_bridge', 'actions' => array('connector.discover', 'connector.actions', 'connector.update.check', 'connector.update.apply')),
		'wp-file-manager/file_folder_manager.php' => array(
			'id' => 'wp_file_manager',
			'mode' => 'shared_filesystem_interface',
			'actions' => array('filesystem.inspect', 'filesystem.list', 'filesystem.read_text', 'filesystem.write_text'),
			'note' => 'WP File Manager remains the visual admin UI. The connector does not reuse its private AJAX/elFinder protocol; both operate on the same WordPress filesystem, with connector writes bounded to existing plugin/theme text files.',
		),
		'wp-mail-smtp/wp_mail_smtp.php' => array('id' => 'wp_mail_smtp', 'mode' => 'secret_bound', 'actions' => array(), 'note' => 'SMTP passwords, OAuth tokens and provider secrets are never exported through GitHub.'),
		'wp-rocket/wp-rocket.php' => array('id' => 'wp_rocket', 'mode' => 'native_settings_api', 'actions' => array('plugin.settings.inspect', 'plugin.settings.update', 'maintenance.cache_flush')),
		'wordpress-seo/wp-seo.php' => array('id' => 'yoast', 'mode' => 'native_adapter', 'actions' => array('yoast.inspect', 'yoast.update', 'yoast.templates.inspect', 'yoast.templates.update', 'yoast.site_representation.inspect', 'yoast.site_representation.update')),
		'wordpress-seo-premium/wp-seo-premium.php' => array('id' => 'yoast_premium', 'mode' => 'native_adapter', 'actions' => array('yoast.inspect', 'yoast.update', 'yoast.templates.inspect', 'yoast.templates.update', 'yoast.site_representation.inspect', 'yoast.site_representation.update')),
	);

	public function register(Registry $registry): void
	{
		$registry->register('plugin.settings.catalog', array($this, 'catalog'), array(
			'privileged' => true,
			'description' => 'List installed plugins and their safe ChatGPT/GitHub control mode without exposing secrets.',
		));
		$registry->register('plugin.audit.inspect', array($this, 'audit'), array(
			'privileged' => true,
			'public_repository_safe' => true,
			'capability' => 'manage_options',
			'description' => 'Read-only inventory of every installed plugin and narrowly scoped nonsecret checks; no raw settings, passwords or versions are exported.',
		));
		$registry->register('plugin.settings.inspect', array($this, 'inspect'), array(
			'privileged' => true,
			'description' => 'Read allowlisted settings through a plugin-owned API and return a state fingerprint.',
		));
		$registry->register('plugin.settings.update', array($this, 'update'), array(
			'mutation' => true,
			'privileged' => true,
			'description' => 'Update allowlisted plugin settings through plugin-owned APIs with dry-run, readback and rollback.',
		));
	}

	/**
	 * Inspect every installed plugin without exporting provider configuration.
	 *
	 * The existing catalog is the single source of plugin identity, presence,
	 * activation state, declared integration mode and registered capabilities.
	 * Only explicitly modeled nonsecret indicators are read below. Anything
	 * else remains inventory-only rather than pretending to be an audit pass.
	 */
	public function audit(array $payload = array(), array $context = array()): array
	{
		if ($payload) {
			throw new RuntimeException('Plugin audit accepts no arguments or arbitrary option keys.');
		}
		if (Policy::publicRepositoryContext()
			&& (empty($context['dry_run']) || ! empty($context['confirm']))) {
			throw new RuntimeException('Public plugin audit is strictly read-only.');
		}
		if (! function_exists('current_user_can') || ! current_user_can('manage_options')) {
			throw new RuntimeException('Plugin audit requires manage_options.');
		}

		$inventory = $this->catalog(array(), $context);
		$installed = (array) ($inventory['plugins'] ?? array());
		if (count($installed) > 150) {
			throw new RuntimeException('Plugin inventory exceeds the bounded audit limit.');
		}

		$items = array();
		$activeCount = 0;
		$unknownCount = 0;
		$checkedCount = 0;
		$activeIds = array();
		$indicators = array();
		foreach ($installed as $plugin) {
			$known = (string) ($plugin['mode'] ?? '') !== 'unmodeled';
			$id = $known ? (string) ($plugin['id'] ?? '') : 'unmodeled_' . (++$unknownCount);
			if (! preg_match('/^[a-z][a-z0-9_-]{1,79}\\z/', $id)) {
				throw new RuntimeException('Plugin integration identifier is invalid.');
			}
			$active = true === ($plugin['active'] ?? null);
			$activeCount += $active ? 1 : 0;
			if ($active) {
				$activeIds[$id] = true;
			}

			$registration = (string) ($plugin['registered_action_status'] ?? 'not_checked');
			$coverage = !$known ? 'unmodeled'
				: (empty($plugin['actions']) ? 'inventory_only'
					: ($registration === 'complete' ? 'registered'
						: ($registration === 'partial' ? 'partial' : 'not_checked')));
			// Provider-version restrictions take precedence over generic action registration.
			if ($known && in_array((string) ($plugin['mode'] ?? ''), array(
				'version_bound_unsupported', 'optimizer_version_unsupported',
				'security_provider_contract_needed', 'external_account_bound',
				'secret_bound', 'filesystem_bound', 'system_operation',
			), true)) {
				$coverage = 'restricted';
			}

			$checks = array();
			if ($active) {
				try {
					$checks = $this->auditNonsecretChecks($id, (string) ($plugin['mode'] ?? ''));
				} catch (\Throwable $error) {
					// One provider failure must not erase the rest of the audit.
					// Do not serialize the exception: provider errors may include secrets.
					$checks = array('provider_probe' => 'error');
				}
			}
			if ($checks) {
				++$checkedCount;
				$indicators[$id] = $checks;
			}
			$items[] = array(
				'id' => $id,
				'active' => $active,
				'coverage' => $coverage,
				'checks' => $checks,
			);
		}

		// These indicators concern configuration only, not tested frontend behavior.
		$lazyloadOverlap = 'unverified';
		if (isset($activeIds['wp_rocket'], $activeIds['ewww_image_optimizer'])
			&& isset($indicators['wp_rocket']['image_lazyload'], $indicators['ewww_image_optimizer']['image_lazyload'])) {
			$rocket = $indicators['wp_rocket']['image_lazyload'];
			$ewww = $indicators['ewww_image_optimizer']['image_lazyload'];
			if ('enabled' === $rocket && 'enabled' === $ewww) {
				$lazyloadOverlap = 'possible_overlap';
			} elseif (('enabled' === $rocket || 'disabled' === $rocket)
				&& ('enabled' === $ewww || 'disabled' === $ewww)) {
				$lazyloadOverlap = 'no_overlap_observed';
			}
		}

		return array(
			'installed_count' => count($items),
			'must_use_count' => $this->auditCorePluginCount('get_mu_plugins'),
			'dropin_count' => $this->auditCorePluginCount('get_dropins'),
			'active_count' => $activeCount,
			'inactive_count' => count($items) - $activeCount,
			'unmodeled_count' => $unknownCount,
			'provider_checks_count' => $checkedCount,
			'plugins' => $items,
			'image_lazyload_overlap' => $lazyloadOverlap,
			'checkout_qa' => 'not_run',
			'smtp_delivery_qa' => 'not_run',
			'translation_browser_qa' => 'not_run',
			'private_settings_exported' => false,
		);
	}

	/**
	 * All keys and all values in these checks are fixed enumerations.
	 * Never return plugin-header text, unrestricted option data, credentials,
	 * SMTP addresses, firewall rules, payment settings or source file paths.
	 */
	private function auditNonsecretChecks(string $id, string $mode): array
	{
		if (! function_exists('get_option')) {
			return array();
		}
		if ('woocommerce' === $id) {
			return array(
				'guest_checkout' => $this->auditToggle(get_option('woocommerce_enable_guest_checkout', null)),
				'stock_management' => $this->auditToggle(get_option('woocommerce_manage_stock', null)),
				'cart_page' => $this->auditPageAssignment(get_option('woocommerce_cart_page_id', null)),
				'checkout_page' => $this->auditPageAssignment(get_option('woocommerce_checkout_page_id', null)),
			);
		}
		if ('gtranslate' === $id) {
			$raw = get_option('GTranslate', null);
			if (! is_array($raw)) {
				return array('english_source' => 'unknown', 'nl_en_languages' => 'unknown');
			}
			$languages = $raw['incl_langs'] ?? null;
			$fallback = $raw['fincl_langs'] ?? null;
			return array(
				'english_source' => ! isset($raw['default_language']) ? 'unknown'
					: ('en' === $raw['default_language'] ? 'matches' : 'mismatch'),
				'nl_en_languages' => ! is_array($languages) || ! is_array($fallback) ? 'unknown'
					: ($languages === array('nl', 'en') && $fallback === array('nl', 'en') ? 'matches' : 'mismatch'),
			);
		}
		if ('yoast' === $id || 'yoast_premium' === $id) {
			$raw = get_option('wpseo_titles', null);
			$title = get_option('blogname', null);
			if (! is_array($raw)) {
				return array('organization_name' => 'unknown', 'organization_description' => 'unknown', 'organization_logo' => 'unknown');
			}
			$company = $raw['company_name'] ?? null;
			return array(
				'organization_name' => ! is_string($title) || '' === trim($title) || ! is_string($company) || '' === trim($company)
					? 'unknown' : (0 === strcasecmp(trim($title), trim($company)) ? 'matches' : 'mismatch'),
				'organization_description' => $this->auditTextPresence($raw['org-description'] ?? null),
				'organization_logo' => $this->auditPageAssignment($raw['company_logo_id'] ?? null),
			);
		}
		if ('joinchat' === $id) {
			$raw = get_option('joinchat', null);
			if (! is_array($raw)) {
				return array('button_tip_saved' => 'unknown', 'message_saved' => 'unknown');
			}
			// Saved values are not necessarily the effective default/translations.
			return array(
				'button_tip_saved' => $this->auditTextPresence($raw['button_tip'] ?? null),
				'message_saved' => $this->auditTextPresence($raw['message_text'] ?? null),
				'whatsapp_number' => $this->auditPhonePresence($raw['telephone'] ?? null),
			);
		}
		if ('wp_rocket' === $id && function_exists('get_rocket_option')) {
			return array(
				'image_lazyload' => $this->auditToggle(get_rocket_option('lazyload', null)),
				'remove_unused_css' => $this->auditToggle(get_rocket_option('remove_unused_css', null)),
			);
		}
		if ('ewww_image_optimizer' === $id && 'guarded_settings' === $mode
			&& function_exists('ewww_image_optimizer_get_option')) {
			return array(
				'image_lazyload' => $this->auditToggle(ewww_image_optimizer_get_option('ewww_image_optimizer_lazy_load', null)),
				'missing_dimensions' => $this->auditToggle(ewww_image_optimizer_get_option('ewww_image_optimizer_add_missing_dims', null)),
			);
		}
		if ('asset_cleanup' === $id && 'inspect_only_provider_api_required' === $mode) {
			// Stored settings are not proof of Asset CleanUp effective runtime settings.
			$raw = get_option('wpassetcleanup_settings', null);
			return array('settings_record' => null === $raw ? 'missing' : 'present', 'effective_settings' => 'unverified');
		}
		if ('wp_mail_smtp' === $id) {
			// Never inspect, copy or hash the credential-containing option value.
			$raw = get_option('wp_mail_smtp', null);
			return array('settings_record' => is_array($raw) ? 'present' : 'unknown', 'delivery' => 'unverified');
		}
		return array();
	}

	private function auditToggle($value): string
	{
		if (in_array($value, array(true, 1, '1', 'yes', 'on'), true)) {
			return 'enabled';
		}
		if (in_array($value, array(false, 0, '0', 'no', 'off'), true)) {
			return 'disabled';
		}
		return 'unknown';
	}

	private function auditPageAssignment($value): string
	{
		if (is_int($value) || (is_string($value) && preg_match('/^[0-9]{1,10}\\z/', $value))) {
			return (int) $value > 0 ? 'present' : 'missing';
		}
		return 'unknown';
	}

	private function auditCorePluginCount(string $function): ?int
	{
		if (! in_array($function, array('get_mu_plugins', 'get_dropins'), true)
			|| ! function_exists($function)) {
			return null;
		}
		try {
			$plugins = $function();
		} catch (\Throwable $error) {
			return null;
		}
		if (! is_array($plugins) || count($plugins) > 150) {
			return null;
		}
		return count($plugins);
	}

	private function auditPhonePresence($value): string
	{
		if (! is_string($value)) {
			return null === $value ? 'missing' : 'unknown';
		}
		if ('' === trim($value)) {
			return 'missing';
		}
		if (strlen($value) > 32 || preg_match('/[^0-9+() .-]/', $value)) {
			return 'unknown';
		}
		$digits = preg_replace('/[^0-9]/', '', $value);
		return is_string($digits) && strlen($digits) >= 8 && strlen($digits) <= 15
			? 'present' : 'unknown';
	}

	private function auditTextPresence($value): string
	{
		return is_string($value) ? ('' === trim($value) ? 'missing' : 'present') : 'unknown';
	}

	public function catalog(array $payload = array(), array $context = array()): array
	{
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$registeredActions = isset($context['registry']) && $context['registry'] instanceof Registry
			? $context['registry']->catalog() : null;
		$items = array();
		foreach (get_plugins() as $file => $data) {
			$integration = self::INTEGRATIONS[$file] ?? array(
				'id' => sanitize_key((string) dirname($file)),
				'mode' => 'unmodeled',
				'actions' => array(),
				'note' => 'No safe plugin-specific write contract has been modeled yet.',
			);
			// Only advertise optimizer controls that the installed provider can actually support.
			if ('ewww-image-optimizer/ewww-image-optimizer.php' === $file) {
				if (! is_plugin_active($file) || ! preg_match('/^8\\.8\\.[0-9]+$/D', (string) ($data['Version'] ?? ''))) {
					$integration['mode'] = 'version_bound_unsupported';
					$integration['actions'] = array();
					$integration['note'] = 'EWWW optimizer actions require an active, verified 8.8.x provider.';
				}
			} elseif ('wp-asset-clean-up/wpacu.php' === $file) {
				if (! is_plugin_active($file)) {
					$integration['mode'] = 'inactive';
					$integration['actions'] = array();
					$integration['note'] = 'Asset CleanUp is inactive.';
				} elseif (! preg_match('/^1\\.4\\.0\\.[0-9]+$/D', (string) ($data['Version'] ?? ''))) {
					$integration['mode'] = 'optimizer_version_unsupported';
					$integration['actions'] = array('maintenance.cache_capabilities', 'maintenance.cache_flush');
					$integration['note'] = 'Optimizer inspection requires a verified 1.4.0.x provider; cache availability is checked independently.';
				} else {
					$integration['mode'] = 'inspect_only_provider_api_required';
					$integration['actions'] = array('optimizer.settings.inspect', 'maintenance.cache_capabilities', 'maintenance.cache_flush');
					$integration['note'] = 'Raw settings inspection is not effective-provider readback; optimizer writes are blocked pending provider API verification.';
				}
			}
			$item = array_merge(array(
				'file' => (string) $file,
				'name' => isset($data['Name']) ? (string) $data['Name'] : (string) $file,
				'version' => isset($data['Version']) ? (string) $data['Version'] : '',
				'active' => is_plugin_active($file),
			), $integration);
			if (null !== $registeredActions) {
				$missing = array_values(array_filter((array) $item['actions'], static function ($action) use ($registeredActions): bool {
					return ! is_string($action) || ! isset($registeredActions[$action]);
				}));
				$item['registered_action_status'] = ! $item['actions'] ? 'not_modeled' : ($missing ? 'partial' : 'complete');
				$item['missing_registered_actions'] = $missing;
			}
			$items[] = $item;
		}
		usort($items, static function (array $a, array $b): int {
			return strcasecmp((string) $a['name'], (string) $b['name']);
		});

		return array(
			'plugins' => $items,
			'supported_setting_profiles' => array('wp_rocket', 'imagify', 'really_simple_security', 'joinchat'),
			'security' => array(
				'raw_secret_export' => false,
				'arbitrary_code_settings' => false,
				'arbitrary_filesystem' => false,
				'bounded_filesystem_bridge' => true,
				'mutations_require_confirm' => true,
			),
		);
	}

	public function inspect(array $payload, array $context = array()): array
	{
		$plugin = $this->profile($payload);
		$settings = Policy::redact($this->readProfile($plugin));
		return array(
			'plugin' => $plugin,
			'settings' => $settings,
			'fingerprint' => Fingerprint::make($settings),
		);
	}

	public function update(array $payload, array $context): array
	{
		$plugin = $this->profile($payload);
		$updates = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
		if (! $updates) {
			throw new RuntimeException('plugin.settings.update requires payload.fields.');
		}

		$before = $this->readProfile($plugin);
		$clean = $this->validateUpdates($plugin, $updates, $before);
		$after = $before;
		foreach ($clean as $key => $value) {
			$after[$key] = $value;
		}

		$result = array(
			'plugin' => $plugin,
			'before' => Policy::redact($before),
			'after' => Policy::redact($after),
			'_current_fingerprint' => Fingerprint::make(Policy::redact($before)),
		);
		if (! empty($context['dry_run'])) {
			return $result;
		}

		$this->writeProfile($plugin, $clean);
		$readback = $this->readProfile($plugin);
		foreach ($clean as $key => $value) {
			if (! array_key_exists($key, $readback) || $this->normalizeForCompare($readback[$key]) !== $this->normalizeForCompare($value)) {
				throw new RuntimeException('Plugin settings readback verification failed for ' . $plugin . ':' . $key . '.');
			}
		}

		$rollback = array();
		foreach ($clean as $key => $value) {
			if (array_key_exists($key, $before)) {
				$rollback[$key] = $before[$key];
			}
		}
		$result['after'] = Policy::redact($readback);
		if ($rollback) {
			$result['_rollback'] = array(
				'action' => 'plugin.settings.update',
				'payload' => array('plugin' => $plugin, 'fields' => $rollback),
			);
		}
		return $result;
	}

	private function profile(array $payload): string
	{
		$plugin = isset($payload['plugin']) ? sanitize_key((string) $payload['plugin']) : '';
		if (! in_array($plugin, array('wp_rocket', 'imagify', 'really_simple_security', 'joinchat'), true)) {
			throw new RuntimeException('Unsupported plugin settings profile: ' . $plugin . '.');
		}
		return $plugin;
	}

	private function readProfile(string $plugin): array
	{
		if ('wp_rocket' === $plugin) {
			return $this->readWpRocket();
		}
		if ('imagify' === $plugin) {
			return $this->readImagify();
		}
		return 'joinchat' === $plugin ? $this->readJoinchat() : $this->readReallySimpleSecurity();
	}

	private function writeProfile(string $plugin, array $updates): void
	{
		if ('wp_rocket' === $plugin) {
			$this->writeWpRocket($updates);
			return;
		}
		if ('imagify' === $plugin) {
			$this->writeImagify($updates);
			return;
		}
		if ('joinchat' === $plugin) {
			$this->writeJoinchat($updates);
			return;
		}
		$this->writeReallySimpleSecurity($updates);
	}

	private function validateUpdates(string $plugin, array $updates, array $before): array
	{
		$clean = array();
		foreach ($updates as $key => $value) {
			if (! is_string($key) || '' === $key) {
				throw new RuntimeException('Plugin setting names must be non-empty strings.');
			}
			Policy::assertKeyAllowed($key);
			if (! array_key_exists($key, $before)) {
				throw new RuntimeException('Unsupported or unavailable ' . $plugin . ' setting: ' . $key . '.');
			}

			if ('wp_rocket' === $plugin) {
				if (! isset(self::WP_ROCKET_FIELDS[$key])) {
					throw new RuntimeException('Unsupported WP Rocket setting: ' . $key . '.');
				}
				$clean[$key] = $this->sanitizeWpRocketValue(self::WP_ROCKET_FIELDS[$key], $value);
				continue;
			}

			if ('joinchat' === $plugin) {
				if (! isset(self::JOINCHAT_FIELDS[$key])) {
					throw new RuntimeException('Unsupported Joinchat setting: ' . $key . '.');
				}
				$clean[$key] = $this->sanitizeJoinchatValue(self::JOINCHAT_FIELDS[$key], $value);
				continue;
			}

			if ('really_simple_security' === $plugin && $this->isHighRiskSecuritySetting($key) && ! Policy::flag('WPCONNECTOR_ALLOW_SENSITIVE')) {
				throw new RuntimeException('This Really Simple Security setting requires the connector sensitive-action gate: ' . $key . '.');
			}
			$clean[$key] = $this->sanitizeLike($before[$key], $value);
		}
		return $clean;
	}

	private function readWpRocket(): array
	{
		if (! function_exists('get_rocket_option') || ! function_exists('update_rocket_option')) {
			throw new RuntimeException('WP Rocket settings API is not available.');
		}
		$settings = array();
		foreach (self::WP_ROCKET_FIELDS as $key => $type) {
			$settings[$key] = get_rocket_option($key, null);
		}
		return $settings;
	}

	private function writeWpRocket(array $updates): void
	{
		if (! function_exists('update_rocket_option')) {
			throw new RuntimeException('WP Rocket settings API is not available.');
		}
		foreach ($updates as $key => $value) {
			update_rocket_option($key, $value);
		}
	}

	private function readImagify(): array
	{
		$settings = $this->executeAbility('imagify/get-settings', array());
		unset($settings['api_key'], $settings['version']);
		return $settings;
	}

	private function writeImagify(array $updates): void
	{
		unset($updates['api_key'], $updates['version']);
		$result = $this->executeAbility('imagify/update-settings', $updates);
		if (isset($result['status']) && 'error' === $result['status']) {
			throw new RuntimeException(isset($result['error_message']) ? (string) $result['error_message'] : 'Imagify settings update failed.');
		}
	}

	private function executeAbility(string $name, array $input): array
	{
		if (! function_exists('wp_get_ability')) {
			throw new RuntimeException('WordPress Abilities API is not available.');
		}
		$ability = wp_get_ability($name);
		if (! is_object($ability) || ! method_exists($ability, 'execute')) {
			throw new RuntimeException('Required WordPress ability is not available: ' . $name . '.');
		}
		$result = $ability->execute($input);
		if (is_wp_error($result)) {
			throw new RuntimeException($result->get_error_message());
		}
		if (! is_array($result)) {
			throw new RuntimeException('WordPress ability returned an unsupported result type: ' . $name . '.');
		}
		return Policy::redact($result);
	}


	private function readJoinchat(): array
	{
		if (! defined('JOINCHAT_VERSION') || ! preg_match('/^6\.4\.[0-9]+\z/', (string) JOINCHAT_VERSION) || ! function_exists('jc_common')) {
			throw new RuntimeException('Joinchat settings require an active supported 6.4.x installation.');
		}
		$provider = jc_common();
		if (! is_object($provider) || ! method_exists($provider, 'defaults')) {
			throw new RuntimeException('Joinchat default settings API is not available.');
		}
		$defaults = $provider->defaults();
		$stored = get_option('joinchat', array());
		if (! is_array($defaults) || ! is_array($stored)) {
			throw new RuntimeException('Joinchat settings are not an array.');
		}
		$effective = array_merge($defaults, $stored);
		$settings = array();
		foreach (self::JOINCHAT_FIELDS as $key => $type) {
			if (array_key_exists($key, $effective)) {
				$settings[$key] = $effective[$key];
			}
		}
		return $settings;
	}

	private function writeJoinchat(array $updates): void
	{
		$stored = get_option('joinchat', array());
		if (! is_array($stored)) {
			throw new RuntimeException('Joinchat settings are not an array.');
		}
		// Preserve all unrelated provider, premium and translated settings.
		update_option('joinchat', array_merge($stored, $updates));
	}

	private function sanitizeJoinchatValue(string $type, $value)
	{
		if ('yes_no' === $type) {
			if (! is_string($value) || ! in_array($value, array('yes', 'no'), true)) {
				throw new RuntimeException('Joinchat toggle must be yes or no.');
			}
			return $value;
		}
		if ('position' === $type) {
			if (! is_string($value) || ! in_array($value, array('left', 'right'), true)) {
				throw new RuntimeException('Joinchat position must be left or right.');
			}
			return $value;
		}
		if ('delay' === $type) {
			if (! is_int($value) || $value < 0 || $value > 30) {
				throw new RuntimeException('Joinchat button delay must be an integer between 0 and 30.');
			}
			return $value;
		}
		if ('phone' === $type) {
			if (! is_string($value) || ! preg_match('/^\+?[0-9().\s-]+\z/', $value)) {
				throw new RuntimeException('Joinchat telephone must contain only phone-number characters.');
			}
			$phone = preg_replace('/[^0-9]/', '', $value);
			if (strlen($phone) < 8 || strlen($phone) > 15) {
				throw new RuntimeException('Joinchat telephone must contain 8-15 digits.');
			}
			return $phone;
		}
		if ('color' === $type) {
			if (! is_string($value) || ! preg_match('/^#[0-9a-f]{6}(?:\/(?:0|100))?\z/i', $value)) {
				throw new RuntimeException('Joinchat color must be an RGB hex value with optional /0 or /100 contrast.');
			}
			return strtolower($value);
		}
		if ('short_text' === $type || 'message_text' === $type) {
			$max = 'short_text' === $type ? 40 : 800;
			if (! is_string($value) || strlen($value) > $max * 4) {
				throw new RuntimeException('Joinchat message must be a bounded UTF-8 string.');
			}
			$clean = sanitize_text_field($value);
			if (strlen($clean) > $max) {
				throw new RuntimeException('Joinchat text exceeds the field length limit.');
			}
			return $clean;
		}
		throw new RuntimeException('Unsupported Joinchat value type.');
	}

	private function readReallySimpleSecurity(): array
	{
		if (! function_exists('rsssl_fields') || ! function_exists('rsssl_get_option') || ! function_exists('rsssl_update_option')) {
			throw new RuntimeException('Really Simple Security settings API is not available.');
		}
		$settings = array();
		foreach ((array) rsssl_fields(true) as $field) {
			if (! is_array($field) || empty($field['id']) || ! is_string($field['id'])) {
				continue;
			}
			$id = sanitize_key($field['id']);
			if ('' === $id || $this->isSecretSetting($id)) {
				continue;
			}
			try {
				Policy::assertKeyAllowed($id);
			} catch (RuntimeException $error) {
				continue;
			}
			$value = rsssl_get_option($id, null);
			if (is_object($value) || is_resource($value)) {
				continue;
			}
			$settings[$id] = $value;
		}
		ksort($settings, SORT_STRING);
		return $settings;
	}

	private function writeReallySimpleSecurity(array $updates): void
	{
		if (! function_exists('rsssl_update_option')) {
			throw new RuntimeException('Really Simple Security settings API is not available.');
		}
		foreach ($updates as $key => $value) {
			rsssl_update_option($key, $value);
		}
	}

	private function sanitizeWpRocketValue(string $type, $value)
	{
		if ('bool' === $type) {
			return $this->toBoolInt($value);
		}
		if ('positive_int' === $type) {
			$value = (int) $value;
			if ($value < 1 || $value > 10080) {
				throw new RuntimeException('WP Rocket interval must be between 1 and 10080.');
			}
			return $value;
		}
		if ('rocket_cron_unit' === $type) {
			$value = strtoupper(sanitize_text_field((string) $value));
			if (! in_array($value, array('HOUR_IN_SECONDS', 'DAY_IN_SECONDS'), true)) {
				throw new RuntimeException('WP Rocket purge_cron_unit must be HOUR_IN_SECONDS or DAY_IN_SECONDS.');
			}
			return $value;
		}
		if ('hostname_list' === $type) {
			$values = is_array($value) ? $value : array($value);
			$clean = array();
			foreach ($values as $host) {
				$host = trim(sanitize_text_field((string) $host));
				if ('' === $host) {
					continue;
				}
				if ((bool) preg_match('/\s/', $host)) {
					throw new RuntimeException('WP Rocket CDN CNAME entries may not contain whitespace.');
				}
				$clean[] = $host;
			}
			return array_values(array_unique($clean));
		}
		throw new RuntimeException('Unsupported WP Rocket value type.');
	}

	private function sanitizeLike($current, $value)
	{
		if (is_bool($current)) {
			return (bool) $this->toBoolInt($value);
		}
		if (is_int($current)) {
			return (int) $value;
		}
		if (is_float($current)) {
			return (float) $value;
		}
		if (is_array($current)) {
			if (! is_array($value)) {
				throw new RuntimeException('Array setting requires an array value.');
			}
			return $this->sanitizeArray($value);
		}
		if (null === $current) {
			if (is_array($value)) {
				return $this->sanitizeArray($value);
			}
			if (is_bool($value) || is_int($value) || is_float($value)) {
				return $value;
			}
		}
		if (! is_scalar($value) && null !== $value) {
			throw new RuntimeException('Plugin setting values must be scalar, array or null.');
		}
		return null === $value ? null : sanitize_text_field((string) $value);
	}

	private function sanitizeArray(array $value): array
	{
		$clean = array();
		foreach ($value as $key => $item) {
			if (is_array($item)) {
				$clean[$key] = $this->sanitizeArray($item);
			} elseif (is_scalar($item) || null === $item) {
				$clean[$key] = is_string($item) ? sanitize_text_field($item) : $item;
			} else {
				throw new RuntimeException('Nested plugin setting values may not contain objects or resources.');
			}
		}
		return $clean;
	}

	private function toBoolInt($value): int
	{
		$normalized = strtolower((string) $value);
		if (true === $value || 1 === $value || '1' === $normalized || 'true' === $normalized || 'yes' === $normalized || 'on' === $normalized) {
			return 1;
		}
		if (false === $value || 0 === $value || '0' === $normalized || 'false' === $normalized || 'no' === $normalized || 'off' === $normalized) {
			return 0;
		}
		throw new RuntimeException('Boolean setting requires true/false or 1/0.');
	}

	private function normalizeForCompare($value): string
	{
		return (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	private function isSecretSetting(string $key): bool
	{
		return 1 === preg_match('/(password|passwd|secret|token|api[_-]?key|private[_-]?key|license|oauth|authorization|certificate|nonce|salt)/i', $key);
	}

	private function isHighRiskSecuritySetting(string $key): bool
	{
		return 1 === preg_match('/(firewall|two[_-]?fa|2fa|login|lockout|xmlrpc|user|role|admin|lets?encrypt|encrypt|hardening|vulnerab|file[_-]?edit|rest[_-]?api|hsts|csp|security[_-]?header)/i', $key);
	}
}
