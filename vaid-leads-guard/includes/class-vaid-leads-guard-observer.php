<?php
/**
 * Shadow-mode observer.
 *
 * Hook choice: `fluentform_submission_inserted( $insertId, $formData, $form )`.
 * This fires AFTER Fluent Forms has already written the entry to its own
 * submissions table. That is deliberate for v0.1: the spec requires
 * zero interference with submission creation, and a post-insert hook
 * cannot fail, delay, or block a lead from being recorded — the entry
 * already exists by the time this code runs. A pre-insert/validation
 * hook is left for a future OTP/blocking version, where gating
 * submission creation is the explicit point; faking that contract here
 * (or repurposing this hook to also gate) is exactly what the v0.1
 * brief prohibits.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'VAID_LEADS_GUARD_TEST_MODE' ) ) {
	exit;
}

class VAID_Leads_Guard_Observer {

	public function register_hooks() {
		add_action( 'fluentform_submission_inserted', array( $this, 'on_submission_inserted' ), 20, 3 );
	}

	/**
	 * @param int   $insert_id
	 * @param array $form_data
	 * @param mixed $form
	 */
	public function on_submission_inserted( $insert_id, $form_data, $form ) {
		try {
			$form_id = is_object( $form ) && isset( $form->id ) ? (int) $form->id : 0;

			if ( ! VAID_Leads_Guard_Form_Map::is_supported( $form_id ) ) {
				return;
			}

			if ( ! VAID_Leads_Guard_Settings::is_form_monitored( $form_id ) ) {
				return;
			}

			$config = VAID_Leads_Guard_Form_Map::get_form_config( $form_id );
			$now    = current_time( 'mysql' );
			$secret = VAID_Leads_Guard_Settings::get_hmac_secret();

			$raw_phone = $this->extract_field( $form_data, $config['phone_field'] );
			$raw_email = $this->extract_field( $form_data, $config['email_field'] );

			$phone = VAID_Leads_Guard_Normalizer::normalize_phone( $raw_phone );
			$email = VAID_Leads_Guard_Normalizer::normalize_email( $raw_email );

			$phone_fp = $phone['valid'] ? VAID_Leads_Guard_Fingerprint::make( $phone['normalized'], $secret ) : null;
			$email_fp = $email['valid'] ? VAID_Leads_Guard_Fingerprint::make( $email['normalized'], $secret ) : null;
			$pair_fp  = ( $phone['valid'] && $email['valid'] )
				? VAID_Leads_Guard_Fingerprint::make_pair( $phone['normalized'], $email['normalized'], $secret )
				: null;

			// Prefer the strongest available signal: phone+email pair,
			// then phone alone, then email alone.
			$match       = null;
			$match_type  = null;

			if ( $pair_fp ) {
				$match      = VAID_Leads_Guard_DB::find_prior_by_fingerprint( $pair_fp, 'pair_fingerprint', $now );
				$match_type = $match ? 'pair' : null;
			}

			if ( ! $match && $phone_fp ) {
				$match      = VAID_Leads_Guard_DB::find_prior_by_fingerprint( $phone_fp, 'phone_fingerprint', $now );
				$match_type = $match ? 'phone' : null;
			}

			if ( ! $match && $email_fp ) {
				$match      = VAID_Leads_Guard_DB::find_prior_by_fingerprint( $email_fp, 'email_fingerprint', $now );
				$match_type = $match ? 'email' : null;
			}

			$delta_seconds = null;
			$is_cross_form = false;
			$prior_entry_id = null;
			$prior_form_id  = null;

			if ( $match ) {
				$prior_entry_id = (int) $match->entry_id;
				$prior_form_id  = (int) $match->form_id;
				$is_cross_form  = ( $prior_form_id !== $form_id );

				try {
					$prior_dt      = new DateTime( $match->created_at );
					$current_dt    = new DateTime( $now );
					$delta_seconds = abs( $current_dt->getTimestamp() - $prior_dt->getTimestamp() );
				} catch ( Exception $e ) {
					$delta_seconds = null;
				}
			}

			$classification = VAID_Leads_Guard_Classifier::classify( $delta_seconds, $is_cross_form );

			VAID_Leads_Guard_DB::insert_observation(
				array(
					'form_id'            => $form_id,
					'entry_id'           => (int) $insert_id,
					'prior_entry_id'     => $prior_entry_id,
					'prior_form_id'      => $prior_form_id,
					'phone_fingerprint'  => $phone_fp,
					'email_fingerprint'  => $email_fp,
					'pair_fingerprint'   => $pair_fp,
					'match_type'         => $match_type,
					'time_delta_seconds' => $delta_seconds,
					'classification'     => $classification,
					'is_cross_form'      => $is_cross_form ? 1 : 0,
					'action'             => 'shadow_only',
					'created_at'         => $now,
				)
			);
		} catch ( Exception $e ) {
			// Shadow mode must never surface an error to the visitor or
			// interfere with the real submission, which has already
			// completed by the time this hook runs. Log for admins only.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'VAID Leads Guard observer error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}
	}

	/**
	 * Fluent Forms' $formData is typically a flat associative array of
	 * field-name => value, but defensively also check an ->data
	 * property in case a future FF version wraps it in an object.
	 *
	 * @param mixed  $form_data
	 * @param string $field_name
	 * @return string|null
	 */
	private function extract_field( $form_data, $field_name ) {
		if ( is_array( $form_data ) && array_key_exists( $field_name, $form_data ) ) {
			$value = $form_data[ $field_name ];
			return is_scalar( $value ) ? (string) $value : null;
		}

		if ( is_object( $form_data ) && isset( $form_data->data ) && is_array( $form_data->data )
			&& array_key_exists( $field_name, $form_data->data ) ) {
			$value = $form_data->data[ $field_name ];
			return is_scalar( $value ) ? (string) $value : null;
		}

		return null;
	}
}
