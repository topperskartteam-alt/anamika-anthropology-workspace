<?php
/**
 * Uninstall handler.
 *
 * Deliberately conservative: uninstalling (delete via wp-admin Plugins
 * screen) does NOT touch the shadow-observation table or its data.
 * That table holds no raw PII (only HMAC fingerprints and entry IDs),
 * so leaving it behind carries no privacy cost, and silently deleting
 * an audit trail on uninstall is exactly what the v0.1 spec forbids.
 *
 * Explicit, admin-triggered data removal is left for a future version
 * with its own confirmation step. This file intentionally removes only
 * the plugin's own configuration options, not observation history.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Settings and the HMAC secret are safe to remove: they hold no audit
// history and losing the secret only means future fingerprints won't
// match past ones, which is an expected consequence of uninstalling.
delete_option( 'vaid_leads_guard_settings' );
delete_option( 'vaid_leads_guard_hmac_secret' );

// Intentionally NOT removed on uninstall:
// - the vaid_leads_guard_db_version option
// - the {$wpdb->prefix}vaid_leads_guard_observations table and its rows
//
// A future version may add an explicit, separately-confirmed
// "Delete all shadow-audit data" admin action. Uninstall alone must
// never trigger it.
