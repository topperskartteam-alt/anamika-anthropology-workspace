<?php
/**
 * Shared in-memory fake of the observations table, used by both
 * test-scenarios.php (sequential decision-path coverage) and
 * test-concurrency.php (race simulation). Mirrors
 * VAID_Leads_Guard_DB::find_prior_by_fingerprint()'s semantics: most
 * recent prior row with a matching fingerprint, strictly before a given
 * timestamp, across any form (cross-form matching is intentional).
 */

class Fake_Observation_Store {
	private $rows = array();

	public function insert( $fingerprint, $form_id, $entry_id, $timestamp ) {
		$this->rows[] = array(
			'fingerprint' => $fingerprint,
			'form_id'     => $form_id,
			'entry_id'    => $entry_id,
			'timestamp'   => $timestamp,
		);
	}

	public function find_prior( $fingerprint, $before_timestamp ) {
		$candidates = array_filter(
			$this->rows,
			function ( $row ) use ( $fingerprint, $before_timestamp ) {
				return $row['fingerprint'] === $fingerprint && $row['timestamp'] < $before_timestamp;
			}
		);

		if ( empty( $candidates ) ) {
			return null;
		}

		usort(
			$candidates,
			function ( $a, $b ) {
				return $b['timestamp'] <=> $a['timestamp'];
			}
		);

		return array_values( $candidates )[0];
	}
}
