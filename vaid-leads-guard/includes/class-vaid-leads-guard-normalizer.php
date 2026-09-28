<?php
/**
 * Pure identity-normalization logic — no WordPress dependency, so it can
 * be unit-tested directly from the CLI (see tests/).
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'VAID_LEADS_GUARD_TEST_MODE' ) ) {
	exit;
}

class VAID_Leads_Guard_Normalizer {

	/**
	 * Normalize a raw phone value.
	 *
	 * Strips everything but digits, then tries to resolve common Indian
	 * mobile representations (+91XXXXXXXXXX, 91XXXXXXXXXX, 0XXXXXXXXXX)
	 * down to a plain 10-digit number starting with 6-9. Anything that
	 * does not resolve to that shape is marked invalid rather than
	 * guessed at — a 9-digit number or free-text value is never
	 * "corrected".
	 *
	 * @param string|null $raw
	 * @return array{valid: bool, normalized: ?string, reason: ?string}
	 */
	public static function normalize_phone( $raw ) {
		if ( null === $raw || '' === trim( (string) $raw ) ) {
			return array( 'valid' => false, 'normalized' => null, 'reason' => 'empty' );
		}

		$digits = preg_replace( '/\D+/', '', (string) $raw );

		if ( '' === $digits ) {
			return array( 'valid' => false, 'normalized' => null, 'reason' => 'non_numeric' );
		}

		if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '91' ) ) {
			$digits = substr( $digits, 2 );
		} elseif ( 13 === strlen( $digits ) && 0 === strpos( $digits, '091' ) ) {
			$digits = substr( $digits, 3 );
		} elseif ( 11 === strlen( $digits ) && 0 === strpos( $digits, '0' ) ) {
			$digits = substr( $digits, 1 );
		}

		if ( 10 !== strlen( $digits ) ) {
			return array( 'valid' => false, 'normalized' => null, 'reason' => 'wrong_length' );
		}

		if ( ! preg_match( '/^[6-9]\d{9}$/', $digits ) ) {
			return array( 'valid' => false, 'normalized' => null, 'reason' => 'invalid_prefix_or_pattern' );
		}

		return array( 'valid' => true, 'normalized' => $digits, 'reason' => null );
	}

	/**
	 * Normalize a raw email value: trim + lowercase + syntactic
	 * validation only. Gmail dot/plus-address semantics are left
	 * untouched — we do not attempt to collapse "a.b+tag@gmail.com"
	 * to "ab@gmail.com", since that is a meaningful behavior change
	 * beyond simple normalization and was not asked for.
	 *
	 * @param string|null $raw
	 * @return array{valid: bool, normalized: ?string, reason: ?string}
	 */
	public static function normalize_email( $raw ) {
		if ( null === $raw || '' === trim( (string) $raw ) ) {
			return array( 'valid' => false, 'normalized' => null, 'reason' => 'empty' );
		}

		$trimmed = strtolower( trim( (string) $raw ) );

		if ( ! filter_var( $trimmed, FILTER_VALIDATE_EMAIL ) ) {
			return array( 'valid' => false, 'normalized' => null, 'reason' => 'invalid_syntax' );
		}

		return array( 'valid' => true, 'normalized' => $trimmed, 'reason' => null );
	}
}
