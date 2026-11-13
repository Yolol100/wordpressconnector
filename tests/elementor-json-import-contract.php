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
if(false!==strpos($compactSource,'update_post_meta($postId,\'_elementor_data\'')) {
    fwrite(STDERR,"Elementor JSON import must not write _elementor_data directly.\n");
    exit(1);
}
$plugin=file_get_contents(__DIR__.'/../plugin/wordpressconnector/includes/Plugin.php');
if(!is_string($plugin)||false===strpos($plugin,'(new ElementorJsonImport())->register();')) {
    fwrite(STDERR,"ElementorJsonImport is not registered by Plugin::boot().\n");
    exit(1);
}
echo "Elementor JSON import contract OK\n";
