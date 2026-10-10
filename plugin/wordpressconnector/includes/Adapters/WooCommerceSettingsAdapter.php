<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * WooCommerce-owned Settings REST API facade. Settings groups and the
 * admin tab/section tree are discovered instead of guessed.
 *
 * The inventory is intentionally broader than the update allowlist:
 * shipping rates, payment credentials, tax policy, checkout routes and
 * third-party settings each require a dedicated staging/restore contract.
 */
final class WooCommerceSettingsAdapter
{
    private const EDITABLE = array(
        'general' => array(
            'woocommerce_currency_pos' => true,
            'woocommerce_price_thousand_sep' => true,
            'woocommerce_price_decimal_sep' => true,
        ),
        'products' => array(
            'woocommerce_weight_unit' => true,
            'woocommerce_dimension_unit' => true,
            'woocommerce_enable_reviews' => true,
            'woocommerce_enable_review_rating' => true,
            'woocommerce_review_rating_required' => true,
            'woocommerce_review_rating_verification_required' => true,
            'woocommerce_enable_verified_owner_label' => true,
        ),
    );

    private const PRIVATE_PATTERN = '/(password|secret|token|auth|api[_-]?key|consumer|credential|private|license|webhook|smtp|recipient|authorization|client[_-]?id|client[_-]?secret|session)/i';

    public function register(Registry $registry): void
    {
        $read = array('privileged' => true, 'capability' => 'manage_woocommerce');
        $write = array_merge($read, array('mutation' => true));

        $registry->register('woocommerce.settings.catalog', array($this, 'catalog'), array_merge($read, array(
            'description' => 'Discover real WooCommerce Settings REST groups and available admin tabs and subtabs; no option values.',
        )));
        $registry->register('woocommerce.settings.inspect', array($this, 'inspect'), array_merge($read, array(
            'description' => 'Read one bounded WooCommerce settings group; redact secrets and mark non-allowlisted fields read-only.',
        )));
        $registry->register('woocommerce.settings.update', array($this, 'update'), array_merge($write, array(
            'description' => 'Update one allowlisted WooCommerce setting through the official REST controller, with dry-run/readback/rollback.',
        )));
        $registry->register('woocommerce.settings.restore', array($this, 'restore'), array_merge($write, array(
            'description' => 'Rollback-only restoration of one WooCommerce-owned setting with stale-state protection.',
        )));
    }

    public function catalog(array $payload = array(), array $context = array()): array
    {
        $this->rejectExtra($payload, array());
        $groups = $this->api('GET', '/wc/v3/settings');
        if (count($groups) > 80) {
            throw new RuntimeException('WooCommerce settings group inventory exceeds safe limit.');
        }
        $result = array();
        foreach ($groups as $group) {
            if (! is_array($group) || empty($group['id'])) {
                continue;
            }
            $id = (string) $group['id'];
            if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $id)) {
                continue;
            }
            $result[] = array(
                'id' => $id,
                'label' => $this->shortText($group['label'] ?? $id),
                'parent_id' => $this->shortText($group['parent_id'] ?? ''),
                'sub_groups' => $this->stringIds($group['sub_groups'] ?? array(), 40),
                'source' => 'woocommerce_rest_v3',
                'settings_endpoint_available' => true,
            );
        }
        return array(
            'groups' => $result,
            'admin_navigation' => $this->adminNavigation(),
            'coverage' => 'all_exposed_groups_readable; only_explicit_allowlist_mutable',
            'outside_settings_api' => array(
                'shipping_zones_and_methods', 'tax_rates_and_classes', 'payment_gateway_credentials',
                'email_delivery_credentials', 'download_directory_rules', 'webhooks_and_api_keys',
                'third_party_mollie_settings', 'point_of_sale_provider_settings',
            ),
        );
    }

    public function inspect(array $payload, array $context = array()): array
    {
        $this->rejectExtra($payload, array('group', 'offset', 'limit'));
        $group = $this->groupId($payload);
        $offset = $this->boundedInt($payload['offset'] ?? 0, 0, 2000, 'offset');
        $limit = $this->boundedInt($payload['limit'] ?? 25, 1, 50, 'limit');
        $this->assertGroupExists($group);

        $items = $this->api('GET', '/wc/v3/settings/' . $group);
        if (count($items) > 2000) {
            throw new RuntimeException('WooCommerce settings group exceeds safe inventory size.');
        }
        $fields = array();
        foreach (array_slice($items, $offset, $limit) as $option) {
            if (! is_array($option)) {
                continue;
            }
            $id = isset($option['id']) ? (string) $option['id'] : '';
            $type = isset($option['type']) ? (string) $option['type'] : '';
            if (! preg_match('/^[a-z][a-z0-9_]{0,119}$/D', $id)) {
                continue;
            }
            $private = $this->isPrivate($id, $type);
            $mode = $private ? 'secret_blocked'
                : ($this->editable($group, $id) ? 'editable' : 'read_only_review_required');

            $field = array(
                'id' => $id,
                'label' => $this->shortText($option['label'] ?? $id),
                'type' => $this->shortText($type),
                'mode' => $mode,
                'value_state' => $private ? 'withheld' : 'visible',
                'group' => $group,
            );
            if (! $private) {
                $field['value'] = $this->safeValue($option['value'] ?? null);
                if (isset($option['options']) && is_array($option['options'])) {
                    $field['option_count'] = count($option['options']);
                }
            }
            $fields[] = $field;
        }
        return array(
            'group' => $group,
            'total' => count($items),
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => $offset + $limit < count($items),
            'fields' => $fields,
        );
    }

    public function update(array $payload, array $context): array
    {
        $this->rejectExtra($payload, array('group', 'id', 'value'));
        $group = $this->groupId($payload);
        $id = $this->settingId($payload);
        if (! $this->editable($group, $id) || $this->isPrivate($id, '')) {
            throw new RuntimeException('WooCommerce setting requires a separate guarded provider workflow.');
        }
        if (! array_key_exists('value', $payload)) {
            throw new RuntimeException('Missing WooCommerce setting value.');
        }
        $this->assertGroupExists($group);
        $before = $this->item($group, $id);
        $desired = $this->validateValue($payload['value'], $before);
        $previous = $before['value'] ?? null;
        $fingerprint = $this->valueFingerprint($group, $id, $previous);
        $result = array(
            'group' => $group,
            'id' => $id,
            'before' => $this->safeValue($previous),
            'after' => $this->safeValue($desired),
            '_current_fingerprint' => $fingerprint,
        );
        if (! empty($context['dry_run'])) {
            return $result;
        }

        $this->api('PUT', $this->itemPath($group, $id), array('value' => $desired));
        $readback = $this->item($group, $id);
        if (($readback['value'] ?? null) !== $desired) {
            // Best-effort compensation for a provider that rewrites/rejects the
            // value after an apparent success. Never report a verified write.
            try {
                $this->api('PUT', $this->itemPath($group, $id), array('value' => $previous));
            } catch (Throwable $error) {
                throw new RuntimeException('WooCommerce setting readback failed; automatic restore was not verified.');
            }
            $restored = $this->item($group, $id);
            if (($restored['value'] ?? null) !== $previous) {
                throw new RuntimeException('WooCommerce setting readback failed and previous state was not restored.');
            }
            throw new RuntimeException('WooCommerce setting was not accepted; previous state restored.');
        }

        $result['after'] = $this->safeValue($readback['value']);
        $result['_rollback'] = array(
            'action' => 'woocommerce.settings.restore',
            'payload' => array(
                'group' => $group,
                'id' => $id,
                'value' => $previous,
                'expected_after_fingerprint' => $this->valueFingerprint($group, $id, $desired),
            ),
        );
        return $result;
    }

    public function restore(array $payload, array $context): array
    {
        if (empty($context['rollback_mode'])) {
            throw new RuntimeException('WooCommerce setting restore is rollback-only.');
        }
        $this->rejectExtra($payload, array('group', 'id', 'value', 'expected_after_fingerprint'));
        $group = $this->groupId($payload);
        $id = $this->settingId($payload);
        if (! $this->editable($group, $id) || ! array_key_exists('value', $payload)) {
            throw new RuntimeException('Unsafe WooCommerce setting rollback payload.');
        }
        $before = $this->item($group, $id);
        $fingerprint = $this->valueFingerprint($group, $id, $before['value'] ?? null);
        $expected = $payload['expected_after_fingerprint'] ?? null;
        if (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/D', $expected)
            || ! hash_equals($fingerprint, $expected)) {
            throw new RuntimeException('WooCommerce setting rollback is unsafe: value changed since write.');
        }
        $previous = $this->validateValue($payload['value'], $before);
        if (! empty($context['dry_run'])) {
            return array('would_restore' => $id, '_current_fingerprint' => $fingerprint);
        }
        $this->api('PUT', $this->itemPath($group, $id), array('value' => $previous));
        $readback = $this->item($group, $id);
        if (($readback['value'] ?? null) !== $previous) {
            throw new RuntimeException('WooCommerce setting rollback readback failed.');
        }
        return array('restored' => true, 'group' => $group, 'id' => $id);
    }

    private function adminNavigation(): array
    {
        if (! class_exists('WC_Admin_Settings')) {
            if (! function_exists('WC') || ! is_object(WC()) || ! method_exists(WC(), 'plugin_path')) {
                return array('available' => false, 'reason' => 'admin_settings_not_loaded');
            }
            $path = WC()->plugin_path() . '/includes/admin/class-wc-admin-settings.php';
            if (! is_readable($path)) {
                return array('available' => false, 'reason' => 'admin_settings_not_available');
            }
            require_once $path;
        }
        if (! method_exists('WC_Admin_Settings', 'get_settings_pages')) {
            return array('available' => false, 'reason' => 'admin_settings_unsupported');
        }
        try {
            $pages = \WC_Admin_Settings::get_settings_pages();
            if (! is_array($pages) || count($pages) > 50) {
                return array('available' => false, 'reason' => 'admin_settings_inventory_invalid');
            }
            $tabs = array();
            foreach ($pages as $page) {
                if (! is_object($page) || ! method_exists($page, 'get_id')
                    || ! method_exists($page, 'get_label') || ! method_exists($page, 'get_sections')) {
                    continue;
                }
                $tabId = (string) $page->get_id();
                if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $tabId)) {
                    continue;
                }
                $sections = $page->get_sections();
                $subtabs = array();
                if (is_array($sections)) {
                    foreach (array_slice($sections, 0, 60, true) as $id => $label) {
                        $subtabs[] = array('id' => $this->shortText((string) $id), 'label' => $this->shortText($label));
                    }
                }
                $tabs[] = array(
                    'id' => $tabId,
                    'label' => $this->shortText($page->get_label()),
                    'subtabs' => $subtabs,
                    'provider' => in_array($tabId, array(
                        'general', 'products', 'shipping', 'checkout', 'account', 'email',
                        'integration', 'advanced', 'tax', 'site_visibility', 'point_of_sale',
                    ), true) ? 'woocommerce' : 'extension_or_custom',
                );
            }
            return array('available' => true, 'tabs' => $tabs);
        } catch (Throwable $error) {
            return array('available' => false, 'reason' => 'admin_settings_inventory_failed');
        }
    }

    private function assertGroupExists(string $group): void
    {
        $groups = $this->api('GET', '/wc/v3/settings');
        foreach ($groups as $entry) {
            if (is_array($entry) && ($entry['id'] ?? '') === $group) {
                return;
            }
        }
        throw new RuntimeException('WooCommerce Settings REST group is not registered.');
    }

    private function item(string $group, string $id): array
    {
        $item = $this->api('GET', $this->itemPath($group, $id));
        if (! isset($item['id']) || $item['id'] !== $id) {
            throw new RuntimeException('WooCommerce setting identity readback mismatch.');
        }
        return $item;
    }

    private function itemPath(string $group, string $id): string
    {
        return '/wc/v3/settings/' . $group . '/' . $id;
    }

    private function api(string $method, string $path, array $params = array()): array
    {
        if (! class_exists('WP_REST_Request') || ! function_exists('rest_do_request')) {
            throw new RuntimeException('WordPress REST runtime is unavailable.');
        }
        $request = new \WP_REST_Request($method, $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $response = rest_do_request($request);
        if (! is_object($response) || ! method_exists($response, 'get_status') || ! method_exists($response, 'get_data')) {
            throw new RuntimeException('WooCommerce Settings REST API did not return a valid response.');
        }
        if ($response->get_status() < 200 || $response->get_status() >= 300) {
            throw new RuntimeException('WooCommerce Settings REST request was rejected; HTTP ' . (int) $response->get_status() . '.');
        }
        $data = $response->get_data();
        if (! is_array($data)) {
            throw new RuntimeException('WooCommerce Settings REST response has an invalid schema.');
        }
        return $data;
    }

    private function validateValue($value, array $option)
    {
        $type = (string) ($option['type'] ?? '');
        if (! is_string($value) || strlen($value) > 128) {
            throw new RuntimeException('WooCommerce setting update expects a bounded string.');
        }
        if ('checkbox' === $type && ! in_array($value, array('yes', 'no'), true)) {
            throw new RuntimeException('WooCommerce checkbox accepts yes or no.');
        }
        if (in_array($type, array('select', 'radio'), true)
            && (! isset($option['options']) || ! is_array($option['options'])
                || ! array_key_exists($value, $option['options']))) {
            throw new RuntimeException('WooCommerce selection does not match the provider options.');
        }
        if ('number' === $type && ! preg_match('/^\d{1,3}$/D', $value)) {
            throw new RuntimeException('WooCommerce numeric setting is outside the supported range.');
        }
        if (! in_array($type, array('select', 'radio', 'checkbox', 'text', 'number'), true)) {
            throw new RuntimeException('WooCommerce setting type is not supported for automated changes.');
        }
        if (in_array($type, array('text', 'number'), true) && preg_match('/[<>\r\n]/', $value)) {
            throw new RuntimeException('Unsafe WooCommerce text setting value.');
        }
        return $value;
    }

    private function valueFingerprint(string $group, string $id, $value): string
    {
        return Fingerprint::make(array('group' => $group, 'id' => $id, 'value' => $value));
    }

    private function groupId(array $payload): string
    {
        $id = $payload['group'] ?? null;
        if (! is_string($id) || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $id)) {
            throw new RuntimeException('WooCommerce Settings group must be a canonical ID.');
        }
        return $id;
    }

    private function settingId(array $payload): string
    {
        $id = $payload['id'] ?? null;
        if (! is_string($id) || ! preg_match('/^woocommerce_[a-z0-9_]{1,100}$/D', $id)) {
            throw new RuntimeException('WooCommerce setting requires a canonical option ID.');
        }
        return $id;
    }

    private function editable(string $group, string $id): bool
    {
        return isset(self::EDITABLE[$group][$id]);
    }

    private function isPrivate(string $id, string $type): bool
    {
        return 'password' === $type || (bool) preg_match(self::PRIVATE_PATTERN, $id);
    }

    private function safeValue($value)
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return strlen($value) > 1024 ? '[long_text_withheld]' : sanitize_text_field($value);
        }
        return '[complex_value_withheld]';
    }

    private function shortText($value): string
    {
        return is_scalar($value) ? substr(sanitize_text_field((string) $value), 0, 160) : '';
    }

    private function stringIds($value, int $limit): array
    {
        if (! is_array($value)) {
            return array();
        }
        $ids = array();
        foreach (array_slice($value, 0, $limit) as $id) {
            if (is_string($id) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function boundedInt($value, int $min, int $max, string $field): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new RuntimeException('WooCommerce settings ' . $field . ' is outside its bounds.');
        }
        return $value;
    }

    private function rejectExtra(array $payload, array $keys): void
    {
        if (array_diff(array_keys($payload), $keys)) {
            throw new RuntimeException('Unexpected WooCommerce settings request parameters.');
        }
    }
}
