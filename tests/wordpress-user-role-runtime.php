<?php

// Only run in a disposable WordPress/MySQL test instance as --user=admin.
if (! defined('ABSPATH') || ! class_exists('Webactueel\WordPressConnector\Adapters\SystemAdapter')) {
    throw new RuntimeException('Connector must be active on a real WordPress instance.');
}
if (! current_user_can('manage_options') || ! current_user_can('create_users')) {
    throw new RuntimeException('Test administrator is not authenticated.');
}

$userName = 'wpconn_qa_' . strtolower(wp_generate_password(10, false, false));
$userId = wp_create_user($userName, wp_generate_password(32, true, true), $userName . '@example.test');
if (is_wp_error($userId)) throw new RuntimeException('Could not create disposable QA account.');
$roleName = 'wpconn_qa_' . strtolower(wp_generate_password(10, false, false));
$createdRole = false;
try {
    $user = get_user_by('id', (int) $userId);
    $user->set_role('subscriber');
    $user->add_role('editor');
    $adapter = new \Webactueel\WordPressConnector\Adapters\SystemAdapter();
    $preview = $adapter->userUpdate(array('id'=>(int)$userId,'remove_roles'=>array('editor')),array('dry_run'=>true));
    if (($preview['after']['roles'] ?? null) !== array('subscriber')) throw new RuntimeException('Real WordPress role preview was incorrect.');
    $before = get_user_by('id',(int)$userId)->roles;
    if ($before !== array('subscriber','editor')) throw new RuntimeException('Dry-run changed real WordPress roles.');
    $changed = $adapter->userUpdate(array('id'=>(int)$userId,'remove_roles'=>array('editor')),array('dry_run'=>false));
    if (($changed['after']['roles'] ?? null) !== array('subscriber')) throw new RuntimeException('Real WordPress write/readback mismatch.');
    $restore = $changed['_rollback'] ?? null;
    if (! is_array($restore) || ($restore['action']??null) !== 'user.update') throw new RuntimeException('Rollback receipt missing.');
    $adapter->userUpdate($restore['payload'], array('dry_run'=>false));
    if (get_user_by('id',(int)$userId)->roles !== array('subscriber','editor')) throw new RuntimeException('Real WordPress rollback lost a role.');

    if (! add_role($roleName, 'Connector QA Role', array('read'=>true))) throw new RuntimeException('Could not create disposable QA role.');
    $createdRole = true;
    $rejected = false;
    try {
        $adapter->roleUpdate(array('role'=>$roleName,'add'=>array('edit_posts'=>true,'manage_options'=>'invalid_bool')),array('dry_run'=>false));
    } catch (RuntimeException $expected) {
        $rejected = true;
    }
    if (! $rejected || get_role($roleName)->capabilities !== array('read'=>true)) {
        throw new RuntimeException('Invalid role grant caused partial database writes.');
    }
    $adapter->roleUpdate(array('role'=>$roleName,'add'=>array('edit_posts'=>true)),array('dry_run'=>false));
    if ((get_role($roleName)->capabilities['edit_posts'] ?? null) !== true) throw new RuntimeException('Valid role grant failed.');
    // Exercise the actual REST and MCP routes, including their permission
    // callbacks, with synthetic data in this disposable WordPress database.
    $adminId = get_current_user_id();
    $oldHttps = $_SERVER['HTTPS'] ?? null;
    $oldPublicContext = \Webactueel\WordPressConnector\Security\Policy::publicRepositoryContext();
    $optionName = 'wpconnector_qa_' . bin2hex(random_bytes(6));
    $fixtureData = array('object' => (object) array('api_key' => 'synthetic-private-value', 'visible' => 'kept'));
    if (!add_option($optionName, $fixtureData, '', false)) throw new RuntimeException('Could not create synthetic redaction option.');
    try {
        $_SERVER['HTTPS'] = 'on';
        \Webactueel\WordPressConnector\Security\Policy::setPublicRepositoryContext(false);
        $restRequest = new WP_REST_Request('POST', '/webactueel-wordpress-connector/v1/execute');
        $restRequest->set_header('content-type', 'application/json');
        $restRequest->set_body(wp_json_encode(array('version'=>1, 'request_id'=>$optionName.'-rest', 'action'=>'option.get', 'payload'=>array('name'=>$optionName), 'dry_run'=>true, 'confirm'=>false)));
        $restResult = rest_do_request($restRequest);
        $restData = $restResult->get_data();
        if (200 !== $restResult->get_status() || true !== ($restData['ok'] ?? null)
            || '[redacted]' !== ($restData['data']['value']['object']->api_key ?? null)
            || 'kept' !== ($restData['data']['value']['object']->visible ?? null)
            || false !== strpos(wp_json_encode($restData), 'synthetic-private-value')) {
            throw new RuntimeException('Real WordPress REST output did not redact nested object data.');
        }
        foreach (array('2025-11-25','2026-07-28') as $protocol) {
            $mcpRequest = new WP_REST_Request('POST', '/webactueel-wordpress-connector/v1/mcp');
            $mcpRequest->set_header('content-type','application/json');
            $mcpRequest->set_header('mcp-protocol-version',$protocol);
            $mcpRequest->set_header('mcp-method','tools/call');
            $mcpRequest->set_header('mcp-name','wordpress_connector_execute');
            $params = array('name'=>'wordpress_connector_execute','arguments'=>array('action'=>'option.get','payload'=>array('name'=>$optionName),'dry_run'=>true,'confirm'=>false,'request_id'=>$optionName.'-'.str_replace('-','',$protocol)));
            if ('2026-07-28' === $protocol) {
                $params['_meta'] = array('io.modelcontextprotocol/protocolVersion'=>$protocol,'io.modelcontextprotocol/clientCapabilities'=>new stdClass());
            }
            $mcpRequest->set_body(wp_json_encode(array('jsonrpc'=>'2.0','id'=>'redaction-test','method'=>'tools/call','params'=>$params)));
            $mcpResult = rest_do_request($mcpRequest);
            $mcpData = $mcpResult->get_data();
            if (200 !== $mcpResult->get_status() || !isset($mcpData['result']) || !empty($mcpData['result']['isError'])
                || false !== strpos(wp_json_encode($mcpData), 'synthetic-private-value')
                || false === strpos(wp_json_encode($mcpData), '[redacted]')) {
                throw new RuntimeException('Real WordPress MCP redaction failed for '.$protocol);
            }
        }
        wp_set_current_user((int)$userId);
        if (current_user_can('manage_options')) throw new RuntimeException('Expected non-admin test account.');
        if (403 !== rest_do_request($restRequest)->get_status() || 403 !== rest_do_request($mcpRequest)->get_status()) {
            throw new RuntimeException('REST or MCP accepted the non-admin test account.');
        }
        wp_set_current_user(0);
        if (401 !== rest_do_request($restRequest)->get_status() || 401 !== rest_do_request($mcpRequest)->get_status()) {
            throw new RuntimeException('REST or MCP accepted anonymous access.');
        }
        if (get_option($optionName)['object']->api_key !== 'synthetic-private-value') {
            throw new RuntimeException('Redaction unexpectedly mutated stored WordPress data.');
        }
        WP_CLI::success('Real WordPress REST/MCP redaction, object shape, permission denial and data preservation passed.');
    } finally {
        wp_set_current_user($adminId);
        \Webactueel\WordPressConnector\Security\Policy::setPublicRepositoryContext($oldPublicContext);
        if (null === $oldHttps) unset($_SERVER['HTTPS']); else $_SERVER['HTTPS']=$oldHttps;
        delete_option($optionName);
        if (false !== get_option($optionName,false)) throw new RuntimeException('Synthetic option cleanup failed.');
    }
    WP_CLI::success('Real WordPress user role preview/rollback and role-grant validation passed.');
} finally {
    if ($createdRole) remove_role($roleName);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    if (get_user_by('id', (int)$userId)) wp_delete_user((int)$userId);
}
