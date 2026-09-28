<?php
/**
 * Settings accessor. All reads/writes go through here so the option
 * shape stays consistent between activation defaults, the admin
 * screen, and the observer.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'VAID_LEADS_GUARD_TEST_MODE' ) ) {
	exit;
}

class VAID_Leads_Guard_Settings {

	/**
	 * @return array{master_enabled: bool, form_9_enabled: bool, form_1_enabled: bool, blocking_enabled: bool}
	 */
	public static function defaults() {
		return array(
			// Defaults per v0.1 spec: shadow monitoring ON for both
			// supported forms, blocking permanently OFF in this version.
			'master_enabled'   => true,
			'form_9_enabled'   => true,
			'form_1_enabled'   => true,
			// Not a real toggle in v0.1 — always false, kept only so the
			// admin screen can render a disabled control with a clear
			// "not implemented" explanation instead of hiding the concept.
			'blocking_enabled' => false,
		);
	}

	/**
	 * @return array
	 */
	public static function get() {
		$stored = get_option( VAID_LEADS_GUARD_OPTION_SETTINGS, array() );
		$merged = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );

		// v0.1 hard rule: blocking can never be turned on from this
		// version's settings, regardless of what is stored.
		$merged['blocking_enabled'] = false;

		return $merged;
	}

	/**
	 * @param array $new_values
	 * @return bool
	 */
	public static function update( array $new_values ) {
		$current = self::get();

		$current['master_enabled'] = ! empty( $new_values['master_enabled'] );
		$current['form_9_enabled'] = ! empty( $new_values['form_9_enabled'] );
		$current['form_1_enabled'] = ! empty( $new_values['form_1_enabled'] );
		// blocking_enabled is intentionally never settable in v0.1.
		$current['blocking_enabled'] = false;

		return update_option( VAID_LEADS_GUARD_OPTION_SETTINGS, $current );
	}

	/**
	 * @param int $form_id
	 * @return bool
	 */
	public static function is_form_monitored( $form_id ) {
		$settings = self::get();

		if ( empty( $settings['master_enabled'] ) ) {
			return false;
		}

		if ( VAID_Leads_Guard_Form_Map::FORM_COURSE_PAGE === (int) $form_id ) {
			return ! empty( $settings['form_9_enabled'] );
		}

		if ( VAID_Leads_Guard_Form_Map::FORM_RESERVE_FREE_SEAT === (int) $form_id ) {
			return ! empty( $settings['form_1_enabled'] );
		}

		return false;
	}

	/**
	 * @return string
	 */
	public static function get_hmac_secret() {
		$secret = get_option( VAID_LEADS_GUARD_OPTION_SECRET, '' );

		if ( '' === $secret ) {
			// Defensive fallback — activation should always have set
			// this, but never let a missing secret cause a fatal.
			$secret = wp_generate_password( 64, true, true );
			add_option( VAID_LEADS_GUARD_OPTION_SECRET, $secret, '', 'no' );
		}

		return $secret;
	}
}
