<?php
/**
 * A-Z navigation partial — matches Figma Tab Item master (7:62), which
 * defines exactly two states: State=Active (7:63) and State=Inactive
 * (7:66). No third "disabled" variant exists, so a letter with zero
 * terms still renders as a normal (Inactive) button rather than a
 * separate disabled style — selecting it shows the minimal no-results
 * text per the build brief, it does not change the button's own look.
 *
 * The 27th cell seen in the Figma desktop frame (365:4014 … 365:4392)
 * could not be confirmed as "All" or any other specific label before
 * the Figma read budget for this session was exhausted (see
 * BUILD-REPORT.md) — per instruction not to guess, only the 26
 * unambiguous A-Z letters are rendered here.
 *
 * @package VAID\Glossary
 * @var string[] $vaid_glossary_letters
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<nav class="vaid-glossary-az" aria-label="<?php esc_attr_e( 'Jump to letter', 'vaid-anthropology-glossary' ); ?>" data-vaid-glossary-az>
	<ul class="vaid-glossary-az__list">
		<?php foreach ( $vaid_glossary_letters as $letter ) : ?>
			<li class="vaid-glossary-az__item">
				<button
					type="button"
					class="vaid-glossary-az__tab"
					data-vaid-glossary-letter="<?php echo esc_attr( $letter ); ?>"
					aria-pressed="false"
				>
					<?php echo esc_html( $letter ); ?>
				</button>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
