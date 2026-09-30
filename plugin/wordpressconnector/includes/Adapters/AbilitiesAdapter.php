<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use Webactueel\WordPressConnector\Runtime\Registry;

final class AbilitiesAdapter
{
    public function register(Registry $registry): void
    {
        $registry->register('wordpress.abilities', array($this, 'catalog'), array(
            'privileged' => true,
            'description' => 'Discover exposed WordPress Abilities API entries and their schemas without executing them.',
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

    private function isValidName(string $name): bool
    {
        // Discover public abilities from any installed plugin, but never execute them here.
        return 1 === preg_match('/^[a-z0-9][a-z0-9._-]{0,99}\/[a-z0-9][a-z0-9._-]*(?:\/[a-z0-9][a-z0-9._-]*){0,10}$/iD', $name);
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
            'execution_exposed' => false,
        );
    }
}
