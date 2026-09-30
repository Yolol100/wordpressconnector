<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['wpconnector_transients'] = array();
$GLOBALS['wpconnector_jwks'] = array();
$GLOBALS['wpconnector_mailbox_main_sha'] = '53aaaa8b9a0d15bc25931f51dadc9951e85451b2';

class WP_REST_Request
{
    private array $headers;
    public function __construct(array $headers) { $this->headers = $headers; }
    public function get_header($name) { return $this->headers[strtolower((string) $name)] ?? ''; }
}
function rest_url($path = ''): string { return 'https://example.com/wp-json/' . ltrim((string) $path, '/'); }
function get_transient($key) { return $GLOBALS['wpconnector_transients'][$key] ?? false; }
function set_transient($key, $value, $ttl): bool { $GLOBALS['wpconnector_transients'][$key] = $value; return true; }
function wp_safe_remote_get($url, $args = array()) { if (false !== strpos((string)$url,'/commits/main')) { return array('response'=>array('code'=>200),'body'=>json_encode(array('sha'=>$GLOBALS['wpconnector_mailbox_main_sha']))); } return array('response'=>array('code'=>200),'body'=>json_encode(array('keys'=>$GLOBALS['wpconnector_jwks']))); }
function is_wp_error($value): bool { return false; }
function wp_remote_retrieve_response_code($response): int { return (int)($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body($response): string { return (string)($response['body'] ?? ''); }
function get_users($args = array()): array { return array(1); }
function apply_filters($name, $value) { return $value; }
function user_can($userId, $capability): bool { return 1 === (int)$userId && 'manage_options' === $capability; }
function current_user_can($capability): bool { return true; }

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/Policy.php';
require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Security/GitHubOidc.php';

use Webactueel\WordPressConnector\Security\GitHubOidc;
use Webactueel\WordPressConnector\Security\Policy;

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
$auth=new GitHubOidc();$jwt=$token();$userId=$auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$jwt)));
if(1!==$userId||!Policy::publicRepositoryContext()){fwrite(STDERR,"Valid GitHub OIDC token was not accepted.\n");exit(1);}
try{$auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$jwt)));fwrite(STDERR,"Replayed token accepted.\n");exit(1);}catch(RuntimeException $e){if(false===strpos($e->getMessage(),'already used'))throw $e;}
try{$auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$token(array('aud'=>'https://evil.example/wp-json/webactueel-wordpress-connector/v1')))));fwrite(STDERR,"Wrong audience accepted.\n");exit(1);}catch(RuntimeException $e){if(false===strpos($e->getMessage(),'audience'))throw $e;}
$mailboxClaims=array(
    'repository'=>'Yolol100/Leadscanner',
    'repository_id'=>'1334704263',
    'repository_owner_id'=>'22932777',
    'actor_id'=>'22932777',
    'repository_visibility'=>'public',
    'ref'=>'refs/heads/main',
    'workflow_ref'=>'Yolol100/Leadscanner/.github/workflows/mailbox-execute.yml@refs/heads/main',
    'event_name'=>'issues',
    'runner_environment'=>'github-hosted',
    'sha'=>$GLOBALS['wpconnector_mailbox_main_sha'],
);
$mailboxJwt=$token($mailboxClaims);
if(1!==$auth->authenticateMailboxExecutor(new WP_REST_Request(array('x-webactueel-github-oidc'=>$mailboxJwt)))){fwrite(STDERR,"Valid mailbox executor token was not accepted.\n");exit(1);}
try{$auth->authenticateMailboxExecutor(new WP_REST_Request(array('x-webactueel-github-oidc'=>$token(array_merge($mailboxClaims,array('workflow_ref'=>'Yolol100/Leadscanner/.github/workflows/other.yml@refs/heads/main'))))));fwrite(STDERR,"Wrong mailbox workflow accepted.\n");exit(1);}catch(RuntimeException $e){if(false===strpos($e->getMessage(),'workflow_ref'))throw $e;}
try{$auth->authenticateMailboxExecutor(new WP_REST_Request(array('x-webactueel-github-oidc'=>$token(array_merge($mailboxClaims,array('sha'=>'1111111111111111111111111111111111111111'))))));fwrite(STDERR,"Stale mailbox workflow SHA accepted.\n");exit(1);}catch(RuntimeException $e){if(false===strpos($e->getMessage(),'sha'))throw $e;}
try{$auth->authenticate(new WP_REST_Request(array('x-webactueel-github-oidc'=>$token($mailboxClaims))));fwrite(STDERR,"Mailbox executor token gained normal connector access.\n");exit(1);}catch(RuntimeException $e){if(false===strpos($e->getMessage(),'repository'))throw $e;}
echo "github oidc contract OK\n";
