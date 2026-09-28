<?php
/**
 * Glossary Hub template. Included by Hub_Renderer::render() with
 * $vaid_glossary_terms, $vaid_glossary_letters, $vaid_glossary_breadcrumb
 * already set in scope. Structure mirrors the verified Figma frames
 * Glossary /D (365:4538) / Glossary /M (453:8439): breadcrumb -> hero
 * (title, subtitle, search) -> A-Z nav -> term card grid.
 *
 * The theme's own header/footer are NOT included here — the Hub
 * deliberately renders only between them (see Hub_Page / Hub_Shortcode).
 *
 * @package VAID\Glossary
 * @var array<int,array> $vaid_glossary_terms
 * @var string[]          $vaid_glossary_letters
 * @var string             $vaid_glossary_breadcrumb
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="vaid-glossary">
	<?php echo $vaid_glossary_breadcrumb; // phpcs:ignore WordPress.Security.EscapeOutput -- already escaped in Breadcrumb::render(). ?>

	<?php include __DIR__ . '/partials/hero.php'; ?>

	<?php include __DIR__ . '/partials/az-nav.php'; ?>

	<div class="vaid-glossary-list" data-vaid-glossary-list>
		<?php if ( empty( $vaid_glossary_terms ) ) : ?>
			<p class="vaid-glossary-no-results" data-vaid-glossary-empty>
				<?php esc_html_e( 'No glossary terms have been published yet.', 'vaid-anthropology-glossary' ); ?>
			</p>
		<?php else : ?>
			<?php foreach ( $vaid_glossary_terms as $vaid_glossary_term ) : ?>
				<?php include __DIR__ . '/partials/term-card.php'; ?>
			<?php endforeach; ?>
		<?php endif; ?>

		<p class="vaid-glossary-no-results vaid-glossary-no-results--js" data-vaid-glossary-js-empty hidden>
			<?php esc_html_e( 'No terms found.', 'vaid-anthropology-glossary' ); ?>
		</p>
	</div>
</div>
