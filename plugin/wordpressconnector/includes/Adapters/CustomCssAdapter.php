<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class CustomCssAdapter
{
    private const MAX_CSS_BYTES = 524288;

    public function register(Registry $registry): void
    {
        $registry->register('custom_css.inspect', array($this, 'inspect'), array(
            'privileged' => true,
            'description' => 'Read WordPress Additional CSS for one installed theme stylesheet.',
        ));

        $registry->register('custom_css.update', array($this, 'update'), array(
            'mutation' => true,
            'privileged' => true,
            'description' => 'Replace WordPress Additional CSS with stale-state protection, readback and rollback.',
        ));
    }

    public function inspect(array $payload, array $context): array
    {
        $snapshot = $this->snapshot($this->stylesheet($payload));

        return array(
            'custom_css' => $snapshot,
            'fingerprint' => Fingerprint::make($snapshot),
        );
    }

    public function update(array $payload, array $context): array
    {
        if (! array_key_exists('css', $payload) || ! is_string($payload['css'])) {
            throw new RuntimeException('custom_css.update requires payload.css as a string.');
        }

        $css = $payload['css'];
        if (strlen($css) > self::MAX_CSS_BYTES) {
            throw new RuntimeException('Additional CSS exceeds the 512 KiB connector limit.');
        }

        $stylesheet = $this->stylesheet($payload);
        $before = $this->snapshot($stylesheet);
        $result = array(
            'before' => $before,
            'after' => array(
                'stylesheet' => $stylesheet,
                'css' => $css,
                'post_id' => $before['post_id'],
            ),
            '_current_fingerprint' => Fingerprint::make($before),
        );

        if (! empty($context['dry_run'])) {
            return $result;
        }

        $updated = wp_update_custom_css_post($css, array('stylesheet' => $stylesheet));
        if (is_wp_error($updated)) {
            throw new RuntimeException($updated->get_error_message());
        }
        if (! $updated instanceof \WP_Post) {
            throw new RuntimeException('WordPress did not return an Additional CSS post after update.');
        }

        clean_post_cache((int) $updated->ID);
        $readback = $this->snapshot($stylesheet);

        if ($readback['css'] !== $css) {
            wp_update_custom_css_post((string) $before['css'], array('stylesheet' => $stylesheet));
            throw new RuntimeException('Additional CSS readback verification failed; the previous CSS was restored.');
        }

        $result['after'] = $readback;
        $result['_rollback'] = array(
            'action' => 'custom_css.update',
            'payload' => array(
                'stylesheet' => $stylesheet,
                'css' => (string) $before['css'],
            ),
        );

        return $result;
    }

    private function snapshot(string $stylesheet): array
    {
        $post = wp_get_custom_css_post($stylesheet);

        return array(
            'stylesheet' => $stylesheet,
            'css' => (string) wp_get_custom_css($stylesheet),
            'post_id' => $post instanceof \WP_Post ? (int) $post->ID : null,
        );
    }

    private function stylesheet(array $payload): string
    {
        $stylesheet = isset($payload['stylesheet'])
            ? sanitize_text_field((string) $payload['stylesheet'])
            : (string) get_stylesheet();

        if ('' === $stylesheet) {
            throw new RuntimeException('Theme stylesheet is required.');
        }

        $themes = wp_get_themes();
        if (! isset($themes[$stylesheet])) {
            throw new RuntimeException('Theme stylesheet is not installed: ' . $stylesheet);
        }

        return $stylesheet;
    }
}
