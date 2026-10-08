<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Runtime;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Deterministic, content-free error guidance. Never copies exception messages,
 * request payloads, credentials or URLs into diagnostic metadata.
 */
final class Diagnostics
{
    public static function classify(string $message, bool $mutating = false): array
    {
        $msg = strtolower(substr($message, 0, 1000));
        $category = 'unknown_failure';
        $resolution = 'Inspect the request-correlated, access-controlled WordPress and transport logs.';

        if (self::has($msg, array('http 401', 'authentication is required', 'not authenticated', 'oidc authentication'))) {
            $category = 'authentication_required';
            $resolution = 'Verify the existing HTTPS authentication and OIDC trust configuration; do not bypass authentication.';
        } elseif (self::has($msg, array('http 403', 'forbidden', 'lacks permission', 'lacks the required', 'not allowed', 'disabled by server policy', 'privileged actions are blocked'))) {
            $category = 'permission_denied';
            $resolution = 'Check WordPress capabilities and the hosting or WAF access policy; keep authorization enabled.';
        } elseif (self::has($msg, array('fingerprint does not match', 'fingerprint mismatch', 'state token', 'stale state', 'changed since', 'changed after', 'request branch moved'))) {
            $category = 'stale_state';
            $resolution = 'Read the current state, repeat the dry-run and use the new fingerprint before any write.';
        } elseif (self::has($msg, array('another connector mutation', 'already in progress', 'locked by another'))) {
            $category = 'mutation_busy';
            $resolution = 'Check the existing request_id outcome and wait for the active mutation to finish before retrying that same request_id.';
        } elseif (self::has($msg, array('already used for a different mutation', 'already exists', 'ownership conflict', 'conflicting change'))) {
            $category = 'conflict';
            $resolution = 'Resolve request or resource ownership; do not overwrite or choose another identifier blindly.';
        } elseif (self::has($msg, array('timed out', 'timeout', 'http 429', 'http 502', 'http 503', 'http 504', 'rate limit', 'could not resolve host', 'connection refused'))) {
            $category = 'transient_transport';
            $resolution = $mutating
                ? 'Reconcile the original request_id and server state before any retry; the write outcome may be unknown.'
                : 'Retry this read with backoff after checking the network and provider status.';
        } elseif (self::has($msg, array('not active', 'not installed', 'missing dependency', 'not available', 'not supported', 'requires an active'))) {
            $category = 'dependency_unavailable';
            $resolution = 'Inspect the installed plugin version and required feature; do not guess an alternative API.';
        } elseif (self::has($msg, array('unknown connector action', 'unsupported field', 'invalid ', 'requires ', 'must be ', 'not a valid', 'is required'))) {
            $category = 'invalid_request';
            $resolution = 'Correct the request using the advertised action schema and run a dry-run first.';
        }

        return array(
            'code' => $category,
            'retryable_read' => ! $mutating && 'transient_transport' === $category,
            'reconcile_before_retry' => $mutating,
            'next_step' => $resolution,
        );
    }

    private static function has(string $message, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (false !== strpos($message, $needle)) {
                return true;
            }
        }
        return false;
    }
}
