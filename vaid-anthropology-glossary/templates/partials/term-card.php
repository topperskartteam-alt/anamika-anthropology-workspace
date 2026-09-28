<?php
/**
 * Term card partial — matches Figma Term Entry component (365:4400):
 * title, hairline divider, "IN SIMPLE WORDS" eyebrow + short definition.
 * Single visual state only (no hover/variant evidence exists for this
 * component — see Round-1 Figma evidence), and non-interactive/static
 * in v0.4.0 since no term-detail page exists to link to.
 *
 * data-* attributes carry the client-side search index for this card
 * (a combined title+aliases+short-definition haystack, plus first
 * letter), per the build brief's "no REST/admin-ajax request"
 * requirement — the browser filters these already-rendered cards in
 * place instead of calling a server endpoint.
 *
 * v0.4.1 red-team correction: v0.4.0 additionally emitted separate
 * data-title / data-aliases / data-definition attributes that
 * assets/js/glossary-search.js never actually reads (it only reads
 * data-haystack and data-letter) — roughly doubling each card's markup
 * for no functional benefit at scale. Removed. Lowercasing now uses
 * vaid_glossary_mb_strtolower() (Unicode-aware) instead of strtolower(),
 * so accented anthropology terms (e.g. "Lévi-Strauss") match
 * case-insensitively the same way JavaScript's String.toLowerCase() does.
 *
 * @package VAID\Glossary
 * @var array $vaid_glossary_term {id, title, short_definition, aliases, first_letter}
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$vaid_search_haystack = vaid_glossary_mb_strtolower(
	$vaid_glossary_term['title'] . ' ' . implode( ' ', $vaid_glossary_term['aliases'] ) . ' ' . $vaid_glossary_term['short_definition']
);
?>
<article
	class="vaid-glossary-card"
	data-vaid-glossary-card
	data-letter="<?php echo vaid_glossary_esc_data_attr( $vaid_glossary_term['first_letter'] ); ?>"
	data-haystack="<?php echo vaid_glossary_esc_data_attr( $vaid_search_haystack ); ?>"
>
	<h3 class="vaid-glossary-card__title"><?php echo esc_html( $vaid_glossary_term['title'] ); ?></h3>
	<hr class="vaid-glossary-card__divider" />
	<div class="vaid-glossary-card__callout">
		<p class="vaid-glossary-card__eyebrow"><?php esc_html_e( 'In Simple Words', 'vaid-anthropology-glossary' ); ?></p>
		<p class="vaid-glossary-card__definition"><?php echo esc_html( $vaid_glossary_term['short_definition'] ); ?></p>
	</div>
</article>
