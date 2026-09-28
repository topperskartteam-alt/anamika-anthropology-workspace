<?php
/**
 * CSV import: parse -> sanitize -> validate every row -> dry-run report
 * -> explicit Confirm Import -> batch-logged commit -> rollback action.
 *
 * Never overwrites or deletes a pre-existing term automatically. No
 * DB-level transaction is claimed across the WP post/meta APIs; instead,
 * every post created by a batch is logged (Import_Batch_Log) so a failed
 * or unwanted batch can be rolled back afterwards (posts are trashed,
 * never hard-deleted, by the rollback action).
 *
 * v0.4.1 red-team corrections applied here: file MIME/extension is
 * validated before parsing (validate_uploaded_file()); the
 * formula-injection guard is no longer applied on import (see
 * functions.php — it is export-only now, so legitimate content is never
 * mutated); a row whose wp_insert_post() call fails is now counted and
 * reported rather than silently dropped from the totals; and the
 * Confirm/Rollback nonces are bound to the specific token/batch ID they
 * act on, not just a generic action name.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CSV_Import {

	const NONCE_DRY_RUN = 'vaid_glossary_import_dry_run';
	const NONCE_CONFIRM = 'vaid_glossary_import_confirm';
	const NONCE_ROLLBACK = 'vaid_glossary_import_rollback';
	const EXPECTED_HEADER = array( 'title', 'short_definition', 'aliases', 'topic', 'status' );

	public static function init() {
		add_action( 'admin_post_vaid_glossary_import_dry_run', array( __CLASS__, 'handle_dry_run' ) );
		add_action( 'admin_post_vaid_glossary_import_confirm', array( __CLASS__, 'handle_confirm' ) );
		add_action( 'admin_post_vaid_glossary_import_rollback', array( __CLASS__, 'handle_rollback' ) );
	}

	public static function render_page() {
		if ( ! current_user_can( VAID_GLOSSARY_CAP_IMPORT ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vaid-anthropology-glossary' ) );
		}

		$token  = isset( $_GET['vaid_token'] ) ? sanitize_key( wp_unslash( $_GET['vaid_token'] ) ) : '';
		$report = $token ? get_transient( self::transient_key( $token ) ) : false;

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Glossary Terms (CSV)', 'vaid-anthropology-glossary' ); ?></h1>

			<?php if ( $report ) : ?>
				<?php self::render_report( $token, $report ); ?>
			<?php else : ?>
				<p><?php esc_html_e( 'Upload a CSV with columns: title, short_definition, aliases, topic, status. Aliases are pipe-separated (e.g. "term A|term B"). Nothing is written to the database on this step — you will see a validation report first.', 'vaid-anthropology-glossary' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="vaid_glossary_import_dry_run" />
					<?php wp_nonce_field( self::NONCE_DRY_RUN ); ?>
					<p><input type="file" name="vaid_glossary_csv" accept=".csv,text/csv" required /></p>
					<?php submit_button( __( 'Run Dry-Run Validation', 'vaid-anthropology-glossary' ) ); ?>
				</form>
			<?php endif; ?>

			<?php self::render_recent_batches(); ?>
		</div>
		<?php
	}

	private static function transient_key( $token ) {
		return 'vaid_glossary_import_' . $token;
	}

	// -------------------------------------------------------------
	// Step 1: parse + sanitize + validate -> dry-run report
	// -------------------------------------------------------------

	public static function handle_dry_run() {
		if ( ! current_user_can( VAID_GLOSSARY_CAP_IMPORT ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'vaid-anthropology-glossary' ) );
		}

		check_admin_referer( self::NONCE_DRY_RUN );

		if ( empty( $_FILES['vaid_glossary_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['vaid_glossary_csv']['tmp_name'] ) ) {
			wp_die( esc_html__( 'No file was uploaded, or the upload failed.', 'vaid-anthropology-glossary' ) );
		}

		$file_error = self::validate_uploaded_file( $_FILES['vaid_glossary_csv'] );
		if ( is_wp_error( $file_error ) ) {
			wp_die( esc_html( $file_error->get_error_message() ) );
		}

		$rows = self::parse_csv( $_FILES['vaid_glossary_csv']['tmp_name'] );

		if ( is_wp_error( $rows ) ) {
			wp_die( esc_html( $rows->get_error_message() ) );
		}

		$report = self::validate_rows( $rows );

		$token = wp_generate_password( 20, false, false );
		set_transient( self::transient_key( $token ), $report, HOUR_IN_SECONDS );

		$redirect = add_query_arg(
			array(
				'post_type' => VAID_GLOSSARY_CPT,
				'page'      => 'vaid-glossary-import',
				'vaid_token' => $token,
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Validate the uploaded file's extension and actual MIME type before
	 * it is ever opened as CSV — v0.4.1 red-team addition (v0.4.0 had no
	 * such check and would attempt to fopen()/fgetcsv() parse whatever was
	 * uploaded, regardless of type). Uses wp_check_filetype_and_ext(),
	 * which content-sniffs the real file in addition to checking the
	 * claimed extension, so a renamed non-CSV file is still rejected.
	 *
	 * This does not by itself change the file's own security exposure —
	 * the temp upload is only ever read (fopen/fgetcsv), never moved into
	 * a web-accessible directory or executed — but it gives a clear error
	 * for a wrong file type instead of silently attempting to parse
	 * garbage, and closes the "no MIME/extension handling" gap named in
	 * the red-team brief.
	 *
	 * @param array $file One entry of the $_FILES superglobal.
	 * @return true|\WP_Error
	 */
	private static function validate_uploaded_file( array $file ) {
		$file_name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';

		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file_name, array( 'csv' => 'text/csv' ) );

		if ( empty( $check['ext'] ) || 'csv' !== $check['ext'] ) {
			return new \WP_Error(
				'vaid_glossary_import_bad_filetype',
				__( 'The uploaded file must be a .csv file.', 'vaid-anthropology-glossary' )
			);
		}

		return true;
	}

	/**
	 * Parse an uploaded CSV file into an array of associative rows,
	 * sanitizing every cell (tag-stripping only — NOT the formula-injection
	 * guard, which is export-only as of v0.4.1; see
	 * vaid_glossary_sanitize_csv_cell()'s docblock in functions.php).
	 *
	 * @param string $tmp_path Path to the uploaded temp file.
	 * @return array|\WP_Error
	 */
	private static function parse_csv( $tmp_path ) {
		$handle = fopen( $tmp_path, 'r' );
		if ( ! $handle ) {
			return new \WP_Error( 'vaid_glossary_import_unreadable', __( 'Could not read the uploaded file.', 'vaid-anthropology-glossary' ) );
		}

		$header = fgetcsv( $handle );
		if ( ! $header ) {
			fclose( $handle );
			return new \WP_Error( 'vaid_glossary_import_empty', __( 'The CSV file appears to be empty.', 'vaid-anthropology-glossary' ) );
		}

		$header = array_map( static function ( $col ) {
			return strtolower( trim( (string) $col ) );
		}, $header );

		$rows        = array();
		$line_number = 1;

		while ( false !== ( $line = fgetcsv( $handle ) ) ) {
			++$line_number;

			if ( 1 === count( $line ) && null === $line[0] ) {
				continue; // Skip blank lines.
			}

			$assoc = array();
			foreach ( $header as $index => $col_name ) {
				$raw               = isset( $line[ $index ] ) ? $line[ $index ] : '';
				// Tag-stripping only. No formula-injection prefixing here —
				// see vaid_glossary_sanitize_csv_cell()'s docblock: that
				// mitigation belongs at export time only, not on data being
				// stored, or it permanently corrupts legitimate content
				// that happens to start with -, +, @, or =.
				$assoc[ $col_name ] = sanitize_textarea_field( $raw );
			}

			$rows[] = array(
				'line'             => $line_number,
				'title'            => isset( $assoc['title'] ) ? trim( wp_strip_all_tags( $assoc['title'] ) ) : '',
				'short_definition' => isset( $assoc['short_definition'] ) ? trim( wp_strip_all_tags( $assoc['short_definition'] ) ) : '',
				'aliases'          => isset( $assoc['aliases'] ) && '' !== $assoc['aliases']
					? array_values( array_filter( array_map( 'trim', explode( '|', $assoc['aliases'] ) ) ) )
					: array(),
				'topic'            => isset( $assoc['topic'] ) ? trim( wp_strip_all_tags( $assoc['topic'] ) ) : '',
				'status'           => isset( $assoc['status'] ) && in_array( trim( $assoc['status'] ), array( 'publish', 'draft', 'pending' ), true )
					? trim( $assoc['status'] )
					: 'draft',
			);
		}

		fclose( $handle );

		return $rows;
	}

	/**
	 * Validate every parsed row: required fields, and duplicate governance
	 * via Duplicate_Guard::evaluate_import_row().
	 *
	 * @param array $rows Parsed rows.
	 * @return array Report: ['rows' => [...], 'ok_count', 'warn_count', 'block_count']
	 */
	private static function validate_rows( array $rows ) {
		$existing_titles  = Duplicate_Guard::get_existing_title_index();
		$existing_aliases = Duplicate_Guard::get_existing_alias_index();
		$batch_titles_seen = array();

		$results    = array();
		$ok_count   = 0;
		$warn_count = 0;
		$block_count = 0;

		foreach ( $rows as $row ) {
			$messages = array();
			$status   = 'ok';

			if ( '' === $row['title'] ) {
				$status     = 'block';
				$messages[] = __( 'Missing required title.', 'vaid-anthropology-glossary' );
			}

			if ( '' === $row['short_definition'] ) {
				$status     = 'block';
				$messages[] = __( 'Missing required short_definition.', 'vaid-anthropology-glossary' );
			}

			if ( 'block' !== $status ) {
				$dup = Duplicate_Guard::evaluate_import_row( $row, $existing_titles, $existing_aliases, $batch_titles_seen );
				$status     = $dup['status'];
				$messages   = array_merge( $messages, $dup['messages'] );
			}

			if ( '' !== $row['title'] ) {
				$batch_titles_seen[ vaid_glossary_normalize_title( $row['title'] ) ] = $row['line'];
			}

			if ( 'block' === $status ) {
				++$block_count;
			} elseif ( 'warn' === $status ) {
				++$warn_count;
			} else {
				++$ok_count;
			}

			$results[] = array_merge( $row, array( 'validation_status' => $status, 'validation_messages' => $messages ) );
		}

		return array(
			'rows'        => $results,
			'ok_count'    => $ok_count,
			'warn_count'  => $warn_count,
			'block_count' => $block_count,
			'total'       => count( $results ),
		);
	}

	private static function render_report( $token, $report ) {
		?>
		<h2><?php esc_html_e( 'Dry-Run Validation Report', 'vaid-anthropology-glossary' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: total rows, 2: ok, 3: warn, 4: blocked */
				esc_html__( '%1$d rows parsed — %2$d ready to import, %3$d with warnings (still importable), %4$d blocked (will be skipped).', 'vaid-anthropology-glossary' ),
				(int) $report['total'],
				(int) $report['ok_count'],
				(int) $report['warn_count'],
				(int) $report['block_count']
			);
			?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Row', 'vaid-anthropology-glossary' ); ?></th>
					<th><?php esc_html_e( 'Title', 'vaid-anthropology-glossary' ); ?></th>
					<th><?php esc_html_e( 'Status', 'vaid-anthropology-glossary' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'vaid-anthropology-glossary' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $report['rows'] as $row ) : ?>
				<tr>
					<td><?php echo (int) $row['line']; ?></td>
					<td><?php echo esc_html( $row['title'] ); ?></td>
					<td>
						<?php
						$labels = array(
							'ok'    => __( 'OK', 'vaid-anthropology-glossary' ),
							'warn'  => __( 'Warning', 'vaid-anthropology-glossary' ),
							'block' => __( 'Blocked', 'vaid-anthropology-glossary' ),
						);
						echo esc_html( $labels[ $row['validation_status'] ] );
						?>
					</td>
					<td><?php echo $row['validation_messages'] ? esc_html( implode( ' ', $row['validation_messages'] ) ) : '—'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;">
			<input type="hidden" name="action" value="vaid_glossary_import_confirm" />
			<input type="hidden" name="vaid_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php wp_nonce_field( self::NONCE_CONFIRM . '_' . $token ); ?>
			<?php submit_button( __( 'Confirm Import (rows marked OK or Warning only)', 'vaid-anthropology-glossary' ), 'primary', 'submit', false ); ?>
			<a href="<?php echo esc_url( remove_query_arg( 'vaid_token' ) ); ?>" class="button" style="margin-left:8px;"><?php esc_html_e( 'Cancel / upload a different file', 'vaid-anthropology-glossary' ); ?></a>
		</form>
		<?php
	}

	// -------------------------------------------------------------
	// Step 2: explicit confirm -> commit (rows marked block are skipped)
	// -------------------------------------------------------------

	public static function handle_confirm() {
		if ( ! current_user_can( VAID_GLOSSARY_CAP_IMPORT ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'vaid-anthropology-glossary' ) );
		}

		// v0.4.1 red-team hardening: the nonce action is bound to the
		// specific token being confirmed, not just the generic "confirm"
		// action, so a valid nonce for one pending report cannot be reused
		// to confirm a different token.
		$token = isset( $_POST['vaid_token'] ) ? sanitize_key( wp_unslash( $_POST['vaid_token'] ) ) : '';
		check_admin_referer( self::NONCE_CONFIRM . '_' . $token );

		$report = $token ? get_transient( self::transient_key( $token ) ) : false;

		if ( ! $report ) {
			wp_die( esc_html__( 'This import report has expired. Please re-upload the CSV.', 'vaid-anthropology-glossary' ) );
		}

		$batch_id  = Import_Batch_Log::new_batch_id();
		$created   = 0;
		$skipped   = 0;
		$failed    = 0;

		foreach ( $report['rows'] as $row ) {
			if ( 'block' === $row['validation_status'] ) {
				++$skipped;
				continue; // Never create a post for a blocked row.
			}

			$post_id = wp_insert_post(
				array(
					'post_type'   => VAID_GLOSSARY_CPT,
					'post_title'  => $row['title'],
					'post_status' => $row['status'],
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				// v0.4.1 red-team correction: v0.4.0 silently `continue`d
				// here without counting the row anywhere, so a genuine
				// insert failure (e.g. a DB error) vanished from the
				// reported totals — "created" + "skipped" would undercount
				// the rows actually processed. Now counted and reported.
				++$failed;
				continue;
			}

			update_post_meta( $post_id, Meta_Fields::SHORT_DEFINITION, sanitize_textarea_field( $row['short_definition'] ) );
			update_post_meta( $post_id, Meta_Fields::ALIASES, Meta_Fields::sanitize_aliases( $row['aliases'] ) );
			update_post_meta( $post_id, Meta_Fields::FIRST_LETTER, vaid_glossary_derive_first_letter( $row['title'] ) );

			if ( '' !== $row['topic'] ) {
				$term = term_exists( $row['topic'], VAID_GLOSSARY_TAXONOMY );
				if ( ! $term ) {
					$term = wp_insert_term( $row['topic'], VAID_GLOSSARY_TAXONOMY );
				}
				if ( ! is_wp_error( $term ) ) {
					wp_set_object_terms( $post_id, array( (int) $term['term_id'] ), VAID_GLOSSARY_TAXONOMY, false );
				}
			}

			Import_Batch_Log::record( $batch_id, $post_id );
			++$created;
		}

		delete_transient( self::transient_key( $token ) );

		$redirect = add_query_arg(
			array(
				'post_type'      => VAID_GLOSSARY_CPT,
				'page'           => 'vaid-glossary-import',
				'vaid_imported'  => $created,
				'vaid_skipped'   => $skipped,
				'vaid_failed'    => $failed,
				'vaid_batch'     => $batch_id,
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	// -------------------------------------------------------------
	// Rollback
	// -------------------------------------------------------------

	private static function render_recent_batches() {
		if ( isset( $_GET['vaid_imported'] ) ) {
			$failed_count = isset( $_GET['vaid_failed'] ) ? (int) $_GET['vaid_failed'] : 0;
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				sprintf(
					/* translators: 1: created count, 2: skipped count, 3: batch id, 4: failed-insert count */
					esc_html__( 'Import complete: %1$d term(s) created, %2$d row(s) skipped (blocked), %3$d row(s) failed to insert. Batch ID: %4$s', 'vaid-anthropology-glossary' ),
					(int) $_GET['vaid_imported'],
					(int) $_GET['vaid_skipped'],
					$failed_count,
					esc_html( isset( $_GET['vaid_batch'] ) ? sanitize_text_field( wp_unslash( $_GET['vaid_batch'] ) ) : '' )
				)
			);
		}

		$batches = Import_Batch_Log::list_recent_batches();
		if ( ! $batches ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Recent Import Batches', 'vaid-anthropology-glossary' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Batch ID', 'vaid-anthropology-glossary' ); ?></th>
					<th><?php esc_html_e( 'Terms created', 'vaid-anthropology-glossary' ); ?></th>
					<th><?php esc_html_e( 'When', 'vaid-anthropology-glossary' ); ?></th>
					<th><?php esc_html_e( 'Action', 'vaid-anthropology-glossary' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $batches as $batch ) : ?>
				<tr>
					<td><code><?php echo esc_html( $batch->batch_id ); ?></code></td>
					<td><?php echo (int) $batch->item_count; ?></td>
					<td><?php echo esc_html( $batch->created_at ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Move every term created by this batch to Trash? This does not touch any other term.', 'vaid-anthropology-glossary' ) ); ?>');">
							<input type="hidden" name="action" value="vaid_glossary_import_rollback" />
							<input type="hidden" name="vaid_batch_id" value="<?php echo esc_attr( $batch->batch_id ); ?>" />
							<?php wp_nonce_field( self::NONCE_ROLLBACK . '_' . $batch->batch_id ); ?>
							<?php submit_button( __( 'Rollback (trash batch)', 'vaid-anthropology-glossary' ), 'delete small', 'submit', false ); ?>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function handle_rollback() {
		if ( ! current_user_can( VAID_GLOSSARY_CAP_IMPORT ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'vaid-anthropology-glossary' ) );
		}

		$batch_id = isset( $_POST['vaid_batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['vaid_batch_id'] ) ) : '';
		if ( '' === $batch_id ) {
			wp_die( esc_html__( 'Missing batch ID.', 'vaid-anthropology-glossary' ) );
		}

		// v0.4.1 red-team hardening: nonce bound to the specific batch ID.
		check_admin_referer( self::NONCE_ROLLBACK . '_' . $batch_id );

		$count = Import_Batch_Log::rollback( $batch_id );

		$redirect = add_query_arg(
			array(
				'post_type'      => VAID_GLOSSARY_CPT,
				'page'           => 'vaid-glossary-import',
				'vaid_rolled_back' => $count,
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
