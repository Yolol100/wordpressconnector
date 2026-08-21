<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Runtime;

use Webactueel\WordPressConnector\Security\Policy;

final class Result
{
    public static function success(Request $request, array $data, array $meta = array()): array
    {
        return Policy::redact(array(
            'version' => 1,
            'ok' => true,
            'request_id' => $request->id(),
            'action' => $request->action(),
            'dry_run' => $request->dryRun(),
            'completed_at_gmt' => gmdate('c'),
            'data' => $data,
            'meta' => $meta,
        ));
    }

    public static function failure(Request $request, string $message, array $meta = array()): array
    {
        return Policy::redact(array(
            'version' => 1,
            'ok' => false,
            'request_id' => $request->id(),
            'action' => $request->action(),
            'dry_run' => $request->dryRun(),
            'completed_at_gmt' => gmdate('c'),
            'error' => $message,
            'meta' => $meta,
        ));
    }
}
