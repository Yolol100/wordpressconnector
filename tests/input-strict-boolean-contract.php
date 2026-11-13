<?php

declare(strict_types=1);

use Webactueel\WordPressConnector\Support\Input;

require_once dirname(__DIR__) . '/plugin/wordpressconnector/includes/Support/Input.php';

$cases = array(
    array('input' => array(), 'default' => false, 'expected' => false),
    array('input' => array(), 'default' => true, 'expected' => true),
    array('input' => array('flag' => true), 'default' => false, 'expected' => true),
    array('input' => array('flag' => false), 'default' => true, 'expected' => false),
);

foreach ($cases as $case) {
    if (Input::bool($case['input'], 'flag', $case['default']) !== $case['expected']) {
        throw new RuntimeException('Strict boolean input/default behavior changed.');
    }
}

foreach (array(0, 1, '0', '1', 'true', '', null, array()) as $invalid) {
    try {
        Input::boolValue($invalid, 'flag');
    } catch (RuntimeException $error) {
        if ('flag must be boolean.' !== $error->getMessage()) {
            throw new RuntimeException('Strict boolean error message changed.');
        }
        continue;
    }
    throw new RuntimeException('Non-boolean input passed strict validation.');
}

echo "strict boolean input contract OK\n";
