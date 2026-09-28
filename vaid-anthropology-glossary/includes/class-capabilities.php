<?php
/**
 * Capability registration for the plugin's custom capabilities.
 *
 * @package VAID\Glossary
 */

namespace VAID\Glossary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {

	/**
	 * Grant the plugin's two custom capabilities to Administrator and
	 * Editor roles on activation. Import stays restricted to
	 * Administrator only, matching the Round-2 decision that CSV import
	 * is an admin-level action, not a general editor action.
	 */
	public static function add_capabilities() {
		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->add_cap( VAID_GLOSSARY_CAP_MANAGE );
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( VAID_GLOSSARY_CAP_MANAGE );
			$admin->add_cap( VAID_GLOSSARY_CAP_IMPORT );
		}
	}
}
