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
	 * v0.4.1 red-team correction: `show_in_rest` is now `false`. WordPress's
	 * default REST posts controller does NOT gate anonymous read access on
	 * the `public` argument — a non-public, non-publicly-queryable CPT with
	 * `show_in_rest => true` still exposes every *published* record (plus
	 * any meta registered with `show_in_rest => true`) to anonymous users
	 * at `/wp-json/wp/v2/{rest_base}`, with no capability check on GET
	 * requests for published content. That directly contradicts the "one
	 * canonical Hub, no alternate public term endpoints" goal, so REST is
	 * off entirely in v0.4.1. Nothing in this plugin's actual admin UI
	 * depends on REST — the Add/Edit metabox is a classic $_POST form, and
	 * with `show_in_rest => false` the post edit screen simply falls back
	 * to the classic editor for this CPT, which is what this plugin's UI
	 * already assumes. If a future feature genuinely needs REST access, it
	 * should register a purpose-built route with an explicit
	 * `permission_callback`, not flip this flag back on.
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
				'show_in_rest'        => false, // See docblock above — anonymous REST read exposure risk on a non-public CPT.
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
				'show_in_rest'      => false, // Same REST-exposure reasoning as the CPT itself — see register_post_type().
				'hierarchical'      => true,
				'rewrite'           => false,
				'query_var'         => false,
			)
		);
	}
}
