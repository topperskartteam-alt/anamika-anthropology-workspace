<?php
/**
 * Plugin Name:       VAID Anthropology Glossary
 * Plugin URI:        https://vaidsics.example/
 * Description:       Pilot build of the VAID Anthropology & UPSC Glossary — admin-managed glossary terms with a Figma-faithful public Hub (hero, search, A-Z navigation, term cards).
 * Version:           0.4.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            VAID
 * Text Domain:       vaid-anthropology-glossary
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// ---------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------

define( 'VAID_GLOSSARY_VERSION', '0.4.1' );
define( 'VAID_GLOSSARY_PLUGIN_FILE', __FILE__ );
define( 'VAID_GLOSSARY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VAID_GLOSSARY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'VAID_GLOSSARY_CPT', 'vaid_glossary_term' );
define( 'VAID_GLOSSARY_TAXONOMY', 'vaid_glossary_topic' );
define( 'VAID_GLOSSARY_OPTION_PREFIX', 'vaid_glossary_' );
define( 'VAID_GLOSSARY_META_PREFIX', '_vaid_glossary_' );
define( 'VAID_GLOSSARY_CAP_MANAGE', 'manage_vaid_glossary' );
define( 'VAID_GLOSSARY_CAP_IMPORT', 'import_vaid_glossary_terms' );

// ---------------------------------------------------------------------
// Minimal manual autoload (small plugin — no Composer dependency needed)
// ---------------------------------------------------------------------

spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, __NAMESPACE__ . '\\' ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( __NAMESPACE__ . '\\' ) );
		$relative = str_replace( '\\', '/', $relative );
		$file     = VAID_GLOSSARY_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

require_once VAID_GLOSSARY_PLUGIN_DIR . 'includes/functions.php';

// ---------------------------------------------------------------------
// Activation / deactivation
// ---------------------------------------------------------------------

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate_plugin' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate_plugin' );

/**
 * Activation: register CPT/taxonomy, create the import-batch log table,
 * seed default options, add capabilities.
 *
 * v0.4.1 red-team correction: no flush_rewrite_rules() call anywhere in
 * this plugin. The CPT and taxonomy are both registered with
 * `rewrite => false` and `has_archive => false` — this plugin owns no
 * rewrite rule of any kind, so there is nothing for it to flush, on
 * activation, deactivation, or any frontend request. (v0.4.0 called
 * flush_rewrite_rules() here "to be safe"; that could not be justified
 * against a real registered rewrite, so it was removed rather than kept
 * as an unnecessary full rewrite-rules rebuild.)
 */
function activate_plugin() {
	CPT::register_post_type();
	CPT::register_taxonomy();

	Import_Batch_Log::maybe_create_table();

	if ( false === get_option( VAID_GLOSSARY_OPTION_PREFIX . 'version' ) ) {
		update_option( VAID_GLOSSARY_OPTION_PREFIX . 'version', VAID_GLOSSARY_VERSION );
	}

	if ( false === get_option( VAID_GLOSSARY_OPTION_PREFIX . 'hub_page_id' ) ) {
		update_option( VAID_GLOSSARY_OPTION_PREFIX . 'hub_page_id', 0 );
	}

	Capabilities::add_capabilities();
}

/**
 * Deactivation: preserve all data. No rewrite flush (see activate_plugin()
 * docblock) and no option/meta/post deletion (see uninstall.php for the
 * equally non-destructive uninstall behaviour).
 */
function deactivate_plugin() {
	// Intentionally empty — deactivation must not remove or alter any data.
}

// ---------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------

add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );

function bootstrap() {
	load_plugin_textdomain( 'vaid-anthropology-glossary', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	CPT::init();
	Meta_Fields::init();
	Duplicate_Guard::init();

	if ( is_admin() ) {
		Admin_Menu::init();
		Admin_List_Table::init();
		Admin_Metabox::init();
		CSV_Export::init();
		CSV_Import::init();
		Admin_Notices::init();
	}

	Hub_Page::init();
	Hub_Shortcode::init();
	Frontend_Assets::init();
}
