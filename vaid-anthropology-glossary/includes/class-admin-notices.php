<?php
/**
 * Global admin notices for the plugin (Hub page not assigned, etc.).
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Notices {

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'hub_not_assigned_notice' ) );
	}

	public static function hub_not_assigned_notice() {
		if ( ! current_user_can( VAID_GLOSSARY_CAP_MANAGE ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, VAID_GLOSSARY_CPT ) ) {
			return;
		}

		if ( vaid_glossary_get_hub_page_id() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'The VAID Anthropology Glossary Hub page is not assigned yet — the public Hub will not appear anywhere on the site.', 'vaid-anthropology-glossary' ),
			esc_url( admin_url( 'edit.php?post_type=' . VAID_GLOSSARY_CPT . '&page=vaid-glossary-settings' ) ),
			esc_html__( 'Assign it in Glossary → Settings', 'vaid-anthropology-glossary' )
		);
	}
}
