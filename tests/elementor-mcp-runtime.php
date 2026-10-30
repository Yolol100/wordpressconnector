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

    $structure = $adapter->readAbility(
        array('name' => 'elementor/get-page-structure', 'input' => array('post_id' => $postId)),
        array()
    );
    if (! isset($structure['result']['elements']) || ! is_array($structure['result']['elements'])) {
        throw new RuntimeException('Native Elementor page-structure readback failed after create-page.');
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

// Exercise the Connector's real Elementor document API, persisted readback,
// patch and rollback. This reproduces the previously false negative in
// get_elements_data() on installed Elementor, not with a mocked provider.
$documentAdapter = new \Webactueel\WordPressConnector\Adapters\ElementorAdapter();
$documentPostId = 0;
$documentData = array(array(
    'id' => 'aabbcc01',
    'elType' => 'container',
    'settings' => array(),
    'elements' => array(array(
        'id' => 'aabbcc02',
        'elType' => 'widget',
        'widgetType' => 'heading',
        'settings' => array('title' => 'Runtime before'),
        'elements' => array(),
    )),
));
// Diagnose Elementor's installed save() normalization using disposable dummy data.
$probeId = wp_insert_post(array('post_type' => 'page', 'post_status' => 'draft',
    'post_title' => 'Connector Elementor persistence probe'), true);
if (is_wp_error($probeId)) {
    throw new RuntimeException('Could not create disposable Elementor persistence probe.');
}
try {
    update_post_meta((int) $probeId, '_elementor_edit_mode', 'builder');
    $probeDocument = \Elementor\Plugin::$instance->documents->get((int) $probeId);
    $providerNormalized = null;
    $listener = static function ($savedId, $elements) use ($probeId, &$providerNormalized): void {
        if ((int) $savedId === (int) $probeId && is_array($elements)) {
            $providerNormalized = $elements;
        }
    };
    add_action('elementor/editor/after_save', $listener, 10, 2);
    try {
        $probeSave = $probeDocument->save(array('elements' => $documentData, 'settings' => array()));
    } finally {
        remove_action('elementor/editor/after_save', $listener, 10);
    }
    $probeRaw = get_post_meta((int) $probeId, '_elementor_data', true);
    $probeDecoded = is_string($probeRaw) ? json_decode($probeRaw, true) : $probeRaw;
    if (! is_array($providerNormalized)
        || \Webactueel\WordPressConnector\Support\Fingerprint::make($providerNormalized)
            !== \Webactueel\WordPressConnector\Support\Fingerprint::make($probeDecoded)) {
        throw new RuntimeException('Provider-normalized Elementor persistence differs from database: expected='
            . wp_json_encode($providerNormalized) . ' actual=' . wp_json_encode($probeDecoded)
            . ' save_result_type=' . gettype($probeSave));
    }
} finally {
    wp_delete_post((int) $probeId, true);
}

try {
    $created = $documentAdapter->createDocument(array(
        'post_type' => 'page',
        'title' => 'Connector Elementor persisted readback runtime',
        'slug' => 'connector-elementor-readback-fixture',
        'status' => 'draft',
        'data' => $documentData,
        'page_settings' => array(),
    ), array('dry_run' => false));
    $documentPostId = (int) ($created['document']['post_id'] ?? 0);
    if ($documentPostId < 1) {
        throw new RuntimeException('Elementor document creation did not produce a post ID.');
    }

    $before = $documentAdapter->inspect(array('id' => $documentPostId));
    if (! isset($before['document']['data'][0]['elements'][0]['settings']['title'])
        || 'Runtime before' !== $before['document']['data'][0]['elements'][0]['settings']['title']) {
        throw new RuntimeException('Persisted Elementor initial element mismatch.');
    }

    // Verify the new bulk matcher against a real draft Elementor page:
    // a copied filename resolves correctly, duplicate and mismatched targets fail closed.
    $method = new \ReflectionMethod(
        \Webactueel\WordPressConnector\Admin\ElementorJsonImport::class,
        'buildBulkPlan'
    );
    $method->setAccessible(true);
    $importer = new \Webactueel\WordPressConnector\Admin\ElementorJsonImport();
    $pagePost = get_post($documentPostId);
    $entry = array(
        'name' => (string) $pagePost->post_name . '-elementor-1.json',
        'payload' => array('type' => 'wp-page', 'content' => $documentData),
    );
    $matchPlan = $method->invoke($importer, array($entry));
    if (1 !== count($matchPlan) || 'ready' !== ($matchPlan[0]['status'] ?? '')
        || $documentPostId !== ($matchPlan[0]['post_id'] ?? null)) {
        throw new RuntimeException('Elementor bulk import failed exact draft page and copy-suffix matching.');
    }
    foreach (array(
        (string) $pagePost->post_name . '-elementor-SEO-HERZIEN.json',
        (string) $pagePost->post_name . '-elementor-LANG-MIN125.json',
        (string) $pagePost->post_name . '-elementor-LANG-MIN125 (3).json',
    ) as $exportFilename) {
        $variantEntry = $entry;
        $variantEntry['name'] = $exportFilename;
        $variantPlan = $method->invoke($importer, array($variantEntry));
        if (1 !== count($variantPlan) || 'ready' !== ($variantPlan[0]['status'] ?? '')
            || $documentPostId !== ($variantPlan[0]['post_id'] ?? null)) {
            throw new RuntimeException('Elementor bulk import did not match a known export suffix: ' . $exportFilename);
        }
    }
    $unknownEntry = $entry;
    $unknownEntry['name'] = (string) $pagePost->post_name . '-elementor-unrecognized-suffix.json';
    $unknownPlan = $method->invoke($importer, array($unknownEntry));
    if ('ready' === ($unknownPlan[0]['status'] ?? '')) {
        throw new RuntimeException('Elementor bulk import guessed an unknown filename suffix.');
    }

    $duplicatePlan = $method->invoke($importer, array($entry, $entry));
    if ('conflict: repeated target' !== ($duplicatePlan[0]['status'] ?? '')
        || 'conflict: repeated target' !== ($duplicatePlan[1]['status'] ?? '')) {
        throw new RuntimeException('Elementor bulk import accepted duplicate target mapping.');
    }
    $invalidEntry = $entry;
    $invalidEntry['payload']['source'] = array('post_type' => 'post');
    $mismatchPlan = $method->invoke($importer, array($invalidEntry));
    if ('conflict: source post type does not match Elementor document type' !== ($mismatchPlan[0]['status'] ?? '')) {
        throw new RuntimeException('Elementor bulk import accepted wrong source type.');
    }

    // Existing-page Elementor JSON import must tolerate provider-added fields
    // such as container isInner=false without falsely triggering rollback.
    $replacementData = $documentData;
    $replacementData[0]['elements'][0]['settings']['title'] = 'Runtime imported';
    $documentAdapter->replaceDocument(array(
        'id' => $documentPostId,
        'data' => $replacementData,
        'page_settings' => array(),
        'edit_mode' => 'builder',
    ), array('dry_run' => false));
    $imported = $documentAdapter->inspect(array('id' => $documentPostId));
    if ('Runtime imported' !== ($imported['document']['data'][0]['elements'][0]['settings']['title'] ?? null)) {
        throw new RuntimeException('Existing Elementor JSON import did not persist normalized content.');
    }

    // Elementor stores the page layout in _wp_page_template, not in regular
    // document settings. This was rejected by Connector 1.20.1.
    $documentAdapter->replaceDocument(array(
        'id' => $documentPostId,
        'data' => $replacementData,
        'page_settings' => array('template' => 'elementor_canvas'),
        'edit_mode' => 'builder',
    ), array('dry_run' => false));
    if ('elementor_canvas' !== (string) get_post_meta($documentPostId, '_wp_page_template', true)) {
        throw new RuntimeException('Elementor template import was not saved in WordPress meta.');
    }

    $patch = $documentAdapter->patchElement(array(
        'id' => $documentPostId,
        'element_id' => 'aabbcc02',
        'settings' => array('title' => 'Runtime after'),
    ), array('dry_run' => false));
    if ('Runtime after' !== ($patch['after_element']['settings']['title'] ?? null)) {
        throw new RuntimeException('Elementor document patch readback failed.');
    }
    $changed = $documentAdapter->inspect(array('id' => $documentPostId));
    if ('Runtime after' !== ($changed['document']['data'][0]['elements'][0]['settings']['title'] ?? null)) {
        throw new RuntimeException('Elementor change was not persisted to the post.');
    }
    $previousDocument = $before['document'];
    $documentAdapter->replaceDocument(array(
        'id' => $documentPostId,
        'data' => $previousDocument['data'],
        'page_settings' => $previousDocument['page_settings'],
        'edit_mode' => $previousDocument['edit_mode'],
        'template_type' => $previousDocument['template_type'],
        'conditions' => $previousDocument['conditions'],
    ), array('dry_run' => false));
    $restored = $documentAdapter->inspect(array('id' => $documentPostId));
    if ('default' !== ($restored['document']['page_settings']['template'] ?? null)) {
        throw new RuntimeException('Elementor rollback did not restore the original page template.');
    }
    if ('Runtime before' !== ($restored['document']['data'][0]['elements'][0]['settings']['title'] ?? null)) {
        throw new RuntimeException('Elementor document rollback did not persist original content.');
    }
} finally {
    if ($documentPostId > 0) {
        wp_delete_post($documentPostId, true);
    }
}

// Exercise media.rename on a disposable WordPress image attachment. This
// verifies actual metadata and compatibility files, not only source markers.
$uploads = wp_upload_dir();
if (! empty($uploads['error']) || ! is_dir($uploads['path'])) {
    throw new RuntimeException('Disposable media rename test cannot access WordPress uploads.');
}
$mediaPrefix = 'wpconnector-runtime-' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
$oldMediaPath = trailingslashit($uploads['path']) . $mediaPrefix . '-before.png';
$newMediaPath = trailingslashit($uploads['path']) . $mediaPrefix . '-after.png';
$mediaPostId = 0;
$featurePostId = 0;
try {
    $tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMBAAD2hGLhAAAAAElFTkSuQmCC', true);
    if (! is_string($tinyPng) || false === file_put_contents($oldMediaPath, $tinyPng)) {
        throw new RuntimeException('Could not write isolated image fixture.');
    }
    $mediaPostId = wp_insert_attachment(array(
        'post_title' => 'Connector media rename runtime probe',
        'post_mime_type' => 'image/png',
        'post_status' => 'inherit',
    ), $oldMediaPath, 0, true);
    if (is_wp_error($mediaPostId) || $mediaPostId < 1) {
        throw new RuntimeException('Could not create isolated media attachment.');
    }
    update_attached_file((int) $mediaPostId, $oldMediaPath);
    $relativePath = ltrim($uploads['subdir'] . '/' . basename($oldMediaPath), '/');
    wp_update_attachment_metadata((int) $mediaPostId, array(
        'file' => $relativePath, 'width' => 1, 'height' => 1, 'sizes' => array(),
    ));
    $mediaAdapter = new \Webactueel\WordPressConnector\Adapters\MediaAdapter();
    $mediaPayload = array('id' => (int) $mediaPostId, 'filename' => basename($newMediaPath));
    $mediaDryRun = $mediaAdapter->mediaRename($mediaPayload, array('dry_run' => true));
    if (! isset($mediaDryRun['would_rename']) || file_exists($newMediaPath)) {
        throw new RuntimeException('Media rename dry-run unexpectedly changed the uploads folder.');
    }
    $mediaResult = $mediaAdapter->mediaRename($mediaPayload, array('dry_run' => false));
    if (empty($mediaResult['old_urls_preserved']) || ! file_exists($oldMediaPath)
        || ! file_exists($newMediaPath)
        || realpath((string) get_attached_file((int) $mediaPostId)) !== realpath($newMediaPath)
        || basename((string) (wp_get_attachment_metadata((int) $mediaPostId)['file'] ?? '')) !== basename($newMediaPath)) {
        throw new RuntimeException('Media rename did not persist while retaining the original filename.');
    }

    $featurePostId = wp_insert_post(array(
        'post_type' => 'post',
        'post_status' => 'draft',
        'post_title' => 'Connector featured image rollback runtime probe',
    ), true);
    if (is_wp_error($featurePostId) || $featurePostId <= 0) {
        throw new RuntimeException('Could not create disposable featured-image target.');
    }
    $assignPayload = array(
        'id' => (int) $mediaPostId,
        'target_type' => 'featured_image',
        'target_id' => (int) $featurePostId,
    );
    $assigned = $mediaAdapter->mediaAssign($assignPayload, array('dry_run' => false));
    if ((int) get_post_thumbnail_id((int) $featurePostId) !== (int) $mediaPostId
        || (int) ($assigned['before'] ?? -1) !== 0
        || (int) ($assigned['_rollback']['payload']['id'] ?? -1) !== 0) {
        throw new RuntimeException('Featured-image assignment did not produce a valid empty-state rollback.');
    }
    $noChange = $mediaAdapter->mediaAssign($assignPayload, array('dry_run' => false));
    if (true !== ($noChange['unchanged'] ?? false)) {
        throw new RuntimeException('An already-assigned featured image was not treated as unchanged.');
    }
    $mediaAdapter->mediaAssign($assigned['_rollback']['payload'], array(
        'rollback_mode' => true, 'dry_run' => false,
    ));
    if (0 !== (int) get_post_thumbnail_id((int) $featurePostId)) {
        throw new RuntimeException('Featured-image rollback to an empty original state failed.');
    }

    $zeroBlocked = false;
    try {
        $mediaAdapter->mediaAssign($assigned['_rollback']['payload'], array('dry_run' => true));
    } catch (RuntimeException $expected) {
        $zeroBlocked = true;
    }
    if (! $zeroBlocked) {
        throw new RuntimeException('Non-rollback media assignment improperly accepted a zero attachment ID.');
    }
    $originalUser = get_current_user_id();
    try {
        wp_set_current_user(0);
        $permissionDenied = false;
        try {
            $mediaAdapter->mediaAssign($assignPayload, array('dry_run' => true));
        } catch (RuntimeException $expected) {
            $permissionDenied = true;
        }
        if (! $permissionDenied) {
            throw new RuntimeException('Unauthenticated media assignment was not blocked.');
        }
    } finally {
        wp_set_current_user($originalUser);
    }
} finally {
    if (is_int($featurePostId) && $featurePostId > 0) {
        wp_delete_post($featurePostId, true);
    }
    if (is_int($mediaPostId) && $mediaPostId > 0) {
        wp_delete_attachment($mediaPostId, true);
    }
    if (is_file($oldMediaPath)) {
        unlink($oldMediaPath);
    }
    if (is_file($newMediaPath)) {
        unlink($newMediaPath);
    }
}

// Verify the installed Elementor provider actually finishes the guarded CSS
// regeneration. Contract-string tests alone cannot prove this runtime hook.
$cache = new \Webactueel\WordPressConnector\Adapters\CacheMaintenanceAdapter();
$preview = $cache->flush(array('layer' => 'elementor', 'regenerate' => true), array('dry_run' => true));
if (true !== ($preview['would_flush'] ?? null)
    || true === ($preview['regenerate_invoked'] ?? null)
    || null !== ($preview['regeneration_verified'] ?? null)) {
    throw new RuntimeException('Elementor cache regeneration preflight unexpectedly mutated provider state.');
}
$regenerated = $cache->flush(array('layer' => 'elementor', 'regenerate' => true), array('dry_run' => false));
if (true !== ($regenerated['readback_verified'] ?? null)
    || true !== ($regenerated['regenerate_invoked'] ?? null)
    || true !== ($regenerated['regeneration_verified'] ?? null)) {
    throw new RuntimeException('Elementor did not signal completed CSS regeneration.');
}

echo 'Elementor MCP and CSS regeneration runtime OK (Elementor ' . ELEMENTOR_VERSION . ', WordPress ' . get_bloginfo('version') . ").\n";
