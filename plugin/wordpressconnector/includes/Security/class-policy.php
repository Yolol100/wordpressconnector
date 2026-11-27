<?php
/**
 * Applies Policy safeguards to connector operations.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Security;

use RuntimeException;

require_once __DIR__ . '/trait-policymethods.php';

/**
 * Applies Policy safeguards to connector operations.
 */
final class Policy {
	use PolicyMethods {
		assert_action_allowed as assertActionAllowed;
		assert_public_content_target as assertPublicContentTarget;
		assert_readable_post_type as assertReadablePostType;
		assert_local_asset_path as assertLocalAssetPath;
		set_public_repository_context as setPublicRepositoryContext;
		assert_public_action_target as assertPublicActionTarget;
		assert_post_readable as assertPostReadable;
		assert_key_allowed as assertKeyAllowed;
		assert_meta_key_allowed as assertMetaKeyAllowed;
		assert_option_key_allowed as assertOptionKeyAllowed;
		public_repository_context as publicRepositoryContext;
	}

	private const SENSITIVE_POST_TYPES = array(
		'shop_order',
		'shop_order_refund',
		'shop_subscription',
		'wp_user_request',
	);

	private const BLOCKED_OPTION_KEYS = array(
		'active_plugins',
		'active_sitewide_plugins',
		'allowedthemes',
		'cron',
		'current_theme',
		'default_role',
		'home',
		'recently_activated',
		'rewrite_rules',
		'site_admins',
		'siteurl',
		'stylesheet',
		'template',
		'uninstall_plugins',
		'upload_path',
		'upload_url_path',
		'users_can_register',
		'wp_user_roles',
	);

	private const AUTO_ENABLED_FLAGS = array(
		'WPCONNECTOR_ALLOW_WRITES',
		'WPCONNECTOR_ALLOW_PRIVILEGED',
		'WPCONNECTOR_ALLOW_SENSITIVE',
		'WPCONNECTOR_ALLOW_SYSTEM_UPDATES',
		'WPCONNECTOR_ALLOW_FILESYSTEM_WRITES',
	);

	private const PUBLIC_REPOSITORY_ACTIONS = array(
		'post.list',
		'post.get',
		'post.update',
		'post.literal_replace',
		'plugin.audit.inspect',
		'site.identity.inspect',
		'site.identity.update',
		'gtranslate.settings.inspect',
		'gtranslate.settings.update',
		'yoast.site_representation.inspect',
		'yoast.site_representation.update',
		'yoast.snippet.inspect',
		'yoast.snippet.update',
		'yoast.templates.inspect',
		'yoast.templates.update',
		'acf.update',
		'acf.portfolio_stats_update',
		'portfolio.case_text_update',
		'acf.field_groups',
		'acf.schema.ensure_text_fields',
		'elementor.inspect',
		'elementor.patch_element',
		'elementor.header_links.replace',
		'elementor.header_links.inspect',
		'connector.batch',
		'connector.read_batch',
		'connector.rollback',
		'connector.update.check',
		'connector.update.apply',
		'code_snippets.patch',
		'maintenance.cache_capabilities',
		'maintenance.cache_flush',
		'custom_css.inspect',
		'custom_css.patch',
		'plugin.install_package',
		'theme.install_package',
	);

	/**
	 * Runtime public repository maintained by the connector.
	 *
	 * @var ?bool
	 */
	private static ?bool $runtime_public_repository = null;
	/**
	 * Request confirmed maintained by the connector.
	 *
	 * @var bool
	 */
	private static bool $request_confirmed = false;

	/**
	 * Process the redact operation in the Policy component.
	 *
	 * @param mixed $value Input for the operation.
	 *
	 * @return mixed Operation result.
	 */
	public static function redact( $value ) {
		if ( ! is_array( $value ) ) {
			return $value; }
		$redacted = array();
		foreach ( $value as $key => $item ) {
			$name = (string) $key;
			if ( (bool) preg_match( '/(password|passwd|secret|token|api[_-]?key|private[_-]?key|consumer_secret|authorization|cookie)/i', $name ) ) {
				$redacted[ $key ] = '[redacted]';
				continue;
			}
			$redacted[ $key ] = self::redact( $item );
		}
		return $redacted;
	}

	/**
	 * Process the flag operation in the Policy component.
	 *
	 * @param string $name Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	public static function flag( string $name ): bool {
		if ( defined( $name ) ) {
			return self::truthy( constant( $name ) ); }
		$environment = getenv( $name );
		if ( false !== $environment && '' !== (string) $environment ) {
			return self::truthy( $environment ); }
		if ( in_array( $name, self::AUTO_ENABLED_FLAGS, true ) ) {
			return true; }
		return false;
	}

	/**
	 * Process the truthy operation in the Policy component.
	 *
	 * @param mixed $value Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	private static function truthy( $value ): bool {
		if ( true === $value || 1 === $value ) {
			return true; }
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Process the sensitive export allowed operation in the Policy component.
	 *
	 * @return bool Operation result.
	 */
	private static function sensitive_export_allowed(): bool {
		return self::$request_confirmed && ! self::publicRepositoryContext() && self::flag( 'WPCONNECTOR_ALLOW_SENSITIVE' );
	}
}
