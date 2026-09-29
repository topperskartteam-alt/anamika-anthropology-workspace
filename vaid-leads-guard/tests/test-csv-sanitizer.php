<?php
require_once __DIR__ . '/bootstrap.php';

// --- formula-trigger characters get a defusing leading quote ---
foreach ( array( '=cmd|"/c calc"!A1', '+1+1', '-1+1', '@SUM(A1:A2)', "\ttabbed", "\rcr" ) as $dangerous ) {
	$result = VAID_Leads_Guard_Csv_Sanitizer::sanitize_cell( $dangerous );
	VAID_Test_Runner::assert_true(
		0 === strpos( $result, "'" ),
		'csv: value starting with ' . var_export( $dangerous[0], true ) . ' must be prefixed with a defusing quote'
	);
	VAID_Test_Runner::assert_equals( "'" . $dangerous, $result, 'csv: sanitized value must be exactly quote + original text' );
}

// --- ordinary values pass through untouched ---
VAID_Test_Runner::assert_equals( 'strong_mechanical_repeat', VAID_Leads_Guard_Csv_Sanitizer::sanitize_cell( 'strong_mechanical_repeat' ), 'csv: ordinary classification string must pass through unchanged' );
VAID_Test_Runner::assert_equals( 'a1b2c3d4e5', VAID_Leads_Guard_Csv_Sanitizer::sanitize_cell( 'a1b2c3d4e5' ), 'csv: hex fingerprint prefix must pass through unchanged' );

// --- non-string / empty values pass through untouched ---
VAID_Test_Runner::assert_equals( 1422, VAID_Leads_Guard_Csv_Sanitizer::sanitize_cell( 1422 ), 'csv: integer cell must pass through unchanged' );
VAID_Test_Runner::assert_equals( null, VAID_Leads_Guard_Csv_Sanitizer::sanitize_cell( null ), 'csv: null cell must pass through unchanged' );
VAID_Test_Runner::assert_equals( '', VAID_Leads_Guard_Csv_Sanitizer::sanitize_cell( '' ), 'csv: empty string must pass through unchanged' );

// --- row-level helper applies to every cell ---
$row = VAID_Leads_Guard_Csv_Sanitizer::sanitize_row( array( '=evil', 'safe', 42, '@also_evil' ) );
VAID_Test_Runner::assert_equals( "'=evil", $row[0], 'csv: sanitize_row must sanitize each dangerous cell' );
VAID_Test_Runner::assert_equals( 'safe', $row[1], 'csv: sanitize_row must leave safe cells untouched' );
VAID_Test_Runner::assert_equals( 42, $row[2], 'csv: sanitize_row must leave numeric cells untouched' );
VAID_Test_Runner::assert_equals( "'@also_evil", $row[3], 'csv: sanitize_row must sanitize every dangerous cell, not just the first' );

exit( VAID_Test_Runner::summary() ? 0 : 1 );
