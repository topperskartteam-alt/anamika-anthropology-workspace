<?php
/**
 * CSV export of all glossary terms. Column schema intentionally mirrors
 * what CSV_Import expects, so export -> edit -> re-import is a supported
 * round trip.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CSV_Export {

	const NONCE_ACTION = 'vaid_glossary_export';

	public static function init() {
		add_action( 'admin_post_vaid_glossary_export_csv', array( __CLASS__, 'handle_export' ) );
	}

	public static function render_page() {
		if ( ! current_user_can( VAID_GLOSSARY_CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vaid-anthropology-glossary' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Export Glossary Terms (CSV)', 'vaid-anthropology-glossary' ); ?></h1>
			<p><?php esc_html_e( 'Exports all glossary terms (any status) as a CSV with columns: title, short_definition, aliases, topic, status. This file can be re-imported using Import CSV.', 'vaid-anthropology-glossary' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vaid_glossary_export_csv" />
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<?php submit_button( __( 'Download CSV', 'vaid-anthropology-glossary' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_export() {
		if ( ! current_user_can( VAID_GLOSSARY_CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'vaid-anthropology-glossary' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$posts = get_posts(
			array(
				'post_type'      => VAID_GLOSSARY_CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="vaid-anthropology-glossary-export-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'title', 'short_definition', 'aliases', 'topic', 'status' ) );

		foreach ( $posts as $post ) {
			$aliases = get_post_meta( $post->ID, Meta_Fields::ALIASES, true );
			$topics  = get_the_terms( $post->ID, VAID_GLOSSARY_TAXONOMY );
			$topic   = ( $topics && ! is_wp_error( $topics ) ) ? $topics[0]->name : '';

			fputcsv(
				$out,
				array(
					vaid_glossary_sanitize_csv_cell( $post->post_title ),
					vaid_glossary_sanitize_csv_cell( get_post_meta( $post->ID, Meta_Fields::SHORT_DEFINITION, true ) ),
					vaid_glossary_sanitize_csv_cell( is_array( $aliases ) ? implode( '|', $aliases ) : '' ),
					vaid_glossary_sanitize_csv_cell( $topic ),
					$post->post_status,
				)
			);
		}

		fclose( $out );
		exit;
	}
}
