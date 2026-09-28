<?php
/**
 * Custom list-table columns for the vaid_glossary_term CPT screen, and
 * extending admin search to also match against aliases.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_List_Table {

	public static function init() {
		add_filter( 'manage_' . VAID_GLOSSARY_CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . VAID_GLOSSARY_CPT . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-' . VAID_GLOSSARY_CPT . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'handle_sorting_and_alias_search' ) );
	}

	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['vaid_glossary_first_letter'] = __( 'Letter', 'vaid-anthropology-glossary' );
				$new['vaid_glossary_aliases']      = __( 'Aliases', 'vaid-anthropology-glossary' );
			}
		}
		return $new;
	}

	public static function render_column( $column, $post_id ) {
		if ( 'vaid_glossary_first_letter' === $column ) {
			echo esc_html( get_post_meta( $post_id, Meta_Fields::FIRST_LETTER, true ) ?: '—' );
		}

		if ( 'vaid_glossary_aliases' === $column ) {
			$aliases = get_post_meta( $post_id, Meta_Fields::ALIASES, true );
			if ( is_array( $aliases ) && $aliases ) {
				$text = implode( ', ', array_map( 'sanitize_text_field', $aliases ) );
				echo esc_html( mb_strlen( $text ) > 60 ? mb_substr( $text, 0, 57 ) . '…' : $text );
			} else {
				echo '—';
			}
		}
	}

	public static function sortable_columns( $columns ) {
		$columns['vaid_glossary_first_letter'] = 'vaid_glossary_first_letter';
		return $columns;
	}

	/**
	 * Two behaviours on the CPT list screen:
	 * 1. Sorting by the Letter column, via the derived meta field.
	 * 2. Extending the admin search box to also match aliases (meta),
	 *    so an editor can find a term by a known synonym.
	 *
	 * @param \WP_Query $query Current query.
	 */
	public static function handle_sorting_and_alias_search( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( VAID_GLOSSARY_CPT !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( 'vaid_glossary_first_letter' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', Meta_Fields::FIRST_LETTER );
			$query->set( 'orderby', 'meta_value' );
		}

		$search = $query->get( 's' );
		if ( $search ) {
			// Widen the query to also match posts whose alias meta value
			// contains the search term, via WP core's dedicated
			// posts_search filter (avoids hand-parsing the WHERE clause).
			add_filter( 'posts_search', array( __CLASS__, 'alias_search' ), 10, 2 );
		}
	}

	/**
	 * Append an "OR post ID IN (alias matches)" clause to the core search
	 * SQL, scoped only to this CPT's list-table query.
	 *
	 * @param string    $search Existing search SQL fragment.
	 * @param \WP_Query $query  Current query.
	 * @return string
	 */
	public static function alias_search( $search, $query ) {
		global $wpdb;

		if ( ! is_admin() || VAID_GLOSSARY_CPT !== $query->get( 'post_type' ) || ! $query->get( 's' ) ) {
			return $search;
		}

		$term = $query->get( 's' );

		$matching_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s",
				Meta_Fields::ALIASES,
				'%' . $wpdb->esc_like( $term ) . '%'
			)
		);

		if ( empty( $matching_ids ) ) {
			return $search;
		}

		$ids_sql = implode( ',', array_map( 'absint', $matching_ids ) );
		$search  = ' AND (' . trim( preg_replace( '/^\s*AND\s*/', '', $search ), '() ' ) . " OR {$wpdb->posts}.ID IN ({$ids_sql}))";

		return $search;
	}
}
