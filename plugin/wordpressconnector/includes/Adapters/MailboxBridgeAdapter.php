<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Runtime\MailboxBridgeStore;
use Webactueel\WordPressConnector\Runtime\Registry;

final class MailboxBridgeAdapter
{
    private const ACTIONS = array(
        'list_folders','list_messages','search','read','read_attachment','read_attachment_chunk','read_body_chunk','thread',
        'create_folder','rename_folder','delete_folder','mark_read','mark_unread','flag','unflag',
        'copy','move','archive','trash','junk','delete','create_draft','replace_draft',
        'send','reply','reply_all','forward','send_draft'
    );
    private const SEND_ACTIONS = array('send','reply','reply_all','forward','send_draft');
    private MailboxBridgeStore $store;

    public function __construct(MailboxBridgeStore $store) { $this->store = $store; }

    public function register(Registry $registry): void
    {
        $registry->register('mailbox.bridge.request_put', array($this, 'putRequest'), array(
            'mutation'=>true,'privileged'=>true,'sensitive'=>true,'capability'=>'manage_options',
            'description'=>'Store one bounded temporary mailbox request for the dedicated executor.'
        ));
        $registry->register('mailbox.bridge.result_get', array($this, 'getResult'), array(
            'privileged'=>true,'sensitive'=>true,'capability'=>'manage_options',
            'description'=>'Read one bounded temporary mailbox result.'
        ));
        $registry->register('mailbox.bridge.clear', array($this, 'clear'), array(
            'mutation'=>true,'privileged'=>true,'sensitive'=>true,'capability'=>'manage_options',
            'description'=>'Delete one temporary mailbox request and result.'
        ));
    }

    public function putRequest(array $payload, array $context): array
    {
        $this->assertKeys($payload, array('request_id','request','ttl'));
        $requestId = isset($payload['request_id']) && is_string($payload['request_id']) ? $payload['request_id'] : '';
        $request = isset($payload['request']) && is_array($payload['request']) ? $payload['request'] : array();
        $ttl = isset($payload['ttl']) ? (int) $payload['ttl'] : 3600;
        $this->assertMailboxRequest($request);
        if (! empty($context['dry_run'])) {
            return array('request_id'=>$requestId,'would_store'=>true,'ttl'=>max(60,min(86400,$ttl)));
        }
        return $this->store->putRequest($requestId, $request, $ttl);
    }

    public function getResult(array $payload, array $context): array
    {
        $this->assertKeys($payload, array('request_id'));
        $requestId = isset($payload['request_id']) && is_string($payload['request_id']) ? $payload['request_id'] : '';
        return $this->store->getResult($requestId);
    }

    public function clear(array $payload, array $context): array
    {
        $this->assertKeys($payload, array('request_id'));
        $requestId = isset($payload['request_id']) && is_string($payload['request_id']) ? $payload['request_id'] : '';
        if (! empty($context['dry_run'])) { return array('request_id'=>$requestId,'would_clear'=>true); }
        $this->store->clear($requestId);
        return array('request_id'=>$requestId,'cleared'=>true);
    }

    private function assertMailboxRequest(array $request): void
    {
        $action = isset($request['action']) && is_string($request['action']) ? $request['action'] : '';
        if (! in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException('Mailbox request action is not allowed.');
        }
        if (in_array($action, self::SEND_ACTIONS, true) && true !== ($request['confirm_send'] ?? false)) {
            throw new RuntimeException('Mailbox send-like requests require confirm_send=true.');
        }
        if (in_array($action, array('delete','delete_folder','replace_draft'), true) && true !== ($request['confirm'] ?? false)) {
            throw new RuntimeException('Destructive mailbox requests require confirm=true.');
        }
    }

    private function assertKeys(array $payload, array $allowed): void
    {
        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                throw new RuntimeException('Unsupported mailbox bridge payload key: ' . (string) $key);
            }
        }
    }
}
