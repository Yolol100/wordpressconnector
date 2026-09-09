<?php

declare(strict_types=1);

if ($argc < 2) { fwrite(STDERR, "Usage: php scripts/validate-request.php <request.json>\n"); exit(2); }
$path=$argv[1];
if (!is_file($path)||!is_readable($path)) { fwrite(STDERR,"Request is not readable: {$path}\n"); exit(2); }
$raw=(string)file_get_contents($path);
if (''===$raw||strlen($raw)>262144) { fwrite(STDERR,"Request must be between 1 byte and 256 KiB.\n"); exit(1); }
try { $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR); } catch (JsonException $error) { fwrite(STDERR,'Invalid JSON: '.$error->getMessage()."\n"); exit(1); }
$errors=array();
if (!is_array($data)) { $errors[]='Root must be a JSON object.'; $data=array(); }
$allowed=array('version','site_url','request_id','action','dry_run','confirm','expected_fingerprint','expected_state_token','payload');
foreach(array_keys($data) as $key){ if(!is_string($key)||!in_array($key,$allowed,true))$errors[]='Unknown top-level key: '.(string)$key; }
if(!array_key_exists('version',$data)||1!==$data['version'])$errors[]='version must be integer 1.';
$siteUrl=$data['site_url']??null;
if(!is_string($siteUrl)||''===$siteUrl||strlen($siteUrl)>240||rtrim($siteUrl,'/')!==$siteUrl){$errors[]='site_url must be a canonical HTTPS URL without a trailing slash.';}else{
    $parts=parse_url($siteUrl);$scheme=is_array($parts)?(string)($parts['scheme']??''):'';$host=is_array($parts)?(string)($parts['host']??''):'';$pathPart=is_array($parts)?(string)($parts['path']??''):'';
    if('https'!==strtolower($scheme)||''===$host||filter_var($siteUrl,FILTER_VALIDATE_URL)===false)$errors[]='site_url must be a valid HTTPS URL.';
    if(is_array($parts)&&(isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])))$errors[]='site_url must not contain credentials, query parameters or fragments.';
    if(is_array($parts)&&isset($parts['port'])&&443!==(int)$parts['port'])$errors[]='site_url may only use the default HTTPS port 443.';
    if(''!==$pathPart&&!preg_match('#^/[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*$#D',$pathPart))$errors[]='site_url path contains unsupported characters.';
    if('localhost'===strtolower($host)||filter_var($host,FILTER_VALIDATE_IP)!==false)$errors[]='site_url must use a public DNS hostname, not localhost or an IP literal.';
}
$id=$data['request_id']??null;if(!is_string($id)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}$/D',$id))$errors[]='Invalid request_id.';
$action=$data['action']??null;if(!is_string($action)||!preg_match('/^[a-z0-9][a-z0-9._-]*$/D',$action))$errors[]='Invalid action.';
if(!array_key_exists('payload',$data)||!is_array($data['payload']))$errors[]='payload must be an object.';
if(array_key_exists('dry_run',$data)&&!is_bool($data['dry_run']))$errors[]='dry_run must be boolean.';
if(array_key_exists('confirm',$data)&&!is_bool($data['confirm']))$errors[]='confirm must be boolean.';
foreach(array('expected_fingerprint','expected_state_token') as $guard){if(!array_key_exists($guard,$data)||null===$data[$guard])continue;if(!is_string($data[$guard])||!preg_match('/^[a-f0-9]{64}$/D',$data[$guard]))$errors[]='Invalid '.$guard.'.';}
if($errors){foreach($errors as $error)fwrite(STDERR,$error."\n");exit(1);}echo $id.' '.$action.PHP_EOL;
