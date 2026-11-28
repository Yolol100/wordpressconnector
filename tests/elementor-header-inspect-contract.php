<?php
declare(strict_types=1);

use Webactueel\WordPressConnector\Adapters\ElementorAdapter;

final class WP_Post {
	public $post_type = 'elementor_library';
	public $post_status = 'publish';
	public $post_password = '';
}

$GLOBALS['header_post'] = new WP_Post();
$GLOBALS['header_can_edit'] = true;
$url = 'https://example.test/contact';
$item = array( 'link' => array( 'url' => $url ) );
$GLOBALS['header_meta'] = array(
	'_elementor_template_type' => 'header',
	'_elementor_edit_mode' => 'builder',
	'_elementor_data' => array(
		array(
			'id' => 'container',
			'elements' => array(
				array(
					'id' => 'singlewidget',
					'widgetType' => 'icon-list',
					'settings' => array( 'icon_list' => array( $item ) ),
				),
				array(
					'id' => 'doublewidget',
					'widgetType' => 'icon-list',
					'settings' => array( 'icon_list' => array( $item, $item, $item ) ),
				),
				array( 'id' => 'wrongwidget', 'widgetType' => 'heading' ),
			),
		),
	),
);

function get_post( $id ) {
	return 42 === $id ? $GLOBALS['header_post'] : null;
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['header_meta'][$key] ?? '';
}
function get_post_type( $id ) {
	return 'elementor_library';
}
function current_user_can( $capability, ...$args ) {
	return 'edit_post' === $capability && array( 42 ) === $args && $GLOBALS['header_can_edit'];
}
function home_url( $path = '/' ) {
	return 'https://example.test' . $path;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

$root = dirname( __DIR__ ) . '/plugin/wordpressconnector/includes';
require_once $root . '/Support/Json.php';
require_once $root . '/Support/class-fingerprint.php';
require_once $root . '/Adapters/class-elementoradapter.php';

function header_expect( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . ': ' . var_export( $actual, true ) );
	}
}

$adapter = new ElementorAdapter();
$before = $GLOBALS['header_meta'];
$inspect = static function ( array $ids ) use ( $adapter, $url ): array {
	return $adapter->inspectHeaderLinks( array(
		'post_id' => 42,
		'checks' => array_map( static function ( string $id ) use ( $url ): array {
			return array( 'element_id' => $id, 'from' => $url );
		}, $ids ),
	) );
};
$single = $inspect( array( 'singlewidget' ) );
header_expect( true, $single['eligible'], 'Single nested match must be eligible' );
header_expect( 'ready', $single['reason_code'], 'Single match reason' );
$expected_single = array( 'element_id' => 'singlewidget', 'is_icon_list' => true, 'exact_url_matches' => 1 );
$expected_missing = array( 'element_id' => 'missingwidget', 'is_icon_list' => false, 'exact_url_matches' => 0 );
$expected_double = array( 'element_id' => 'doublewidget', 'is_icon_list' => true, 'exact_url_matches' => 2 );
$expected_wrong = array( 'element_id' => 'wrongwidget', 'is_icon_list' => false, 'exact_url_matches' => 0 );
header_expect( array( $expected_single ), $single['checks'], 'Single match result' );
foreach ( array(
	array( array( 'missingwidget', 'singlewidget', 'doublewidget', 'wrongwidget' ), array( $expected_missing, $expected_single, $expected_double, $expected_wrong ) ),
	array( array( 'singlewidget', 'missingwidget' ), array( $expected_single, $expected_missing ) ),
) as $case ) {
	$result = $inspect( $case[0] );
	header_expect( false, $result['eligible'], 'Missing, duplicate, or wrong widget must be ineligible' );
	header_expect( 'widget_or_source_mismatch', $result['reason_code'], 'Mismatch reason' );
	header_expect( $case[1], $result['checks'], 'Independent ordered results' );
	header_expect( $single['document_fingerprint'], $result['document_fingerprint'], 'Stable document fingerprint' );
}
$GLOBALS['header_can_edit'] = false;
$denied = $inspect( array( 'singlewidget' ) );
header_expect( false, $denied['eligible'], 'Edit capability must remain required' );
header_expect( 'edit_post_denied', $denied['reason_code'], 'Capability denial reason' );
header_expect( array( $expected_single ), $denied['checks'], 'Read-only diagnostics are retained on denial' );
header_expect( $before, $GLOBALS['header_meta'], 'Inspection must not alter stored metadata' );
$GLOBALS['header_can_edit'] = true;
$GLOBALS['header_meta']['_elementor_template_type'] = 'footer';
header_expect( 'not_header_template', $inspect( array( 'singlewidget' ) )['reason_code'], 'Non-header target must remain ineligible' );
$GLOBALS['header_meta'] = $before;
$GLOBALS['header_post']->post_password = 'protected';
header_expect( 'not_published_elementor_library', $inspect( array( 'singlewidget' ) )['reason_code'], 'Protected target must remain ineligible' );

echo "Elementor read-only header inspection contract OK\n";
