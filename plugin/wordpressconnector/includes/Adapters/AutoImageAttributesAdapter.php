<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class AutoImageAttributesAdapter
{
    private const BOOLEAN_FIELDS = array(
        'image_title',
        'image_caption',
        'image_description',
        'image_alttext',
        'image_title_to_html',
        'hyphens',
        'under_score',
        'clean_filename',
        'bu_image_title',
        'bu_image_caption',
        'bu_image_description',
        'bu_image_alttext',
        'bu_title_location_ml',
        'bu_title_location_post',
        'bu_alt_text_location_ml',
        'bu_alt_text_location_post',
        'bu_caption_location_ml',
        'bu_description_location_ml',
    );

    public function register(Registry $registry): void
    {
        $registry->register('auto_image_attributes.inspect', array($this, 'inspect'), array(
            'privileged' => true,
            'description' => 'Read safe Auto Image Attributes upload and bulk-update settings.',
        ));
        $registry->register('auto_image_attributes.update', array($this, 'update'), array(
            'mutation' => true,
            'privileged' => true,
            'description' => 'Update allowlisted Auto Image Attributes settings with dry-run, readback and rollback.',
        ));
    }

    public function inspect(array $payload = array(), array $context = array()): array
    {
        $fields = $this->snapshot();
        return array(
            'active' => defined('IAFF_VERSION_NUM') || function_exists('iaff_get_settings'),
            'version' => defined('IAFF_VERSION_NUM') ? (string) IAFF_VERSION_NUM : null,
            'fields' => $fields,
            'fingerprint' => Fingerprint::make($fields),
        );
    }

    public function update(array $payload, array $context): array
    {
        $updates = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        if (! $updates) {
            throw new RuntimeException('auto_image_attributes.update requires payload.fields.');
        }

        $before = $this->snapshot();
        $clean = array();
        foreach ($updates as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::BOOLEAN_FIELDS, true)) {
                throw new RuntimeException('Unsupported Auto Image Attributes setting: ' . (string) $key);
            }
            $clean[$key] = $this->booleanString($value);
        }

        $after = $before;
        foreach ($clean as $key => $value) {
            $after[$key] = $value;
        }

        $result = array(
            'before' => $before,
            'after' => $after,
            '_current_fingerprint' => Fingerprint::make($before),
        );

        if (! empty($context['dry_run'])) {
            return $result;
        }

        $raw = get_option('iaff_settings', array());
        if (! is_array($raw)) {
            throw new RuntimeException('Auto Image Attributes settings are unavailable or malformed.');
        }
        foreach ($clean as $key => $value) {
            $raw[$key] = $value;
        }

        update_option('iaff_settings', $raw, false);
        $readback = $this->snapshot();
        foreach ($clean as $key => $value) {
            if (! isset($readback[$key]) || (string) $readback[$key] !== $value) {
                throw new RuntimeException('Auto Image Attributes readback verification failed for: ' . $key);
            }
        }

        $rollback = array();
        foreach ($clean as $key => $value) {
            if (array_key_exists($key, $before)) {
                $rollback[$key] = $before[$key];
            }
        }

        $result['after'] = $readback;
        $result['_rollback'] = array(
            'action' => 'auto_image_attributes.update',
            'payload' => array('fields' => $rollback),
        );
        return $result;
    }

    private function snapshot(): array
    {
        $raw = function_exists('iaff_get_settings') ? iaff_get_settings() : get_option('iaff_settings', array());
        if (! is_array($raw)) {
            throw new RuntimeException('Auto Image Attributes settings are unavailable or malformed.');
        }

        $result = array();
        foreach (self::BOOLEAN_FIELDS as $key) {
            if (array_key_exists($key, $raw)) {
                $result[$key] = $this->booleanString($raw[$key]);
            }
        }
        return $result;
    }

    private function booleanString($value): string
    {
        if (true === $value || 1 === $value || '1' === $value || 'true' === strtolower((string) $value) || 'yes' === strtolower((string) $value) || 'on' === strtolower((string) $value)) {
            return '1';
        }
        if (false === $value || 0 === $value || '0' === $value || 'false' === strtolower((string) $value) || 'no' === strtolower((string) $value) || 'off' === strtolower((string) $value)) {
            return '0';
        }
        throw new RuntimeException('Auto Image Attributes boolean settings require true/false or 1/0.');
    }
}
