<?php

declare(strict_types=1);

class WP_Post
{
    public $ID;
    public $post_type;
    public $post_parent;
    public $post_status;
    public $post_password;

    public function __construct(int $id, string $status = 'publish', string $password = '', string $type = 'post')
    {
        $this->ID = $id;
        $this->post_type = $type;
        $this->post_parent = 0;
        $this->post_status = $status;
        $this->post_password = $password;
    }
}

$GLOBALS['wpconnector_public_target_posts'] = array(
    101 => new WP_Post(101, 'publish'),
    102 => new WP_Post(102, 'draft'),
    103 => new WP_Post(103, 'private'),
    104 => new WP_Post(104, 'publish', 'protected'),
    105 => new WP_Post(105, 'publish', '', 'internal_note'),
);
$GLOBALS['wpconnector_public_target_can_edit'] = true;

function get_post($id)
{
    return $GLOBALS['wpconnector_public_target_posts'][(int) $id] ?? null;
}

function get_post_type_object($type)
{
    return (object) array('public' => 'internal_note' !== (string) $type);
}

function current_user_can($capability, ...$args): bool
{
    if ('edit_post' === (string) $capability) {
        return ! empty($GLOBALS['wpconnector_public_target_can_edit']);
    }
    return false;
}

$root = dirname(__DIR__);
require_once $root . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once $root . '/plugin/wordpressconnector/includes/Runtime/Registry.php';

use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Security\Policy;

$expectFailure = static function (callable $callback, string $label, string $needle = ''): void {
    try {
        $callback();
    } catch (RuntimeException $error) {
        if ('' !== $needle && false === strpos($error->getMessage(), $needle)) {
            fwrite(STDERR, 'Unexpected failure for ' . $label . ': ' . $error->getMessage() . "\n");
            exit(1);
        }
        return;
    }
    fwrite(STDERR, 'Expected guarded public post.update failure: ' . $label . ".\n");
    exit(1);
};

Policy::setPublicRepositoryContext(true);
$registry = new Registry();
$leafCalls = 0;
$registry->register('post.update', static function (array $payload, array $context) use (&$leafCalls): array {
    $leafCalls++;
    return array('id' => (int) $payload['id'], 'dry_run' => ! empty($context['dry_run']));
}, array('mutation' => true));
$registry->register('connector.batch', static function (array $payload, array $context) use ($registry): array {
    $results = array();
    foreach ((array) ($payload['operations'] ?? array()) as $operation) {
        $results[] = $registry->execute((string) $operation['action'], (array) $operation['payload'], $context);
    }
    return array('operations' => $results);
}, array('mutation' => true));

$result = $registry->execute('post.update', array('id' => 101, 'excerpt' => 'Safe update.'), array('dry_run' => true));
if (($result['id'] ?? 0) !== 101 || true !== ($result['dry_run'] ?? null) || 1 !== $leafCalls) {
    fwrite(STDERR, "Published editable public post.update did not reach the registered handler.\n");
    exit(1);
}

foreach (array(
    102 => 'draft target',
    103 => 'private target',
    104 => 'password-protected target',
    105 => 'non-public post type',
) as $id => $label) {
    $before = $leafCalls;
    $expectFailure(static function () use ($registry, $id): void {
        $registry->execute('post.update', array('id' => $id, 'status' => 'publish'), array('dry_run' => false));
    }, $label, 'public');
    if ($before !== $leafCalls) {
        fwrite(STDERR, 'Rejected ' . $label . " reached the mutation handler.\n");
        exit(1);
    }
}

$GLOBALS['wpconnector_public_target_can_edit'] = false;
$before = $leafCalls;
$expectFailure(static function () use ($registry): void {
    $registry->execute('post.update', array('id' => 101, 'excerpt' => 'No permission.'), array('dry_run' => false));
}, 'missing edit_post capability', 'permission');
if ($before !== $leafCalls) {
    fwrite(STDERR, "Capability-rejected post.update reached the mutation handler.\n");
    exit(1);
}
$GLOBALS['wpconnector_public_target_can_edit'] = true;

$before = $leafCalls;
$expectFailure(static function () use ($registry): void {
    $registry->execute('connector.batch', array('operations' => array(
        array('action' => 'post.update', 'payload' => array('id' => 102, 'status' => 'publish')),
    )), array('dry_run' => false));
}, 'draft post.update reached through connector.batch', 'public');
if ($before !== $leafCalls) {
    fwrite(STDERR, "Rejected connector.batch post.update leaf reached the mutation handler.\n");
    exit(1);
}

$batch = $registry->execute('connector.batch', array('operations' => array(
    array('action' => 'post.update', 'payload' => array('id' => 101, 'excerpt' => 'Batch safe update.')),
)), array('dry_run' => true));
if (($batch['operations'][0]['id'] ?? 0) !== 101 || 2 !== $leafCalls) {
    fwrite(STDERR, "Published editable connector.batch post.update leaf was not admitted.\n");
    exit(1);
}

Policy::setPublicRepositoryContext(false);
$result = $registry->execute('post.update', array('id' => 102, 'status' => 'publish'), array('dry_run' => true));
if (($result['id'] ?? 0) !== 102 || 3 !== $leafCalls) {
    fwrite(STDERR, "Public-target guard leaked into non-public connector execution.\n");
    exit(1);
}

echo "public post update target contract OK\n";
