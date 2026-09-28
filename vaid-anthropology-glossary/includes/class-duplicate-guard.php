<?php
/**
 * Duplicate governance — shared by the Add/Edit Term admin save path and
 * the CSV import validator, so both enforce identical rules.
 *
 * Rules (Round-3 control-room correction, section 6):
 * - normalized exact-title collision            -> HARD BLOCK
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
	 * exactly matches an existing term. Implemented via wp_insert_post_data
	 * so it applies to both classic Add/Edit and REST/block-editor saves.
	 *
	 * @param array $data    Slashed post data about to be saved.
	 * @param array $postarr Raw $_POST-like array, includes ID for edits.
	 * @return array
	 */
	public static function block_exact_duplicate_on_save( $data, $postarr ) {
		if ( VAID_GLOSSARY_CPT !== $data['post_type'] || 'auto-draft' === $data['post_status'] ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$title   = isset( $data['post_title'] ) ? $data['post_title'] : '';

		if ( '' === trim( wp_strip_all_tags( $title ) ) ) {
			return $data;
		}

		$index = self::get_existing_title_index( $post_id );
		$key   = vaid_glossary_normalize_title( $title );

		if ( isset( $index[ $key ] ) ) {
			// Revert to draft rather than silently publishing a duplicate;
			// surface the block via a transient admin notice.
			$data['post_status'] = 'draft';

			if ( $post_id ) {
				set_transient( 'vaid_glossary_dup_block_' . get_current_user_id(), $key, 60 );
			}
		}

		return $data;
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
