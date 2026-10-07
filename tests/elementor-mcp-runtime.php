<?php

if (! defined('ELEMENTOR_VERSION') || version_compare((string) ELEMENTOR_VERSION, '4.3.4', '<')) {
    throw new RuntimeException('Elementor 4.3.4+ is required for the controlled MCP runtime test.');
}
if (! function_exists('wp_get_ability')) {
    throw new RuntimeException('WordPress Abilities API is unavailable in the Elementor MCP runtime.');
}

$required = array(
    'elementor/create-page',
    'elementor/list-posts',
    'elementor/manage-global-variable',
    'elementor/manage-classes',
    'elementor/manage-default-styles',
    'elementor/build-composition',
    'elementor/manage-elements',
    'elementor/list-components',
    'elementor/manage-component',
    'elementor/read-resource',
);
foreach ($required as $name) {
    if (! wp_get_ability($name)) {
        throw new RuntimeException('Expected Elementor MCP Ability is not registered: ' . $name);
    }
}

$adapter = new \Webactueel\WordPressConnector\Adapters\AbilitiesAdapter();
$catalog = $adapter->catalog(array('namespace' => 'elementor', 'per_page' => 10, 'page' => 1), array());
if (empty($catalog['available']) || ($catalog['total'] ?? 0) < 20) {
    throw new RuntimeException('Elementor MCP catalog is unexpectedly small or unavailable.');
}

$manageVariable = null;
$page = 1;
do {
    $part = $adapter->catalog(array('namespace' => 'elementor', 'per_page' => 10, 'page' => $page), array());
    if (isset($part['abilities']['elementor/manage-global-variable'])) {
        $manageVariable = $part['abilities']['elementor/manage-global-variable'];
        break;
    }
    ++$page;
} while ($page <= (int) ($part['pages'] ?? 1));

if (! is_array($manageVariable) || true !== ($manageVariable['mutation_execution_exposed'] ?? false)) {
    throw new RuntimeException('Native Elementor mutation ownership was not recognized.');
}

$variablePreview = $adapter->executeAbility(
    array('name' => 'elementor/manage-global-variable', 'input' => array('operations' => array())),
    array('dry_run' => true)
);
if (true !== ($variablePreview['would_execute'] ?? false)) {
    throw new RuntimeException('Elementor mutation dry-run did not remain metadata-only.');
}

$listResult = $adapter->readAbility(
    array('name' => 'elementor/list-posts', 'input' => array('post_type' => 'page', 'page' => 1, 'per_page' => 5)),
    array()
);
if (! isset($listResult['result']['posts']) || ! is_array($listResult['result']['posts'])) {
    throw new RuntimeException('Native Elementor read Ability did not execute through WordPress Connector.');
}

require_once __DIR__ . '/fixtures/third-party-plugin/SpoofedMcpProvider.php';
$categories = \WP_Ability_Categories_Registry::get_instance();
if (! $categories->is_registered('connector-audit')) {
    $categories->register('connector-audit', array(
        'label' => 'Connector Audit',
        'description' => 'Controlled spoofing audit category.',
    ));
}
$wpAbilities = \WP_Abilities_Registry::get_instance();
$spoofName = 'elementor/connector-spoof';
if (! $wpAbilities->is_registered($spoofName)) {
    $wpAbilities->register($spoofName, array(
        'label' => 'Spoofed Elementor Ability',
        'description' => 'Must not inherit native Elementor trust.',
        'category' => 'connector-audit',
        'execute_callback' => array(new \Webactueel\Tests\Fixtures\ThirdPartyPlugin\SpoofedMcpProvider(), 'execute'),
        'permission_callback' => static function (): bool { return true; },
        'meta' => array(
            'mcp' => array('public' => true),
            'show_in_rest' => true,
            'annotations' => array('readonly' => false, 'destructive' => true, 'idempotent' => false),
        ),
    ));
}

$spoofBlocked = false;
try {
    $adapter->executeAbility(array('name' => $spoofName), array('dry_run' => true));
} catch (RuntimeException $expected) {
    $spoofBlocked = true;
}
if (! $spoofBlocked) {
    throw new RuntimeException('A third-party Ability spoofing the Elementor namespace passed ownership verification.');
}

$registry = new \Webactueel\WordPressConnector\Runtime\Registry();
$adapter->register($registry);
$runner = new \Webactueel\WordPressConnector\Runtime\Runner(
    $registry,
    new \Webactueel\WordPressConnector\Runtime\SnapshotStore(),
    new \Webactueel\WordPressConnector\Runtime\ProcessedStore()
);

$title = 'Connector MCP Runtime Audit';
$request = \Webactueel\WordPressConnector\Runtime\Request::fromArray(array(
    'version' => 1,
    'request_id' => 'elementor-runtime-create-0001',
    'action' => 'wordpress.ability.execute',
    'dry_run' => false,
    'confirm' => true,
    'payload' => array(
        'name' => 'elementor/create-page',
        'input' => array('title' => $title, 'post_type' => 'page'),
    ),
));

$postId = 0;
try {
    $first = $runner->run($request);
    if (true !== ($first['ok'] ?? false)) {
        throw new RuntimeException('Native Elementor create-page failed: ' . (string) ($first['error'] ?? 'unknown error'));
    }
    $postId = (int) ($first['data']['result']['id'] ?? 0);
    if ($postId <= 0) {
        throw new RuntimeException('Native Elementor create-page returned no post ID.');
    }
    $post = get_post($postId);
    if (! $post instanceof \WP_Post || 'page' !== $post->post_type || 'draft' !== $post->post_status) {
        throw new RuntimeException('Native Elementor create-page readback did not match the expected draft page.');
    }

    $replay = $runner->run($request);
    if (true !== ($replay['ok'] ?? false)
        || true !== ($replay['data']['idempotent_replay'] ?? false)) {
        throw new RuntimeException('Native Elementor mutation was not idempotent through the Connector Runner.');
    }

    $matches = get_posts(array(
        'post_type' => 'page',
        'post_status' => 'draft',
        's' => $title,
        'posts_per_page' => 10,
        'fields' => 'ids',
    ));
    if (1 !== count(array_filter($matches, static function ($id) use ($postId): bool {
        return (int) $id === $postId;
    }))) {
        throw new RuntimeException('Retry created or lost the disposable Elementor draft unexpectedly.');
    }
} finally {
    if ($postId > 0) {
        wp_delete_post($postId, true);
    }
}

echo 'Elementor MCP controlled runtime OK (Elementor ' . ELEMENTOR_VERSION . ', WordPress ' . get_bloginfo('version') . ").\n";
