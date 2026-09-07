<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Admin;

use RuntimeException;
use Throwable;

final class ElementorJsonExport
{
    private const ACTION = 'wpconnector_export_elementor_json';
    private const BULK_ACTION = 'wpconnector_bulk_export_elementor_json';
    private const POST_TYPES = array('page', 'post', 'elementor_library');
    private const BUNDLE_FORMAT = 'wordpressconnector/elementor-site-parts-bundle';
    private const BULK_FORMAT = 'wordpressconnector/elementor-bulk-export';
    private const BUNDLE_VERSION = 1;
    private const MAX_BULK_ITEMS = 100;

    public function register(): void
    {
        if (! is_admin()) {
            return;
        }

        add_filter('page_row_actions', array($this, 'rowActions'), 99, 2);
        add_filter('post_row_actions', array($this, 'rowActions'), 99, 2);
        add_action('admin_post_' . self::ACTION, array($this, 'download'));

        foreach (array('edit-page', 'edit-post', 'edit-elementor_library') as $screen) {
            add_filter('bulk_actions-' . $screen, array($this, 'bulkActions'));
            add_filter('handle_bulk_actions-' . $screen, array($this, 'handleBulkAction'), 10, 3);
        }

        add_action('admin_notices', array($this, 'bulkNotice'));
    }

    public function rowActions(array $actions, \WP_Post $post): array
    {
        if (! $this->supportsPostType((string) $post->post_type)
            || ! current_user_can('edit_post', (int) $post->ID)
            || ! $this->isElementorDocument((int) $post->ID)) {
            return $actions;
        }

        if ('elementor_library' === (string) $post->post_type && isset($actions['export-template'])) {
            return $actions;
        }

        $actions['wpconnector_export_elementor_json'] = sprintf(
            '<a href="%1$s">%2$s</a>',
            esc_url($this->exportUrl((int) $post->ID, false)),
            esc_html__('Export Elementor JSON', 'wordpressconnector')
        );

        if (in_array((string) $post->post_type, array('page', 'post'), true)
            && class_exists('ElementorPro\\Modules\\ThemeBuilder\\Module')) {
            $actions['wpconnector_export_elementor_site_parts'] = sprintf(
                '<a href="%1$s">%2$s</a>',
                esc_url($this->exportUrl((int) $post->ID, true)),
                esc_html__('Export Elementor + Site Parts', 'wordpressconnector')
            );
        }

        return $actions;
    }

    public function bulkActions(array $actions): array
    {
        $actions[self::BULK_ACTION] = __('Export Elementor JSON (ZIP)', 'wordpressconnector');
        return $actions;
    }

    public function handleBulkAction(string $redirectUrl, string $action, array $postIds): string
    {
        if (self::BULK_ACTION !== $action) {
            return $redirectUrl;
        }

        check_admin_referer('bulk-posts');

        $postIds = array_values(array_unique(array_filter(array_map('absint', $postIds))));
        if (count($postIds) > self::MAX_BULK_ITEMS) {
            $postIds = array_slice($postIds, 0, self::MAX_BULK_ITEMS);
        }

        $files = array();
        $exported = array();
        $skipped = array();

        foreach ($postIds as $postId) {
            $post = get_post($postId);
            if (! $post instanceof \WP_Post || ! $this->supportsPostType((string) $post->post_type)) {
                $skipped[] = array('post_id' => $postId, 'reason' => 'unsupported_post_type');
                continue;
            }
            if (! current_user_can('edit_post', $postId)) {
                $skipped[] = array('post_id' => $postId, 'reason' => 'forbidden');
                continue;
            }
            if (! $this->isElementorDocument($postId)) {
                $skipped[] = array('post_id' => $postId, 'reason' => 'not_elementor');
                continue;
            }

            try {
                $payload = $this->exportPayload($this->document($postId), $post);
                $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (! is_string($json) || '' === $json) {
                    throw new RuntimeException('WordPress could not encode one Elementor export as JSON.');
                }
            } catch (Throwable $error) {
                $skipped[] = array('post_id' => $postId, 'reason' => 'export_failed');
                continue;
            }

            $slug = '' !== (string) $post->post_name ? (string) $post->post_name : (string) $post->post_type . '-' . $postId;
            $filename = sanitize_file_name($postId . '-' . $slug . '-elementor.json');
            $files[$filename] = $json;
            $exported[] = array(
                'post_id' => $postId,
                'post_type' => (string) $post->post_type,
                'title' => (string) $post->post_title,
                'file' => $filename,
            );
        }

        if (empty($files)) {
            return add_query_arg('wpconnector_elementor_bulk_export', 'none', $redirectUrl);
        }

        $manifest = array(
            'format' => self::BULK_FORMAT,
            'version' => 1,
            'exported' => $exported,
            'skipped' => $skipped,
        );

        $this->downloadBulkArchive($files, $manifest);
        return $redirectUrl;
    }

    public function bulkNotice(): void
    {
        if (! isset($_GET['wpconnector_elementor_bulk_export'])
            || 'none' !== (string) wp_unslash($_GET['wpconnector_elementor_bulk_export'])) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p>'
            . esc_html__('No selected item could be exported as Elementor JSON.', 'wordpressconnector')
            . '</p></div>';
    }

    public function download(): void
    {
        $postId = isset($_GET['post_id']) ? absint(wp_unslash($_GET['post_id'])) : 0;
        $includeSiteParts = isset($_GET['include_site_parts']) && '1' === (string) wp_unslash($_GET['include_site_parts']);
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
        if ($includeSiteParts && ! in_array((string) $post->post_type, array('page', 'post'), true)) {
            wp_die(esc_html__('Site-parts export is available only for pages and posts.', 'wordpressconnector'), '', array('response' => 400));
        }

        try {
            $documentPayload = $this->exportPayload($this->document($postId), $post);
            $payload = $includeSiteParts ? $this->bundleWithSiteParts($post, $documentPayload) : $documentPayload;
            $json = wp_json_encode($payload);
            if (! is_string($json) || '' === $json) {
                throw new RuntimeException('WordPress could not encode the Elementor export as JSON.');
            }
        } catch (RuntimeException $error) {
            wp_die(esc_html($error->getMessage()), '', array('response' => 400));
        } catch (Throwable $error) {
            wp_die(esc_html__('The Elementor JSON export could not be completed.', 'wordpressconnector'), '', array('response' => 500));
        }

        $slug = '' !== (string) $post->post_name ? (string) $post->post_name : (string) $post->post_type . '-' . $postId;
        $filename = sanitize_file_name($slug . ($includeSiteParts ? '-elementor-with-site-parts.json' : '-elementor.json'));
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Content-Length: ' . strlen($json));
        header('X-Content-Type-Options: nosniff');
        echo $json;
        exit;
    }

    private function downloadBulkArchive(array $files, array $manifest): void
    {
        $archivePath = wp_tempnam('wordpressconnector-elementor-json.zip');
        if (! is_string($archivePath) || '' === $archivePath) {
            wp_die(esc_html__('WordPress could not create a temporary bulk export archive.', 'wordpressconnector'), '', array('response' => 500));
        }

        try {
            if (class_exists('ZipArchive')) {
                $zip = new \ZipArchive();
                $opened = $zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                if (true !== $opened) {
                    throw new RuntimeException('The Elementor bulk export ZIP could not be opened.');
                }
                foreach ($files as $filename => $json) {
                    if (! $zip->addFromString($filename, $json)) {
                        $zip->close();
                        throw new RuntimeException('One Elementor JSON file could not be added to the ZIP.');
                    }
                }
                $manifestJson = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (! is_string($manifestJson) || ! $zip->addFromString('manifest.json', $manifestJson)) {
                    $zip->close();
                    throw new RuntimeException('The Elementor bulk export manifest could not be added to the ZIP.');
                }
                if (! $zip->close()) {
                    throw new RuntimeException('The Elementor bulk export ZIP could not be finalized.');
                }
            } else {
                $this->buildBulkArchiveWithPclZip($archivePath, $files, $manifest);
            }

            $size = filesize($archivePath);
            if (false === $size || $size < 1) {
                throw new RuntimeException('The Elementor bulk export ZIP is empty.');
            }

            nocache_headers();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename=elementor-json-bulk-' . gmdate('Ymd-His') . '.zip');
            header('Content-Length: ' . (string) $size);
            header('X-Content-Type-Options: nosniff');
            readfile($archivePath);
        } catch (Throwable $error) {
            @unlink($archivePath);
            wp_die(esc_html__('The Elementor JSON bulk export could not be completed.', 'wordpressconnector'), '', array('response' => 500));
        }

        @unlink($archivePath);
        exit;
    }

    private function buildBulkArchiveWithPclZip(string $archivePath, array $files, array $manifest): void
    {
        if (! class_exists('PclZip')) {
            $pclZipPath = ABSPATH . 'wp-admin/includes/class-pclzip.php';
            if (! is_file($pclZipPath)) {
                throw new RuntimeException('WordPress PclZip is unavailable.');
            }
            require_once $pclZipPath;
        }
        if (! class_exists('PclZip')) {
            throw new RuntimeException('WordPress PclZip could not be loaded.');
        }

        $tempDir = wp_tempnam('wpconnector-elementor-bulk');
        if (! is_string($tempDir) || '' === $tempDir) {
            throw new RuntimeException('WordPress could not create a temporary bulk export directory.');
        }
        @unlink($tempDir);
        if (! wp_mkdir_p($tempDir)) {
            throw new RuntimeException('WordPress could not prepare the temporary bulk export directory.');
        }

        $paths = array();
        try {
            foreach ($files as $filename => $json) {
                $path = trailingslashit($tempDir) . $filename;
                if (false === file_put_contents($path, $json)) {
                    throw new RuntimeException('One Elementor JSON file could not be written for the ZIP.');
                }
                $paths[] = $path;
            }

            $manifestPath = trailingslashit($tempDir) . 'manifest.json';
            $manifestJson = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (! is_string($manifestJson) || false === file_put_contents($manifestPath, $manifestJson)) {
                throw new RuntimeException('The Elementor bulk export manifest could not be written.');
            }
            $paths[] = $manifestPath;

            $archive = new \PclZip($archivePath);
            $created = $archive->create($paths, PCLZIP_OPT_REMOVE_PATH, $tempDir);
            if (0 === $created) {
                throw new RuntimeException('WordPress PclZip could not create the Elementor bulk export archive.');
            }
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
            @rmdir($tempDir);
        }
    }

    private function exportUrl(int $postId, bool $includeSiteParts): string
    {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action' => self::ACTION,
                    'post_id' => $postId,
                    'include_site_parts' => $includeSiteParts ? 1 : 0,
                ),
                admin_url('admin-post.php')
            ),
            self::ACTION . '_' . $postId
        );
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
        $content = isset($templateData['content']) && is_array($templateData['content']) ? $templateData['content'] : array();
        $settings = isset($templateData['settings']) && is_array($templateData['settings']) ? $templateData['settings'] : array();
        if (empty($content)) {
            throw new RuntimeException('The Elementor document is empty.');
        }
        $content = apply_filters('elementor/template_library/sources/local/export/elements', $content);
        if (! is_array($content)) {
            throw new RuntimeException('Elementor export filters returned invalid document content.');
        }
        $type = 'elementor_library' === (string) $post->post_type ? (string) get_post_meta((int) $post->ID, '_elementor_template_type', true) : '';
        if ('' === $type && method_exists($document, 'get_name')) {
            $type = (string) $document->get_name();
        }
        if ('' === $type) {
            $type = 'page' === (string) $post->post_type ? 'wp-page' : ('post' === (string) $post->post_type ? 'wp-post' : 'page');
        }
        $payload = array(
            'content' => $content,
            'page_settings' => $settings,
            'version' => class_exists('Elementor\\DB') ? (string) \Elementor\DB::DB_VERSION : '0.4',
            'title' => (string) $post->post_title,
            'type' => $type,
        );
        $snapshots = apply_filters('elementor/template_library/export/build_snapshots', array(), $content, (int) $post->ID, $payload);
        if (is_array($snapshots)) {
            if (! empty($snapshots['global_classes'])) {
                $payload['global_classes'] = $snapshots['global_classes'];
            }
            if (! empty($snapshots['global_variables'])) {
                $payload['global_variables'] = $snapshots['global_variables'];
            }
        }
        return $payload;
    }

    private function bundleWithSiteParts(\WP_Post $post, array $documentPayload): array
    {
        $parts = $this->sitePartsForPost($post);
        return array(
            'format' => self::BUNDLE_FORMAT,
            'bundle_version' => self::BUNDLE_VERSION,
            'source' => array(
                'post_id' => (int) $post->ID,
                'post_type' => (string) $post->post_type,
                'title' => (string) $post->post_title,
            ),
            'document' => $documentPayload,
            'header' => isset($parts['header']['payload']) ? $parts['header']['payload'] : null,
            'footer' => isset($parts['footer']['payload']) ? $parts['footer']['payload'] : null,
            'site_part_meta' => array(
                'header' => $this->sitePartMeta($parts['header'] ?? null),
                'footer' => $this->sitePartMeta($parts['footer'] ?? null),
            ),
            'warnings' => isset($parts['warnings']) && is_array($parts['warnings']) ? $parts['warnings'] : array(),
        );
    }

    private function sitePartsForPost(\WP_Post $sourcePost): array
    {
        if (! class_exists('ElementorPro\\Modules\\ThemeBuilder\\Module')) {
            return array('header' => null, 'footer' => null, 'warnings' => array('Elementor Pro Theme Builder is not active.'));
        }
        try {
            $module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
            if (! is_object($module) || ! method_exists($module, 'get_conditions_manager')) {
                return $this->unsupportedSiteParts();
            }
            $conditions = $module->get_conditions_manager();
            if (! is_object($conditions) || ! method_exists($conditions, 'get_documents_for_location')) {
                return $this->unsupportedSiteParts();
            }
            $matches = $this->withSingularQuery(
                $sourcePost,
                static function () use ($conditions): array {
                    return array(
                        'header' => $conditions->get_documents_for_location('header'),
                        'footer' => $conditions->get_documents_for_location('footer'),
                    );
                }
            );
            $header = $this->firstSitePart($matches['header'] ?? array(), 'header');
            $footer = $this->firstSitePart($matches['footer'] ?? array(), 'footer');
            $warnings = array();
            if (null === $header) {
                $warnings[] = 'No matching Elementor Theme Builder header was found for this document.';
            }
            if (null === $footer) {
                $warnings[] = 'No matching Elementor Theme Builder footer was found for this document.';
            }
            return array('header' => $header, 'footer' => $footer, 'warnings' => $warnings);
        } catch (Throwable $error) {
            return $this->unsupportedSiteParts('Elementor Pro Theme Builder could not resolve the matching site parts for this document.');
        }
    }

    private function firstSitePart($matches, string $location): ?array
    {
        if (! is_array($matches) || empty($matches)) {
            return null;
        }
        foreach ($matches as $key => $document) {
            $templateId = 0;
            $template = null;
            if (is_object($document) && method_exists($document, 'get_post')) {
                $template = $document->get_post();
                if ($template instanceof \WP_Post) {
                    $templateId = (int) $template->ID;
                }
            } elseif (is_numeric($key)) {
                $templateId = (int) $key;
            } elseif (is_numeric($document)) {
                $templateId = (int) $document;
            }
            if ($templateId < 1) {
                continue;
            }
            $template = $template instanceof \WP_Post ? $template : get_post($templateId);
            if (! $template instanceof \WP_Post || ! current_user_can('edit_post', $templateId)) {
                continue;
            }
            $payload = $this->exportPayload($this->document($templateId), $template);
            if ((string) ($payload['type'] ?? '') !== $location) {
                continue;
            }
            return array('id' => $templateId, 'title' => (string) $template->post_title, 'payload' => $payload);
        }
        return null;
    }

    private function withSingularQuery(\WP_Post $sourcePost, callable $callback)
    {
        global $post, $wp_query, $wp_the_query;
        $previousPost = $post ?? null;
        $previousQuery = $wp_query ?? null;
        $previousTheQuery = $wp_the_query ?? null;
        $args = array(
            'post_type' => (string) $sourcePost->post_type,
            'post_status' => 'any',
            'posts_per_page' => 1,
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        );
        if ('page' === (string) $sourcePost->post_type) {
            $args['page_id'] = (int) $sourcePost->ID;
        } else {
            $args['p'] = (int) $sourcePost->ID;
        }
        $query = new \WP_Query($args);
        if (! $query->have_posts()) {
            throw new RuntimeException('The document context could not be prepared for Theme Builder conditions.');
        }
        $wp_query = $query;
        $wp_the_query = $query;
        $query->the_post();
        try {
            return $callback();
        } finally {
            $query->reset_postdata();
            $wp_query = $previousQuery;
            $wp_the_query = $previousTheQuery;
            $post = $previousPost;
            if ($previousPost instanceof \WP_Post) {
                setup_postdata($previousPost);
            }
        }
    }

    private function unsupportedSiteParts(string $warning = 'The active Elementor Pro version does not expose the Theme Builder condition API required for site-parts export.'): array
    {
        return array('header' => null, 'footer' => null, 'warnings' => array($warning));
    }

    private function sitePartMeta(?array $part): ?array
    {
        return null === $part ? null : array('id' => (int) ($part['id'] ?? 0), 'title' => (string) ($part['title'] ?? ''));
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
        if (! is_object($document) || ! method_exists($document, 'get_elements_data') || ! method_exists($document, 'get_export_data')) {
            throw new RuntimeException('This item is not an exportable Elementor document.');
        }
        return $document;
    }

    private function supportsPostType(string $postType): bool
    {
        return in_array($postType, self::POST_TYPES, true);
    }
}
