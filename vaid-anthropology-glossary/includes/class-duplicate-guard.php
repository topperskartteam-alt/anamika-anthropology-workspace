<?php
/**
 * Duplicate governance — shared by the Add/Edit Term admin save path and
 * the CSV import validator, so both enforce identical rules.
 *
 * Rules (Round-3 control-room correction, section 6):
 * - normalized exact-title collision            -> HARD BLOCK (v0.4.1: a
 *   genuine pre-insert wp_die() stop on the interactive admin save path —
 *   see block_exact_duplicate_on_save() docblock for the AJAX exception)
 * - exact alias-to-existing-title collision      -> WARN, import row blocked unless resolved
 * - alias-to-alias collision                     -> WARN/flag, not silent overwrite
 * - near/fuzzy title similarity                  -> WARN ONLY, never auto-block
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Duplicate_Guard {

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_block_notice' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'block_exact_duplicate_on_save' ), 10, 2 );
	}

	/**
	 * Build a lookup of normalized-title => post_id for all existing
	 * glossary terms, optionally excluding one post ID (the one being
	 * saved/edited).
	 *
	 * @param int $exclude_id Post ID to exclude from the lookup.
	 * @return array<string,int>
	 */
	public static function get_existing_title_index( $exclude_id = 0 ) {
		$posts = get_posts(
			array(
				'post_type'      => VAID_GLOSSARY_CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'exclude'        => $exclude_id ? array( $exclude_id ) : array(),
			)
		);

		$index = array();
		foreach ( $posts as $post_id ) {
			$title = get_the_title( $post_id );
			$index[ vaid_glossary_normalize_title( $title ) ] = $post_id;
		}

		return $index;
	}

	/**
	 * Build a lookup of normalized-alias => [post_id, alias] for all
	 * existing glossary terms' aliases.
	 *
	 * @param int $exclude_id Post ID to exclude.
	 * @return array<string,array{post_id:int,alias:string}>
	 */
	public static function get_existing_alias_index( $exclude_id = 0 ) {
		$posts = get_posts(
			array(
				'post_type'      => VAID_GLOSSARY_CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'exclude'        => $exclude_id ? array( $exclude_id ) : array(),
			)
		);

		$index = array();
		foreach ( $posts as $post_id ) {
			$aliases = get_post_meta( $post_id, Meta_Fields::ALIASES, true );
			if ( ! is_array( $aliases ) ) {
				continue;
			}
			foreach ( $aliases as $alias ) {
				$key           = vaid_glossary_normalize_title( $alias );
				$index[ $key ] = array(
					'post_id' => $post_id,
					'alias'   => $alias,
				);
			}
		}

		return $index;
	}

	/**
	 * Hard-block save of a new/edited glossary term whose normalized title
	 * exactly matches an existing term, via the `wp_insert_post_data`
	 * filter — this runs INSIDE wp_insert_post(), before its `$wpdb->insert()`
	 * / `$wpdb->update()` call, so it is a genuine pre-insert intervention,
	 * not a post-save cleanup.
	 *
	 * v0.4.1 red-team correction: v0.4.0 always downgraded a duplicate save
	 * to Draft status, which still inserted a duplicate row into the
	 * database — not a real "HARD BLOCK" as the product rule requires. For
	 * the plugin's primary path (the classic Add/Edit Term screen — this
	 * CPT now forces the classic editor since REST/Gutenberg is disabled,
	 * see class-cpt.php) this now calls `wp_die()` to genuinely halt
	 * execution before any row is written: zero duplicate post is created.
	 * Automated/AJAX-driven paths (Quick Edit inline-save, the
	 * heartbeat/autosave endpoint) deliberately keep the older
	 * non-disruptive Draft-downgrade behavior instead of wp_die(), because
	 * a hard stop there would surface as a broken/corrupted inline response
	 * rather than a readable admin notice — this is the "does not corrupt
	 * autosaves/revisions" requirement from the brief.
	 *
	 * Autosaves and revisions never reach this method in the first place:
	 * WordPress stores both as `post_type = 'revision'` rows, which fail
	 * the post_type check below regardless of the DOING_AUTOSAVE check.
	 *
	 * @param array $data    Slashed post data about to be saved.
	 * @param array $postarr Raw $_POST-like array, includes ID for edits.
	 * @return array
	 */
	public static function block_exact_duplicate_on_save( $data, $postarr ) {
		if ( VAID_GLOSSARY_CPT !== $data['post_type'] ) {
			return $data;
		}

		if ( in_array( $data['post_status'], array( 'auto-draft', 'trash' ), true ) ) {
			return $data;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$title   = isset( $data['post_title'] ) ? $data['post_title'] : '';

		if ( '' === trim( wp_strip_all_tags( $title ) ) ) {
			return $data;
		}

		$index = self::get_existing_title_index( $post_id );
		$key   = vaid_glossary_normalize_title( $title );

		if ( ! isset( $index[ $key ] ) ) {
			return $data;
		}

		// Automated/inline-editing paths: keep the safe, non-disruptive
		// fallback rather than a hard wp_die() stop.
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			$data['post_status'] = 'draft';
			if ( $post_id ) {
				set_transient( 'vaid_glossary_dup_block_' . get_current_user_id(), $key, 60 );
			}
			return $data;
		}

		// Primary interactive path: true hard block. Execution stops here;
		// wp_insert_post() never reaches its DB write, so no duplicate row
		// is ever created.
		set_transient( 'vaid_glossary_dup_block_' . get_current_user_id(), $key, 60 );

		wp_die(
			esc_html__( 'This term title already exists in the glossary. No new entry was created. Go back and rename it, or edit the existing term instead.', 'vaid-anthropology-glossary' ),
			esc_html__( 'Duplicate glossary term', 'vaid-anthropology-glossary' ),
			array(
				'response'  => 400,
				'back_link' => true,
			)
		);
	}

	public static function maybe_show_block_notice() {
		$screen = get_current_screen();
		if ( ! $screen || VAID_GLOSSARY_CPT !== $screen->post_type ) {
			return;
		}

		$key = get_transient( 'vaid_glossary_dup_block_' . get_current_user_id() );
		if ( ! $key ) {
			return;
		}

		delete_transient( 'vaid_glossary_dup_block_' . get_current_user_id() );

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'This term title already exists in the glossary. The entry was saved as a draft, not published, to avoid a duplicate term. Please rename it or edit the existing term instead.', 'vaid-anthropology-glossary' )
		);
	}

	/**
	 * Evaluate one CSV import row against existing data and the rest of
	 * the batch, per the governance rules above. Used only by CSV_Import.
	 *
	 * @param array $row              Row with 'title' and 'aliases' (array).
	 * @param array $existing_titles  Normalized-title => post_id.
	 * @param array $existing_aliases Normalized-alias => {post_id, alias}.
	 * @param array $batch_titles_seen Normalized-title => row_number, rows already processed in this same CSV.
	 * @return array{status:string,messages:string[]} status: 'ok'|'warn'|'block'
	 */
	public static function evaluate_import_row( array $row, array $existing_titles, array $existing_aliases, array $batch_titles_seen ) {
		$messages = array();
		$status   = 'ok';

		$title_key = vaid_glossary_normalize_title( $row['title'] );

		// Exact-title collision with existing DB record -> HARD BLOCK.
		if ( isset( $existing_titles[ $title_key ] ) ) {
			$status     = 'block';
			$messages[] = __( 'Exact title already exists in the glossary (existing term will not be overwritten).', 'vaid-anthropology-glossary' );
		}

		// Exact-title collision within the same CSV -> HARD BLOCK the repeat.
		if ( isset( $batch_titles_seen[ $title_key ] ) ) {
			$status     = 'block';
			$messages[] = sprintf(
				/* translators: %d: row number */
				__( 'Duplicate title within this CSV (already seen at row %d).', 'vaid-anthropology-glossary' ),
				$batch_titles_seen[ $title_key ]
			);
		}

		// Alias-to-existing-title collision -> WARN, block unless resolved.
		foreach ( (array) $row['aliases'] as $alias ) {
			$alias_key = vaid_glossary_normalize_title( $alias );
			if ( isset( $existing_titles[ $alias_key ] ) ) {
				$status     = 'block';
				$messages[] = sprintf(
					/* translators: %s: alias text */
					__( 'Alias "%s" matches an existing term title exactly. Resolve before import.', 'vaid-anthropology-glossary' ),
					$alias
				);
			}

			// Alias-to-alias collision -> WARN/flag only, does not block.
			if ( isset( $existing_aliases[ $alias_key ] ) ) {
				$status     = ( 'block' === $status ) ? 'block' : 'warn';
				$messages[] = sprintf(
					/* translators: 1: alias text, 2: existing term title */
					__( 'Alias "%1$s" is already used by existing term "%2$s". Flagged for review, not blocked.', 'vaid-anthropology-glossary' ),
					$alias,
					get_the_title( $existing_aliases[ $alias_key ]['post_id'] )
				);
			}
		}

		// Near/fuzzy title similarity -> WARN ONLY, never auto-block.
		foreach ( $existing_titles as $existing_key => $existing_id ) {
			if ( $existing_key === $title_key ) {
				continue; // Already handled as exact match above.
			}
			similar_text( $existing_key, $title_key, $percent );
			if ( $percent >= 85.0 ) {
				$status     = ( 'block' === $status ) ? 'block' : 'warn';
				$messages[] = sprintf(
					/* translators: %s: similar existing term title */
					__( 'Title is very similar to existing term "%s". Please review for a possible duplicate.', 'vaid-anthropology-glossary' ),
					get_the_title( $existing_id )
				);
			}
		}

		return array(
			'status'   => $status,
			'messages' => $messages,
		);
	}
}
