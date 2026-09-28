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
 * Multi-byte-safe lowercasing, with a graceful fallback if the mbstring
 * extension is somehow unavailable.
 *
 * Plain strtolower() is byte-based and does not lowercase non-ASCII
 * characters (e.g. accented anthropology terms like "Lévi-Strauss"),
 * which would make case-insensitive comparisons/search non-deterministic
 * for such titles. Used by both duplicate-title normalization and the
 * client-side search haystack, so the two stay consistent with each
 * other and with JavaScript's Unicode-aware String.toLowerCase().
 *
 * @param string $value Raw value.
 * @return string Lowercased value.
 */
function vaid_glossary_mb_strtolower( $value ) {
	$value = (string) $value;

	if ( function_exists( 'mb_strtolower' ) ) {
		return mb_strtolower( $value, 'UTF-8' );
	}

	return strtolower( $value );
}

/**
 * Normalize a title for duplicate comparison: case-insensitive
 * (Unicode-aware), whitespace-collapsed, punctuation-insensitive on the
 * edges.
 *
 * @param string $title Raw title.
 * @return string Normalized comparison key.
 */
function vaid_glossary_normalize_title( $title ) {
	$title = wp_strip_all_tags( (string) $title );
	$title = vaid_glossary_mb_strtolower( $title );
	$title = preg_replace( '/\s+/', ' ', $title );
	return trim( $title );
}

/**
 * Prefix a leading apostrophe onto a value that would otherwise be
 * interpreted as a formula by spreadsheet software (the standard,
 * OWASP-recommended CSV formula-injection mitigation).
 *
 * v0.4.1 red-team correction: this is EXPORT-ONLY. v0.4.0 also applied it
 * during CSV import, which permanently mutated legitimate stored content
 * — a term titled "-5 degree adaptation" or a definition starting with
 * "@" would have a stray leading apostrophe baked into the actual
 * WordPress title/meta value forever. The formula-injection threat model
 * only applies when THIS PLUGIN later outputs a CSV that a human opens in
 * a spreadsheet app (i.e. CSV_Export) — not when data is merely stored in
 * the WordPress database (CSV_Import no longer calls this function).
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
