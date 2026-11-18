<?php

declare(strict_types=1);

$path=__DIR__.'/../plugin/wordpressconnector/includes/Admin/ElementorJsonImport.php';
$source=file_get_contents($path);
if(!is_string($source)||''===$source) {
    fwrite(STDERR,"ElementorJsonImport.php is missing or unreadable.\n");
    exit(1);
}
$compactSource = preg_replace('/\\s+/', '', $source);
if (! is_string($compactSource)) { throw new RuntimeException('Could not normalize Elementor import source.'); }
$required=array("add_filter('page_row_actions'","add_filter('post_row_actions'","add_action('admin_menu'","admin_post_","Import Elementor JSON","elementor_library","MAX_BYTES","new ElementorAdapter()","replaceDocument","createDocument","Create new draft from JSON","'edit_mode'=>'builder'");
foreach($required as $needle) {
    if(false===strpos($compactSource,preg_replace('/\\s+/', '', $needle))) {
        fwrite(STDERR,"Missing Elementor JSON import contract marker: {$needle}\n");
        exit(1);
    }
}
if (
    false !== strpos($compactSource, "update_post_meta(\$postId,'_elementor_data'")
    || false !== strpos($compactSource, "update_post_meta(\$post_id,'_elementor_data'")
) {
    fwrite(STDERR,"Elementor JSON import must not write _elementor_data directly.\n");
    exit(1);
}
$plugin=file_get_contents(__DIR__.'/../plugin/wordpressconnector/includes/Plugin.php');
if(!is_string($plugin)||false===strpos($plugin,'( new ElementorJsonImport() )->register();')) {
    fwrite(STDERR,"ElementorJsonImport is not registered by Plugin::boot().\n");
    exit(1);
}

// Malformed multipart arrays must fail closed without PHP array-to-string warnings.
// The standalone contract runner has no WordPress bootstrap.
if (! function_exists('sanitize_file_name')) {
    function sanitize_file_name($name): string {
        if (! is_string($name)) { throw new RuntimeException('Upload filename must be a string.'); }
        return $name;
    }
}
// Standalone admin-post stubs: tests may invoke the handler, but never write posts.
if (! function_exists('check_admin_referer')) {
    function check_admin_referer(string $action): void {
        if ('wpconnector_import_elementor_json' !== $action) { throw new LogicException('Unexpected admin action.'); }
    }
}
if (! function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (! function_exists('sanitize_key')) {
    function sanitize_key($value): string { return is_string($value) ? strtolower($value) : ''; }
}
if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool { return true; }
}
if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain): string { return $text; }
}
if (! function_exists('esc_html')) {
    function esc_html(string $text): string { return $text; }
}
if (! function_exists('wp_die')) {
    function wp_die($message, $title = '', $args = array()): void {
        throw new DomainException((string) $message, (int) ($args['response'] ?? 0));
    }
}

require_once $path;
$import = new \Webactueel\WordPressConnector\Admin\ElementorJsonImport();
$single = new ReflectionMethod($import, 'uploaded_payload');
$bulk = new ReflectionMethod($import, 'bulk_uploads');
$single->setAccessible(true);
$bulk->setAccessible(true);
$originalFiles = $_FILES ?? array();
$cases = array(
    array('single nested filename', $single, array('elementor_json' => array('error' => 0, 'size' => 10, 'name' => array('bad.json'), 'tmp_name' => '/tmp/not-uploaded'))),
    array('single nested tmp_name', $single, array('elementor_json' => array('error' => 0, 'size' => 10, 'name' => 'bad.json', 'tmp_name' => array('/tmp/not-uploaded')))),
    array('single nested size', $single, array('elementor_json' => array('error' => 0, 'size' => array(10), 'name' => 'bad.json', 'tmp_name' => '/tmp/not-uploaded'))),
    array('bulk nested filename', $bulk, array('elementor_jsons' => array('name' => array(array('bad.json')), 'error' => array(0), 'size' => array(10), 'tmp_name' => array('/tmp/not-uploaded')))),
    array('bulk missing size', $bulk, array('elementor_jsons' => array('name' => array('bad.json'), 'error' => array(0), 'tmp_name' => array('/tmp/not-uploaded')))),
    array('bulk sparse index', $bulk, array('elementor_jsons' => array('name' => array(1 => 'bad.json'), 'error' => array(1 => 0), 'size' => array(1 => 10), 'tmp_name' => array(1 => '/tmp/not-uploaded')))),
    array('bulk string error', $bulk, array('elementor_jsons' => array('name' => array('bad.json'), 'error' => array('0'), 'size' => array(10), 'tmp_name' => array('/tmp/not-uploaded')))),
    array('bulk mismatched count', $bulk, array('elementor_jsons' => array('name' => array('one.json', 'two.json'), 'error' => array(0), 'size' => array(10, 10), 'tmp_name' => array('/tmp/one', '/tmp/two')))),
);
set_error_handler(static function(int $severity, string $message): void { throw new ErrorException($message, 0, $severity); });
try {
    foreach ($cases as $case) {
        $_FILES = $case[2];
        try {
            $case[1]->invoke($import);
            throw new RuntimeException('Unexpectedly accepted malformed upload: ' . $case[0]);
        } catch (RuntimeException $error) {
            if (false === strpos($error->getMessage(), 'upload metadata') && false === strpos($error->getMessage(), 'upload indexes')) {
                throw new RuntimeException('Wrong rejection for ' . $case[0] . ': ' . $error->getMessage());
            }
        }
    }
    // Well-formed metadata must pass the shape gate and reach the upload-file check.
    $wellFormed = array(
        array('single well-formed', $single, array('elementor_json' => array('error' => 0, 'size' => 10, 'name' => 'ok.json', 'tmp_name' => '/tmp/not-uploaded')), 'The Elementor JSON upload is invalid.'),
        array('bulk well-formed', $bulk, array('elementor_jsons' => array('name' => array('ok.json'), 'error' => array(0), 'size' => array(10), 'tmp_name' => array('/tmp/not-uploaded'))), 'Invalid uploaded file.'),
    );
    foreach ($wellFormed as $case) {
        $_FILES = $case[2];
        try {
            $case[1]->invoke($import);
            throw new RuntimeException('Unexpectedly accepted fake upload: ' . $case[0]);
        } catch (RuntimeException $error) {
            if ($case[3] !== $error->getMessage()) {
                throw new RuntimeException('Well-formed metadata was rejected too early: ' . $case[0] . ': ' . $error->getMessage());
            }
        }
    }
} finally {
    $_FILES = $originalFiles;
    restore_error_handler();
}
// Exact post identity is required before reading uploads or writing Elementor content.
$oldPost = $_POST ?? array();
$oldFiles = $_FILES ?? array();
try {
    $_FILES = array();
    $invalidIds = array(
        array(1), '-1', '1.5', '1e0', '+1', '01', ' 1',
        "1\n", str_repeat('9', 40),
    );
    foreach ($invalidIds as $invalidId) {
        $_POST = array('post_id' => $invalidId, 'post_type' => 'page');
        try {
            $import->handleImport();
            throw new LogicException('Malformed import target unexpectedly accepted.');
        } catch (DomainException $error) {
            if (400 !== $error->getCode() || 'Invalid Elementor JSON import target.' !== $error->getMessage()) {
                throw new RuntimeException('Malformed target was not rejected before writes: ' . $error->getMessage());
            }
        }
    }
    // Canonical zero/create and positive/post targets must pass the ID gate,
    // then stop at the missing upload check (no Elementor write is possible).
    foreach (array('0', '23') as $validId) {
        $_POST = array('post_id' => $validId, 'post_type' => 'page');
        try {
            $import->handleImport();
            throw new LogicException('Missing upload unexpectedly accepted.');
        } catch (DomainException $error) {
            if (400 !== $error->getCode() || 'No Elementor JSON file was uploaded.' !== $error->getMessage()) {
                throw new RuntimeException('Valid canonical target rejected too early: ' . $error->getMessage());
            }
        }
    }
} finally {
    $_POST = $oldPost;
    $_FILES = $oldFiles;
}
echo "Elementor JSON import contract OK\n";
