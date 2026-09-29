<?php
/**
 * Shadow-mode observer.
 *
 * Hook contract (verified 2026-09-29 against Fluent Forms' own official
 * documentation — fluentforms.com/docs and developers.fluentforms.com —
 * via search-engine-retrieved summaries, since direct HTTPS fetch to
 * those domains is blocked by this environment's egress policy; see
 * ARCHITECTURE.md "Hook contract evidence" for the exact evidence
 * chain and its limits):
 *
 *   - Fluent Forms renamed ALL of its hooks from underscore to
 *     slash-namespaced form in v5.0. The CURRENT, canonical hook is
 *     `fluentform/submission_inserted`, firing AFTER the submission
 *     row is written to Fluent Forms' own table, with signature
 *     `function( $entryId, $formData, $form )`.
 *   - The old underscore name, `fluentform_submission_inserted` — which
 *     is what v0.1.0 registered on, and the ONLY name it registered on —
 *     still fires via `do_action_deprecated()` for backward
 *     compatibility, but is scheduled for removal in Fluent Forms 7.0.
 *   - v0.1.0's DEFECT (repaired here): it hooked only the deprecated
 *     underscore name. On any site already upgraded to the modern hook
 *     naming AND past the point the legacy alias is removed (FF >= 7.0),
 *     v0.1.0 would silently observe nothing at all, with no error.
 *   - v0.1.1 registers on BOTH the canonical and the deprecated hook
 *     name, so it keeps working whether the live site's Fluent Forms
 *     version fires the old name, the new name, or (as is true for
 *     FF 5.x/6.x today) both for the same event. Firing both is made
 *     safe by a UNIQUE KEY(form_id, entry_id) at the database layer —
 *     see class-vaid-leads-guard-db.php — so a submission observed
 *     twice in the same request can never produce two rows.
 *
 * Both hooks remain strictly POST-insert: the entry already exists in
 * Fluent Forms' own table by the time this code runs, so nothing here
 * can fail, delay, gate, or corrupt lead capture. Fluent Forms' family
 * of BEFORE-insert/validation hooks is explicitly never used in this
 * version — see tests/test-static-checks.php, which greps the shipped
 * source for both the underscore and slash forms of every known
 * pre-insert/validation hook name and fails the build if any appear.
 *
 * WordPress action hooks (do_action, as opposed to filters) never use a
 * callback's return value for anything — this is a core, version-stable
 * WordPress Plugin API guarantee, not something specific to Fluent
 * Forms, so no return-value contract needs to be verified per-hook.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'VAID_LEADS_GUARD_TEST_MODE' ) ) {
	exit;
}

class VAID_Leads_Guard_Observer {

	const HOOK_CURRENT    = 'fluentform/submission_inserted';
	const HOOK_DEPRECATED = 'fluentform_submission_inserted';

	public function register_hooks() {
		add_action( self::HOOK_CURRENT, array( $this, 'on_submission_inserted' ), 20, 3 );
		add_action( self::HOOK_DEPRECATED, array( $this, 'on_submission_inserted' ), 20, 3 );
	}

	/**
	 * @param int   $insert_id
	 * @param array $form_data
	 * @param mixed $form
	 */
	public function on_submission_inserted( $insert_id, $form_data, $form ) {
		// Catches \Throwable, not just \Exception: a TypeError or other
		// PHP 7+ Error thrown anywhere below must fail open exactly like
		// an Exception would. v0.1.0 caught Exception only, which is an
		// incomplete safety net for the "shadow mode can never interfere"
		// guarantee this plugin makes.
		try {
			$form_id = is_object( $form ) && isset( $form->id ) ? (int) $form->id : 0;

			if ( ! VAID_Leads_Guard_Form_Map::is_supported( $form_id ) ) {
				return;
			}

			if ( ! VAID_Leads_Guard_Settings::is_form_monitored( $form_id ) ) {
				return;
			}

			$config = VAID_Leads_Guard_Form_Map::get_form_config( $form_id );

			// Stored/compared in UTC throughout, deliberately. India (the
			// site's audience and WP timezone) observes no DST, so this
			// makes little practical difference here — but naive
			// site-local-time string diffing (v0.1.0's approach) is not
			// safe in general across a DST transition, and UTC removes
			// the ambiguity entirely rather than relying on that fact.
			$now    = current_time( 'mysql', true );
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

			// Nothing to observe or match on — skip the row entirely
			// rather than logging a pure-noise "new_identity" entry with
			// no usable identity signal at all.
			if ( ! $phone_fp && ! $email_fp ) {
				return;
			}

			// Prefer the strongest available signal: phone+email pair,
			// then phone alone, then email alone.
			$match      = null;
			$match_type = null;

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

			$delta_seconds  = null;
			$is_cross_form  = false;
			$prior_entry_id = null;
			$prior_form_id  = null;

			if ( $match ) {
				$prior_entry_id = (int) $match->entry_id;
				$prior_form_id  = (int) $match->form_id;
				$is_cross_form  = ( $prior_form_id !== $form_id );

				try {
					$utc           = new DateTimeZone( 'UTC' );
					$prior_dt      = new DateTime( $match->created_at, $utc );
					$current_dt    = new DateTime( $now, $utc );
					// abs(): out-of-order/clock-drift deltas (a "prior" row
					// timestamped after the current one, possible on a
					// multi-web-server install with drifted clocks) must
					// never fail unsafely — they fail safe into whichever
					// bucket the magnitude of the gap lands in.
					$delta_seconds = abs( $current_dt->getTimestamp() - $prior_dt->getTimestamp() );
				} catch ( Exception $e ) {
					$delta_seconds = null;
				}
			}

			$classification = VAID_Leads_Guard_Classifier::classify( $delta_seconds, $is_cross_form );

			$inserted = VAID_Leads_Guard_DB::insert_observation(
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

			// A duplicate-key rejection here is the EXPECTED, safe outcome
			// when both the current and deprecated Fluent Forms hooks fire
			// for the same entry_id in one request (see class docblock) —
			// not an error. Any other insert failure is logged for admin
			// visibility only; shadow mode still fails open either way.
			if ( false === $inserted && function_exists( 'error_log' ) ) {
				global $wpdb;
				$is_duplicate = isset( $wpdb->last_error ) && false !== stripos( $wpdb->last_error, 'duplicate' );
				if ( ! $is_duplicate ) {
					error_log( 'VAID Leads Guard: observation insert failed for entry ' . (int) $insert_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				}
			}
		} catch ( Throwable $e ) {
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
