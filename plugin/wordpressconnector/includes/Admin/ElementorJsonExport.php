<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Admin;

use RuntimeException;

final class ElementorJsonExport
{
    private const ACTION = 'wpconnector_export_elementor_json';
    private const POST_TYPES = array('page', 'post', 'elementor_library');

    public function register(): void
    {
        if (! is_admin()) {
            return;
        }

        add_filter('page_row_actions', array($this, 'rowActions'), 99, 2);
        add_filter('post_row_actions', array($this, 'rowActions'), 99, 2);
        add_action('admin_post_' . self::ACTION, array($this, 'download'));
    }

    public function rowActions(array $actions, \WP_Post $post): array
    {
        if (! $this->supportsPostType((string) $post->post_type)) {
            return $actions;
        }

        if (! current_user_can('edit_post', (int) $post->ID) || ! $this->isElementorDocument((int) $post->ID)) {
            return $actions;
        }

        // Elementor already supplies the canonical template export action. Keep it
        // when present and only provide a fallback for the template library.
        if ('elementor_library' === (string) $post->post_type && isset($actions['export-template'])) {
            return $actions;
        }

        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'action' => self::ACTION,
                    'post_id' => (int) $post->ID,
                ),
                admin_url('admin-post.php')
            ),
            self::ACTION . '_' . (int) $post->ID
        );

        $actions['wpconnector_export_elementor_json'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url($url),
            esc_html__('Export Elementor JSON', 'wordpressconnector')
        );

        return $actions;
    }

    public function download(): void
    {
        $postId = isset($_GET['post_id']) ? absint(wp_unslash($_GET['post_id'])) : 0;
        if ($postId < 1) {
            wp_die(esc_html__('Invalid Elementor document.', 'wordpressconnector'), '', array('response' => 400));
        }

        check_admin_referer(self::ACTION . '_' . $postId);

        $post = get_post($postId);
        if (! $post instanceof \WP_Post || ! $this->supportsPostType((string) $post->post_type)) {
            wp_die(esc_html__('This item cannot be exported as Elementor JSON.', 'wordpressconnector'), '', array('response' => 400));
        }

        if (! current_user_can('edit_post', $postId)) {
            wp_die(esc_html__('You are not allowed to export this Elementor document.', 'wordpressconnector'), '', array('response' => 403));
        }

        try {
            $document = $this->document($postId);
            $payload = $this->exportPayload($document, $post);
            $json = wp_json_encode($payload);
            if (! is_string($json) || '' === $json) {
                throw new RuntimeException('WordPress could not encode the Elementor export as JSON.');
            }
        } catch (RuntimeException $error) {
            wp_die(esc_html($error->getMessage()), '', array('response' => 400));
        }

        $filename = sanitize_file_name('elementor-' . $postId . '-' . gmdate('Y-m-d') . '.json');

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        header('X-Content-Type-Options: nosniff');

        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download payload.
        exit;
    }

    private function exportPayload(object $document, \WP_Post $post): array
    {
        if (! method_exists($document, 'get_export_data')) {
            throw new RuntimeException('This Elementor version does not expose document export data.');
        }

        $templateData = $document->get_export_data();
        if (! is_array($templateData)) {
            throw new RuntimeException('Elementor returned invalid export data.');
        }

        $content = isset($templateData['content']) && is_array($templateData['content'])
            ? $templateData['content']
            : array();
        $settings = isset($templateData['settings']) && is_array($templateData['settings'])
            ? $templateData['settings']
            : array();

        if (empty($content)) {
            throw new RuntimeException('The Elementor document is empty.');
        }

        $type = '';
        if ('elementor_library' === (string) $post->post_type) {
            $type = (string) get_post_meta((int) $post->ID, '_elementor_template_type', true);
        }
        if ('' === $type && method_exists($document, 'get_name')) {
            $type = (string) $document->get_name();
        }
        if ('' === $type) {
            $type = 'page' === (string) $post->post_type ? 'wp-page' : ('post' === (string) $post->post_type ? 'wp-post' : 'page');
        }

        $version = class_exists('Elementor\\DB') ? (string) \Elementor\DB::DB_VERSION : '0.4';

        return array(
            'content' => $content,
            'page_settings' => $settings,
            'version' => $version,
            'title' => (string) $post->post_title,
            'type' => $type,
        );
    }

    private function isElementorDocument(int $postId): bool
    {
        if ('builder' !== (string) get_post_meta($postId, '_elementor_edit_mode', true)) {
            return false;
        }

        try {
            $this->document($postId);
            return true;
        } catch (RuntimeException $error) {
            return false;
        }
    }

    private function document(int $postId): object
    {
        if (! class_exists('Elementor\\Plugin')) {
            throw new RuntimeException('Elementor is not active.');
        }

        $plugin = \Elementor\Plugin::$instance;
        $manager = isset($plugin->documents) ? $plugin->documents : null;
        if (! is_object($manager) || ! method_exists($manager, 'get')) {
            throw new RuntimeException('Elementor document manager is unavailable.');
        }

        $document = $manager->get($postId);
        if (! is_object($document) || ! method_exists($document, 'get_elements_data')) {
            throw new RuntimeException('This item is not an editable Elementor document.');
        }

        return $document;
    }

    private function supportsPostType(string $postType): bool
    {
        return in_array($postType, self::POST_TYPES, true);
    }
}
