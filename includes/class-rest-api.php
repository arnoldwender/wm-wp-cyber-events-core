<?php
/**
 * REST API Endpoints & Request Controllers.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_REST_API {

	/**
	 * Register all REST API routes under cybevents/v1 namespace.
	 */
	public static function register_routes() {
		register_rest_route(
			'cybevents/v1',
			'/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_register' ),
				'permission_callback' => array( __CLASS__, 'check_public_events_access' ),
			)
		);

		// Double opt-in confirmation: Brevo redirects here after the participant clicks the link in
		// the DOI mail. Until 2026-09-15 no route confirmed a registration at all.
		register_rest_route(
			'cybevents/v1',
			'/confirm',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_confirm' ),
				'permission_callback' => array( __CLASS__, 'check_public_events_access' ),
			)
		);

		register_rest_route(
			'cybevents/v1',
			'/session-selection',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_get_session_selection' ),
				'permission_callback' => array( __CLASS__, 'check_public_events_access' ),
			)
		);

		register_rest_route(
			'cybevents/v1',
			'/book-sessions',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_book_sessions' ),
				'permission_callback' => array( __CLASS__, 'check_public_events_access' ),
			)
		);

		register_rest_route(
			'cybevents/v1',
			'/brevo-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_brevo_webhook' ),
				'permission_callback' => array( __CLASS__, 'validate_brevo_webhook_permission' ),
			)
		);

		// Enterprise Admission Check-In API
		register_rest_route(
			'cybevents/v1',
			'/checkin',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_checkin' ),
				'permission_callback' => array( __CLASS__, 'check_staff_or_admin_permission' ),
			)
		);

		// Dynamic iCalendar .ics Generator
		register_rest_route(
			'cybevents/v1',
			'/calendar',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_download_calendar' ),
				'permission_callback' => array( __CLASS__, 'check_public_events_access' ),
			)
		);

		// Self-Service Registration Cancellation (DSGVO)
		register_rest_route(
			'cybevents/v1',
			'/cancel-registration',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_cancel_registration' ),
				'permission_callback' => array( __CLASS__, 'check_public_events_access' ),
			)
		);

		// Participant Profile Image / Avatar Upload API
		register_rest_route(
			'cybevents/v1',
			'/upload-avatar',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_upload_avatar' ),
				'permission_callback' => array( __CLASS__, 'check_public_events_access' ),
			)
		);

		// Digital Admission Pass & QR Ticket
		register_rest_route(
			'cybevents/v1',
			'/ticket',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_get_ticket' ),
				'permission_callback' => array( __CLASS__, 'check_public_events_access' ),
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Seat map live stream (Server-Sent Events). Occupancy numbers only: no names, no tokens.
		register_rest_route(
			'cybevents/v1',
			'/seat-stream',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_seat_stream' ),
				'permission_callback' => array( __CLASS__, 'check_public_events_access' ),
				'args'                => array(
					'event_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Seat map cached ETag snapshot (default polling transport; returns 304 when unchanged).
		register_rest_route(
			'cybevents/v1',
			'/seat-snapshot',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_seat_snapshot' ),
				'permission_callback' => array( __CLASS__, 'check_public_events_access' ),
				'args'                => array(
					'event_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Venues / Veranstaltungsorte Directory API
		register_rest_route(
			'cybevents/v1',
			'/venues',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_get_venues' ),
				'permission_callback' => array( __CLASS__, 'check_public_events_access' ),
			)
		);
	}

	/**
	 * Hold the connection open and write the seat occupancy of an event as it changes.
	 *
	 * The response never returns: CyberEvents_Seat_Map::stream() writes and exits. An event id that
	 * names nothing streams an empty session list rather than an error, because the stream is read by
	 * a script that would have to tell two shapes apart for no gain.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return void
	 */
	public static function handle_seat_stream( $request ) {
		CyberEvents_Seat_Map::stream( (int) $request->get_param( 'event_id' ) );
	}

	/**
	 * Return the short-lived cached seat occupancy snapshot with ETag and 304 support.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_seat_snapshot( $request ) {
		$snap    = CyberEvents_Seat_Map::cached_snapshot( (int) $request->get_param( 'event_id' ) );
		$etag    = '"' . $snap['digest'] . '"';
		$if_none = trim( (string) $request->get_header( 'if_none_match' ) );

		if ( '' !== $if_none && ( hash_equals( $etag, $if_none ) || hash_equals( 'W/' . $etag, $if_none ) ) ) {
			return new WP_REST_Response(
				null,
				304,
				array(
					'ETag'          => $etag,
					'Cache-Control' => 'private, no-cache',
				)
			);
		}

		return new WP_REST_Response(
			$snap,
			200,
			array(
				'ETag'          => $etag,
				'Cache-Control' => 'private, no-cache',
			)
		);
	}

	/**
	 * Get list of all venues with metadata.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_get_venues( $request ) {
		if ( ! class_exists( 'CyberEvents_Venues_Manager' ) ) {
			return new WP_REST_Response( array( 'success' => false, 'venues' => array() ), 200 );
		}
		$venues = CyberEvents_Venues_Manager::get_all_venues();
		return new WP_REST_Response(
			array(
				'success' => true,
				'count'   => count( $venues ),
				'venues'  => $venues,
			),
			200
		);
	}

	/**
	 * Permission callback for Brevo Webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function validate_brevo_webhook_permission( $request ) {
		$options = get_option( 'cybevents_settings', array() );
		$secret  = (string) ( $options['brevo_webhook_secret'] ?? '' );

		// Fail closed. An empty secret used to mean "accept everything", and the activation
		// default is empty: any anonymous POST could bounce registrations and free their seats
		// (fleet audit 2026-09-13 §6.2 #5). The same pattern as wm-ethical-conversion-triggers.
		if ( '' === $secret ) {
			return new WP_Error(
				'cybevents_webhook_unconfigured',
				'Brevo-Webhook nicht konfiguriert: Shared Secret in den Einstellungen hinterlegen.',
				array( 'status' => 401 )
			);
		}

		$header_secret = $request->get_header( 'x-brevo-webhook-secret' );
		if ( empty( $header_secret ) || ! hash_equals( $secret, $header_secret ) ) {
			return new WP_Error(
				'cybevents_unauthorized_webhook',
				'Ungültige oder fehlende Webhook-Signatur.',
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Admin notice on the plugin's own screens while the Brevo webhook has no secret:
	 * the route answers 401 until it is set, and bounces will not be processed.
	 */
	public static function warn_if_webhook_unconfigured() {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, 'cybevents' ) ) {
			return;
		}
		$options  = get_option( 'cybevents_settings', array() );
		$warnings = array();
		if ( '' === (string) ( $options['brevo_webhook_secret'] ?? '' ) ) {
			$warnings[] = __( 'Der Brevo-Webhook ist ohne Shared Secret deaktiviert (antwortet 401). Bounces und Abmeldungen werden nicht verarbeitet, bis ein Secret hinterlegt ist.', 'wm-cyber-events-core' );
		}
		if ( ! CyberEvents_Brevo_API::is_configured() ) {
			$warnings[] = __( 'Kein Brevo-API-Schlüssel hinterlegt: Bestätigungs-E-Mails werden nicht versendet, neue Anmeldungen bleiben unbestätigt und werden nach 14 Tagen gelöscht.', 'wm-cyber-events-core' );
		}
		if ( ! empty( $options['enable_friendly_captcha'] ) && ! CyberEvents_Accessibility::captcha_widget_available() ) {
			$warnings[] = __( 'Friendly Captcha ist aktiviert, hat aber keine Wirkung: das Plugin enthält kein Captcha-Widget, das Formular wird ohne Bot-Schutz verarbeitet.', 'wm-cyber-events-core' );
		}
		foreach ( $warnings as $warning ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html( $warning ),
				esc_url( admin_url( 'admin.php?page=cybevents-settings' ) ),
				esc_html__( 'Einstellungen öffnen', 'wm-cyber-events-core' )
			);
		}
	}

	/**
	 * Permission callback for Staff / Admin Check-In API.
	 *
	 * @param WP_REST_Request $request REST Request.
	 * @return bool
	 */
	public static function check_staff_or_admin_permission( $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$auth_header = $request->get_header( 'X-CyberEvents-Staff-Key' );
		$options     = get_option( 'cybevents_settings', array() );
		$staff_key   = $options['staff_scanner_key'] ?? '';

		if ( ! empty( $staff_key ) && ! empty( $auth_header ) && hash_equals( $staff_key, $auth_header ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Handle Fast Admission Check-In via QR / Token / ID.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_checkin( $request ) {
		global $wpdb;

		$raw_token       = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$registration_id = (int) $request->get_param( 'registration_id' );

		if ( empty( $raw_token ) && empty( $registration_id ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Token oder Registrierungs-ID fehlt.' ), 400 );
		}

		if ( ! empty( $raw_token ) ) {
			$reg = CyberEvents_Database::get_registration_by_token( $raw_token );
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$reg = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cybevents_registrations WHERE id = %d", $registration_id )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		if ( ! $reg ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Registrierung nicht gefunden oder ungültig.' ), 404 );
		}

		// Only confirmed registrations are admitted. Until 2026-09-15 only 'cancelled' was refused,
		// so unconfirmed and bounced registrations checked in.
		if ( 'confirmed' !== $reg->status ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Zugang verweigert: Diese Registrierung ist nicht bestätigt (Status: ' . $reg->status . ').' ), 403 );
		}

		$already_in = array(
			'success'    => false,
			'already_in' => true,
			'name'       => $reg->first_name . ' ' . $reg->last_name,
			'company'    => $reg->company,
		);

		if ( (int) $reg->checked_in === 1 ) {
			$already_in['message']       = 'Bereits eingecheckt am ' . $reg->checked_in_at;
			$already_in['checked_in_at'] = $reg->checked_in_at;
			return new WP_REST_Response( $already_in, 409 );
		}

		// The check-in happens in the WHERE of one UPDATE: of two simultaneous scans exactly one
		// changes the row. Until 2026-09-15 the row was read and then updated unconditionally, and
		// both scans answered 200.
		$now = current_time( 'mysql' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			"{$wpdb->prefix}cybevents_registrations",
			array(
				'checked_in'    => 1,
				'checked_in_at' => $now,
			),
			array(
				'id'         => $reg->id,
				'checked_in' => 0,
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $updated ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Datenbankfehler beim Check-in.' ), 500 );
		}
		if ( 1 !== (int) $updated ) {
			$already_in['message'] = 'Bereits eingecheckt.';
			return new WP_REST_Response( $already_in, 409 );
		}

		CyberEvents_Database::log_audit(
			'checkin_completed',
			$reg->id,
			get_current_user_id(),
			wp_json_encode( array( 'time' => $now ) )
		);

		return new WP_REST_Response(
			array(
				'success'       => true,
				'message'       => 'Check-in erfolgreich.',
				'name'          => $reg->first_name . ' ' . $reg->last_name,
				'company'       => $reg->company,
				'checked_in_at' => $now,
			),
			200
		);
	}

	/**
	 * Handle iCalendar (.ics) download for attendee or global event.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function handle_download_calendar( $request ) {
		global $wpdb;

		$token    = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$event_id = (int) $request->get_param( 'event_id' );

		if ( ! empty( $token ) ) {
			$reg = CyberEvents_Database::get_registration_by_token( $token );
			if ( ! $reg ) {
				wp_die( 'Ungültiger Kalender-Token.', 'Fehler', array( 'response' => 404 ) );
			}
			$event_id = (int) $reg->event_id;
		}

		if ( empty( $event_id ) ) {
			wp_die( 'Event-ID erforderlich.', 'Fehler', array( 'response' => 400 ) );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$event = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cybevents_events WHERE id = %d", $event_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $event ) {
			wp_die( 'Event nicht gefunden.', 'Fehler', array( 'response' => 404 ) );
		}

		$sessions = array();
		if ( ! empty( $reg ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$sessions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.* FROM {$wpdb->prefix}cybevents_sessions s
					 INNER JOIN {$wpdb->prefix}cybevents_registration_sessions rs ON s.id = rs.session_id
					 WHERE rs.registration_id = %d ORDER BY s.start_time ASC",
					$reg->id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		$ics_content = CyberEvents_Calendar_Exporter::generate_ics( $event, $sessions, ! empty( $reg ) ? $reg->first_name . ' ' . $reg->last_name : '' );
		$filename    = sanitize_title( $event->slug ?: $event->title ) . '.ics';

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RFC 5545 text/calendar stream.
		echo apply_filters( 'wm_cybevents_ics_output', $ics_content, $event );
		exit;
	}

	/**
	 * Handle Self-Service Cancellation (DSGVO).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_cancel_registration( $request ) {
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( empty( $token ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Token fehlt.' ), 400 );
		}

		$reg = CyberEvents_Database::get_registration_by_token( $token );
		$res = CyberEvents_GDPR_Manager::cancel_registration_self_service( $token, 'Teilnehmer-Selbststornierung via Web-Link' );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => $res->get_error_message() ), 400 );
		}

		if ( $reg ) {
			CyberEvents_Seat_Map::invalidate_cache( (int) $reg->event_id );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Ihre Registrierung und alle reservierten Sessions wurden erfolgreich storniert.',
			),
			200
		);
	}

	/**
	 * Permission callback for public event routes: enforces transient-based HMAC-SHA256 blinded-IP rate limiting.
	 *
	 * Administrators (`manage_options`) bypass the limit; zero raw IP addresses are stored in transients.
	 *
	 * @param WP_REST_Request|null $request Incoming REST request.
	 * @return bool|WP_Error True if permitted, HTTP 429 WP_Error when rate limited.
	 */
	public static function check_public_events_access( $request = null ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$ip            = sanitize_text_field( wp_unslash( (string) ( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ) ) );
		$blinded_ip    = substr( hash_hmac( 'sha256', $ip . '|' . gmdate( 'Y-m-d-H-i' ), wp_salt( 'auth' ) ), 0, 32 );
		$transient_key = 'cybevents_pub_' . $blinded_ip;
		$limit         = max( 1, (int) apply_filters( 'wm_cybevents_public_rest_rate_limit', 60, $request ) );
		$count         = (int) get_transient( $transient_key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'wm_cybevents_rate_limited',
				__( 'Zu viele Anfragen. Bitte versuchen Sie es in einer Minute erneut.', 'wm-cyber-events-core' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $transient_key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Rate limiter for public endpoints (Max requests per window per HMAC-SHA256 blinded IP).
	 *
	 * @param string $endpoint Endpoint identifier.
	 * @param int    $limit    Max requests allowed.
	 * @param int    $expiry   Expiry window in seconds.
	 * @return bool True if allowed, false if rate limited.
	 */
	public static function check_rate_limit( $endpoint = 'register', $limit = 15, $expiry = 60 ) {
		$ip            = sanitize_text_field( wp_unslash( (string) ( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ) ) );
		$blinded_key   = substr( hash_hmac( 'sha256', $endpoint . '|' . $ip . '|' . gmdate( 'Y-m-d-H-i' ), wp_salt( 'auth' ) ), 0, 32 );
		$transient_key = 'cybevents_rl_' . $blinded_key;
		$count         = (int) get_transient( $transient_key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $transient_key, $count + 1, (int) $expiry );
		return true;
	}

	/**
	 * Handle Step 1 Registration Submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_register( $request ) {
		// The waiting room first: a ticket drop is turned away before any query runs. It counts per
		// event when the request names one, so a burst on one event does not close another.
		$admission = CyberEvents_Waiting_Room::admit(
			CyberEvents_Waiting_Room::GATE_REGISTER,
			(int) $request->get_param( 'event_id' )
		);
		if ( ! $admission['admitted'] ) {
			return CyberEvents_Waiting_Room::response( $admission );
		}

		if ( ! self::check_rate_limit( 'register' ) ) {
			return new WP_REST_Response(
				array( 'message' => 'Zu viele Anfragen. Bitte warten Sie einen Moment.' ),
				429
			);
		}

		// The form carries a hidden field people never fill in; until 2026-09-15 nothing read it.
		if ( '' !== trim( (string) $request->get_param( 'cybevents_hp' ) ) ) {
		return new WP_REST_Response( array( 'message' => 'Anfrage abgelehnt.' ), 400 );
		}

		$event_id        = (int) $request->get_param( 'event_id' );
		$email           = sanitize_email( (string) $request->get_param( 'email' ) );
		$first_name      = sanitize_text_field( (string) $request->get_param( 'first_name' ) );
		$last_name       = sanitize_text_field( (string) $request->get_param( 'last_name' ) );
		$company         = sanitize_text_field( (string) $request->get_param( 'company' ) );
		$consent_privacy = (int) $request->get_param( 'consent_privacy' );
		$consent_photo   = (int) $request->get_param( 'consent_photo' );
		$captcha_solution = sanitize_text_field( (string) $request->get_param( 'frc_captcha_solution' ) );

		if ( empty( $event_id ) || empty( $email ) || empty( $first_name ) || empty( $last_name ) ) {
			return new WP_REST_Response(
				array( 'message' => 'Pflichtfelder fehlen (Vorname, Nachname, E-Mail).' ),
				400
			);
		}

		if ( ! is_email( $email ) ) {
			return new WP_REST_Response(
				array( 'message' => 'Ungültige E-Mail-Adresse.' ),
				400
			);
		}

		if ( 1 !== $consent_privacy ) {
			return new WP_REST_Response(
				array( 'message' => 'Die Zustimmung zur Datenschutzerklärung ist obligatorisch.' ),
				400
			);
		}

		// Enforced only when a widget exists that can produce a solution (see
		// CyberEvents_Accessibility::captcha_widget_available()).
		if ( CyberEvents_Accessibility::captcha_widget_available() && CyberEvents_Accessibility::captcha_configured() ) {
			if ( ! CyberEvents_Accessibility::verify_friendly_captcha( $captcha_solution ) ) {
				return new WP_REST_Response(
					array( 'message' => 'Anti-Spam-Verifizierung (Friendly Captcha) fehlgeschlagen.' ),
					403
				);
			}
		}

		// Only an uploaded file becomes the avatar. Until 2026-09-15 any external URL posted as
		// avatar_url was stored and rendered as an image in the admin list and on the ticket pass.
		$avatar_url = '';
		$files      = $request->get_file_params();
		if ( ! empty( $files['avatar'] ) ) {
			$upload_res = CyberEvents_Database::handle_avatar_upload( $files['avatar'] );
			if ( ! is_wp_error( $upload_res ) ) {
				$avatar_url = $upload_res;
			}
		} elseif ( ! empty( $_FILES['avatar'] ) ) {
			$upload_res = CyberEvents_Database::handle_avatar_upload( $_FILES['avatar'] );
			if ( ! is_wp_error( $upload_res ) ) {
				$avatar_url = $upload_res;
			}
		}

		$result = CyberEvents_Database::create_registration(
			array(
				'event_id'        => $event_id,
				'email'           => $email,
				'first_name'      => $first_name,
				'last_name'       => $last_name,
				'company'         => $company,
				'avatar_url'      => $avatar_url,
				'consent_privacy' => $consent_privacy,
				'consent_photo'   => $consent_photo,
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array( 'message' => $result->get_error_message() ),
				400
			);
		}

		// Until 2026-09-15 the job was pushed as 'brevo_send_doi', a type no runner handled, and the
		// Brevo request carried no link back to the plugin. The redirect URL brings the participant
		// back to /confirm with both tokens; the queue drops the payload once the job is done.
		$event = CyberEvents_Database::get_event( $event_id );
		CyberEvents_Queue_Runner::push(
			'brevo_doi',
			array(
				'registration_id' => $result['registration_id'],
				'email'           => $email,
				'first_name'      => $first_name,
				'last_name'       => $last_name,
				'company'         => $company,
				'event_title'     => $event ? (string) $event->title : '',
				'redirection_url' => self::confirmation_url( $result['raw_doi_token'], $result['raw_token'] ),
			)
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Ihre Registrierung wurde erfasst. Bitte bestätigen Sie den Aktivierungslink in Ihrer Bestätigungs-E-Mail (Double Opt-In).',
			),
			200
		);
	}

	/**
	 * Link the DOI mail sends the participant back to.
	 *
	 * @param string $raw_doi_token Raw DOI token.
	 * @param string $raw_token     Raw participant token.
	 * @return string
	 */
	public static function confirmation_url( $raw_doi_token, $raw_token ) {
		return add_query_arg(
			array(
				'doi'   => rawurlencode( (string) $raw_doi_token ),
				'token' => rawurlencode( (string) $raw_token ),
			),
			rest_url( 'cybevents/v1/confirm' )
		);
	}

	/**
	 * Published page that shows the session selector (shortcode or block), or the front page.
	 *
	 * @return string
	 */
	public static function session_selector_page_url() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$page_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'page' AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID ASC LIMIT 1",
				'%' . $wpdb->esc_like( '[cybevents_session_selector' ) . '%',
				'%' . $wpdb->esc_like( 'wp:wm-cyber-events/session-selector' ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $page_id ? (string) get_permalink( $page_id ) : home_url( '/' );
	}

	/**
	 * Where a confirmation link leads: the session selector with the participant token when the DOI
	 * token confirmed a registration and the participant token belongs to it; otherwise the front
	 * page with a status flag.
	 *
	 * @param string $raw_doi_token Raw DOI token.
	 * @param string $raw_token     Raw participant token.
	 * @return string
	 */
	public static function confirmation_target( $raw_doi_token, $raw_token ) {
		$reg = CyberEvents_Database::confirm_doi_registration( (string) $raw_doi_token );
		if ( is_wp_error( $reg ) ) {
			return add_query_arg( 'cybevents_doi', 'invalid', home_url( '/' ) );
		}

		$args = array( 'cybevents_doi' => 'confirmed' );
		if ( '' !== (string) $raw_token && hash_equals( (string) $reg->token_hash, hash( 'sha256', (string) $raw_token ) ) ) {
			$args['token'] = rawurlencode( (string) $raw_token );
		}
		return add_query_arg( $args, self::session_selector_page_url() );
	}

	/**
	 * Confirm a registration from the DOI link and redirect.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function handle_confirm( $request ) {
		$target = self::confirmation_target(
			sanitize_text_field( (string) $request->get_param( 'doi' ) ),
			sanitize_text_field( (string) $request->get_param( 'token' ) )
		);
		nocache_headers();
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Handle Step 2 Session Selection Retrieval via Token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_get_session_selection( $request ) {
		$raw_token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( empty( $raw_token ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Token fehlt.' ), 400 );
		}

		$reg = CyberEvents_Database::get_registration_by_token( $raw_token );
		if ( ! $reg ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Ungültiger oder abgelaufener Buchungslink.' ), 404 );
		}

		if ( 'confirmed' !== $reg->status ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Diese Registrierung ist nicht bestätigt; Sessions können erst nach der Bestätigung gewählt werden.' ), 403 );
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, description, speaker, raum, start_time, end_time, kapazitaet, belegt,
				(kapazitaet - belegt) AS verfuegbar
				FROM {$wpdb->prefix}cybevents_sessions
				WHERE event_id = %d
				ORDER BY start_time ASC",
				$reg->event_id
			)
		);

		$booked = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT session_id FROM {$wpdb->prefix}cybevents_registration_sessions WHERE registration_id = %d",
				$reg->id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// The script reads success, participant.first_name/last_name, sessions[].verfuegbar and
		// booked_sessions (it read other names until 2026-09-15 and always showed an error). E-mail
		// and company are not returned: the page does not show them.
		return new WP_REST_Response(
			array(
				'success'         => true,
				'participant'     => array(
					'first_name' => $reg->first_name,
					'last_name'  => $reg->last_name,
					'status'     => $reg->status,
				),
				'sessions'        => $sessions,
				'booked_sessions' => array_map( 'intval', $booked ),
			),
			200
		);
	}

	/**
	 * Handle Step 2 Session Booking with Concurrency Lock.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_book_sessions( $request ) {
		// Before the token lookup, the scope check and the row locks: this is the route a ticket drop
		// lands on. A request with an event_id counts against that event, one without against the
		// shared booking bucket — either way nothing has touched the database yet when it is refused.
		$admission = CyberEvents_Waiting_Room::admit(
			CyberEvents_Waiting_Room::GATE_BOOK,
			(int) $request->get_param( 'event_id' )
		);
		if ( ! $admission['admitted'] ) {
			return CyberEvents_Waiting_Room::response( $admission );
		}

		$raw_token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$session_ids = $request->get_param( 'session_ids' );

		if ( empty( $raw_token ) ) {
			return new WP_REST_Response( array( 'message' => 'Token fehlt.' ), 400 );
		}

		if ( ! is_array( $session_ids ) ) {
			$session_ids = array();
		}

		$session_ids = array_map( 'intval', $session_ids );

		$reg = CyberEvents_Database::get_registration_by_token( $raw_token );
		if ( ! $reg ) {
			return new WP_REST_Response( array( 'message' => 'Ungültiger oder abgelaufener Buchungslink.' ), 404 );
		}

		// Until 2026-09-15 unconfirmed, cancelled and bounced registrations could book seats.
		if ( 'confirmed' !== $reg->status ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Diese Registrierung ist nicht bestätigt; es können keine Sessions gebucht werden.' ), 403 );
		}

		global $wpdb;

		// IDOR & Scope Check: Ensure all requested session_ids belong to the registered event
		if ( ! empty( $session_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $session_ids ), '%d' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$valid_event_sessions = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}cybevents_sessions WHERE event_id = %d AND id IN ($placeholders)",
					array_merge( array( $reg->event_id ), $session_ids )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( count( $valid_event_sessions ) !== count( $session_ids ) ) {
				return new WP_REST_Response(
					array( 'message' => 'Sicherheitsfehler: Eine oder mehrere ausgewählte Sessions gehören nicht zu diesem Event.' ),
					400
				);
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$currently_booked = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT session_id FROM {$wpdb->prefix}cybevents_registration_sessions WHERE registration_id = %d",
				$reg->id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$currently_booked = array_map( 'intval', $currently_booked );

		$to_cancel = array_diff( $currently_booked, $session_ids );
		$to_book   = array_diff( $session_ids, $currently_booked );

		$errors = array();

		// New seats first. If one of them cannot be booked, the seats taken in this request are
		// released again and the existing bookings stay: until 2026-09-15 the old sessions were
		// cancelled first, and a failed swap left the participant with neither.
		$booked = array();
		foreach ( $to_book as $b_id ) {
			$res = CyberEvents_Database::book_session_atomic( $b_id, $reg->id );
			if ( is_wp_error( $res ) ) {
				$errors[] = $res->get_error_message();
				break;
			}
			$booked[] = (int) $b_id;
		}

		if ( ! empty( $errors ) ) {
			foreach ( $booked as $undo_id ) {
				CyberEvents_Database::cancel_session_atomic( $undo_id, $reg->id );
			}
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: Comma-separated list of error messages */
						__( 'Die Auswahl wurde nicht gespeichert: %s Ihre bisherigen Buchungen bleiben bestehen.', 'wm-cyber-events-core' ),
						implode( ' ', $errors )
					),
					'errors'  => $errors,
				),
				409
			);
		}

		foreach ( $to_cancel as $c_id ) {
			$res = CyberEvents_Database::cancel_session_atomic( $c_id, $reg->id );
			if ( is_wp_error( $res ) ) {
				$errors[] = $res->get_error_message();
			}
		}

		if ( ! empty( $booked ) || ! empty( $to_cancel ) ) {
			CyberEvents_Seat_Map::invalidate_cache( (int) $reg->event_id );
		}

		if ( ! empty( $errors ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: Comma-separated list of error messages */
						__( 'Neue Sessions wurden gebucht, aber nicht jede abgewählte Session konnte freigegeben werden: %s', 'wm-cyber-events-core' ),
						implode( ' ', $errors )
					),
					'errors'  => $errors,
				),
				409
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Ihre Session-Auswahl wurde gespeichert.',
			),
			200
		);
	}

	/**
	 * Handle Brevo Webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_brevo_webhook( $request ) {
		$options = get_option( 'cybevents_settings', array() );
		$secret  = (string) ( $options['brevo_webhook_secret'] ?? '' );

		// Defence in depth behind the permission callback: never process without a secret.
		if ( '' === $secret ) {
			return new WP_REST_Response( array( 'message' => 'Brevo-Webhook nicht konfiguriert.' ), 401 );
		}

		$header_secret = (string) $request->get_header( 'X-Brevo-Webhook-Secret' );
		if ( '' === $header_secret || ! hash_equals( $secret, $header_secret ) ) {
			return new WP_REST_Response( array( 'message' => 'Ungültige Webhook-Signatur.' ), 403 );
		}

		$params = $request->get_json_params();
		$event  = sanitize_text_field( (string) ( $params['event'] ?? '' ) );
		$email  = sanitize_email( (string) ( $params['email'] ?? '' ) );

		if ( empty( $email ) ) {
			return new WP_REST_Response( array( 'message' => 'E-Mail fehlt im Payload.' ), 400 );
		}

		global $wpdb;

		if ( in_array( $event, array( 'hard_bounce', 'soft_bounce', 'spam', 'unsubscribed' ), true ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$registrations = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, event_id FROM {$wpdb->prefix}cybevents_registrations WHERE email = %s", $email )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$invalidated_events = array();
			foreach ( $registrations as $r ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$booked_sessions = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT session_id FROM {$wpdb->prefix}cybevents_registration_sessions WHERE registration_id = %d",
						$r->id
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

				foreach ( $booked_sessions as $sess_id ) {
					CyberEvents_Database::cancel_session_atomic( (int) $sess_id, $r->id );
				}

				if ( ! empty( $booked_sessions ) ) {
					$invalidated_events[ (int) $r->event_id ] = true;
				}

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					"{$wpdb->prefix}cybevents_registrations",
					array( 'status' => 'bounced' ),
					array( 'id' => $r->id )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}

			foreach ( array_keys( $invalidated_events ) as $eid ) {
				CyberEvents_Seat_Map::invalidate_cache( $eid );
			}

			// The count, not the payload: until 2026-09-15 the whole webhook body (address included)
			// was written into the audit log.
			CyberEvents_Database::log_audit( 'brevo_webhook_' . $event, 0, 0, wp_json_encode( array( 'registrations' => count( (array) $registrations ) ) ) );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Handle Avatar Upload for existing participant (Self-Service or Admin).
	 *
	 * @param WP_REST_Request $request REST Request.
	 * @return WP_REST_Response
	 */
	public static function handle_upload_avatar( $request ) {
		$token      = sanitize_text_field( (string) $request->get_param( 'token' ) );
		$rest_nonce = sanitize_text_field( (string) ( $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' ) ) );
		$form_nonce = sanitize_text_field( (string) $request->get_param( '_cybevents_nonce' ) );
		$reg        = null;

		if ( '' !== $token ) {
			if ( '' !== $rest_nonce && ! wp_verify_nonce( $rest_nonce, 'wp_rest' ) ) {
				return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Ungültiges Sicherheits-Token (Nonce).', 'wm-cyber-events-core' ) ), 403 );
			}
			if ( '' !== $form_nonce && ! wp_verify_nonce( $form_nonce, 'cybevents_public_nonce' ) ) {
				return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Ungültiges Formular-Token (Nonce).', 'wm-cyber-events-core' ) ), 403 );
			}
			$candidate = CyberEvents_Database::get_registration_by_token( $token );
			if ( $candidate && in_array( (string) $candidate->status, array( 'pending_doi', 'confirmed' ), true ) ) {
				$reg = $candidate;
			}
		} elseif ( current_user_can( 'manage_options' ) && '' !== $rest_nonce && wp_verify_nonce( $rest_nonce, 'wp_rest' ) ) {
			$reg_id = (int) $request->get_param( 'registration_id' );
			if ( $reg_id ) {
				global $wpdb;
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$reg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cybevents_registrations WHERE id = %d", $reg_id ) );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}
		}

		if ( ! $reg ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Teilnehmer nicht autorisiert oder nicht gefunden.', 'wm-cyber-events-core' ) ), 403 );
		}

		$files = $request->get_file_params();
		$file  = ! empty( $files['avatar'] ) ? $files['avatar'] : ( ! empty( $_FILES['avatar'] ) ? $_FILES['avatar'] : null );

		if ( empty( $file ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'Keine Bilddatei übermittelt.', 'wm-cyber-events-core' ) ), 400 );
		}

		$upload_res = CyberEvents_Database::handle_avatar_upload( $file );
		if ( is_wp_error( $upload_res ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => $upload_res->get_error_message() ), 400 );
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			"{$wpdb->prefix}cybevents_registrations",
			array( 'avatar_url' => $upload_res ),
			array( 'id' => $reg->id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		CyberEvents_Database::log_audit(
			'avatar_uploaded',
			$reg->id,
			get_current_user_id(),
			wp_json_encode( array( 'avatar_url' => $upload_res ) )
		);

		return new WP_REST_Response(
			array(
				'success'    => true,
				'message'    => __( 'Profilbild erfolgreich aktualisiert.', 'wm-cyber-events-core' ),
				'avatar_url' => $upload_res,
			),
			200
		);
	}

	/**
	 * Render Digital Admission Pass & QR Ticket Metadata.
	 *
	 * @param WP_REST_Request $request REST Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get_ticket( $request ) {
		$raw_token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( empty( $raw_token ) ) {
			return new WP_Error( 'cybevents_missing_token', __( 'Bestätigungs-Token fehlt.', 'wm-cyber-events-core' ), array( 'status' => 400 ) );
		}

		$reg = CyberEvents_Database::get_registration_by_token( $raw_token );
		if ( ! $reg || 'confirmed' !== $reg->status ) {
			return new WP_Error( 'cybevents_ticket_not_found', __( 'Ungültiges oder noch nicht bestätigtes Ticket.', 'wm-cyber-events-core' ), array( 'status' => 404 ) );
		}

		$event = CyberEvents_Database::get_event( $reg->event_id );
		if ( ! $event ) {
			return new WP_Error( 'cybevents_event_not_found', __( 'Veranstaltung nicht gefunden.', 'wm-cyber-events-core' ), array( 'status' => 404 ) );
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id, s.title, s.speaker, s.raum, s.start_time, s.end_time FROM {$wpdb->prefix}cybevents_sessions s
				INNER JOIN {$wpdb->prefix}cybevents_registration_sessions rs ON rs.session_id = s.id
				WHERE rs.registration_id = %d ORDER BY s.start_time ASC",
				$reg->id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$pass_data = array(
			'ticket_id'       => (int) $reg->id,
			'ticket_hash'     => hash( 'sha256', $raw_token ),
			'attendee_name'   => $reg->first_name . ' ' . $reg->last_name,
			'first_name'      => $reg->first_name,
			'last_name'       => $reg->last_name,
			'company'         => $reg->company,
			'email'           => $reg->email,
			'avatar_url'      => $reg->avatar_url ?: '',
			'event_title'     => $event->title,
			// Empty when the event has no location; until 2026-09-15 a venue of one client was shown.
			'event_location'  => (string) $event->location,
			'event_date'      => date_i18n( 'd. F Y', strtotime( $event->start_date ) ),
			'checked_in'      => (int) $reg->checked_in === 1,
			'checked_in_at'   => $reg->checked_in_at,
			'invoice_number'  => $reg->invoice_number,
			'booked_sessions' => $sessions ?: array(),
		);

		// Name, e-mail, company and invoice number of one person: never stored by a shared cache.
		// Until 2026-09-15 this was 'public, max-age=86400'.
		$response = new WP_REST_Response( $pass_data, 200 );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		return $response;
	}
}
