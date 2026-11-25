<?php
/**
 * Enterprise Production Seed & Demo Data Engine.
 *
 * Populates a fictional demo conference: CPT posts, sessions (one saturated, one overlapping),
 * registrations in every state and invoices. Every name, address and domain is invented; e-mail
 * addresses and websites use the reserved example.com / example.org / example.net domains (RFC 2606).
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Seeder {

	/** Venue the demo event is assigned to; also the first slug the demo purge deletes. */
	const MAIN_VENUE_SLUG = 'demo-kongresszentrum-musterstadt';

	/** Option holding the IDs of the pages the seeder created (landing page, workshop selection). */
	const DEMO_PAGES_OPTION = 'cybevents_demo_page_ids';

	/**
	 * Seed or re-seed complete functional enterprise demo ecosystem.
	 *
	 * @param bool $force If true, deletes previous demo data and re-seeds cleanly.
	 * @return array Summary of seeded records.
	 */
	public static function seed_demo_data( $force = false ) {
		global $wpdb;

		if ( ! $force ) {
			// Check if events already exist
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_events = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_events WHERE id > %d", 0 ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $existing_events > 0 ) {
				return array( 'status' => 'skipped', 'message' => 'Daten existieren bereits.' );
			}
		}

		// 0. Seed Venues / Veranstaltungsorte
		$venues_to_seed = array(
			array(
				'name'        => 'Kongresszentrum Musterstadt',
				'slug'        => 'demo-kongresszentrum-musterstadt',
				'description' => 'Erfundener Veranstaltungsort der Demodaten: großer Saal, drei Workshopräume, stufenloser Zugang.',
				'address'     => 'Musterstraße 1',
				'city'        => 'Musterstadt',
				'zip'         => '12345',
				'country'     => 'Deutschland',
				'capacity'    => 500,
				'accessible'  => '1',
				'maps_url'    => '',
				'website'     => 'https://example.com',
			),
			array(
				'name'        => 'Tagungshaus am Beispielplatz',
				'slug'        => 'demo-tagungshaus-beispielplatz',
				'description' => 'Erfundener Veranstaltungsort der Demodaten für kleinere Workshops.',
				'address'     => 'Beispielplatz 7',
				'city'        => 'Musterstadt',
				'zip'         => '12345',
				'country'     => 'Deutschland',
				'capacity'    => 150,
				'accessible'  => '1',
				'maps_url'    => '',
				'website'     => 'https://example.org',
			),
			array(
				'name'        => 'Messehalle Beispielstadt',
				'slug'        => 'demo-messehalle-beispielstadt',
				'description' => 'Erfundener Veranstaltungsort der Demodaten für große Konferenzen.',
				'address'     => 'Messeallee 10',
				'city'        => 'Beispielstadt',
				'zip'         => '54321',
				'country'     => 'Deutschland',
				'capacity'    => 1200,
				'accessible'  => '1',
				'maps_url'    => '',
				'website'     => 'https://example.net',
			),
			array(
				'name'        => 'Coworking-Loft Musterhafen',
				'slug'        => 'demo-coworking-musterhafen',
				'description' => 'Erfundener Veranstaltungsort der Demodaten ohne stufenlosen Zugang.',
				'address'     => 'Hafenkai 3',
				'city'        => 'Musterhafen',
				'zip'         => '23456',
				'country'     => 'Deutschland',
				'capacity'    => 300,
				'accessible'  => '0',
				'maps_url'    => '',
				'website'     => 'https://example.com',
			),
		);

		$main_venue_term_id = 0;
		foreach ( $venues_to_seed as $v_data ) {
			$existing_term = term_exists( $v_data['name'], 'cybvenue' );
			if ( ! $existing_term ) {
				$term_res = wp_insert_term(
					$v_data['name'],
					'cybvenue',
					array(
						'slug'        => $v_data['slug'],
						'description' => $v_data['description'],
					)
				);
				if ( ! is_wp_error( $term_res ) && isset( $term_res['term_id'] ) ) {
					$tid = (int) $term_res['term_id'];
					update_term_meta( $tid, '_cyb_venue_address', $v_data['address'] );
					update_term_meta( $tid, '_cyb_venue_city', $v_data['city'] );
					update_term_meta( $tid, '_cyb_venue_zip', $v_data['zip'] );
					update_term_meta( $tid, '_cyb_venue_country', $v_data['country'] );
					update_term_meta( $tid, '_cyb_venue_capacity', $v_data['capacity'] );
					update_term_meta( $tid, '_cyb_venue_accessible', $v_data['accessible'] );
					update_term_meta( $tid, '_cyb_venue_maps_url', $v_data['maps_url'] );
					update_term_meta( $tid, '_cyb_venue_website', $v_data['website'] );

					if ( self::MAIN_VENUE_SLUG === $v_data['slug'] ) {
						$main_venue_term_id = $tid;
					}
				}
			} else {
				$tid = is_array( $existing_term ) ? (int) $existing_term['term_id'] : (int) $existing_term;
				if ( self::MAIN_VENUE_SLUG === $v_data['slug'] ) {
					$main_venue_term_id = $tid;
				}
			}
		}

		// 1. Create WordPress CPT Event Post & Relational Event Record
		$event_title = '[Demo] Fachkonferenz Sichere Software 2026';
		$event_slug  = 'demo-fachkonferenz-sichere-software-2026';
		$event_desc  = 'Erfundene Beispielveranstaltung der Demodaten, damit Anmeldung, Double Opt-in, Session-Buchung, Check-in und Storno ohne echte Personen ausprobiert werden können. Themen: Post-Quanten-Kryptografie, Zero Trust und sichere Software-Lieferketten.';
		$location    = 'Kongresszentrum Musterstadt, Musterstadt';
		$start_date  = '2026-10-15 08:30:00';
		$end_date    = '2026-10-16 18:00:00';

		// Create Relational Event Table Record First to get $event_id for Block attributes
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			"{$wpdb->prefix}cybevents_events",
			array(
				'wp_post_id'   => 0,
				'title'        => $event_title,
				'slug'         => $event_slug,
				'description'  => $event_desc,
				'start_date'   => $start_date,
				'end_date'     => $end_date,
				'location'     => $location,
				'max_capacity' => 500,
				'is_paid'      => 1,
				'ticket_price' => 250.00,
				'status'       => 'active',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%s' )
		);
		$event_id = (int) $wpdb->insert_id;

		// Structured Enterprise Gutenberg Blocks Layout
		$block_content = '<!-- wp:wm-cyber-events/event-hero {"eventId":' . $event_id . ',"showCountdown":true,"showCapacityPill":true,"showCalendarButton":true} /-->

<!-- wp:wm-cyber-events/calendar-widget {"eventId":' . $event_id . '} /-->

<!-- wp:wm-cyber-events/speaker-grid {"eventId":' . $event_id . ',"columns":3} /-->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Konferenzplan &amp; Fach-Tracks</h2>
<!-- /wp:heading -->

<!-- wp:wm-cyber-events/agenda-matrix {"eventId":' . $event_id . ',"showExportButton":true} /-->

<!-- wp:wm-cyber-events/security-trust-badge /-->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">Anmeldung</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Sichern Sie sich Ihren Konferenzpass inklusive Zugang zu allen Keynotes, Fach-Workshops und Verpflegung. Nach der Anmeldung erhalten Sie eine Bestätigungs-E-Mail (Double Opt-In) zur Auswahl Ihrer Workshop-Plätze.</p>
<!-- /wp:paragraph -->

<!-- wp:wm-cyber-events/registration-form {"eventId":' . $event_id . ',"showKeyFacts":true,"showStepper":true} /-->';

		// Create CPT Post with Native Blocks
		$post_id = wp_insert_post(
			array(
				'post_title'   => $event_title,
				'post_name'    => $event_slug,
				'post_content' => $block_content,
				'post_status'  => 'publish',
				'post_type'    => 'cybevent',
			)
		);

		if ( $post_id && ! is_wp_error( $post_id ) ) {
			if ( $main_venue_term_id > 0 ) {
				wp_set_object_terms( $post_id, array( $main_venue_term_id ), 'cybvenue' );
			}
			update_post_meta( $post_id, '_cybevents_location', $location );
			update_post_meta( $post_id, '_cybevents_start_date', $start_date );
			update_post_meta( $post_id, '_cybevents_end_date', $end_date );
			update_post_meta( $post_id, '_cybevents_max_capacity', 500 );
			// Saving the event in the editor syncs price and paid flag from these keys; without them the
			// demo event became free on its first save (until 2026-09-15).
			update_post_meta( $post_id, '_cybevents_ticket_price', 250.00 );
			update_post_meta( $post_id, '_cybevents_is_paid', 1 );

			// wp_insert_post() fired save_post, and CyberEvents_Events_Manager::sync_cpt_to_table() already
			// inserted a row for this post before the meta existed (0 seats, free). Until 2026-11-19 every
			// seed left two events for one post and the dashboard listed the phantom. Keep the row above.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}cybevents_events WHERE wp_post_id = %d AND id <> %d", $post_id, $event_id ) );

			// Update relational record with post ID
			$wpdb->update(
				"{$wpdb->prefix}cybevents_events",
				array( 'wp_post_id' => $post_id ),
				array( 'id' => $event_id )
			);
		}

		// Also Create Dedicated Standalone Landing Page (Page Post Type)
		$landing_page_id = wp_insert_post(
			array(
				'post_title'   => '[Demo] Fachkonferenz Sichere Software 2026',
				'post_name'    => 'demo-fachkonferenz',
				'post_content' => $block_content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		// Create Dedicated Workshop Selection DOI Page
		$workshop_page_content = '<!-- wp:heading {"level":1} -->
<h1 class="wp-block-heading">Ihre Workshop- &amp; Session-Auswahl</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"fontSize":"medium"} -->
<p class="has-medium-font-size">Vielen Dank für Ihre Registrierung zur <strong>Fachkonferenz Sichere Software 2026</strong> (Demo). Bitte wählen Sie nachfolgend Ihre bevorzugten Fach-Tracks und Workshops aus.</p>
<!-- /wp:paragraph -->

<!-- wp:wm-cyber-events/session-selector /-->';

		$workshop_page_id = wp_insert_post(
			array(
				'post_title'   => 'Workshop- & Session-Auswahl',
				'post_name'    => 'workshop-auswahl',
				'post_content' => $workshop_page_content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		// Until 2026-11-19 the purge left both pages behind, and every new seed added a copy
		// (demo-fachkonferenz-2, -3, ...). The purge deletes exactly these IDs, never pages found by slug or title.
		$demo_page_ids = array_values( array_filter( array_map( 'absint', array( $landing_page_id, $workshop_page_id ) ) ) );
		update_option( self::DEMO_PAGES_OPTION, array_merge( (array) get_option( self::DEMO_PAGES_OPTION, array() ), $demo_page_ids ), false );

		// 2. Create 4 Functional Sessions / Workshop Tracks
		$sessions_def = array(
			array(
				'title'       => 'Keynote: Quantenresistente Verschlüsselung in der Praxis',
				'description' => 'Einordnung der Migration auf Post-Quanten-Verfahren für Verwaltung und Unternehmen.',
				'speaker'     => 'Dr. Erika Mustermann (Beispiel-Institut)',
				'raum'        => 'Großer Saal (Saal A)',
				'start_time'  => '2026-10-15 09:30:00',
				'end_time'    => '2026-10-15 11:00:00',
				'kapazitaet'  => 300,
				'belegt'      => 0,
			),
			array(
				'title'       => 'Hands-on Workshop: Post-Quantum TLS 1.3 & ML-KEM Migration',
				'description' => 'Praxisnahes Live-Lab zur Implementierung quantenresistenter KEM- und Signaturalgorithmen in Linux- und Cloud-Infrastrukturen.',
				'speaker'     => 'Prof. Dr. Max Beispiel (Musterhochschule)',
				'raum'        => 'Labor 1 (Tech Hub)',
				'start_time'  => '2026-10-15 11:30:00',
				'end_time'    => '2026-10-15 13:00:00',
				'kapazitaet'  => 40,
				'belegt'      => 0,
			),
			array(
				'title'       => 'Live Hacking Defense: Zero Trust & Micro-Segmentation in KRITIS',
				'description' => 'Analyse realer APT-Szenarien und automatisierte Gegenmaßnahmen mit eBPF und Netzwerk-Isolation.',
				'speaker'     => 'Jonas Muster (Beispiel GmbH)',
				'raum'        => 'Saal B (Security Track)',
				'start_time'  => '2026-10-15 14:00:00',
				'end_time'    => '2026-10-15 15:30:00',
				'kapazitaet'  => 60,
				'belegt'      => 0,
			),
			array(
				'title'       => 'Panel: Europäische Souveränität & Autonomie in der Cyberabwehr 2030',
				'description' => 'Diskussionsrunde mit erfundenen Gästen aus Verwaltung, Forschung und Wirtschaft.',
				'speaker'     => 'Panel (Demo)',
				'raum'        => 'Executive Lounge',
				'start_time'  => '2026-10-15 16:00:00',
				'end_time'    => '2026-10-15 17:30:00',
				'kapazitaet'  => 120,
				'belegt'      => 0,
			),
			// Deliberately saturated. Until 2026-08-27 the REST handler answered HTTP 200 with
			// "erfolgreich gespeichert" when a session was full, so nobody could see the refusal
			// path without filling a session by hand. The demo set now ships one.
			array(
				'title'       => 'Closed Briefing: Incident Response im Ernstfall (ausgebucht)',
				'description' => 'Vertrauliches Briefing mit begrenzter Teilnehmerzahl. Diese Session ist bewusst ausgebucht, damit die Ablehnungslogik (HTTP 409 mit errors[]) im Demobetrieb sichtbar ist.',
				'speaker'     => 'Lagezentrum Musterstadt (Demo)',
				'raum'        => 'Sicherheitsbereich (Raum S1)',
				'start_time'  => '2026-10-15 14:00:00',
				'end_time'    => '2026-10-15 15:30:00',
				'kapazitaet'  => 12,
				'belegt'      => 12,
			),
			// Overlaps the Live-Hacking track above on purpose, so the collision guard for
			// simultaneous bookings has something to refuse.
			array(
				'title'       => 'Parallel-Track: Sichere Software-Lieferketten (CRA & SBOM)',
				'description' => 'Zeitgleich zum Live-Hacking-Track. Dient der Demonstration der Kollisionsprüfung: eine Person kann nicht beide Sessions buchen.',
				'speaker'     => 'Dr. Lena Probst (Beispiel AG)',
				'raum'        => 'Saal C (Supply Chain Track)',
				'start_time'  => '2026-10-15 14:30:00',
				'end_time'    => '2026-10-15 16:00:00',
				'kapazitaet'  => 80,
				'belegt'      => 0,
			),
		);

		$session_ids = array();
		foreach ( $sessions_def as $s ) {
			$sess_post_id = wp_insert_post(
				array(
					'post_title'   => $s['title'],
					'post_content' => $s['description'],
					'post_status'  => 'publish',
					'post_type'    => 'cybsession',
				)
			);
			// The keys the session editor reads and CyberEvents_Sessions_Manager::sync_cpt_to_table() saves
			// back. Until 2026-09-15 the seeder wrote _cybevents_event_id, _cybevents_raum and
			// _cybevents_kapazitaet, so saving a demo session in the editor detached it from its event
			// and reset room and capacity.
			update_post_meta( $sess_post_id, '_cybevents_parent_event_id', $event_id );
			update_post_meta( $sess_post_id, '_cybevents_speaker', $s['speaker'] );
			update_post_meta( $sess_post_id, '_cybevents_room', $s['raum'] );
			update_post_meta( $sess_post_id, '_cybevents_start_time', $s['start_time'] );
			update_post_meta( $sess_post_id, '_cybevents_end_time', $s['end_time'] );
			update_post_meta( $sess_post_id, '_cybevents_capacity', $s['kapazitaet'] );

			$row = array(
				'event_id'    => $event_id,
				'wp_post_id'  => $sess_post_id,
				'title'       => $s['title'],
				'description' => $s['description'],
				'speaker'     => $s['speaker'],
				'raum'        => $s['raum'],
				'start_time'  => $s['start_time'],
				'end_time'    => $s['end_time'],
				'kapazitaet'  => $s['kapazitaet'],
				// Honour the definition instead of forcing 0: one session ships saturated so
				// the refusal path is visible without filling it by hand.
				'belegt'      => (int) ( $s['belegt'] ?? 0 ),
			);
			$formats = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' );

			// wp_insert_post() above fires save_post, and CyberEvents_Sessions_Manager::
			// sync_cpt_to_table() already wrote a row for this post with default values. Inserting
			// a second one left phantom sessions in the register: 10 rows for 6 sessions, four of
			// them at the default 30 capacity with no times. Update the row the hook created.
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cybevents_sessions WHERE wp_post_id = %d LIMIT 1", $sess_post_id )
			);

			if ( $existing_id ) {
				$wpdb->update( "{$wpdb->prefix}cybevents_sessions", $row, array( 'id' => $existing_id ), $formats, array( '%d' ) );
				$session_ids[] = $existing_id;
			} else {
				$wpdb->insert( "{$wpdb->prefix}cybevents_sessions", $row, $formats );
				$session_ids[] = (int) $wpdb->insert_id;
			}
		}

		// 3. Create Realistic Attendees with DOI Confirmed & Invoices
		$attendees = array(
			array(
				'first_name' => 'Erika',
				'last_name'  => 'Mustermann',
				'company'    => 'Musterfirma GmbH',
				'email'      => 'erika.mustermann@example.com',
				'checked_in' => 1,
				'sessions'   => array( $session_ids[0], $session_ids[1] ),
			),
			array(
				'first_name' => 'Dr. Max',
				'last_name'  => 'Mustermann',
				'company'    => 'Beispiel-Institut für IT-Sicherheit',
				'email'      => 'max.mustermann@example.org',
				'checked_in' => 1,
				'sessions'   => array( $session_ids[0], $session_ids[2] ),
			),
			array(
				'first_name' => 'Lena',
				'last_name'  => 'Beispiel',
				'company'    => 'Beispiel Software AG',
				'email'      => 'lena.beispiel@example.net',
				'checked_in' => 0,
				'sessions'   => array( $session_ids[1], $session_ids[3] ),
			),
			// Registered but never followed the double opt-in link. Without one of these the
			// pending_doi state, its badge and the reminder queue are invisible in the demo.
			array(
				'first_name' => 'Jonas',
				'last_name'  => 'Probe',
				'company'    => 'Musterverwaltung Musterland',
				'email'      => 'jonas.probe@example.com',
				'checked_in' => 0,
				'sessions'   => array(),
				'confirm_doi' => false,
				'invoice'     => false,
			),
			// Cancelled after confirming, with a credit note. Exercises the storno path and the
			// released capacity that cancel_session_atomic() gives back.
			array(
				'first_name' => 'Tobias',
				'last_name'  => 'Muster',
				'company'    => 'Stadtwerke Musterstadt GmbH',
				'email'      => 'tobias.muster@example.org',
				'checked_in' => 0,
				'sessions'   => array( $session_ids[3] ),
				'cancel_after' => true,
			),
		);

		$seeded_regs   = array();
		$seed_warnings = array();
		foreach ( $attendees as $att ) {
			$reg_data = CyberEvents_Database::create_registration(
				array(
					'event_id'        => $event_id,
					'email'           => $att['email'],
					'first_name'      => $att['first_name'],
					'last_name'       => $att['last_name'],
					'company'         => $att['company'],
					'consent_privacy' => 1,
				)
			);

			if ( ! is_wp_error( $reg_data ) ) {
				$reg_id = $reg_data['registration_id'];

				// Leave one registration in pending_doi so that state is visible in the demo.
				if ( false !== ( $att['confirm_doi'] ?? true ) ) {
					CyberEvents_Database::confirm_doi( $reg_data['raw_doi_token'] );
				}

				// Book assigned sessions. book_session_atomic() returns true|WP_Error since
				// 2026-08-27; a refusal here is real and must not be swallowed by the seeder.
				foreach ( $att['sessions'] as $s_id ) {
					$booking = CyberEvents_Database::book_session_atomic( $s_id, $reg_id );
					if ( is_wp_error( $booking ) ) {
						$seed_warnings[] = sprintf(
							/* translators: 1: attendee email, 2: error message */
							__( 'Demodaten: Buchung für %1$s abgelehnt — %2$s', 'wm-cyber-events-core' ),
							$att['email'],
							$booking->get_error_message()
						);
					}
				}

				// Check-in status
				if ( 1 === $att['checked_in'] ) {
					$wpdb->update(
						"{$wpdb->prefix}cybevents_registrations",
						array(
							'checked_in'    => 1,
							'checked_in_at' => current_time( 'mysql' ),
						),
						array( 'id' => $reg_id )
					);
				}

				// Generate realistic ZUGFeRD 2.3 Invoice
				// create_invoice() returns an int invoice id, not an array. The previous code read
				// $invoice['invoice_number'], which on an int yields null — every seeded
				// registration reported an empty invoice number.
				$invoice_id = ( false === ( $att['invoice'] ?? true ) ) ? 0 : CyberEvents_Invoicing::create_invoice(
					array(
						'registration_id'   => $reg_id,
						'event_id'          => $event_id,
						'recipient_name'    => $att['first_name'] . ' ' . $att['last_name'],
						'recipient_company' => $att['company'],
						'recipient_email'   => $att['email'],
						'recipient_address' => "Musterstraße 42\n12345 Musterstadt\nDeutschland",
						'net_amount'        => 250.00,
						'tax_rate'          => 19.00,
					)
				);

				$invoice_number = '';
				// Without seller data in the settings no invoice is issued (a demo invoice named a
				// hardcoded client as seller until 2026-09-15).
				if ( is_wp_error( $invoice_id ) ) {
					$seed_warnings[] = $invoice_id->get_error_message();
				}
				if ( $invoice_id && ! is_wp_error( $invoice_id ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$invoice_number = (string) $wpdb->get_var(
						$wpdb->prepare( "SELECT invoice_number FROM {$wpdb->prefix}cybevents_invoices WHERE id = %d", $invoice_id )
					);
				}

				// Cancel one attendee after the fact: releases the session capacity and leaves a
				// storno on the invoice, which is the state sec. 14 Abs. 2 UStG produces.
				if ( ! empty( $att['cancel_after'] ) ) {
					foreach ( $att['sessions'] as $s_id ) {
						CyberEvents_Database::cancel_session_atomic( $s_id, $reg_id );
					}
					$wpdb->update(
						"{$wpdb->prefix}cybevents_registrations",
						array( 'status' => 'cancelled' ),
						array( 'id' => $reg_id )
					);
					if ( $invoice_id && ! is_wp_error( $invoice_id ) ) {
						CyberEvents_Invoicing::cancel_invoice( $invoice_id, __( 'Demodaten: Teilnahme storniert', 'wm-cyber-events-core' ) );
					}
				}

				$seeded_regs[] = array(
					'id'        => $reg_id,
					'email'     => $att['email'],
					'raw_token' => $reg_data['raw_token'],
					'invoice'   => $invoice_number,
				);
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		CyberEvents_Database::log_audit(
			'demo_data_seeded',
			$event_id,
			get_current_user_id(),
			wp_json_encode( array( 'event' => $event_title, 'sessions' => count( $session_ids ), 'registrations' => count( $seeded_regs ) ) )
		);

		return array(
			'status'        => 'success',
			'event_id'      => $event_id,
			'event_title'   => $event_title,
			'sessions'      => $session_ids,
			'registrations' => $seeded_regs,
			'warnings'      => $seed_warnings,
		);
	}

	/**
	 * Delete all demo events, sessions, registrations, and invoices cleanly
	 *
	 * @return array Counts of deleted records
	 */
	public static function delete_demo_data() {
		global $wpdb;

		// 1. Find demo events
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$demo_event_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}cybevents_events WHERE title LIKE %s",
				'%[Demo]%'
			)
		);

		$deleted_events        = 0;
		$deleted_sessions      = 0;
		$deleted_registrations = 0;
		$deleted_invoices      = 0;

		if ( ! empty( $demo_event_ids ) ) {
			foreach ( $demo_event_ids as $eid ) {
				$eid = (int) $eid;

				// Delete Invoices for this event
				$inv_count = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}cybevents_invoices WHERE event_id = %d", $eid ) );
				$deleted_invoices += (int) $inv_count;

				// Delete Registration Sessions
				$reg_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cybevents_registrations WHERE event_id = %d", $eid ) );
				if ( ! empty( $reg_ids ) ) {
					$safe_ids = array_map( 'absint', $reg_ids );
					$placeholders = implode( ',', array_fill( 0, count( $safe_ids ), '%d' ) );
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}cybevents_registration_sessions WHERE registration_id IN ({$placeholders})", ...$safe_ids ) );
				}

				// Delete Registrations
				$reg_count = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}cybevents_registrations WHERE event_id = %d", $eid ) );
				$deleted_registrations += (int) $reg_count;

				// Delete Sessions (table + CPT)
				$session_cpt_ids = $wpdb->get_col( $wpdb->prepare( "SELECT wp_post_id FROM {$wpdb->prefix}cybevents_sessions WHERE event_id = %d", $eid ) );
				if ( ! empty( $session_cpt_ids ) ) {
					foreach ( $session_cpt_ids as $scpt ) {
						if ( (int) $scpt > 0 ) {
							wp_delete_post( (int) $scpt, true );
						}
					}
				}
				$sess_count = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}cybevents_sessions WHERE event_id = %d", $eid ) );
				$deleted_sessions += (int) $sess_count;

				// Delete Event CPT post
				$cpt_post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT wp_post_id FROM {$wpdb->prefix}cybevents_events WHERE id = %d", $eid ) );
				if ( $cpt_post_id > 0 ) {
					wp_delete_post( $cpt_post_id, true );
				}

				// Delete Event from table
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}cybevents_events WHERE id = %d", $eid ) );
				$deleted_events++;
			}
		}

		// Delete Demo Venues
		$demo_venue_slugs = array( self::MAIN_VENUE_SLUG, 'demo-tagungshaus-beispielplatz', 'demo-messehalle-beispielstadt', 'demo-coworking-musterhafen' );
		foreach ( $demo_venue_slugs as $vslug ) {
			$term = get_term_by( 'slug', $vslug, 'cybvenue' );
			if ( $term && ! is_wp_error( $term ) ) {
				wp_delete_term( $term->term_id, 'cybvenue' );
			}
		}

		$deleted_pages = self::delete_demo_pages();

		CyberEvents_Database::log_audit(
			'demo_data_purged',
			0,
			get_current_user_id(),
			sprintf( 'Demodaten bereinigt: %d Events, %d Sessions, %d Registrierungen, %d Rechnungen.', $deleted_events, $deleted_sessions, $deleted_registrations, $deleted_invoices )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'events'        => $deleted_events,
			'sessions'      => $deleted_sessions,
			'registrations' => $deleted_registrations,
			'invoices'      => $deleted_invoices,
			'pages'         => $deleted_pages,
		);
	}

	/**
	 * Delete the pages recorded by seed_demo_data() and forget them.
	 *
	 * Only recorded IDs that are still pages: a page found by slug or title could be the site's own.
	 * Pages from seeds before 2026-11-19 were never recorded and stay; they carry "[Demo]" in the title.
	 *
	 * @return int Number of pages deleted.
	 */
	public static function delete_demo_pages(): int {
		$deleted = 0;
		foreach ( array_unique( array_map( 'absint', (array) get_option( self::DEMO_PAGES_OPTION, array() ) ) ) as $page_id ) {
			if ( $page_id > 0 && 'page' === get_post_type( $page_id ) && wp_delete_post( $page_id, true ) ) {
				++$deleted;
			}
		}
		delete_option( self::DEMO_PAGES_OPTION );
		return $deleted;
	}

	/**
	 * Atomic Factory Reset (Auf Werkseinstellungen zurücksetzen)
	 *
	 * Drops all data in custom tables, removes all cybevent/cybsession posts,
	 * deletes cybvenue terms, and restores default plugin settings.
	 */
	public static function reset_to_factory_defaults() {
		global $wpdb;

		// 1. Delete all CPT posts
		$all_events = get_posts( array( 'post_type' => 'cybevent', 'numberposts' => -1, 'post_status' => 'any' ) );
		foreach ( $all_events as $p ) {
			wp_delete_post( $p->ID, true );
		}

		$all_sessions = get_posts( array( 'post_type' => 'cybsession', 'numberposts' => -1, 'post_status' => 'any' ) );
		foreach ( $all_sessions as $p ) {
			wp_delete_post( $p->ID, true );
		}

		self::delete_demo_pages();

		// 2. Delete all venue terms
		$venues = get_terms( array( 'taxonomy' => 'cybvenue', 'hide_empty' => false ) );
		if ( ! is_wp_error( $venues ) && ! empty( $venues ) ) {
			foreach ( $venues as $v ) {
				wp_delete_term( $v->term_id, 'cybvenue' );
			}
		}

		// 3. Truncate Custom Tables
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}cybevents_events" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}cybevents_sessions" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}cybevents_registrations" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}cybevents_registration_sessions" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}cybevents_invoices" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}cybevents_queue" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}cybevents_audit_log" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 4. Reset Settings Options
		delete_option( 'cybevents_settings' );
		delete_option( 'cybevents_active_theme' );
		delete_option( 'cybevents_custom_tokens' );

		CyberEvents_Database::log_audit(
			'factory_reset_executed',
			0,
			get_current_user_id(),
			'System vollständig auf Werkseinstellungen zurückgesetzt.'
		);
	}
}
