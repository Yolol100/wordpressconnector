<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Admin;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Adapters\ElementorAdapter;

final class ElementorJsonImport
{
    private const ACTION = 'wpconnector_import_elementor_json';
    private const POST_TYPES = array('page', 'post', 'elementor_library');

    public function register(): void
    {
        if (! is_admin()) {
            return;
        }

        add_filter('page_row_actions', array($this, 'rowActions'), 99, 2);
        add_filter('post_row_actions', array($this, 'rowActions'), 99, 2);
        add_action('admin_menu', array($this, 'registerImportPages'));
        add_action('admin_post_' . self::ACTION, array($this, 'handleImport'));
    }

    public function rowActions(array $actions, \WP_Post $post): array
    {
        if (! $this->supportsPostType((string) $post->post_type) || ! current_user_can('edit_post', (int) $post->ID)) {
            return $actions;
        }

        $url = $this->importUrl((string) $post->post_type, (int) $post->ID);
        $actions['wpconnector_import_elementor_json'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url($url),
            esc_html__('Import Elementor JSON', 'wordpressconnector')
        );

        return $actions;
    }

    public function registerImportPages(): void
    {
        add_submenu_page(
            'edit.php?post_type=page',
            __('Import Elementor JSON', 'wordpressconnector'),
            __('Import Elementor JSON', 'wordpressconnector'),
            'edit_pages',
            'wpconnector-import-elementor-page',
            function (): void {
                $this->renderImportPage('page');
            }
        );

        add_submenu_page(
            'edit.php',
            __('Import Elementor JSON', 'wordpressconnector'),
            __('Import Elementor JSON', 'wordpressconnector'),
            'edit_posts',
            'wpconnector-import-elementor-post',
            function (): void {
                $this->renderImportPage('post');
            }
        );

        if (post_type_exists('elementor_library')) {
            add_submenu_page(
                'edit.php?post_type=elementor_library',
                __('Import Elementor JSON', 'wordpressconnector'),
                __('Import Elementor JSON', 'wordpressconnector'),
                'edit_posts',
                'wpconnector-import-elementor-template',
                function (): void {
                    $this->renderImportPage('elementor_library');
                }
            );
        }
    }

    public function renderImportPage(string $postType): void
    {
        if (! $this->supportsPostType($postType) || ! current_user_can($this->capabilityForPostType($postType))) {
            wp_die(esc_html__('You are not allowed to import Elementor JSON here.', 'wordpressconnector'), '', array('response' => 403));
        }

        $selectedId = isset($_GET['post_id']) ? absint(wp_unslash($_GET['post_id'])) : 0;
        $posts = get_posts(array(
            'post_type' => $postType,
            'post_status' => array('publish', 'draft', 'pending', 'private', 'future'),
            'posts_per_page' => 200,
            'orderby' => 'modified',
            'order' => 'DESC',
        ));

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Import Elementor JSON', 'wordpressconnector') . '</h1>';
        echo '<p>' . esc_html__('Choose an existing item and upload an Elementor JSON export. The Elementor structure and page settings of that item will be replaced. WordPress Connector verifies the write and restores the previous Elementor snapshot automatically if readback fails.', 'wordpressconnector') . '</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="post_type" value="' . esc_attr($postType) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="wpconnector-target-post">' . esc_html__('Target', 'wordpressconnector') . '</label></th><td>';
        echo '<select id="wpconnector-target-post" name="post_id" required>';
        echo '<option value="">' . esc_html__('Select an item', 'wordpressconnector') . '</option>';
        foreach ($posts as $post) {
            if (! $post instanceof \WP_Post || ! current_user_can('edit_post', (int) $post->ID)) {
                continue;
            }
            printf(
                '<option value="%1$d"%2$s>%3$s (#%1$d)</option>',
                (int) $post->ID,
                selected($selectedId, (int) $post->ID, false),
                esc_html((string) $post->post_title)
            );
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="wpconnector-elementor-json">' . esc_html__('Elementor JSON file', 'wordpressconnector') . '</label></th><td>';
        echo '<input id="wpconnector-elementor-json" type="file" name="elementor_json" accept="application/json,.json" required>';
        echo '<p class="description">' . esc_html__('Accepted fields: content, page_settings (or settings), version, title and type. Maximum file size: 5 MB.', 'wordpressconnector') . '</p>';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button(__('Import and replace Elementor content', 'wordpressconnector'));
        echo '</form></div>';
    }

    public function handleImport(): void
    {
        check_admin_referer(self::ACTION);

        $postId = isset($_POST['post_id']) ? absint(wp_unslash($_POST['post_id'])) : 0;
        $postType = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : '';
        if ($postId < 1 || ! $this->supportsPostType($postType)) {
            wp_die(esc_html__('Invalid Elementor JSON import target.', 'wordpressconnector'), '', array('response' => 400));
        }

        $post = get_post($postId);
        if (! $post instanceof \WP_Post || (string) $post->post_type !== $postType) {
            wp_die(esc_html__('The selected import target no longer exists.', 'wordpressconnector'), '', array('response' => 400));
        }
        if (! current_user_can('edit_post', $postId)) {
            wp_die(esc_html__('You are not allowed to edit this item.', 'wordpressconnector'), '', array('response' => 403));
        }

        try {
            $payload = $this->uploadedPayload();
            $data = isset($payload['content']) && is_array($payload['content']) ? $payload['content'] : null;
            if (null === $data || empty($data)) {
                throw new RuntimeException('The uploaded Elementor JSON does not contain non-empty content.');
            }
            $settings = array();
            if (isset($payload['page_settings']) && is_array($payload['page_settings'])) {
                $settings = $payload['page_settings'];
            } elseif (isset($payload['settings']) && is_array($payload['settings'])) {
                $settings = $payload['settings'];
            }

            $adapterPayload = array(
                'id' => $postId,
                'data' => $data,
                'page_settings' => $settings,
                'edit_mode' => 'builder',
            );
            if ('elementor_library' === $postType && isset($payload['type']) && is_string($payload['type']) && '' !== $payload['type']) {
                $adapterPayload['template_type'] = sanitize_key($payload['type']);
            }

            (new ElementorAdapter())->replaceDocument($adapterPayload, array('dry_run' => false));
        } catch (RuntimeException $error) {
            wp_die(esc_html($error->getMessage()), '', array('response' => 400));
        } catch (Throwable $error) {
            wp_die(esc_html__('The Elementor JSON import failed.', 'wordpressconnector'), '', array('response' => 500));
        }

        $redirect = add_query_arg(
            array(
                'post_type' => $postType,
                'wpconnector_elementor_imported' => 1,
            ),
            admin_url('edit.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    private function uploadedPayload(): array
    {
        if (! isset($_FILES['elementor_json']) || ! is_array($_FILES['elementor_json'])) {
            throw new RuntimeException('No Elementor JSON file was uploaded.');
        }

        $file = $_FILES['elementor_json']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below as a local upload.
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if (UPLOAD_ERR_OK !== $error) {
            throw new RuntimeException('The Elementor JSON upload failed.');
        }
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size < 1 || $size > 5 * MB_IN_BYTES) {
            throw new RuntimeException('The Elementor JSON file must be between 1 byte and 5 MB.');
        }
        $tmpName = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ('' === $tmpName || ! is_uploaded_file($tmpName)) {
            throw new RuntimeException('The Elementor JSON upload is invalid.');
        }

        $raw = file_get_contents($tmpName); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local validated upload.
        if (! is_string($raw) || '' === trim($raw)) {
            throw new RuntimeException('The Elementor JSON file is empty.');
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || JSON_ERROR_NONE !== json_last_error()) {
            throw new RuntimeException('The uploaded file is not valid JSON.');
        }
        if (! isset($decoded['content']) || ! is_array($decoded['content'])) {
            throw new RuntimeException('The JSON is not an Elementor document export.');
        }
        return $decoded;
    }

    private function importUrl(string $postType, int $postId): string
    {
        $slug = 'page' === $postType
            ? 'wpconnector-import-elementor-page'
            : ('post' === $postType ? 'wpconnector-import-elementor-post' : 'wpconnector-import-elementor-template');
        $base = 'post' === $postType ? admin_url('edit.php') : admin_url('edit.php?post_type=' . $postType);
        return add_query_arg(array('page' => $slug, 'post_id' => $postId), $base);
    }

    private function capabilityForPostType(string $postType): string
    {
        return 'page' === $postType ? 'edit_pages' : 'edit_posts';
    }

    private function supportsPostType(string $postType): bool
    {
        return in_array($postType, self::POST_TYPES, true);
    }
}
