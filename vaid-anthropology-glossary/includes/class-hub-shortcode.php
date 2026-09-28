<?php
/**
 * [vaid_glossary_hub] shortcode — fallback insertion mechanism for when
 * an admin prefers to place the Hub via shortcode rather than the
 * Settings page assignment.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hub_Shortcode {

	public static function init() {
		add_shortcode( 'vaid_glossary_hub', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		return Hub_Renderer::render();
	}
}
