<?php
declare(strict_types=1);

// Standalone contracts do not bootstrap WordPress. Model its UTF-8 HTML
// text escaping for fixture values; do not substitute this in production.
if (!function_exists('esc_html')) {
    function esc_html($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}
