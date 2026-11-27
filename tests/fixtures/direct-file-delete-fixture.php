<?php
declare(strict_types=1);

// Only for isolated REST asset contracts, not production code.
class WP_Filesystem_Direct {
    public function __construct($unused) {}
    public function delete($path, $recursive = false, $type = false): bool {
        if ($recursive || 'f' !== $type) {
            throw new LogicException('Private file cleanup must be nonrecursive and exact.');
        }
        return \Webactueel\WordPressConnector\REST\unlink($path);
    }
}
