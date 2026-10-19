<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Native WordPress Site Editor support. Never interpret arbitrary REST
 * paths from client input: only Core's two template controllers are used.
 */
final class SiteEditorAdapter
{
	private const MAX_CONTENT_BYTES = 65536;

	public function register(Registry $registry): void
	{
		$read = array('privileged' => true, 'capability' => 'edit_theme_options');
		$write = $read + array('mutation' => true);
		$registry->register('site_editor.template.list', array($this, 'listTemplates'),
			$read + array('description' => 'List active-theme Site Editor templates or template parts via core REST permissions.'));
		$registry->register('site_editor.template.inspect', array($this, 'inspect'),
			$read + array('description' => 'Inspect one exact active-theme template or part, with source and fingerprint.'));
		$registry->register('site_editor.template.update', array($this, 'update'),
			$write + array('description' => 'Guarded update of a previously customized Site Editor template or part using core REST validation and exact rollback.'));
		$registry->register('site_editor.template.restore', array($this, 'restore'),
			$write + array('description' => 'Rollback-only restore for an exact previously customized Site Editor template/part.'));
		$registry->register('site_editor.global_styles.inspect', array($this, 'globalStyles'),
			$read + array('description' => 'Read the current block theme global style configuration via core REST without changing it.'));
	}

	public function listTemplates(array $payload = array()): array
	{
		$this->keys($payload, array('kind', 'page'));
		$kind = $this->kind($payload);
		$page = $payload['page'] ?? 1;
		if (! is_int($page) || $page < 1 || $page > 100) {
			throw new RuntimeException('Site Editor page must be an integer between 1 and 100.');
		}
		$response = $this->request('GET', $this->route($kind), array(
			'context' => 'edit', 'per_page' => 20, 'page' => $page,
		));
		$data = $this->data($response);
		if (! is_array($data)) {
			throw new RuntimeException('WordPress did not return template records.');
		}
		$rows = array();
		foreach ($data as $item) {
			if (! is_array($item) || ($item['theme'] ?? null) !== get_stylesheet()) {
				continue;
			}
			$rows[] = array(
				'id' => (string) ($item['id'] ?? ''),
				'slug' => (string) ($item['slug'] ?? ''),
				'title' => $this->title($item['title'] ?? ''),
				'source' => (string) ($item['source'] ?? ''),
				'customized' => 'custom' === ($item['source'] ?? '') && (int) ($item['wp_id'] ?? 0) > 0,
			);
		}
		return array('kind' => $kind, 'theme' => get_stylesheet(), 'page' => $page,
			'items' => $rows, 'count' => count($rows), 'per_page' => 20);
	}

	public function inspect(array $payload): array
	{
		$this->keys($payload, array('kind', 'id'));
		$kind = $this->kind($payload);
		$id = $this->id($payload);
		$record = $this->record($kind, $id);
		$state = $this->state($kind, $id, $record);
		return $state + array('content' => $this->rawContent($record));
	}

	public function globalStyles(array $payload = array()): array
	{
		$this->keys($payload, array());
		$theme = get_stylesheet();
		if (! is_string($theme) || ! preg_match('/^[A-Za-z0-9_-]{1,80}\z/', $theme)) {
			throw new RuntimeException('Global styles for this theme need a dedicated supported theme identity.');
		}
		$result = $this->data($this->request('GET', '/wp/v2/global-styles/themes/' . $theme));
		if (! is_array($result)) {
			throw new RuntimeException('WordPress did not return global styles.');
		}
		$settings = $result['settings'] ?? array();
		$styles = $result['styles'] ?? array();
		if (! is_array($settings) || ! is_array($styles)) {
			throw new RuntimeException('WordPress global styles format is unsupported.');
		}
		$json = wp_json_encode(array($settings, $styles));
		if (! is_string($json) || strlen($json) > self::MAX_CONTENT_BYTES) {
			throw new RuntimeException('Global styles exceed the bounded response size.');
		}
		return array('theme' => $theme, 'settings' => $settings, 'styles' => $styles,
			'fingerprint' => Fingerprint::make(array($settings, $styles)));
	}

	public function update(array $payload, array $context): array
	{
		$this->keys($payload, array('kind', 'id', 'content', 'expected_before_fingerprint'));
		$kind = $this->kind($payload);
		$id = $this->id($payload);
		$content = $this->content($payload);
		$before = $this->state($kind, $id, $this->record($kind, $id));
		$this->writable($before);
		$this->expected($payload['expected_before_fingerprint'] ?? null, $before['fingerprint']);

		$result = array(
			'kind' => $kind, 'id' => $id, 'theme' => get_stylesheet(),
			'would_change' => $before['content_sha256'] !== hash('sha256', $content),
			'before' => array('content_sha256' => $before['content_sha256'], 'modified' => $before['modified']),
			'_current_fingerprint' => $before['fingerprint'],
		);
		if (! empty($context['dry_run'])) {
			return $result;
		}
		$this->writeAllowed();
		// Use Core's exact template route, validation, sanitization and
		// permission callback. Do not update wp_template database rows directly.
		$current = $this->record($kind, $id);
		$fresh = $this->state($kind, $id, $current);
		if (! hash_equals($before['fingerprint'], $fresh['fingerprint'])) {
			throw new RuntimeException('Site Editor template changed immediately before the write.');
		}
		$previous = $this->content(array('content' => $this->rawContent($current)));
		try {
			$this->request('POST', $this->route($kind) . '/' . $id, array('content' => $content));
			$after = $this->state($kind, $id, $this->record($kind, $id));
			if ($after['content_sha256'] !== hash('sha256', $content)) {
				throw new RuntimeException('WordPress did not retain the requested template content.');
			}
		} catch (\Throwable $failure) {
			$this->restoreAfterFailure($kind, $id, $previous);
			throw new RuntimeException('Template update was not confirmed; original content restored and verified.');
		}
		$result['updated'] = true;
		$result['after'] = array('content_sha256' => $after['content_sha256'], 'modified' => $after['modified']);
		$result['_rollback'] = array(
			'action' => 'site_editor.template.restore',
			'payload' => array('kind' => $kind, 'id' => $id, 'content' => $previous,
				'expected_after_fingerprint' => $after['fingerprint']),
		);
		return $result;
	}

	public function restore(array $payload, array $context): array
	{
		$this->keys($payload, array('kind', 'id', 'content', 'expected_after_fingerprint'));
		if (empty($context['rollback_mode'])) {
			throw new RuntimeException('Site Editor restore is available only to a stored connector rollback.');
		}
		$kind = $this->kind($payload);
		$id = $this->id($payload);
		$content = $this->content($payload);
		$before = $this->state($kind, $id, $this->record($kind, $id));
		$this->writable($before);
		$this->expected($payload['expected_after_fingerprint'] ?? null, $before['fingerprint']);
		if (! empty($context['dry_run'])) {
			return array('would_restore' => $id, '_current_fingerprint' => $before['fingerprint']);
		}
		$this->writeAllowed();
		$this->request('POST', $this->route($kind) . '/' . $id, array('content' => $content));
		$after = $this->state($kind, $id, $this->record($kind, $id));
		if ($after['content_sha256'] !== hash('sha256', $content)) {
			throw new RuntimeException('Site Editor rollback readback failed.');
		}
		return array('restored' => $id, 'readback_verified' => true);
	}

	private function restoreAfterFailure(string $kind, string $id, string $previous): void
	{
		try {
			$this->request('POST', $this->route($kind) . '/' . $id, array('content' => $previous));
			$after = $this->record($kind, $id);
			if (hash('sha256', $this->rawContent($after)) !== hash('sha256', $previous)) {
				throw new RuntimeException('Content mismatch after restoration.');
			}
		} catch (\Throwable $error) {
			throw new RuntimeException('Template update and recovery failed; manual staging restore required.');
		}
	}

	private function state(string $kind, string $id, array $record): array
	{
		if (($record['id'] ?? null) !== $id || ($record['theme'] ?? null) !== get_stylesheet()) {
			throw new RuntimeException('WordPress Site Editor returned an unexpected theme/template identity.');
		}
		$content = $this->rawContent($record);
		$state = array('kind' => $kind, 'id' => $id, 'theme' => get_stylesheet(),
			'source' => (string) ($record['source'] ?? ''),
			'wp_id' => (int) ($record['wp_id'] ?? 0),
			'modified' => (string) ($record['modified'] ?? ''),
			'content_sha256' => hash('sha256', $content),
			'customized' => 'custom' === ($record['source'] ?? '') && (int) ($record['wp_id'] ?? 0) > 0);
		$state['fingerprint'] = Fingerprint::make($state);
		return $state;
	}

	private function writable(array $state): void
	{
		if (! $state['customized'] || $state['wp_id'] <= 0
			|| ! current_user_can('edit_post', $state['wp_id'])) {
			throw new RuntimeException('Only previously customized, editable Site Editor templates can be modified.');
		}
	}

	private function writeAllowed(): void
	{
		if (Policy::publicRepositoryContext() || ! Policy::flag('WPCONNECTOR_ALLOW_SITE_EDITOR_WRITES')) {
			throw new RuntimeException('Site Editor writes require explicitly enabled private server policy.');
		}
	}

	private function expected($value, string $actual): void
	{
		if (! is_string($value) || ! preg_match('/^[a-f0-9]{64}\z/', $value)
			|| ! hash_equals($actual, $value)) {
			throw new RuntimeException('Template content or metadata changed since the exact dry-run fingerprint.');
		}
	}

	private function record(string $kind, string $id): array
	{
		$data = $this->data($this->request('GET', $this->route($kind) . '/' . $id,
			array('context' => 'edit')));
		if (! is_array($data)) {
			throw new RuntimeException('WordPress returned an invalid template.');
		}
		return $data;
	}

	private function request(string $method, string $path, array $params = array())
	{
		if (! function_exists('rest_do_request') || ! class_exists('\WP_REST_Request')) {
			throw new RuntimeException('The WordPress REST API is not initialized.');
		}
		$request = new \WP_REST_Request($method, $path);
		foreach ($params as $key => $value) {
			$request->set_param($key, $value);
		}
		$response = rest_do_request($request);
		if (! is_object($response) || ! method_exists($response, 'get_status')
			|| $response->get_status() < 200 || $response->get_status() >= 300) {
			throw new RuntimeException('Native WordPress Site Editor API denied or could not complete the request.');
		}
		return $response;
	}

	private function data($response)
	{
		return $response->get_data();
	}

	private function rawContent(array $record): string
	{
		$value = $record['content'] ?? null;
		$raw = is_array($value) ? ($value['raw'] ?? null) : $value;
		if (! is_string($raw) || strlen($raw) > self::MAX_CONTENT_BYTES) {
			throw new RuntimeException('Native Site Editor template content cannot be safely represented.');
		}
		return $raw;
	}

	private function content(array $payload): string
	{
		$content = $payload['content'] ?? null;
		if (! is_string($content) || strlen($content) > self::MAX_CONTENT_BYTES
			|| strpos($content, '<?') !== false
			|| preg_match('/<\s*script\b|on(?:load|error|click)\s*=/i', $content)) {
			throw new RuntimeException('Site Editor content is oversized or contains executable markup.');
		}
		return $content;
	}

	private function title($value): string
	{
		if (is_array($value)) {
			$value = $value['raw'] ?? $value['rendered'] ?? '';
		}
		return is_string($value) ? substr(wp_strip_all_tags($value), 0, 140) : '';
	}

	private function id(array $payload): string
	{
		$id = $payload['id'] ?? null;
		$theme = get_stylesheet();
		if (! is_string($id) || ! is_string($theme)
			|| ! preg_match('/^[A-Za-z0-9_-]{1,80}\z/', $theme)
			|| ! preg_match('/^[A-Za-z0-9_-]{1,80}\/\/[A-Za-z0-9_-]{1,80}\z/', $id)
			|| strpos($id, $theme . '//') !== 0) {
			throw new RuntimeException('Template ID must belong exactly to the active theme.');
		}
		return $id;
	}

	private function kind(array $payload): string
	{
		$kind = $payload['kind'] ?? null;
		if (! in_array($kind, array('template', 'template_part'), true)) {
			throw new RuntimeException('Site Editor kind must be template or template_part.');
		}
		return $kind;
	}

	private function route(string $kind): string
	{
		return 'template' === $kind ? '/wp/v2/templates' : '/wp/v2/template-parts';
	}

	private function keys(array $payload, array $allowed): void
	{
		if (array_diff(array_keys($payload), $allowed)) {
			throw new RuntimeException('Site Editor payload contains unsupported fields.');
		}
	}
}
