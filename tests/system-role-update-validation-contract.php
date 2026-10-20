<?php

declare(strict_types=1);

// Reproduce invalid-later-grant partial writes using the actual SystemAdapter.
class MockRole {
    public array $capabilities = array('read' => true);
    public function add_cap(string $cap, bool $grant = true): void { $this->capabilities[$cap] = $grant; }
    public function remove_cap(string $cap): void { unset($this->capabilities[$cap]); }
}
$GLOBALS['fixture_role'] = new MockRole();
function get_role(string $name) { return 'editor' === $name ? $GLOBALS['fixture_role'] : false; }
function is_multisite(): bool { return false; }
function current_user_can(string $cap): bool { return true; }
function sanitize_key(string $value): string { return (string) preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
$root = dirname(__DIR__) . '/plugin/wordpressconnector/includes';
require_once $root . '/Support/Json.php';
require_once $root . '/Support/Fingerprint.php';
require_once $root . '/Support/Input.php';
require_once $root . '/Adapters/SystemAdapter.php';
$a = new \Webactueel\WordPressConnector\Adapters\SystemAdapter();
$deny = static function (callable $f): void {
    try { $f(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Expected invalid capability input to be rejected.');
};
$deny(static function() use ($a): void {
    $a->roleUpdate(array('role'=>'editor','add'=>array('edit_posts'=>true,'manage_options'=>'yes')),array('dry_run'=>false));
});
if ($GLOBALS['fixture_role']->capabilities !== array('read'=>true)) throw new RuntimeException('An invalid grant mutated a capability before rejection.');
$deny(static function() use ($a): void {
    $a->roleUpdate(array('role'=>'editor','remove'=>array('delete users')),array('dry_run'=>false));
});
if ($GLOBALS['fixture_role']->capabilities !== array('read'=>true)) throw new RuntimeException('Invalid capability name mutated a role.');
$deny(static function() use ($a): void {
    $a->roleUpdate(array('role'=>'editor','add'=>'read'),array('dry_run'=>true));
});
$valid=$a->roleUpdate(array('role'=>'editor','add'=>array('edit_posts'=>true), 'remove'=>array('read')),array('dry_run'=>true));
if ($valid['before']!==array('read'=>true)) throw new RuntimeException('Valid dry-run mismatch');
if ($GLOBALS['fixture_role']->capabilities !== array('read'=>true)) throw new RuntimeException('Dry-run must not mutate.');
$actual=$a->roleUpdate(array('role'=>'editor','add'=>array('edit_posts'=>true), 'remove'=>array('read')),array('dry_run'=>false));
if ($actual['capabilities']!==array('edit_posts'=>true)) throw new RuntimeException('Valid role update mismatch');
echo "Role capability inputs validated before mutations; valid operations work\n";
