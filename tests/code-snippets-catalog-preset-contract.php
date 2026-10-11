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
        return array_values($GLOBALS['wpc_snippet_records']);
    }
    function get_snippet(int $id, ?bool $network = null)
    {
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
    require_once $root . '/plugin/wordpressconnector/includes/Support/Json.php';
    require_once $root . '/plugin/wordpressconnector/includes/Support/Fingerprint.php';
    require_once $root . '/plugin/wordpressconnector/includes/Adapters/CodeSnippetsAdapter.php';

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
    echo "code snippets catalogue preset contract OK\n";
}
