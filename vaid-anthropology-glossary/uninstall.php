<?php
/**
 * Uninstall handler.
 *
 * v0.4.0 pilot behaviour: preserves ALL data by default. No terms,
 * options, or the import-batch-log table are removed. There is no
 * one-click destructive "delete everything" path in this pilot — that
 * is explicitly deferred (per the Round-3 build brief, section 8) to a
 * future version with explicit confirmation and a backup workflow.
 *
 * @package VAID\Glossary
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Intentionally no-op: activation/deactivation/uninstall must all
// preserve data in this pilot. Nothing is deleted here.
