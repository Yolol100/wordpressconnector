<?php
// One-time conservative WPCS cleanup. Delete from branch after generating and testing.
declare(strict_types=1);
$directory = $argv[1] ?? '';
$dry = in_array('--dry-run', $argv, true);
if (!is_dir($directory)) { fwrite(STDERR, "not a directory\n"); exit(1); }
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
$testCorpus = '';
$testFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/tests', FilesystemIterator::SKIP_DOTS));
foreach ($testFiles as $testFile) { if ($testFile->isFile() && str_ends_with($testFile->getFilename(), '.php')) $testCorpus .= file_get_contents($testFile->getPathname()) . "\n"; }
$grand = ['files'=>0, 'variables'=>0, 'occurrences'=>0, 'skipped_parameters'=>0, 'skipped_collisions'=>0, 'skipped_literals'=>0];
function snake(string $s): string {
 $body=substr($s,1);
 $body=preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $body);
 $body=preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $body);
 return '$'.strtolower($body);
}
foreach ($files as $file) {
 if (!$file->isFile() || !str_ends_with($file->getFilename(),'.php')) continue;
 $src=file_get_contents($file->getPathname());
 $tokens=token_get_all($src);
 $parameters=[];$existing=[];$locally=[];$globals=[];$props=[];
 foreach($tokens as $t) if(is_array($t)&&$t[0]===T_VARIABLE)$existing[$t[1]]=true;
 $count=count($tokens);
 for($i=0;$i<$count;$i++) {
  $t=$tokens[$i];
  if(!is_array($t))continue;
  if(in_array($t[0],[T_FUNCTION, defined('T_FN') ? T_FN : -1],true)) {
   $pos=$i+1;while($pos<$count && $tokens[$pos]!=='(' && $tokens[$pos]!==';' && $tokens[$pos]!=='{')$pos++;
   if($pos>=$count||$tokens[$pos]!=='(')continue;
   $depth=0;
   for($j=$pos;$j<$count;$j++) {
    if($tokens[$j]==='(')$depth++;
    elseif($tokens[$j]===')') { $depth--;if($depth===0)break; }
    elseif($depth>0&&is_array($tokens[$j])&&$tokens[$j][0]===T_VARIABLE)$parameters[$tokens[$j][1]]=true;
   }
  }
  if($t[0]===T_GLOBAL) {
    for($j=$i+1;$j<$count && $tokens[$j]!==';';$j++)if(is_array($tokens[$j])&&$tokens[$j][0]===T_VARIABLE)$globals[$tokens[$j][1]]=true;
  }
 }
 foreach($tokens as $idx=>$t) {
  if(!is_array($t)||$t[0]!==T_VARIABLE)continue;
  if(in_array($t[1],['$this','$GLOBALS','$_GET','$_POST','$_REQUEST','$_SERVER','$_COOKIE','$_FILES','$_SESSION','$_ENV','$argc','$argv'],true))continue;
  $name=$t[1];$new=snake($name);
  if($name===$new)continue;
  $locally[$name]=($locally[$name]??0)+1;
 }
 $replacements=[];
 foreach($locally as $name=>$usecount){
  $new=snake($name);
  if(isset($parameters[$name])||isset($globals[$name]) || preg_match('/'.preg_quote($name,'/').'(?![a-zA-Z0-9_])/', $testCorpus)){$grand['skipped_parameters']+=$usecount;continue;}
  if(isset($existing[$new])){$grand['skipped_collisions']+=$usecount;continue;}
  $bare=substr($name,1);
  if(str_contains($src,"'".$bare."'") || str_contains($src,'"'.$bare.'"') || preg_match('/\$\$'.preg_quote($bare,'/').'\b/', $src)){$grand['skipped_literals']+=$usecount;continue;}
  if (preg_match('/\b(?:private|protected|public|var|readonly)\s+(?:(?:static|readonly|[\\a-z_][\\a-z0-9_|?]*)\s+)*'.preg_quote($name,'/').'\b/i',$src))continue;
  $replacements[$name]=$new;
 }
 if(!$replacements)continue;
 foreach($tokens as &$t) {
  if(is_array($t)&&$t[0]===T_VARIABLE&&isset($replacements[$t[1]]))$t[1]=$replacements[$t[1]];
 }unset($t);
 $new_src='';foreach($tokens as $t)$new_src.=is_array($t)?$t[1]:$t;
 if($new_src===$src)continue;
 if(!is_array(token_get_all($new_src)))throw new RuntimeException('token parse failed');
 $occ=0;foreach($locally as $name=>$count)if(isset($replacements[$name]))$occ+=$count;
 $grand['files']++;$grand['variables']+=count($replacements);$grand['occurrences']+=$occ;
 echo str_replace($directory.'/','',$file->getPathname()).' vars='.count($replacements).' occurrences='.$occ.' names='.implode(',',array_keys($replacements))."\n";
 if(!$dry)file_put_contents($file->getPathname(),$new_src);
}
echo 'TOTAL '.json_encode($grand)."\n";
