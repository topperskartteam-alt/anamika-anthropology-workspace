<?php
/**
 * Locked form/page mapping from the VAID Leads full-data audit
 * (2026-09-28). Only these two forms are observed in v0.1.
 *
 * Field-name-to-form coupling is inherently fragile: if a form's
 * fields are edited in the Fluent Forms builder (renamed input keys),
 * this map must be updated by hand, or shadow observations for that
 * form will silently stop matching. The `vaid_leads_guard_field_map`
 * filter lets an admin override this without touching plugin code.
 * See ARCHITECTURE.md "Known limitations".
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'VAID_LEADS_GUARD_TEST_MODE' ) ) {
	exit;
}

class VAID_Leads_Guard_Form_Map {

	const FORM_COURSE_PAGE      = 9;
	const FORM_RESERVE_FREE_SEAT = 1;

	/**
	 * @return array<int, array{label:string, url:string, page_id:int, phone_field:string, email_field:string}>
	 */
	public static function get_map() {
		$map = array(
			self::FORM_COURSE_PAGE       => array(
				'label'       => 'Course Page',
				'url'         => '/anthropology/optional-coaching/',
				'page_id'     => 8621,
				'phone_field' => 'input_text',
				'email_field' => 'email',
			),
			self::FORM_RESERVE_FREE_SEAT => array(
				'label'       => 'Reserve My Free Seat',
				'url'         => '/anthropology/workshop/',
				'page_id'     => 7489,
				'phone_field' => 'numeric_field',
				'email_field' => 'email',
			),
		);

		/**
		 * Filter: vaid_leads_guard_field_map
		 * Allows overriding the locked form/field map without editing
		 * plugin code, e.g. after a Fluent Forms field is renamed.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$map = apply_filters( 'vaid_leads_guard_field_map', $map );
		}

		return $map;
	}

	/**
	 * @param int $form_id
	 * @return bool
	 */
	public static function is_supported( $form_id ) {
		return array_key_exists( (int) $form_id, self::get_map() );
	}

	/**
	 * @param int $form_id
	 * @return array|null
	 */
	public static function get_form_config( $form_id ) {
		$map = self::get_map();
		return isset( $map[ (int) $form_id ] ) ? $map[ (int) $form_id ] : null;
	}
}
