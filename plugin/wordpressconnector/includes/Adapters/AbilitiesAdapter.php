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
            'description' => 'Read through an explicitly read-only, client-exposed native Elementor Ability after provider ownership verification; native validation and permission callbacks still run.',
        ));
        $registry->register('wordpress.ability.execute', array($this, 'executeAbility'), array(
            'mutation' => true,
            'privileged' => true,
            'capability' => 'manage_options',
            'description' => 'Execute one explicitly mutating, client-exposed Elementor Ability (elementor/* in the Elementor category) through its native schema validation, permission callback and provider guards. Dry-run never invokes the Ability.',
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
        $namespace = $this->catalogNamespace($payload['namespace'] ?? null);

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
            if (null !== $namespace && 0 !== strpos($name, $namespace . '/')) {
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
        if (null !== $namespace) {
            $response['namespace'] = $namespace;
        }
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($response) : json_encode($response);
        if (! is_string($encoded) || strlen($encoded) > 262144) {
            throw new \RuntimeException('WordPress Ability catalog page exceeds the output limit.');
        }

        return $response;
    }

    public function readAbility(array $payload, array $context): array
    {
        list($name, $ability) = $this->resolveAbility($payload, false);
        $input = array_key_exists('input', $payload) ? $payload['input'] : null;
        return $this->executeAndSanitize($name, $ability, $input, $this->safeAnnotations($ability), false);
    }

    public function executeAbility(array $payload, array $context): array
    {
        list($name, $ability) = $this->resolveAbility($payload, true);
        $input = array_key_exists('input', $payload) ? $payload['input'] : null;
        $annotations = $this->safeAnnotations($ability);

        if (! empty($context['dry_run'])) {
            list($inputSchema, $inputSchemaOmitted) = $this->boundedCatalogValue(
                method_exists($ability, 'get_input_schema') ? $ability->get_input_schema() : null
            );
            return array(
                'name' => $name,
                'would_execute' => true,
                'annotations' => $annotations,
                'input_schema' => $inputSchema,
                'input_schema_omitted' => $inputSchemaOmitted,
                'native_validation_on_confirm' => true,
                'rollback_supported' => false,
                'note' => 'Dry-run does not invoke the Ability. Native input validation, permission callbacks and provider-specific conflict guards run on confirmed execution.',
            );
        }

        $result = $this->executeAndSanitize($name, $ability, $input, $annotations, true);
        $result['rollback_supported'] = false;
        return $result;
    }

    private function resolveAbility(array $payload, bool $mutation): array
    {
        if (! function_exists('wp_get_ability')) {
            throw new \RuntimeException('WordPress Abilities API is not available on this site.');
        }
        $name = isset($payload['name']) && is_string($payload['name']) ? $payload['name'] : '';
        if (! $this->isValidName($name)) {
            throw new \RuntimeException('A valid namespace/ability name is required.');
        }
        $ability = wp_get_ability($name);
        $eligible = $mutation
            ? $this->isElementorMutationEligible($name, $ability)
            : $this->isReadEligible($ability);
        if (! is_object($ability) || ! $eligible) {
            throw new \RuntimeException('The requested client-exposed WordPress Ability was not found.');
        }
        return array($name, $ability);
    }

    private function executeAndSanitize(string $name, object $ability, $input, array $annotations, bool $mutation): array
    {
        $executionStarted = false;
        $executionTracker = null;

        if ($mutation && function_exists('add_action') && function_exists('remove_action')) {
            $executionTracker = static function ($abilityName, $normalizedInput, $executingAbility) use (&$executionStarted, $name, $ability): void {
                if ($abilityName === $name && $executingAbility === $ability) {
                    $executionStarted = true;
                }
            };
            add_action('wp_before_execute_ability', $executionTracker, PHP_INT_MAX, 3);
        }

        try {
            $result = $ability->execute($input);
        } catch (\Throwable $error) {
            if ($mutation && $executionStarted) {
                return $this->terminalMutationFailure($name, $annotations, null);
            }
            throw new \RuntimeException('WordPress Ability failed.');
        } finally {
            if (null !== $executionTracker) {
                remove_action('wp_before_execute_ability', $executionTracker, PHP_INT_MAX);
            }
        }

        if (function_exists('is_wp_error') && is_wp_error($result)) {
            if ($mutation && $executionStarted) {
                $errorCode = method_exists($result, 'get_error_code') ? (string) $result->get_error_code() : null;
                return $this->terminalMutationFailure($name, $annotations, $errorCode);
            }
            throw new \RuntimeException('WordPress Ability failed.');
        }

        try {
            $budget = array('nodes' => 0, 'bytes' => 0);
            $safe = $this->redactAbilityResult($result, 0, new \SplObjectStorage(), $budget);
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($safe) : json_encode($safe);
            if (! is_string($encoded) || strlen($encoded) > 262144) {
                throw new \RuntimeException('WordPress Ability result exceeds the output limit.');
            }
        } catch (\Throwable $error) {
            if (! $mutation) {
                if ($error instanceof \RuntimeException) {
                    throw $error;
                }
                throw new \RuntimeException('WordPress Ability result exceeds the connector output limits.');
            }

            return array(
                'name' => $name,
                'annotations' => $annotations,
                'execution_completed' => true,
                'result' => null,
                'result_omitted' => true,
                'result_omission_reason' => 'Ability completed but its result exceeded connector output limits.',
            );
        }

        $response = array(
            'name' => $name,
            'annotations' => $annotations,
            'result' => $safe,
        );
        if ($mutation) {
            $response['execution_completed'] = true;
            $response['result_omitted'] = false;
        }
        return $response;
    }

    private function terminalMutationFailure(string $name, array $annotations, ?string $providerErrorCode): array
    {
        $safeCode = null;
        if (is_string($providerErrorCode) && 1 === preg_match('/^[A-Za-z0-9._-]{1,120}$/D', $providerErrorCode)) {
            $safeCode = $providerErrorCode;
        }

        return array(
            'name' => $name,
            'annotations' => $annotations,
            'execution_may_have_started' => true,
            'provider_error_code' => $safeCode,
            'result' => null,
            'result_omitted' => true,
            '_terminal_error' => 'Elementor Ability execution ended with a terminal provider failure; this request_id will not execute again.',
        );
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

    private function catalogNamespace($value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (! is_string($value) || 1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $value)) {
            throw new \RuntimeException('namespace must be a valid Ability namespace.');
        }
        return $value;
    }

    private function isReadEligible($ability): bool
    {
        if (! is_object($ability) || ! $this->isExecutionExposed($ability) || ! method_exists($ability, 'execute')) {
            return false;
        }
        $annotations = $this->safeAnnotations($ability);
        return true === ($annotations['readonly'] ?? null) && false === ($annotations['destructive'] ?? null);
    }

    private function isMutationEligible($ability): bool
    {
        if (! is_object($ability) || ! $this->isExecutionExposed($ability) || ! method_exists($ability, 'execute')) {
            return false;
        }
        $annotations = $this->safeAnnotations($ability);
        return false === ($annotations['readonly'] ?? null) && is_bool($annotations['destructive'] ?? null);
    }

    private function isElementorMutationEligible(string $name, $ability): bool
    {
        if (! is_object($ability) || ! $this->isElementorAbilityName($name)) {
            return false;
        }

        if (! $this->isExplicitMcpPublic($ability) || ! $this->isMutationEligible($ability)) {
            return false;
        }

        return $this->isNativeElementorAbilityProvider($name, $ability);
    }

    private function isElementorAbilityName(string $name): bool
    {
        return 0 === strpos($name, 'elementor/') || 0 === strpos($name, 'elementor-pro/');
    }

    private function isExplicitMcpPublic(object $ability): bool
    {
        $meta = method_exists($ability, 'get_meta') ? $ability->get_meta() : array();
        return is_array($meta)
            && isset($meta['mcp'])
            && is_array($meta['mcp'])
            && true === ($meta['mcp']['public'] ?? false);
    }

    private function isNativeElementorAbilityProvider(string $name, object $ability): bool
    {
        $callback = $this->abilityExecuteCallback($ability);
        if (! is_array($callback)
            || 2 !== count($callback)
            || ! is_object($callback[0])
            || 'execute_guarded' !== (string) $callback[1]) {
            return false;
        }

        $provider = $callback[0];
        if (! method_exists($provider, 'get_id') || $name !== (string) $provider->get_id()) {
            return false;
        }

        try {
            $providerFile = (new \ReflectionObject($provider))->getFileName();
        } catch (\Throwable $error) {
            return false;
        }
        if (! is_string($providerFile) || '' === $providerFile) {
            return false;
        }

        $providerPath = realpath($providerFile);
        if (false === $providerPath) {
            return false;
        }

        foreach ($this->trustedElementorPluginRoots() as $root) {
            if ($this->pathWithinRoot($providerPath, $root)) {
                return true;
            }
        }

        return false;
    }

    private function abilityExecuteCallback(object $ability)
    {
        try {
            $reflection = new \ReflectionObject($ability);
            if (! $reflection->hasProperty('execute_callback')) {
                return null;
            }
            $property = $reflection->getProperty('execute_callback');
            if (method_exists($property, 'setAccessible')) {
                $property->setAccessible(true);
            }
            return $property->getValue($ability);
        } catch (\Throwable $error) {
            return null;
        }
    }

    private function trustedElementorPluginRoots(): array
    {
        if (! defined('WP_PLUGIN_DIR') || ! function_exists('get_option')) {
            return array();
        }

        $active = get_option('active_plugins', array());
        $active = is_array($active) ? array_values($active) : array();

        $networkActive = array();
        if (function_exists('get_site_option')) {
            $value = get_site_option('active_sitewide_plugins', array());
            $networkActive = is_array($value) ? array_keys($value) : array();
        }

        $roots = array();
        foreach (array('elementor/elementor.php', 'elementor-pro/elementor-pro.php') as $pluginFile) {
            if (! in_array($pluginFile, $active, true) && ! in_array($pluginFile, $networkActive, true)) {
                continue;
            }

            $mainFile = realpath(rtrim((string) WP_PLUGIN_DIR, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pluginFile));
            if (false === $mainFile || ! is_file($mainFile)) {
                continue;
            }

            $root = realpath(dirname($mainFile));
            if (false !== $root && ! in_array($root, $roots, true)) {
                $roots[] = $root;
            }
        }

        return $roots;
    }

    private function pathWithinRoot(string $file, string $root): bool
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        return $file === $root || 0 === strpos($file, $root . DIRECTORY_SEPARATOR);
    }

    private function isExecutionExposed(object $ability): bool
    {
        $meta = method_exists($ability, 'get_meta') ? $ability->get_meta() : array();
        if (! is_array($meta)) {
            return false;
        }

        if (isset($meta['mcp']) && is_array($meta['mcp']) && array_key_exists('public', $meta['mcp'])) {
            return true === $meta['mcp']['public'];
        }

        return true === ($meta['public'] ?? false) || true === ($meta['show_in_rest'] ?? false);
    }

    private function isExposed(object $ability): bool
    {
        $meta = method_exists($ability, 'get_meta') ? $ability->get_meta() : array();
        if (! is_array($meta)) {
            return false;
        }

        if (isset($meta['mcp']) && is_array($meta['mcp']) && array_key_exists('public', $meta['mcp'])) {
            return true === $meta['mcp']['public'];
        }

        return true === ($meta['public'] ?? false) || true === ($meta['show_in_rest'] ?? false);
    }

    private function safeAnnotations(object $ability): array
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
        return $safeAnnotations;
    }

    private function descriptor(string $name, object $ability): array
    {
        $safeAnnotations = $this->safeAnnotations($ability);
        $readEligible = $this->isReadEligible($ability);
        $mutationEligible = $this->isElementorMutationEligible($name, $ability);

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
            'execution_exposed' => $readEligible,
            'mutation_execution_exposed' => $mutationEligible,
            'connector_action' => $readEligible ? 'wordpress.ability.read' : ($mutationEligible ? 'wordpress.ability.execute' : null),
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
