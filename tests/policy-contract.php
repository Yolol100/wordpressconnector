<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
$GLOBALS['wpconnector_test_caps'] = array();
function current_user_can($capability): bool { return in_array((string) $capability, $GLOBALS['wpconnector_test_caps'], true); }
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';

use Webactueel\WordPressConnector\Security\Policy;

$expectFailure = static function (callable $callback, string $label, string $needle = ''): void {
    try { $callback(); }
    catch (RuntimeException $error) {
        if ('' !== $needle && false === strpos($error->getMessage(), $needle)) {
            fwrite(STDERR, "Unexpected policy failure for {$label}: {$error->getMessage()}\n"); exit(1);
        }
        return;
    }
    fwrite(STDERR, "Expected policy failure: {$label}.\n"); exit(1);
};

foreach (array('WPCONNECTOR_ALLOW_WRITES','WPCONNECTOR_ALLOW_PRIVILEGED','WPCONNECTOR_ALLOW_SENSITIVE','WPCONNECTOR_ALLOW_SYSTEM_UPDATES','WPCONNECTOR_ALLOW_FILESYSTEM_WRITES') as $flag) {
    putenv($flag);
    if (! Policy::flag($flag)) { fwrite(STDERR, "Zero-config policy flag is not enabled by default: {$flag}.\n"); exit(1); }
}

$descriptor=array('mutation'=>false,'privileged'=>false,'sensitive'=>false,'system_update'=>false);
Policy::assertActionAllowed($descriptor,true,false);
$expectFailure(static fn()=>Policy::assertKeyAllowed('api_key'),'secret-like key');
$expectFailure(static fn()=>Policy::assertMetaKeyAllowed('_edit_lock'),'protected metadata');
$expectFailure(static fn()=>Policy::assertOptionKeyAllowed('active_plugins'),'system-owned option');
Policy::assertMetaKeyAllowed('public_project_note');
Policy::assertOptionKeyAllowed('blogdescription');

$expectFailure(static fn()=>Policy::assertActionAllowed(array('sensitive'=>true),true,false),'sensitive request without request confirmation','confirm=true');
Policy::assertActionAllowed(array('sensitive'=>true),true,true);
$expectFailure(static fn()=>Policy::assertActionAllowed(array('mutation'=>true),false,false),'mutation without request confirmation','confirm=true');
Policy::assertActionAllowed(array('mutation'=>true),false,true);

putenv('WPCONNECTOR_ALLOW_WRITES=0');
$expectFailure(static fn()=>Policy::assertActionAllowed(array('mutation'=>true),false,true),'server-side emergency write disable','disabled by server policy');
putenv('WPCONNECTOR_ALLOW_WRITES');

putenv('WPCONNECTOR_ALLOW_PRIVILEGED=0');
$expectFailure(static fn()=>Policy::assertActionAllowed(array('privileged'=>true),true,false),'server-side emergency privileged disable','disabled by server policy');
putenv('WPCONNECTOR_ALLOW_PRIVILEGED');

Policy::setPublicRepositoryContext(true);
$GLOBALS['wpconnector_test_caps']=array('update_plugins');
Policy::assertActionAllowed(array('mutation'=>true,'privileged'=>true,'system_update'=>true,'public_repository_safe'=>true,'capability'=>'update_plugins'),false,true);
$expectFailure(static fn()=>Policy::assertActionAllowed(array('privileged'=>true),true,false),'unmarked public privileged action','public-repository mode');
$expectFailure(static fn()=>Policy::assertActionAllowed(array('sensitive'=>true),true,true),'public sensitive action','Sensitive actions are blocked');
$expectFailure(static fn()=>Policy::assertActionAllowed(array('privileged'=>true,'public_repository_safe'=>true,'capability'=>'install_plugins'),true,false),'missing function-level capability','lacks the required WordPress capability');
Policy::setPublicRepositoryContext(false);
$GLOBALS['wpconnector_test_caps']=array();

echo "policy zero-config contract OK\n";
