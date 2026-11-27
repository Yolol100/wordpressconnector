<?php

declare(strict_types=1);

class WP_Post
{
    public $ID;
    public $post_type;
    public $post_parent;
    public $post_status;
    public $post_password;

    public function __construct(int $id, string $status = 'publish', string $password = '', string $type = 'page')
    {
        $this->ID = $id;
        $this->post_type = $type;
        $this->post_parent = 0;
        $this->post_status = $status;
        $this->post_password = $password;
    }
}

$GLOBALS['wpconnector_elementor_target_posts'] = array(
    201 => new WP_Post(201, 'publish'),
    202 => new WP_Post(202, 'draft'),
    203 => new WP_Post(203, 'private'),
    204 => new WP_Post(204, 'publish', 'protected'),
    205 => new WP_Post(205, 'publish', '', 'internal_note'),
    206 => new WP_Post(206, 'inherit', '', 'attachment'),
);
$GLOBALS['wpconnector_elementor_target_can_edit'] = true;

function get_post($id)
{
    $GLOBALS['wpconnector_elementor_target_lookups'][] = $id;
    return $GLOBALS['wpconnector_elementor_target_posts'][(int) $id] ?? null;
}

function get_post_type_object($type)
{
    return (object) array('public' => 'internal_note' !== (string) $type);
}

function current_user_can($capability, ...$args): bool
{
    if ('edit_post' === (string) $capability) {
        return ! empty($GLOBALS['wpconnector_elementor_target_can_edit']);
    }
    return false;
}

$root = dirname(__DIR__);
require_once $root . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once $root . '/plugin/wordpressconnector/includes/Runtime/class-registry.php';

use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

$expectFailure = static function (callable $callback, string $label): void {
    try {
        $callback();
    } catch (RuntimeException $error) {
        return;
    }
    fwrite(STDERR, 'Expected guarded public Elementor target failure: ' . $label . ".\n");
    exit(1);
};

Policy::setPublicRepositoryContext(true);
$registry = new Registry();
$inspectCalls = 0;
$patchCalls = 0;
$registry->register('elementor.inspect', static function (array $payload) use (&$inspectCalls): array {
    $inspectCalls++;
    return array('post_id' => (int) $payload['id']);
});
$registry->register('elementor.patch_element', static function (array $payload) use (&$patchCalls): array {
    $patchCalls++;
    return array('post_id' => (int) $payload['id']);
}, array('mutation' => true));

$inspect = $registry->execute('elementor.inspect', array('id' => 201), array('dry_run' => true));
if (($inspect['post_id'] ?? 0) !== 201 || 1 !== $inspectCalls) {
    fwrite(STDERR, "Published public Elementor inspect did not reach its handler.\n");
    exit(1);
}
$patch = $registry->execute('elementor.patch_element', array('id' => 201, 'element_id' => 'abc123', 'settings' => array('title' => 'Safe')), array('dry_run' => true));
if (($patch['post_id'] ?? 0) !== 201 || 1 !== $patchCalls) {
    fwrite(STDERR, "Published editable Elementor patch did not reach its handler.\n");
    exit(1);
}

foreach (array(202 => 'draft', 203 => 'private', 204 => 'password-protected', 205 => 'non-public type', 206 => 'unpublished attachment') as $id => $label) {
    $beforeInspect = $inspectCalls;
    $expectFailure(static function () use ($registry, $id): void {
        $registry->execute('elementor.inspect', array('id' => $id), array('dry_run' => true));
    }, $label . ' inspect');
    if ($beforeInspect !== $inspectCalls) {
        fwrite(STDERR, 'Rejected ' . $label . " inspect reached its handler.\n");
        exit(1);
    }

    $beforePatch = $patchCalls;
    $expectFailure(static function () use ($registry, $id): void {
        $registry->execute('elementor.patch_element', array('id' => $id, 'element_id' => 'abc123', 'settings' => array('title' => 'No')), array('dry_run' => false));
    }, $label . ' patch');
    if ($beforePatch !== $patchCalls) {
        fwrite(STDERR, 'Rejected ' . $label . " patch reached its handler.\n");
        exit(1);
    }
}

$GLOBALS['wpconnector_elementor_target_can_edit'] = false;
$beforePatch = $patchCalls;
$expectFailure(static function () use ($registry): void {
    $registry->execute('elementor.patch_element', array('id' => 201, 'element_id' => 'abc123', 'settings' => array('title' => 'No')), array('dry_run' => false));
}, 'missing edit_post capability');
if ($beforePatch !== $patchCalls) {
    fwrite(STDERR, "Capability-rejected Elementor patch reached its handler.\n");
    exit(1);
}

$inspect = $registry->execute('elementor.inspect', array('id' => 201), array('dry_run' => true));
if (($inspect['post_id'] ?? 0) !== 201 || 2 !== $inspectCalls) {
    fwrite(STDERR, "Read-only Elementor inspect unexpectedly required edit capability.\n");
    exit(1);
}

Policy::setPublicRepositoryContext(false);
$privatePatch = $registry->execute('elementor.patch_element', array('id' => 202), array('dry_run' => true));
if (($privatePatch['post_id'] ?? 0) !== 202 || 2 !== $patchCalls) {
    fwrite(STDERR, "Public Elementor target guard leaked into non-public execution.\n");
    exit(1);
}


// Exercise the real Elementor adapter target resolver rather than a registry
// double. Malformed IDs must fail before WordPress lookup for reads, previews,
// or confirmed document changes.
require_once $root . '/plugin/wordpressconnector/includes/Support/Input.php';
require_once $root . '/plugin/wordpressconnector/includes/Adapters/class-elementoradapter.php';
$elementorAdapter = new \Webactueel\WordPressConnector\Adapters\ElementorAdapter();
$postResolver = new ReflectionMethod($elementorAdapter, 'post');
if (PHP_VERSION_ID < 80100) {
    $postResolver->setAccessible(true);
}
$invalidIds = array(true, false, 201.5, 0, '0', '0000', -201, '201junk',
    '201.5', '2.01e2', ' 201', "201\n", '999999999999999999999999', array(201), null);
foreach ($invalidIds as $invalidId) {
    $checks = array(
        'inspect' => static function () use ($elementorAdapter, $invalidId): void {
            $elementorAdapter->inspect(array('id' => $invalidId));
        },
        'replace preview' => static function () use ($elementorAdapter, $invalidId): void {
            $elementorAdapter->replaceDocument(array('id' => $invalidId), array('dry_run' => true));
        },
        'replace write' => static function () use ($elementorAdapter, $invalidId): void {
            $elementorAdapter->replaceDocument(array('id' => $invalidId), array('dry_run' => false));
        },
        'patch preview' => static function () use ($elementorAdapter, $invalidId): void {
            $elementorAdapter->patchElement(array('id' => $invalidId, 'element_id' => 'abc123'), array('dry_run' => true));
        },
        'patch write' => static function () use ($elementorAdapter, $invalidId): void {
            $elementorAdapter->patchElement(array('id' => $invalidId, 'element_id' => 'abc123'), array('dry_run' => false));
        },
    );
    foreach ($checks as $label => $check) {
        $lookupCount = count($GLOBALS['wpconnector_elementor_target_lookups'] ?? array());
        try {
            $check();
            throw new RuntimeException('Malformed Elementor ID accepted in ' . $label);
        } catch (RuntimeException $error) {
            if ('Post not found.' !== $error->getMessage()) {
                throw $error;
            }
        }
        if ($lookupCount !== count($GLOBALS['wpconnector_elementor_target_lookups'] ?? array())) {
            throw new RuntimeException('Malformed Elementor ID reached get_post() in ' . $label);
        }
    }
}
foreach (array(201, '201', '000201') as $validId) {
    $post = $postResolver->invoke($elementorAdapter, array('id' => $validId));
    if (! $post instanceof WP_Post || 201 !== $post->ID
        || 201 !== end($GLOBALS['wpconnector_elementor_target_lookups'])) {
        throw new RuntimeException('Valid Elementor document ID compatibility changed.');
    }
}
try {
    $postResolver->invoke($elementorAdapter, array('id' => 999));
    throw new RuntimeException('Nonexistent Elementor target accepted.');
} catch (RuntimeException $error) {
    if ('Post not found.' !== $error->getMessage()) {
        throw $error;
    }
}

echo "public Elementor target boundary contract OK\n";
