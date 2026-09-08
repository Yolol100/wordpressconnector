<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Runtime;

use RuntimeException;

final class Registry
{
    private array $actions = array();

    public function register(string $name, callable $handler, array $metadata = array()): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*\z/', $name)) {
            throw new RuntimeException('Invalid action name: ' . $name);
        }

        if (isset($this->actions[$name])) {
            throw new RuntimeException('Duplicate action registration: ' . $name);
        }

        $capability = null;
        if (array_key_exists('capability', $metadata)) {
            if (! is_string($metadata['capability']) || ! preg_match('/^[a-z][a-z0-9_]*\z/', $metadata['capability'])) {
                throw new RuntimeException('Invalid WordPress capability for action: ' . $name);
            }
            $capability = $metadata['capability'];
        }

        $this->actions[$name] = array(
            'name' => $name,
            'handler' => $handler,
            'mutation' => ! empty($metadata['mutation']),
            'privileged' => ! empty($metadata['privileged']),
            'sensitive' => ! empty($metadata['sensitive']),
            'system_update' => ! empty($metadata['system_update']),
            'public_repository_safe' => ! empty($metadata['public_repository_safe']),
            'capability' => $capability,
            'description' => isset($metadata['description']) ? (string) $metadata['description'] : '',
        );
    }

    public function descriptor(string $name): array
    {
        if (! isset($this->actions[$name])) {
            throw new RuntimeException('Unknown connector action: ' . $name);
        }

        return $this->actions[$name];
    }

    public function execute(string $name, array $payload, array $context = array()): array
    {
        $descriptor = $this->descriptor($name);
        $result = call_user_func($descriptor['handler'], $payload, $context);
        if (! is_array($result)) {
            throw new RuntimeException('Connector action did not return an array: ' . $name);
        }

        return $result;
    }

    public function catalog(): array
    {
        $catalog = array();
        foreach ($this->actions as $name => $descriptor) {
            $catalog[$name] = array(
                'mutation' => $descriptor['mutation'],
                'privileged' => $descriptor['privileged'],
                'sensitive' => $descriptor['sensitive'],
                'system_update' => $descriptor['system_update'],
                'public_repository_safe' => $descriptor['public_repository_safe'],
                'capability' => $descriptor['capability'],
                'description' => $descriptor['description'],
            );
        }
        ksort($catalog);
        return $catalog;
    }
}
