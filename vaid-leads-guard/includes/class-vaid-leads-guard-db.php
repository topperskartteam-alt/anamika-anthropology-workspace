<?php
/**
 * Versioned schema for the shadow-observation log.
 *
 * Deliberately does NOT create a second raw-lead database: no phone,
 * email, or name column exists anywhere in this table. Only
 * non-reversible HMAC fingerprints, form/entry IDs already present in
 * Fluent Forms' own tables, a time delta, and a classification label.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'VAID_LEADS_GUARD_TEST_MODE' ) ) {
	exit;
}

class VAID_Leads_Guard_DB {

	/**
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'vaid_leads_guard_observations';
	}

	/**
	 * Create or upgrade the table via dbDelta (idempotent, safe to call
	 * on every plugin load once the stored db version is behind).
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id SMALLINT UNSIGNED NOT NULL,
			entry_id BIGINT UNSIGNED NOT NULL,
			prior_entry_id BIGINT UNSIGNED NULL DEFAULT NULL,
			prior_form_id SMALLINT UNSIGNED NULL DEFAULT NULL,
			phone_fingerprint CHAR(64) NULL DEFAULT NULL,
			email_fingerprint CHAR(64) NULL DEFAULT NULL,
			pair_fingerprint CHAR(64) NULL DEFAULT NULL,
			match_type VARCHAR(16) NULL DEFAULT NULL,
			time_delta_seconds INT UNSIGNED NULL DEFAULT NULL,
			classification VARCHAR(32) NOT NULL,
			is_cross_form TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(20) NOT NULL DEFAULT 'shadow_only',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY entry_id (entry_id),
			KEY phone_fingerprint (phone_fingerprint),
			KEY email_fingerprint (email_fingerprint),
			KEY pair_fingerprint (pair_fingerprint),
			KEY classification (classification),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( VAID_LEADS_GUARD_OPTION_DB_VERSION, VAID_LEADS_GUARD_DB_VERSION );
	}

	/**
	 * Find the most recent prior observation sharing a fingerprint,
	 * across BOTH supported forms (cross-form detection needs this),
	 * strictly before the given timestamp.
	 *
	 * @param string $fingerprint
	 * @param string $column      One of phone_fingerprint|email_fingerprint|pair_fingerprint.
	 * @param string $before_datetime MySQL DATETIME string.
	 * @return object|null
	 */
	public static function find_prior_by_fingerprint( $fingerprint, $column, $before_datetime ) {
		global $wpdb;

		$allowed_columns = array( 'phone_fingerprint', 'email_fingerprint', 'pair_fingerprint' );
		if ( ! in_array( $column, $allowed_columns, true ) ) {
			return null;
		}

		$table_name = self::table_name();

		// Column name is whitelisted above, not user input — safe to
		// interpolate; value and datetime are passed as prepared params.
		$sql = "SELECT entry_id, form_id, created_at
			FROM {$table_name}
			WHERE {$column} = %s AND created_at < %s
			ORDER BY created_at DESC
			LIMIT 1";

		return $wpdb->get_row( $wpdb->prepare( $sql, $fingerprint, $before_datetime ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param array $row
	 * @return int|false Inserted row ID, or false on failure.
	 */
	public static function insert_observation( array $row ) {
		global $wpdb;

		$defaults = array(
			'form_id'             => 0,
			'entry_id'            => 0,
			'prior_entry_id'      => null,
			'prior_form_id'       => null,
			'phone_fingerprint'   => null,
			'email_fingerprint'   => null,
			'pair_fingerprint'    => null,
			'match_type'          => null,
			'time_delta_seconds'  => null,
			'classification'      => VAID_Leads_Guard_Classifier::NEW_IDENTITY,
			'is_cross_form'       => 0,
			'action'              => 'shadow_only',
			'created_at'          => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $row, $defaults );

		$formats = array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' );

		$result = $wpdb->insert( self::table_name(), $data, $formats );

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * @return array{total:int, by_form:array, by_classification:array}
	 */
	public static function get_summary() {
		global $wpdb;
		$table_name = self::table_name();

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$by_form_rows = $wpdb->get_results( "SELECT form_id, COUNT(*) as cnt FROM {$table_name} GROUP BY form_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$by_form      = array();
		foreach ( (array) $by_form_rows as $r ) {
			$by_form[ (int) $r->form_id ] = (int) $r->cnt;
		}

		$by_class_rows = $wpdb->get_results( "SELECT classification, COUNT(*) as cnt FROM {$table_name} GROUP BY classification" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$by_class      = array();
		foreach ( (array) $by_class_rows as $r ) {
			$by_class[ $r->classification ] = (int) $r->cnt;
		}

		return array(
			'total'              => $total,
			'by_form'            => $by_form,
			'by_classification'  => $by_class,
		);
	}

	/**
	 * @param int $limit
	 * @param int $offset
	 * @return array
	 */
	public static function get_recent( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table_name = self::table_name();

		$sql = "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d";

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $limit, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
