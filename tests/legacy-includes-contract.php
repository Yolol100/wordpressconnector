<?php
declare(strict_types=1);
define('ABSPATH','/qa/');
define('DAY_IN_SECONDS',86400);
$root=dirname(__DIR__).'/plugin/wordpressconnector';
$map=array(
    'includes/Adapters/AbilitiesAdapter.php' => 'includes/Adapters/class-abilitiesadapter.php',
    'includes/Adapters/ConnectorUpdateAdapter.php' => 'includes/Adapters/class-connectorupdateadapter.php',
    'includes/Adapters/ElementorCapabilitiesAdapter.php' => 'includes/Adapters/class-elementorcapabilitiesadapter.php',
    'includes/Adapters/SystemAdapter.php' => 'includes/Adapters/class-systemadapter.php',
    'includes/Adapters/WooCommerceAdapter.php' => 'includes/Adapters/class-woocommerceadapter.php',
    'includes/Admin/ElementorJsonExport.php' => 'includes/Admin/class-elementorjsonexport.php',
    'includes/Admin/ElementorJsonImport.php' => 'includes/Admin/class-elementorjsonimport.php',
    'includes/MCP/Controller.php' => 'includes/MCP/class-controller.php',
    'includes/REST/AssetStore.php' => 'includes/REST/class-assetstore.php',
    'includes/REST/Controller.php' => 'includes/REST/class-controller.php',
    'includes/Runtime/ProcessedStore.php' => 'includes/Runtime/class-processedstore.php',
    'includes/Runtime/Runner.php' => 'includes/Runtime/class-runner.php',
    'includes/Runtime/SnapshotStore.php' => 'includes/Runtime/class-snapshotstore.php',
    'includes/Security/GitHubOidc.php' => 'includes/Security/class-githuboidc.php',
    'includes/Security/OAuthResourceServer.php' => 'includes/Security/class-oauthresourceserver.php',
    'includes/Security/Policy.php' => 'includes/Security/class-policy.php',
    'includes/Security/ReleaseApproval.php' => 'includes/Security/class-releaseapproval.php',
    'includes/Security/SitePairing.php' => 'includes/Security/class-sitepairing.php',
    'includes/Support/Input.php' => 'includes/Support/class-input.php',
    'includes/Support/Json.php' => 'includes/Support/class-json.php',
    'includes/Adapters/CodeSnippetsAdapter.php' => 'includes/Adapters/class-codesnippetsadapter.php',
    'includes/Adapters/FilesystemAdapter.php' => 'includes/Adapters/class-filesystemadapter.php',
);
$legacyFirst=($argv[1]??'')==='legacy';
$boot=file_get_contents($root.'/wordpressconnector.php');
if(!preg_match('/\$wpconnector_files\s*=\s*array\((.*?)\);/s',$boot,$match)) throw new RuntimeException('Bootstrap list missing.');
preg_match_all("/'([^']+\.php)'/",$match[1],$files);
$byCanonical=array_flip($map);
$included=0;
foreach($files[1] as $relative){
    if(!isset($byCanonical[$relative])){require_once $root.'/'.$relative;continue;}
    $old=$byCanonical[$relative];
    $legacy=$root.'/'.$old;
    $canonical=$root.'/'.$relative;
    if(!is_file($legacy)||is_link($legacy)||!is_file($canonical)||is_link($canonical))throw new RuntimeException('Missing regular include.');
    if($legacyFirst){require_once $legacy;require_once $canonical;}
    else{require_once $canonical;require_once $legacy;}
    ++$included;
}
if($included!==count($map))throw new RuntimeException('Incomplete legacy include coverage.');
$paths=array_map('realpath',get_included_files());
foreach($map as $old=>$canonical){
    if(!in_array(realpath($root.'/'.$old),$paths,true)||!in_array(realpath($root.'/'.$canonical),$paths,true))throw new RuntimeException('Include not executed.');
}
echo 'Legacy and canonical include contract OK ('.$included.' paths, '.($legacyFirst?'legacy':'canonical').' first).'.PHP_EOL;
