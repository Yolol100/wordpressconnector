<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class YoastAdapter
{
    private const FIELDS = array(
        'title' => '_yoast_wpseo_title',
        'description' => '_yoast_wpseo_metadesc',
        'focus_keyphrase' => '_yoast_wpseo_focuskw',
        'canonical' => '_yoast_wpseo_canonical',
        'robots_noindex' => '_yoast_wpseo_meta-robots-noindex',
        'robots_nofollow' => '_yoast_wpseo_meta-robots-nofollow',
        'robots_advanced' => '_yoast_wpseo_meta-robots-adv',
        'breadcrumb_title' => '_yoast_wpseo_bctitle',
        'cornerstone' => '_yoast_wpseo_is_cornerstone',
        'schema_page_type' => '_yoast_wpseo_schema_page_type',
        'schema_article_type' => '_yoast_wpseo_schema_article_type',
        'redirect' => '_yoast_wpseo_redirect',
        'primary_category_term_id' => '_yoast_wpseo_primary_category',
        'opengraph_title' => '_yoast_wpseo_opengraph-title',
        'opengraph_description' => '_yoast_wpseo_opengraph-description',
        'opengraph_image' => '_yoast_wpseo_opengraph-image',
        'opengraph_image_id' => '_yoast_wpseo_opengraph-image-id',
        'twitter_title' => '_yoast_wpseo_twitter-title',
        'twitter_description' => '_yoast_wpseo_twitter-description',
        'twitter_image' => '_yoast_wpseo_twitter-image',
        'twitter_image_id' => '_yoast_wpseo_twitter-image-id',
    );

    public function register(Registry $registry): void
    {
        $registry->register('yoast.inspect', array($this, 'inspect'), array(
            'privileged' => true,
            'description' => 'Read supported Yoast SEO and Yoast SEO Premium fields for one WordPress content object.',
        ));
        $registry->register('yoast.update', array($this, 'update'), array(
            'mutation' => true,
            'privileged' => true,
            'description' => 'Update supported Yoast SEO and Premium post fields with dry-run, fingerprint, readback and rollback support.',
        ));
    }

    public function inspect(array $payload, array $context): array
    {
        $post = $this->post($payload);
        $fields = $this->snapshot((int) $post->ID);

        return array(
            'post_id' => (int) $post->ID,
            'yoast_active' => defined('WPSEO_VERSION') || defined('YOAST_SEO_VERSION'),
            'yoast_version' => defined('WPSEO_VERSION') ? WPSEO_VERSION : (defined('YOAST_SEO_VERSION') ? YOAST_SEO_VERSION : null),
            'yoast_premium_active' => defined('WPSEO_PREMIUM_VERSION'),
            'yoast_premium_version' => defined('WPSEO_PREMIUM_VERSION') ? WPSEO_PREMIUM_VERSION : null,
            'fields' => $fields,
            'fingerprint' => Fingerprint::make($fields),
        );
    }

    public function update(array $payload, array $context): array
    {
        $post = $this->post($payload);
        $updates = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        if (! $updates) {
            throw new RuntimeException('yoast.update requires payload.fields.');
        }

        foreach ($updates as $field => $value) {
            if (! isset(self::FIELDS[$field])) {
                throw new RuntimeException('Unsupported Yoast field: ' . (string) $field);
            }
            if (null !== $value && ! is_scalar($value)) {
                throw new RuntimeException('Yoast field values must be scalar or null.');
            }
        }

        $before = $this->snapshot((int) $post->ID);
        $after = $before;
        foreach ($updates as $field => $value) {
            $after[$field] = null === $value ? null : $this->sanitizeField((string) $field, $value);
        }

        $result = array(
            'post_id' => (int) $post->ID,
            'before' => $before,
            'after' => $after,
            '_current_fingerprint' => Fingerprint::make($before),
        );

        if (! empty($context['dry_run'])) {
            return $result;
        }

        foreach ($updates as $field => $value) {
            $metaKey = self::FIELDS[$field];
            if (null === $value || '' === (string) $value) {
                delete_post_meta((int) $post->ID, $metaKey);
            } else {
                update_post_meta((int) $post->ID, $metaKey, $this->sanitizeField((string) $field, $value));
            }
        }

        clean_post_cache((int) $post->ID);
        $readback = $this->snapshot((int) $post->ID);
        foreach ($updates as $field => $value) {
            $expected = null === $value || '' === (string) $value ? null : $this->sanitizeField((string) $field, $value);
            if ($readback[$field] !== $expected) {
                throw new RuntimeException('Yoast readback verification failed for field: ' . (string) $field);
            }
        }

        $result['after'] = $readback;
        $result['_rollback'] = array(
            'action' => 'yoast.update',
            'payload' => array(
                'id' => (int) $post->ID,
                'fields' => $before,
            ),
        );

        return $result;
    }

    private function post(array $payload): \WP_Post
    {
        $post = get_post(isset($payload['id']) ? (int) $payload['id'] : 0);
        if (! $post instanceof \WP_Post) {
            throw new RuntimeException('Post not found.');
        }
        Policy::assertPostReadable($post);
        return $post;
    }

    private function snapshot(int $postId): array
    {
        $result = array();
        foreach (self::FIELDS as $field => $metaKey) {
            $value = get_post_meta($postId, $metaKey, true);
            $result[$field] = '' === $value ? null : (string) $value;
        }
        return $result;
    }

    private function sanitizeField(string $field, $value): string
    {
        $value = (string) $value;

        if (in_array($field, array('canonical', 'redirect', 'opengraph_image', 'twitter_image'), true)) {
            $clean = esc_url_raw($value);
            if ('' !== $value && '' === $clean) {
                throw new RuntimeException('Yoast field requires a valid URL: ' . $field);
            }
            return $clean;
        }

        if (in_array($field, array('opengraph_image_id', 'twitter_image_id', 'primary_category_term_id'), true)) {
            $id = (int) $value;
            if ($id < 1) {
                throw new RuntimeException('Yoast field requires a positive integer ID: ' . $field);
            }
            return (string) $id;
        }

        if ('robots_noindex' === $field && ! in_array($value, array('0', '1', '2'), true)) {
            throw new RuntimeException('robots_noindex must be 0 (default), 1 (noindex), or 2 (index).');
        }
        if ('robots_nofollow' === $field && ! in_array($value, array('0', '1'), true)) {
            throw new RuntimeException('robots_nofollow must be 0 (follow) or 1 (nofollow).');
        }
        if ('robots_advanced' === $field) {
            $allowed = array('noimageindex', 'noarchive', 'nosnippet');
            $parts = array_filter(array_map('trim', explode(',', $value)));
            foreach ($parts as $part) {
                if (! in_array($part, $allowed, true)) {
                    throw new RuntimeException('Unsupported advanced robots directive: ' . $part);
                }
            }
            return implode(',', array_values(array_unique($parts)));
        }
        if ('cornerstone' === $field) {
            if (! in_array(strtolower($value), array('true', 'false'), true)) {
                throw new RuntimeException('cornerstone must be true or false.');
            }
            return strtolower($value);
        }

        return sanitize_text_field($value);
    }
}
