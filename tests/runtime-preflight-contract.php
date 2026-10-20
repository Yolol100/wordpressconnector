<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once __DIR__ . '/source-token-contract-helper.php';
$system = file_get_contents($root . '/plugin/wordpressconnector/includes/Adapters/SystemAdapter.php');
$runner = file_get_contents($root . '/plugin/wordpressconnector/includes/Runtime/Runner.php');

if (false === $system || false === $runner) {
    fwrite(STDERR, "Unable to read runtime preflight sources.\n");
    exit(1);
}

$systemTokens = wpconn_source_tokens($system);
$doctorOk = "\$checks['ok'] = \$checks['wordpress_bootstrap'] && \$checks['database'];";
if (strpos($systemTokens, wpconn_source_fragment($doctorOk)) === false) {
    fwrite(STDERR, "system.doctor must base universal runtime health on WordPress bootstrap and database access.\n");
    exit(1);
}
if (strpos($systemTokens, wpconn_source_fragment("\$checks['ok'] = \$checks['wordpress_bootstrap'] && \$checks['database'] && \$checks['wp_cli'];")) !== false) {
    fwrite(STDERR, "system.doctor must not require optional WP-CLI for REST runtime health.\n");
    exit(1);
}
if (1 !== preg_match("/'wp_cli'\\s*=>\\s*defined\\s*\\(\\s*'WP_CLI'\\s*\\)\\s*&&\\s*WP_CLI/", $system)) {
    fwrite(STDERR, "system.doctor must retain WP-CLI as an informational capability.\n");
    exit(1);
}

if (strpos($runner, "{7,99}\\z/'") === false) {
    fwrite(STDERR, "connector.rollback request_id must use an absolute regex end boundary.\n");
    exit(1);
}
if (strpos($runner, "{7,99}$/'") !== false) {
    fwrite(STDERR, "connector.rollback still contains a newline-tolerant request_id boundary.\n");
    exit(1);
}
foreach (array("^[a-f0-9]{64}\\z/", "[A-Za-z0-9._-]{7,99}\\z/") as $needle) {
    if (strpos($runner, $needle) === false) {
        fwrite(STDERR, "Strict Runner boundary missing: {$needle}\n");
        exit(1);
    }
}

echo "runtime preflight contract OK\n";
