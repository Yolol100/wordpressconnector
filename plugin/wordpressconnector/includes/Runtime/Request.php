<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Runtime;

use RuntimeException;
use Webactueel\WordPressConnector\Support\Json;

final class Request
{
    private string $id;
    private string $action;
    private array $payload;
    private bool $dryRun;
    private bool $confirm;
    private ?string $expectedFingerprint;
    private ?string $expectedStateToken;

    private function __construct(
        string $id,
        string $action,
        array $payload,
        bool $dryRun,
        bool $confirm,
        ?string $expectedFingerprint,
        ?string $expectedStateToken
    ) {
        $this->id = $id;
        $this->action = $action;
        $this->payload = $payload;
        $this->dryRun = $dryRun;
        $this->confirm = $confirm;
        $this->expectedFingerprint = $expectedFingerprint;
        $this->expectedStateToken = $expectedStateToken;
    }

    public static function fromFile(string $path): self
    {
        return self::fromArray(Json::readFile($path));
    }

    public static function fromArray(array $data): self
    {
        $allowed = array(
            'version',
            'request_id',
            'action',
            'dry_run',
            'confirm',
            'expected_fingerprint',
            'expected_state_token',
            'payload',
        );
        foreach (array_keys($data) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new RuntimeException('Unknown top-level request key: ' . (string) $key . '.');
            }
        }

        if (! array_key_exists('version', $data) || 1 !== $data['version']) {
            throw new RuntimeException('version must be integer 1.');
        }

        if (! array_key_exists('request_id', $data) || ! is_string($data['request_id'])) {
            throw new RuntimeException('request_id must be a string.');
        }
        $id = $data['request_id'];
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,99}\z/', $id)) {
            throw new RuntimeException('request_id must be 8-100 safe characters.');
        }

        if (! array_key_exists('action', $data) || ! is_string($data['action'])) {
            throw new RuntimeException('action must be a string.');
        }
        $action = $data['action'];
        if (! preg_match('/^[a-z0-9][a-z0-9._-]*\z/', $action)) {
            throw new RuntimeException('action is invalid.');
        }

        if (! array_key_exists('payload', $data) || ! is_array($data['payload'])) {
            throw new RuntimeException('payload must be an object.');
        }
        $payload = $data['payload'];

        $dryRun = true;
        if (array_key_exists('dry_run', $data)) {
            if (! is_bool($data['dry_run'])) {
                throw new RuntimeException('dry_run must be boolean.');
            }
            $dryRun = $data['dry_run'];
        }

        $confirm = false;
        if (array_key_exists('confirm', $data)) {
            if (! is_bool($data['confirm'])) {
                throw new RuntimeException('confirm must be boolean.');
            }
            $confirm = $data['confirm'];
        }

        return new self(
            $id,
            $action,
            $payload,
            $dryRun,
            $confirm,
            self::hexGuard($data, 'expected_fingerprint'),
            self::hexGuard($data, 'expected_state_token')
        );
    }

    private static function hexGuard(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || null === $data[$key]) {
            return null;
        }
        if (! is_string($data[$key]) || ! preg_match('/^[a-f0-9]{64}\z/', $data[$key])) {
            throw new RuntimeException($key . ' must be a SHA-256 hex string.');
        }
        return $data[$key];
    }

    public function id(): string
    {
        return $this->id;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function dryRun(): bool
    {
        return $this->dryRun;
    }

    public function confirm(): bool
    {
        return $this->confirm;
    }

    public function expectedFingerprint(): ?string
    {
        return $this->expectedFingerprint;
    }

    public function expectedStateToken(): ?string
    {
        return $this->expectedStateToken;
    }
}
