<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

final class AbilitiesAdapter
{
    public function register(Registry $registry): void
    {
        $registry->register('wordpress.abilities', array($this, 'catalog'), array(
            'privileged' => true,
            'description' => 'Discover exposed WordPress Abilities API entries and their schemas without executing them.',
        ));
        $registry->register('wordpress.ability.read', array($this, 'readAbility'), array(
            'privileged' => true,
            'sensitive' => true,
            'capability' => 'manage_options',
            'description' => 'Read through a REST-exposed, explicitly read-only WordPress Ability and its native input validation and permission callback.',
        ));
    }

    public function catalog(array $payload, array $context): array
    {
        if (! function_exists('wp_get_abilities')) {
            return array(
                'available' => false,
                'abilities' => array(),
                'reason' => 'WordPress Abilities API is not available on this site.',
            );
        }

        $perPage = $this->catalogPositiveInteger($payload['per_page'] ?? 10, 10, 'per_page');
        $page = $this->catalogPositiveInteger($payload['page'] ?? 1, 100000, 'page');

        $registered = (array) wp_get_abilities();
        if (count($registered) > 10000) {
            throw new \RuntimeException('WordPress Ability catalog exceeds the discovery limit.');
        }

        $eligible = array();
        foreach ($registered as $name => $ability) {
            if (! is_object($ability)) {
                continue;
            }

            if (! is_string($name) || '' === $name) {
                $name = method_exists($ability, 'get_name') ? (string) $ability->get_name() : '';
            }

            if (! $this->isValidName($name) || ! $this->isExposed($ability)) {
                continue;
            }

            $eligible[$name] = $ability;
        }

        ksort($eligible, SORT_STRING);
        $total = count($eligible);
        $pages = max(1, (int) ceil($total / $perPage));
        $selected = array_slice($eligible, ($page - 1) * $perPage, $perPage, true);

        $result = array();
        foreach ($selected as $name => $ability) {
            $result[$name] = $this->descriptor($name, $ability);
        }

        $response = array(
            'available' => true,
            'abilities' => $result,
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
        );
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($response) : json_encode($response);
        if (! is_string($encoded) || strlen($encoded) > 262144) {
            throw new \RuntimeException('WordPress Ability catalog page exceeds the output limit.');
        }

        return $response;
    }

    public function readAbility(array $payload, array $context): array
    {
        if (! function_exists('wp_get_ability')) {
            throw new \RuntimeException('WordPress Abilities API is not available on this site.');
        }
        $name = isset($payload['name']) && is_string($payload['name']) ? $payload['name'] : '';
        if (! $this->isValidName($name)) {
            throw new \RuntimeException('A valid namespace/ability name is required.');
        }
        $ability = wp_get_ability($name);
        if (! is_object($ability) || ! $this->isReadEligible($ability)) {
            throw new \RuntimeException('The requested REST-exposed WordPress Ability was not found.');
        }
        $input = array_key_exists('input', $payload) ? $payload['input'] : null;
        try {
            $result = $ability->execute($input);
        } catch (\Throwable $error) {
            throw new \RuntimeException('WordPress Ability failed.');
        }
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            throw new \RuntimeException('WordPress Ability failed.');
        }
        $budget = array('nodes' => 0, 'bytes' => 0);
        $safe = $this->redactAbilityResult($result, 0, new \SplObjectStorage(), $budget);
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($safe) : json_encode($safe);
        if (! is_string($encoded) || strlen($encoded) > 262144) throw new \RuntimeException('WordPress Ability result exceeds the output limit.');
        return array('name' => $name, 'result' => $safe);
    }

    private function redactAbilityResult($value, int $depth, \SplObjectStorage $seen, array &$budget)
    {
        if (++$budget['nodes'] > 10000 || $depth > 20) throw new \RuntimeException('WordPress Ability result exceeds the traversal limit.');
        $object = null;
        if (is_object($value)) {
            if ($seen->contains($value)) return '[circular object omitted]';
            $seen->attach($value);
            $object = $value;
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $budget['bytes'] += strlen($key);
                    if ($budget['bytes'] > 262144) throw new \RuntimeException('WordPress Ability result exceeds the output limit.');
                }
                $value[$key] = $this->redactAbilityResult($item, $depth + 1, $seen, $budget);
            }
            $value = Policy::redact($value);
            if (null !== $object) $seen->detach($object);
            return $value;
        }
        if (is_string($value)) {
            $budget['bytes'] += strlen($value);
            if ($budget['bytes'] > 262144) throw new \RuntimeException('WordPress Ability result exceeds the output limit.');
            return $value;
        }
        return is_scalar($value) || null === $value ? $value : null;
    }

    private function isValidName(string $name): bool
    {
        return 1 === preg_match('/^[a-z0-9][a-z0-9_-]*\/[a-z0-9][a-z0-9_-]*$/D', $name);
    }

    private function isRestExposed(object $ability): bool
    {
        $meta = method_exists($ability, 'get_meta') ? $ability->get_meta() : array();
        return is_array($meta) && true === ($meta['show_in_rest'] ?? false);
    }

    private function isReadEligible(object $ability): bool
    {
        if (! $this->isRestExposed($ability) || ! method_exists($ability, 'execute')) return false;
        $meta = method_exists($ability, 'get_meta') ? $ability->get_meta() : array();
        $annotations = is_array($meta) && isset($meta['annotations']) && is_array($meta['annotations']) ? $meta['annotations'] : array();
        return true === ($annotations['readonly'] ?? false) && false === ($annotations['destructive'] ?? null);
    }

    private function isExposed(object $ability): bool
    {
        $meta = method_exists($ability, 'get_meta') ? $ability->get_meta() : array();
        if (! is_array($meta)) {
            return false;
        }

        if (true === ($meta['public'] ?? false) || true === ($meta['show_in_rest'] ?? false)) {
            return true;
        }

        return isset($meta['mcp']) && is_array($meta['mcp']) && true === ($meta['mcp']['public'] ?? false);
    }

    private function descriptor(string $name, object $ability): array
    {
        $meta = method_exists($ability, 'get_meta') ? $ability->get_meta() : array();
        $annotations = is_array($meta) && isset($meta['annotations']) && is_array($meta['annotations'])
            ? $meta['annotations']
            : array();
        $safeAnnotations = array();
        foreach (array('readonly', 'destructive', 'idempotent') as $key) {
            if (array_key_exists($key, $annotations) && (is_bool($annotations[$key]) || null === $annotations[$key])) {
                $safeAnnotations[$key] = $annotations[$key];
            }
        }

        list($inputSchema, $inputSchemaOmitted) = $this->boundedCatalogValue(
            method_exists($ability, 'get_input_schema') ? $ability->get_input_schema() : null
        );
        list($outputSchema, $outputSchemaOmitted) = $this->boundedCatalogValue(
            method_exists($ability, 'get_output_schema') ? $ability->get_output_schema() : null
        );

        return array(
            'name' => $name,
            'label' => $this->boundedCatalogText(method_exists($ability, 'get_label') ? (string) $ability->get_label() : $name, 512),
            'description' => $this->boundedCatalogText(method_exists($ability, 'get_description') ? (string) $ability->get_description() : '', 4096),
            'category' => $this->boundedCatalogText(method_exists($ability, 'get_category') ? (string) $ability->get_category() : '', 512),
            'input_schema' => $inputSchema,
            'input_schema_omitted' => $inputSchemaOmitted,
            'output_schema' => $outputSchema,
            'output_schema_omitted' => $outputSchemaOmitted,
            'annotations' => $safeAnnotations,
            'execution_exposed' => $this->isReadEligible($ability),
        );
    }

    private function catalogPositiveInteger($value, int $max, string $field): int
    {
        if (! is_int($value) && (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value))) {
            throw new \RuntimeException($field . ' must be a positive integer.');
        }
        $validated = filter_var((string) $value, FILTER_VALIDATE_INT);
        if (false === $validated || $validated < 1) {
            throw new \RuntimeException($field . ' must be a positive integer.');
        }
        return min($max, (int) $validated);
    }

    private function boundedCatalogText(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($value, 0, $maxBytes, 'UTF-8');
        }
        $cut = $maxBytes;
        while ($cut > 0 && isset($value[$cut]) && (ord($value[$cut]) & 0xC0) === 0x80) {
            --$cut;
        }
        return substr($value, 0, $cut);
    }

    private function boundedCatalogValue($value): array
    {
        $budget = array('nodes' => 0, 'bytes' => 0);
        try {
            $safe = $this->boundedCatalogWalk($value, 0, new \SplObjectStorage(), $budget);
        } catch (\RuntimeException $error) {
            return array(null, true);
        }

        $encoded = function_exists('wp_json_encode') ? wp_json_encode($safe) : json_encode($safe);
        if (! is_string($encoded) || strlen($encoded) > 8192) {
            return array(null, true);
        }
        return array($safe, false);
    }

    private function boundedCatalogWalk($value, int $depth, \SplObjectStorage $seen, array &$budget)
    {
        if (++$budget['nodes'] > 2000 || $depth > 12) {
            throw new \RuntimeException('catalog value limit');
        }

        $object = null;
        if (is_object($value)) {
            if ($seen->contains($value)) {
                return '[circular object omitted]';
            }
            $seen->attach($value);
            $object = $value;
            $value = get_object_vars($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $budget['bytes'] += strlen($key);
                }
                if ($budget['bytes'] > 8192) {
                    throw new \RuntimeException('catalog value limit');
                }
                $value[$key] = $this->boundedCatalogWalk($item, $depth + 1, $seen, $budget);
            }
            $value = Policy::redact($value);
            if (null !== $object) {
                $seen->detach($object);
            }
            return $value;
        }

        if (is_string($value)) {
            $budget['bytes'] += strlen($value);
            if ($budget['bytes'] > 8192) {
                throw new \RuntimeException('catalog value limit');
            }
            return $value;
        }

        return is_scalar($value) || null === $value ? $value : null;
    }
}
