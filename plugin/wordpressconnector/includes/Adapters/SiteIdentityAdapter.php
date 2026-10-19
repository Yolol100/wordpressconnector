<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Narrow, nonsecret site identity settings owned by WordPress Core.
 * Public GitHub transport still requires verified owner OIDC plus manage_options.
 * No arbitrary option keys, network settings, credentials or filesystem writes.
 */
final class SiteIdentityAdapter
{
	private const KEYS = array('blogname', 'blogdescription', 'timezone_string', 'WPLANG');

	public function register(Registry $registry): void
	{
		$public = array(
			'privileged' => true,
			'public_repository_safe' => true,
			'capability' => 'manage_options',
		);
		$registry->register('site.identity.inspect', array($this, 'inspect'),
			array_merge($public, array('description' => 'Read nonsecret WordPress site title, tagline, timezone and stored locale.')));
		$registry->register('site.identity.update', array($this, 'update'),
			array_merge($public, array('mutation' => true, 'description' => 'Update bounded site identity with dry-run, stale-state protection and rollback.')));
		$registry->register('site.identity.restore', array($this, 'restore'), array(
			'mutation' => true, 'privileged' => true, 'capability' => 'manage_options',
			'description' => 'Restore a prior site identity snapshot only via verified connector rollback.',
		));
	}

	public function inspect(array $payload = array(), array $context = array()): array
	{
		if ($payload) throw new RuntimeException('Site identity inspect requires an empty payload.');
		$state = $this->snapshot();
		return array('fields' => $state, 'fingerprint' => Fingerprint::make($state));
	}

	public function update(array $payload, array $context): array
	{
		if (array_diff(array_keys($payload), array('fields'))) {
			throw new RuntimeException('Site identity update accepts only fields.');
		}
		if (function_exists('is_multisite') && is_multisite()) {
			throw new RuntimeException('Site identity update is not supported on multisite.');
		}
		$values = $this->validateFields($payload['fields'] ?? null);
		$before = $this->snapshot();
		$next = array_replace($before, $values);
		$fingerprint = Fingerprint::make($before);
		$result = array('before' => $before, 'after' => $next, '_current_fingerprint' => $fingerprint);
		if (! empty($context['dry_run'])) return $result;

		$guard = isset($context['expected_prewrite_fingerprint']) ? (string) $context['expected_prewrite_fingerprint'] : '';
		if ('' === $guard || ! hash_equals($fingerprint, $guard)) {
			throw new RuntimeException('Site identity update requires a confirmed dry-run fingerprint.');
		}
		try {
			foreach ($values as $key => $value) update_option($key, $value);
			if ($this->snapshot() !== $next) {
				throw new RuntimeException('Site identity readback did not retain the requested settings.');
			}
		} catch (Throwable $error) {
			foreach ($before as $key => $value) {
				if (array_key_exists($key, $values)) update_option($key, $value);
			}
			throw new RuntimeException('Site identity write failed; previous values restoration attempted.');
		}
		$result['after'] = $this->snapshot();
		$result['readback_verified'] = true;
		$result['_rollback'] = array(
			'action' => 'site.identity.restore',
			'payload' => array(
				'fields' => $before,
				'expected_after_fingerprint' => Fingerprint::make($result['after']),
			),
		);
		return $result;
	}

	public function restore(array $payload, array $context): array
	{
		if (empty($context['rollback_mode'])) throw new RuntimeException('Site identity restore is rollback-only.');
		if (! current_user_can('manage_options')) throw new RuntimeException('Site identity restore requires manage_options.');
		if (array_diff(array_keys($payload), array('fields','expected_after_fingerprint'))) {
			throw new RuntimeException('Site identity restore payload has unexpected keys.');
		}
		$before = $this->snapshot();
		$fingerprint = Fingerprint::make($before);
		$expected = isset($payload['expected_after_fingerprint']) ? (string) $payload['expected_after_fingerprint'] : '';
		if (! preg_match('/^[a-f0-9]{64}$/D', $expected) || ! hash_equals($expected, $fingerprint)) {
			throw new RuntimeException('Site identity rollback target changed since the update.');
		}
		$fields = $payload['fields'] ?? null;
		if (! is_array($fields) || count($fields) !== count(self::KEYS)
			|| array_diff(array_keys($fields), self::KEYS)
			|| array_diff(self::KEYS, array_keys($fields))) {
			throw new RuntimeException('Site identity rollback snapshot is invalid.');
		}
		if (! empty($context['dry_run'])) {
			return array('would_restore' => true, '_current_fingerprint' => $fingerprint);
		}
		foreach ($fields as $key => $value) {
			if (! is_string($value)) throw new RuntimeException('Site identity rollback value must be text.');
			update_option($key, $value);
		}
		if ($this->snapshot() !== $fields) throw new RuntimeException('Site identity rollback readback failed.');
		return array('restored' => true);
	}

	private function snapshot(): array
	{
		$result = array();
		foreach (self::KEYS as $key) {
			$value = get_option($key, '');
			if (! is_scalar($value)) throw new RuntimeException('Unexpected site identity value for: ' . $key);
			$result[$key] = (string) $value;
		}
		return $result;
	}

	private function validateFields($fields): array
	{
		if (! is_array($fields) || ! $fields || count($fields) > count(self::KEYS)
			|| array_keys($fields) === range(0, count($fields) - 1)) {
			throw new RuntimeException('Site identity update requires 1–4 named fields.');
		}
		$result = array();
		foreach ($fields as $key => $value) {
			if (! in_array($key, self::KEYS, true) || ! is_string($value)) {
				throw new RuntimeException('Site identity field is unsupported or not a string.');
			}
			if ('blogname' === $key || 'blogdescription' === $key) {
				$max = 'blogname' === $key ? 100 : 240;
				if ('' === trim($value) || strlen($value) > $max
					|| preg_match('/[\x00-\x1F\x7F<>]/', $value)
					|| sanitize_text_field($value) !== $value) {
					throw new RuntimeException('Site identity text is invalid.');
				}
			} elseif ('timezone_string' === $key) {
				if (! in_array($value, timezone_identifiers_list(), true)) {
					throw new RuntimeException('Site identity timezone must be an IANA name.');
				}
			} elseif ('WPLANG' === $key) {
				if (! in_array($value, array('en_GB', 'en_US', 'nl_NL'), true)) {
					throw new RuntimeException('Site identity locale is outside the bounded allowlist.');
				}
				if (defined('WPLANG') && WPLANG && WPLANG !== $value) {
					throw new RuntimeException('Site locale is overridden in wp-config; cannot safely update.');
				}
				if ('en_US' !== $value && (! function_exists('get_available_languages')
					|| ! in_array($value, get_available_languages(), true))) {
					throw new RuntimeException('Requested WordPress language pack is not installed.');
				}
			}
			$result[$key] = $value;
		}
		return $result;
	}
}
