<?php
/**
 * WM Suite Cyber Events Core — Suite Hub Spoke Adapter
 *
 * Implements the WM Suite Hub contract (WM_Plugin_Module_Interface): health from the
 * audit chain, the queue and the Brevo settings, measured telemetry, and audit chain verification.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	return;
}

use ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface;

class CyberEvents_Spoke_Adapter implements WM_Plugin_Module_Interface {

	public function get_module_slug(): string {
		return 'wm-cyber-events-core';
	}

	public function get_name(): string {
		return 'WM Cyber Events Core';
	}

	public function get_version(): string {
		return defined( 'CYBEREVENTS_VERSION' ) ? CYBEREVENTS_VERSION : '1.2.3';
	}

	public function get_description(): string {
		return 'Veranstaltungen, Sessions und Anmeldungen mit Double Opt-in über Brevo, Session-Buchung mit Kapazitätsprüfung, Check-in, Audit-Kette, CSV-, DATEV- und iCalendar-Export.';
	}

	/**
	 * ERROR with a broken audit chain; NEEDS_CONFIG without a Brevo API key or webhook secret (no
	 * confirmation mail can be sent, no bounce processed); DEGRADED when the queue event is not
	 * scheduled or jobs have failed. Until 2026-09-15 this always returned HEALTHY.
	 */
	public function get_health_status(): string {
		$audit = CyberEvents_Database::verify_audit_chain();
		if ( empty( $audit['valid'] ) ) {
			return 'ERROR';
		}

		$settings = get_option( 'cybevents_settings', array() );
		if ( ! CyberEvents_Brevo_API::is_configured() || '' === (string) ( $settings['brevo_webhook_secret'] ?? '' ) ) {
			return 'NEEDS_CONFIG';
		}

		$metrics = $this->get_telemetry_metrics();
		if ( ! $metrics['queue_event_scheduled'] || $metrics['failed_queue_tasks'] > 0 ) {
			return 'DEGRADED';
		}

		return 'HEALTHY';
	}

	public function get_sbom_data(): array {
		return [
			'bom-ref'     => 'pkg:wordpress/wm-cyber-events-core@' . $this->get_version(),
			'type'        => 'application',
			'name'        => $this->get_name(),
			'version'     => $this->get_version(),
			'description' => $this->get_description(),
			'scope'       => 'required',
			'hashes'      => [
				[
					'alg'     => 'SHA-256',
					'content' => hash_file( 'sha256', __DIR__ . '/../wm-cyber-events-core.php' ),
				],
			],
			// The licence the plugin ships under: the header, readme.txt and LICENSE say GPLv2 or later.
			// Until 2026-11-05 this declared a proprietary licence with an id and a name at once;
			// `Proprietary` is not an SPDX id, and CycloneDX takes an SPDX id or a name, not both.
			'licenses'    => [
				[
					'license' => [
						'id' => 'GPL-2.0-or-later',
					],
				],
			],
			// Features, not standards: until 2026-09-15 this listed "ZUGFeRD 2.3 / Factur-X / EN 16931"
			// and "BFSG 2025 / BITV 2.0 / WCAG 2.2 AA", which the code does not establish.
			'properties'  => [
				[
					'name'  => 'wm:features:registration',
					'value' => 'registration form and REST route, Brevo double opt-in with confirmation route, session booking with a capacity check in one UPDATE, check-in',
				],
				[
					'name'  => 'wm:features:exports',
					'value' => 'attendee CSV, DATEV EXTF batch, CII XML draft for invoices, iCalendar',
				],
				[
					'name'  => 'wm:features:audit',
					'value' => 'HMAC-SHA256 audit chain with tip mirror and pseudonymisation after 90 days',
				],
			],
		];
	}

	public function get_dependencies(): array {
		return [];
	}

	public function get_settings_url(): ?string {
		return admin_url( 'admin.php?page=cybevents-settings' );
	}

	public function get_telemetry_metrics(): array {
		global $wpdb;
		$table_events   = $wpdb->prefix . 'cybevents_events';
		$table_sessions = $wpdb->prefix . 'cybevents_sessions';
		$table_regs     = $wpdb->prefix . 'cybevents_registrations';
		$table_queue    = $wpdb->prefix . 'cybevents_queue';

		$events_count   = 0;
		$sessions_count = 0;
		$regs_count     = 0;
		$pending_doi    = 0;
		$queue_pending  = 0;
		$queue_failed   = 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_events ) ) === $table_events ) {
			$events_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_events} WHERE id > %d", 0 ) );
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_sessions ) ) === $table_sessions ) {
			$sessions_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_sessions} WHERE id > %d", 0 ) );
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_regs ) ) === $table_regs ) {
			$regs_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_regs} WHERE id > %d", 0 ) );
			$pending_doi = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_regs} WHERE status = %s", 'pending_doi' ) );
		}

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_queue ) ) === $table_queue ) {
			$queue_pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_queue} WHERE status = %s", 'pending' ) );
			$queue_failed  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_queue} WHERE status = %s", 'failed' ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Until 2026-09-15 two literals were reported here: 'INNODB_ROW_LOCKS_ACTIVE' and
		// 'ZUGFeRD_2.3_READY'.
		return [
			'total_events'          => $events_count,
			'total_sessions'        => $sessions_count,
			'total_registrations'   => $regs_count,
			'pending_doi'           => $pending_doi,
			'pending_queue_tasks'   => $queue_pending,
			'failed_queue_tasks'    => $queue_failed,
			'queue_event_scheduled' => (bool) wp_next_scheduled( 'cybevents_process_queue_cron' ),
			'brevo_configured'      => CyberEvents_Brevo_API::is_configured(),
			'audit_key_source'      => CyberEvents_Database::audit_key_source(),
			// The waiting room and where it counts: with the database backend a refused request still
			// writes a transient, which is the difference the hub should be able to see.
			'admission_limit'       => CyberEvents_Waiting_Room::limit(),
			'admission_backend'     => CyberEvents_Waiting_Room::backend(),
		];
	}

	public function execute_action( string $action, array $params = [] ): array {
		switch ( $action ) {
			case 'self_test':
			case 'audit_verify':
				$audit_result = CyberEvents_Database::verify_audit_chain();
				if ( ! empty( $audit_result['valid'] ) ) {
					return [
						'success' => true,
						'message' => sprintf( 'Audit-Kette intakt: %d Einträge inhaltlich geprüft, %d pseudonymisiert (Schlüsselquelle: %s).', (int) $audit_result['checked'], (int) $audit_result['pseudonymized'], esc_html( (string) $audit_result['key_source'] ) ),
					];
				}
				return [
					'success' => false,
					'message' => sprintf( 'Audit-Ketten-Integritätsprüfung fehlgeschlagen: %s', esc_html( (string) ( $audit_result['error'] ?? 'Unbekannter Fehler' ) ) ),
				];

			default:
				return [
					'success' => false,
					'message' => sprintf( 'Unbekannte Spoke-Aktion "%s".', esc_html( $action ) ),
				];
		}
	}
}
