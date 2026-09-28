<?php
/**
 * Static source checks that don't need a WordPress runtime:
 *  - the observer only registers a post-insert hook, never a
 *    pre-insert/validation hook that could gate or block a submission
 *  - the observer never returns false / short-circuits Fluent Forms'
 *    own validation or insertion logic
 *  - no plaintext OTP or credential-shaped values anywhere in source
 *  - the "ANAMIKA" session-only name never leaks into shipped plugin code
 */

require_once __DIR__ . '/bootstrap.php';

$plugin_root = __DIR__ . '/..';

$php_files = array_merge(
	glob( $plugin_root . '/*.php' ),
	glob( $plugin_root . '/includes/*.php' ),
	glob( $plugin_root . '/admin/*.php' )
);

$observer_src = file_get_contents( $plugin_root . '/includes/class-vaid-leads-guard-observer.php' );

// Known Fluent Forms hooks that run BEFORE/DURING submission creation
// and could be used to gate or block a lead. None of these may appear
// anywhere in v0.1 source.
$forbidden_pre_insert_hooks = array(
	'fluentform_before_insert_submission',
	'fluentform_validation_errors',
	'fluentform_submission_data',
	'fluentform_before_submission_confirmation',
);

foreach ( $forbidden_pre_insert_hooks as $hook ) {
	VAID_Test_Runner::assert_true(
		false === strpos( $observer_src, $hook ),
		"static: observer must not reference pre-insert/validation hook '{$hook}'"
	);
}

VAID_Test_Runner::assert_true(
	false !== strpos( $observer_src, 'fluentform_submission_inserted' ),
	'static: observer must register the post-insert fluentform_submission_inserted hook'
);

// The observer must never itself terminate the request or force an
// error response, since a real lead has already been saved by the
// time this code runs.
$forbidden_blocking_calls = array( 'wp_die(', 'wp_send_json_error(', 'status_header( 4', 'status_header(4' );
foreach ( $forbidden_blocking_calls as $call ) {
	VAID_Test_Runner::assert_true(
		false === strpos( $observer_src, $call ),
		"static: observer must not call '{$call}' (would interfere with an already-saved submission)"
	);
}

// No plaintext-secret-shaped constants/strings and no "ANAMIKA" leakage
// anywhere in shipped plugin code.
foreach ( $php_files as $file ) {
	$src = file_get_contents( $file );

	VAID_Test_Runner::assert_true(
		false === stripos( $src, 'ANAMIKA' ),
		'static: ' . basename( $file ) . ' must not contain the session-only codename "ANAMIKA"'
	);

	VAID_Test_Runner::assert_true(
		0 === preg_match( '/\botp\s*=\s*[\'"][0-9]{4,8}[\'"]/i', $src ),
		'static: ' . basename( $file ) . ' must not contain a hardcoded plaintext OTP-shaped value'
	);

	// v0.1 stores fingerprints only — the literal column/property names
	// "phone" / "email" as a *stored plaintext field name* should not
	// appear as a bare $wpdb->insert key outside the fingerprint helpers.
	if ( false !== strpos( $file, 'class-vaid-leads-guard-db.php' ) ) {
		VAID_Test_Runner::assert_true(
			0 === preg_match( "/'phone'\s*=>/", $src ) && 0 === preg_match( "/'email'\s*=>/", $src ),
			'static: DB layer must not define raw phone/email columns'
		);
	}
}

// No outbound network calls anywhere (v0.1 has none by design).
$network_functions = array( 'wp_remote_get(', 'wp_remote_post(', 'curl_init(', 'file_get_contents( \'http', 'fsockopen(' );
foreach ( $php_files as $file ) {
	$src = file_get_contents( $file );
	foreach ( $network_functions as $fn ) {
		VAID_Test_Runner::assert_true(
			false === strpos( $src, $fn ),
			'static: ' . basename( $file ) . " must not call '{$fn}' (v0.1 makes no network calls)"
		);
	}
}

exit( VAID_Test_Runner::summary() ? 0 : 1 );
