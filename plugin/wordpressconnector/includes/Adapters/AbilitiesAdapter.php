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

        $result = array();
        foreach ((array) wp_get_abilities() as $name => $ability) {
            if (! is_object($ability)) {
                continue;
            }

            if (! is_string($name) || '' === $name) {
                $name = method_exists($ability, 'get_name') ? (string) $ability->get_name() : '';
            }

            if (! $this->isValidName($name) || ! $this->isExposed($ability)) {
                continue;
            }

            $result[$name] = $this->descriptor($name, $ability);
        }

        ksort($result, SORT_STRING);

        return array(
            'available' => true,
            'abilities' => $result,
        );
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
        $result = $ability->execute($input);
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
        if (is_object($value)) {
            if ($seen->contains($value)) return '[circular object omitted]';
            $seen->attach($value);
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
            return Policy::redact($value);
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
        return true === ($annotations['readonly'] ?? false) && true !== ($annotations['destructive'] ?? false);
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

        return array(
            'name' => $name,
            'label' => method_exists($ability, 'get_label') ? (string) $ability->get_label() : $name,
            'description' => method_exists($ability, 'get_description') ? (string) $ability->get_description() : '',
            'category' => method_exists($ability, 'get_category') ? (string) $ability->get_category() : '',
            'input_schema' => method_exists($ability, 'get_input_schema') ? $ability->get_input_schema() : null,
            'output_schema' => method_exists($ability, 'get_output_schema') ? $ability->get_output_schema() : null,
            'annotations' => $annotations,
            'execution_exposed' => $this->isReadEligible($ability),
        );
    }
}
