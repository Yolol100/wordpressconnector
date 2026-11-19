<?php

declare(strict_types=1);

namespace {
    final class WP_Post
    {
        public $ID;
        public $post_type;
        public $post_title;

        public function __construct(int $id, string $postType, string $title)
        {
            $this->ID = $id;
            $this->post_type = $postType;
            $this->post_title = $title;
        }
    }

    // Minimal isolated WP_Query fixture: no WordPress installation is loaded.
    final class WP_Query
    {
        public function __construct(array $args) {}
        public function have_posts(): bool { return true; }
        public function the_post(): void {
            if ('the_post' === ($GLOBALS['wpconnector_query_fail_at'] ?? '')) {
                throw new RuntimeException('Injected query setup error.');
            }
            $GLOBALS['post'] = new WP_Post(999, 'page', 'Export subject');
        }
        public function reset_postdata(): void {
            ++$GLOBALS['wpconnector_query_reset_calls'];
            if ('reset_postdata' === ($GLOBALS['wpconnector_query_fail_at'] ?? '')) {
                throw new RuntimeException('Injected query reset error.');
            }
        }
    }
    function setup_postdata(WP_Post $post): void
    {
        $GLOBALS['post'] = $post;
    }

    $GLOBALS['wpconnector_test_hooks'] = array();
    $GLOBALS['wpconnector_test_can_edit'] = true;
    $GLOBALS['wpconnector_test_meta'] = array();
    $GLOBALS['wpconnector_test_documents'] = array();
    $GLOBALS['wpconnector_test_invalid_export_filter'] = false;

    function is_admin(): bool
    {
        return true;
    }

    function add_filter(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['wpconnector_test_hooks'][$hook] = array($callback, $priority, $acceptedArgs);
        return true;
    }

    function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        $GLOBALS['wpconnector_test_hooks'][$hook] = array($callback, $priority, $acceptedArgs);
        return true;
    }

    function current_user_can(string $capability, ...$args): bool
    {
        return (bool) $GLOBALS['wpconnector_test_can_edit'];
    }

    function get_post_meta(int $postId, string $key, bool $single = true)
    {
        return $GLOBALS['wpconnector_test_meta'][$postId][$key] ?? '';
    }

    function wp_nonce_url(string $url, string $action): string
    {
        return $url . '&_wpnonce=test-' . rawurlencode($action);
    }

    function add_query_arg(array $args, string $url): string
    {
        return $url . '?' . http_build_query($args, '', '&');
    }

    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }

    function esc_url(string $value): string
    {
        return $value;
    }

    function esc_html__(string $text, string $domain = ''): string
    {
        return $text;
    }

    function apply_filters(string $hook, $value, ...$args)
    {
        if ('elementor/template_library/sources/local/export/elements' === $hook) {
            if (! empty($GLOBALS['wpconnector_test_invalid_export_filter'])) {
                return 'invalid';
            }
            if (isset($value[0]) && is_array($value[0])) {
                $value[0]['export_filter'] = 'applied';
            }
            return $value;
        }

        if ('elementor/template_library/export/build_snapshots' === $hook) {
            return array(
                'global_classes' => array('class-a' => array('label' => 'Class A')),
                'global_variables' => array('var-a' => array('value' => '#123456')),
            );
        }

        return $value;
    }
}

namespace Elementor {
    final class DB
    {
        public const DB_VERSION = '3-test';
    }

    final class Plugin
    {
        public static $instance;
    }
}

namespace {
    final class FakeElementorDocument
    {
        private $name;

        public function __construct(string $name)
        {
            $this->name = $name;
        }

        public function get_elements_data(): array
        {
            return array(array('id' => 'original'));
        }

        public function get_export_data(): array
        {
            return array(
                'content' => array(array('id' => 'exported')),
                'settings' => array('hide_title' => 'yes'),
            );
        }

        public function get_name(): string
        {
            return $this->name;
        }
    }

    final class NonExportableElementorDocument
    {
        public function get_elements_data(): array
        {
            return array(array('id' => 'original'));
        }
    }

    final class FakeElementorDocumentsManager
    {
        public function get(int $postId)
        {
            return $GLOBALS['wpconnector_test_documents'][$postId] ?? null;
        }
    }

    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Input.php';
    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Admin/ElementorJsonExport.php';

    \Elementor\Plugin::$instance = (object) array(
        'documents' => new FakeElementorDocumentsManager(),
    );

    $assert = static function (bool $condition, string $message): void {
        if (! $condition) {
            fwrite(STDERR, $message . "\n");
            exit(1);
        }
    };

    $GLOBALS['wpconnector_test_meta'] = array(
        10 => array('_elementor_edit_mode' => 'builder'),
        11 => array('_elementor_edit_mode' => 'builder'),
        12 => array('_elementor_edit_mode' => 'builder', '_elementor_template_type' => 'header'),
        13 => array('_elementor_edit_mode' => 'builder', '_elementor_template_type' => 'footer'),
        14 => array('_elementor_edit_mode' => ''),
        15 => array('_elementor_edit_mode' => 'builder'),
    );
    $GLOBALS['wpconnector_test_documents'] = array(
        10 => new FakeElementorDocument('wp-page'),
        11 => new FakeElementorDocument('wp-post'),
        12 => new FakeElementorDocument('header'),
        13 => new FakeElementorDocument('footer'),
        14 => new FakeElementorDocument('wp-page'),
        15 => new NonExportableElementorDocument(),
    );

    $export = new \Webactueel\WordPressConnector\Admin\ElementorJsonExport();
    $export->register();

    foreach (array('page_row_actions', 'post_row_actions', 'admin_post_wpconnector_export_elementor_json') as $hook) {
        $assert(isset($GLOBALS['wpconnector_test_hooks'][$hook]), 'Missing registered export hook: ' . $hook);
    }

    $page = new WP_Post(10, 'page', 'Example page');
    $pageActions = $export->rowActions(array(), $page);
    $assert(isset($pageActions['wpconnector_export_elementor_json']), 'Elementor page export row action is missing.');
    $assert(false !== strpos($pageActions['wpconnector_export_elementor_json'], 'post_id=10'), 'Page export URL is missing its post ID.');
    $assert(false !== strpos($pageActions['wpconnector_export_elementor_json'], '_wpnonce='), 'Page export URL is missing its nonce.');

    $GLOBALS['wpconnector_test_can_edit'] = false;
    $assert(array() === $export->rowActions(array(), $page), 'Users without edit_post must not receive an export action.');
    $GLOBALS['wpconnector_test_can_edit'] = true;

    $notBuilder = new WP_Post(14, 'page', 'Classic page');
    $assert(array() === $export->rowActions(array(), $notBuilder), 'Non-Elementor content must not receive an export action.');

    $nonExportable = new WP_Post(15, 'page', 'Unsupported Elementor document');
    $assert(array() === $export->rowActions(array(), $nonExportable), 'Non-exportable Elementor documents must not receive an export action.');

    $template = new WP_Post(12, 'elementor_library', 'Header');
    $nativeActions = array('export-template' => '<a>Native</a>');
    $assert($nativeActions === $export->rowActions($nativeActions, $template), 'Native Elementor template export must remain authoritative.');

    $fallbackTemplate = new WP_Post(13, 'elementor_library', 'Footer');
    $fallbackActions = $export->rowActions(array(), $fallbackTemplate);
    $assert(isset($fallbackActions['wpconnector_export_elementor_json']), 'Template fallback export action is missing.');

    $reflection = new \ReflectionMethod($export, 'export_payload');
    $reflection->setAccessible(true);
    $payload = $reflection->invoke($export, $GLOBALS['wpconnector_test_documents'][10], $page);

    $assert('wp-page' === $payload['type'], 'Page export type must come from the Elementor document.');
    $assert('3-test' === $payload['version'], 'Export version must use Elementor DB::DB_VERSION.');
    $assert('yes' === $payload['page_settings']['hide_title'], 'Page settings were not preserved.');
    $assert('applied' === $payload['content'][0]['export_filter'], 'Elementor local export element filter was not applied.');
    $assert(isset($payload['global_classes']['class-a']), 'Referenced global classes snapshot was not attached.');
    $assert(isset($payload['global_variables']['var-a']), 'Referenced global variables snapshot was not attached.');

    $templatePayload = $reflection->invoke($export, $GLOBALS['wpconnector_test_documents'][12], $template);
    $assert('header' === $templatePayload['type'], 'Saved Template export must preserve _elementor_template_type.');

    $GLOBALS['wpconnector_test_invalid_export_filter'] = true;
    try {
        $reflection->invoke($export, $GLOBALS['wpconnector_test_documents'][10], $page);
        $assert(false, 'Invalid Elementor export-filter output must fail closed.');
    } catch (\ReflectionException $error) {
        throw $error;
    } catch (\RuntimeException $error) {
        $assert(false !== strpos($error->getMessage(), 'invalid document content'), 'Unexpected invalid-filter error message.');
    }

    // Regression: the original query and post must survive success and failures
    // in a WordPress hook, the export callback, or query teardown.
    $context = new \ReflectionMethod($export, 'with_singular_query');
    $context->setAccessible(true);
    $originalPost = $GLOBALS['post'] = new WP_Post(77, 'page', 'Original context');
    $originalQuery = $GLOBALS['wp_query'] = (object) array('previous' => true);
    $originalMainQuery = $GLOBALS['wp_the_query'] = (object) array('previous_main' => true);
    $GLOBALS['wpconnector_query_fail_at'] = '';
    $GLOBALS['wpconnector_query_reset_calls'] = 0;
    $value = $context->invoke($export, $page, static function (): string {
        if (! $GLOBALS['wp_query'] instanceof WP_Query || ! $GLOBALS['post'] instanceof WP_Post
            || 999 !== $GLOBALS['post']->ID) {
            throw new RuntimeException('Singular export query context not installed.');
        }
        return 'export-readback-ok';
    });
    $assert('export-readback-ok' === $value, 'Successful export query lost its callback result.');
    $assert($GLOBALS['post'] === $originalPost && $GLOBALS['wp_query'] === $originalQuery
        && $GLOBALS['wp_the_query'] === $originalMainQuery,
        'Successful export query did not restore the calling WordPress globals.');

    foreach (array('the_post', 'callback', 'reset_postdata') as $failure) {
        $GLOBALS['wpconnector_query_fail_at'] = $failure;
        $previousResets = $GLOBALS['wpconnector_query_reset_calls'];
        try {
            $context->invoke($export, $page, static function () use ($failure): void {
                if ('callback' === $failure) {
                    throw new RuntimeException('Injected export callback error.');
                }
            });
            $assert(false, 'Injected ' . $failure . ' error was ignored.');
        } catch (RuntimeException $error) {
            $assert(false !== strpos($error->getMessage(), 'Injected '),
                'Unexpected singular export failure message for ' . $failure);
        }
        $assert($GLOBALS['wpconnector_query_reset_calls'] === $previousResets + 1,
            'Failed singular export query skipped teardown after ' . $failure);
        $assert($GLOBALS['post'] === $originalPost && $GLOBALS['wp_query'] === $originalQuery
            && $GLOBALS['wp_the_query'] === $originalMainQuery,
            'Failed singular export query leaked WordPress globals after ' . $failure);
    }
    $GLOBALS['wpconnector_query_fail_at'] = '';


    // The export GET target uses the same strict positive-ID contract as
    // document mutations. A malformed target must fail before nonce validation.
    function wp_unslash($value) { return $value; }
    function wp_die($message, $title = '', $args = array()): void {
        throw new \DomainException((string) $message);
    }
    function check_admin_referer(string $action): void {
        $GLOBALS['wpconnector_export_nonce_actions'][] = $action;
        throw new \LogicException('Export nonce boundary reached.');
    }

    $previousGet = $_GET ?? array();
    $GLOBALS['wpconnector_export_nonce_actions'] = array();
    $invalidExportIds = array(null, true, false, 10.5, 0, '0', '0000', -10,
        '10junk', '10.5', '1e1', ' 10', "10\n", '999999999999999999999999', array(10));
    foreach ($invalidExportIds as $invalidId) {
        $_GET = array('post_id' => $invalidId);
        $beforeNonces = count($GLOBALS['wpconnector_export_nonce_actions']);
        try {
            $export->download();
            $assert(false, 'Malformed Elementor export ID was accepted.');
        } catch (\DomainException $error) {
            $assert('Invalid Elementor document.' === $error->getMessage(),
                'Malformed Elementor export ID returned an unexpected error.');
        }
        $assert(count($GLOBALS['wpconnector_export_nonce_actions']) === $beforeNonces,
            'Malformed Elementor export ID reached nonce validation.');
    }
    foreach (array(10, '10', '00010') as $validId) {
        $_GET = array('post_id' => $validId);
        try {
            $export->download();
            $assert(false, 'Valid Elementor export target skipped nonce validation.');
        } catch (\LogicException $error) {
            $assert('Export nonce boundary reached.' === $error->getMessage(),
                'Valid Elementor export ID returned an unexpected error.');
        }
        $assert('wpconnector_export_elementor_json_10' === end($GLOBALS['wpconnector_export_nonce_actions']),
            'Valid Elementor export target changed its nonce action or resolved ID.');
    }
    $_GET = $previousGet;

    echo "elementor JSON export runtime contract OK\n";
}
