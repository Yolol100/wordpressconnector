<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['wpconnector_transients'] = array();
$GLOBALS['wpconnector_jwks'] = array();

class WP_REST_Request
{
    private array $headers;
    public function __construct(array $headers) { $this->headers = $headers; }
    public function get_header($name) { return $this->headers[strtolower((string) $name)] ?? ''; }
}
$GLOBALS['wpconnector_base_url'] = 'https://example.com';
$GLOBALS['wpconnector_blog_id'] = 1;
$GLOBALS['wpconnector_site_options'] = array();
function rest_url($path = ''): string { return $GLOBALS['wpconnector_base_url'] . '/wp-json/' . ltrim((string) $path, '/'); }
function get_option($key, $default = false) { return $GLOBALS['wpconnector_site_options'][$GLOBALS['wpconnector_blog_id']][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $old=get_option($key,null); $GLOBALS['wpconnector_site_options'][$GLOBALS['wpconnector_blog_id']][$key]=$value; return $old!==$value; }
function delete_option($key) { unset($GLOBALS['wpconnector_site_options'][$GLOBALS['wpconnector_blog_id']][$key]); return true; }
function get_transient($key) { return $GLOBALS['wpconnector_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl): bool { $GLOBALS['wpconnector_transients'][$key] = $value; return true; }
function wp_safe_remote_get($url, $args = array()) { return array('response'=>array('code'=>200),'body'=>json_encode(array('keys'=>$GLOBALS['wpconnector_jwks']))); }
function is_wp_error($value): bool { return false; }
function wp_remote_retrieve_response_code($response): int { return (int)($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body($response): string { return (string)($response['body'] ?? ''); }
function get_users($args = array()): array { return array(1); }
function apply_filters($name, $value) { return $value; }
function user_can($userId, $capability): bool { return 1 === (int)$userId && 'manage_options' === $capability; }
$GLOBALS['admin_activation_context'] = true;
$GLOBALS['admin_activation_user_id'] = 1;
$GLOBALS['can_activate_plugins'] = true;
function is_admin(): bool { return $GLOBALS['admin_activation_context']; }
function get_current_user_id(): int { return $GLOBALS['admin_activation_user_id']; }
function current_user_can($capability): bool {
    if ('activate_plugins' === $capability) return $GLOBALS['can_activate_plugins'];
    return true;
}

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/SitePairing.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/GitHubOidc.php';

use Webactueel\WordPressConnector\Security\GitHubOidc;
use Webactueel\WordPressConnector\Security\Policy;
use Webactueel\WordPressConnector\Security\SitePairing;

if (!function_exists('openssl_pkey_new')) { echo "github oidc contract skipped: OpenSSL unavailable\n"; exit(0); }
$key=openssl_pkey_new(array('private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA));
if(false===$key){fwrite(STDERR,"Could not create test RSA key.\n");exit(1);}
$details=openssl_pkey_get_details($key);
if(!is_array($details)||!isset($details['rsa']['n'],$details['rsa']['e'])){fwrite(STDERR,"Could not read test RSA details.\n");exit(1);}
$b64url=static function(string $value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');};
$GLOBALS['wpconnector_jwks']=array(array('kty'=>'RSA','alg'=>'RS256','use'=>'sig','kid'=>'test-key','n'=>$b64url($details['rsa']['n']),'e'=>$b64url($details['rsa']['e'])));
$token=static function(array $overrides=array())use($key,$b64url):string{
    $now=time();$header=array('alg'=>'RS256','typ'=>'JWT','kid'=>'test-key');
    $claims=array_merge(array(
        'iss'=>'https://token.actions.githubusercontent.com','aud'=>'https://example.com/wp-json/webactueel-wordpress-connector/v1','exp'=>$now+300,'iat'=>$now,'nbf'=>$now-1,'jti'=>bin2hex(random_bytes(16)),
        'repository'=>'Yolol100/wordpressconnector','repository_id'=>'1341990468','repository_owner_id'=>'22932777','repository_visibility'=>'public','ref'=>'refs/heads/main',
        'workflow_ref'=>'Yolol100/wordpressconnector/.github/workflows/wordpress-zero-config-execute.yml@refs/heads/main','event_name'=>'workflow_dispatch','runner_environment'=>'github-hosted',
    ),$overrides);
    $segments=array($b64url(json_encode($header,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),$b64url(json_encode($claims,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)));
    $input=implode('.',$segments);if(!openssl_sign($input,$signature,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('Could not sign test JWT.');
    return $input.'.'.$b64url($signature);
};
$auth=new GitHubOidc();
$jwt=$token();
// Installing the plugin alone must never grant remote administrator access.
try { $auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$jwt))); throw new RuntimeException('Unpaired install accepted GitHub OIDC.'); }
catch (RuntimeException $e) { if (strpos($e->getMessage(),'not approved') === false) throw $e; }
// Uploading PHP files alone is not a grant, but normal WordPress admin
// activation is the single explicit site-owner action required.
SitePairing::approveOnActivation(false);
if (SitePairing::pairedAdministratorId() !== 1) throw new RuntimeException('Admin activation did not approve trusted GitHub OIDC.');
SitePairing::disable();
$GLOBALS['admin_activation_context'] = false;
SitePairing::approveOnActivation(false);
if (SitePairing::pairedAdministratorId() !== 0) throw new RuntimeException('CLI activation silently approved site.');
$GLOBALS['admin_activation_context'] = true;
$GLOBALS['can_activate_plugins'] = false;
SitePairing::approveOnActivation(false);
if (SitePairing::pairedAdministratorId() !== 0) throw new RuntimeException('Unprivileged activation approved site.');
$GLOBALS['can_activate_plugins'] = true;
SitePairing::approveOnActivation(true);
if (SitePairing::pairedAdministratorId() !== 0) throw new RuntimeException('Network activation automatically approved every site.');
$GLOBALS['admin_activation_user_id'] = 0;
SitePairing::approveOnActivation(false);
if (SitePairing::pairedAdministratorId() !== 0) throw new RuntimeException('Missing authenticated administrator was approved.');
$GLOBALS['admin_activation_user_id'] = 1;
SitePairing::enableForAdministrator(1);
if (SitePairing::pairedAdministratorId() !== 1) throw new RuntimeException('Site approval not retained.');
$GLOBALS['wpconnector_blog_id'] = 2;
if (SitePairing::pairedAdministratorId() !== 0) throw new RuntimeException('Pairing crossed multisite blog boundary.');
$GLOBALS['wpconnector_blog_id'] = 1;
$GLOBALS['wpconnector_base_url'] = 'https://moved.example.com';
if (SitePairing::pairedAdministratorId() !== 0) throw new RuntimeException('Pairing survived site URL change.');
$GLOBALS['wpconnector_base_url'] = 'https://example.com';
$userId=$auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$jwt)));
if(1!==$userId||!Policy::publicRepositoryContext()){fwrite(STDERR,"Valid GitHub OIDC token was not accepted.\n");exit(1);}
try{$auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$jwt)));fwrite(STDERR,"Replayed token accepted.\n");exit(1);}catch(RuntimeException $e){if(false===strpos($e->getMessage(),'already used'))throw $e;}
try{$auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$token(array('aud'=>'https://evil.example/wp-json/webactueel-wordpress-connector/v1')))));fwrite(STDERR,"Wrong audience accepted.\n");exit(1);}catch(RuntimeException $e){if(false===strpos($e->getMessage(),'audience'))throw $e;}

// The canonical connector repository may be private while retaining exactly
// the same repository ID and pinned trusted workflow. Private mode must be
// derived from a verified GitHub visibility claim, never a request flag.
$sameRepoPrivateJwt=$token(array('repository_visibility'=>'private'));
$sameRepoPrivateId=$auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$sameRepoPrivateJwt)));
if(1!==$sameRepoPrivateId||Policy::publicRepositoryContext()){
    fwrite(STDERR,"Canonical private GitHub OIDC claim did not enter private policy mode.\\n");exit(1);
}
Policy::assertActionAllowed(array(
    'name'=>'user.create',
    'mutation'=>true,
    'privileged'=>true,
    'sensitive'=>true,
    'capability'=>'manage_options',
),false,true);
try {
    $auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$token(array(
        'repository_visibility'=>'private',
        'workflow_ref'=>'Yolol100/wordpressconnector/.github/workflows/another.yml@refs/heads/main',
    )))));
    fwrite(STDERR,"Private canonical repository accepted an untrusted workflow.\\n");exit(1);
} catch (RuntimeException $e) {
    if (false===strpos($e->getMessage(),'workflow_ref')) throw $e;
}
$restoredPublicId=$auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$token())));
if(1!==$restoredPublicId||!Policy::publicRepositoryContext()){
    fwrite(STDERR,"Public canonical repo must retain public safety mode.\\n");exit(1);
}
try {
    Policy::assertActionAllowed(array('name'=>'site.identity.inspect','sensitive'=>true),true,true);
    fwrite(STDERR,"Public canonical repository exposed sensitive actions.\\n");exit(1);
} catch (RuntimeException $e) {
    if(false===strpos($e->getMessage(),'Sensitive actions are blocked')) throw $e;
}

$privateJwt=$token(array(
    'repository'=>'Yolol100/Wordpress',
    'repository_id'=>'933904076',
    'repository_visibility'=>'private',
    'workflow_ref'=>'Yolol100/Wordpress/.github/workflows/wordpressconnector-zero-config-execute.yml@refs/heads/main',
));
$privateUserId=$auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$privateJwt)));
if(1!==$privateUserId||Policy::publicRepositoryContext()){fwrite(STDERR,"Trusted private control-plane token was not accepted as private runtime.\n");exit(1);}
Policy::assertActionAllowed(array(
    'name'=>'user.create',
    'mutation'=>true,
    'privileged'=>true,
    'sensitive'=>true,
    'capability'=>'manage_options',
), false, true);

try{
    $auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$token(array(
        'repository'=>'Yolol100/Wordpress',
        'repository_id'=>'933904076',
        'repository_visibility'=>'public',
        'workflow_ref'=>'Yolol100/Wordpress/.github/workflows/wordpressconnector-zero-config-execute.yml@refs/heads/main',
    )))));
    fwrite(STDERR,"Private control-plane accepted public visibility.\n");exit(1);
}catch(RuntimeException $e){if(false===strpos($e->getMessage(),'visibility'))throw $e;}

try{
    $auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$token(array(
        'repository'=>'Yolol100/Other',
        'repository_id'=>'999999999',
        'repository_visibility'=>'private',
        'workflow_ref'=>'Yolol100/Other/.github/workflows/wordpressconnector-zero-config-execute.yml@refs/heads/main',
    )))));
    fwrite(STDERR,"Untrusted repository token was accepted.\n");exit(1);
}catch(RuntimeException $e){if(false===strpos($e->getMessage(),'repository'))throw $e;}


SitePairing::disable();
if (SitePairing::pairedAdministratorId() !== 0) throw new RuntimeException('Revoked OIDC pairing remained active.');
try { $auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$token()))); throw new RuntimeException('Revoked OIDC authentication succeeded.'); }
catch (RuntimeException $e) { if (strpos($e->getMessage(),'not approved') === false) throw $e; }

echo "github oidc contract OK\n";
