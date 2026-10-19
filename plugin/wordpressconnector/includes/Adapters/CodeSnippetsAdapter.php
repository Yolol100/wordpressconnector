<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class CodeSnippetsAdapter
{
	private const MAX_CODE_BYTES = 1048576;
	private const MAX_REPLACEMENTS = 4;
	private const CATALOG_NAME = 'Webactueel | WooCommerce Catalogue (No Orders)';
	private const CATALOG_MARKER = 'WAB_CATALOG_ONLY_2026';

	public function register(Registry $registry): void
	{

		$registry->register('code_snippets.catalog_only.inspect', array($this, 'catalogOnlyInspect'), array(
			'privileged' => true, 'capability' => 'manage_options',
			'description' => 'Read safe metadata of the built-in WooCommerce catalogue-only Code Snippets preset.',
		));
		$registry->register('code_snippets.catalog_only.ensure', array($this, 'catalogOnlyEnsure'), array(
			'mutation' => true, 'privileged' => true, 'capability' => 'manage_options',
			'description' => 'Create/activate only the vetted built-in WooCommerce catalogue snippet with rollback. No caller PHP.',
		));
		$registry->register('code_snippets.catalog_only.undo', array($this, 'catalogOnlyUndo'), array(
			'mutation' => true, 'privileged' => true, 'capability' => 'manage_options',
			'description' => 'Guarded internal rollback of a connector-created/activated catalogue snippet.',
		));
		$registry->register('code_snippets.patch', array($this, 'patch'), array(
			'mutation' => true,
			'privileged' => true,
			'description' => 'Patch one uniquely identified Code Snippets PHP snippet using exact bounded replacements with fingerprint, readback and rollback.',
		));

		$registry->register('code_snippets.restore_code', array($this, 'restoreCode'), array(
			'mutation' => true,
			'privileged' => true,
			'description' => 'Internal rollback action for restoring one Code Snippets record after a guarded patch.',
		));
	}


	private function catalogCode(): string
	{
		$path = __DIR__ . '/../Presets/woocommerce-catalog-no-orders.txt';
		$code = is_file($path) ? file_get_contents($path) : false;
		if (! is_string($code) || '' === trim($code) ||
			strlen($code) > self::MAX_CODE_BYTES ||
			false === strpos($code, self::CATALOG_MARKER)) {
			throw new RuntimeException('Audited WooCommerce catalogue preset is unavailable.');
		}
		return trim($code);
	}

	private function catalogProvider(): void
	{
		$this->assertAvailable();
		if (! current_user_can('manage_options') || ! class_exists(\Code_Snippets\Model\Snippet::class)) {
			throw new RuntimeException('Code Snippets administrator API is unavailable.');
		}
		foreach (array('save_snippet', 'activate_snippet', 'deactivate_snippet', 'delete_snippet') as $name) {
			if (! function_exists('\\Code_Snippets\\' . $name)) {
				throw new RuntimeException('Code Snippets provider is missing a required operation.');
			}
		}
	}

	private function catalogFind(): ?object
	{
		$found = array();
		foreach ((array) \Code_Snippets\get_snippets(array(), false) as $snippet) {
			if (is_object($snippet) &&
				((string) ($snippet->name ?? '') === self::CATALOG_NAME ||
				 false !== strpos((string) ($snippet->code ?? ''), self::CATALOG_MARKER))) {
				$found[] = $snippet;
			}
		}
		if (count($found) > 1) {
			throw new RuntimeException('Conflicting catalogue snippets exist; refusing duplicate activation.');
		}
		return $found ? $found[0] : null;
	}

	private static function catalogState(?object $snippet): array
	{
		return array(
			'present' => null !== $snippet,
			'id' => $snippet ? (int) ($snippet->id ?? 0) : 0,
			'name' => $snippet ? (string) ($snippet->name ?? '') : '',
			'type' => $snippet ? (string) ($snippet->type ?? '') : '',
			'scope' => $snippet ? (string) ($snippet->scope ?? '') : '',
			'active' => $snippet ? ! empty($snippet->active) : false,
			'network' => $snippet ? ! empty($snippet->network) : false,
			'trashed' => $snippet ? ! empty($snippet->trashed) : false,
			'locked' => $snippet ? ! empty($snippet->locked) : false,
			'code_sha256' => $snippet ? hash('sha256', trim((string) ($snippet->code ?? ''))) : '',
		);
	}

	private function catalogGuard(?object $snippet, string $expectedCode): void
	{
		if (null === $snippet) {
			return;
		}
		$state = self::catalogState($snippet);
		if ($state['id'] <= 0 || $state['name'] !== self::CATALOG_NAME ||
			$state['scope'] !== 'global' || $state['type'] !== 'php' ||
			$state['network'] || $state['trashed'] || $state['locked'] ||
			! hash_equals(hash('sha256', $expectedCode), $state['code_sha256'])) {
			throw new RuntimeException('Existing catalogue snippet differs from audited preset. No changes performed.');
		}
	}

	public function catalogOnlyInspect(array $payload, array $context = array()): array
	{
		if ($payload) {
			throw new RuntimeException('Catalogue preset inspection accepts no payload fields.');
		}
		$this->catalogProvider();
		$code = $this->catalogCode();
		$snippet = $this->catalogFind();
		$this->catalogGuard($snippet, $code);
		$state = self::catalogState($snippet);
		return array(
			'snippet' => $state,
			'source_sha256' => hash('sha256', $code),
			'fingerprint' => Fingerprint::make($state),
			'_current_fingerprint' => Fingerprint::make($state),
		);
	}

	public function catalogOnlyEnsure(array $payload, array $context): array
	{
		if ($payload) {
			throw new RuntimeException('Preset creation never accepts PHP or caller configuration.');
		}
		$this->catalogProvider();
		$code = $this->catalogCode();
		$existing = $this->catalogFind();
		$this->catalogGuard($existing, $code);
		$before = self::catalogState($existing);
		$result = array(
			'before' => $before,
			'source_sha256' => hash('sha256', $code),
			'would_create' => null === $existing,
			'would_activate' => null === $existing || ! $before['active'],
			'_current_fingerprint' => Fingerprint::make($before),
		);
		if (! empty($context['dry_run']) || ($existing && $before['active'])) {
			if ($existing && $before['active']) {
				$result['snippet'] = $before;
				$result['readback_verified'] = true;
			}
			return $result;
		}

		$created = null === $existing;
		$id = $created ? 0 : (int) $existing->id;
		if ($created) {
			// Only source shipped in the verified connector ZIP reaches this provider API.
			$snippet = new \Code_Snippets\Model\Snippet(array(
				'id' => 0, 'name' => self::CATALOG_NAME,
				'desc' => 'Existing WooCommerce prices visible, cart and checkout disabled.',
				'code' => $code, 'scope' => 'global', 'priority' => 10,
				'active' => false, 'network' => false,
			));
			$saved = \Code_Snippets\save_snippet($snippet);
			if (! is_object($saved) || (int) ($saved->id ?? 0) <= 0) {
				throw new RuntimeException('Code Snippets provider could not save the audited preset.');
			}
			$id = (int) $saved->id;
		}
		try {
			$new = $this->getSnippet($id);
			$this->catalogGuard($new, $code);
			if (empty($new->active)) {
				$activated = \Code_Snippets\activate_snippet($id, false);
				if (! is_object($activated)) {
					throw new RuntimeException('Code Snippets provider could not activate the preset.');
				}
			}
			$after = $this->getSnippet($id);
			$this->catalogGuard($after, $code);
			if (empty($after->active)) {
				throw new RuntimeException('Catalogue snippet did not activate on WordPress readback.');
			}
		} catch (\Throwable $error) {
			if ($created) {
				if (! \Code_Snippets\delete_snippet($id, false)) {
					throw new RuntimeException('Failed preset activation; manually remove new inactive snippet.');
				}
			} else {
				\Code_Snippets\deactivate_snippet($id, false);
			}
			throw new RuntimeException('Catalogue preset activation failed; prior state restored.');
		}
		$result['snippet'] = self::catalogState($after);
		$result['readback_verified'] = true;
		$result['_rollback'] = array(
			'action' => 'code_snippets.catalog_only.undo',
			'payload' => array(
				'snippet_id' => $id,
				'expected_code_sha256' => hash('sha256', $code),
				'created' => $created,
			),
		);
		return $result;
	}

	public function catalogOnlyUndo(array $payload, array $context): array
	{
		if (array_keys($payload) !== array('snippet_id', 'expected_code_sha256', 'created') ||
			! is_int($payload['snippet_id']) || $payload['snippet_id'] <= 0 ||
			! is_string($payload['expected_code_sha256']) || ! is_bool($payload['created'])) {
			throw new RuntimeException('Invalid internal catalogue rollback request.');
		}
		$this->catalogProvider();
		$code = $this->catalogCode();
		if (! hash_equals(hash('sha256', $code), $payload['expected_code_sha256'])) {
			throw new RuntimeException('Stored catalogue rollback does not match vetted preset.');
		}
		$snippet = $this->catalogFind();
		$this->catalogGuard($snippet, $code);
		if (! $snippet || (int) $snippet->id !== $payload['snippet_id'] || empty($snippet->active)) {
			throw new RuntimeException('Catalogue snippet changed since creation; rollback refused.');
		}
		$state = self::catalogState($snippet);
		if (! empty($context['dry_run'])) {
			return array('would_undo' => $state, '_current_fingerprint' => Fingerprint::make($state));
		}
		$ok = $payload['created']
			? \Code_Snippets\delete_snippet((int) $snippet->id, false)
			: (bool) \Code_Snippets\deactivate_snippet((int) $snippet->id, false);
		if (! $ok) {
			throw new RuntimeException('Code Snippets rejected managed preset rollback.');
		}
		$after = $this->catalogFind();
		if ($payload['created'] ? null !== $after : (null === $after || ! empty($after->active))) {
			throw new RuntimeException('Managed catalogue rollback readback failed.');
		}
		return array('undone' => true, 'readback_verified' => true);
	}

	public function patch(array $payload, array $context): array
	{
		$this->assertAvailable();

		$snippet = $this->findTarget($payload);
		$before = $this->snapshot($snippet);

		if (! empty($before['locked'])) {
			throw new RuntimeException('Code Snippets target is locked and cannot be modified.');
		}
		if (! empty($before['trashed'])) {
			throw new RuntimeException('Code Snippets target is trashed and cannot be modified.');
		}
		if ('php' !== (string) $before['type']) {
			throw new RuntimeException('Only PHP Code Snippets may be patched through this action.');
		}

		$replacements = $this->replacements($payload);
		$newCode = (string) $before['code'];

		foreach ($replacements as $index => $replacement) {
			$occurrences = substr_count($newCode, $replacement['find']);
			if (1 !== $occurrences) {
				throw new RuntimeException('Replacement ' . $index . ' must match exactly once; matched ' . $occurrences . ' times.');
			}
			$newCode = str_replace($replacement['find'], $replacement['replace'], $newCode);
		}

		if ($newCode === (string) $before['code']) {
			throw new RuntimeException('Code Snippets patch does not change the target code.');
		}
		if (strlen($newCode) > self::MAX_CODE_BYTES) {
			throw new RuntimeException('Patched Code Snippets code exceeds the 1 MiB connector limit.');
		}

		$afterPlanned = $before;
		$afterPlanned['code'] = $newCode;

		$result = array(
			'snippet' => $this->publicSnapshot($before),
			'replacement_count' => count($replacements),
			'before_code_fingerprint' => hash('sha256', (string) $before['code']),
			'after_code_fingerprint' => hash('sha256', $newCode),
			'before_fingerprint' => Fingerprint::make($before),
			'after_fingerprint' => Fingerprint::make($afterPlanned),
			'_current_fingerprint' => Fingerprint::make($before),
		);

		if (! empty($context['dry_run'])) {
			$result['readback_verified'] = null;
			return $result;
		}

		$this->writeCode((int) $before['id'], $newCode);
		$after = $this->snapshot($this->getSnippet((int) $before['id']));

		if (! $this->sameMetadata($before, $after) || $newCode !== (string) $after['code']) {
			$this->writeCode((int) $before['id'], (string) $before['code']);
			$restored = $this->snapshot($this->getSnippet((int) $before['id']));
			if ((string) $restored['code'] !== (string) $before['code']) {
				throw new RuntimeException('Code Snippets patch readback failed and automatic restore also failed.');
			}
			throw new RuntimeException('Code Snippets patch readback failed; previous code was restored.');
		}

		$result['snippet'] = $this->publicSnapshot($after);
		$result['readback_verified'] = true;
		$result['_rollback'] = array(
			'action' => 'code_snippets.restore_code',
			'payload' => array(
				'snippet_id' => (int) $before['id'],
				'code' => (string) $before['code'],
				'expected_code_fingerprint' => hash('sha256', $newCode),
			),
		);

		return $result;
	}

	public function restoreCode(array $payload, array $context): array
	{
		$this->assertAvailable();

		$snippetId = isset($payload['snippet_id']) ? (int) $payload['snippet_id'] : 0;
		$code = isset($payload['code']) && is_string($payload['code']) ? $payload['code'] : null;
		$expected = isset($payload['expected_code_fingerprint']) ? strtolower((string) $payload['expected_code_fingerprint']) : '';

		if ($snippetId <= 0 || null === $code || strlen($code) > self::MAX_CODE_BYTES) {
			throw new RuntimeException('Code Snippets rollback payload is invalid.');
		}
		if (! preg_match('/^[a-f0-9]{64}\z/', $expected)) {
			throw new RuntimeException('Code Snippets rollback requires a valid expected_code_fingerprint.');
		}

		$before = $this->snapshot($this->getSnippet($snippetId));
		if (! empty($before['locked'])) {
			throw new RuntimeException('Code Snippets rollback target is locked.');
		}
		if (! hash_equals($expected, hash('sha256', (string) $before['code']))) {
			throw new RuntimeException('Stale target: Code Snippets rollback fingerprint does not match current code.');
		}

		$result = array(
			'snippet' => $this->publicSnapshot($before),
			'before_code_fingerprint' => hash('sha256', (string) $before['code']),
			'after_code_fingerprint' => hash('sha256', $code),
			'_current_fingerprint' => Fingerprint::make($before),
		);

		if (! empty($context['dry_run'])) {
			$result['readback_verified'] = null;
			return $result;
		}

		$this->writeCode($snippetId, $code);
		$after = $this->snapshot($this->getSnippet($snippetId));

		if (! $this->sameMetadata($before, $after) || $code !== (string) $after['code']) {
			throw new RuntimeException('Code Snippets rollback readback verification failed.');
		}

		$result['snippet'] = $this->publicSnapshot($after);
		$result['readback_verified'] = true;
		return $result;
	}

	private function assertAvailable(): void
	{
		foreach (array('get_snippet', 'get_snippets', 'update_snippet_fields') as $function) {
			$qualified = '\\Code_Snippets\\' . $function;
			if (! function_exists($qualified)) {
				throw new RuntimeException('Code Snippets API is unavailable: ' . $function . '.');
			}
		}

		if (function_exists('\\Code_Snippets\\code_snippets')) {
			$plugin = \Code_Snippets\code_snippets();
			if (is_object($plugin) && method_exists($plugin, 'current_user_can') && ! $plugin->current_user_can()) {
				throw new RuntimeException('Current user cannot manage Code Snippets.');
			}
		}
	}

	private function findTarget(array $payload)
	{
		$snippetId = isset($payload['snippet_id']) ? (int) $payload['snippet_id'] : 0;
		$marker = isset($payload['match_code_contains']) && is_string($payload['match_code_contains'])
			? $payload['match_code_contains']
			: '';

		if (($snippetId > 0 && '' !== $marker) || ($snippetId <= 0 && '' === $marker)) {
			throw new RuntimeException('Code Snippets patch requires exactly one of snippet_id or match_code_contains.');
		}

		if ($snippetId > 0) {
			return $this->getSnippet($snippetId);
		}

		if (strlen($marker) > 240 || preg_match('/[\x00-\x1F\x7F]/', $marker)) {
			throw new RuntimeException('match_code_contains must be a printable string up to 240 bytes.');
		}

		$matches = array();
		foreach ((array) \Code_Snippets\get_snippets(array(), false) as $snippet) {
			if (! is_object($snippet) || empty($snippet->id) || ! isset($snippet->code)) {
				continue;
			}
			if (false !== strpos((string) $snippet->code, $marker)) {
				$matches[] = $snippet;
			}
		}

		if (1 !== count($matches)) {
			throw new RuntimeException('Code Snippets marker must identify exactly one snippet; matched ' . count($matches) . '.');
		}

		return $matches[0];
	}

	private function getSnippet(int $snippetId)
	{
		$snippet = \Code_Snippets\get_snippet($snippetId, false);
		if (! is_object($snippet) || (int) ($snippet->id ?? 0) !== $snippetId) {
			throw new RuntimeException('Code Snippets target was not found.');
		}
		return $snippet;
	}

	private function replacements(array $payload): array
	{
		$input = isset($payload['replacements']) && is_array($payload['replacements']) ? array_values($payload['replacements']) : array();

		if (! $input || count($input) > self::MAX_REPLACEMENTS) {
			throw new RuntimeException('Code Snippets patch requires 1-' . self::MAX_REPLACEMENTS . ' replacements.');
		}

		$replacements = array();
		foreach ($input as $index => $replacement) {
			if (! is_array($replacement)) {
				throw new RuntimeException('Replacement ' . $index . ' must be an object.');
			}
			foreach (array_keys($replacement) as $key) {
				if (! in_array((string) $key, array('find', 'replace'), true)) {
					throw new RuntimeException('Replacement ' . $index . ' contains unsupported key: ' . (string) $key . '.');
				}
			}

			$find = isset($replacement['find']) && is_string($replacement['find']) ? $replacement['find'] : '';
			$replace = isset($replacement['replace']) && is_string($replacement['replace']) ? $replacement['replace'] : '';

			if ('' === $find || strlen($find) > self::MAX_CODE_BYTES || strlen($replace) > self::MAX_CODE_BYTES) {
				throw new RuntimeException('Replacement ' . $index . ' has an invalid find/replace size.');
			}
			if (false !== strpos($find, "\0") || false !== strpos($replace, "\0")) {
				throw new RuntimeException('Replacement ' . $index . ' may not contain NUL bytes.');
			}

			$replacements[] = array('find' => $find, 'replace' => $replace);
		}

		return $replacements;
	}

	private function writeCode(int $snippetId, string $code): void
	{
		if (strlen($code) > self::MAX_CODE_BYTES) {
			throw new RuntimeException('Code Snippets code exceeds the 1 MiB connector limit.');
		}

		\Code_Snippets\update_snippet_fields($snippetId, array('code' => $code), false);
	}

	private function snapshot($snippet): array
	{
		return array(
			'id' => (int) ($snippet->id ?? 0),
			'name' => (string) ($snippet->name ?? ''),
			'desc' => (string) ($snippet->desc ?? ''),
			'code' => (string) ($snippet->code ?? ''),
			'tags' => isset($snippet->tags) && is_array($snippet->tags) ? array_values($snippet->tags) : array(),
			'scope' => (string) ($snippet->scope ?? ''),
			'priority' => (int) ($snippet->priority ?? 0),
			'active' => ! empty($snippet->active),
			'network' => ! empty($snippet->network),
			'shared_network' => ! empty($snippet->shared_network),
			'locked' => ! empty($snippet->locked),
			'trashed' => ! empty($snippet->trashed),
			'type' => (string) ($snippet->type ?? ''),
		);
	}

	private function publicSnapshot(array $snapshot): array
	{
		unset($snapshot['code']);
		return $snapshot;
	}

	private function sameMetadata(array $before, array $after): bool
	{
		foreach (array('id','name','desc','tags','scope','priority','active','network','shared_network','locked','trashed','type') as $key) {
			if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
				return false;
			}
		}
		return true;
	}
}
