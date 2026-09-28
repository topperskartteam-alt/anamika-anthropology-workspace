<?php
/**
 * Pure duplicate-likelihood classification logic — no WordPress
 * dependency, so it can be unit-tested directly from the CLI.
 *
 * Per the v0.1 spec, time gap alone is a SIGNAL, not proof of intent.
 * Bucket labels describe likelihood, not a verdict:
 *   <=2m   => strong_mechanical_repeat  (very likely a double-click/reload)
 *   2-10m  => short_repeat              (elevated risk, not proof)
 *   10m-24h=> repeat_same_day
 *   >24h   => returning_enquiry
 * No prior match at all => new_identity.
 * A match against a *different* form overrides the bucket label with
 * cross_form_repeat (still observe/report only, per spec).
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'VAID_LEADS_GUARD_TEST_MODE' ) ) {
	exit;
}

class VAID_Leads_Guard_Classifier {

	const STRONG_MECHANICAL_REPEAT = 'strong_mechanical_repeat';
	const SHORT_REPEAT             = 'short_repeat';
	const REPEAT_SAME_DAY          = 'repeat_same_day';
	const RETURNING_ENQUIRY        = 'returning_enquiry';
	const CROSS_FORM_REPEAT        = 'cross_form_repeat';
	const NEW_IDENTITY             = 'new_identity';

	const WINDOW_STRONG_MECHANICAL_SECONDS = 120;
	const WINDOW_SHORT_REPEAT_SECONDS      = 600;
	const WINDOW_SAME_DAY_SECONDS          = 86400;

	/**
	 * @param int|null $delta_seconds   Seconds since the prior matching
	 *                                  submission, or null if there is no
	 *                                  prior match at all.
	 * @param bool     $is_cross_form   True if the prior match came from
	 *                                  the *other* supported form.
	 * @return string One of the class constants above.
	 */
	public static function classify( $delta_seconds, $is_cross_form = false ) {
		if ( null === $delta_seconds ) {
			return self::NEW_IDENTITY;
		}

		if ( $is_cross_form ) {
			return self::CROSS_FORM_REPEAT;
		}

		$delta_seconds = abs( (int) $delta_seconds );

		if ( $delta_seconds <= self::WINDOW_STRONG_MECHANICAL_SECONDS ) {
			return self::STRONG_MECHANICAL_REPEAT;
		}

		if ( $delta_seconds <= self::WINDOW_SHORT_REPEAT_SECONDS ) {
			return self::SHORT_REPEAT;
		}

		if ( $delta_seconds <= self::WINDOW_SAME_DAY_SECONDS ) {
			return self::REPEAT_SAME_DAY;
		}

		return self::RETURNING_ENQUIRY;
	}

	/**
	 * All valid classification labels, for validation/reporting use.
	 *
	 * @return string[]
	 */
	public static function all_labels() {
		return array(
			self::STRONG_MECHANICAL_REPEAT,
			self::SHORT_REPEAT,
			self::REPEAT_SAME_DAY,
			self::RETURNING_ENQUIRY,
			self::CROSS_FORM_REPEAT,
			self::NEW_IDENTITY,
		);
	}
}
