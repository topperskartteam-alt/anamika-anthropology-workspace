/**
 * VAID Anthropology Glossary — client-side search + A-Z filter.
 *
 * Round-3 control-room correction: no REST/admin-ajax request at all.
 * Every published term is already server-rendered as a .vaid-glossary-card
 * with its title/aliases/short-definition/first-letter in data-* attributes
 * (see templates/partials/term-card.php); this script only ever
 * shows/hides those existing DOM nodes. No network call is made while
 * typing or when selecting a letter.
 *
 * @namespace VAIDGlossary
 */
( function () {
	'use strict';

	var config = window.VAIDGlossaryConfig || { debounceMs: 250, noResultsText: 'No terms found.' };

	/**
	 * @param {Function} fn
	 * @param {number} wait
	 * @returns {Function}
	 */
	function debounce( fn, wait ) {
		var timer = null;
		return function () {
			var args = arguments;
			var context = this;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				fn.apply( context, args );
			}, wait );
		};
	}

	function initGlossaryHub( root ) {
		var input = root.querySelector( '[data-vaid-glossary-search]' );
		var clearBtn = root.querySelector( '[data-vaid-glossary-clear]' );
		var azTabs = root.querySelectorAll( '[data-vaid-glossary-letter]' );
		var list = root.querySelector( '[data-vaid-glossary-list]' );
		var jsEmptyMessage = root.querySelector( '[data-vaid-glossary-js-empty]' );

		if ( ! list ) {
			return;
		}

		var cards = Array.prototype.slice.call( list.querySelectorAll( '[data-vaid-glossary-card]' ) );
		var activeLetter = null;

		if ( jsEmptyMessage && config.noResultsText ) {
			jsEmptyMessage.textContent = config.noResultsText;
		}

		function applyFilters() {
			var query = input ? input.value.trim().toLowerCase() : '';
			var visibleCount = 0;

			cards.forEach( function ( card ) {
				var matchesQuery = ! query || ( card.getAttribute( 'data-haystack' ) || '' ).indexOf( query ) !== -1;
				var matchesLetter = ! activeLetter || card.getAttribute( 'data-letter' ) === activeLetter;
				var visible = matchesQuery && matchesLetter;

				card.hidden = ! visible;
				if ( visible ) {
					visibleCount++;
				}
			} );

			if ( jsEmptyMessage ) {
				jsEmptyMessage.hidden = visibleCount !== 0;
			}

			if ( clearBtn ) {
				clearBtn.hidden = ! query;
			}
		}

		var debouncedApply = debounce( applyFilters, config.debounceMs || 250 );

		if ( input ) {
			input.addEventListener( 'input', debouncedApply );
		}

		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				if ( input ) {
					input.value = '';
					input.focus();
				}
				applyFilters();
			} );
		}

		azTabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				var letter = tab.getAttribute( 'data-vaid-glossary-letter' );

				// Toggle: selecting the already-active letter clears it
				// (returns to "All"), matching a conventional filter-chip
				// pattern; Figma defines no separate "All" cell to drive
				// this from (see Hub_Renderer::az_letters()).
				if ( activeLetter === letter ) {
					activeLetter = null;
				} else {
					activeLetter = letter;
				}

				azTabs.forEach( function ( t ) {
					var thisIsNowActive = activeLetter !== null && t.getAttribute( 'data-vaid-glossary-letter' ) === activeLetter;
					t.setAttribute( 'aria-pressed', thisIsNowActive ? 'true' : 'false' );
				} );

				applyFilters();
			} );
		} );

		applyFilters();
	}

	function init() {
		var roots = document.querySelectorAll( '.vaid-glossary' );
		roots.forEach( initGlossaryHub );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.VAIDGlossary = window.VAIDGlossary || {};
	window.VAIDGlossary.search = { init: init };
} )();
