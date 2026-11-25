<?php
/**
 * WM Suite Cyber Events Core — Opt-in Uninstaller (D-UNINSTALL).
 *
 * Preserves all event, session, registration, invoice, waiting-room, queue, and audit records
 * by default when the plugin is uninstalled. Data and tables are removed only when the site
 * administrator explicitly enables `wm_cybevents_delete_data_on_uninstall` in the plugin settings.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$cybevents_settings    = get_option( 'cybevents_settings', array() );
$cybevents_delete_data = (bool) get_option( 'wm_cybevents_delete_data_on_uninstall', false )
	|| ( is_array( $cybevents_settings ) && ! empty( $cybevents_settings['delete_data_on_uninstall'] ) );

if ( ! $cybevents_delete_data ) {
	return;
}

global $wpdb;

// 1. Delete Options
delete_option( 'cybevents_settings' );
delete_option( 'wm_cybevents_delete_data_on_uninstall' );
delete_option( 'cybevents_db_version' );
delete_option( 'cybevents_audit_chain_tip' );
delete_option( 'cybevents_invoice_sequence' );
delete_option( 'cybevents_active_theme' );
delete_option( 'cybevents_custom_tokens' );
delete_option( 'cybevents_demo_page_ids' );
if ( function_exists( 'delete_site_option' ) ) {
	delete_site_option( 'cybevents_settings' );
	delete_site_option( 'wm_cybevents_delete_data_on_uninstall' );
}

// 2. Clear Scheduled Crons
wp_clear_scheduled_hook( 'cybevents_process_queue_cron' );
wp_clear_scheduled_hook( 'cybevents_gdpr_retention_cron' );

// 3. Drop All 7 Custom Tables
$cybevents_tables = array(
	$wpdb->prefix . 'cybevents_events',
	$wpdb->prefix . 'cybevents_sessions',
	$wpdb->prefix . 'cybevents_registrations',
	$wpdb->prefix . 'cybevents_registration_sessions',
	$wpdb->prefix . 'cybevents_invoices',
	$wpdb->prefix . 'cybevents_queue',
	$wpdb->prefix . 'cybevents_audit_log',
);

foreach ( $cybevents_tables as $cybevents_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$cybevents_table}" );
}
