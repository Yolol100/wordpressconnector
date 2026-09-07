<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$store = file_get_contents($root . '/plugin/wordpressconnector/includes/Runtime/ProcessedStore.php');
$runner = file_get_contents($root . '/plugin/wordpressconnector/includes/Runtime/Runner.php');
if ($store === false || $runner === false) { fwrite(STDERR, "Unable to read runtime lock sources.\n"); exit(1); }
$storeChecks = array('private const LOCK_KEY'=>'mutation lock key','private const LOCK_TTL'=>'stale lock TTL','add_option(self::LOCK_KEY'=>'atomic initial lock acquisition','compareAndSwapLock'=>'stale lock compare-and-swap','deleteLockIfValue'=>'conditional lock release','wp_cache_delete(self::LOCK_KEY'=>'option cache invalidation');
foreach ($storeChecks as $needle=>$label) { if (strpos($store,$needle)===false) { fwrite(STDERR,"ProcessedStore missing {$label}.\n"); exit(1); } }
$normalized = preg_replace('/\s+/', '', $runner);
if (! is_string($normalized)) { exit(1); }
$runnerChecks = array('$this->processed->acquireMutationLock()'=>'mutation lock acquisition','$this->processed->get($request->id())'=>'idempotency recheck after acquisition','finally{'=>'guaranteed lock cleanup','$this->processed->releaseMutationLock($lockToken)'=>'mutation lock release','executeWithStateGuards'=>'state guard inside serialized mutation path');
foreach ($runnerChecks as $needle=>$label) { if (strpos($normalized,$needle)===false) { fwrite(STDERR,"Runner missing {$label}.\n"); exit(1); } }
$acquirePosition=strpos($normalized,'$this->processed->acquireMutationLock()');$getPosition=strpos($normalized,'$this->processed->get($request->id())');$executePosition=strpos($normalized,'$data=$this->executeWithStateGuards(');$putPosition=strpos($normalized,'$this->processed->put(');
if($acquirePosition===false||$getPosition===false||$executePosition===false||$putPosition===false||!($acquirePosition<$getPosition&&$getPosition<$executePosition&&$executePosition<$putPosition)){fwrite(STDERR,"Mutation lock/idempotency execution order is unsafe.\n");exit(1);}
echo "mutation lock contract OK\n";
