<?php

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__);
    require_once $root . '/plugin/wordpressconnector/includes/Support/Json.php';
    require_once $root . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
    require_once $root . '/plugin/wordpressconnector/includes/Security/Policy.php';
    require_once $root . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
    require_once $root . '/plugin/wordpressconnector/includes/Adapters/SiteEditorAdapter.php';

    $GLOBALS['site_editor_test'] = array(
        'admin' => true, 'post' => true, 'rest_calls' => 0,
        'allow_rest' => true, 'normalize_write' => false, 'failure_post' => false,
        'record' => array('id' => 'testtheme//header', 'theme' => 'testtheme',
            'wp_id' => 44, 'slug' => 'header', 'title' => array('raw' => 'Header'),
            'source' => 'custom', 'modified' => '2026-10-18T10:00:00',
            'content' => array('raw' => '<!-- wp:paragraph --><p>Original</p><!-- /wp:paragraph -->')),
    );

    class WP_REST_Request
    {
        private string $method;
        private string $route;
        private array $params = array();
        public function __construct(string $method, string $route)
        {
            $this->method = $method;
            $this->route = $route;
        }
        public function get_method(): string { return $this->method; }
        public function get_route(): string { return $this->route; }
        public function set_param(string $key, $value): void { $this->params[$key] = $value; }
        public function get_param(string $key) { return $this->params[$key] ?? null; }
    }

    class WP_REST_Response
    {
        private $data;
        private int $status;
        public function __construct($data, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }
        public function get_data() { return $this->data; }
        public function get_status(): int { return $this->status; }
    }

    function get_stylesheet(): string { return 'testtheme'; }
    function wp_json_encode($value) { return json_encode($value); }
    function wp_strip_all_tags($value): string { return strip_tags((string) $value); }
    function current_user_can(string $capability, $id = null): bool
    {
        if ('edit_post' === $capability) {
            return $GLOBALS['site_editor_test']['post'] && $id === 44;
        }
        return $GLOBALS['site_editor_test']['admin'] && 'edit_theme_options' === $capability;
    }
    function rest_do_request(WP_REST_Request $request): WP_REST_Response
    {
        ++$GLOBALS['site_editor_test']['rest_calls'];
        if (! $GLOBALS['site_editor_test']['allow_rest']) {
            return new WP_REST_Response(array('code' => 'rest_forbidden'), 403);
        }
        $path = $request->get_route();
        if ($path === '/wp/v2/global-styles/themes/testtheme') {
            return new WP_REST_Response(array('settings' => array('color' => array()),
                'styles' => array('typography' => array('fontSize' => '16px'))));
        }
        if ($path === '/wp/v2/templates' || $path === '/wp/v2/template-parts') {
            return new WP_REST_Response(array($GLOBALS['site_editor_test']['record']));
        }
        if (preg_match('#^/wp/v2/(templates|template-parts)/testtheme//header\z#D', $path) !== 1) {
            return new WP_REST_Response(array('code' => 'rest_no_route'), 404);
        }
        if ('POST' === $request->get_method()) {
            if ($GLOBALS['site_editor_test']['failure_post']) {
                return new WP_REST_Response(array('code' => 'rest_forbidden'), 403);
            }
            $new = $request->get_param('content');
            $GLOBALS['site_editor_test']['record']['content']['raw'] =
                $GLOBALS['site_editor_test']['normalize_write'] ? (string) $new . ' trimmed' : $new;
            $GLOBALS['site_editor_test']['record']['modified'] = '2026-10-18T11:00:00';
        }
        return new WP_REST_Response($GLOBALS['site_editor_test']['record']);
    }

    $adapter = new \Webactueel\WordPressConnector\Adapters\SiteEditorAdapter();
    $registry = new \Webactueel\WordPressConnector\Runtime\Registry();
    $adapter->register($registry);

    $assert = static function (bool $condition, string $message): void {
        if (! $condition) throw new \RuntimeException($message);
    };
    $fails = static function (callable $operation, string $message): void {
        try { $operation(); } catch (\RuntimeException $error) { return; }
        throw new \RuntimeException('Expected failure: ' . $message);
    };
    foreach (array('site_editor.template.list', 'site_editor.template.inspect',
        'site_editor.template.update', 'site_editor.template.restore',
        'site_editor.global_styles.inspect') as $name) {
        $desc = $registry->descriptor($name);
        $assert($desc['privileged'] && $desc['capability'] === 'edit_theme_options'
            && ! $desc['public_repository_safe'], 'Site Editor must stay private and privileged: ' . $name);
    }
    $assert($registry->descriptor('site_editor.template.update')['mutation']
        && $registry->descriptor('site_editor.template.restore')['mutation'],
        'Site Editor mutations must be classified as writes.');

    $items = $adapter->listTemplates(array('kind' => 'template_part'));
    $assert($items['count'] === 1 && $items['items'][0]['customized'], 'Custom template inventory failed.');
    $styles = $adapter->globalStyles();
    $assert(($styles['styles']['typography']['fontSize'] ?? '') === '16px',
        'Global styles read did not use the native Core API.');

    $payload = array('kind' => 'template_part', 'id' => 'testtheme//header');
    $before = $adapter->inspect($payload);
    $assert(strlen($before['fingerprint']) === 64
        && $before['content_sha256'] === hash('sha256', $before['content']),
        'Template inspect fingerprint failed.');
    $newContent = '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->';
    $request = $payload + array('content' => $newContent,
        'expected_before_fingerprint' => $before['fingerprint']);
    $calls = $GLOBALS['site_editor_test']['rest_calls'];
    $preview = $adapter->update($request, array('dry_run' => true));
    $assert($preview['would_change'] && $GLOBALS['site_editor_test']['rest_calls'] > $calls
        && $GLOBALS['site_editor_test']['record']['content']['raw'] === $before['content'],
        'Dry run must not mutate Site Editor content.');
    $fails(static function () use ($adapter, $request): void {
        $adapter->update($request, array('dry_run' => false));
    }, 'server opt-in missing');
    $assert($GLOBALS['site_editor_test']['record']['content']['raw'] === $before['content'],
        'No site mutation is permitted without opt-in.');

    define('WPCONNECTOR_ALLOW_SITE_EDITOR_WRITES', true);
    $fails(static function () use ($adapter, $request): void {
        $changed = $request;
        $changed['expected_before_fingerprint'] = str_repeat('a', 64);
        $adapter->update($changed, array('dry_run' => false));
    }, 'stale fingerprint');
    $GLOBALS['site_editor_test']['post'] = false;
    $fails(static function () use ($adapter, $request): void {
        $adapter->update($request, array('dry_run' => false));
    }, 'WordPress edit_post missing');
    $GLOBALS['site_editor_test']['post'] = true;

    foreach (array(
        array('kind' => 'template', 'id' => 'othertheme//header'),
        array('kind' => 'template_part', 'id' => 'testtheme//../wp-config'),
        array('kind' => 'template_part', 'id' => 'testtheme//header', 'unexpected' => true),
    ) as $invalid) {
        $fails(static function () use ($adapter, $invalid): void {
            $adapter->inspect($invalid);
        }, 'invalid target or extra arguments');
    }
    $fails(static function () use ($adapter, $request): void {
        $r = $request; $r['content'] = '<script>alert(1)</script>';
        $adapter->update($r, array('dry_run' => false));
    }, 'arbitrary script');
    $fails(static function () use ($adapter, $request): void {
        $r = $request; $r['content'] = str_repeat('x', 65537);
        $adapter->update($r, array('dry_run' => false));
    }, 'oversize content');

    $GLOBALS['site_editor_test']['allow_rest'] = false;
    $fails(static function () use ($adapter, $payload): void {
        $adapter->inspect($payload);
    }, 'native WordPress REST permissions');
    $GLOBALS['site_editor_test']['allow_rest'] = true;

    $GLOBALS['site_editor_test']['record']['source'] = 'theme';
    $fails(static function () use ($adapter, $request): void {
        $adapter->update($request, array('dry_run' => false));
    }, 'unmodified theme file must not be overwritten');
    $GLOBALS['site_editor_test']['record']['source'] = 'custom';

    $result = $adapter->update($request, array('dry_run' => false));
    $assert($result['updated'] === true
        && $GLOBALS['site_editor_test']['record']['content']['raw'] === $newContent
        && $result['_rollback']['action'] === 'site_editor.template.restore',
        'Site Editor confirmed mutation failed.');
    $rollback = $result['_rollback']['payload'];
    $fails(static function () use ($adapter, $rollback): void {
        $adapter->restore($rollback, array('rollback_mode' => false));
    }, 'direct rollback');
    $restored = $adapter->restore($rollback, array('rollback_mode' => true));
    $assert($restored['readback_verified']
        && $GLOBALS['site_editor_test']['record']['content']['raw'] === $before['content'],
        'Site Editor explicit rollback did not restore exact previous bytes.');

    // A provider that silently alters saved content must trigger recovery.
    $before = $adapter->inspect($payload);
    $changed = $payload + array('content' => $newContent,
        'expected_before_fingerprint' => $before['fingerprint']);
    $GLOBALS['site_editor_test']['normalize_write'] = true;
    $fails(static function () use ($adapter, $changed): void {
        $adapter->update($changed, array('dry_run' => false));
    }, 'mismatched provider readback');
    $GLOBALS['site_editor_test']['normalize_write'] = false;
    // The test double alters rollback too; this must be reported as failure,
    // rather than claiming a rollback that was not byte-verified.
    $assert($GLOBALS['site_editor_test']['record']['content']['raw'] !== $before['content'],
        'Fixture must exercise a detectable failed restoration.');

    echo "Site Editor Core REST, permission, opt-in, dry-run, rollback contract OK\n";
}
