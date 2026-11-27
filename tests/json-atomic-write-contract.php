<?php

declare(strict_types=1);

namespace Webactueel\WordPressConnector\Support {
    function file_put_contents($path, $data, $flags = 0) {
        ++$GLOBALS['json_writer']['writes'];
        switch ($GLOBALS['json_writer']['fault']) {
            case 'write_false': return false;
            case 'write_throw': throw new \RuntimeException('fixture write failure');
            case 'write_short': return \file_put_contents($path, substr($data, 0, 5), $flags);
        }
        return \file_put_contents($path, $data, $flags);
    }
    function rename($from, $to) {
        ++$GLOBALS['json_writer']['renames'];
        if ('rename_false' === $GLOBALS['json_writer']['fault']) return false;
        if ('rename_throw' === $GLOBALS['json_writer']['fault']) throw new \RuntimeException('fixture rename failure');
        return \rename($from, $to);
    }
    function tempnam($directory, $prefix) {
        ++$GLOBALS['json_writer']['temp_files'];
        if ('temp_false' === $GLOBALS['json_writer']['fault']) return false;
        $path = \tempnam('temp_fallback' === $GLOBALS['json_writer']['fault'] ? \sys_get_temp_dir() : $directory, $prefix);
        if (false !== $path) $GLOBALS['json_writer']['created'][] = $path;
        return $path;
    }
    function file_get_contents($path) {
        if ('read_false' === ($GLOBALS['json_writer']['fault'] ?? '')) return false;
        return \file_get_contents($path);
    }
    function unlink($path) {
        if (!empty($GLOBALS['json_writer']['cleanup_fail'])) return false;
        switch ($GLOBALS['json_writer']['cleanup_mode'] ?? '') {
            case 'true_without_removal': return true;
            case 'throw_before_removal': throw new \RuntimeException('fixture cleanup failure');
            case 'false_after_removal': \unlink($path); return false;
            case 'throw_after_removal': \unlink($path); throw new \RuntimeException('fixture cleanup reported failure after removal');
        }
        return \unlink($path);
    }
}
namespace {
    require_once ($argv[1] ?? dirname(__DIR__) . '/plugin/wordpressconnector') . '/includes/Support/Json.php';
    use Webactueel\WordPressConnector\Support\Json;

    function wp_mkdir_p($path): bool { return 'directory_failure' !== ($GLOBALS['json_writer']['fault'] ?? '') && (is_dir($path) || mkdir($path,0700,true)); }
    $root = sys_get_temp_dir() . '/wpconnector-json-' . bin2hex(random_bytes(8));
    if (!mkdir($root,0700)) throw new RuntimeException('Could not create test directory.');
    $target = $root . '/result.json';
    $prior = '{"unchanged":true}';
    $checks = 0;
    $failures = array();
    $assert = static function(bool $ok, string $label) use (&$checks, &$failures): void {
        ++$checks;
        if (!$ok) $failures[]=$label;
    };
    $reset = static function(string $fault = '') use ($target, $prior): void {
        $GLOBALS['json_writer'] = array('fault'=>$fault, 'writes'=>0, 'renames'=>0, 'temp_files'=>0, 'created'=>array(), 'cleanup_fail'=>false);
        file_put_contents($target,$prior);
    };
    $clean = static function(): void {
        foreach ($GLOBALS['json_writer']['created'] as $path) if (is_file($path)) \unlink($path);
    };
    try {
        foreach (array('write_false','write_short','write_throw','rename_false','rename_throw','temp_false','temp_fallback') as $fault) {
            $reset($fault);
            $error = null;
            try { Json::writeFileAtomic($target,array('complete'=>'new output')); }
            catch (Throwable $caught) { $error = $caught; }
            $assert($error instanceof Throwable, $fault . ': must report failure');
            $assert(file_get_contents($target) === $prior, $fault . ': original target must be untouched');
            $assert(count(array_filter($GLOBALS['json_writer']['created'],'is_file')) === 0, $fault . ': no temporary residue');
            if (strpos($fault,'write_') === 0) $assert($GLOBALS['json_writer']['renames'] === 0, $fault . ': must not rename incomplete file');
            $clean();
        }
        $reset();
        $error=null;
        try { Json::writeFileAtomic($target,array('invalid_utf8'=>"\xB1\x31")); }
        catch (Throwable $caught) { $error=$caught; }
        $assert($error instanceof Throwable && file_get_contents($target) === $prior, 'Encode failure preserves target');
        $assert($GLOBALS['json_writer']['temp_files'] === 0 && $GLOBALS['json_writer']['writes'] === 0, 'Encode before creating temporary files');
        $assert(count(array_filter($GLOBALS['json_writer']['created'],'is_file')) === 0, 'Encode failure creates no residue');
        $clean();

        $reset('write_false'); $GLOBALS['json_writer']['cleanup_fail']=true;
        $error=null;
        try { Json::writeFileAtomic($target,array('valid'=>'data')); }
        catch (Throwable $caught) { $error=$caught; }
        $assert($error instanceof Throwable && strpos($error->getMessage(),'manual cleanup') !== false, 'Cleanup failure must be explicit');
        $assert(file_get_contents($target) === $prior, 'Cleanup failure preserves target');
        $GLOBALS['json_writer']['cleanup_fail']=false; $clean();

        // Cleanup success must be verified by absence, not a backend return value.
        foreach (array('true_without_removal', 'throw_before_removal', 'false_after_removal', 'throw_after_removal') as $mode) {
            $reset('write_false');
            $GLOBALS['json_writer']['cleanup_mode'] = $mode;
            $error = null;
            try { Json::writeFileAtomic($target, array('valid'=>'data')); }
            catch (Throwable $caught) { $error = $caught; }
            $residue = in_array($mode, array('true_without_removal', 'throw_before_removal'), true);
            $assert($error instanceof RuntimeException, $mode . ': write failure remains explicit');
            $assert($error instanceof RuntimeException && false !== strpos($error->getMessage(), $residue ? 'manual cleanup' : 'Could not write complete output file'), $mode . ': cleanup classification matches readback');
            $assert(file_get_contents($target) === $prior, $mode . ': prior target untouched');
            $assert(count(array_filter($GLOBALS['json_writer']['created'], 'is_file')) === ($residue ? 1 : 0), $mode . ': actual residue matches outcome');
            $clean();
        }

        // Diagnostics must not copy a filesystem path into caller-visible text.
        $opaque = 'qa_private_json_path_85b3';
        foreach (array('missing', 'read_false', 'directory_failure') as $fault) {
            $reset($fault);
            $error = null;
            try {
                if ('missing' === $fault) {
                    Json::readFile($root . '/' . $opaque . '.json');
                } elseif ('read_false' === $fault) {
                    Json::readFile($target);
                } else {
                    Json::writeFileAtomic($root . '/' . $opaque . '/output.json', array('ok'=>true));
                }
            } catch (RuntimeException $caught) { $error = $caught; }
            $expected = array('missing'=>'JSON file is not readable', 'read_false'=>'Could not read JSON file', 'directory_failure'=>'Could not create output directory')[$fault];
            $assert($error instanceof RuntimeException && false !== strpos($error->getMessage(), $expected), $fault . ': recognizable diagnostic');
            $assert($error instanceof RuntimeException && false === strpos($error->getMessage(), $opaque) && false === strpos($error->getMessage(), $root), $fault . ': private path is not reflected');
            $assert(file_get_contents($target) === $prior, $fault . ': prior target untouched');
        }

        $reset();
        $data=array('text'=>'hello','list'=>array(1,2),'object'=>(object)array('label'=>'safe'));
        Json::writeFileAtomic($target,$data);
        $assert(file_get_contents($target) === Json::encode($data) . PHP_EOL, 'Success preserves exact encoded bytes');
        $assert($GLOBALS['json_writer']['writes'] === 1 && $GLOBALS['json_writer']['renames'] === 1, 'One successful write and rename');
        $assert(count(array_filter($GLOBALS['json_writer']['created'],'is_file')) === 0, 'Success creates no temporary residue');
        $assert(Json::decode('[{"id":"element"}]') === array(array('id'=>'element')), 'Elementor JSON lists remain supported');
        $assert(Json::decode('{"version":1}') === array('version'=>1), 'JSON object decoding remains supported');
        foreach(array('null','0','false','"text"') as $scalar) {
            $rejected=false; try { Json::decode($scalar); } catch(RuntimeException $e) { $rejected=true; }
            $assert($rejected,'Scalar root is rejected: ' . $scalar);
        }
        if ($failures) throw new RuntimeException(implode("\n",$failures));
        echo "PASS $checks atomic JSON write and root-shape assertions\n";
    } finally {
        $GLOBALS['json_writer']['cleanup_fail']=false;
        $clean();
        if (is_file($target)) unlink($target);
        rmdir($root);
    }
}
