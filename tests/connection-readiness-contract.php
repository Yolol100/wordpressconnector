<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Security {
    final class SitePairing
    {
        public static function pairedAdministratorId(): int
        {
            return $GLOBALS['setup_readiness']['paired'] ? 21 : 0;
        }
    }
}

namespace WP\MCP\Core {
    // Fixture only: availability also requires an active canonical plugin path.
    class McpAdapter {}
}

namespace {
    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/ConnectionReadinessAdapter.php';

    $GLOBALS['setup_readiness'] = array(
        'https' => true, 'url' => 'https://example.invalid/wp-json/',
        'paired' => false, 'plugins' => array(), 'network' => array(),
        'writes' => 0, 'outbound' => 0,
    );

    function rest_url(string $path): string { return $GLOBALS['setup_readiness']['url'] . $path; }
    function is_ssl(): bool { return $GLOBALS['setup_readiness']['https']; }
    function wp_parse_url(string $url, int $component) { return parse_url($url, $component); }
    function get_option(string $name, $default = false) {
        return 'active_plugins' === $name ? $GLOBALS['setup_readiness']['plugins'] : $default;
    }
    function get_site_option(string $name, $default = false) {
        return 'active_sitewide_plugins' === $name ? $GLOBALS['setup_readiness']['network'] : $default;
    }
    function update_option(): void { ++$GLOBALS['setup_readiness']['writes']; throw new RuntimeException('Unexpected write'); }
    function wp_remote_get(): void { ++$GLOBALS['setup_readiness']['outbound']; throw new RuntimeException('Unexpected external request'); }

    $assert = static function (bool $condition, string $message): void {
        if (! $condition) throw new RuntimeException($message);
    };
    $adapter = new \Webactueel\WordPressConnector\Adapters\ConnectionReadinessAdapter();
    $registry = new \Webactueel\WordPressConnector\Runtime\Registry();
    $adapter->register($registry);
    $desc = $registry->descriptor('connector.setup.inspect');
    $assert($desc['privileged'] === true && $desc['mutation'] === false
        && $desc['sensitive'] === false && $desc['public_repository_safe'] === false
        && $desc['capability'] === 'manage_options',
        'Setup inspection must be a private administrator-only read.');

    $status = $adapter->inspect();
    $assert($status['read_only'] === true && $status['wordpress']['https_ready'] === true
        && $status['wordpress']['github_pairing_recorded'] === false
        && $status['wordpress']['github_oidc_execution_verified'] === false
        && $status['chatgpt']['oauth_21_ready'] === false
        && $status['chatgpt']['oauth_authorization_verified'] === false
        && $status['chatgpt']['native_mcp_adapter_detected'] === false
        && $status['chatgpt']['native_mcp_server_candidate'] === null
        && $status['chatgpt']['native_mcp_server_verified'] === false
        && $status['recovery']['database_backup_verified'] === false
        && $status['recovery']['site_files_backup_verified'] === false
        && $status['recovery']['offsite_backup_verified'] === false
        && $status['recovery']['staging_restore_verified'] === false
        && $status['high_risk_changes_cleared'] === false,
        'The initial setup snapshot must never guess that OAuth or recovery works.');

    $GLOBALS['setup_readiness']['plugins'] = array(
        'updraftplus/updraftplus.php',
        'all-in-one-wp-migration/all-in-one-wp-migration.php',
        'mcp-adapter/mcp-adapter.php',
        'unrelated/unrelated.php',
    );
    $GLOBALS['setup_readiness']['network'] = array('duplicator/duplicator.php' => 1);
    $GLOBALS['setup_readiness']['paired'] = true;
    $status = $adapter->inspect();
    $assert($status['wordpress']['github_pairing_recorded'] === true
        && $status['recovery']['active_backup_plugins_detected'] === array('UpdraftPlus', 'Duplicator', 'All-in-One WP Migration')
        && $status['chatgpt']['native_mcp_adapter_detected'] === true
        && $status['chatgpt']['native_mcp_server_candidate'] === 'https://example.invalid/wp-json/mcp/mcp-adapter-default-server'
        && $status['chatgpt']['native_mcp_server_verified'] === false
        && $status['recovery']['staging_restore_verified'] === false
        && $status['recovery']['offsite_backup_verified'] === false
        && $status['chatgpt']['oauth_21_ready'] === false,
        'Active backup plugins and WordPress pairing cannot impersonate backup/ChatGPT proof.');

    $GLOBALS['setup_readiness']['plugins'] = array('unrelated/unrelated.php');
    $GLOBALS['setup_readiness']['network'] = array();
    $status = $adapter->inspect();
    $assert($status['recovery']['active_backup_plugins_detected'] === array()
        && $status['chatgpt']['native_mcp_adapter_detected'] === false,
        'Inactive or unrecognized plugins must not be treated as active services.');

    $GLOBALS['setup_readiness']['plugins'] = array(
        array('invalid'), 'wpvivid-backuprestore/wpvivid-backuprestore.php', true
    );
    $GLOBALS['setup_readiness']['network'] = array('bad' => array('invalid'));
    set_error_handler(static function ($severity, $message): void {
        throw new RuntimeException('Malformed active plugin option triggered a warning: ' . $message);
    });
    try {
        $status = $adapter->inspect();
        $assert($status['recovery']['active_backup_plugins_detected'] === array('WPvivid')
            && $status['chatgpt']['native_mcp_adapter_detected'] === false,
            'Broken active-plugin options must be ignored without false-positive integrations.');
    } finally {
        restore_error_handler();
    }
    $GLOBALS['setup_readiness']['plugins'] = array();
    $GLOBALS['setup_readiness']['network'] = array();

    $GLOBALS['setup_readiness']['https'] = false;
    $status = $adapter->inspect();
    $assert($status['wordpress']['https_ready'] === false
        && $status['chatgpt']['mcp_endpoint_https'] === false,
        'Insecure WordPress context must fail closed.');
    $GLOBALS['setup_readiness']['https'] = true;
    $GLOBALS['setup_readiness']['url'] = 'http://example.invalid/wp-json/';
    $status = $adapter->inspect();
    $assert($status['wordpress']['https_ready'] === false,
        'HTTP MCP endpoint must fail closed even if is_ssl returns true.');

    try {
        $adapter->inspect(array('oauth_ready' => true));
        throw new RuntimeException('Request input could impersonate OAuth readiness.');
    } catch (RuntimeException $expected) {
        $assert($expected->getMessage() === 'Connection readiness does not accept parameters.',
            'Setup read must reject injected payloads.');
    }

    $assert($GLOBALS['setup_readiness']['writes'] === 0
        && $GLOBALS['setup_readiness']['outbound'] === 0,
        'Readiness must never write WordPress state or contact external servers.');

    $adminSource = (string) file_get_contents(dirname(__DIR__) .
        '/plugin/wordpressconnector/includes/Admin/Settings.php');
    foreach (array(
        'renderConnectionRecoveryGuide()',
        'new ConnectionReadinessAdapter()',
        'Not independently verified',
        'trusted OAuth 2.1 provider',
        'Official WordPress MCP Adapter',
        'Installing the official MCP Adapter alone does not enable OAuth.',
        'database',
        'separate staging site',
    ) as $needle) {
        $assert(false !== strpos($adminSource, $needle),
            'Admin UI is missing a safe recovery/ChatGPT step: ' . $needle);
    }
    echo "Connection readiness: admin-only no-secret OAuth/backup/status contracts OK\n";
}
