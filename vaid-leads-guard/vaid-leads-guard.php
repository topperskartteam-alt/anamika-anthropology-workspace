<?php
/**
 * Plugin Name:       VAID Leads Guard
 * Plugin URI:        https://vaidsics.com/anthropology/
 * Description:       Shadow-mode duplicate-submission observer for Fluent Forms leads on vaidsics.com/anthropology. v0.1 observes and logs likely duplicates; it never blocks, merges, or alters submissions.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            VAID
 * License:           GPL-2.0-or-later
 * Text Domain:       vaid-leads-guard
 *
 * IMPORTANT: v0.1.0 is SHADOW MODE ONLY. It never blocks, gates, merges,
 * or deletes a Fluent Forms submission. It observes already-inserted
 * entries (post-insert hook) and logs a duplicate-likelihood
 * classification for reporting purposes only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'VAID_LEADS_GUARD_VERSION', '0.1.0' );
define( 'VAID_LEADS_GUARD_DB_VERSION', '1.0.0' );
define( 'VAID_LEADS_GUARD_FILE', __FILE__ );
define( 'VAID_LEADS_GUARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'VAID_LEADS_GUARD_URL', plugin_dir_url( __FILE__ ) );
define( 'VAID_LEADS_GUARD_OPTION_SETTINGS', 'vaid_leads_guard_settings' );
define( 'VAID_LEADS_GUARD_OPTION_SECRET', 'vaid_leads_guard_hmac_secret' );
define( 'VAID_LEADS_GUARD_OPTION_DB_VERSION', 'vaid_leads_guard_db_version' );

require_once VAID_LEADS_GUARD_DIR . 'includes/class-vaid-leads-guard-normalizer.php';
require_once VAID_LEADS_GUARD_DIR . 'includes/class-vaid-leads-guard-classifier.php';
require_once VAID_LEADS_GUARD_DIR . 'includes/class-vaid-leads-guard-fingerprint.php';
require_once VAID_LEADS_GUARD_DIR . 'includes/class-vaid-leads-guard-form-map.php';
require_once VAID_LEADS_GUARD_DIR . 'includes/class-vaid-leads-guard-settings.php';
require_once VAID_LEADS_GUARD_DIR . 'includes/class-vaid-leads-guard-db.php';
require_once VAID_LEADS_GUARD_DIR . 'includes/class-vaid-leads-guard-observer.php';

if ( is_admin() ) {
	require_once VAID_LEADS_GUARD_DIR . 'admin/class-vaid-leads-guard-admin.php';
}

/**
 * Plugin activation: create/upgrade the shadow-observation table and
 * generate a local HMAC secret used only for identity fingerprinting.
 * No data is deleted on activation; re-activating an existing install
 * is safe and idempotent.
 */
function vaid_leads_guard_activate() {
	VAID_Leads_Guard_DB::install();

	if ( false === get_option( VAID_LEADS_GUARD_OPTION_SECRET, false ) ) {
		add_option( VAID_LEADS_GUARD_OPTION_SECRET, wp_generate_password( 64, true, true ), '', 'no' );
	}

	if ( false === get_option( VAID_LEADS_GUARD_OPTION_SETTINGS, false ) ) {
		add_option( VAID_LEADS_GUARD_OPTION_SETTINGS, VAID_Leads_Guard_Settings::defaults() );
	}
}
register_activation_hook( VAID_LEADS_GUARD_FILE, 'vaid_leads_guard_activate' );

/**
 * Plugin deactivation intentionally does nothing destructive: settings
 * and shadow-observation data are left in place so re-activating does
 * not lose audit history. Deletion only ever happens via uninstall.php,
 * and even then the observation table is preserved by default.
 */
function vaid_leads_guard_deactivate() {
	// Intentionally no-op: shadow-mode data is preserved.
}
register_deactivation_hook( VAID_LEADS_GUARD_FILE, 'vaid_leads_guard_deactivate' );

/**
 * Bootstraps the shadow observer once Fluent Forms is confirmed active.
 * Never hard-depends on Fluent Forms constants at plugin load time so
 * activation cannot fatal on a site where FF is missing/updating.
 */
function vaid_leads_guard_bootstrap() {
	if ( version_compare( get_option( VAID_LEADS_GUARD_OPTION_DB_VERSION, '0' ), VAID_LEADS_GUARD_DB_VERSION, '<' ) ) {
		VAID_Leads_Guard_DB::install();
	}

	$observer = new VAID_Leads_Guard_Observer();
	$observer->register_hooks();
}
add_action( 'plugins_loaded', 'vaid_leads_guard_bootstrap' );
