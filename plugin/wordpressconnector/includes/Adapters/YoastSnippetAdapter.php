<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Narrow public-GitHub SEO snippet repair. Never exposes Yoast private fields,
 * robots/indexability controls, canonical URLs, focus keywords or redirects.
 */
final class YoastSnippetAdapter
{
	private const KEYS = array(
		'title' => '_yoast_wpseo_title',
		'description' => '_yoast_wpseo_metadesc',
	);

	public function register(Registry $registry): void
	{
		$registry->register('yoast.snippet.inspect', array($this, 'inspect'), array(
			'privileged' => true,
			'public_repository_safe' => true,
			'description' => 'Inspect only the public title and meta description overrides of a published post.',
		));
		$registry->register('yoast.snippet.update', array($this, 'update'), array(
			'mutation' => true,
			'privileged' => true,
			'public_repository_safe' => true,
			'description' => 'Guarded edit of public Yoast SEO title and description overrides only.',
		));
		$registry->register('yoast.snippet.restore', array($this, 'restore'), array(
			'mutation' => true,
			'privileged' => true,
			'description' => 'Internal stale-state-guarded rollback of a previous snippet edit.',
		));
	}

	private function target(array $payload, bool $edit): \WP_Post
	{
		if (! isset($payload['id']) || ! is_int($payload['id']) || $payload['id'] < 1) {
			throw new RuntimeException('Snippet target requires a positive integer id.');
		}
		$post = get_post($payload['id']);
		if (! $post instanceof \WP_Post || ! in_array($post->post_type, array('page', 'post', 'product'), true)) {
			throw new RuntimeException('Snippet target must be a published page, post or product.');
		}
		Policy::assertPublicContentTarget($post, $edit);
		if (! current_user_can('edit_post', (int) $post->ID)) {
			throw new RuntimeException('Missing edit_post capability for snippet target.');
		}
		if (! defined('WPSEO_VERSION') && ! defined('YOAST_SEO_VERSION')) {
			throw new RuntimeException('Yoast SEO is not active.');
		}
		return $post;
	}

	private function fields(int $id): array
	{
		$values = array();
		foreach (self::KEYS as $key => $meta) {
			$raw = get_post_meta($id, $meta, true);
			$values[$key] = is_string($raw) && $raw !== '' ? $raw : null;
		}
		return $values;
	}

	private function state(int $id): array
	{
		return array('post_id' => $id, 'fields' => $this->fields($id));
	}

	/** Shared server-side validation; request/runner also validate independently. */
	public static function validateFields($fields, bool $restore = false): array
	{
		if (! is_array($fields) || ! $fields || count($fields) > 2 || array_keys($fields) === range(0, count($fields) - 1)) {
			throw new RuntimeException('Snippet fields must be a nonempty title/description object.');
		}
		$validated = array();
		foreach ($fields as $key => $value) {
			if (! in_array($key, array('title', 'description'), true)) {
				throw new RuntimeException('Unsupported public snippet field.');
			}
			if ($restore && null === $value) {
				$validated[$key] = null;
				continue;
			}
			$max = 'title' === $key ? 190 : 320;
			if (! is_string($value) || strlen($value) < 10 || strlen($value) > $max ||
				trim($value) !== $value || preg_match('/[\x00-\x1f\x7f<>]/', $value) ||
				strpos($value, '%%') !== false) {
				throw new RuntimeException('Snippet text must be plain, bounded and nonempty.');
			}
			$clean = sanitize_text_field($value);
			if (! hash_equals($value, $clean)) {
				throw new RuntimeException('Snippet text changed after sanitization.');
			}
			$validated[$key] = $clean;
		}
		return $validated;
	}

	public function inspect(array $payload, array $context = array()): array
	{
		if (array_keys($payload) !== array('id')) {
			throw new RuntimeException('Snippet inspect accepts only id.');
		}
		$post = $this->target($payload, true);
		$before = $this->state((int) $post->ID);
		return array(
			'post_id' => (int) $post->ID,
			'fields' => $before['fields'],
			'fingerprint' => Fingerprint::make($before),
		);
	}

	public function update(array $payload, array $context): array
	{
		if (array_diff(array_keys($payload), array('id', 'fields'))) {
			throw new RuntimeException('Snippet update accepts only id and fields.');
		}
		$post = $this->target($payload, true);
		$updates = self::validateFields($payload['fields'] ?? null);
		$before = $this->state((int) $post->ID);
		$after = $before;
		foreach ($updates as $key => $value) {
			$after['fields'][$key] = $value;
		}
		$result = array(
			'post_id' => (int) $post->ID,
			'before' => $before,
			'after' => $after,
			'_current_fingerprint' => Fingerprint::make($before),
		);
		if (! empty($context['dry_run'])) {
			return $result;
		}
		foreach ($updates as $key => $value) {
			update_post_meta((int) $post->ID, self::KEYS[$key], $value);
		}
		clean_post_cache((int) $post->ID);
		$actual = $this->state((int) $post->ID);
		if ($actual !== $after) {
			foreach ($updates as $key => $value) {
				if (null === $before['fields'][$key]) {
					delete_post_meta((int) $post->ID, self::KEYS[$key]);
				} else {
					update_post_meta((int) $post->ID, self::KEYS[$key], $before['fields'][$key]);
				}
			}
			clean_post_cache((int) $post->ID);
			throw new RuntimeException('Snippet readback mismatch; prior values restored.');
		}
		$result['after'] = $actual;
		$previous = array_intersect_key($before['fields'], $updates);
		$result['_rollback'] = array(
			'action' => 'yoast.snippet.restore',
			'payload' => array(
				'id' => (int) $post->ID,
				'fields' => $previous,
				'expected_after_fingerprint' => Fingerprint::make($actual),
			),
		);
		return $result;
	}

	public function restore(array $payload, array $context): array
	{
		if (empty($context['rollback_mode']) || array_diff(array_keys($payload), array('id', 'fields', 'expected_after_fingerprint'))) {
			throw new RuntimeException('Snippet restore is reserved for guarded rollback.');
		}
		$post = $this->target($payload, true);
		$previous = self::validateFields($payload['fields'] ?? null, true);
		$expected = $payload['expected_after_fingerprint'] ?? null;
		$current = $this->state((int) $post->ID);
		$currentFp = Fingerprint::make($current);
		if (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}\z/', $expected) || ! hash_equals($expected, $currentFp)) {
			throw new RuntimeException('Snippet changed after edit; rollback refused.');
		}
		if (! empty($context['dry_run'])) {
			return array('_current_fingerprint' => $currentFp, 'would_restore' => array_keys($previous));
		}
		foreach ($previous as $key => $value) {
			if (null === $value) {
				delete_post_meta((int) $post->ID, self::KEYS[$key]);
			} else {
				update_post_meta((int) $post->ID, self::KEYS[$key], $value);
			}
		}
		clean_post_cache((int) $post->ID);
		$after = $this->state((int) $post->ID);
		foreach ($previous as $key => $value) {
			if ($after['fields'][$key] !== $value) {
				throw new RuntimeException('Snippet rollback readback mismatch.');
			}
		}
		return array('restored' => true, 'post_id' => (int) $post->ID);
	}
}
