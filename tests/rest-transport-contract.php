<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/plugin/wordpressconnector/includes/REST/Controller.php');
$assetStore = file_get_contents($root . '/plugin/wordpressconnector/includes/REST/AssetStore.php');
$settings = file_get_contents($root . '/plugin/wordpressconnector/includes/Admin/Settings.php');
$policy = file_get_contents($root . '/plugin/wordpressconnector/includes/Security/Policy.php');
$oidc = file_get_contents($root . '/plugin/wordpressconnector/includes/Security/GitHubOidc.php');
$bootstrap = file_get_contents($root . '/plugin/wordpressconnector/wordpressconnector.php');
foreach (array('controller'=>$controller,'asset store'=>$assetStore,'settings'=>$settings,'policy'=>$policy,'oidc'=>$oidc,'bootstrap'=>$bootstrap) as $name=>$contents) {
    if (false === $contents) { fwrite(STDERR,"Unable to read REST {$name}.\n"); exit(1); }
}
$required = array(
    "register_rest_route(self::NAMESPACE, '/presence'",
    "register_rest_route(self::NAMESPACE, '/health'",
    "register_rest_route(self::NAMESPACE, '/assets'",
    "register_rest_route(self::NAMESPACE, '/execute'",
    "'permission_callback' => array(\$this, 'authorize')",
    "'permission_callback' => array(\$this, 'allowPresence')",
    "current_user_can('manage_options')",
    'is_ssl()',
    'new GitHubOidc()',
    'wp_set_current_user($userId)',
    'get_json_params()',
    'strlen($body)>self::MAX_REQUEST_BYTES',
    'Request::fromArray($data)',
    "'transport'=>'rest'",
    "'zero_config'=>true",
);
foreach ($required as $needle) {
    if (strpos(str_replace(' ', '', $controller), str_replace(' ', '', $needle)) === false) {
        fwrite(STDERR,"Missing zero-config REST controller contract: {$needle}\n"); exit(1);
    }
}
if (strpos($controller, "get_option('wpconnector_rest_enabled'") !== false) { fwrite(STDERR,"REST transport still depends on a checkbox.\n"); exit(1); }
foreach (array('Zero-config runtime bridge.','no connector checkboxes','Short-lived GitHub Actions OIDC token','Persistent connector secrets','None required') as $needle) {
    if (strpos($settings,$needle)===false) { fwrite(STDERR,"Missing zero-config settings contract: {$needle}\n"); exit(1); }
}
foreach (array('register_setting(','renderCheckbox','options.php','wpconnector_allow_writes','WPCONNECTOR_REST_APPLICATION_PASSWORD','WPCONNECTOR_SITE_URL') as $needle) {
    if (strpos($settings,$needle)!==false) { fwrite(STDERR,"Manual setup remains in settings: {$needle}\n"); exit(1); }
}
foreach (array('AUTO_ENABLED_FLAGS',"'WPCONNECTOR_ALLOW_WRITES'",'Sensitive actions require confirm=true','setPublicRepositoryContext','public_repository_safe') as $needle) {
    if (strpos($policy,$needle)===false) { fwrite(STDERR,"Missing zero-config policy boundary: {$needle}\n"); exit(1); }
}
foreach (array("private const ISSUER = 'https://token.actions.githubusercontent.com'","private const JWKS_URL = 'https://token.actions.githubusercontent.com/.well-known/jwks'","private const REPOSITORY = 'Yolol100/wordpressconnector'","private const REPOSITORY_ID = '1341990468'","private const REPOSITORY_OWNER_ID = '22932777'",'wordpress-zero-config-execute.yml@refs/heads/main',"private const HEADER = 'x-webactueel-github-oidc'",'openssl_verify(','wp_safe_remote_get(self::JWKS_URL','assertNotReplayed',"'workflow_dispatch'","'github-hosted'") as $needle) {
    if (strpos($oidc,$needle)===false) { fwrite(STDERR,"Missing GitHub OIDC boundary: {$needle}\n"); exit(1); }
}
foreach (array('is_uploaded_file($tmpName)','filesize($tmpName)','allowedFileType($relativePath)','get_allowed_mime_types()','MAX_FILES = 10','MAX_TOTAL_BYTES = 26214400','MAX_FILE_BYTES = 20971520','realpath($root)','sys_get_temp_dir()','assertOutsideWebRoot') as $needle) {
    if (strpos($assetStore,$needle)===false) { fwrite(STDERR,"Missing REST asset security contract: {$needle}\n"); exit(1); }
}
if (strpos($controller,'__return_true')!==false) { fwrite(STDERR,"REST routes must not use __return_true.\n"); exit(1); }
foreach (array('eval(','shell_exec(','passthru(','proc_open(','popen(') as $primitive) {
    if (strpos($controller,$primitive)!==false || strpos($assetStore,$primitive)!==false || strpos($oidc,$primitive)!==false) { fwrite(STDERR,"Forbidden execution primitive: {$primitive}\n"); exit(1); }
}
foreach (array("'includes/REST/Controller.php'","'includes/REST/AssetStore.php'","'includes/Security/GitHubOidc.php'") as $needle) {
    if (strpos($bootstrap,$needle)===false) { fwrite(STDERR,"Bootstrap missing dependency: {$needle}\n"); exit(1); }
}
echo "REST zero-config transport contract OK\n";
