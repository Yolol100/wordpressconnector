<?php

declare(strict_types=1);

/**
 * Compare security-critical PHP syntax without depending on WPCS whitespace.
 * Comments and trivia are excluded, while identifiers, string contents,
 * operators and literal values remain exact. Never use this to replace
 * behavioral authorization or WordPress runtime tests.
 */
function wpconn_source_tokens(string $source): string
{
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], array(T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }
    return $out;
}

function wpconn_source_fragment(string $fragment): string
{
    return wpconn_source_tokens("<?php\n" . $fragment);
}
