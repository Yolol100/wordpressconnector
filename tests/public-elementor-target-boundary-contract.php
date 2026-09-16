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
);
$GLOBALS['wpconnector_elementor_target_can_edit'] = true;

function get_post($id)
{
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
require_once $root . '/plugin/wordpressconnector/includes/Runtime/Registry.php';

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

foreach (array(202 => 'draft', 203 => 'private', 204 => 'password-protected', 205 => 'non-public type') as $id => $label) {
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

echo "public Elementor target boundary contract OK\n";
