<?php
/**
 * Fetches published terms and renders the Hub template. Shared by both
 * the Settings-assigned-page mechanism (Hub_Page) and the
 * [vaid_glossary_hub] shortcode fallback (Hub_Shortcode), so the two
 * insertion methods can never visually diverge.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hub_Renderer {

	/**
	 * @return string Rendered Hub HTML.
	 */
	public static function render() {
		Frontend_Assets::mark_hub_rendered();

		$terms = self::get_published_terms();

		ob_start();
		$vaid_glossary_terms      = $terms;
		$vaid_glossary_letters    = self::az_letters();
		$vaid_glossary_breadcrumb = Breadcrumb::render();
		include VAID_GLOSSARY_PLUGIN_DIR . 'templates/hub.php';
		return ob_get_clean();
	}

	/**
	 * All published glossary terms, each reduced to exactly the fields the
	 * public Hub needs (title, aliases, short definition, first letter) —
	 * future-ready fields (related terms, examples, sources, long
	 * explanation) are deliberately not fetched here, since nothing in
	 * v0.4.0 renders them.
	 *
	 * @return array<int,array>
	 */
	public static function get_published_terms() {
		$posts = get_posts(
			array(
				'post_type'      => VAID_GLOSSARY_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$terms = array();

		foreach ( $posts as $post ) {
			$aliases = get_post_meta( $post->ID, Meta_Fields::ALIASES, true );

			$terms[] = array(
				'id'               => $post->ID,
				'title'            => get_the_title( $post ),
				'short_definition' => (string) get_post_meta( $post->ID, Meta_Fields::SHORT_DEFINITION, true ),
				'aliases'          => is_array( $aliases ) ? $aliases : array(),
				'first_letter'     => (string) get_post_meta( $post->ID, Meta_Fields::FIRST_LETTER, true ) ?: vaid_glossary_derive_first_letter( get_the_title( $post ) ),
			);
		}

		return $terms;
	}

	/**
	 * The A-Z navigation's cell labels, in Figma-verified order.
	 *
	 * Figma evidence (Round 1): the desktop Tab Item strip has 27 cells
	 * (`365:4014` … `365:4392`); the master Tab Item component only
	 * defines two states, Active/Inactive, with no separate label
	 * enumerated anywhere else in the file, and every sampled instance
	 * renders the same placeholder letter "A". There is therefore no
	 * confirmed evidence that the 27th cell is "All" (or anything else) —
	 * per the build brief's instruction not to guess this, the 27th cell
	 * is rendered here as a 27th alphabet-adjacent placeholder only if a
	 * real, non-alphabetic label were confirmed; since none was, this
	 * ships as the 26 standard A-Z letters and the count intentionally
	 * does not force a 27th cell. See BUILD-REPORT.md for the flagged
	 * owner decision.
	 *
	 * @return string[] 26 letters, A-Z.
	 */
	public static function az_letters() {
		return range( 'A', 'Z' );
	}
}
