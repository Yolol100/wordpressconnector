<?php
/**
 * Applies Policy safeguards to connector operations.
 *
 * @package WordPressConnector
 */

declare( strict_types = 1 );

namespace Webactueel\WordPressConnector\Security;

use RuntimeException;

/**
 * Applies Policy safeguards to connector operations.
 */
final class Policy {
	private const SENSITIVE_POST_TYPES = array(
		'shop_order',
		'shop_order_refund',
		'shop_subscription',
		'wp_user_request',
	);

	private const BLOCKED_OPTION_KEYS = array(
		'active_plugins', 'active_sitewide_plugins', 'allowedthemes', 'cron', 'current_theme', 'default_role', 'home', 'recently_activated', 'rewrite_rules', 'site_admins', 'siteurl', 'stylesheet', 'template', 'uninstall_plugins', 'upload_path', 'upload_url_path', 'users_can_register', 'wp_user_roles',
	);

	private const AUTO_ENABLED_FLAGS = array(
		'WPCONNECTOR_ALLOW_WRITES', 'WPCONNECTOR_ALLOW_PRIVILEGED', 'WPCONNECTOR_ALLOW_SENSITIVE', 'WPCONNECTOR_ALLOW_SYSTEM_UPDATES', 'WPCONNECTOR_ALLOW_FILESYSTEM_WRITES',
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

	private static ?bool $runtimePublicRepository = null;
	private static bool $requestConfirmed = false;

	/**
	 * Ensure action allowed for the Policy component.
	 *
	 * @param array $descriptor Input for the operation.
	 * @param bool  $dryRun Input for the operation.
	 * @param bool  $confirm Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assertActionAllowed( array $descriptor, bool $dryRun, bool $confirm ): void {
		self::$requestConfirmed = $confirm;

		if ( self::publicRepositoryContext() ) {
			$action = isset( $descriptor['name'] ) ? (string) $descriptor['name'] : '';
			if ( ! in_array( $action, self::PUBLIC_REPOSITORY_ACTIONS, true ) ) {
				throw new RuntimeException( 'Action is not allowed in public-repository mode: ' . $action );
			}
		}

		if ( ! empty( $descriptor['sensitive'] ) ) {
			if ( self::publicRepositoryContext() ) {
				throw new RuntimeException( 'Sensitive actions are blocked in public-repository mode.' );
			}
			if ( ! self::flag( 'WPCONNECTOR_ALLOW_SENSITIVE' ) ) {
				throw new RuntimeException( 'Sensitive actions are disabled by server policy.' );
			}
			if ( ! $confirm ) {
				throw new RuntimeException( 'Sensitive actions require confirm=true for the current request.' );
			}
		}

		if ( ! empty( $descriptor['privileged'] ) ) {
			if ( self::publicRepositoryContext() && empty( $descriptor['public_repository_safe'] ) ) {
				throw new RuntimeException( 'Privileged actions are blocked in public-repository mode unless explicitly marked public_repository_safe.' );
			}
			if ( ! self::flag( 'WPCONNECTOR_ALLOW_PRIVILEGED' ) ) {
				throw new RuntimeException( 'Privileged actions are disabled by server policy.' );
			}
		}

		if ( ! empty( $descriptor['capability'] ) ) {
			$capability = (string) $descriptor['capability'];
			if ( ! function_exists( 'current_user_can' ) || ! current_user_can( $capability ) ) {
				throw new RuntimeException( 'Current user lacks the required WordPress capability: ' . $capability . '.' );
			}
		}

		if ( ! empty( $descriptor['system_update'] ) && ! self::flag( 'WPCONNECTOR_ALLOW_SYSTEM_UPDATES' ) ) {
			throw new RuntimeException( 'System update actions are disabled by server policy.' );
		}

		if ( empty( $descriptor['mutation'] ) || $dryRun ) {
			return;
		}
		if ( ! $confirm ) {
			throw new RuntimeException( 'Mutation requires confirm=true.' );
		}
		if ( ! self::flag( 'WPCONNECTOR_ALLOW_WRITES' ) ) {
			throw new RuntimeException( 'Writes are disabled by server policy.' );
		}
	}

	/**
	 * Ensure public action target for the Policy component.
	 *
	 * @param string $action Input for the operation.
	 * @param array  $payload Requested action payload.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assertPublicActionTarget( string $action, array $payload ): void {
		if ( ! self::publicRepositoryContext() || ! in_array( $action, array( 'post.update', 'post.literal_replace', 'acf.update', 'elementor.inspect', 'elementor.patch_element', 'yoast.snippet.inspect', 'yoast.snippet.update', 'yoast.snippet.restore' ), true ) ) {
			return;
		}

		if ( 'acf.update' === $action ) {
			$postId = isset( $payload['post_id'] ) && is_int( $payload['post_id'] ) ? $payload['post_id'] : ( isset( $payload['target'] ) && is_int( $payload['target'] ) ? $payload['target'] : 0 );
		} else {
			$postId = isset( $payload['id'] ) ? (int) $payload['id'] : 0;
		}
		if ( $postId <= 0 ) {
			throw new RuntimeException( 'Public ' . $action . ' requires a positive post id.' );
		}
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			throw new RuntimeException( 'Public ' . $action . ' target post was not found.' );
		}
		$requiresEdit = in_array( $action, array( 'post.update', 'post.literal_replace', 'acf.update', 'elementor.patch_element', 'yoast.snippet.inspect', 'yoast.snippet.update', 'yoast.snippet.restore' ), true );
		self::assertPublicContentTarget( $post, $requiresEdit );
	}

	/**
	 * Ensure public content target for the Policy component.
	 *
	 * @param \WP_Post $post Input for the operation.
	 * @param bool     $requiresEdit Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assertPublicContentTarget( \WP_Post $post, bool $requiresEdit = false ): void {
		self::assertReadablePostType( (string) $post->post_type );
		$postType = get_post_type_object( (string) $post->post_type );
		if ( ! $postType || ! $postType->public ) {
			throw new RuntimeException( 'Only public post types may be targeted in public-repository mode.' );
		}
		if ( 'publish' !== (string) $post->post_status || '' !== (string) $post->post_password ) {
			throw new RuntimeException( 'Only published, non-password-protected public content may be targeted in public-repository mode.' );
		}
		if ( $requiresEdit && ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_post', (int) $post->ID ) ) ) {
			throw new RuntimeException( 'Current user lacks permission to edit the public connector target.' );
		}
	}

	/**
	 * Ensure post readable for the Policy component.
	 *
	 * @param \WP_Post $post Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assertPostReadable( \WP_Post $post ): void {
		self::assertReadablePostType( (string) $post->post_type );
		if ( ! self::publicRepositoryContext() ) { return; }
		if ( 'attachment' === $post->post_type ) {
			if ( (int) $post->post_parent > 0 ) {
				$parent = get_post( (int) $post->post_parent );
				if ( $parent instanceof \WP_Post && 'publish' !== $parent->post_status ) {
					throw new RuntimeException( 'Attachment parent is not public.' );
				}
			}
			return;
		}
		$postType = get_post_type_object( (string) $post->post_type );
		if ( ! $postType || ! $postType->public ) {
			throw new RuntimeException( 'Non-public post types cannot be exported in public-repository mode.' );
		}
		if ( 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			throw new RuntimeException( 'Only public, non-password-protected content may be exported in public-repository mode.' );
		}
	}

	/**
	 * Ensure readable post type for the Policy component.
	 *
	 * @param string $postType Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assertReadablePostType( string $postType ): void {
		if ( in_array( $postType, self::SENSITIVE_POST_TYPES, true ) && ! self::sensitiveExportAllowed() ) {
			throw new RuntimeException( 'Sensitive post type export requires confirm=true on a non-public transport.' );
		}
		if ( (bool) preg_match( '/(medical|patient|intake|consult|questionnaire|prescription|submission|order_request)/i', $postType ) && ! self::sensitiveExportAllowed() ) {
			throw new RuntimeException( 'Potentially sensitive post type export requires confirm=true on a non-public transport.' );
		}
	}

	/**
	 * Ensure key allowed for the Policy component.
	 *
	 * @param string $key Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assertKeyAllowed( string $key ): void {
		if ( (bool) preg_match( '/(password|passwd|secret|token|api[_-]?key|private[_-]?key|consumer_secret|authorization|cookie|auth_key|secure_auth|logged_in_key|nonce_key|salt)/i', $key ) ) {
			throw new RuntimeException( 'Secret-like keys cannot be read or written through the connector.' );
		}
	}

	/**
	 * Ensure meta key allowed for the Policy component.
	 *
	 * @param string $key Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assertMetaKeyAllowed( string $key ): void {
		self::assertKeyAllowed( $key );
		if ( isset( $key[0] ) && '_' === $key[0] ) {
			throw new RuntimeException( 'Protected/internal metadata must use its dedicated semantic adapter.' );
		}
	}

	/**
	 * Ensure option key allowed for the Policy component.
	 *
	 * @param string $key Input for the operation.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assertOptionKeyAllowed( string $key ): void {
		self::assertKeyAllowed( $key );
		if ( in_array( strtolower( $key ), self::BLOCKED_OPTION_KEYS, true ) ) {
			throw new RuntimeException( 'System-owned option must use its dedicated semantic action instead of generic option access.' );
		}
	}

	/**
	 * Ensure local asset path for the Policy component.
	 *
	 * @param string $path Input for the operation.
	 * @param string $assetRoot Input for the operation.
	 *
	 * @return string Operation result.
	 *
	 * @throws \RuntimeException If the operation cannot be completed safely.
	 */
	public static function assertLocalAssetPath( string $path, string $assetRoot ): string {
		// Reject lexical symlinks before realpath() erases the path components.
		$base = rtrim( $assetRoot, DIRECTORY_SEPARATOR );
		if ( '' === $base || ( $path !== $base && 0 !== strpos( $path, $base . DIRECTORY_SEPARATOR ) ) ) {
			throw new RuntimeException( 'Asset path does not belong to the request root.' );
		}
		$cursor = $path;
		while ( $cursor !== $base ) {
			if ( is_link( $cursor ) ) {
				throw new RuntimeException( 'Symlink assets are not allowed.' );
			}
			$parent = dirname( $cursor );
			if ( $cursor === $parent ) {
				throw new RuntimeException( 'Asset path does not belong to the request root.' );
			}
			$cursor = $parent;
		}
		if ( is_link( $base ) ) {
			throw new RuntimeException( 'Symlink assets are not allowed.' );
		}
		$root = realpath( $assetRoot );
		$real = realpath( $path );
		if ( false === $root || false === $real ) {
			throw new RuntimeException( 'Asset path does not exist.' );
		}
		$root = rtrim( $root, DIRECTORY_SEPARATOR );
		if ( $real !== $root && 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
			throw new RuntimeException( 'Asset path escapes the allowed asset root.' );
		}
		return $real;
	}

	/**
	 * Process the redact operation in the Policy component.
	 *
	 * @param mixed $value Input for the operation.
	 *
	 * @return mixed Operation result.
	 */
	public static function redact( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		$redacted = array();
		foreach ( $value as $key => $item ) {
			$name = (string) $key;
			if ( (bool) preg_match( '/(password|passwd|secret|token|api[_-]?key|private[_-]?key|consumer_secret|authorization|cookie)/i', $name ) ) {
				$redacted[ $key ] = '[redacted]'; continue;
			}
			$redacted[ $key ] = self::redact( $item );
		}
		return $redacted;
	}

	/**
	 * Process the set public repository context operation in the Policy component.
	 *
	 * @param bool $isPublic Input for the operation.
	 */
	public static function setPublicRepositoryContext( bool $isPublic ): void { self::$runtimePublicRepository = $isPublic; }

	/**
	 * Process the public repository context operation in the Policy component.
	 *
	 * @return bool Operation result.
	 */
	public static function publicRepositoryContext(): bool {
		if ( null !== self::$runtimePublicRepository ) { return self::$runtimePublicRepository; }
		return self::flag( 'WPCONNECTOR_PUBLIC_REPOSITORY' );
	}

	/**
	 * Process the flag operation in the Policy component.
	 *
	 * @param string $name Input for the operation.
	 *
	 * @return bool Operation result.
	 */
	public static function flag( string $name ): bool {
		if ( defined( $name ) ) { return self::truthy( constant( $name ) ); }
		$environment = getenv( $name );
		if ( false !== $environment && '' !== (string) $environment ) { return self::truthy( $environment ); }
		if ( in_array( $name, self::AUTO_ENABLED_FLAGS, true ) ) { return true; }
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
		if ( true === $value || 1 === $value ) { return true; }
		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Process the sensitive export allowed operation in the Policy component.
	 *
	 * @return bool Operation result.
	 */
	private static function sensitiveExportAllowed(): bool {
		return self::$requestConfirmed && ! self::publicRepositoryContext() && self::flag( 'WPCONNECTOR_ALLOW_SENSITIVE' );
	}
}
