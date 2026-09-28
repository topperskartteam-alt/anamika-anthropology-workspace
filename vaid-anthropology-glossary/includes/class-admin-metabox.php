<?php
/**
 * Add/Edit Term metabox: required Short Definition, Aliases, read-only
 * First Letter, and a clearly separated "Future fields" section (Topic,
 * Related Terms, Examples, Sources) that is not rendered publicly.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Metabox {

	const NONCE_ACTION = 'vaid_glossary_save_meta';
	const NONCE_FIELD   = 'vaid_glossary_meta_nonce';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post_' . VAID_GLOSSARY_CPT, array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function register() {
		add_meta_box(
			'vaid-glossary-details',
			__( 'Glossary Details', 'vaid-anthropology-glossary' ),
			array( __CLASS__, 'render' ),
			VAID_GLOSSARY_CPT,
			'normal',
			'high'
		);
	}

	public static function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$short_definition = get_post_meta( $post->ID, Meta_Fields::SHORT_DEFINITION, true );
		$aliases          = get_post_meta( $post->ID, Meta_Fields::ALIASES, true );
		$aliases_text     = is_array( $aliases ) ? implode( ', ', $aliases ) : '';
		$first_letter     = get_post_meta( $post->ID, Meta_Fields::FIRST_LETTER, true );
		$topics            = get_the_terms( $post->ID, VAID_GLOSSARY_TAXONOMY );
		$selected_topic_id = ( $topics && ! is_wp_error( $topics ) ) ? $topics[0]->term_id : 0;
		$all_topics        = get_terms( array( 'taxonomy' => VAID_GLOSSARY_TAXONOMY, 'hide_empty' => false ) );
		$examples          = get_post_meta( $post->ID, Meta_Fields::EXAMPLES, true );
		$examples_text     = is_array( $examples ) ? implode( "\n", $examples ) : '';
		$related_ids       = get_post_meta( $post->ID, Meta_Fields::RELATED_TERM_IDS, true );
		$related_text      = is_array( $related_ids ) ? implode( ', ', $related_ids ) : '';
		?>
		<p>
			<label for="vaid_glossary_short_definition"><strong><?php esc_html_e( 'Short Definition', 'vaid-anthropology-glossary' ); ?></strong> <span style="color:#b32d2e;">*</span></label><br />
			<textarea id="vaid_glossary_short_definition" name="vaid_glossary_short_definition" rows="3" class="large-text" required><?php echo esc_textarea( $short_definition ); ?></textarea>
			<span class="description"><?php esc_html_e( 'This is the "In Simple Words" text shown on the public glossary card. Required.', 'vaid-anthropology-glossary' ); ?></span>
		</p>

		<p>
			<label for="vaid_glossary_aliases"><strong><?php esc_html_e( 'Aliases / synonyms', 'vaid-anthropology-glossary' ); ?></strong></label><br />
			<input type="text" id="vaid_glossary_aliases" name="vaid_glossary_aliases" class="large-text" value="<?php echo esc_attr( $aliases_text ); ?>" />
			<span class="description"><?php esc_html_e( 'Comma-separated. Used for admin and public search matching, not shown on the card.', 'vaid-anthropology-glossary' ); ?></span>
		</p>

		<p>
			<strong><?php esc_html_e( 'First Letter (auto)', 'vaid-anthropology-glossary' ); ?></strong><br />
			<code><?php echo esc_html( $first_letter ?: __( '(saved automatically)', 'vaid-anthropology-glossary' ) ); ?></code>
			<span class="description"><?php esc_html_e( 'Derived automatically from the title on save. Not editable.', 'vaid-anthropology-glossary' ); ?></span>
		</p>

		<hr />
		<p><strong><?php esc_html_e( 'Future fields (not shown publicly in v0.4.0)', 'vaid-anthropology-glossary' ); ?></strong></p>

		<p>
			<label for="vaid_glossary_topic"><?php esc_html_e( 'Syllabus Topic', 'vaid-anthropology-glossary' ); ?></label><br />
			<select id="vaid_glossary_topic" name="vaid_glossary_topic">
				<option value="0"><?php esc_html_e( '— None —', 'vaid-anthropology-glossary' ); ?></option>
				<?php if ( ! is_wp_error( $all_topics ) ) : foreach ( $all_topics as $topic ) : ?>
					<option value="<?php echo (int) $topic->term_id; ?>" <?php selected( $selected_topic_id, $topic->term_id ); ?>>
						<?php echo esc_html( $topic->name ); ?>
					</option>
				<?php endforeach; endif; ?>
			</select>
		</p>

		<p>
			<label for="vaid_glossary_related_term_ids"><?php esc_html_e( 'Related term IDs', 'vaid-anthropology-glossary' ); ?></label><br />
			<input type="text" id="vaid_glossary_related_term_ids" name="vaid_glossary_related_term_ids" class="large-text" value="<?php echo esc_attr( $related_text ); ?>" placeholder="<?php esc_attr_e( 'Comma-separated post IDs', 'vaid-anthropology-glossary' ); ?>" />
		</p>

		<p>
			<label for="vaid_glossary_examples"><?php esc_html_e( 'Examples (one per line)', 'vaid-anthropology-glossary' ); ?></label><br />
			<textarea id="vaid_glossary_examples" name="vaid_glossary_examples" rows="3" class="large-text"><?php echo esc_textarea( $examples_text ); ?></textarea>
		</p>

		<p class="description"><?php esc_html_e( 'Long explanation can be written in the main content editor above — reserved for a future term-detail page.', 'vaid-anthropology-glossary' ); ?></p>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( VAID_GLOSSARY_CAP_MANAGE ) ) {
			return;
		}

		if ( isset( $_POST['vaid_glossary_short_definition'] ) ) {
			update_post_meta( $post_id, Meta_Fields::SHORT_DEFINITION, sanitize_textarea_field( wp_unslash( $_POST['vaid_glossary_short_definition'] ) ) );
		}

		if ( isset( $_POST['vaid_glossary_aliases'] ) ) {
			$aliases = Meta_Fields::sanitize_aliases( wp_unslash( $_POST['vaid_glossary_aliases'] ) );
			update_post_meta( $post_id, Meta_Fields::ALIASES, $aliases );
		}

		if ( isset( $_POST['vaid_glossary_topic'] ) ) {
			$topic_id = absint( $_POST['vaid_glossary_topic'] );
			if ( $topic_id ) {
				wp_set_object_terms( $post_id, array( $topic_id ), VAID_GLOSSARY_TAXONOMY, false );
			} else {
				wp_set_object_terms( $post_id, array(), VAID_GLOSSARY_TAXONOMY, false );
			}
		}

		if ( isset( $_POST['vaid_glossary_related_term_ids'] ) ) {
			$ids = array_filter( array_map( 'absint', explode( ',', wp_unslash( $_POST['vaid_glossary_related_term_ids'] ) ) ) );
			update_post_meta( $post_id, Meta_Fields::RELATED_TERM_IDS, array_values( $ids ) );
		}

		if ( isset( $_POST['vaid_glossary_examples'] ) ) {
			$lines = array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( wp_unslash( $_POST['vaid_glossary_examples'] ) ) ) ) );
			update_post_meta( $post_id, Meta_Fields::EXAMPLES, array_values( $lines ) );
		}
	}
}
