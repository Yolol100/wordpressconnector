<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\REST;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\Request;
use Webactueel\WordPressConnector\Runtime\Runner;
use Webactueel\WordPressConnector\Security\GitHubOidc;
use Webactueel\WordPressConnector\Security\Policy;

final class Controller
{
    private const NAMESPACE = 'webactueel-wordpress-connector/v1';
    private const MAX_REQUEST_BYTES = 262144;

    private Runner $runner;
    private AssetStore $assets;

    public function __construct(Runner $runner, AssetStore $assets)
    {
        $this->runner = $runner;
        $this->assets = $assets;
    }

    public function register(): void { add_action('rest_api_init', array($this, 'registerRoutes')); }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/presence', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'presence'),
            'permission_callback' => array($this, 'allowPresence'),
        ));
        register_rest_route(self::NAMESPACE, '/health', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'health'),
            'permission_callback' => array($this, 'authorize'),
        ));
        register_rest_route(self::NAMESPACE, '/assets', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'uploadAsset'),
            'permission_callback' => array($this, 'authorize'),
            'args' => array(
                'request_id' => array('required'=>true,'type'=>'string','validate_callback'=>array($this,'validateRequestId')),
                'asset_path' => array('required'=>true,'type'=>'string','validate_callback'=>array($this,'validateAssetPath')),
            ),
        ));
        register_rest_route(self::NAMESPACE, '/execute', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'execute'),
            'permission_callback' => array($this, 'authorize'),
        ));
    }

    public function allowPresence(\WP_REST_Request $request)
    {
        if (! is_ssl()) { return new \WP_Error('wpconnector_https_required','WordPress Connector presence requires HTTPS.',array('status'=>403)); }
        return true;
    }

    public function authorize(\WP_REST_Request $request)
    {
        if (! is_ssl()) { return new \WP_Error('wpconnector_https_required','WordPress Connector REST transport requires HTTPS.',array('status'=>403)); }
        if (! is_user_logged_in()) {
            try {
                $userId=(new GitHubOidc())->authenticate($request);
                if ($userId>0) { wp_set_current_user($userId); }
            } catch (RuntimeException $error) {
                return new \WP_Error('wpconnector_oidc_rejected','GitHub OIDC authentication was rejected.',array('status'=>401));
            }
        }
        if (! is_user_logged_in()) { return new \WP_Error('wpconnector_auth_required','Authentication is required.',array('status'=>401)); }
        if (! current_user_can('manage_options')) { return new \WP_Error('wpconnector_forbidden','Administrator capability is required.',array('status'=>403)); }
        return true;
    }

    public function presence(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(array('ok'=>true,'connector'=>'webactueel-wordpress-connector','zero_config'=>true,'auth'=>'github-actions-oidc'),200);
    }

    public function health(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(array(
            'ok'=>true,'transport'=>'rest','version'=>defined('WPCONNECTOR_VERSION')?WPCONNECTOR_VERSION:null,'user_id'=>get_current_user_id(),
            'authentication'=>'wordpress-or-github-oidc',
            'gates'=>array(
                'writes'=>Policy::flag('WPCONNECTOR_ALLOW_WRITES'),
                'privileged'=>Policy::flag('WPCONNECTOR_ALLOW_PRIVILEGED'),
                'sensitive'=>Policy::flag('WPCONNECTOR_ALLOW_SENSITIVE'),
                'system_updates'=>Policy::flag('WPCONNECTOR_ALLOW_SYSTEM_UPDATES'),
                'filesystem_writes'=>Policy::flag('WPCONNECTOR_ALLOW_FILESYSTEM_WRITES'),
            ),
            'limits'=>array('request_bytes'=>self::MAX_REQUEST_BYTES,'asset_files'=>10,'asset_total_bytes'=>26214400),
        ),200);
    }

    public function uploadAsset(\WP_REST_Request $request)
    {
        $files=$request->get_file_params();
        if (!isset($files['file'])||!is_array($files['file'])) { return new \WP_Error('wpconnector_asset_missing','Multipart field "file" is required.',array('status'=>400)); }
        try {
            $this->assets->cleanupExpired();
            $stored=$this->assets->put((string)$request->get_param('request_id'),(string)$request->get_param('asset_path'),$files['file']);
            return new \WP_REST_Response(array('ok'=>true,'asset'=>$stored),201);
        } catch (Throwable $error) { return new \WP_Error('wpconnector_asset_rejected',$error->getMessage(),array('status'=>400)); }
    }

    public function execute(\WP_REST_Request $request)
    {
        $body=(string)$request->get_body();
        if (''===$body||strlen($body)>self::MAX_REQUEST_BYTES) { return new \WP_Error('wpconnector_request_size','Request JSON is empty or exceeds 256 KiB.',array('status'=>413)); }
        $data=$request->get_json_params();
        if (!is_array($data)) { return new \WP_Error('wpconnector_invalid_json','Request body must be a JSON object.',array('status'=>400)); }
        try { $connectorRequest=Request::fromArray($data); }
        catch (RuntimeException $error) { return new \WP_Error('wpconnector_invalid_request',$error->getMessage(),array('status'=>400)); }
        try {
            $this->assets->cleanupExpired();
            $result=$this->runner->run($connectorRequest,array('transport'=>'rest','authenticated_user_id'=>get_current_user_id(),'asset_root'=>$this->assets->rootForRequest($connectorRequest->id(),false)));
            return new \WP_REST_Response($result,200);
        } finally {
            try { $this->assets->cleanup($connectorRequest->id()); } catch (Throwable $cleanupError) {}
        }
    }

    public function validateRequestId($value): bool { return is_string($value)&&(bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/',$value); }
    public function validateAssetPath($value): bool
    {
        if (!is_string($value)||''===$value||strlen($value)>240||'/'===$value[0]||false!==strpos($value,'\\')) { return false; }
        foreach (explode('/',$value) as $segment) {
            if (''===$segment||'.'===$segment||'..'===$segment||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}\z/',$segment)) { return false; }
        }
        return true;
    }
}
