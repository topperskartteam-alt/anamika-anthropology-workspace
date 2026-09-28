<?php
/**
 * Renders the Glossary Hub on the admin-assigned page (Glossary ->
 * Settings), inside the theme's normal header/footer. Never creates or
 * publishes any content automatically.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hub_Page {

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'inject_on_assigned_page' ) );
	}

	/**
	 * Append the Hub markup to the_content only when rendering the exact
	 * page the admin assigned in Settings. Uses the_content (not a
	 * template_include page-template swap) specifically so the theme's
	 * existing header/footer, sidebar, and page-level SEO handling all
	 * continue to run completely unmodified around it.
	 *
	 * @param string $content Original page content.
	 * @return string
	 */
	public static function inject_on_assigned_page( $content ) {
		if ( is_admin() || ! is_page() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$hub_page_id = vaid_glossary_get_hub_page_id();

		if ( ! $hub_page_id || get_the_ID() !== $hub_page_id ) {
			return $content;
		}

		return $content . Hub_Renderer::render();
	}
}
