<?php

declare(strict_types=1);

require_once __DIR__ . '/fixtures/wordpress-escape-fixture.php';

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


// Shared post IDs must be lossless: no floats, booleans, suffixes or overflow.
foreach ( array( 71, '71', '00071' ) as $valid_id ) {
	if ( 71 !== Input::positive_id( $valid_id ) ) {
		throw new RuntimeException( 'Valid positive post ID was not normalized.' );
	}
}
foreach ( array( false, true, 71.5, 0, -1, '0', '0000', '-71', '71bad', '7.1e1', ' 71', "71\n", null, array( 71 ), '999999999999999999999999' ) as $invalid_id ) {
	try {
		Input::positive_id( $invalid_id );
		throw new RuntimeException( 'Invalid post ID was accepted.' );
	} catch ( RuntimeException $error ) {
		if ( 'Identifier must be a positive integer.' !== $error->getMessage() ) {
			throw $error;
		}
	}
}

try {
    Input::boolValue('false', '<fixture>');
    throw new RuntimeException('Invalid boolean accepted.');
} catch (RuntimeException $error) {
    if ('&lt;fixture&gt; must be boolean.' !== $error->getMessage()) throw $error;
}
echo "strict boolean input contract OK\n";
