<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Adapter for the OFFICIAL Yoast SEO Abilities API (Yoast 28.6+, WP 6.9+).
 *
 * Private authenticated transport only. The public GitHub OIDC transport has
 * a separate, much smaller allowlist and must never invoke these actions.
 * Yoast's own Ability permission checks and validation are authoritative.
 */
final class YoastNativeAbilitiesAdapter
{
	private const ABILITIES = array(
		'post_read' => 'yoast-seo/get-post-seo-data',
		'post_update' => 'yoast-seo/update-post-seo-data',
		'seo_scores' => 'yoast-seo/get-seo-scores',
		'readability_scores' => 'yoast-seo/get-readability-scores',
		'inclusive_language_scores' => 'yoast-seo/get-inclusive-language-scores',
	);

	private const WRITABLE = array(
		'seo_title', 'meta_description', 'canonical', 'is_cornerstone',
		'noindex', 'nofollow', 'noimageindex', 'noarchive', 'nosnippet',
		'open_graph_title', 'open_graph_description', 'twitter_title',
		'twitter_description', 'schema_page_type', 'schema_article_type',
	);

	private const HIGH_IMPACT = array(
		'canonical', 'noindex', 'nofollow', 'noimageindex', 'noarchive',
		'nosnippet', 'schema_page_type', 'schema_article_type',
	);

	public function register(Registry $registry): void
	{
		$registry->register('yoast.native.capabilities', array($this, 'capabilities'), array(
			'privileged' => true,
			'capability' => 'manage_options',
			'description' => 'Discover the five official Yoast SEO Abilities with exact version/availability; private transport only.',
		));
		$registry->register('yoast.native.scores', array($this, 'scores'), array(
			'privileged' => true,
			'capability' => 'wpseo_manage_options',
			'description' => 'Read bounded official Yoast SEO, readability or inclusive-language analysis scores.',
		));
		$registry->register('yoast.native.post.inspect', array($this, 'inspect'), array(
			'privileged' => true,
			'capability' => 'wpseo_edit_advanced_metadata',
			'description' => 'Read native Yoast post, page or WooCommerce product SEO metadata and rendered output.',
		));
		$registry->register('yoast.native.post.update', array($this, 'update'), array(
			'mutation' => true,
			'privileged' => true,
			'capability' => 'wpseo_edit_advanced_metadata',
			'description' => 'Guarded single-post Yoast metadata update via official Ability, with fingerprint, readback and rollback.',
		));
		$registry->register('yoast.native.post.restore', array($this, 'restore'), array(
			'mutation' => true,
			'privileged' => true,
			'capability' => 'wpseo_edit_advanced_metadata',
			'description' => 'Rollback-only restore of an earlier native Yoast SEO metadata change.',
		));
	}

	public function capabilities(array $payload = array(), array $context = array()): array
	{
		if (Policy::publicRepositoryContext() || ! function_exists('current_user_can')
			|| ! current_user_can('manage_options')) {
			throw new RuntimeException('Private Yoast capability discovery requires manage_options.');
		}
		$available = function_exists('wp_get_ability') &&
			(defined('WPSEO_VERSION') || defined('YOAST_SEO_VERSION'));
		$actions = array();
		foreach (self::ABILITIES as $kind => $name) {
			$ability = $available ? wp_get_ability($name) : null;
			$actions[$kind] = is_object($ability) && method_exists($ability, 'execute');
		}
		return array(
			'available' => $available,
			'yoast_version' => defined('WPSEO_VERSION') ? (string) WPSEO_VERSION :
				(defined('YOAST_SEO_VERSION') ? (string) YOAST_SEO_VERSION : null),
			'abilities' => $actions,
			'requires' => array('wordpress' => '6.9', 'yoast' => '28.6'),
			'transport' => 'private_authenticated_only',
		);
	}

	public function scores(array $payload, array $context = array()): array
	{
		$this->assertPrivate();
		if (! function_exists('current_user_can') || ! current_user_can('wpseo_manage_options')) {
			throw new RuntimeException('Yoast analysis scores require wpseo_manage_options.');
		}
		$kind = isset($payload['kind']) ? (string) $payload['kind'] : '';
		if (! in_array($kind, array('seo_scores', 'readability_scores', 'inclusive_language_scores'), true)) {
			throw new RuntimeException('Unsupported Yoast analysis kind.');
		}
		$number = $payload['number_of_posts'] ?? 10;
		if (! is_int($number) || $number < 1 || $number > 100) {
			throw new RuntimeException('number_of_posts must be an integer from 1 through 100.');
		}
		$result = $this->invoke(self::ABILITIES[$kind], array('number_of_posts' => $number));
		if (! is_array($result) || count($result) > $number) {
			throw new RuntimeException('Yoast analysis response shape was invalid.');
		}
		return array('kind' => $kind, 'items' => $result, 'count' => count($result));
	}

	public function inspect(array $payload, array $context = array()): array
	{
		$id = $this->assertPostAccess($payload);
		$data = $this->postData($id);
		return array(
			'post_id' => $id,
			'fields' => $this->snapshot($data),
			'rendered' => $this->renderedFields($data),
			'analysis' => array_intersect_key($data, array_flip(array(
				'focus_keyphrase', 'seo_score', 'readability_score', 'inclusive_language_score',
			))),
			'fingerprint' => Fingerprint::make($this->snapshot($data)),
		);
	}

	public function update(array $payload, array $context = array()): array
	{
		$id = $this->assertPostAccess($payload);
		$fields = $this->validatedFields($payload['fields'] ?? null);
		$highImpact = array_values(array_intersect(array_keys($fields), self::HIGH_IMPACT));
		if ($highImpact && true !== ($payload['risk_acknowledged'] ?? false)) {
			throw new RuntimeException('High-impact Yoast metadata requires risk_acknowledged=true.');
		}

		$before = $this->snapshot($this->postData($id));
		$proposed = array_replace($before, $fields);
		$result = array(
			'post_id' => $id,
			'before' => array_intersect_key($before, $fields),
			'after' => array_intersect_key($proposed, $fields),
			'high_impact_fields' => $highImpact,
			'_current_fingerprint' => Fingerprint::make($before),
		);
		if (! empty($context['dry_run'])) {
			return $result;
		}
		if ($before === $proposed) {
			$result['no_change'] = true;
			$result['readback_verified'] = true;
			return $result;
		}

		$restoreFields = array_intersect_key($before, $fields);
		$this->invoke(self::ABILITIES['post_update'], array_merge(array('post_id' => $id), $fields));
		$after = $this->snapshot($this->postData($id));
		foreach ($fields as $field => $value) {
			if ($after[$field] !== $value) {
				try {
					$this->invoke(self::ABILITIES['post_update'], array_merge(array('post_id' => $id), $restoreFields));
					$restored = $this->snapshot($this->postData($id));
					if ($restored !== $before) {
						throw new RuntimeException('Automatic restoration mismatch.');
					}
				} catch (\Throwable $error) {
					throw new RuntimeException('Yoast native readback mismatch; manual recovery required.');
				}
				throw new RuntimeException('Yoast native readback mismatch; original metadata restored.');
			}
		}

		$result['after'] = array_intersect_key($after, $fields);
		$result['readback_verified'] = true;
		$result['_rollback'] = array(
			'action' => 'yoast.native.post.restore',
			'payload' => array(
				'post_id' => $id,
				'fields' => $restoreFields,
				'expected_after_fingerprint' => Fingerprint::make($after),
			),
		);
		return $result;
	}

	public function restore(array $payload, array $context = array()): array
	{
		if (empty($context['rollback_mode'])) {
			throw new RuntimeException('Yoast native restore is rollback-only.');
		}
		$id = $this->assertPostAccess($payload);
		$previous = $this->validatedFields($payload['fields'] ?? null);
		$expected = $payload['expected_after_fingerprint'] ?? null;
		if (! is_string($expected) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $expected)) {
			throw new RuntimeException('A valid rollback fingerprint is required.');
		}
		$current = $this->snapshot($this->postData($id));
		if (! hash_equals(Fingerprint::make($current), $expected)) {
			throw new RuntimeException('Yoast metadata changed since the original write.');
		}
		if (! empty($context['dry_run'])) {
			return array('would_restore' => array_keys($previous), '_current_fingerprint' => Fingerprint::make($current));
		}
		$this->invoke(self::ABILITIES['post_update'], array_merge(array('post_id' => $id), $previous));
		$after = $this->snapshot($this->postData($id));
		foreach ($previous as $field => $value) {
			if ($after[$field] !== $value) {
				throw new RuntimeException('Yoast native rollback readback mismatch.');
			}
		}
		return array('post_id' => $id, 'restored' => true, 'readback_verified' => true);
	}

	private function assertPrivate(): void
	{
		if (Policy::publicRepositoryContext()) {
			throw new RuntimeException('Native Yoast Abilities require a private authenticated transport.');
		}
		if (! defined('WPSEO_VERSION') && ! defined('YOAST_SEO_VERSION')) {
			throw new RuntimeException('An active Yoast SEO installation is required.');
		}
		if (! function_exists('wp_get_ability')) {
			throw new RuntimeException('Yoast Abilities require WordPress 6.9 or later and a compatible Yoast version.');
		}
	}

	private function assertPostAccess(array $payload): int
	{
		$this->assertPrivate();
		$id = $payload['post_id'] ?? null;
		if (! is_int($id) || $id < 1) {
			throw new RuntimeException('An explicit positive post_id is required.');
		}
		$post = function_exists('get_post') ? get_post($id) : null;
		if (! $post instanceof \WP_Post) {
			throw new RuntimeException('Yoast target post was not found.');
		}
		if (! function_exists('current_user_can') ||
			! current_user_can('wpseo_edit_advanced_metadata') ||
			! current_user_can('edit_post', $id)) {
			throw new RuntimeException('Missing Yoast advanced metadata or post editing permission.');
		}
		return $id;
	}

	private function postData(int $id): array
	{
		$result = $this->invoke(self::ABILITIES['post_read'], array('post_id' => $id));
		if (! is_array($result)) {
			throw new RuntimeException('Yoast native post response was invalid.');
		}
		$rows = isset($result['post_id']) ? array($result) : array_values($result);
		if (count($rows) !== 1 || ! is_array($rows[0]) ||
			(int) ($rows[0]['post_id'] ?? 0) !== $id) {
			throw new RuntimeException('Yoast native post response did not match the exact target.');
		}
		return $rows[0];
	}

	private function invoke(string $name, array $input)
	{
		$this->assertPrivate();
		if (! in_array($name, array_values(self::ABILITIES), true)) {
			throw new RuntimeException('Unrecognized native Yoast Ability.');
		}
		$ability = wp_get_ability($name);
		if (! is_object($ability) || ! method_exists($ability, 'execute')) {
			throw new RuntimeException('Installed Yoast version does not expose this official Ability.');
		}
		try {
			$result = $ability->execute($input);
		} catch (\Throwable $error) {
			throw new RuntimeException('Native Yoast Ability execution failed.');
		}
		if (function_exists('is_wp_error') && is_wp_error($result)) {
			throw new RuntimeException('Native Yoast Ability rejected the request.');
		}
		return $result;
	}

	private function snapshot(array $data): array
	{
		$fields = array();
		foreach (self::WRITABLE as $name) {
			$fields[$name] = array_key_exists($name, $data) ? $data[$name] : null;
		}
		return $fields;
	}

	private function renderedFields(array $data): array
	{
		$fields = array();
		foreach (array('seo_title_rendered', 'meta_description_rendered', 'canonical_rendered',
			'open_graph_title_rendered', 'open_graph_description_rendered',
			'twitter_title_rendered', 'twitter_description_rendered') as $name) {
			$fields[$name] = isset($data[$name]) && is_scalar($data[$name]) ?
				(string) $data[$name] : null;
		}
		return $fields;
	}

	private function validatedFields($fields): array
	{
		if (! is_array($fields) || ! $fields || count($fields) > count(self::WRITABLE)) {
			throw new RuntimeException('Expected 1-15 explicit Yoast metadata fields.');
		}
		$normalized = array();
		foreach ($fields as $name => $value) {
			if (! is_string($name) || ! in_array($name, self::WRITABLE, true)) {
				throw new RuntimeException('Unsupported native Yoast metadata field.');
			}
			if (in_array($name, array('is_cornerstone', 'nofollow', 'noimageindex', 'noarchive', 'nosnippet'), true)) {
				if (! is_bool($value)) {
					throw new RuntimeException('Yoast flag must be a JSON boolean.');
				}
			} elseif ('noindex' === $name) {
				if (! is_bool($value) && null !== $value) {
					throw new RuntimeException('Yoast noindex must be boolean or null.');
				}
			} else {
				if (! is_string($value) && null !== $value) {
					throw new RuntimeException('Yoast text metadata must be a string or null.');
				}
				if (is_string($value)) {
					$limit = 'canonical' === $name ? 2048 :
						(0 === strpos($name, 'schema_') ? 100 : 600);
					if (strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
						throw new RuntimeException('Yoast metadata is too large or contains invalid controls.');
					}
					if ('canonical' === $name && '' !== $value &&
						(! filter_var($value, FILTER_VALIDATE_URL) ||
						 0 !== strpos(strtolower($value), 'https://'))) {
						throw new RuntimeException('Yoast canonical must be an HTTPS URL or null.');
					}
				}
			}
			$normalized[$name] = $value;
		}
		return $normalized;
	}
}
