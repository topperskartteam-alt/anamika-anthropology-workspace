<?php
/**
 * Minimal test bootstrap. VAID_LEADS_GUARD_TEST_MODE lets the pure-logic
 * classes (Normalizer, Classifier, Fingerprint, Form_Map) load without a
 * WordPress environment. DB/Observer/Admin classes are NOT loaded here —
 * they depend on $wpdb and WP hooks and are out of scope for this
 * no-WordPress test harness by design; see ARCHITECTURE.md "Testing
 * strategy".
 */

define( 'VAID_LEADS_GUARD_TEST_MODE', true );

require_once __DIR__ . '/../includes/class-vaid-leads-guard-normalizer.php';
require_once __DIR__ . '/../includes/class-vaid-leads-guard-classifier.php';
require_once __DIR__ . '/../includes/class-vaid-leads-guard-fingerprint.php';
require_once __DIR__ . '/../includes/class-vaid-leads-guard-form-map.php';

/**
 * Tiny assertion harness — deterministic, dependency-free, exits
 * non-zero on any failure so it works as a CI gate.
 */
class VAID_Test_Runner {
	private static $pass = 0;
	private static $fail = 0;

	public static function assert_true( $condition, $message ) {
		if ( $condition ) {
			self::$pass++;
		} else {
			self::$fail++;
			fwrite( STDERR, "FAIL: {$message}\n" );
		}
	}

	public static function assert_equals( $expected, $actual, $message ) {
		$ok = ( $expected === $actual );
		self::assert_true(
			$ok,
			$message . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')'
		);
	}

	public static function assert_null( $actual, $message ) {
		self::assert_true( null === $actual, $message . ' (got ' . var_export( $actual, true ) . ')' );
	}

	public static function summary() {
		$total = self::$pass + self::$fail;
		echo "\n{$total} assertions, " . self::$pass . " passed, " . self::$fail . " failed.\n";
		return 0 === self::$fail;
	}
}
