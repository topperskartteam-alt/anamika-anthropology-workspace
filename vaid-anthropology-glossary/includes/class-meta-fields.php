<?php
/**
 * Post meta registration for vaid_glossary_term.
 *
 * Short definition + aliases are the only fields rendered publicly in
 * v0.4.0. Related term IDs / examples / sources are registered now
 * (future-ready data model) but are never read by any public template
 * in this pilot.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meta_Fields {

	const SHORT_DEFINITION = VAID_GLOSSARY_META_PREFIX . 'short_definition';
	const ALIASES          = VAID_GLOSSARY_META_PREFIX . 'aliases';
	const FIRST_LETTER     = VAID_GLOSSARY_META_PREFIX . 'first_letter';
	const RELATED_TERM_IDS = VAID_GLOSSARY_META_PREFIX . 'related_term_ids';
	const EXAMPLES         = VAID_GLOSSARY_META_PREFIX . 'examples';
	const SOURCES          = VAID_GLOSSARY_META_PREFIX . 'sources';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'save_post_' . VAID_GLOSSARY_CPT, array( __CLASS__, 'derive_first_letter' ), 20, 1 );
	}

	public static function register_meta() {
		register_post_meta(
			VAID_GLOSSARY_CPT,
			self::SHORT_DEFINITION,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_textarea_field',
				'auth_callback'     => array( __CLASS__, 'can_edit' ),
			)
		);

		register_post_meta(
			VAID_GLOSSARY_CPT,
			self::ALIASES,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_aliases' ),
				'auth_callback'     => array( __CLASS__, 'can_edit' ),
			)
		);

		register_post_meta(
			VAID_GLOSSARY_CPT,
			self::FIRST_LETTER,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( __CLASS__, 'can_edit' ),
			)
		);

		// Future-ready fields — not rendered publicly in v0.4.0.
		register_post_meta(
			VAID_GLOSSARY_CPT,
			self::RELATED_TERM_IDS,
			array(
				'type'          => 'array',
				'single'        => true,
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
				'auth_callback' => array( __CLASS__, 'can_edit' ),
			)
		);

		register_post_meta(
			VAID_GLOSSARY_CPT,
			self::EXAMPLES,
			array(
				'type'          => 'array',
				'single'        => true,
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'auth_callback' => array( __CLASS__, 'can_edit' ),
			)
		);

		register_post_meta(
			VAID_GLOSSARY_CPT,
			self::SOURCES,
			array(
				'type'          => 'array',
				'single'        => true,
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
				'auth_callback' => array( __CLASS__, 'can_edit' ),
			)
		);
	}

	public static function can_edit() {
		return current_user_can( VAID_GLOSSARY_CAP_MANAGE );
	}

	/**
	 * Sanitize a list of alias strings: trims, drops empties/duplicates,
	 * strips tags.
	 *
	 * @param mixed $value Raw aliases value (expected array of strings).
	 * @return array<int,string>
	 */
	public static function sanitize_aliases( $value ) {
		if ( ! is_array( $value ) ) {
			$value = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
		}

		$clean = array();
		foreach ( $value as $alias ) {
			$alias = sanitize_text_field( wp_strip_all_tags( (string) $alias ) );
			$alias = trim( $alias );
			if ( '' !== $alias ) {
				$clean[] = $alias;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Auto-derive and store the first-letter index on every save, from the
	 * post title. This is a read-only/computed field from the admin UI's
	 * point of view — it is not an editable input.
	 *
	 * @param int $post_id Post ID being saved.
	 */
	public static function derive_first_letter( $post_id ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$letter = vaid_glossary_derive_first_letter( $post->post_title );

		// update_post_meta() during save_post can re-trigger save_post in
		// some setups; guard with a static flag to avoid recursion.
		static $running = array();
		if ( ! empty( $running[ $post_id ] ) ) {
			return;
		}
		$running[ $post_id ] = true;

		update_post_meta( $post_id, self::FIRST_LETTER, $letter );

		unset( $running[ $post_id ] );
	}
}
