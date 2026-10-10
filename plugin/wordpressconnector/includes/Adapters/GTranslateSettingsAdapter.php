<?php
declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Version-bound, secret-safe GTranslate language configuration.
 * The vendor owns the GTranslate option array; unrelated/premium fields are
 * preserved. This is deliberately not a generic arbitrary plugin option editor.
 */
final class GTranslateSettingsAdapter
{
    private const KEYS = array('default_language', 'incl_langs', 'fincl_langs', 'detect_browser_language');
    private const READ_ONLY = array('widget_look', 'flag_style', 'show_in_menu', 'float_switcher_open_direction');

    public function register(Registry $registry): void
    {
        $read = array('privileged' => true, 'capability' => 'manage_options');
        $registry->register('gtranslate.settings.inspect', array($this, 'inspect'), $read);
        $registry->register('gtranslate.settings.update', array($this, 'update'),
            array_merge($read, array('mutation' => true)));
        $registry->register('gtranslate.settings.restore', array($this, 'restore'),
            array_merge($read, array('mutation' => true)));
    }

    public function inspect(array $payload = array(), array $context = array()): array
    {
        if ($payload) throw new RuntimeException('GTranslate inspection does not accept extra options.');
        $raw = $this->settings();
        return array(
            'version' => $this->version(),
            'fields' => $this->publicFields($raw),
            'modifiable_fields' => self::KEYS,
            'fingerprint' => Fingerprint::make($raw),
        );
    }

    public function update(array $payload, array $context): array
    {
        $this->assertKeys($payload, array('fields', 'expected_before_fingerprint'));
        $raw = $this->settings();
        $before = $this->publicFields($raw);
        $fingerprint = Fingerprint::make($raw);
        $patch = $this->validatePatch($payload['fields'] ?? null);
        $afterRaw = array_replace($raw, $patch);
        $this->ensureLanguages($afterRaw);
        $preview = array(
            'before' => $before,
            'after' => $this->publicFields($afterRaw),
            '_current_fingerprint' => $fingerprint,
        );
        if (! empty($context['dry_run'])) return $preview;
        $this->expectedFingerprint($payload['expected_before_fingerprint'] ?? null, $fingerprint);

        $old = array();
        foreach ($patch as $key => $new) {
            $old[$key] = array(
                'exists' => array_key_exists($key, $raw),
                'value' => $raw[$key] ?? null,
            );
        }

        update_option('GTranslate', $afterRaw);
        $actual = $this->settings();
        foreach ($patch as $key => $new) {
            if (! array_key_exists($key, $actual) || $actual[$key] !== $new) {
                try { update_option('GTranslate', $raw); } catch (Throwable $ignored) {}
                throw new RuntimeException('GTranslate did not retain requested settings; previous state restoration attempted.');
            }
        }
        foreach ($raw as $key => $value) {
            if (! array_key_exists($key, $patch)
                && (! array_key_exists($key, $actual) || $actual[$key] !== $value)) {
                try { update_option('GTranslate', $raw); } catch (Throwable $ignored) {}
                throw new RuntimeException('GTranslate modified unrelated settings; previous state restoration attempted.');
            }
        }
        $preview['after'] = $this->publicFields($actual);
        $preview['_rollback'] = array(
            'action' => 'gtranslate.settings.restore',
            'payload' => array(
                'previous_fields' => $old,
                'expected_after_fingerprint' => Fingerprint::make($actual),
            ),
        );
        return $preview;
    }

    public function restore(array $payload, array $context): array
    {
        if (empty($context['rollback_mode'])) throw new RuntimeException('GTranslate restoration is rollback-only.');
        $this->assertKeys($payload, array('previous_fields', 'expected_after_fingerprint'));
        $raw = $this->settings();
        $this->expectedFingerprint($payload['expected_after_fingerprint'] ?? null, Fingerprint::make($raw));
        $entries = $payload['previous_fields'] ?? null;
        if (! is_array($entries) || ! $entries || count($entries) > count(self::KEYS)) {
            throw new RuntimeException('Invalid GTranslate rollback fields.');
        }
        $before = $raw;
        foreach ($entries as $key => $entry) {
            if (! in_array($key, self::KEYS, true) || ! is_array($entry)
                || ! array_key_exists('exists', $entry) || ! is_bool($entry['exists'])
                || array_diff(array_keys($entry), array('exists', 'value'))) {
                throw new RuntimeException('Invalid GTranslate rollback field.');
            }
            if (! $entry['exists']) {
                unset($before[$key]);
            } else {
                if (! array_key_exists('value', $entry)) {
                    throw new RuntimeException('Incomplete GTranslate rollback field.');
                }
                $before[$key] = $entry['value'];
            }
        }
        if (! empty($context['dry_run'])) return array('would_restore' => array_keys($entries));
        update_option('GTranslate', $before);
        if ($this->settings() !== $before) {
            throw new RuntimeException('GTranslate rollback readback does not match.');
        }
        return array('restored' => true);
    }

    private function version(): string
    {
        if (! function_exists('get_plugins') || ! function_exists('is_plugin_active')) {
            throw new RuntimeException('WordPress plugin inventory is unavailable.');
        }
        $all = get_plugins();
        $entry = $all['gtranslate/gtranslate.php'] ?? null;
        $version = is_array($entry) ? ($entry['Version'] ?? '') : '';
        if (! is_plugin_active('gtranslate/gtranslate.php') || ! is_string($version)
            || ! preg_match('/^5\\.0\\.[0-9]+$/D', $version)) {
            throw new RuntimeException('GTranslate settings require an active supported 5.0.x installation.');
        }
        return $version;
    }

    private function settings(): array
    {
        $this->version();
        $raw = get_option('GTranslate', null);
        if (! is_array($raw) || count($raw) > 150) {
            throw new RuntimeException('GTranslate provider settings are missing or invalid.');
        }
        return $raw;
    }

    private function publicFields(array $raw): array
    {
        $out = array();
        foreach (array_merge(self::KEYS, self::READ_ONLY) as $key) {
            $value = $raw[$key] ?? null;
            if ($value === null || is_bool($value) || is_int($value)
                || (is_string($value) && strlen($value) <= 100)) {
                $out[$key] = $value;
            } elseif (is_array($value) && count($value) <= 80
                && count(array_filter($value, 'is_string')) === count($value)) {
                $out[$key] = array_values($value);
            } else {
                $out[$key] = '[unsupported_value_withheld]';
            }
        }
        return $out;
    }

    private function validatePatch($fields): array
    {
        if (! is_array($fields) || ! $fields || count($fields) > count(self::KEYS)) {
            throw new RuntimeException('GTranslate update needs 1-4 selected fields.');
        }
        $out = array();
        foreach ($fields as $key => $value) {
            if (! in_array($key, self::KEYS, true)) {
                throw new RuntimeException('GTranslate setting is not in the write allowlist.');
            }
            if ($key === 'default_language') {
                if ($value !== 'nl' && $value !== 'en') {
                    throw new RuntimeException('Supported GTranslate source language must be Dutch or English.');
                }
            } elseif ($key === 'incl_langs' || $key === 'fincl_langs') {
                if (! is_array($value) || array_values(array_unique($value)) !== array('nl', 'en')) {
                    throw new RuntimeException('TPS Pack GTranslate languages must be exactly Dutch and English.');
                }
            } elseif ($value !== 0 && $value !== 1) {
                throw new RuntimeException('GTranslate browser-language detection accepts 0 or 1.');
            }
            $out[$key] = $value;
        }
        return $out;
    }

    private function ensureLanguages(array $raw): void
    {
        // Never leave the source language unavailable in the selected list.
        foreach (array('incl_langs','fincl_langs') as $key) {
            if (! isset($raw[$key]) || ! is_array($raw[$key])
                || ! in_array($raw['default_language'] ?? '', $raw[$key], true)) {
                throw new RuntimeException('GTranslate language selector must include its source language.');
            }
        }
    }

    private function expectedFingerprint($expected, string $actual): void
    {
        if (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/D', $expected)
            || ! hash_equals($actual, $expected)) {
            throw new RuntimeException('GTranslate options have changed since the preflight read.');
        }
    }

    private function assertKeys(array $payload, array $allowed): void
    {
        if (array_diff(array_keys($payload), $allowed)) {
            throw new RuntimeException('Unexpected GTranslate settings request field.');
        }
    }
}
