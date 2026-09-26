<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Adapters;

use RuntimeException;
use Webactueel\WordPressConnector\Admin\ElementorJsonExport;
use Webactueel\WordPressConnector\Admin\ElementorJsonImport;
use Webactueel\WordPressConnector\Runtime\Registry;
use Webactueel\WordPressConnector\Support\Fingerprint;
use Webactueel\WordPressConnector\Support\Input;

final class ElementorJsonAdapter
{
    public function register(Registry $registry): void
    {
        $registry->register(
            'elementor.json_export',
            array($this, 'export'),
            array(
                'privileged' => true,
                'sensitive' => true,
                'description' => 'Export one Elementor page, post or saved template as canonical Elementor JSON through the shared export service.',
            )
        );
        $registry->register(
            'elementor.json_import',
            array($this, 'import'),
            array(
                'mutation' => true,
                'privileged' => true,
                'sensitive' => true,
                'description' => 'Import canonical Elementor JSON into a new draft or an existing target through the shared document API/readback/rollback service.',
            )
        );
    }

    public function export(array $payload, array $context = array()): array
    {
        $postId = isset($payload['id']) ? (int) $payload['id'] : 0;
        if ($postId < 1) {
            throw new RuntimeException('elementor.json_export requires payload.id.');
        }
        $includeSiteParts = Input::bool($payload, 'include_site_parts');
        $document = (new ElementorJsonExport())->exportDocument($postId, $includeSiteParts);

        return array(
            'document' => $document,
            'fingerprint' => Fingerprint::make($document),
        );
    }

    public function import(array $payload, array $context): array
    {
        if (! isset($payload['document']) || ! is_array($payload['document'])) {
            throw new RuntimeException('elementor.json_import requires payload.document.');
        }

        $postType = isset($payload['post_type']) ? sanitize_key((string) $payload['post_type']) : 'page';
        $postId = isset($payload['id']) ? (int) $payload['id'] : 0;

        return (new ElementorJsonImport())->importDocument(
            $payload['document'],
            $postType,
            $postId,
            $context
        );
    }
}
