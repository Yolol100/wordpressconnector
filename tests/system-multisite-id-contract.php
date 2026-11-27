<?php
/**
 * Multisite update/delete must never coerce an ID to another site target.
 */
declare(strict_types=1);

class WP_Site {
    public $blog_id;
    public $site_id = 1;
    public $domain = 'original.example.test';
    public $path = '/subsite/';
    public $registered = '2026-01-01 00:00:00';
    public $last_updated = '2026-01-01 00:00:00';
    public $public = 1;
    public $archived = 0;
    public $mature = 0;
    public $spam = 0;
    public $deleted = 0;

    public function __construct(int $id) { $this->blog_id = $id; }
}
$GLOBALS['is_multisite'] = true;
$GLOBALS['site_fixture'] = new WP_Site(52);
$GLOBALS['site_lookups'] = 0;
$GLOBALS['main_site_checks'] = 0;
$GLOBALS['site_updates'] = 0;
$GLOBALS['site_deletes'] = 0;
$GLOBALS['last_site_update'] = null;

function is_multisite(): bool { return $GLOBALS['is_multisite']; }
function get_main_site_id(): int {
    ++$GLOBALS['main_site_checks'];
    return 1;
}
function get_site($id) {
    ++$GLOBALS['site_lookups'];
    if (! is_int($id)) {
        throw new RuntimeException('Site lookup received a coerced ID.');
    }
    return 52 === $id ? $GLOBALS['site_fixture'] : false;
}
class WP_Error { public function get_error_message(): string { return 'private provider diagnostic'; } }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_update_site($id, $data) {
    ++$GLOBALS['site_updates'];
    if (52 !== $id || ! is_int($id)) {
        throw new RuntimeException('Wrong site update target.');
    }
    if (array_key_exists('site_provider_update',$GLOBALS)) { return $GLOBALS['site_provider_update']; }
    $GLOBALS['last_site_update'] = $data;
    foreach ($data as $field => $value) {
        $GLOBALS['site_fixture']->$field = $value;
    }
    return 52;
}
function wp_delete_site($id) {
    ++$GLOBALS['site_deletes'];
    if (52 !== $id || ! is_int($id)) {
        throw new RuntimeException('Wrong site delete target.');
    }
    if (array_key_exists('site_provider_delete',$GLOBALS)) { return $GLOBALS['site_provider_delete']; }
    return $GLOBALS['site_fixture'];
}

$root = dirname(__DIR__);
foreach (array('Support/class-json.php', 'Support/class-fingerprint.php',
    'Support/class-input.php', 'Adapters/class-systemadapter.php') as $file) {
    require_once $root . '/plugin/wordpressconnector/includes/' . $file;
}
$adapter = new \Webactueel\WordPressConnector\Adapters\SystemAdapter();

// Invalid IDs must fail before main-site checks, lookups or mutations.
$invalid = array(true, false, 52.5, -52, 0, '0', '',
    '52junk', '5.2e1', ' 52', "52\n",
    '99999999999999999999999999999', array(52), null);
foreach ($invalid as $id) {
    foreach (array('siteUpdate', 'siteDelete') as $method) {
        foreach (array(true, false) as $dryRun) {
            $lookups = $GLOBALS['site_lookups'];
            $mainChecks = $GLOBALS['main_site_checks'];
            $updates = $GLOBALS['site_updates'];
            $deletes = $GLOBALS['site_deletes'];
            try {
                $adapter->$method(
                    array('id' => $id, 'domain' => 'unsafe.example.test'),
                    array('dry_run' => $dryRun)
                );
                throw new RuntimeException('Malformed site ID was accepted.');
            } catch (RuntimeException $error) {
                if ('Site not found.' !== $error->getMessage()) {
                    throw $error;
                }
            }
            if ($lookups !== $GLOBALS['site_lookups']
                || $mainChecks !== $GLOBALS['main_site_checks']
                || $updates !== $GLOBALS['site_updates']
                || $deletes !== $GLOBALS['site_deletes']) {
                throw new RuntimeException('Rejected site ID reached WordPress APIs.');
            }
        }
    }
}
foreach (array('siteUpdate', 'siteDelete') as $method) {
    try {
        $adapter->$method(array('id' => 999), array('dry_run' => true));
        throw new RuntimeException('Nonexistent site was accepted.');
    } catch (RuntimeException $error) {
        if ('Site not found.' !== $error->getMessage()) { throw $error; }
    }
}
// Preserve the special protection for the main site before any lookup.
foreach (array(1, '1', '0001') as $mainID) {
    $lookups = $GLOBALS['site_lookups'];
    try {
        $adapter->siteDelete(array('id' => $mainID), array('dry_run' => false));
        throw new RuntimeException('Main site deletion was allowed.');
    } catch (RuntimeException $error) {
        if ('Refusing to delete the main site.' !== $error->getMessage()) {
            throw $error;
        }
    }
    if ($lookups !== $GLOBALS['site_lookups'] || $GLOBALS['site_deletes'] !== 0) {
        throw new RuntimeException('Main site protection reached a destructive API.');
    }
}
foreach (array(52, '52', '00052') as $id) {
    $preview = $adapter->siteUpdate(
        array('id' => $id, 'domain' => 'changed.example.test', 'public' => false),
        array('dry_run' => true)
    );
    if (($preview['before']['id'] ?? null) !== 52
        || ($preview['changes']['domain'] ?? null) !== 'changed.example.test'
        || ($preview['changes']['public'] ?? null) !== 0
        || ! preg_match('/\A[a-f0-9]{64}\z/', $preview['_current_fingerprint'] ?? '')
        || $GLOBALS['site_updates'] !== 0) {
        throw new RuntimeException('Valid multisite update dry-run changed.');
    }
    $deletionPreview = $adapter->siteDelete(
        array('id' => $id), array('dry_run' => true)
    );
    if (($deletionPreview['before']['id'] ?? null) !== 52
        || ! isset($deletionPreview['_current_fingerprint'])
        || $GLOBALS['site_deletes'] !== 0) {
        throw new RuntimeException('Valid multisite delete dry-run changed.');
    }
}
$changed = $adapter->siteUpdate(
    array('id' => '00052', 'domain' => 'changed.example.test', 'public' => false),
    array('dry_run' => false)
);
if ($GLOBALS['site_updates'] !== 1
    || ($changed['site']['id'] ?? null) !== 52
    || ($changed['site']['domain'] ?? null) !== 'changed.example.test'
    || ($changed['site']['public'] ?? null) !== 0
    || ($changed['_rollback']['action'] ?? null) !== 'multisite.site.update'
    || ($changed['_rollback']['payload']['id'] ?? null) !== 52
    || ($changed['_rollback']['payload']['domain'] ?? null) !== 'original.example.test'
    || ($GLOBALS['last_site_update']['public'] ?? null) !== 0) {
    throw new RuntimeException('Multisite update or rollback metadata changed.');
}
$deleted = $adapter->siteDelete(
    array('id' => '00052'), array('dry_run' => false)
);
if ($GLOBALS['site_deletes'] !== 1
    || ($deleted['deleted'] ?? null) !== 52
    || ($deleted['rollback_supported'] ?? null) !== false) {
    throw new RuntimeException('Exact multisite delete target or no-rollback contract changed.');
}
// The multisite guard must still reject operations outside multisite.
$GLOBALS['is_multisite'] = false;
foreach (array('siteUpdate', 'siteDelete') as $method) {
    try {
        $adapter->$method(array('id' => 52), array('dry_run' => true));
        throw new RuntimeException('Non-multisite update/delete accepted.');
    } catch (RuntimeException $error) {
        if ('Not a multisite installation.' !== $error->getMessage()) {
            throw $error;
        }
    }
}
if ($GLOBALS['site_updates'] !== 1 || $GLOBALS['site_deletes'] !== 1) {
    throw new RuntimeException('Non-multisite guard modified site data.');
}
$GLOBALS['is_multisite'] = true;
foreach (array('siteUpdate'=>'update','siteDelete'=>'delete') as $method=>$kind) {
    $cases = 'update' === $kind ? array(new WP_Error(),false,0,53,'52') : array(new WP_Error(),false,null,new WP_Site(53));
    foreach ($cases as $failure) {
        $GLOBALS['site_provider_'.$kind]=$failure;
        try {
            $adapter->$method(array('id'=>52,'domain'=>'unsafe.example.test'),array('dry_run'=>false));
            throw new RuntimeException('Rejected multisite provider result accepted.');
        } catch (RuntimeException $error) {
            if ('WordPress site '.$kind.' failed.' !== $error->getMessage()) throw $error;
        }
    }
    unset($GLOBALS['site_provider_'.$kind]);
}
function sanitize_key($value): string { return strtolower((string)$value); }
function wp_get_scheduled_event($hook,$args) { return (object)array('timestamp'=>123,'schedule'=>false,'args'=>$args); }
function wp_schedule_single_event($timestamp,$hook,$args,$wp_error) { return $GLOBALS['cron_provider_result']; }
function wp_schedule_event($timestamp,$recurrence,$hook,$args,$wp_error) { return $GLOBALS['cron_provider_result']; }
function wp_clear_scheduled_hook($hook,$args,$wp_error=false) {
    if (true !== $wp_error) throw new RuntimeException('Cron deletion must request typed errors.');
    return $GLOBALS['cron_provider_result'];
}
foreach (array('cronSchedule'=>'schedule','cronDelete'=>'deletion') as $method=>$kind) {
    foreach (array(new WP_Error(),false,null,'1',-1) as $failure) {
        $GLOBALS['cron_provider_result']=$failure;
        try {
            $adapter->$method(array('hook'=>'fixture_hook','timestamp'=>456),array('dry_run'=>false));
            throw new RuntimeException('Rejected cron provider result accepted.');
        } catch (RuntimeException $error) {
            if ('WordPress cron '.$kind.' failed.' !== $error->getMessage()) throw $error;
        }
    }
}
$GLOBALS['cron_provider_result']=0;
if (0 !== ($adapter->cronDelete(array('hook'=>'fixture_hook'),array('dry_run'=>false))['cleared'] ?? null)) throw new RuntimeException('Valid zero cron clear rejected.');
$GLOBALS['cron_provider_result']=true;
if (true !== ($adapter->cronSchedule(array('hook'=>'fixture_hook','timestamp'=>456),array('dry_run'=>false))['scheduled'] ?? null)) throw new RuntimeException('Valid cron scheduling rejected.');
echo "Multisite exact-ID update/delete and main-site guard contract OK\n";
