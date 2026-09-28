<?php
/**
 * Import batch tracking: a small native WordPress custom table recording
 * one row per CSV import batch, and one row per post created by that
 * batch, so a failed/unwanted batch can be rolled back without touching
 * any pre-existing term.
 *
 * A custom table (not postmeta) is used here — deliberately, and only
 * here — because batch membership is inherently relational bookkeeping
 * (which posts belong to which batch, so they can be deleted together),
 * not term content; it does not change the CPT-vs-table decision made
 * for the terms themselves in Round 2.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Import_Batch_Log {

	/**
	 * @return string Fully-prefixed batch-items table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'vaid_glossary_import_batch_items';
	}

	/**
	 * Create the batch-items table on activation if it does not exist yet.
	 * Uses dbDelta for safe, idempotent, upgrade-friendly schema creation.
	 */
	public static function maybe_create_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id VARCHAR(40) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL,
			created_by BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY post_id (post_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Generate a new unique batch identifier.
	 *
	 * @return string
	 */
	public static function new_batch_id() {
		return 'vgb_' . gmdate( 'Ymd_His' ) . '_' . substr( wp_generate_password( 8, false, false ), 0, 8 );
	}

	/**
	 * Record that $post_id was created as part of $batch_id.
	 *
	 * @param string $batch_id Batch identifier.
	 * @param int    $post_id  Newly created post ID.
	 */
	public static function record( $batch_id, $post_id ) {
		global $wpdb;

		$wpdb->insert(
			self::table_name(),
			array(
				'batch_id'   => $batch_id,
				'post_id'    => $post_id,
				'created_by' => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s' )
		);
	}

	/**
	 * Get all post IDs recorded under a batch.
	 *
	 * @param string $batch_id Batch identifier.
	 * @return int[]
	 */
	public static function get_post_ids( $batch_id ) {
		global $wpdb;

		$table = self::table_name();

		return array_map(
			'absint',
			$wpdb->get_col(
				$wpdb->prepare( "SELECT post_id FROM {$table} WHERE batch_id = %s", $batch_id )
			)
		);
	}

	/**
	 * List recent batches (id, count, created_at, created_by) for the
	 * admin rollback UI.
	 *
	 * @param int $limit Max batches to return.
	 * @return array
	 */
	public static function list_recent_batches( $limit = 20 ) {
		global $wpdb;

		$table = self::table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT batch_id, COUNT(*) as item_count, MIN(created_at) as created_at, MAX(created_by) as created_by
				 FROM {$table}
				 GROUP BY batch_id
				 ORDER BY created_at DESC
				 LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Roll back a batch: trash every post recorded under it (never a hard
	 * delete, so an accidental rollback is itself recoverable from Trash),
	 * then remove the batch's log rows.
	 *
	 * Only ever acts on posts this batch created — never touches a
	 * pre-existing term, even if that term was later edited.
	 *
	 * @param string $batch_id Batch identifier.
	 * @return int Number of posts trashed.
	 */
	public static function rollback( $batch_id ) {
		global $wpdb;

		$post_ids = self::get_post_ids( $batch_id );
		$count    = 0;

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( $post && VAID_GLOSSARY_CPT === $post->post_type ) {
				wp_trash_post( $post_id );
				++$count;
			}
		}

		$table = self::table_name();
		$wpdb->delete( $table, array( 'batch_id' => $batch_id ), array( '%s' ) );

		return $count;
	}
}
