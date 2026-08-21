<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Support\Fingerprint;

final class MediaAdapter
{
    public function register(Registry $registry): void
    {
        $registry->register('media.list', array($this, 'mediaList'), array('description' => 'List media attachments.'));
        $registry->register('media.get', array($this, 'mediaGet'), array('description' => 'Read one media attachment and metadata.'));
        $registry->register('media.import', array($this, 'mediaImport'), array('mutation' => true, 'description' => 'Import a file from the trusted request asset root into the media library.'));
        $registry->register('media.update', array($this, 'mediaUpdate'), array('mutation' => true, 'description' => 'Update attachment title, alt text, caption, description and parent.'));
        $registry->register('media.assign', array($this, 'mediaAssign'), array('mutation' => true, 'description' => 'Assign media as featured image, Woo gallery image, custom logo or site icon.'));
        $registry->register('media.regenerate', array($this, 'mediaRegenerate'), array('mutation' => true, 'description' => 'Regenerate attachment metadata and image subsizes.'));
    }

    public function mediaList(array $payload): array
    {
        $query = new \WP_Query(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => isset($payload['per_page']) ? max(1, min(100, (int) $payload['per_page'])) : 50,
            'paged' => isset($payload['page']) ? max(1, (int) $payload['page']) : 1,
            'post_mime_type' => isset($payload['mime_type']) ? sanitize_mime_type((string) $payload['mime_type']) : '',
            's' => isset($payload['search']) ? sanitize_text_field((string) $payload['search']) : '',
        ));

        $items = array();
        foreach ($query->posts as $post) {
            if (! $post instanceof \WP_Post) continue;
            try {
                Policy::assertPostReadable($post);
                $items[] = $this->snapshot((int) $post->ID);
            } catch (RuntimeException $error) {
                continue;
            }
        }
        return array('media' => $items, 'total' => (int) $query->found_posts, 'pages' => (int) $query->max_num_pages);
    }

    public function mediaGet(array $payload): array
    {
        $id = $this->attachmentId($payload);
        $post = get_post($id);
        Policy::assertPostReadable($post);
        $snapshot = $this->snapshot($id);
        return array('media' => $snapshot, 'fingerprint' => Fingerprint::make($snapshot));
    }

    public function mediaImport(array $payload, array $context): array
    {
        $assetRoot = isset($context['asset_root']) ? (string) $context['asset_root'] : '';
        if ('' === $assetRoot) throw new RuntimeException('media.import requires --asset-root in the CLI context.');
        $source = isset($payload['source_path']) ? (string) $payload['source_path'] : '';
        if ('' === $source) throw new RuntimeException('source_path is required.');
        $candidate = $assetRoot . DIRECTORY_SEPARATOR . ltrim(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $source), DIRECTORY_SEPARATOR);
        $real = Policy::assertLocalAssetPath($candidate, $assetRoot);

        $maxBytes = (int) (getenv('WPCONNECTOR_MAX_MEDIA_BYTES') ?: 20971520);
        $size = filesize($real);
        if (false === $size || $size <= 0 || $size > $maxBytes) throw new RuntimeException('Media file size is invalid or exceeds the configured limit.');

        $checked = wp_check_filetype_and_ext($real, basename($real));
        if (empty($checked['type']) || empty($checked['ext'])) throw new RuntimeException('WordPress rejected the media MIME type or extension.');

        $planned = array(
            'source_path' => $source,
            'filename' => basename($real),
            'mime_type' => $checked['type'],
            'bytes' => (int) $size,
            'parent' => isset($payload['parent']) ? (int) $payload['parent'] : 0,
        );
        if (! empty($context['dry_run'])) {
            return array('would_import' => $planned, '_current_fingerprint' => Fingerprint::make(array('source' => $source, 'size' => $size, 'mtime' => filemtime($real))));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = wp_tempnam(basename($real));
        if (! $tmp || ! copy($real, $tmp)) throw new RuntimeException('Could not prepare temporary media file.');

        $fileArray = array('name' => basename($real), 'tmp_name' => $tmp);
        $attachmentId = media_handle_sideload($fileArray, $planned['parent'], isset($payload['description']) ? (string) $payload['description'] : null);
        if (is_wp_error($attachmentId)) {
            @unlink($tmp);
            throw new RuntimeException($attachmentId->get_error_message());
        }

        $update = array('id' => (int) $attachmentId);
        foreach (array('title', 'alt', 'caption', 'description', 'parent') as $field) {
            if (array_key_exists($field, $payload)) $update[$field] = $payload[$field];
        }
        if (count($update) > 1) $this->applyUpdate($update);

        return array(
            'media' => $this->snapshot((int) $attachmentId),
            '_rollback' => array('action' => 'post.trash', 'payload' => array('id' => (int) $attachmentId)),
        );
    }

    public function mediaUpdate(array $payload, array $context): array
    {
        $id = $this->attachmentId($payload);
        $before = $this->snapshot($id);
        $after = $before;
        foreach (array('title', 'alt', 'caption', 'description', 'parent') as $field) if (array_key_exists($field, $payload)) $after[$field] = $payload[$field];
        $result = array('before' => $before, 'after' => $after, '_current_fingerprint' => Fingerprint::make($before));
        if (! empty($context['dry_run'])) return $result;
        $this->applyUpdate($payload);
        $result['after'] = $this->snapshot($id);
        $result['_rollback'] = array('action' => 'media.update', 'payload' => array(
            'id' => $id,
            'title' => $before['title'],
            'alt' => $before['alt'],
            'caption' => $before['caption'],
            'description' => $before['description'],
            'parent' => $before['parent'],
        ));
        return $result;
    }

    public function mediaAssign(array $payload, array $context): array
    {
        $attachmentId = $this->attachmentId($payload);
        $targetType = isset($payload['target_type']) ? sanitize_key((string) $payload['target_type']) : '';
        $targetId = isset($payload['target_id']) ? (int) $payload['target_id'] : 0;

        switch ($targetType) {
            case 'featured_image':
                $before = (int) get_post_thumbnail_id($targetId);
                $result = array('before' => $before, 'after' => $attachmentId, '_current_fingerprint' => Fingerprint::make($before));
                if (! empty($context['dry_run'])) return $result;
                if (! set_post_thumbnail($targetId, $attachmentId)) throw new RuntimeException('Could not assign featured image.');
                $result['_rollback'] = array('action' => 'media.assign', 'payload' => array('id' => $before, 'target_type' => 'featured_image', 'target_id' => $targetId));
                return $result;

            case 'product_gallery':
                if (! function_exists('wc_get_product')) throw new RuntimeException('WooCommerce is not active.');
                $product = wc_get_product($targetId);
                if (! $product instanceof \WC_Product) throw new RuntimeException('Product not found.');
                $before = array_map('intval', $product->get_gallery_image_ids());
                $mode = isset($payload['mode']) ? (string) $payload['mode'] : 'append';
                $after = 'replace' === $mode ? array($attachmentId) : array_values(array_unique(array_merge($before, array($attachmentId))));
                $result = array('before' => $before, 'after' => $after, '_current_fingerprint' => Fingerprint::make($before));
                if (! empty($context['dry_run'])) return $result;
                $product->set_gallery_image_ids($after);
                $product->save();
                $result['_rollback'] = array('action' => 'woocommerce.product.update', 'payload' => array('id' => $targetId, 'gallery_image_ids' => $before));
                return $result;

            case 'custom_logo':
                $before = (int) get_theme_mod('custom_logo', 0);
                if (! empty($context['dry_run'])) return array('before' => $before, 'after' => $attachmentId, '_current_fingerprint' => Fingerprint::make($before));
                set_theme_mod('custom_logo', $attachmentId);
                return array('after' => $attachmentId, '_rollback' => array('action' => 'theme_mod.update', 'payload' => array('name' => 'custom_logo', 'value' => $before)));

            case 'site_icon':
                $before = (int) get_option('site_icon', 0);
                if (! empty($context['dry_run'])) return array('before' => $before, 'after' => $attachmentId, '_current_fingerprint' => Fingerprint::make($before));
                update_option('site_icon', $attachmentId, false);
                return array('after' => $attachmentId, '_rollback' => array('action' => 'option.update', 'payload' => array('name' => 'site_icon', 'value' => $before)));

            default:
                throw new RuntimeException('target_type must be featured_image, product_gallery, custom_logo or site_icon. Use acf.update, elementor.patch_element or gutenberg.patch for builder-specific assignments.');
        }
    }

    public function mediaRegenerate(array $payload, array $context): array
    {
        $id = $this->attachmentId($payload);
        $before = wp_get_attachment_metadata($id);
        $file = get_attached_file($id);
        if (! $file || ! is_file($file)) throw new RuntimeException('Attachment file is missing.');
        if (! wp_attachment_is_image($id)) throw new RuntimeException('Attachment is not an image.');
        if (! empty($context['dry_run'])) return array('would_regenerate' => $id, 'before' => $before, '_current_fingerprint' => Fingerprint::make($before));
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($id, $file);
        if (! is_array($metadata) || ! wp_update_attachment_metadata($id, $metadata)) throw new RuntimeException('Image metadata regeneration failed.');
        return array('attachment_id' => $id, 'metadata' => wp_get_attachment_metadata($id), 'rollback_supported' => false);
    }

    private function attachmentId(array $payload): int
    {
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $post = get_post($id);
        if (! $post instanceof \WP_Post || 'attachment' !== $post->post_type) throw new RuntimeException('Attachment not found.');
        return $id;
    }

    private function snapshot(int $id): array
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post) throw new RuntimeException('Attachment not found.');
        return array(
            'id' => $id,
            'title' => (string) $post->post_title,
            'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'caption' => (string) $post->post_excerpt,
            'description' => (string) $post->post_content,
            'parent' => (int) $post->post_parent,
            'mime_type' => (string) $post->post_mime_type,
            'url' => wp_get_attachment_url($id),
            'file' => get_attached_file($id),
            'metadata' => wp_get_attachment_metadata($id),
        );
    }

    private function applyUpdate(array $payload): void
    {
        $id = $this->attachmentId($payload);
        $postFields = array('ID' => $id);
        if (array_key_exists('title', $payload)) $postFields['post_title'] = sanitize_text_field((string) $payload['title']);
        if (array_key_exists('caption', $payload)) $postFields['post_excerpt'] = (string) $payload['caption'];
        if (array_key_exists('description', $payload)) $postFields['post_content'] = (string) $payload['description'];
        if (array_key_exists('parent', $payload)) $postFields['post_parent'] = (int) $payload['parent'];
        if (count($postFields) > 1) {
            $updated = wp_update_post(wp_slash($postFields), true);
            if (is_wp_error($updated)) throw new RuntimeException($updated->get_error_message());
        }
        if (array_key_exists('alt', $payload)) update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field((string) $payload['alt']));
    }
}
