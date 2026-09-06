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
        'breadcrumb_title' => '_yoast_wpseo_bctitle',
        'opengraph_title' => '_yoast_wpseo_opengraph-title',
        'opengraph_description' => '_yoast_wpseo_opengraph-description',
        'twitter_title' => '_yoast_wpseo_twitter-title',
        'twitter_description' => '_yoast_wpseo_twitter-description',
    );

    public function register(Registry $registry): void
    {
        $registry->register('yoast.inspect', array($this, 'inspect'), array(
            'privileged' => true,
            'description' => 'Read supported Yoast SEO fields for one WordPress content object.',
        ));
        $registry->register('yoast.update', array($this, 'update'), array(
            'mutation' => true,
            'privileged' => true,
            'description' => 'Update supported Yoast SEO fields with dry-run, fingerprint and rollback support.',
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
            $after[$field] = null === $value ? null : (string) $value;
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
            if (null === $value) {
                delete_post_meta((int) $post->ID, $metaKey);
            } else {
                update_post_meta((int) $post->ID, $metaKey, (string) $value);
            }
        }

        clean_post_cache((int) $post->ID);
        $readback = $this->snapshot((int) $post->ID);
        foreach ($updates as $field => $value) {
            $expected = null === $value ? null : (string) $value;
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
}
