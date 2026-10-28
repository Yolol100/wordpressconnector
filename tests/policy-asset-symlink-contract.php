<?php
/** Run against the actual plugin's Policy.php: source file supplied as argv[1]. */
$policy = $argv[1] ?? dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
if (! is_file($policy)) { fwrite(STDERR, "Policy file missing\n"); exit(9); }
require $policy;
use Webactueel\WordPressConnector\Security\Policy;
$root = sys_get_temp_dir() . '/wpconn-asset-test-' . bin2hex(random_bytes(8));
if (!mkdir($root,0700,true)) throw new RuntimeException('fixture directory creation');
mkdir($root.'/nested',0700);
mkdir($root.'/alternate',0700);
file_put_contents($root.'/regular.zip', 'dummy archive');
file_put_contents($root.'/nested/asset.txt', 'safe placeholder');
file_put_contents($root.'/alternate/safe.txt', 'nested safe placeholder');
file_put_contents($root.'-outside.txt', 'outside');
symlink($root.'/regular.zip', $root.'/file-link.zip');
symlink($root.'/nested', $root.'/dir-link');
symlink($root.'-outside.txt', $root.'/outside-link.txt');
symlink($root.'/alternate', $root.'/nested/subalias');
$cases = [
 ['id'=>'inside-root-regular','path'=>$root.'/regular.zip','ok'=>true],
 ['id'=>'inside-root-nested','path'=>$root.'/nested/asset.txt','ok'=>true],
 ['id'=>'inside-root-deep','path'=>$root.'/alternate/safe.txt','ok'=>true],
 ['id'=>'final-symlink','path'=>$root.'/file-link.zip','ok'=>false],
 ['id'=>'intermediate-directory-symlink','path'=>$root.'/dir-link/asset.txt','ok'=>false],
 ['id'=>'nested-directory-symlink','path'=>$root.'/nested/subalias/safe.txt','ok'=>false],
 ['id'=>'symlink-outside','path'=>$root.'/outside-link.txt','ok'=>false],
 ['id'=>'sibling-prefix-collision','path'=>$root.'-outside.txt','ok'=>false],
 ['id'=>'parent-traversal','path'=>$root.'/../'.basename($root).'-outside.txt','ok'=>false],
 ['id'=>'missing-file','path'=>$root.'/missing.txt','ok'=>false],
 ['id'=>'relative-root-mismatch','path'=>'nested/asset.txt','ok'=>false],
];
$failed=[];
foreach($cases as $case){
 $passed=false;
 try{$resolved=Policy::assertLocalAssetPath($case['path'],$root);$passed=$case['ok'] && is_string($resolved);}catch(Throwable $e){$passed=!$case['ok'];}
 if(!$passed)$failed[]=$case['id'];
 echo ($passed?'PASS ':'FAIL ') . $case['id'] . "\n";
}
unlink($root.'/nested/subalias');unlink($root.'/outside-link.txt');unlink($root.'/dir-link');unlink($root.'/file-link.zip');
unlink($root.'/regular.zip');unlink($root.'/nested/asset.txt');unlink($root.'/alternate/safe.txt');
rmdir($root.'/nested');rmdir($root.'/alternate');rmdir($root);unlink($root.'-outside.txt');
if($failed){fwrite(STDERR,'Failed '.join(', ',$failed)."\n");exit(1);}
echo 'PASS ALL '.count($cases).' asset-path scenarios' . "\n";
