<?php
/**
 * Term card partial — matches Figma Term Entry component (365:4400):
 * title, hairline divider, "IN SIMPLE WORDS" eyebrow + short definition.
 * Single visual state only (no hover/variant evidence exists for this
 * component — see Round-1 Figma evidence), and non-interactive/static
 * in v0.4.0 since no term-detail page exists to link to.
 *
 * data-* attributes carry the client-side search index for this card
 * (title/aliases/short-definition/first-letter), per the build brief's
 * "no REST/admin-ajax request" requirement — the browser filters these
 * already-rendered cards in place instead of calling a server endpoint.
 *
 * @package VAID\Glossary
 * @var array $vaid_glossary_term {id, title, short_definition, aliases, first_letter}
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$vaid_search_haystack = strtolower(
	$vaid_glossary_term['title'] . ' ' . implode( ' ', $vaid_glossary_term['aliases'] ) . ' ' . $vaid_glossary_term['short_definition']
);
?>
<article
	class="vaid-glossary-card"
	data-vaid-glossary-card
	data-title="<?php echo vaid_glossary_esc_data_attr( strtolower( $vaid_glossary_term['title'] ) ); ?>"
	data-aliases="<?php echo vaid_glossary_esc_data_attr( strtolower( implode( ' ', $vaid_glossary_term['aliases'] ) ) ); ?>"
	data-definition="<?php echo vaid_glossary_esc_data_attr( strtolower( $vaid_glossary_term['short_definition'] ) ); ?>"
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
