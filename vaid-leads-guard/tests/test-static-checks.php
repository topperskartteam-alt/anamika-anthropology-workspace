<?php
/**
 * Static source checks that don't need a WordPress runtime.
 *
 * v0.1.1 additions over v0.1.0: the forbidden-hook list now covers
 * BOTH the deprecated underscore-style AND the current slash-style
 * Fluent Forms hook names (the v0.1.0 list only checked underscore
 * names, which is itself a defect the red-team found — see
 * ARCHITECTURE.md defect table, item P0-1). Also adds a structural
 * check that on_submission_inserted()'s entire body is wrapped in a
 * single try/catch(Throwable), and that both the required v0.1.1 hook
 * registrations are present.
 */

require_once __DIR__ . '/bootstrap.php';

$plugin_root = __DIR__ . '/..';

$php_files = array_merge(
	glob( $plugin_root . '/*.php' ),
	glob( $plugin_root . '/includes/*.php' ),
	glob( $plugin_root . '/admin/*.php' )
);

$observer_src = file_get_contents( $plugin_root . '/includes/class-vaid-leads-guard-observer.php' );
$db_src       = file_get_contents( $plugin_root . '/includes/class-vaid-leads-guard-db.php' );

// Known Fluent Forms hooks that run BEFORE/DURING submission creation
// and could be used to gate or block a lead — both the current
// slash-namespaced names (FF >= 5.0) and the deprecated underscore
// names they replaced. None of these may appear anywhere in shadow-mode
// source, in either form.
$forbidden_pre_insert_hooks = array(
	'fluentform/before_insert_submission',
	'fluentform_before_insert_submission',
	'fluentform/validation_errors',
	'fluentform_validation_errors',
	'fluentform/submission_data',
	'fluentform_submission_data',
	'fluentform/before_submission_confirmation',
	'fluentform_before_submission_confirmation',
);

foreach ( $forbidden_pre_insert_hooks as $hook ) {
	VAID_Test_Runner::assert_true(
		false === strpos( $observer_src, $hook ),
		"static: observer must not reference pre-insert/validation hook '{$hook}'"
	);
}

// Both required POST-insert hooks must be registered (see observer
// docblock: v0.1.0 registered only the deprecated name, which is the
// central P0 defect this version repairs).
VAID_Test_Runner::assert_true(
	false !== strpos( $observer_src, "'fluentform/submission_inserted'" ),
	'static: observer must register the current post-insert hook fluentform/submission_inserted'
);
VAID_Test_Runner::assert_true(
	false !== strpos( $observer_src, "'fluentform_submission_inserted'" ),
	'static: observer must ALSO register the deprecated post-insert hook fluentform_submission_inserted (still live pre-FF7.0)'
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

// --- Fail-open structural check ---
// The entire body of on_submission_inserted() must be wrapped in a
// single try { ... } catch ( Throwable $e ) { ... } — Throwable, not
// just Exception, so a PHP Error (TypeError etc.) also fails open
// instead of propagating back into Fluent Forms' request lifecycle.
if ( preg_match(
	'/public function on_submission_inserted\([^)]*\)\s*\{(.*)\}\s*(?:\/\*\*|private function|\z)/s',
	$observer_src,
	$m
) ) {
	$body = $m[1];
	// Strip leading blank lines and // line-comments (the fail-open
	// rationale is documented directly above the try{} for a reader) so
	// this checks the first executable statement, not the first line.
	$body_code_only = preg_replace( '/^(\s*\/\/[^\n]*\n)+/', '', ltrim( $body ) );
	VAID_Test_Runner::assert_true(
		1 === preg_match( '/^\s*try\s*\{/', $body_code_only ),
		'static: on_submission_inserted() must open with try { as its first executable statement (comments aside)'
	);
	VAID_Test_Runner::assert_true(
		false !== strpos( $body, 'catch ( Throwable $e )' ) || false !== strpos( $body, 'catch (Throwable $e)' ),
		'static: on_submission_inserted() must catch (Throwable $e), not just Exception, to guarantee fail-open'
	);
} else {
	VAID_Test_Runner::assert_true( false, 'static: could not locate on_submission_inserted() body to structurally verify try/catch wrapping' );
}

// --- UTC time-basis consistency ---
// v0.1.0 used current_time('mysql') (site-local) for storage and bare
// `new DateTime($string)` (PHP-default-timezone) for comparison — safe
// for elapsed-time math only by coincidence of both using a consistent
// basis, and unsafe across a DST transition in general. v0.1.1 must use
// UTC explicitly and consistently in the observer.
VAID_Test_Runner::assert_true(
	false !== strpos( $observer_src, "current_time( 'mysql', true )" ),
	"static: observer must store timestamps via current_time( 'mysql', true ) (UTC), not site-local time"
);
VAID_Test_Runner::assert_true(
	false !== strpos( $observer_src, "new DateTimeZone( 'UTC' )" ),
	"static: observer must parse timestamps with an explicit UTC DateTimeZone"
);
VAID_Test_Runner::assert_true(
	0 === preg_match( "/current_time\\(\\s*'mysql'\\s*\\)/", $observer_src ),
	"static: observer must not call current_time('mysql') without the UTC flag"
);

// --- Concurrency defense: UNIQUE KEY present in schema ---
VAID_Test_Runner::assert_true(
	false !== stripos( $db_src, 'UNIQUE KEY' ) && false !== strpos( $db_src, '(form_id, entry_id)' ),
	'static: schema must define UNIQUE KEY (form_id, entry_id) so a submission observed via both hooks cannot double-insert'
);

// No plaintext-secret-shaped constants/strings and no session-codename
// leakage anywhere in shipped plugin code.
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

	if ( false !== strpos( $file, 'class-vaid-leads-guard-db.php' ) ) {
		VAID_Test_Runner::assert_true(
			0 === preg_match( "/'phone'\s*=>/", $src ) && 0 === preg_match( "/'email'\s*=>/", $src ),
			'static: DB layer must not define raw phone/email columns'
		);
	}
}

// No outbound network calls anywhere (still none by design in v0.1.1).
$network_functions = array( 'wp_remote_get(', 'wp_remote_post(', 'curl_init(', 'file_get_contents( \'http', 'fsockopen(' );
foreach ( $php_files as $file ) {
	$src = file_get_contents( $file );
	foreach ( $network_functions as $fn ) {
		VAID_Test_Runner::assert_true(
			false === strpos( $src, $fn ),
			'static: ' . basename( $file ) . " must not call '{$fn}' (v0.1.x makes no network calls)"
		);
	}
}

// Version strings must agree across the plugin header and changelog
// entry point, so a red-team reader can trust the declared version.
$main_src = file_get_contents( $plugin_root . '/vaid-leads-guard.php' );
VAID_Test_Runner::assert_true(
	false !== strpos( $main_src, "Version:           0.1.1" ) && false !== strpos( $main_src, "'0.1.1'" ),
	'static: plugin header and VAID_LEADS_GUARD_VERSION constant must both read 0.1.1'
);

exit( VAID_Test_Runner::summary() ? 0 : 1 );
