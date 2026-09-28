<?php
/**
 * Custom post type + taxonomy registration.
 *
 * V1 visibility is intentionally non-public: terms are admin/CMS records
 * only in the v0.4.0 pilot (Round-3 control-room correction, section 5).
 * No single-term rewrite rules, no term sitemap entries, no frontend
 * rewrite flush beyond the one that already happens on activation.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPT {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
	}

	/**
	 * Register the vaid_glossary_term CPT.
	 *
	 * Visibility flags match the Round-3 control-room spec exactly:
	 * public/publicly_queryable/has_archive/rewrite all false,
	 * show_ui/show_in_menu/show_in_rest true, exclude_from_search true.
	 */
	public static function register_post_type() {
		$labels = array(
			'name'               => __( 'Glossary Terms', 'vaid-anthropology-glossary' ),
			'singular_name'      => __( 'Glossary Term', 'vaid-anthropology-glossary' ),
			'menu_name'          => __( 'Glossary', 'vaid-anthropology-glossary' ),
			'add_new'            => __( 'Add New Term', 'vaid-anthropology-glossary' ),
			'add_new_item'       => __( 'Add New Glossary Term', 'vaid-anthropology-glossary' ),
			'edit_item'          => __( 'Edit Glossary Term', 'vaid-anthropology-glossary' ),
			'new_item'           => __( 'New Glossary Term', 'vaid-anthropology-glossary' ),
			'view_item'          => __( 'View Glossary Term', 'vaid-anthropology-glossary' ),
			'search_items'       => __( 'Search Glossary Terms', 'vaid-anthropology-glossary' ),
			'not_found'          => __( 'No glossary terms found.', 'vaid-anthropology-glossary' ),
			'not_found_in_trash' => __( 'No glossary terms found in Trash.', 'vaid-anthropology-glossary' ),
			'all_items'          => __( 'All Terms', 'vaid-anthropology-glossary' ),
		);

		register_post_type(
			VAID_GLOSSARY_CPT,
			array(
				'labels'              => $labels,
				'description'         => __( 'Anthropology & UPSC glossary entries (admin-managed; not individually public in v0.4.0).', 'vaid-anthropology-glossary' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => false, // Custom top-level menu is added by Admin_Menu, this CPT screen is attached to it.
				'show_in_admin_bar'   => true,
				'show_in_rest'        => true,
				'rest_base'           => 'vaid-glossary-terms',
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => true,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Register the future-ready, non-public vaid_glossary_topic taxonomy.
	 *
	 * Registered now so a future syllabus/topic-linkage feature needs no
	 * schema migration. Not exposed in any public UI or admin term list
	 * in v0.4.0 beyond the raw field on the Add/Edit screen (kept in an
	 * explicitly separated "future fields" area per the build brief).
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			VAID_GLOSSARY_TAXONOMY,
			VAID_GLOSSARY_CPT,
			array(
				'labels'            => array(
					'name'          => __( 'Glossary Topics', 'vaid-anthropology-glossary' ),
					'singular_name' => __( 'Glossary Topic', 'vaid-anthropology-glossary' ),
				),
				'public'            => false,
				'publicly_queryable' => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => false,
				'query_var'         => false,
			)
		);
	}
}
