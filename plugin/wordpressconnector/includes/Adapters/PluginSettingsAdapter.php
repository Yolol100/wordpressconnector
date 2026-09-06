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

    private const INTEGRATIONS = array(
        'acf-content-analysis-for-yoast-seo/yoast-acf-analysis.php' => array('id' => 'acf_yoast_analysis', 'mode' => 'covered_by_acf_and_yoast', 'actions' => array('acf.get', 'acf.update', 'yoast.inspect', 'yoast.update')),
        'acf-page-text-manager/acf-page-text-manager.php' => array('id' => 'acf_page_text_manager', 'mode' => 'project_specific', 'actions' => array('acf.get', 'acf.update')),
        'advanced-custom-fields/acf.php' => array('id' => 'acf', 'mode' => 'native_adapter', 'actions' => array('acf.field_groups', 'acf.get', 'acf.update')),
        'all-in-one-wp-migration/all-in-one-wp-migration.php' => array('id' => 'all_in_one_wp_migration', 'mode' => 'system_operation', 'actions' => array(), 'note' => 'Backup/import/export operations require a separate high-risk staging-first contract.'),
        'wp-asset-clean-up/wpacu.php' => array('id' => 'asset_cleanup', 'mode' => 'version_bound', 'actions' => array(), 'note' => 'Asset unload rules require an installed-version contract plus browser regression readback.'),
        'auto-image-attributes-from-filename-with-bulk-updater/iaff_image-attributes-from-filename.php' => array('id' => 'auto_image_attributes', 'mode' => 'native_allowlist', 'actions' => array('auto_image_attributes.inspect', 'auto_image_attributes.update', 'media.get', 'media.update')),
        'broken-link-checker/broken-link-checker.php' => array('id' => 'broken_link_checker', 'mode' => 'version_bound', 'actions' => array(), 'note' => 'Cloud/local engines and account-bound state require an exact installed-version contract before writes are exposed.'),
        'code-snippets/code-snippets.php' => array('id' => 'code_snippets', 'mode' => 'blocked_arbitrary_code', 'actions' => array(), 'note' => 'Remote arbitrary-code management is intentionally outside the connector contract.'),
        'content-sync-manager/content-sync-manager.php' => array('id' => 'content_sync_manager', 'mode' => 'project_specific', 'actions' => array()),
        'duplicate-page/duplicatepage.php' => array('id' => 'duplicate_page', 'mode' => 'no_special_adapter_needed', 'actions' => array('post.get', 'post.create')),
        'elementor/elementor.php' => array('id' => 'elementor', 'mode' => 'native_adapter', 'actions' => array('elementor.capabilities', 'elementor.inspect', 'elementor.inventory', 'elementor.patch_element', 'elementor.replace_document')),
        'elementor-pro-2/elementor-pro.php' => array('id' => 'elementor_pro', 'mode' => 'native_adapter', 'actions' => array('elementor.capabilities', 'elementor.inspect', 'elementor.inventory', 'elementor.patch_element', 'elementor.replace_document')),
        'gtranslate/gtranslate.php' => array('id' => 'gtranslate', 'mode' => 'version_bound', 'actions' => array()),
        'imagify/imagify.php' => array('id' => 'imagify', 'mode' => 'wordpress_ability', 'actions' => array('plugin.settings.inspect', 'plugin.settings.update')),
        'creame-whatsapp-me/joinchat.php' => array('id' => 'joinchat', 'mode' => 'version_bound', 'actions' => array()),
        'litespeed-cache/litespeed-cache.php' => array('id' => 'litespeed_cache', 'mode' => 'inactive_or_version_bound', 'actions' => array()),
        'loco-translate/loco.php' => array('id' => 'loco_translate', 'mode' => 'filesystem_bound', 'actions' => array()),
        'really-simple-ssl/rlrsssl-really-simple-ssl.php' => array('id' => 'really_simple_security', 'mode' => 'native_settings_api', 'actions' => array('plugin.settings.inspect', 'plugin.settings.update')),
        'google-site-kit/google-site-kit.php' => array('id' => 'site_kit', 'mode' => 'oauth_bound', 'actions' => array(), 'note' => 'OAuth tokens, service connections and encrypted settings are intentionally not exported through GitHub.'),
        'wordfence/wordfence.php' => array('id' => 'wordfence', 'mode' => 'limited_stable_api', 'actions' => array(), 'note' => 'Broad settings writes are withheld until stable public interfaces and safe allowlists are proven.'),
        'wordpressconnector/wordpressconnector.php' => array('id' => 'wordpress_connector', 'mode' => 'canonical_bridge', 'actions' => array('connector.discover', 'connector.actions')),
        'wp-file-manager/file_folder_manager.php' => array('id' => 'wp_file_manager', 'mode' => 'blocked_arbitrary_filesystem', 'actions' => array(), 'note' => 'Generic remote filesystem writes are intentionally outside the connector contract.'),
        'wp-mail-smtp/wp_mail_smtp.php' => array('id' => 'wp_mail_smtp', 'mode' => 'secret_bound', 'actions' => array(), 'note' => 'SMTP passwords, OAuth tokens and provider secrets are never exported through GitHub.'),
        'wp-rocket/wp-rocket.php' => array('id' => 'wp_rocket', 'mode' => 'native_settings_api', 'actions' => array('plugin.settings.inspect', 'plugin.settings.update')),
        'wordpress-seo/wp-seo.php' => array('id' => 'yoast', 'mode' => 'native_adapter', 'actions' => array('yoast.inspect', 'yoast.update')),
        'wordpress-seo-premium/wp-seo-premium.php' => array('id' => 'yoast_premium', 'mode' => 'native_adapter', 'actions' => array('yoast.inspect', 'yoast.update')),
    );

    public function register(Registry $registry): void
    {
        $registry->register('plugin.settings.catalog', array($this, 'catalog'), array(
            'privileged' => true,
            'description' => 'List installed plugins and their safe ChatGPT/GitHub control mode without exposing secrets.',
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

    public function catalog(array $payload = array(), array $context = array()): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $items = array();
        foreach (get_plugins() as $file => $data) {
            $integration = self::INTEGRATIONS[$file] ?? array(
                'id' => sanitize_key((string) dirname($file)),
                'mode' => 'unmodeled',
                'actions' => array(),
                'note' => 'No safe plugin-specific write contract has been modeled yet.',
            );
            $items[] = array_merge(array(
                'file' => (string) $file,
                'name' => isset($data['Name']) ? (string) $data['Name'] : (string) $file,
                'version' => isset($data['Version']) ? (string) $data['Version'] : '',
                'active' => is_plugin_active($file),
            ), $integration);
        }
        usort($items, static function (array $a, array $b): int {
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return array(
            'plugins' => $items,
            'supported_setting_profiles' => array('wp_rocket', 'imagify', 'really_simple_security'),
            'security' => array(
                'raw_secret_export' => false,
                'arbitrary_code' => false,
                'arbitrary_filesystem' => false,
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
        if (! in_array($plugin, array('wp_rocket', 'imagify', 'really_simple_security'), true)) {
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
        return $this->readReallySimpleSecurity();
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
