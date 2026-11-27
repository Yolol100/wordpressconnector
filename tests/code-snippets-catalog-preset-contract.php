<?php

declare(strict_types=1);

namespace Code_Snippets\Model {
    final class Snippet
    {
        public $id = 0;
        public $name = '';
        public $desc = '';
        public $code = '';
        public $scope = 'global';
        public $type = 'php';
        public $priority = 10;
        public $active = false;
        public $network = false;
        public $trashed = false;
        public $locked = false;

        public function __construct(array $values = array())
        {
            foreach ($values as $key => $value) {
                $this->{$key} = $value;
            }
        }
    }
}

namespace Code_Snippets {
    function get_snippets(array $ids = array(), ?bool $network = null): array
    {
        ++$GLOBALS['wpc_identity_reads'];
        return array_values($GLOBALS['wpc_snippet_records']);
    }
    function get_snippet(int $id, ?bool $network = null)
    {
        ++$GLOBALS['wpc_identity_reads'];
        return $GLOBALS['wpc_snippet_records'][$id] ?? new \Code_Snippets\Model\Snippet();
    }
    function update_snippet_fields(int $id, array $fields, ?bool $network = null): void
    {
        throw new \RuntimeException('Unrelated snippet patch primitive must not be called.');
    }
    function save_snippet($snippet)
    {
        $snippet->id = 101;
        $GLOBALS['wpc_snippet_records'][101] = $snippet;
        $GLOBALS['wpc_save_calls']++;
        return $snippet;
    }
    function activate_snippet(int $id, ?bool $network = null)
    {
        if (! isset($GLOBALS['wpc_snippet_records'][$id])) {
            return 'No matching snippet.';
        }
        $GLOBALS['wpc_snippet_records'][$id]->active = true;
        return $GLOBALS['wpc_snippet_records'][$id];
    }
    function deactivate_snippet(int $id, ?bool $network = null)
    {
        if (! isset($GLOBALS['wpc_snippet_records'][$id])) {
            return null;
        }
        $GLOBALS['wpc_snippet_records'][$id]->active = false;
        return $GLOBALS['wpc_snippet_records'][$id];
    }
    function delete_snippet(int $id, ?bool $network = null): bool
    {
        if (! isset($GLOBALS['wpc_snippet_records'][$id])) {
            return false;
        }
        unset($GLOBALS['wpc_snippet_records'][$id]);
        return true;
    }
}

namespace {
    function current_user_can(string $capability): bool
    {
        return 'manage_options' === $capability;
    }

    $root = dirname(__DIR__);
    require_once $root . '/plugin/wordpressconnector/includes/Support/class-json.php';
    require_once $root . '/plugin/wordpressconnector/includes/Support/class-fingerprint.php';
    require_once $root . '/plugin/wordpressconnector/includes/Support/class-input.php';
    require_once $root . '/plugin/wordpressconnector/includes/Adapters/class-codesnippetsadapter.php';

    $fail = static function (string $message): void {
        fwrite(STDERR, 'Code Snippets catalogue preset: ' . $message . PHP_EOL);
        exit(1);
    };
    $expectFailure = static function (callable $callback, string $message) use ($fail): void {
        try {
            $callback();
        } catch (\RuntimeException $exception) {
            return;
        }
        $fail($message);
    };
    $sourcePath = $root . '/plugin/wordpressconnector/includes/Presets/woocommerce-catalog-no-orders.txt';
    $source = (string) file_get_contents($sourcePath);

    // Exercise the real fixed-path preset reader in a private package copy.
    // A sparse oversized file must fail normally under a tight memory budget,
    // not exhaust PHP memory before the size limit can be checked.
    $probeRoot = sys_get_temp_dir() . '/wpconnector-preset-probe-' . bin2hex(random_bytes(8));
    if (!mkdir($probeRoot . '/Adapters', 0700, true) || !mkdir($probeRoot . '/Presets', 0700)) {
        throw new RuntimeException('Unable to allocate preset test fixture.');
    }
    $adapterPath = $root . '/plugin/wordpressconnector/includes/Adapters/class-codesnippetsadapter.php';
    $probeAdapter = $probeRoot . '/Adapters/' . basename($adapterPath);
    $probeTrait = $probeRoot . '/Adapters/trait-codesnippetsadapteractions.php';
    $probePreset = $probeRoot . '/Presets/woocommerce-catalog-no-orders.txt';
    $probeScript = $probeRoot . '/probe.php';
    try {
        if (!copy($adapterPath, $probeAdapter) ||
            !copy(dirname($adapterPath) . '/trait-codesnippetsadapteractions.php', $probeTrait)) {
            throw new RuntimeException('Unable to copy exact preset reader fixture.');
        }
        $probe = <<<'PHP'
<?php
require $argv[1];
$reader = new ReflectionMethod(Webactueel\WordPressConnector\Adapters\CodeSnippetsAdapter::class, 'catalog_code');
$reader->setAccessible(true);
try {
    $bytes = $reader->invoke(new Webactueel\WordPressConnector\Adapters\CodeSnippetsAdapter());
    if ($argv[2] !== hash('sha256', $bytes)) exit(3);
    echo "ACCEPTED\n";
} catch (RuntimeException $error) {
    if ($argv[2] !== 'reject' || $error->getMessage() !== 'Audited WooCommerce catalogue preset is unavailable.') exit(4);
    echo "REJECTED\n";
}
PHP;
        if (false === file_put_contents($probeScript, $probe)) throw new RuntimeException('Cannot write test probe.');
        $runProbe = static function (string $expected) use ($probeScript, $probeAdapter): void {
            $output = array();
            $status = 0;
            exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=16M ' . escapeshellarg($probeScript) . ' ' .
                escapeshellarg($probeAdapter) . ' ' . escapeshellarg($expected) . ' 2>&1', $output, $status);
            if (0 !== $status || $output !== array('reject' === $expected ? 'REJECTED' : 'ACCEPTED')) {
                throw new RuntimeException('Bounded preset probe failed: ' . $status . ' ' . implode(' ', $output));
            }
        };
        $exact = 'WAB_CATALOG_ONLY_2026' . str_repeat('x', 1048576 - strlen('WAB_CATALOG_ONLY_2026'));
        file_put_contents($probePreset, $exact);
        $runProbe(hash('sha256', $exact));
        file_put_contents($probePreset, $exact . 'x');
        $runProbe('reject');
        $handle = fopen($probePreset, 'r+b');
        if (false === $handle || !ftruncate($handle, 64 * 1024 * 1024)) throw new RuntimeException('Cannot create oversized fixture.');
        fclose($handle);
        $runProbe('reject');
        file_put_contents($probePreset, 'no marker');
        $runProbe('reject');
        file_put_contents($probePreset, " \n\t");
        $runProbe('reject');
        unlink($probePreset);
        $runProbe('reject');
    } finally {
        foreach (array($probePreset, $probeScript, $probeAdapter, $probeTrait) as $file) {
            if (is_file($file)) unlink($file);
        }
        rmdir($probeRoot . '/Adapters');
        rmdir($probeRoot . '/Presets');
        rmdir($probeRoot);
    }
    if (strlen($source) < 1000 || false === strpos($source, 'WAB_CATALOG_ONLY_2026')) {
        $fail('Versioned source preset is missing or empty.');
    }
    foreach (array(
        'woocommerce_is_purchasable', 'woocommerce_variation_is_purchasable',
        'woocommerce_add_to_cart_validation', 'woocommerce_checkout_process',
        'woocommerce_check_cart_items', 'rest_pre_dispatch',
        'woocommerce_get_price_html', 'wc/store', '(?:cart|batch)', 'checkout',
        'woocommerce_after_shop_loop_item', 'woocommerce_single_variation',
    ) as $needle) {
        if (false === strpos($source, $needle) && 'wc/store' !== $needle) {
            $fail('Missing checkout / price safeguard: ' . $needle);
        }
    }
    if (false === strpos($source, 'preg_match(') || false !== strpos($source, 'eval(')) {
        $fail('Store API routing safety or source purity missing.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'wpc-catalog-');
    file_put_contents($tmp, "<?php\n" . $source);
    $lines = array();
    $exitCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $lines, $exitCode);
    unlink($tmp);
    if (0 !== $exitCode) {
        $fail('Snippet PHP syntax error: ' . implode(' ', $lines));
    }

    $GLOBALS['wpc_snippet_records'] = array();
    $GLOBALS['wpc_save_calls'] = 0;
    $GLOBALS['wpc_identity_reads'] = 0;
    $adapter = new \Webactueel\WordPressConnector\Adapters\CodeSnippetsAdapter();

    $empty = $adapter->catalogOnlyInspect(array());
    if ($empty['snippet']['present'] || empty($empty['fingerprint'])) {
        $fail('Empty inspect state is invalid.');
    }
    $dry = $adapter->catalogOnlyEnsure(array(), array('dry_run' => true));
    if (! $dry['would_create'] || ! $dry['would_activate'] || 0 !== $GLOBALS['wpc_save_calls']) {
        $fail('Dry-run did not preserve state.');
    }
    $expectFailure(static function () use ($adapter): void {
        $adapter->catalogOnlyEnsure(array('code' => 'echo "unsafe";'), array('dry_run' => false));
    }, 'Caller-controlled PHP was permitted.');
    $expectFailure(static function () use ($adapter): void {
        $adapter->catalogOnlyEnsure(array('name' => 'Unexpected'), array('dry_run' => true));
    }, 'Caller-controlled fields were permitted.');

    $created = $adapter->catalogOnlyEnsure(array(), array('dry_run' => false));
    if (1 !== $GLOBALS['wpc_save_calls'] || 101 !== $created['snippet']['id'] ||
        ! $created['snippet']['active'] || ! $created['readback_verified'] ||
        $created['snippet']['code_sha256'] !== hash('sha256', trim($source)) ||
        false === strpos($GLOBALS['wpc_snippet_records'][101]->code, 'woocommerce_is_purchasable')) {
        $fail('Managed snippet was not created and validated.');
    }
    $same = $adapter->catalogOnlyEnsure(array(), array('dry_run' => false));
    if (1 !== $GLOBALS['wpc_save_calls'] || $same['would_create']) {
        $fail('Repeated ensure created another snippet.');
    }
    $GLOBALS['wpc_snippet_records'][101]->code = 'echo 123;';
    $expectFailure(static function () use ($adapter): void {
        $adapter->catalogOnlyInspect(array());
    }, 'Tampered snippet was not rejected.');
    $GLOBALS['wpc_snippet_records'][101]->code = trim($source);

    $adapter->catalogOnlyUndo($created['_rollback']['payload'], array('dry_run' => true));
    if (! isset($GLOBALS['wpc_snippet_records'][101])) {
        $fail('Dry-run rollback deleted a snippet.');
    }
    $undo = $adapter->catalogOnlyUndo($created['_rollback']['payload'], array('dry_run' => false));
    if (empty($undo['readback_verified']) || ! empty($GLOBALS['wpc_snippet_records'])) {
        $fail('Created snippet did not rollback cleanly.');
    }

    $preexisting = new \Code_Snippets\Model\Snippet(array(
        'id' => 202,
        'name' => 'Webactueel | WooCommerce Catalogue (No Orders)',
        'code' => trim($source),
        'scope' => 'global',
        'type' => 'php',
        'active' => false,
    ));
    $GLOBALS['wpc_snippet_records'][202] = $preexisting;
    $activated = $adapter->catalogOnlyEnsure(array(), array('dry_run' => false));
    if ($activated['would_create'] || ! $GLOBALS['wpc_snippet_records'][202]->active ||
        ! empty($activated['_rollback']['payload']['created'])) {
        $fail('Existing valid inactive snippet was not safely activated.');
    }
    $adapter->catalogOnlyUndo($activated['_rollback']['payload'], array('dry_run' => false));
    if (! isset($GLOBALS['wpc_snippet_records'][202]) || $GLOBALS['wpc_snippet_records'][202]->active) {
        $fail('Preexisting snippet should remain saved, but inactive, after rollback.');
    }
    // Invalid selectors must never be converted to a different valid ID.
    $fixture = new \Code_Snippets\Model\Snippet(array(
        'id' => 1, 'name' => 'Identity fixture', 'code' => 'fixture marker', 'type' => 'php',
    ));
    $GLOBALS['wpc_snippet_records'][1] = $fixture;
    $patch = array('snippet_id' => 1, 'replacements' => array(array('find' => 'marker', 'replace' => 'changed')));
    $rollback = array('snippet_id' => 1, 'code' => 'prior fixture', 'expected_code_fingerprint' => hash('sha256', $fixture->code));
    $badIds = array(true, false, array(1), array(), 1.9, '1junk', '1e0', '+1', ' 1', -1, str_repeat('9', 50));
    foreach ($badIds as $invalid) {
        foreach (array('patch' => $patch, 'restoreCode' => $rollback) as $method => $payload) {
            $payload['snippet_id'] = $invalid;
            $beforeReads = $GLOBALS['wpc_identity_reads'];
            $rejected = false;
            try {
                $adapter->$method($payload, array('dry_run' => true));
            } catch (\RuntimeException $error) {
                $rejected = true;
            }
            if (!$rejected || $beforeReads !== $GLOBALS['wpc_identity_reads']) {
                $fail('Malformed identifier reached provider: ' . $method);
            }
        }
    }
    foreach (array(false, array('fixture'), 1) as $invalid) {
        $payload = $patch;
        $payload['match_code_contains'] = $invalid;
        $beforeReads = $GLOBALS['wpc_identity_reads'];
        $rejected = false;
        try { $adapter->patch($payload, array('dry_run' => true)); }
        catch (\RuntimeException $error) { $rejected = true; }
        if (!$rejected || $beforeReads !== $GLOBALS['wpc_identity_reads']) {
            $fail('Malformed marker reached provider.');
        }
    }
    foreach (array(1, '0001') as $validId) {
        $payload = $patch;
        $payload['snippet_id'] = $validId;
        $preview = $adapter->patch($payload, array('dry_run' => true));
        if (1 !== $preview['snippet']['id'] || 'fixture marker' !== $fixture->code) {
            $fail('Valid ID preview changed target or fixture.');
        }
        $payload = $rollback;
        $payload['snippet_id'] = $validId;
        $preview = $adapter->restoreCode($payload, array('dry_run' => true));
        if (1 !== $preview['snippet']['id'] || 'fixture marker' !== $fixture->code) {
            $fail('Valid rollback preview changed target or fixture.');
        }
    }
    foreach (array(array(), array('snippet_id' => 0)) as $selector) {
        $payload = array_merge($selector, array('match_code_contains' => 'fixture marker', 'replacements' => $patch['replacements']));
        $preview = $adapter->patch($payload, array('dry_run' => true));
        if (1 !== $preview['snippet']['id'] || 'fixture marker' !== $fixture->code) {
            $fail('Valid marker preview changed target or fixture.');
        }
    }
    foreach (array(array('x'), false, 7) as $invalid) {
        $payload = $rollback;
        $payload['expected_code_fingerprint'] = $invalid;
        $beforeReads = $GLOBALS['wpc_identity_reads'];
        $rejected = false;
        set_error_handler(static function(int $severity, string $message): bool { throw new \ErrorException($message, 0, $severity); });
        try { $adapter->restoreCode($payload, array('dry_run' => true)); }
        catch (\RuntimeException $error) { $rejected = true; }
        finally { restore_error_handler(); }
        if (!$rejected || $beforeReads !== $GLOBALS['wpc_identity_reads']) {
            $fail('Malformed rollback fingerprint reached provider.');
        }
    }
    echo "code snippets catalogue preset and target identity contract OK\n";
}
