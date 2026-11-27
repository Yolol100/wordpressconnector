<?php

declare(strict_types=1);

$path=__DIR__.'/../plugin/wordpressconnector/includes/Admin/ElementorJsonImport.php';
$source=file_get_contents($path);
if(!is_string($source)||''===$source) {
    fwrite(STDERR,"ElementorJsonImport.php is missing or unreadable.\n");
    exit(1);
}
$importImplementation = file_get_contents(dirname($path) . '/trait-elementorjsonimportmethods.php');
if (!is_string($importImplementation)) { throw new RuntimeException('Missing canonical Elementor import implementation.'); }
$source .= $importImplementation;
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
$plugin=file_get_contents(__DIR__.'/../plugin/wordpressconnector/includes/class-plugin.php');
if(!is_string($plugin)||false===strpos($plugin,'( new ElementorJsonImport() )->register();')) {
    fwrite(STDERR,"ElementorJsonImport is not registered by Plugin::boot().\n");
    exit(1);
}

// The private upload parsers are intentionally not standalone HTTP entrypoints.
// Keep their only public call paths behind the action-specific nonce checks.
$singleStart = strpos($source, 'public function handleImport(): void {');
$singleNonce = false !== $singleStart ? strpos($source, 'check_admin_referer( self::ACTION );', $singleStart) : false;
$singleParser = false !== $singleNonce ? strpos($source, '$this->uploaded_payload()', $singleNonce) : false;
$bulkStart = strpos($source, 'public function preview_bulk(): void {');
$bulkNonce = false !== $bulkStart ? strpos($source, 'check_admin_referer( self::BULK_PREVIEW );', $bulkStart) : false;
$bulkCapability = false !== $bulkNonce ? strpos($source, "current_user_can( 'manage_options' )", $bulkNonce) : false;
$bulkParser = false !== $bulkCapability ? strpos($source, '$this->bulk_uploads()', $bulkCapability) : false;
$singleEnd = false !== $singleStart ? strpos($source, 'private function uploaded_payload(): array {', $singleStart) : false;
$bulkEnd = false !== $bulkStart ? strpos($source, 'public function confirm_bulk(): void {', $bulkStart) : false;
if (false === $singleNonce || false === $singleParser || false === $singleEnd
    || ! ($singleStart < $singleNonce && $singleNonce < $singleParser && $singleParser < $singleEnd)
    || false === $bulkNonce || false === $bulkCapability || false === $bulkParser || false === $bulkEnd
    || ! ($bulkStart < $bulkNonce && $bulkNonce < $bulkCapability && $bulkCapability < $bulkParser && $bulkParser < $bulkEnd)
    || 1 !== substr_count($source, '$this->uploaded_payload()')
    || 1 !== substr_count($source, '$this->bulk_uploads()')) {
    throw new RuntimeException('Upload parser has an unguarded, duplicate or relocated call site.');
}
// The raw upload metadata is parsed by type before using PHP temporary paths.
// Never run sanitize_text_field() on $_FILES tmp_name: that can alter file identity.
if (false === strpos($source, 'is_uploaded_file( $tmp_name )')
    || false === strpos($source, 'sanitize_file_name( $file[\'name\'] )')
    || false === strpos($source, 'array_keys( $files[ $field ] ) !== array_keys( $files[\'name\'] )')) {
    throw new RuntimeException('Raw upload shape or identity validation is missing.');
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
    function current_user_can(string $capability, int $postId = 0): bool {
        return 'edit_post' !== $capability || 27 !== $postId;
    }
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

// The import form must not preselect a different document when an incoming
// GET target is malformed. No file upload or post write occurs in this fixture.
if (! class_exists('WP_Post')) {
    class WP_Post {
        public $ID;
        public $post_title;
        public $post_type;
        public $post_status;
        public function __construct(int $id, string $type = 'page', string $status = 'publish') {
            $this->ID = $id;
            $this->post_title = 'Existing page';
            $this->post_type = $type;
            $this->post_status = $status;
        }
    }
}
$GLOBALS['wpconnector_import_form_reads'] = 0;
function get_posts(array $args): array {
    ++$GLOBALS['wpconnector_import_form_reads'];
    if (100 !== ($args['posts_per_page'] ?? null) ||
        array('modified' => 'DESC', 'ID' => 'DESC') !== ($args['orderby'] ?? null) ||
        !in_array($args['offset'] ?? null, array(0, 100), true)) {
        throw new RuntimeException('Import selector query is not bounded and deterministic.');
    }
    $GLOBALS['wpconnector_import_form_offsets'][] = $args['offset'];
    if (!empty($GLOBALS['wpconnector_import_form_large'])) {
        return array_map(static function(int $id): WP_Post { return new WP_Post($id); },
            range(300 - $args['offset'], 201 - $args['offset']));
    }
    return array(new WP_Post(23), new WP_Post(27));
}
function get_post(int $id) {
    switch ($id) {
        case 23: case 27: case 204: return new WP_Post($id);
        case 25: return new WP_Post($id, 'post');
        case 26: return new WP_Post($id, 'page', 'trash');
        default: return null;
    }
}
function esc_url(string $value): string { return $value; }
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function admin_url(string $path): string { return 'https://example.invalid/wp-admin/' . $path; }
function wp_nonce_field(string $action): void {}
function selected($value, $current, bool $echo = true): string {
    return $value === $current ? ' selected="selected"' : '';
}
function submit_button(string $label): void { echo '<button>' . $label . '</button>'; }
function __(string $value, string $domain): string { return $value; }

$oldGet = $_GET ?? array();
try {
    foreach (array('23junk', '23.5', '2.3e1', ' 23', "23\n", '023', '00',
        true, false, 23.5, -23, array(23), str_repeat('9', 40)) as $invalidId) {
        $_GET = array('post_id' => $invalidId);
        $reads = $GLOBALS['wpconnector_import_form_reads'];
        try {
            $import->renderImportPage('page');
            throw new LogicException('Malformed import form target unexpectedly accepted.');
        } catch (DomainException $error) {
            if (400 !== $error->getCode() || 'Invalid Elementor JSON import target.' !== $error->getMessage()) {
                throw new RuntimeException('Malformed form target received wrong rejection: ' . $error->getMessage());
            }
        }
        if ($reads !== $GLOBALS['wpconnector_import_form_reads']) {
            throw new RuntimeException('Malformed import form target reached the posts provider.');
        }
    }
    foreach (array(
        array(array(), 0),
        array(array('post_id' => '0'), 0),
        array(array('post_id' => '23'), 23),
        // Explicit targets outside the bounded recent-items query must not fall back to create.
        array(array('post_id' => '204'), 204),
    ) as $validCase) {
        $_GET = $validCase[0];
        ob_start();
        try {
            $import->renderImportPage('page');
            $html = ob_get_clean();
        } catch (Throwable $error) {
            ob_end_clean();
            throw $error;
        }
        $selectedOption = '<option value="' . $validCase[1] . '" selected="selected">';
        if (! is_string($html) || false === strpos($html, $selectedOption)) {
            throw new RuntimeException('Canonical import form target did not select the intended item: ' . $validCase[1]);
        }
        if (1 !== substr_count($html, $selectedOption) || false !== strpos($html, '<option value="27"')) {
            throw new RuntimeException('Import form rendered duplicate selection or unauthorized option.');
        }
        if ($validCase[1] > 0 && false !== strpos($html, '<option value="0" selected="selected">')) {
            throw new RuntimeException('Explicit existing target incorrectly selected create.');
        }
    }
    // A full first page must preserve the second 100 options without a duplicate
    // or additional query, and without changing explicit older-target selection.
    $GLOBALS['wpconnector_import_form_large'] = true;
    $GLOBALS['wpconnector_import_form_offsets'] = array();
    $_GET = array('post_id' => '23');
    ob_start();
    try {
        $import->renderImportPage('page');
        $html = ob_get_clean();
    } catch (Throwable $error) {
        ob_end_clean();
        throw $error;
    }
    unset($GLOBALS['wpconnector_import_form_large']);
    if (array(0, 100) !== $GLOBALS['wpconnector_import_form_offsets'] ||
        202 !== substr_count($html, '<option value="') ||
        1 !== substr_count($html, '<option value="23" selected="selected">') ||
        1 !== substr_count($html, '<option value="101"') ||
        false === strpos($html, '<option value="300"') ||
        false !== strpos($html, '<option value="100"')) {
        throw new RuntimeException('Two-page import selector lost, duplicated, or expanded its bounded options.');
    }
    // Deleted, wrong-type, trashed, and unauthorized explicit targets must stop
    // before rendering a form that could submit an unintended new draft.
    foreach (array(25 => 400, 26 => 400, 27 => 403, 28 => 400) as $id => $status) {
        $_GET = array('post_id' => (string) $id);
        ob_start();
        try {
            $import->renderImportPage('page');
            throw new LogicException('Unavailable target unexpectedly rendered an import form: ' . $id);
        } catch (DomainException $error) {
            if ($status !== $error->getCode() || '' !== ob_get_contents()) {
                throw new RuntimeException('Unavailable target did not fail closed: ' . $id);
            }
        } finally {
            ob_end_clean();
        }
    }
} finally {
    $_GET = $oldGet;
}

echo "Elementor JSON import contract OK\n";
