<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapterPath = $root . '/plugin/wordpressconnector/includes/Adapters/AcfAdapter.php';
$validatorPath = $root . '/scripts/validate-public-request.php';
$legacyValidatorPath = $root . '/scripts/validate-public-request-legacy.php';

foreach (array($adapterPath, $validatorPath, $legacyValidatorPath) as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "Missing complex ACF contract source: {$path}\n");
        exit(1);
    }
}

$adapter = (string) file_get_contents($adapterPath);
$validator = (string) file_get_contents($validatorPath);
$legacyValidator = (string) file_get_contents($legacyValidatorPath);

$requiredActions = array(
    'acf.schema.ensure_fields',
    'acf.schema.remove_fields',
    'acf.schema.create_field_group',
    'acf.schema.delete_field_group',
);
foreach ($requiredActions as $action) {
    if (false === strpos($adapter, "\$registry->register('{$action}'")) {
        fwrite(STDERR, "Missing complex ACF action registration: {$action}\n");
        exit(1);
    }
    if (false !== strpos($validator, "'{$action}'") || false !== strpos($legacyValidator, "'{$action}'")) {
        fwrite(STDERR, "Complex ACF action must remain outside the public GitHub allowlist: {$action}\n");
        exit(1);
    }
}

foreach (array(
    "'image' =>",
    "'relationship' =>",
    "'group' =>",
    "'repeater' =>",
    'normalizeSchemaFields',
    'normalizeLocationRules',
    'assertFieldTreeKeysAvailable',
    'deleteFieldTreeByKey',
    'acf_import_field_group',
    'acf_delete_field_group',
    "['_rollback'] = array(",
    "'_current_fingerprint' => Fingerprint::make",
) as $needle) {
    if (false === strpos($adapter, $needle)) {
        fwrite(STDERR, "Missing complex ACF safety/schema token: {$needle}\n");
        exit(1);
    }
}

if (false !== strpos($adapter, 'acf_update_field_group(')) {
    fwrite(STDERR, "Complex ACF schema must not rewrite existing field groups in place.\n");
    exit(1);
}

if (false === strpos($adapter, "Refusing to overwrite an existing ACF field group")) {
    fwrite(STDERR, "Field-group ownership-before-overwrite guard is missing.\n");
    exit(1);
}
if (false === strpos($adapter, "Refusing to delete an ACF field group that no longer matches the expected schema")) {
    fwrite(STDERR, "Field-group delete stale-schema guard is missing.\n");
    exit(1);
}

echo "complex ACF schema contract OK\n";
