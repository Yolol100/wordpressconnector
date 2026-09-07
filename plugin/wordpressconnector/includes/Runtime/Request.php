<?php

declare(strict_types=1);
namespace Webactueel\WordPressConnector\Runtime;
use RuntimeException;
use Webactueel\WordPressConnector\Support\Json;
final class Request
{
    private string $id; private string $action; private array $payload; private bool $dryRun; private bool $confirm; private ?string $expectedFingerprint; private ?string $expectedStateToken;
    private function __construct(string $id,string $action,array $payload,bool $dryRun,bool $confirm,?string $expectedFingerprint,?string $expectedStateToken){$this->id=$id;$this->action=$action;$this->payload=$payload;$this->dryRun=$dryRun;$this->confirm=$confirm;$this->expectedFingerprint=$expectedFingerprint;$this->expectedStateToken=$expectedStateToken;}
    public static function fromFile(string $path): self { return self::fromArray(Json::readFile($path)); }
    public static function fromArray(array $data): self
    {
        $id=isset($data['request_id'])?(string)$data['request_id']:''; $action=isset($data['action'])?(string)$data['action']:'';
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/',$id)){throw new RuntimeException('request_id must be 8-100 safe characters.');}
        if(!preg_match('/^[a-z0-9][a-z0-9._-]*\z/',$action)){throw new RuntimeException('action is invalid.');}
        $payload=isset($data['payload'])?$data['payload']:array(); if(!is_array($payload)){throw new RuntimeException('payload must be an object.');}
        return new self($id,$action,$payload,array_key_exists('dry_run',$data)?(bool)$data['dry_run']:true,!empty($data['confirm']),self::hexGuard($data,'expected_fingerprint'),self::hexGuard($data,'expected_state_token'));
    }
    private static function hexGuard(array $data,string $key): ?string { if(!array_key_exists($key,$data)||null===$data[$key]){return null;} $value=(string)$data[$key]; if(!preg_match('/^[a-f0-9]{64}\z/',$value)){throw new RuntimeException($key.' must be a SHA-256 hex string.');} return $value; }
    public function id(): string{return $this->id;} public function action(): string{return $this->action;} public function payload(): array{return $this->payload;} public function dryRun(): bool{return $this->dryRun;} public function confirm(): bool{return $this->confirm;} public function expectedFingerprint(): ?string{return $this->expectedFingerprint;} public function expectedStateToken(): ?string{return $this->expectedStateToken;}
}
