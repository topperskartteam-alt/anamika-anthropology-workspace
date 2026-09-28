<?php
/**
 * Enqueues the Hub's CSS/JS only on requests that actually render it —
 * tracked via a render-time flag set by Hub_Renderer, since the Hub can
 * appear via the assigned Settings page OR the [vaid_glossary_hub]
 * shortcode, and we do not want to guess which page(s) need the assets
 * up front.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frontend_Assets {

	private static $hub_rendered = false;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ), 20 );
	}

	public static function mark_hub_rendered() {
		self::$hub_rendered = true;
	}

	public static function maybe_enqueue() {
		// the_content runs before wp_enqueue_scripts fires assets to the
		// browser within the same request lifecycle is not guaranteed by
		// hook order alone, so also pre-check the assigned Hub page/shortcode
		// presence directly against the current queried object as a
		// second, independent signal.
		if ( ! self::$hub_rendered && ! self::current_request_likely_has_hub() ) {
			return;
		}

		wp_enqueue_style(
			'vaid-glossary',
			VAID_GLOSSARY_PLUGIN_URL . 'assets/css/glossary.css',
			array(),
			VAID_GLOSSARY_VERSION
		);

		wp_enqueue_script(
			'vaid-glossary-search',
			VAID_GLOSSARY_PLUGIN_URL . 'assets/js/glossary-search.js',
			array(),
			VAID_GLOSSARY_VERSION,
			true
		);

		wp_localize_script(
			'vaid-glossary-search',
			'VAIDGlossaryConfig',
			array(
				'debounceMs'  => 250,
				'noResultsText' => __( 'No terms found.', 'vaid-anthropology-glossary' ),
			)
		);
	}

	/**
	 * Cheap pre-check so assets can be enqueued at the standard
	 * wp_enqueue_scripts priority without depending on the_content having
	 * already executed for this request.
	 *
	 * @return bool
	 */
	private static function current_request_likely_has_hub() {
		if ( is_admin() ) {
			return false;
		}

		$hub_page_id = vaid_glossary_get_hub_page_id();
		if ( $hub_page_id && is_page( $hub_page_id ) ) {
			return true;
		}

		if ( is_singular() ) {
			global $post;
			if ( $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, 'vaid_glossary_hub' ) ) {
				return true;
			}
		}

		return false;
	}
}
