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
        $registry->register('yoast.site_representation.inspect', array($this, 'inspectSiteRepresentation'), array(
            'privileged' => true,
            'capability' => 'manage_options',
            'description' => 'Read allowlisted Yoast Organization name, logo and description fields.',
        ));
        $registry->register('yoast.site_representation.update', array($this, 'updateSiteRepresentation'), array(
            'mutation' => true,
            'privileged' => true,
            'capability' => 'manage_options',
            'description' => 'Patch 1-3 allowlisted Yoast Organization fields using validated media ID with dry-run, readback and rollback.',
        ));
        $registry->register('yoast.site_representation.restore', array($this, 'restoreSiteRepresentation'), array(
            'mutation' => true,
            'privileged' => true,
            'capability' => 'manage_options',
            'description' => 'Internal rollback only for a prior Yoast Organization change.',
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

    /**
     * Patch only the organization fields Yoast owns, never the full wpseo_titles option.
     * The WordPress media library is the only accepted source for a logo.
     */
    public function inspectSiteRepresentation(array $payload = array(), array $context = array()): array
    {
        $fields = $this->siteRepresentationSnapshot();
        return array('fields' => $fields, 'fingerprint' => Fingerprint::make($fields));
    }

    public function updateSiteRepresentation(array $payload, array $context): array
    {
        $updates = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        if (! $updates || count($updates) > 3) {
            throw new RuntimeException('Site representation requires 1-3 allowlisted fields.');
        }

        $before = $this->siteRepresentationSnapshot();
        if ('company' !== $before['company_or_person']) {
            throw new RuntimeException('Site representation is not set to Organization in Yoast.');
        }

        $options = get_option('wpseo_titles', null);
        $afterOptions = $options;
        foreach ($updates as $key => $value) {
            if ('logo_attachment_id' === $key) {
                if (! is_int($value) || $value < 1) {
                    throw new RuntimeException('Organization logo requires a positive WordPress media attachment ID.');
                }
                $attachment = get_post($value);
                if (! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image($value)) {
                    throw new RuntimeException('Organization logo must reference a WordPress image attachment.');
                }
                $meta = wp_get_attachment_metadata($value);
                if (! is_array($meta) || (int) ($meta['width'] ?? 0) < 112 || (int) ($meta['height'] ?? 0) < 112) {
                    throw new RuntimeException('Organization logo image must be at least 112x112 pixels.');
                }
                $url = wp_get_attachment_url($value);
                if (! is_string($url) || 'https' !== strtolower((string) parse_url($url, PHP_URL_SCHEME))) {
                    throw new RuntimeException('Organization logo must have a valid HTTPS media URL.');
                }
                $afterOptions['company_logo_id'] = $value;
                $afterOptions['company_logo'] = esc_url_raw($url);
                $afterOptions['company_logo_meta'] = false;
            } elseif ('company_name' === $key || 'org_description' === $key) {
                if (! is_string($value) || '' === trim($value) || strlen($value) > 500) {
                    throw new RuntimeException('Organization text must be a nonempty string up to 500 bytes.');
                }
                $afterOptions['company_name' === $key ? 'company_name' : 'org-description'] = sanitize_text_field($value);
            } else {
                throw new RuntimeException('Unsupported site representation field: ' . (string) $key);
            }
        }

        $after = $this->siteRepresentationFields($afterOptions);
        $result = array(
            'before' => $before,
            'after' => $after,
            '_current_fingerprint' => Fingerprint::make($before),
        );
        if (! empty($context['dry_run'])) {
            return $result;
        }
        update_option('wpseo_titles', $afterOptions);
        $readback = $this->siteRepresentationSnapshot();
        if (Fingerprint::make($readback) !== Fingerprint::make($after)) {
            throw new RuntimeException('Yoast organization settings readback failed.');
        }
        $result['after'] = $readback;
        $result['_rollback'] = array(
            'action' => 'yoast.site_representation.restore',
            'payload' => array(
                'fields' => $before,
                'expected_after_fingerprint' => Fingerprint::make($readback),
            ),
        );
        return $result;
    }

    /**
     * Internal compensation only. Direct requests must never overwrite an arbitrary snapshot.
     */
    public function restoreSiteRepresentation(array $payload, array $context): array
    {
        if (empty($context['rollback_mode'])) {
            throw new RuntimeException('Organization settings restore is reserved for connector rollback.');
        }
        $before = $this->siteRepresentationSnapshot();
        $expected = isset($payload['expected_after_fingerprint']) ? (string) $payload['expected_after_fingerprint'] : '';
        if (! preg_match('/^[a-f0-9]{64}$/', $expected) || ! hash_equals($expected, Fingerprint::make($before))) {
            throw new RuntimeException('Organization settings changed after update; rollback is unsafe.');
        }
        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : array();
        if (array_keys($fields) !== array_keys($before)) {
            throw new RuntimeException('Organization rollback snapshot has unexpected fields.');
        }
        $options = get_option('wpseo_titles', null);
        $options['company_or_person'] = $fields['company_or_person'];
        $options['company_name'] = $fields['company_name'];
        $options['company_logo'] = $fields['company_logo'];
        $options['company_logo_id'] = $fields['company_logo_id'];
        $options['company_logo_meta'] = $fields['company_logo_meta'];
        $options['org-description'] = $fields['org_description'];
        update_option('wpseo_titles', $options);
        if (Fingerprint::make($this->siteRepresentationSnapshot()) !== Fingerprint::make($fields)) {
            throw new RuntimeException('Yoast organization rollback readback failed.');
        }
        return array('restored' => true, 'fields' => $fields);
    }

    private function siteRepresentationSnapshot(): array
    {
        if (! defined('WPSEO_VERSION') && ! defined('YOAST_SEO_VERSION')) {
            throw new RuntimeException('Yoast SEO is not active.');
        }
        $options = get_option('wpseo_titles', null);
        if (! is_array($options)) {
            throw new RuntimeException('Yoast SEO site representation options are not available.');
        }
        return $this->siteRepresentationFields($options);
    }

    private function siteRepresentationFields(array $options): array
    {
        return array(
            'company_or_person' => (string) ($options['company_or_person'] ?? ''),
            'company_name' => (string) ($options['company_name'] ?? ''),
            'company_logo' => (string) ($options['company_logo'] ?? ''),
            'company_logo_id' => (int) ($options['company_logo_id'] ?? 0),
            'company_logo_meta' => $options['company_logo_meta'] ?? false,
            'org_description' => (string) ($options['org-description'] ?? ''),
        );
    }

}
