<?php
declare(strict_types=1);
// One-time conservative private-parameter WPCS cleanup. Never merge this helper.
$root=$argv[1]??'';
if(!is_dir($root))throw new RuntimeException('Target directory required');
function tokenText($x):string{return is_array($x)?$x[1]:$x;}
function snakify($name):string{$s=substr($name,1);$s=preg_replace('/([A-Z]+)([A-Z][a-z])/','$1_$2',$s);$s=preg_replace('/([a-z0-9])([A-Z])/','$1_$2',$s);return '$'.strtolower($s);}
$files=0;$renamed=0;$tokensChanged=0;$skipped=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS)) as $file){
 if(!$file->isFile()||!str_ends_with($file->getFilename(),'.php'))continue;
 $path=$file->getPathname();$src=file_get_contents($path);$tokens=token_get_all($src,TOKEN_PARSE);$count=count($tokens);
 for($i=0;$i<$count;$i++){
  if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
  $private=false;
  for($back=$i-1;$back>=max(0,$i-20);$back--){$b=$tokens[$back];if(is_array($b)&&$b[0]===T_PRIVATE){$private=true;break;}if(is_array($b)&&in_array($b[0],[T_PUBLIC,T_PROTECTED,T_FUNCTION],true))break;if(in_array(tokenText($b),['{','}',';'],true))break;}
  if(!$private)continue;
  $p=$i+1;while($p<$count&&tokenText($tokens[$p])!=='(')$p++;if($p>=$count)continue;
  $name='';for($q=$i+1;$q<$p;$q++)if(is_array($tokens[$q])&&$tokens[$q][0]===T_STRING)$name=$tokens[$q][1];
  if(!$name||str_starts_with($name,'__'))continue;
  if($file->getFilename()==='ElementorFormsAdapter.php' && $name==='saveDocument')continue;
  if($file->getFilename()==='ElementorAdapter.php' && $name==='snapshot')continue;
  if($file->getFilename()==='AcfAdapter.php' && $name==='groupId')continue;
  if($file->getFilename()==='AcfAdapter.php' && $name==='fieldMatchesDefinition')continue;
  if($file->getFilename()==='ConnectorUpdateAdapter.php' && $name==='checksum')continue;
  $open=$p;$depth=0;$params=[];
  for(;$p<$count;$p++){
   $v=tokenText($tokens[$p]);if($v==='(')$depth++;elseif($v===')'){if(--$depth===0)break;}
   if($depth===1&&is_array($tokens[$p])&&$tokens[$p][0]===T_VARIABLE)$params[$tokens[$p][1]]=1;
  }
  $bodyOpen=$p+1;while($bodyOpen<$count&&!in_array(tokenText($tokens[$bodyOpen]),['{',';'],true))$bodyOpen++;
  if($bodyOpen>=$count||tokenText($tokens[$bodyOpen])===';')continue;
  $depth=0;$bodyEnd=$bodyOpen;
  for(;$bodyEnd<$count;$bodyEnd++){$v=tokenText($tokens[$bodyEnd]);if($v==='{')$depth++;elseif($v==='}'&&--$depth===0)break;}
  if($bodyEnd>=$count)throw new RuntimeException('Unbalanced method '.$name);
  $existing=[];$bodyString='';$nested=false;
  for($k=$open;$k<=$bodyEnd;$k++){
   if(is_array($tokens[$k])&&in_array($tokens[$k][0],[T_FUNCTION,T_FN],true)&&$k!==$i)$nested=true;
   if(is_array($tokens[$k])&&$tokens[$k][0]===T_VARIABLE)$existing[$tokens[$k][1]]=true;
   $bodyString.=tokenText($tokens[$k]);
  }
  if($nested||preg_match('/\b(?:compact|extract|get_defined_vars|eval)\s*\(/i',$bodyString)||str_contains($bodyString,'$$')){$skipped[]=$file->getFilename().'::'.$name.':dynamic';continue;}
  $names=[];
  foreach(array_keys($params) as $old){$new=snakify($old);if($old===$new||isset($existing[$new]))continue;
   $base=substr($old,1);
   if(preg_match('/\b'.preg_quote($name,'/').'\s*\([^;{}]{0,700}\b'.preg_quote($base,'/').'\s*:/s',$src))continue;
   $names[$old]=$new;
  }
  if(!$names)continue;
  foreach($names as $old=>$new){$n=0;for($k=$open;$k<=$bodyEnd;$k++)if(is_array($tokens[$k])&&$tokens[$k][0]===T_VARIABLE&&$tokens[$k][1]===$old){$tokens[$k][1]=$new;$n++;}
    if(!$n)throw new RuntimeException('No rename performed '.$old);
    $tokensChanged+=$n;
    for($back=$i-1;$back>=max(0,$i-30);$back--){
     $b=$tokens[$back];if(!is_array($b))break;
     if(in_array($b[0],[T_WHITESPACE,T_ATTRIBUTE,T_PRIVATE,T_STATIC,T_FINAL,T_ABSTRACT],true))continue;
     if($b[0]===T_DOC_COMMENT)$tokens[$back][1]=preg_replace('/(@param\s+[^\r\n]*?)'.preg_quote($old,'/').'\b/','$1'.$new,$tokens[$back][1]);
     break;
    }
    $renamed++;
    echo $file->getFilename().'::'.$name.' '.$old.'=>'.$new.' uses='.$n."\n";
  }
 }
 $newSource='';foreach($tokens as $tok)$newSource.=tokenText($tok);
 if($newSource!==$src){$files++;file_put_contents($path,$newSource);token_get_all($newSource,TOKEN_PARSE);}
}
echo 'SUMMARY '.json_encode(['files'=>$files,'parameters'=>$renamed,'tokens'=>$tokensChanged,'skipped_methods'=>count($skipped)])."\n";
