<?php
/**
 * Deterministic, non-reversible identity fingerprints.
 *
 * We never store normalized plaintext phone/email in the database.
 * Instead we HMAC-SHA256 the normalized value with a per-install secret
 * (generated on activation, stored in wp_options, never displayed in
 * the admin UI). Because HMAC is deterministic, the same normalized
 * phone always produces the same fingerprint on this install, which is
 * exactly what duplicate matching needs — equality lookup — without
 * ever holding the plaintext value itself.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'VAID_LEADS_GUARD_TEST_MODE' ) ) {
	exit;
}

class VAID_Leads_Guard_Fingerprint {

	/**
	 * @param string $normalized_value Already-normalized phone or email.
	 * @param string $secret           Per-install HMAC secret.
	 * @return string 64-char hex HMAC-SHA256 digest.
	 */
	public static function make( $normalized_value, $secret ) {
		return hash_hmac( 'sha256', $normalized_value, $secret );
	}

	/**
	 * Fingerprint of the phone+email pair, order-independent join so
	 * "phone|email" and "email|phone" never diverge by accident.
	 *
	 * @param string $normalized_phone
	 * @param string $normalized_email
	 * @param string $secret
	 * @return string
	 */
	public static function make_pair( $normalized_phone, $normalized_email, $secret ) {
		return self::make( $normalized_phone . '|' . $normalized_email, $secret );
	}

	/**
	 * Short, display-safe prefix for admin-screen/CSV output. Never use
	 * this for matching — only ever for a human to eyeball "same
	 * fingerprint appears N times" without ever seeing PII.
	 *
	 * @param string $fingerprint
	 * @return string
	 */
	public static function short( $fingerprint ) {
		return substr( $fingerprint, 0, 10 );
	}
}
