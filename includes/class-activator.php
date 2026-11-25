<?php
/**
 * Plugin Activator and Schema Migrations.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Activator {

	/** Bumped whenever the schema in create_schema() changes. 3: audit_log.residual_mac (2026-09-15). */
	const DB_VERSION = '3';

	/**
	 * Activation: schema, default settings and cron events. Demo data is opt-in via Settings or WP-CLI.
	 */
	public static function activate() {
		self::create_schema();

		// Setup default options
		$default_settings = array(
			'brevo_api_key'            => '',
			'brevo_webhook_secret'     => '',
			'brevo_doi_template_id'    => 1,
			'brevo_list_id'            => 2,
			// Waiting room off until an operator sets a limit: it changes how the public routes answer.
			'admission_per_minute'     => 0,
			'invoice_prefix'           => 'CE-2026-',
			'invoice_counter'          => 1000,
			'friendly_captcha_sitekey' => '',
			'friendly_captcha_api_key' => '',
			'enable_friendly_captcha'  => 0,
			'datev_beraternummer'      => '1001',
			'datev_mandantennummer'    => '10001',
			'datev_sachkontenlaenge'   => '4',
			'datev_erloeskonto'        => '8400',
			'datev_forderungen'        => '1400',
			'seller_name'              => '',
			'seller_street'            => '',
			'seller_postcode'          => '',
			'seller_city'              => '',
			'seller_country'           => 'DE',
			'seller_vat_id'            => '',
			'seller_tax_number'        => '',
			'seller_email'             => '',
			'seller_iban'              => '',
		);
		add_option( 'cybevents_settings', $default_settings );

		self::ensure_cron_events();
	}

	/**
	 * Schema migration for installs that are already active. Runs dbDelta and ensures cron schedules.
	 */
	public static function migrate() {
		self::create_schema();
		self::ensure_cron_events();
	}

	/**
	 * Adds the 'every_minute' recurrence the queue event uses. The label is not translated: this
	 * filter can run before init, where a translation call triggers a notice since WordPress 6.7.
	 *
	 * @param array $schedules Registered schedules.
	 * @return array
	 */
	public static function register_cron_schedules( $schedules ) {
		if ( ! isset( $schedules['every_minute'] ) ) {
			$schedules['every_minute'] = array(
				'interval' => MINUTE_IN_SECONDS,
				'display'  => 'Every minute (WM Cyber Events queue)',
			);
		}
		return $schedules;
	}

	/**
	 * Schedules the queue and retention events when they are missing.
	 */
	public static function ensure_cron_events() {
		if ( ! wp_next_scheduled( 'cybevents_process_queue_cron' ) ) {
			wp_schedule_event( time(), 'every_minute', 'cybevents_process_queue_cron' );
		}
		if ( ! wp_next_scheduled( 'cybevents_gdpr_retention_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'cybevents_gdpr_retention_cron' );
		}
	}

	/**
	 * Create or update the plugin tables.
	 */
	public static function create_schema() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// 1. Events Table
		$sql_events = "CREATE TABLE {$wpdb->prefix}cybevents_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_post_id bigint(20) unsigned DEFAULT NULL,
			title varchar(255) NOT NULL,
			slug varchar(200) NOT NULL,
			description longtext,
			start_date datetime NOT NULL,
			end_date datetime NOT NULL,
			location varchar(255) DEFAULT '',
			max_capacity int(11) NOT NULL DEFAULT 0,
			is_paid tinyint(1) NOT NULL DEFAULT 0,
			ticket_price decimal(10,2) NOT NULL DEFAULT 0.00,
			status varchar(50) NOT NULL DEFAULT 'active',
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY wp_post_id (wp_post_id),
			KEY status (status)
		) $charset_collate ENGINE=InnoDB;";
		dbDelta( $sql_events );

		// 2. Sessions / Tracks Table
		$sql_sessions = "CREATE TABLE {$wpdb->prefix}cybevents_sessions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			wp_post_id bigint(20) unsigned DEFAULT NULL,
			title varchar(255) NOT NULL,
			description longtext,
			speaker varchar(255) DEFAULT '',
			raum varchar(100) DEFAULT '',
			start_time datetime NOT NULL,
			end_time datetime NOT NULL,
			kapazitaet int(11) NOT NULL DEFAULT 0,
			belegt int(11) NOT NULL DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY event_id (event_id),
			KEY start_time (start_time)
		) $charset_collate ENGINE=InnoDB;";
		dbDelta( $sql_sessions );

		// 3. Registrations Table (With Check-in, Self-Service & Avatar fields)
		$sql_registrations = "CREATE TABLE {$wpdb->prefix}cybevents_registrations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id bigint(20) unsigned NOT NULL,
			token_hash varchar(64) NOT NULL,
			email varchar(190) NOT NULL,
			first_name varchar(100) NOT NULL,
			last_name varchar(100) NOT NULL,
			company varchar(255) DEFAULT '',
			avatar_url varchar(255) DEFAULT '',
			status varchar(50) NOT NULL DEFAULT 'pending_doi',
			doi_token_hash varchar(64) DEFAULT NULL,
			doi_requested_at datetime DEFAULT NULL,
			doi_confirmed_at datetime DEFAULT NULL,
			consent_privacy tinyint(1) NOT NULL DEFAULT 0,
			consent_photo tinyint(1) NOT NULL DEFAULT 0,
			payment_status varchar(50) NOT NULL DEFAULT 'free',
			invoice_number varchar(100) DEFAULT NULL,
			checked_in tinyint(1) NOT NULL DEFAULT 0,
			checked_in_at datetime DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY event_email (event_id, email),
			KEY token_hash (token_hash),
			KEY status (status),
			KEY checked_in (checked_in)
		) $charset_collate ENGINE=InnoDB;";
		dbDelta( $sql_registrations );

		// 4. Registration Sessions Mapping Table
		$sql_reg_sessions = "CREATE TABLE {$wpdb->prefix}cybevents_registration_sessions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			registration_id bigint(20) unsigned NOT NULL,
			session_id bigint(20) unsigned NOT NULL,
			booked_at datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY reg_session (registration_id, session_id),
			KEY session_id (session_id)
		) $charset_collate ENGINE=InnoDB;";
		dbDelta( $sql_reg_sessions );

		// 5. Invoices Table (§14 UStG & ZUGFeRD 2.3)
		$sql_invoices = "CREATE TABLE {$wpdb->prefix}cybevents_invoices (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			registration_id bigint(20) unsigned NOT NULL,
			event_id bigint(20) unsigned NOT NULL,
			invoice_number varchar(100) NOT NULL,
			invoice_type varchar(20) NOT NULL DEFAULT 'standard',
			recipient_name varchar(255) NOT NULL,
			recipient_company varchar(255) DEFAULT '',
			recipient_email varchar(190) NOT NULL,
			recipient_address text,
			net_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			tax_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			gross_amount decimal(10,2) NOT NULL DEFAULT 0.00,
			currency varchar(3) NOT NULL DEFAULT 'EUR',
			zugferd_xml longtext,
			status varchar(50) NOT NULL DEFAULT 'issued',
			storno_reference_id bigint(20) unsigned DEFAULT NULL,
			issued_at datetime NOT NULL,
			due_date datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY invoice_number (invoice_number),
			KEY registration_id (registration_id),
			KEY event_id (event_id),
			KEY status (status)
		) $charset_collate ENGINE=InnoDB;";
		dbDelta( $sql_invoices );

		// 6. Queue Table (Brevo API rate limits & async jobs)
		$sql_queue = "CREATE TABLE {$wpdb->prefix}cybevents_queue (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_type varchar(100) NOT NULL DEFAULT 'brevo_doi',
			action varchar(100) NOT NULL DEFAULT 'send_doi',
			payload longtext NOT NULL,
			attempts int(11) NOT NULL DEFAULT 0,
			max_attempts int(11) NOT NULL DEFAULT 5,
			status varchar(50) NOT NULL DEFAULT 'pending',
			error_message text,
			last_error text,
			scheduled_at datetime NOT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			processed_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status_scheduled (status, scheduled_at)
		) $charset_collate ENGINE=InnoDB;";
		dbDelta( $sql_queue );

		// 7. Audit Log Table (GDPR & GoBD Compliance)
		$sql_audit = "CREATE TABLE {$wpdb->prefix}cybevents_audit_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(100) NOT NULL,
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip_address varchar(45) NOT NULL DEFAULT '',
			details longtext,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			content_digest varchar(64) NOT NULL DEFAULT '',
			prev_hash varchar(64) NOT NULL DEFAULT '',
			entry_hash varchar(64) NOT NULL DEFAULT '',
			pseudonymized_at datetime DEFAULT NULL,
			residual_mac varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY object_id (object_id),
			KEY created_at (created_at)
		) $charset_collate ENGINE=InnoDB;";
		dbDelta( $sql_audit );
	}
}
