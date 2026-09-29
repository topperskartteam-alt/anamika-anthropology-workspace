<?php
/**
 * Pure CSV/formula-injection guard — no WordPress dependency, so it can
 * be unit-tested directly from the CLI. Extracted out of the admin
 * class (v0.1.1) specifically so this security-relevant logic has its
 * own direct test coverage rather than only being exercised indirectly
 * through an admin-only export handler.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'VAID_LEADS_GUARD_TEST_MODE' ) ) {
	exit;
}

class VAID_Leads_Guard_Csv_Sanitizer {

	const TRIGGER_CHARS = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Defends against a value like `=cmd(...)`, `+HYPERLINK(...)`,
	 * `-2+3`, or `@SUM(...)` being interpreted as a spreadsheet formula
	 * when the exported CSV is opened in Excel/Sheets/etc. A leading
	 * single quote is prepended, which spreadsheet applications treat
	 * as "force text".
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function sanitize_cell( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( in_array( $value[0], self::TRIGGER_CHARS, true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * @param array $row
	 * @return array
	 */
	public static function sanitize_row( array $row ) {
		return array_map( array( __CLASS__, 'sanitize_cell' ), $row );
	}
}
