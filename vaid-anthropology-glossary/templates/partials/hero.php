<?php
/**
 * Hero partial — matches Figma Hero (275:4300 desktop / 453:8442 mobile):
 * title, subtitle, Search Field (7:220 master, Default state).
 *
 * @package VAID\Glossary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<header class="vaid-glossary-hero">
	<h1 class="vaid-glossary-hero__title"><?php esc_html_e( 'Anthropology & UPSC Glossary', 'vaid-anthropology-glossary' ); ?></h1>
	<p class="vaid-glossary-hero__subtitle"><?php esc_html_e( 'Technical terms explained in plain language', 'vaid-anthropology-glossary' ); ?></p>

	<div class="vaid-glossary-search">
		<label class="screen-reader-text" for="vaid-glossary-search-input"><?php esc_html_e( 'Search for a term', 'vaid-anthropology-glossary' ); ?></label>
		<div class="vaid-glossary-search__field">
			<svg class="vaid-glossary-search__icon" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
				<circle cx="7" cy="7" r="5.25" fill="none" stroke="currentColor" stroke-width="1.5"></circle>
				<line x1="11" y1="11" x2="15" y2="15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"></line>
			</svg>
			<input
				type="search"
				id="vaid-glossary-search-input"
				class="vaid-glossary-search__input"
				placeholder="<?php esc_attr_e( 'Search for a term...', 'vaid-anthropology-glossary' ); ?>"
				autocomplete="off"
				data-vaid-glossary-search
			/>
			<button type="button" class="vaid-glossary-search__clear" data-vaid-glossary-clear hidden>
				<span class="screen-reader-text"><?php esc_html_e( 'Clear search', 'vaid-anthropology-glossary' ); ?></span>
				&times;
			</button>
		</div>
	</div>
</header>
