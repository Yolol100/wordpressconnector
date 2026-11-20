<?php
/**
 * Permission boundary for Gutenberg block mutations, without WordPress bootstrap.
 */
declare(strict_types=1);

class WP_Post {
	public int $ID;
	public string $post_type = 'post';
	public string $post_content;

	public function __construct( int $id, string $content ) {
		$this->ID = $id;
		$this->post_content = $content;
	}
}

$GLOBALS['gutenberg_contract_posts'] = array(
	71 => new WP_Post( 71, 'Original A' ),
	72 => new WP_Post( 72, 'Original B' ),
);
$GLOBALS['gutenberg_contract_editable_ids'] = array();
$GLOBALS['gutenberg_contract_write_calls'] = 0;
$GLOBALS['gutenberg_contract_checked_ids'] = array();

function get_post( $id ) {
	return $GLOBALS['gutenberg_contract_posts'][ (int) $id ] ?? null;
}

function current_user_can( $capability, ...$args ): bool {
	if ( 'edit_post' !== $capability || count( $args ) !== 1 ) {
		return false;
	}
	$id = (int) $args[0];
	$GLOBALS['gutenberg_contract_checked_ids'][] = $id;
	return in_array( $id, $GLOBALS['gutenberg_contract_editable_ids'], true );
}

function parse_blocks( $content ): array {
	return array(
		array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerHTML'    => (string) $content,
			'innerContent' => array( (string) $content ),
			'innerBlocks'  => array(),
		),
	);
}

function serialize_blocks( $blocks ): string {
	$content = '';
	foreach ( $blocks as $block ) {
		$content .= (string) ( $block['innerHTML'] ?? '' );
	}
	return $content;
}

function wp_slash( $value ) {
	return $value;
}

function wp_update_post( $fields, $return_error = false ) {
	++$GLOBALS['gutenberg_contract_write_calls'];
	$id = (int) $fields['ID'];
	$GLOBALS['gutenberg_contract_posts'][ $id ]->post_content = (string) $fields['post_content'];
	return $id;
}

function is_wp_error( $value ): bool {
	return false;
}

$root = dirname( __DIR__ );
foreach ( array(
	'Support/Json.php',
	'Support/Fingerprint.php',
	'Support/Input.php',
	'Security/Policy.php',
	'Adapters/GutenbergAdapter.php',
) as $source ) {
	require_once $root . '/plugin/wordpressconnector/includes/' . $source;
}

$adapter = new \Webactueel\WordPressConnector\Adapters\GutenbergAdapter();
$replace = array( 'id' => 71, 'content' => 'New A' );
$patch   = array(
	'id'            => 71,
	'path'          => array( 0 ),
	'replace_block' => array(
		'blockName'    => 'core/paragraph',
		'attrs'        => array(),
		'innerHTML'    => 'New B',
		'innerContent' => array( 'New B' ),
		'innerBlocks'  => array(),
	),
);

$expect_denied = static function (string $method, array $payload, bool $dry_run) use ( $adapter ): void {
	$before_calls   = $GLOBALS['gutenberg_contract_write_calls'];
	$before_content = $GLOBALS['gutenberg_contract_posts'][ (int) $payload['id'] ]->post_content;
	try {
		$adapter->$method( $payload, array( 'dry_run' => $dry_run ) );
	} catch ( RuntimeException $error ) {
		if ( false === strpos( $error->getMessage(), 'cannot edit' ) ) {
			throw $error;
		}
		if ( $GLOBALS['gutenberg_contract_write_calls'] !== $before_calls
			|| $GLOBALS['gutenberg_contract_posts'][ (int) $payload['id'] ]->post_content !== $before_content ) {
			throw new RuntimeException( 'Denied Gutenberg action changed content or called a writer.' );
		}
		return;
	}
	throw new RuntimeException( 'Gutenberg ' . $method . ' accepted a post without edit_post permission.' );
};

foreach ( array( true, false ) as $dry_run ) {
	$expect_denied( 'replace', $replace, $dry_run );
	$expect_denied( 'patch', $patch, $dry_run );
}

// Editing a different post never grants permission for this target.
$GLOBALS['gutenberg_contract_editable_ids'] = array( 72 );
$expect_denied( 'replace', $replace, false );
$expect_denied( 'patch', $patch, false );
$GLOBALS['gutenberg_contract_editable_ids'] = array( 71 );

$dry = $adapter->replace( $replace, array( 'dry_run' => true ) );
if ( $GLOBALS['gutenberg_contract_write_calls'] !== 0
	|| $GLOBALS['gutenberg_contract_posts'][71]->post_content !== 'Original A'
	|| ! isset( $dry['_current_fingerprint'] ) ) {
	throw new RuntimeException( 'Authorized Gutenberg dry run changed content.' );
}
$written = $adapter->replace( $replace, array( 'dry_run' => false ) );
if ( $GLOBALS['gutenberg_contract_posts'][71]->post_content !== 'New A'
	|| $GLOBALS['gutenberg_contract_write_calls'] !== 1
	|| ( $written['_rollback']['payload']['content'] ?? '' ) !== 'Original A' ) {
	throw new RuntimeException( 'Authorized Gutenberg replace or rollback contract regressed.' );
}
$patched = $adapter->patch( $patch, array( 'dry_run' => false ) );
if ( $GLOBALS['gutenberg_contract_posts'][71]->post_content !== 'New B'
	|| $GLOBALS['gutenberg_contract_write_calls'] !== 2
	|| ( $patched['_rollback']['payload']['content'] ?? '' ) !== 'New A' ) {
	throw new RuntimeException( 'Authorized Gutenberg patch or rollback contract regressed.' );
}
// A malformed path must never get coerced to block zero.
$invalid_paths = array(
	array( 'not-a-number' ),
	array( true ),
	array( false ),
	array( 0.6 ),
	array( -1 ),
	array( '01' ),
	array( '1e3' ),
	array( '922337203685477580700000' ),
	array( null ),
	array( array( 0 ) ),
	array( 2 => 0 ),
	array(),
);
foreach ( $invalid_paths as $invalid_path ) {
	foreach ( array( true, false ) as $dry_run ) {
		$unsafe         = $patch;
		$unsafe['path'] = $invalid_path;
		$write_count    = $GLOBALS['gutenberg_contract_write_calls'];
		$content_before = $GLOBALS['gutenberg_contract_posts'][71]->post_content;
		try {
			$adapter->patch( $unsafe, array( 'dry_run' => $dry_run ) );
			throw new RuntimeException( 'Malformed path unexpectedly reached a Gutenberg block.' );
		} catch ( RuntimeException $error ) {
			if ( false === strpos( $error->getMessage(), 'payload.path' )
				&& false === strpos( $error->getMessage(), 'path indexes' ) ) {
				throw $error;
			}
		}
		if ( $GLOBALS['gutenberg_contract_write_calls'] !== $write_count
			|| $GLOBALS['gutenberg_contract_posts'][71]->post_content !== $content_before ) {
			throw new RuntimeException( 'Malformed Gutenberg path modified post content.' );
		}
	}
}
// Preserve JSON clients sending canonical digit strings as block indexes.
$numeric_string_path         = $patch;
$numeric_string_path['path'] = array( '0' );
$preview = $adapter->patch( $numeric_string_path, array( 'dry_run' => true ) );
if ( ( $preview['path'] ?? null ) !== array( 0 )
	|| $GLOBALS['gutenberg_contract_write_calls'] !== 2
	|| $GLOBALS['gutenberg_contract_posts'][71]->post_content !== 'New B' ) {
	throw new RuntimeException( 'Canonical numeric-string block paths must remain compatible.' );
}

// Reject non-string Gutenberg content rather than silently converting
// numbers, booleans, arrays or objects into new stored markup.
$bad_content_values = array( true, 42, 2.5, array( 'text' ), (object) array( 'text' => 'wrong' ) );
foreach ( $bad_content_values as $bad_content ) {
	foreach ( array( true, false ) as $dry_run ) {
		$bad_replace = array( 'id' => 71, 'content' => $bad_content );
		$bad_patch   = $patch;
		unset( $bad_patch['replace_block'] );
		$bad_patch['innerHTML'] = $bad_content;
		$before_calls          = $GLOBALS['gutenberg_contract_write_calls'];
		$before_content        = $GLOBALS['gutenberg_contract_posts'][71]->post_content;
		foreach ( array(
			array( 'replace', $bad_replace, 'payload.content' ),
			array( 'patch', $bad_patch, 'payload.innerHTML' ),
		) as $case ) {
			try {
				$adapter->{$case[0]}( $case[1], array( 'dry_run' => $dry_run ) );
				throw new RuntimeException( 'Non-string Gutenberg content was accepted.' );
			} catch ( RuntimeException $error ) {
				if ( false === strpos( $error->getMessage(), $case[2] ) ) {
					throw $error;
				}
			}
			if ( $GLOBALS['gutenberg_contract_write_calls'] !== $before_calls
				|| $GLOBALS['gutenberg_contract_posts'][71]->post_content !== $before_content ) {
				throw new RuntimeException( 'Rejected Gutenberg content modified a post.' );
			}
		}
	}
}

// Reject malformed target IDs before capability checks or get_post(). In
// particular, "(int) '71extra'" must not silently target post 71.
$invalid_ids = array(
	true, false, 71.5, -71, 0, '0', '-71', '71extra', '7.1e1',
	'71.0', ' 71', "71\n", '999999999999999999999999999999',
	array( 71 ), (object) array( 'id' => 71 ), null,
);
foreach ( $invalid_ids as $bad_id ) {
	foreach ( array( 'inspect', 'replace', 'patch' ) as $method ) {
		foreach ( array( true, false ) as $dry_run ) {
			$payload       = 'replace' === $method ? $replace : $patch;
			$payload['id'] = $bad_id;
			$write_count   = $GLOBALS['gutenberg_contract_write_calls'];
			$post_content  = $GLOBALS['gutenberg_contract_posts'][71]->post_content;
			try {
				$adapter->$method( $payload, array( 'dry_run' => $dry_run ) );
				throw new RuntimeException( 'Malformed Gutenberg target ID was accepted.' );
			} catch ( RuntimeException $error ) {
				if ( false === strpos( $error->getMessage(), 'Gutenberg post id' ) ) {
					throw $error;
				}
			}
			if ( $GLOBALS['gutenberg_contract_write_calls'] !== $write_count
				|| $GLOBALS['gutenberg_contract_posts'][71]->post_content !== $post_content ) {
				throw new RuntimeException( 'Rejected Gutenberg target changed post content.' );
			}
		}
	}
}
// Keep the JSON string-ID contract and ordinary decimal zero-padding.
$string_target        = $replace;
$string_target['id']  = '00071';
$string_preview       = $adapter->replace( $string_target, array( 'dry_run' => true ) );
if ( 71 !== ( $string_preview['post_id'] ?? 0 )
	|| 2 !== $GLOBALS['gutenberg_contract_write_calls']
	|| 'New B' !== $GLOBALS['gutenberg_contract_posts'][71]->post_content ) {
	throw new RuntimeException( 'Valid numeric string post IDs no longer resolve to the same post.' );
}

if ( ! in_array( 71, $GLOBALS['gutenberg_contract_checked_ids'], true ) ) {
	throw new RuntimeException( 'Gutenberg permission check did not bind to the target post ID.' );
}
echo "Gutenberg post editing permission contract OK\n";
