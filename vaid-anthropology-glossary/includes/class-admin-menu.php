<?php
/**
 * Top-level "Glossary" admin menu grouping All Terms, Add New, Import,
 * Export, and Settings (Hub page assignment) under one place.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Menu {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_vaid_glossary_save_settings', array( __CLASS__, 'handle_save_settings' ) );
	}

	public static function register_menu() {
		$cap = VAID_GLOSSARY_CAP_MANAGE;

		add_menu_page(
			__( 'Glossary', 'vaid-anthropology-glossary' ),
			__( 'Glossary', 'vaid-anthropology-glossary' ),
			$cap,
			'edit.php?post_type=' . VAID_GLOSSARY_CPT,
			'',
			'dashicons-book-alt',
			26
		);

		add_submenu_page(
			'edit.php?post_type=' . VAID_GLOSSARY_CPT,
			__( 'All Terms', 'vaid-anthropology-glossary' ),
			__( 'All Terms', 'vaid-anthropology-glossary' ),
			$cap,
			'edit.php?post_type=' . VAID_GLOSSARY_CPT
		);

		add_submenu_page(
			'edit.php?post_type=' . VAID_GLOSSARY_CPT,
			__( 'Add New Term', 'vaid-anthropology-glossary' ),
			__( 'Add New', 'vaid-anthropology-glossary' ),
			$cap,
			'post-new.php?post_type=' . VAID_GLOSSARY_CPT
		);

		add_submenu_page(
			'edit.php?post_type=' . VAID_GLOSSARY_CPT,
			__( 'Import Terms (CSV)', 'vaid-anthropology-glossary' ),
			__( 'Import CSV', 'vaid-anthropology-glossary' ),
			VAID_GLOSSARY_CAP_IMPORT,
			'vaid-glossary-import',
			array( 'VAID\\Glossary\\CSV_Import', 'render_page' )
		);

		add_submenu_page(
			'edit.php?post_type=' . VAID_GLOSSARY_CPT,
			__( 'Export Terms (CSV)', 'vaid-anthropology-glossary' ),
			__( 'Export CSV', 'vaid-anthropology-glossary' ),
			$cap,
			'vaid-glossary-export',
			array( 'VAID\\Glossary\\CSV_Export', 'render_page' )
		);

		add_submenu_page(
			'edit.php?post_type=' . VAID_GLOSSARY_CPT,
			__( 'Glossary Settings', 'vaid-anthropology-glossary' ),
			__( 'Settings', 'vaid-anthropology-glossary' ),
			$cap,
			'vaid-glossary-settings',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'vaid_glossary_settings',
			VAID_GLOSSARY_OPTION_PREFIX . 'hub_page_id',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			)
		);
	}

	public static function render_settings_page() {
		if ( ! current_user_can( VAID_GLOSSARY_CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'vaid-anthropology-glossary' ) );
		}

		$hub_page_id = vaid_glossary_get_hub_page_id();
		$pages       = get_pages( array( 'sort_column' => 'post_title' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Glossary Settings', 'vaid-anthropology-glossary' ); ?></h1>

			<?php if ( ! $hub_page_id ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'No Glossary Hub page is assigned yet. The public Hub will not render anywhere until you assign an existing page below.', 'vaid-anthropology-glossary' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vaid_glossary_save_settings" />
				<?php wp_nonce_field( 'vaid_glossary_save_settings', 'vaid_glossary_settings_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vaid_glossary_hub_page_id"><?php esc_html_e( 'Glossary Hub page', 'vaid-anthropology-glossary' ); ?></label></th>
						<td>
							<select name="vaid_glossary_hub_page_id" id="vaid_glossary_hub_page_id">
								<option value="0"><?php esc_html_e( '— Not assigned —', 'vaid-anthropology-glossary' ); ?></option>
								<?php foreach ( $pages as $page ) : ?>
									<option value="<?php echo (int) $page->ID; ?>" <?php selected( $hub_page_id, $page->ID ); ?>>
										<?php echo esc_html( $page->post_title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Choose an existing WordPress page to render the Glossary Hub on. The plugin never creates or publishes a page automatically — you must select one explicitly. The page will inherit your active theme\'s header and footer.', 'vaid-anthropology-glossary' ); ?>
								<br />
								<?php esc_html_e( 'Alternatively, you can insert the [vaid_glossary_hub] shortcode directly into any page/post instead of assigning one here.', 'vaid-anthropology-glossary' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'vaid-anthropology-glossary' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_save_settings() {
		if ( ! current_user_can( VAID_GLOSSARY_CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'vaid-anthropology-glossary' ) );
		}

		check_admin_referer( 'vaid_glossary_save_settings', 'vaid_glossary_settings_nonce' );

		$hub_page_id = isset( $_POST['vaid_glossary_hub_page_id'] ) ? absint( $_POST['vaid_glossary_hub_page_id'] ) : 0;
		update_option( VAID_GLOSSARY_OPTION_PREFIX . 'hub_page_id', $hub_page_id );

		$redirect = add_query_arg(
			array(
				'post_type' => VAID_GLOSSARY_CPT,
				'page'      => 'vaid-glossary-settings',
				'updated'   => '1',
			),
			admin_url( 'edit.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
