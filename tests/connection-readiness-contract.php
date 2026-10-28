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

namespace {
    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Runtime/Registry.php';
    require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Adapters/ConnectionReadinessAdapter.php';

    $GLOBALS['setup_readiness'] = array(
        'https' => true, 'url' => 'https://example.invalid/wp-json/',
        'paired' => false, 'plugins' => array(), 'network' => array(),
        'writes' => 0, 'outbound' => 0,
        'permissions' => array('manage_options' => true, 'install_plugins' => true, 'update_plugins' => true),
        'file_mod_allowed' => true,
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
    function current_user_can(string $capability): bool { return ! empty($GLOBALS['setup_readiness']['permissions'][$capability]); }
    function wp_is_file_mod_allowed(string $context): bool { return $GLOBALS['setup_readiness']['file_mod_allowed']; }
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
        && $status['recovery']['database_backup_verified'] === false
        && $status['recovery']['site_files_backup_verified'] === false
        && $status['recovery']['offsite_backup_verified'] === false
        && $status['recovery']['staging_restore_verified'] === false
        && $status['high_risk_changes_cleared'] === false,
        'The initial setup snapshot must never guess that OAuth or recovery works.');

    $management = $status['management'] ?? array();
    $assert(($management['permission_check_only'] ?? false) === true
        && ($management['site_write_verified'] ?? true) === false
        && ($management['capabilities']['manage_options'] ?? null) === true
        && ($management['capabilities']['install_plugins'] ?? null) === true
        && ($management['capabilities']['delete_plugins'] ?? null) === false
        && ($management['server_policy_gates']['writes'] ?? null) === true
        && ($management['server_policy_gates']['system_updates'] ?? null) === true
        && ($management['wordpress_file_modifications_allowed'] ?? null) === true,
        'Private readiness must report exact capability/flag indicators without claiming successful writes.');

    $GLOBALS['setup_readiness']['permissions']['install_plugins'] = false;
    $GLOBALS['setup_readiness']['file_mod_allowed'] = false;
    putenv('WPCONNECTOR_ALLOW_WRITES=0');
    $denied = $adapter->inspect();
    $assert($denied['management']['capabilities']['install_plugins'] === false
        && $denied['management']['server_policy_gates']['writes'] === false
        && $denied['management']['wordpress_file_modifications_allowed'] === false
        && $denied['management']['site_write_verified'] === false,
        'Site restrictions must remain visible and never be interpreted as granted write access.');
    putenv('WPCONNECTOR_ALLOW_WRITES');
    $GLOBALS['setup_readiness']['file_mod_allowed'] = true;

    $GLOBALS['setup_readiness']['plugins'] = array('updraftplus/updraftplus.php', 'unrelated/unrelated.php');
    $GLOBALS['setup_readiness']['network'] = array('duplicator/duplicator.php' => 1);
    $GLOBALS['setup_readiness']['paired'] = true;
    $status = $adapter->inspect();
    $assert($status['wordpress']['github_pairing_recorded'] === true
        && $status['recovery']['active_backup_plugins_detected'] === array('UpdraftPlus', 'Duplicator')
        && $status['recovery']['staging_restore_verified'] === false
        && $status['recovery']['offsite_backup_verified'] === false
        && $status['chatgpt']['oauth_21_ready'] === false,
        'Active backup plugins and WordPress pairing cannot impersonate backup/ChatGPT proof.');

    $GLOBALS['setup_readiness']['plugins'] = array('unrelated/unrelated.php');
    $GLOBALS['setup_readiness']['network'] = array();
    $status = $adapter->inspect();
    $assert($status['recovery']['active_backup_plugins_detected'] === array(),
        'Unrecognized plugins must not be treated as backup coverage.');

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
        'database',
        'separate staging site',
    ) as $needle) {
        $assert(false !== strpos($adminSource, $needle),
            'Admin UI is missing a safe recovery/ChatGPT step: ' . $needle);
    }
    echo "Connection readiness: admin-only no-secret OAuth/backup/status contracts OK\n";
}
