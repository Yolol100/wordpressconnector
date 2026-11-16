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
} finally {
    $_FILES = $originalFiles;
    restore_error_handler();
}
echo "Elementor JSON import contract OK\n";
