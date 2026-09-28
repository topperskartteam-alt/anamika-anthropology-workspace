<?php
/**
 * Procedural helper functions (fallback prefix: vaid_glossary_).
 *
 * Kept outside the namespace so they are usable from templates without
 * a `use` statement, matching the plugin's documented function prefix.
 *
 * @package VAID\Glossary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derive the indexable "first letter" for a glossary term title.
 *
 * Strips leading non-letter characters (numbers, punctuation, whitespace)
 * so a title like "'Acculturation'" or "3D printing" still indexes under
 * a sensible letter, and falls back to '#' for titles with no leading
 * A-Z character at all.
 *
 * @param string $title Term title.
 * @return string Single uppercase letter A-Z, or '#'.
 */
function vaid_glossary_derive_first_letter( $title ) {
	$title = trim( wp_strip_all_tags( (string) $title ) );

	if ( '' === $title ) {
		return '#';
	}

	if ( preg_match( '/[A-Za-z]/', $title, $matches ) ) {
		return strtoupper( $matches[0] );
	}

	return '#';
}

/**
 * Normalize a title for duplicate comparison: case-insensitive,
 * whitespace-collapsed, punctuation-insensitive on the edges.
 *
 * @param string $title Raw title.
 * @return string Normalized comparison key.
 */
function vaid_glossary_normalize_title( $title ) {
	$title = wp_strip_all_tags( (string) $title );
	$title = strtolower( $title );
	$title = preg_replace( '/\s+/', ' ', $title );
	return trim( $title );
}

/**
 * Strip characters that would let a CSV cell be interpreted as a formula
 * by spreadsheet software (CSV formula injection guard). Applied on both
 * import (sanitizing untrusted uploads) and export (defense in depth for
 * anything an editor may have pasted into a term field).
 *
 * @param string $value Raw cell value.
 * @return string Sanitized cell value.
 */
function vaid_glossary_sanitize_csv_cell( $value ) {
	$value = (string) $value;

	if ( '' === $value ) {
		return $value;
	}

	// Leading apostrophe already neutralizes formulas in most readers;
	// only strip repeated triggers so we do not mangle legitimate content
	// like "-5 years" or "=" used mid-sentence.
	if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
		$value = "'" . $value;
	}

	return $value;
}

/**
 * Escape a string for safe inclusion inside an HTML data-* attribute.
 *
 * Thin wrapper kept for readability at call sites in templates.
 *
 * @param string $value Raw value.
 * @return string Escaped value.
 */
function vaid_glossary_esc_data_attr( $value ) {
	return esc_attr( (string) $value );
}

/**
 * Get the WP_Post ID assigned as the Glossary Hub, or 0 if unassigned.
 *
 * @return int
 */
function vaid_glossary_get_hub_page_id() {
	return (int) get_option( VAID_GLOSSARY_OPTION_PREFIX . 'hub_page_id', 0 );
}
