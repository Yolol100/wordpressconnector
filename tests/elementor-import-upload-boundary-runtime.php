<?php

declare(strict_types=1);

// Negative WordPress upload boundary tests. No real HTTP file or site is used.
class WpcImportStop extends RuntimeException {
    public $status;
    public function __construct(int $status) {
        $this->status = $status;
        parent::__construct('WordPress response intercepted');
    }
}
function check_admin_referer($action) { return true; }
function wp_die($message, $title = '', $options = array()): void {
    throw new WpcImportStop((int) ($options['response'] ?? 500));
}
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string) $value)); }
function sanitize_file_name($value) { return preg_replace('/[^a-zA-Z0-9._-]/', '-', (string) $value); }
function esc_html__($value, $domain='') { return (string) $value; }
function current_user_can($cap, ...$args) { return true; }

require dirname(__DIR__) . '/plugin/wordpressconnector/includes/Admin/ElementorJsonImport.php';
$subject = new \Webactueel\WordPressConnector\Admin\ElementorJsonImport();
$probe = new ReflectionMethod($subject, 'uploadedPayload');
$probe->setAccessible(true);
$cases = array(
    array(),
    array('elementor_json' => array()),
    array('elementor_json' => array('name'=>array('doc.json'),'tmp_name'=>'/tmp/abc','error'=>0,'size'=>2)),
    array('elementor_json' => array('name'=>'doc.json','tmp_name'=>array('/tmp/abc'),'error'=>0,'size'=>2)),
    array('elementor_json' => array('name'=>'doc.json','tmp_name'=>'/tmp/abc','error'=>array(0),'size'=>2)),
    array('elementor_json' => array('name'=>'doc.json','tmp_name'=>'/tmp/abc','error'=>0,'size'=>array(2))),
    array('elementor_json' => array('name'=>'doc.json','tmp_name'=>'/tmp/abc','error'=>'not-a-number','size'=>2)),
    array('elementor_json' => array('name'=>'doc.json','tmp_name'=>'/tmp/abc','error'=>0,'size'=>'10000000')),
    array('elementor_json' => array('name'=>'doc.php','tmp_name'=>'/tmp/abc','error'=>0,'size'=>2)),
    array('elementor_json' => array('name'=>'doc.json','tmp_name'=>'/tmp/abc','error'=>0,'size'=>2)),
);
foreach ($cases as $i => $case) {
    $_FILES = $case;
    try {
        $probe->invoke($subject);
    } catch (RuntimeException $error) {
        continue;
    }
    throw new RuntimeException('Upload case ' . $i . ' bypassed validation.');
}
foreach (array(array('123'), false, 123, 'bogus', '../5') as $invalidId) {
    $_POST = array('post_id'=>$invalidId,'post_type'=>'page');
    $_FILES = array();
    try {
        $subject->handleImport();
    } catch (WpcImportStop $response) {
        if (400 === $response->status) {
            continue;
        }
        throw new RuntimeException('Unexpected HTTP status for malformed post ID.');
    }
    throw new RuntimeException('Malformed import target was not rejected.');
}
echo "Elementor upload metadata, bounds, and malformed post targets (15 negative cases) OK\n";
