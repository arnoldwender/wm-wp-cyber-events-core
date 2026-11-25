<?php
/**
 * Fired during plugin deactivation.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Deactivator {

	/**
	 * Clear both scheduled events on deactivation. Until 2026-09-15 the retention event stayed
	 * scheduled after deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'cybevents_process_queue_cron' );
		wp_clear_scheduled_hook( 'cybevents_gdpr_retention_cron' );
	}
}
