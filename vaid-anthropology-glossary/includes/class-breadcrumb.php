<?php
/**
 * Breadcrumb adapter: reuse Yoast's breadcrumb rendering when it is
 * available and enabled on the site, otherwise fall back to a single
 * plugin-owned "Home / Glossary" breadcrumb. Never renders both, so the
 * Hub never shows a duplicate breadcrumb.
 *
 * Final output is always "Home / Glossary" (translated) — this
 * deliberately does NOT reproduce the "Home / Interview Guidance" text
 * found in the Figma breadcrumb instances during Round 1, which was
 * identified there as a content defect in the design file, not a spec
 * to implement.
 *
 * Which path is taken (Yoast vs. fallback) cannot be verified from this
 * repository build alone — it depends on live theme/Yoast configuration.
 * The `vaid_glossary_use_yoast_breadcrumb` filter makes this explicitly
 * testable/configurable, per the build brief, rather than hard-coded.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Breadcrumb {

	/**
	 * @return string Breadcrumb HTML (a single <nav>, never duplicated).
	 */
	public static function render() {
		$use_yoast = function_exists( 'yoast_breadcrumb' )
			&& apply_filters( 'vaid_glossary_use_yoast_breadcrumb', true );

		if ( $use_yoast ) {
			ob_start();
			yoast_breadcrumb(
				'<nav class="vaid-glossary-breadcrumb vaid-glossary-breadcrumb--yoast" aria-label="' . esc_attr__( 'Breadcrumb', 'vaid-anthropology-glossary' ) . '">',
				'</nav>'
			);
			$output = ob_get_clean();

			if ( '' !== trim( $output ) ) {
				return $output;
			}
			// Yoast produced no output (e.g. breadcrumbs disabled in
			// Yoast settings even though the function exists) — fall
			// through to the plugin fallback below.
		}

		return self::render_fallback();
	}

	/**
	 * Minimal single-source fallback breadcrumb: Home / Glossary.
	 * "Home" links to the site's front page; "Glossary" is the current,
	 * non-linked crumb, matching standard breadcrumb conventions.
	 *
	 * @return string
	 */
	private static function render_fallback() {
		return sprintf(
			'<nav class="vaid-glossary-breadcrumb vaid-glossary-breadcrumb--fallback" aria-label="%1$s"><a href="%2$s">%3$s</a><span aria-hidden="true"> / </span><span aria-current="page">%4$s</span></nav>',
			esc_attr__( 'Breadcrumb', 'vaid-anthropology-glossary' ),
			esc_url( home_url( '/' ) ),
			esc_html__( 'Home', 'vaid-anthropology-glossary' ),
			esc_html__( 'Glossary', 'vaid-anthropology-glossary' )
		);
	}
}
