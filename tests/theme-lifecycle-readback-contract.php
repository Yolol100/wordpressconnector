<?php
declare(strict_types=1);

namespace {
    $root = dirname(__DIR__);
    $tmp = sys_get_temp_dir() . '/wpconnector-theme-lifecycle-' . bin2hex(random_bytes(6));
    if (! mkdir($tmp . '/wp-admin/includes', 0700, true)) {
        throw new \RuntimeException('Could not create theme lifecycle test fixture.');
    }
    foreach (array('file.php', 'theme.php', 'update.php', 'class-wp-upgrader.php') as $file) {
        if (false === file_put_contents($tmp . '/wp-admin/includes/' . $file, "<?php\n")) {
            throw new \RuntimeException('Could not create WordPress include fixture.');
        }
    }
    register_shutdown_function(static function () use ($tmp): void {
        foreach (array('file.php', 'theme.php', 'update.php', 'class-wp-upgrader.php') as $file) {
            @unlink($tmp . '/wp-admin/includes/' . $file);
        }
        @rmdir($tmp . '/wp-admin/includes');
        @rmdir($tmp . '/wp-admin');
        @rmdir($tmp);
    });
    define('ABSPATH', $tmp . '/');
    $GLOBALS['theme_fixture'] = array(
        'active' => 'active-theme',
        'parent' => 'active-parent',
        'installed' => array(
            'active-theme' => '1.0',
            'active-parent' => '1.0',
            'candidate-theme' => '2.0',
        ),
        'delete_result' => null,
        'delete_effect' => false,
        'delete_calls' => 0,
        'cache_clears' => 0,
        'switch_effect' => false,
        'update_offer' => '2.5',
        'offer_after_refresh' => null,
        'upgrade_result' => true,
        'upgrade_version' => '2.5',
        'upgrade_calls' => 0,
    );

    final class ThemeLifecycleError
    {
        public function get_error_message(): string { return 'Simulated filesystem failure'; }
    }
    final class ThemeLifecycleTheme
    {
        private string $name;
        public function __construct(string $name) { $this->name = $name; }
        public function exists(): bool { return isset($GLOBALS['theme_fixture']['installed'][$this->name]); }
        public function errors(): bool { return false; }
        public function get(string $key): string
        {
            return 'Version' === $key ? (string) ($GLOBALS['theme_fixture']['installed'][$this->name] ?? '') : '';
        }
    }
    function sanitize_text_field($value): string { return trim((string) $value); }
    function get_stylesheet(): string { return $GLOBALS['theme_fixture']['active']; }
    function get_template(): string { return $GLOBALS['theme_fixture']['parent']; }
    function wp_get_theme(string $name): ThemeLifecycleTheme { return new ThemeLifecycleTheme($name); }
    function is_wp_error($value): bool { return $value instanceof ThemeLifecycleError; }
    function wp_clean_themes_cache(): void { ++$GLOBALS['theme_fixture']['cache_clears']; }
    function delete_theme(string $name)
    {
        ++$GLOBALS['theme_fixture']['delete_calls'];
        $result = $GLOBALS['theme_fixture']['delete_result'];
        if (true === $result && $GLOBALS['theme_fixture']['delete_effect']) {
            unset($GLOBALS['theme_fixture']['installed'][$name]);
        }
        return $result;
    }
    function switch_theme(string $name): void
    {
        if ($GLOBALS['theme_fixture']['switch_effect']) {
            $GLOBALS['theme_fixture']['active'] = $name;
        }
    }
    function get_site_transient(string $key) {
        if ('update_themes' !== $key) return false;
        return (object) array('response' => array(
            'candidate-theme' => array('new_version' => $GLOBALS['theme_fixture']['update_offer']),
        ));
    }
    function wp_update_themes(): void {
        if (null !== $GLOBALS['theme_fixture']['offer_after_refresh']) {
            $GLOBALS['theme_fixture']['update_offer'] = $GLOBALS['theme_fixture']['offer_after_refresh'];
            $GLOBALS['theme_fixture']['offer_after_refresh'] = null;
        }
    }
    class Automatic_Upgrader_Skin {}
    class Theme_Upgrader {
        public function __construct($skin) {}
        public function upgrade($stylesheet) {
            ++$GLOBALS['theme_fixture']['upgrade_calls'];
            $result = $GLOBALS['theme_fixture']['upgrade_result'];
            if (true === $result) {
                $GLOBALS['theme_fixture']['installed'][$stylesheet] =
                    $GLOBALS['theme_fixture']['upgrade_version'];
            }
            return $result;
        }
    }

    require_once $root . '/plugin/wordpressconnector/includes/Adapters/SystemAdapter.php';
    $adapter = new \Webactueel\WordPressConnector\Adapters\SystemAdapter();

    $expectFailure = static function (callable $operation, string $reason): void {
        try {
            $operation();
        } catch (\RuntimeException $error) {
            return;
        }
        throw new \RuntimeException('Expected failure: ' . $reason);
    };
    $delete = static function () use ($adapter): array {
        return $adapter->themeDelete(array('stylesheet' => 'candidate-theme'), array('dry_run' => false));
    };

    // WordPress Core can return null when it needs interactive filesystem
    // credentials, or false on failure; neither is successful deletion.
    foreach (array(null, false, new ThemeLifecycleError()) as $result) {
        $GLOBALS['theme_fixture']['delete_result'] = $result;
        $expectFailure($delete, 'WordPress delete_theme() did not succeed');
    }
    $GLOBALS['theme_fixture']['delete_result'] = true;
    $GLOBALS['theme_fixture']['delete_effect'] = false;
    $expectFailure($delete, 'WordPress reported success without removing theme');
    if (0 === $GLOBALS['theme_fixture']['cache_clears']) {
        throw new \RuntimeException('Successful delete did not invalidate theme cache.');
    }

    // Active theme and active parent must never be deletable.
    foreach (array('active-theme', 'active-parent') as $protected) {
        $priorCalls = $GLOBALS['theme_fixture']['delete_calls'];
        $expectFailure(static function () use ($adapter, $protected): void {
            $adapter->themeDelete(array('stylesheet' => $protected), array('dry_run' => false));
        }, 'protected active theme or parent');
        if ($priorCalls !== $GLOBALS['theme_fixture']['delete_calls']) {
            throw new \RuntimeException('Protected theme reached delete_theme().');
        }
    }

    $GLOBALS['theme_fixture']['delete_effect'] = true;
    $removed = $delete();
    if (($removed['deleted'] ?? null) !== 'candidate-theme'
        || ($removed['readback_verified'] ?? false) !== true
        || ($removed['rollback_supported'] ?? true) !== false) {
        throw new \RuntimeException('Verified theme removal contract failed.');
    }

    $GLOBALS['theme_fixture']['installed']['candidate-theme'] = '2.0';
    $GLOBALS['theme_fixture']['switch_effect'] = false;
    $expectFailure(static function () use ($adapter): void {
        $adapter->themeSwitch(array('stylesheet' => 'candidate-theme'), array('dry_run' => false));
    }, 'theme switch did not persist');
    $GLOBALS['theme_fixture']['switch_effect'] = true;
    $switched = $adapter->themeSwitch(array('stylesheet' => 'candidate-theme'), array('dry_run' => false));
    if (($switched['active'] ?? null) !== 'candidate-theme'
        || ($switched['readback_verified'] ?? false) !== true
        || ($switched['_rollback']['payload']['stylesheet'] ?? null) !== 'active-theme') {
        throw new \RuntimeException('Verified theme activation or rollback contract failed.');
    }

    $preview = $adapter->themeUpdate(array(
        'stylesheet' => 'candidate-theme', 'expected_target_version' => '2.5',
    ), array('dry_run' => true));
    if (($preview['available_version'] ?? null) !== '2.5'
        || ($preview['from_version'] ?? null) !== '2.0') {
        throw new \RuntimeException('Pinned theme preview did not disclose update offer.');
    }
    $expectFailure(static function () use ($adapter): void {
        $adapter->themeUpdate(array('stylesheet' => 'candidate-theme',
            'expected_target_version' => '2.6'), array('dry_run' => true));
    }, 'different theme update offer');
    $expectFailure(static function () use ($adapter): void {
        $adapter->themeUpdate(array('stylesheet' => 'candidate-theme',
            'expected_target_version' => 'dangerous/version'), array('dry_run' => true));
    }, 'invalid theme target version');
    $expectFailure(static function () use ($adapter): void {
        $adapter->themeUpdate(array('stylesheet' => 'candidate-theme',
            'extra' => 123), array('dry_run' => true));
    }, 'unknown theme update argument');

    $calls = $GLOBALS['theme_fixture']['upgrade_calls'];
    $GLOBALS['theme_fixture']['offer_after_refresh'] = '2.6';
    $expectFailure(static function () use ($adapter): void {
        $adapter->themeUpdate(array('stylesheet' => 'candidate-theme',
            'expected_target_version' => '2.5'), array('dry_run' => false));
    }, 'theme offer changed before execution');
    if ($GLOBALS['theme_fixture']['upgrade_calls'] !== $calls
        || $GLOBALS['theme_fixture']['installed']['candidate-theme'] !== '2.0') {
        throw new \RuntimeException('Theme update offer drift started an unapproved upgrade.');
    }
    $GLOBALS['theme_fixture']['update_offer'] = '2.5';
    $GLOBALS['theme_fixture']['upgrade_result'] = false;
    $expectFailure(static function () use ($adapter): void {
        $adapter->themeUpdate(array('stylesheet' => 'candidate-theme',
            'expected_target_version' => '2.5'), array('dry_run' => false));
    }, 'failed native Theme_Upgrader');
    $GLOBALS['theme_fixture']['upgrade_result'] = true;
    $GLOBALS['theme_fixture']['upgrade_version'] = '2.0';
    $expectFailure(static function () use ($adapter): void {
        $adapter->themeUpdate(array('stylesheet' => 'candidate-theme',
            'expected_target_version' => '2.5'), array('dry_run' => false));
    }, 'theme updater no version change');
    $GLOBALS['theme_fixture']['upgrade_version'] = '2.6';
    $expectFailure(static function () use ($adapter): void {
        $adapter->themeUpdate(array('stylesheet' => 'candidate-theme',
            'expected_target_version' => '2.5'), array('dry_run' => false));
    }, 'theme installed unexpected target');
    $GLOBALS['theme_fixture']['installed']['candidate-theme'] = '2.0';
    $GLOBALS['theme_fixture']['upgrade_version'] = '2.5';
    $updated = $adapter->themeUpdate(array(
        'stylesheet' => 'candidate-theme', 'expected_target_version' => '2.5',
    ), array('dry_run' => false));
    if (($updated['version'] ?? '') !== '2.5' || empty($updated['readback_verified'])
        || ($updated['rollback_supported'] ?? true) !== false) {
        throw new \RuntimeException('Pinned native theme update did not verify the exact version.');
    }

    echo "theme lifecycle readback contract OK\n";
}
