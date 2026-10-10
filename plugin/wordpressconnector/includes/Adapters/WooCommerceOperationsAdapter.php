<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

/**
 * Higher-risk WooCommerce provider-owned shipping, tax, and payment settings.
 *
 * No direct SQL, arbitrary option updates, order mutation, credential export,
 * payment execution, refunds, or filesystem access. These writes require
 * a site-local gate, verified restore and exact preflight state.
 */
final class WooCommerceOperationsAdapter
{
    private const AREAS = array(
        'shipping_zone' => array('name', 'order'),
        'shipping_method' => array('enabled', 'order', 'settings'),
        'tax_rate' => array('country', 'state', 'postcode', 'city', 'rate', 'name', 'priority', 'compound', 'shipping', 'order', 'class'),
        'payment_gateway' => array('title', 'description', 'order', 'enabled', 'settings'),
    );

    private const SENSITIVE_PATTERN = '/(api[_-]?key|password|passwd|secret|token|credential|consumer|merchant[_-]?id|private[_-]?key|client[_-]?secret|authorization|webhook|private|license|smtp|auth)/i';

    public function register(Registry $registry): void
    {
        $read = array('privileged' => true, 'capability' => 'manage_woocommerce');
        $write = array_merge($read, array('mutation' => true));
        $registry->register('woocommerce.operations.list', array($this, 'lists'), array_merge($read, array(
            'description' => 'Read bounded provider-owned shipping zones/methods, tax rates and gateway summaries.',
        )));
        $registry->register('woocommerce.operations.inspect', array($this, 'inspect'), array_merge($read, array(
            'description' => 'Inspect exactly one shipping zone or method, tax rate, or payment gateway with secret redaction.',
        )));
        $registry->register('woocommerce.operations.update', array($this, 'update'), array_merge($write, array(
            'description' => 'Guarded provider REST update for WooCommerce shipping, tax and payment settings (not secrets).',
        )));
        $registry->register('woocommerce.operations.restore', array($this, 'restore'), array_merge($write, array(
            'description' => 'Rollback-only restore after explicit critical-setting changes.',
        )));
    }

    public function lists(array $payload = array(), array $context = array()): array
    {
        $this->assertKeys($payload, array('area', 'zone_id'));
        $area = $payload['area'] ?? null;
        if (! is_string($area) || ! in_array($area, array(
            'shipping_zones','shipping_methods','tax_rates','payment_gateways'), true)) {
            throw new RuntimeException('Unrecognized WooCommerce operations inventory area.');
        }
        $path = array(
            'shipping_zones' => '/wc/v3/shipping/zones',
            'tax_rates' => '/wc/v3/taxes',
            'payment_gateways' => '/wc/v3/payment_gateways',
        )[$area] ?? '';
        if ($area === 'shipping_methods') {
            $zoneId = $this->number($payload['zone_id'] ?? null, 'zone_id');
            $path = '/wc/v3/shipping/zones/' . $zoneId . '/methods';
        } elseif (array_key_exists('zone_id', $payload)) {
            throw new RuntimeException('Unexpected zone_id for WooCommerce operations inventory.');
        }
        $response = $this->request('GET', $path);
        if (count($response) > 150) {
            throw new RuntimeException('WooCommerce operations inventory exceeds limit.');
        }
        $out = array();
        foreach ($response as $item) {
            if (! is_array($item)) continue;
            if ($area === 'tax_rates') {
                $out[] = $this->summarize($item, 'tax_rate');
            } elseif ($area === 'payment_gateways') {
                $out[] = $this->summarize($item, 'payment_gateway');
            } elseif ($area === 'shipping_methods') {
                $out[] = $this->summarize($item, 'shipping_method');
            } else {
                $out[] = $this->summarize($item, 'shipping_zone');
            }
        }
        return array('area' => $area, 'items' => $out, 'count' => count($out));
    }

    public function inspect(array $payload, array $context = array()): array
    {
        $this->assertKeys($payload, array('area', 'id', 'zone_id'));
        $area = $this->area($payload);
        $path = $this->path($area, $payload);
        $data = $this->request('GET', $path);
        $clean = $this->summarize($data, $area);
        return array(
            'area' => $area,
            'item' => $clean,
            'fingerprint' => Fingerprint::make($clean),
        );
    }

    public function update(array $payload, array $context): array
    {
        $this->assertKeys($payload, array(
            'area', 'id', 'zone_id', 'fields', 'critical_confirm',
            'restore_verified', 'expected_before_fingerprint',
            'sandbox_verified',
        ));
        $area = $this->area($payload);
        $path = $this->path($area, $payload);
        $fields = $payload['fields'] ?? null;
        if (! is_array($fields) || !$fields || count($fields) > 12) {
            throw new RuntimeException('WooCommerce operations write requires 1-12 explicitly selected fields.');
        }
        $raw = $this->request('GET', $path);
        $before = $this->summarize($raw, $area);
        $fingerprint = Fingerprint::make($before);
        $updates = $this->validateChanges($area, $fields, $raw);
        $result = array(
            'area' => $area,
            'before' => $before,
            'requested' => $updates,
            '_current_fingerprint' => $fingerprint,
        );
        if (! empty($context['dry_run'])) {
            return $result;
        }
        $this->criticalGate($payload, $fingerprint);
        if ($area === 'payment_gateway' && ! empty($updates['enabled'])
            && $updates['enabled'] === true
            && (! defined('WPCONNECTOR_ALLOW_WOO_PAYMENT_ENABLE')
                || WPCONNECTOR_ALLOW_WOO_PAYMENT_ENABLE !== true
                || ($payload['sandbox_verified'] ?? null) !== true)) {
            throw new RuntimeException('Enabling payment gateways requires a separate site-local payment gate and sandbox verification.');
        }
        $previous = array();
        foreach ($updates as $key => $value) {
            if ($key === 'settings') {
                $previous[$key] = array();
                foreach ($value as $setting => $newValue) {
                    if (! isset($raw['settings'][$setting]) || ! array_key_exists('value', $raw['settings'][$setting])) {
                        throw new RuntimeException('Provider settings readback cannot produce rollback data.');
                    }
                    $previous[$key][$setting] = $raw['settings'][$setting]['value'];
                }
            } else {
                if (! array_key_exists($key, $raw)) {
                    throw new RuntimeException('Provider option is missing rollback data.');
                }
                $previous[$key] = $raw[$key];
            }
        }
        $this->request('PUT', $path, $updates);
        $afterRaw = $this->request('GET', $path);
        if (! $this->matches($afterRaw, $updates)) {
            try {
                $this->request('PUT', $path, $previous);
            } catch (Throwable $error) {
                throw new RuntimeException('WooCommerce operations readback failed; automatic recovery is unverified.');
            }
            if (! $this->matches($this->request('GET', $path), $previous)) {
                throw new RuntimeException('WooCommerce operations readback failed and rollback did not match.');
            }
            throw new RuntimeException('WooCommerce provider did not retain requested settings; previous state restored.');
        }
        $after = $this->summarize($afterRaw, $area);
        $result['after'] = $after;
        $result['_rollback'] = array(
            'action' => 'woocommerce.operations.restore',
            'payload' => array(
                'area' => $area,
                'id' => $payload['id'],
                'zone_id' => $payload['zone_id'] ?? null,
                'fields' => $previous,
                'expected_after_fingerprint' => Fingerprint::make($after),
            ),
        );
        return $result;
    }

    public function restore(array $payload, array $context): array
    {
        if (empty($context['rollback_mode'])) {
            throw new RuntimeException('WooCommerce provider restoration is rollback-only.');
        }
        $this->assertKeys($payload, array('area', 'id', 'zone_id', 'fields', 'expected_after_fingerprint'));
        $area = $this->area($payload);
        $path = $this->path($area, $payload);
        $beforeRaw = $this->request('GET', $path);
        $before = $this->summarize($beforeRaw, $area);
        $expected = $payload['expected_after_fingerprint'] ?? null;
        if (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/D', $expected)
            || ! hash_equals(Fingerprint::make($before), $expected)) {
            throw new RuntimeException('WooCommerce provider rollback blocked due to changed remote state.');
        }
        $changes = $this->validateChanges($area, $payload['fields'] ?? null, $beforeRaw);
        if (! empty($context['dry_run'])) {
            return array('would_restore' => array_keys($changes));
        }
        $this->request('PUT', $path, $changes);
        if (! $this->matches($this->request('GET', $path), $changes)) {
            throw new RuntimeException('WooCommerce provider rollback readback failed.');
        }
        return array('restored' => true, 'area' => $area);
    }

    private function summarize(array $data, string $area): array
    {
        $out = array();
        $keys = $area === 'shipping_zone' ? array('id', 'name', 'order')
            : ($area === 'shipping_method' ? array('instance_id', 'method_id', 'title', 'order', 'enabled')
            : ($area === 'tax_rate' ? array('id','country','state','postcode','city','rate','name','priority','compound','shipping','order','class')
            : array('id','title','description','order','enabled','method_title')));
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $this->safePublicValue($data[$key]);
            }
        }
        if (in_array($area, array('shipping_method','payment_gateway'), true)
            && isset($data['settings']) && is_array($data['settings'])) {
            $options = array();
            foreach (array_slice($data['settings'], 0, 150, true) as $id => $setting) {
                if (! is_string($id) || ! preg_match('/^[a-z0-9_\-]{1,100}$/D', $id)
                    || ! is_array($setting)) {
                    continue;
                }
                $type = isset($setting['type']) && is_string($setting['type']) ? $setting['type'] : '';
                $secret = $this->secret($id, $type);
                $option = array(
                    'id' => $id, 'type' => substr($type,0,40),
                    'mode' => $secret ? 'secret_blocked' : 'critical_requires_site_gate',
                );
                if (! $secret && array_key_exists('value', $setting)) {
                    $option['value'] = $this->safePublicValue($setting['value']);
                }
                $options[$id] = $option;
            }
            $out['settings'] = $options;
        }
        return $out;
    }

    private function validateChanges(string $area, $fields, array $before): array
    {
        if (! is_array($fields) || !$fields || count($fields) > 12) {
            throw new RuntimeException('Invalid WooCommerce provider settings payload.');
        }
        $updates = array();
        foreach ($fields as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::AREAS[$area], true)
                || ! array_key_exists($key, $before)) {
                throw new RuntimeException('WooCommerce provider setting is unavailable or disallowed.');
            }
            if ($key === 'settings') {
                if (! in_array($area, array('payment_gateway', 'shipping_method'), true)
                    || ! is_array($value) || !$value || count($value) > 12 || ! is_array($before['settings'])) {
                    throw new RuntimeException('Unsupported WooCommerce provider-specific settings payload.');
                }
                $options = array();
                foreach ($value as $optionId => $desired) {
                    if (! is_string($optionId) || ! preg_match('/^[a-z0-9_\-]{1,100}$/D', $optionId)
                        || ! isset($before['settings'][$optionId])
                        || $this->secret($optionId, (string) ($before['settings'][$optionId]['type'] ?? ''))
                        || ($area === 'payment_gateway'
                            && preg_match('/(live|test|mode|environment|capture|refund|webhook|checkout|subscription|settlement)/i', $optionId))) {
                        throw new RuntimeException('Protected WooCommerce provider credential or setting.');
                    }
                    $field = $before['settings'][$optionId];
                    $type = (string) ($field['type'] ?? '');
                    if (! is_string($desired) || strlen($desired) > 300) {
                        throw new RuntimeException('Provider setting value must be a bounded string.');
                    }
                    if ($type === 'checkbox' && ! in_array($desired, array('yes','no'), true)) {
                        throw new RuntimeException('Provider checkbox value must be yes or no.');
                    }
                    if ($type === 'number' && ! preg_match('/^\\d{1,10}(?:\\.\\d{1,4})?$/D', $desired)) {
                        throw new RuntimeException('Provider numeric setting contains unsupported data.');
                    }
                    if (! in_array($type, array('text','textarea','checkbox','number','select','radio'), true)
                        || preg_match('/[<>\\x00-\\x1F]/', $desired)) {
                        throw new RuntimeException('Provider field requires a dedicated validation contract.');
                    }
                    if ($type === 'select' && isset($field['options']) && is_array($field['options'])
                        && ! array_key_exists($desired, $field['options'])) {
                        throw new RuntimeException('Provider dropdown value is not recognized.');
                    }
                    $options[$optionId] = $desired;
                }
                $updates[$key] = $options;
                continue;
            }
            if ($key === 'enabled' || $key === 'compound' || $key === 'shipping') {
                if (! is_bool($value)) throw new RuntimeException('Provider boolean setting must be true or false.');
            } elseif (in_array($key, array('order','priority'), true)) {
                if (! is_int($value) || $value < 0 || $value > 100000) {
                    throw new RuntimeException('Provider sort order or priority is outside bounds.');
                }
            } elseif (in_array($key, array('postcode','city'), true)) {
                if (! is_array($value) || count($value) > 60) {
                    throw new RuntimeException('Provider geographic constraint exceeds limit.');
                }
                foreach ($value as $place) {
                    if (! is_string($place) || strlen($place) > 80 || preg_match('/[<>\\r\\n]/', $place)) {
                        throw new RuntimeException('Provider geographic constraint is invalid.');
                    }
                }
            } else {
                if (! is_string($value) || strlen($value) > 255 || preg_match('/[<>\\r\\n]/', $value)) {
                    throw new RuntimeException('Provider text setting is invalid.');
                }
                if ($key === 'rate' && ! preg_match('/^\\d{1,2}(?:\\.\\d{1,4})?$/D', $value)) {
                    throw new RuntimeException('Tax rate requires an explicit decimal percentage.');
                }
                if ($key === 'country' && $value !== '' && ! preg_match('/^[A-Z]{2}$/D', $value)) {
                    throw new RuntimeException('Tax country requires an ISO 3166 code.');
                }
                if ($key === 'state' && strlen($value) > 32) {
                    throw new RuntimeException('Tax state exceeds safe length.');
                }
            }
            $updates[$key] = $value;
        }
        return $updates;
    }

    private function matches(array $actual, array $expected): bool
    {
        foreach ($expected as $key => $value) {
            if ($key === 'settings') {
                foreach ($value as $optionId => $wanted) {
                    if (! isset($actual['settings'][$optionId])
                        || ($actual['settings'][$optionId]['value'] ?? null) !== $wanted) {
                        return false;
                    }
                }
            } elseif (! array_key_exists($key, $actual) || $actual[$key] !== $value) {
                return false;
            }
        }
        return true;
    }

    private function criticalGate(array $payload, string $fingerprint): void
    {
        $expected = $payload['expected_before_fingerprint'] ?? null;
        if (! defined('WPCONNECTOR_ALLOW_WOO_CRITICAL') || WPCONNECTOR_ALLOW_WOO_CRITICAL !== true
            || ($payload['critical_confirm'] ?? null) !== true
            || ($payload['restore_verified'] ?? null) !== true) {
            throw new RuntimeException('WooCommerce provider settings require site-local critical gate and verified restore.');
        }
        if (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/D', $expected)
            || ! hash_equals($fingerprint, $expected)) {
            throw new RuntimeException('WooCommerce provider settings have changed since preview.');
        }
    }

    private function safePublicValue($value)
    {
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) return $value;
        if (is_string($value)) return strlen($value) > 800 ? '[long_value_withheld]' : sanitize_text_field($value);
        if (is_array($value)) {
            if (count($value) > 80) return '[large_array_withheld]';
            foreach ($value as $v) {
                if (! is_scalar($v)) return '[complex_array_withheld]';
            }
            return $value;
        }
        return '[complex_value_withheld]';
    }

    private function secret(string $id, string $type): bool
    {
        return $type === 'password' || (bool) preg_match(self::SENSITIVE_PATTERN, $id);
    }

    private function area(array $payload): string
    {
        $area = $payload['area'] ?? null;
        if (! is_string($area) || ! isset(self::AREAS[$area])) {
            throw new RuntimeException('Unknown WooCommerce provider settings area.');
        }
        return $area;
    }

    private function path(string $area, array $payload): string
    {
        $id = $payload['id'] ?? null;
        if ($area === 'payment_gateway') {
            if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9_\-]{1,95}$/D', $id)) {
                throw new RuntimeException('Invalid WooCommerce payment gateway ID.');
            }
            return '/wc/v3/payment_gateways/' . $id;
        }
        $id = $this->number($id, 'id');
        if ($area === 'shipping_method') {
            $zone = $this->number($payload['zone_id'] ?? null, 'zone_id');
            return '/wc/v3/shipping/zones/' . $zone . '/methods/' . $id;
        }
        if (array_key_exists('zone_id', $payload) && $payload['zone_id'] !== null) {
            throw new RuntimeException('Unexpected zone ID.');
        }
        if ($area === 'shipping_zone') return '/wc/v3/shipping/zones/' . $id;
        return '/wc/v3/taxes/' . $id;
    }

    private function number($value, string $field): int
    {
        if (! is_int($value) || $value < 1 || $value > 2147483647) {
            throw new RuntimeException('WooCommerce ' . $field . ' must be a positive integer.');
        }
        return $value;
    }

    private function assertKeys(array $payload, array $allowed): void
    {
        if (array_diff(array_keys($payload), $allowed)) {
            throw new RuntimeException('Unexpected WooCommerce provider settings arguments.');
        }
    }

    private function request(string $method, string $path, array $params = array()): array
    {
        if (! class_exists('WP_REST_Request') || ! function_exists('rest_do_request')) {
            throw new RuntimeException('WooCommerce REST runtime is unavailable.');
        }
        $request = new \WP_REST_Request($method, $path);
        foreach ($params as $key => $value) $request->set_param($key, $value);
        $response = rest_do_request($request);
        if (! is_object($response) || ! method_exists($response, 'get_status')
            || ! method_exists($response, 'get_data')) {
            throw new RuntimeException('WooCommerce provider REST response is invalid.');
        }
        $status = (int) $response->get_status();
        if ($status < 200 || $status >= 300 || ! is_array($response->get_data())) {
            throw new RuntimeException('WooCommerce provider REST request failed: HTTP ' . $status . '.');
        }
        return $response->get_data();
    }
}
