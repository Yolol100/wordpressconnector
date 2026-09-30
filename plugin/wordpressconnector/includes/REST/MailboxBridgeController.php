<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\REST;

use RuntimeException;
use Throwable;
use Webactueel\WordPressConnector\Runtime\MailboxBridgeStore;
use Webactueel\WordPressConnector\Security\GitHubOidc;

final class MailboxBridgeController
{
    private const NAMESPACE = 'webactueel-wordpress-connector/v1';
    private const MAX_RESULT_BYTES = 4194304;
    private MailboxBridgeStore $store;

    public function __construct(MailboxBridgeStore $store) { $this->store = $store; }
    public function register(): void { add_action('rest_api_init', array($this,'registerRoutes')); }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/mailbox/requests/(?P<request_id>[A-Za-z0-9][A-Za-z0-9._-]{7,99})', array(
            'methods'=>\WP_REST_Server::READABLE,'callback'=>array($this,'requestForExecutor'),'permission_callback'=>array($this,'authorizeExecutor'),
        ));
        register_rest_route(self::NAMESPACE, '/mailbox/results/(?P<request_id>[A-Za-z0-9][A-Za-z0-9._-]{7,99})', array(
            'methods'=>\WP_REST_Server::CREATABLE,'callback'=>array($this,'storeExecutorResult'),'permission_callback'=>array($this,'authorizeExecutor'),
        ));
    }

    public function authorizeExecutor(\WP_REST_Request $request)
    {
        if (! is_ssl()) { return new \WP_Error('wpconnector_https_required','Mailbox bridge requires HTTPS.',array('status'=>403)); }
        try { $userId=(new GitHubOidc())->authenticateMailboxExecutor($request); }
        catch (RuntimeException $error) { return new \WP_Error('wpconnector_mailbox_oidc_rejected','Mailbox executor authentication was rejected.',array('status'=>401)); }
        if ($userId<=0) { return new \WP_Error('wpconnector_mailbox_auth_required','Mailbox executor authentication is required.',array('status'=>401)); }
        wp_set_current_user($userId);
        if (! current_user_can('manage_options')) { return new \WP_Error('wpconnector_mailbox_forbidden','Administrator capability is required.',array('status'=>403)); }
        return true;
    }

    public function requestForExecutor(\WP_REST_Request $request)
    {
        try {
            $id=(string)$request->get_param('request_id');
            $record=$this->store->getRequest($id);
            return new \WP_REST_Response(array('request_id'=>$id,'request'=>$record['request'],'sha256'=>$record['sha256'],'expires_at'=>$record['expires_at']),200);
        } catch (Throwable $error) {
            return new \WP_Error('wpconnector_mailbox_request_unavailable',$error->getMessage(),array('status'=>404));
        }
    }

    public function storeExecutorResult(\WP_REST_Request $request)
    {
        $body=(string)$request->get_body();
        if (''===$body||strlen($body)>self::MAX_RESULT_BYTES) {
            return new \WP_Error('wpconnector_mailbox_result_size','Mailbox result is empty or exceeds 4 MiB.',array('status'=>413));
        }
        $data=$request->get_json_params();
        if (!is_array($data)) {
            return new \WP_Error('wpconnector_mailbox_result_json','Mailbox result must be a JSON object.',array('status'=>400));
        }
        try {
            $id=(string)$request->get_param('request_id');
            $stored=$this->store->putResult($id,$data);
            return new \WP_REST_Response(array('ok'=>true,'stored'=>$stored),201);
        } catch (Throwable $error) {
            return new \WP_Error('wpconnector_mailbox_result_rejected',$error->getMessage(),array('status'=>400));
        }
    }
}
