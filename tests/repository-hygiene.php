<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$forbidden = array(
    'bootstrap-manifest.json',
    '.github/workflows/bootstrap-materialize.yml',
    '.github/workflows/package-wordpressconnector-temp.yml',
    '.github/workflows/wordpress-execute.yml',
    'schemas/request.schema.json',
    'schemas/result.schema.json',
);
foreach ($forbidden as $path) {
    if (file_exists($root . '/' . $path)) { fwrite(STDERR,"Forbidden legacy/temporary residue: {$path}\n"); exit(1); }
}
$requiredTransport = array(
    '.github/workflows/wordpress-request.yml',
    '.github/workflows/wordpress-zero-config-execute.yml',
    'scripts/validate-request.php',
    'scripts/validate-public-request.php',
    'scripts/build-public-receipt.php',
    'tests/request-workflow-contract.php',
    'tests/execute-workflow-contract.php',
    'tests/github-oidc-contract.php',
    'tests/public-runtime-contract.php',
);
foreach ($requiredTransport as $path) {
    if (! file_exists($root . '/' . $path)) { fwrite(STDERR,"Missing guarded GitHub zero-config runtime transport source: {$path}\n"); exit(1); }
}
foreach (array('requests','results','receipts','assets/inbox','examples') as $directory) {
    $path=$root.'/'.$directory;if(!is_dir($path))continue;
    $items=array_values(array_filter(scandir($path)?:array(),static function(string $item):bool{return !in_array($item,array('.','..','.gitkeep'),true);}));
    if($items){fwrite(STDERR,"Runtime payload residue on implementation tree: {$directory}\n");exit(1);}
}
$sourceFiles=array(
    $root.'/.github/workflows/wordpress-request.yml',
    $root.'/.github/workflows/wordpress-zero-config-execute.yml',
    $root.'/scripts/validate-request.php',
    $root.'/scripts/validate-public-request.php',
    $root.'/scripts/build-public-receipt.php',
    $root.'/plugin/wordpressconnector/includes/Security/GitHubOidc.php',
);
foreach($sourceFiles as $file){$content=(string)file_get_contents($file);foreach(array('BEGIN RSA PRIVATE KEY','BEGIN OPENSSH PRIVATE KEY','wp-config.php','REST_APP_PASSWORD=','WPCONNECTOR_REST_APPLICATION_PASSWORD','WPCONNECTOR_REST_USERNAME') as $secretPattern){if(strpos($content,$secretPattern)!==false){fwrite(STDERR,"Credential/config residue detected in zero-config transport source: {$file}\n");exit(1);}}}
echo "repository zero-config hygiene OK\n";
