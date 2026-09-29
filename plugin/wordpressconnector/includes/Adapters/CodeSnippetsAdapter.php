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

    public function register(Registry $registry): void
    {
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
