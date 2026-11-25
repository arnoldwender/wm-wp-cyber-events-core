<?php
/**
 * Gutenberg Blocks Manager & Dynamic Block Registrations.
 *
 * Provides native WordPress Block Editor integration for Cyber Events suite:
 * 1. [Registration Form Block] - Accessible stepped registration form with live event selector.
 * 2. [Agenda Schedule Matrix Block] - Multi-track conference timetable with interactive filters.
 * 3. [Session Booking Studio Block] - Token-based workshop selector for DOI confirmation pages.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Blocks_Manager {

	/**
	 * Initialize Block hooks.
	 */
	public static function init() {
		add_filter( 'block_categories_all', array( __CLASS__, 'register_block_category' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_editor_assets' ) );
		self::register_blocks();
	}

	/**
	 * Register dedicated "Cyber Events Enterprise" Block Category.
	 *
	 * @param array                   $categories Block categories.
	 * @param WP_Block_Editor_Context $context Block editor context.
	 * @return array Updated categories.
	 */
	public static function register_block_category( $categories, $context ) {
		return array_merge(
			$categories,
			array(
				array(
					'slug'  => 'cyber-events',
					'title' => __( 'Cyber Events Suite', 'wm-cyber-events-core' ),
					'icon'  => 'shield',
				),
			)
		);
	}

	/**
	 * Register Gutenberg Dynamic Blocks.
	 */
	public static function register_blocks() {
		wp_register_script(
			'cybevents-blocks-editor-js',
			CYBEREVENTS_PLUGIN_URL . 'blocks/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			CYBEREVENTS_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'cybevents-blocks-editor-js', 'wm-cyber-events-core', CYBEREVENTS_PLUGIN_DIR . 'languages' );
		}

		wp_register_style(
			'cybevents-blocks-editor-css',
			CYBEREVENTS_PLUGIN_URL . 'blocks/editor.css',
			array( 'wp-edit-blocks' ),
			CYBEREVENTS_VERSION
		);

		// 1. Registration Form Block
		register_block_type(
			CYBEREVENTS_PLUGIN_DIR . 'blocks/registration-form',
			array(
				'render_callback' => array( __CLASS__, 'render_registration_form_block' ),
			)
		);

		// 2. Agenda Schedule Matrix Block
		register_block_type(
			CYBEREVENTS_PLUGIN_DIR . 'blocks/agenda-matrix',
			array(
				'render_callback' => array( __CLASS__, 'render_agenda_matrix_block' ),
			)
		);

		// 3b. Seat Map Block (SVG occupancy, live over SSE)
		register_block_type(
			CYBEREVENTS_PLUGIN_DIR . 'blocks/seat-map',
			array(
				'render_callback' => array( __CLASS__, 'render_seat_map_block' ),
			)
		);

		// 3. Tokenized Session Selector Block
		register_block_type(
			CYBEREVENTS_PLUGIN_DIR . 'blocks/session-selector',
			array(
				'render_callback' => array( __CLASS__, 'render_session_selector_block' ),
			)
		);

		// 4. Event Hero & Countdown Banner Block
		register_block_type(
			CYBEREVENTS_PLUGIN_DIR . 'blocks/event-hero',
			array(
				'render_callback' => array( __CLASS__, 'render_event_hero_block' ),
			)
		);

		// 5. Calendar & .ics Widget Block
		register_block_type(
			CYBEREVENTS_PLUGIN_DIR . 'blocks/calendar-widget',
			array(
				'render_callback' => array( __CLASS__, 'render_calendar_widget_block' ),
			)
		);

		// 6. Speaker & Expert Grid Block
		register_block_type(
			CYBEREVENTS_PLUGIN_DIR . 'blocks/speaker-grid',
			array(
				'render_callback' => array( __CLASS__, 'render_speaker_grid_block' ),
			)
		);

		// 7. Digital Ticket Pass Verifier Block
		register_block_type(
			CYBEREVENTS_PLUGIN_DIR . 'blocks/ticket-pass-verifier',
			array(
				'render_callback' => array( __CLASS__, 'render_ticket_pass_verifier_block' ),
			)
		);

		// 8. Security & DSGVO Trust Badge Block
		register_block_type(
			CYBEREVENTS_PLUGIN_DIR . 'blocks/security-trust-badge',
			array(
				'render_callback' => array( __CLASS__, 'render_security_trust_badge_block' ),
			)
		);

		// 9. Events Catalog & Overview Grid Block
		register_block_type(
			CYBEREVENTS_PLUGIN_DIR . 'blocks/events-grid',
			array(
				'render_callback' => array( __CLASS__, 'render_events_grid_block' ),
			)
		);

		// 10. Multi-View Calendar & Summit Suite Block
		register_block_type(
			CYBEREVENTS_PLUGIN_DIR . 'blocks/calendar-views',
			array(
				'render_callback' => array( 'CyberEvents_Calendar_Views', 'render' ),
			)
		);
	}

	/**
	 * Enqueue Gutenberg Block Editor Assets and localized data.
	 */
	public static function enqueue_editor_assets() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$events = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, location, start_date FROM {$wpdb->prefix}cybevents_events WHERE id > %d ORDER BY id DESC", 0 ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$events_options = array();
		if ( ! empty( $events ) ) {
			foreach ( $events as $ev ) {
				$events_options[] = array(
					'label' => $ev->title . ' (ID: ' . $ev->id . ')',
					'value' => (int) $ev->id,
				);
			}
		}

		wp_enqueue_script(
			'cybevents-blocks-editor-js',
			CYBEREVENTS_PLUGIN_URL . 'blocks/editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n' ),
			CYBEREVENTS_VERSION,
			true
		);

		wp_enqueue_style(
			'cybevents-blocks-editor-css',
			CYBEREVENTS_PLUGIN_URL . 'blocks/editor.css',
			array( 'wp-edit-blocks' ),
			CYBEREVENTS_VERSION
		);

		wp_localize_script(
			'cybevents-blocks-editor-js',
			'cybeventsGutenbergData',
			array(
				'events' => $events_options,
				'i18n'   => array(
					'selectEvent'      => __( 'Veranstaltung auswählen', 'wm-cyber-events-core' ),
					'selectEventDesc'  => __( 'Wählen Sie das Ziel-Event für diesen Block.', 'wm-cyber-events-core' ),
					'showKeyFacts'     => __( 'Key Facts Header anzeigen', 'wm-cyber-events-core' ),
					'showStepper'      => __( 'Schrittanzeige (Stepper) anzeigen', 'wm-cyber-events-core' ),
					'showExportButton' => __( 'Kalender-Export (.ics) Button anzeigen', 'wm-cyber-events-core' ),
					'showCountdown'    => __( 'Countdown-Timer anzeigen', 'wm-cyber-events-core' ),
					'showCapacityPill' => __( 'Freie Plätze anzeigen', 'wm-cyber-events-core' ),
					'columns'          => __( 'Spaltenanzahl', 'wm-cyber-events-core' ),
				),
			)
		);
	}

	private static function enqueue_render_assets(): void {
		if ( function_exists( 'cybevents_enqueue_assets_for_render' ) ) {
			cybevents_enqueue_assets_for_render();
		}
	}

	/**
	 * Server-side Render Callback for Registration Form Block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output.
	 */
	public static function render_registration_form_block( $attributes ) {
		self::enqueue_render_assets();
		$event_id = isset( $attributes['eventId'] ) ? absint( $attributes['eventId'] ) : 0;
		if ( ! $event_id ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cybevents_events WHERE status = %s ORDER BY id DESC LIMIT 1", 'active' ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		if ( ! $event_id ) {
			return '<div class="cybevents-notice cybevents-notice--info"><p>' . esc_html__( 'Bitte wählen Sie eine gültige Veranstaltung in den Block-Einstellungen.', 'wm-cyber-events-core' ) . '</p></div>';
		}

		return CyberEvents_Accessibility::render_registration_form( $event_id, $attributes );
	}

	/**
	 * Server-side Render Callback for Agenda Schedule Matrix Block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output.
	 */
	public static function render_agenda_matrix_block( $attributes ) {
		self::enqueue_render_assets();
		return CyberEvents_Events_Manager::render_agenda( $attributes );
	}

	/**
	 * Server-side Render Callback for Session Selector Block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output.
	 */
	public static function render_session_selector_block( $attributes ) {
		self::enqueue_render_assets();
		if ( is_admin() ) {
			return '<div class="cybevents-gutenberg-preview-box">
				<div class="cybevents-preview-header">
					<span class="cybevents-badge-official">' . esc_html__( 'Vorschau im Editor', 'wm-cyber-events-core' ) . '</span>
					<h3>' . esc_html__( 'Session-Auswahl für bestätigte Anmeldungen', 'wm-cyber-events-core' ) . '</h3>
					<p>' . esc_html__( 'Dieser Block wird im Frontend dynamisch für jeden Teilnehmer mit seinem individuellen Token gerendert.', 'wm-cyber-events-core' ) . '</p>
				</div>
			</div>';
		}

		if ( ! isset( $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '<div class="cybevents-workshop-empty-state">
				<div class="cybevents-notice cybevents-notice--warning">
					<p><strong>' . esc_html__( 'Kein Bestätigungs-Token gefunden.', 'wm-cyber-events-core' ) . '</strong> ' .
					esc_html__( 'Bitte rufen Sie diesen Bereich über den personalisierten Link aus Ihrer Bestätigungs-E-Mail auf.', 'wm-cyber-events-core' ) . '</p>
				</div>
			</div>';
		}

		$raw_token = sanitize_text_field( wp_unslash( $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$reg       = CyberEvents_Database::get_registration_by_token( $raw_token );

		if ( ! $reg ) {
			return '<p class="cybevents-notice cybevents-notice--error">' . esc_html__( 'Ungültiger oder abgelaufener Teilnehmer-Token.', 'wm-cyber-events-core' ) . '</p>';
		}

		return CyberEvents_Sessions_Manager::render_session_selection_ui( $reg );
	}

	/**
	 * Server-side Render Callback for the Seat Map Block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output.
	 */
	public static function render_seat_map_block( $attributes ) {
		self::enqueue_render_assets();
		$event_id = isset( $attributes['eventId'] ) ? absint( $attributes['eventId'] ) : 0;

		if ( is_admin() ) {
			return '<div class="cybevents-gutenberg-preview-box">
				<div class="cybevents-preview-header">
					<span class="cybevents-badge-official">' . esc_html__( 'Vorschau im Editor', 'wm-cyber-events-core' ) . '</span>
					<h3>' . esc_html__( 'Sitzplan der Veranstaltung', 'wm-cyber-events-core' ) . '</h3>
					<p>' . esc_html__( 'Im Frontend zeigt dieser Block die Belegung jeder Session als SVG und hält sie über Server-Sent Events aktuell.', 'wm-cyber-events-core' ) . '</p>
				</div>
			</div>';
		}

		// Without an event in the block, the first active one — the same fallback the hero block uses.
		if ( ! $event_id ) {
			$active = CyberEvents_Database::get_active_events();
			$event_id = isset( $active[0] ) ? (int) $active[0]->id : 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read; the token only marks the viewer's own seats.
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		return CyberEvents_Seat_Map::render(
			$event_id,
			$token,
			array(
				'heading'     => (string) ( $attributes['heading'] ?? '' ),
				'description' => (string) ( $attributes['description'] ?? '' ),
				'live'        => ! isset( $attributes['live'] ) || (bool) $attributes['live'],
			)
		);
	}

	/**
	 * Server-side Render Callback for Event Hero & Countdown Banner Block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output.
	 */
	public static function render_event_hero_block( $attributes ) {
		self::enqueue_render_assets();
		$event_id = isset( $attributes['eventId'] ) ? absint( $attributes['eventId'] ) : 0;
		if ( ! $event_id ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cybevents_events WHERE status = %s ORDER BY id DESC LIMIT 1", 'active' ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$event = CyberEvents_Database::get_event( $event_id );
		if ( ! $event ) {
			return '<div class="cybevents-notice cybevents-notice--info"><p>' . esc_html__( 'Veranstaltung nicht gefunden.', 'wm-cyber-events-core' ) . '</p></div>';
		}

		$preset_style       = ! empty( $attributes['presetStyle'] ) ? sanitize_html_class( 'cybevents-hero--preset-' . $attributes['presetStyle'] ) : 'cybevents-hero--preset-dark-enterprise';
		$border_radius      = ! empty( $attributes['borderRadius'] ) ? sanitize_html_class( 'cyb-radius-' . $attributes['borderRadius'] ) : 'cyb-radius-lg';
		$glow_class         = ! empty( $attributes['enableGlow'] ) ? 'cybevents-has-glow' : '';
		$custom_heading     = ! empty( $attributes['customHeading'] ) ? (string) $attributes['customHeading'] : (string) $event->title;
		$custom_lead        = ! empty( $attributes['customLead'] ) ? (string) $attributes['customLead'] : ( ! empty( $event->description ) ? wp_strip_all_tags( (string) $event->description ) : '' );
		$badge_text         = ! empty( $attributes['badgeText'] ) ? (string) $attributes['badgeText'] : ''; // The default named a client until 2026-09-15.
		$show_status_pill   = $attributes['showStatusPill'] ?? true;
		$show_countdown     = $attributes['showCountdown'] ?? true;
		$show_capacity      = $attributes['showCapacityPill'] ?? true;
		$show_location      = $attributes['showLocation'] ?? true;
		$show_date_time     = $attributes['showDateTime'] ?? true;
		$show_price         = $attributes['showPrice'] ?? true;
		$show_calendar      = $attributes['showCalendarButton'] ?? true;
		$cta_text           = ! empty( $attributes['ctaText'] ) ? (string) $attributes['ctaText'] : ( (float) $event->ticket_price > 0 ? __( 'Jetzt anmelden', 'wm-cyber-events-core' ) : __( 'Jetzt kostenfrei anmelden', 'wm-cyber-events-core' ) );
		$cta_url            = ! empty( $attributes['ctaUrl'] ) ? esc_url( $attributes['ctaUrl'] ) : '#cybevents-registration-form';
		$show_secondary_cta = $attributes['showSecondaryCta'] ?? true;
		$secondary_cta_text = ! empty( $attributes['secondaryCtaText'] ) ? (string) $attributes['secondaryCtaText'] : __( 'Programm ansehen', 'wm-cyber-events-core' );
		// The agenda of the same event carries id="cybevents-agenda-<event id>"; "#cybevents-agenda" (the
		// default until 2026-09-15) matched no element.
		$secondary_cta_url  = ! empty( $attributes['secondaryCtaUrl'] ) ? esc_url( $attributes['secondaryCtaUrl'] ) : '#cybevents-agenda-' . (int) $event->id;

		// Capacity calc
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$confirmed_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE event_id = %d AND status = 'confirmed'", $event->id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max_capacity    = (int) $event->max_capacity;
		$available_seats = max( 0, $max_capacity - $confirmed_count );

		$start_ts   = strtotime( $event->start_date );
		// Real instant for the countdown: $start_ts reads the site's wall-clock time as UTC (right for the
		// date_i18n() display, off by the site's offset for the countdown until 2026-09-15).
		$start_utc  = CyberEvents_Calendar_Exporter::site_time_to_timestamp( $event->start_date );
		$date_fmt   = date_i18n( 'd. F Y', $start_ts );
		$time_fmt   = date_i18n( 'H:i', $start_ts ) . ' Uhr';
		$price_text = ( (float) $event->ticket_price > 0 ) ? number_format_i18n( (float) $event->ticket_price, 2 ) . ' €' : __( 'Kostenfreie Teilnahme', 'wm-cyber-events-core' );

		ob_start();
		?>
		<section class="cybevents-hero-block <?php echo esc_attr( $preset_style . ' ' . $border_radius . ' ' . $glow_class ); ?>">
			<div class="cybevents-hero-inner">
				<div class="cybevents-hero-badges">
					<?php if ( $badge_text ) : ?>
						<span class="cybevents-badge-official"><?php echo esc_html( $badge_text ); ?></span>
					<?php endif; ?>
					<?php if ( $show_status_pill ) : ?>
						<?php if ( $available_seats > 0 ) : ?>
							<span class="cybevents-badge-status is-open"><?php echo CyberEvents_Icons::svg( 'dot', 10 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Anmeldung geöffnet', 'wm-cyber-events-core' ); ?></span>
						<?php else : ?>
							<span class="cybevents-badge-status is-full"><?php echo CyberEvents_Icons::svg( 'dot', 10 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Ausgebucht', 'wm-cyber-events-core' ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</div>

				<h1 class="cybevents-hero-title"><?php echo esc_html( $custom_heading ); ?></h1>

				<?php if ( ! empty( $custom_lead ) ) : ?>
					<p class="cybevents-hero-lead"><?php echo esc_html( $custom_lead ); ?></p>
				<?php endif; ?>

				<?php if ( $show_date_time || $show_location || $show_price || ( $show_capacity && $max_capacity > 0 ) ) : ?>
					<div class="cybevents-hero-meta-bar">
						<?php if ( $show_date_time ) : ?>
							<div class="cybevents-hero-meta-item">
								<span class="cybevents-meta-icon"><?php echo CyberEvents_Icons::svg( 'calendar', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<div>
									<strong><?php echo esc_html( $date_fmt ); ?></strong>
									<span><?php echo esc_html( $time_fmt ); ?></span>
								</div>
							</div>
						<?php endif; ?>
						<?php if ( $show_location && '' !== (string) $event->location ) : ?>
							<div class="cybevents-hero-meta-item">
								<span class="cybevents-meta-icon"><?php echo CyberEvents_Icons::svg( 'map-pin', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<div>
									<strong><?php echo esc_html( $event->location ); ?></strong>
									<span><?php esc_html_e( 'Veranstaltungsort', 'wm-cyber-events-core' ); ?></span>
								</div>
							</div>
						<?php endif; ?>
						<?php if ( $show_price ) : ?>
							<div class="cybevents-hero-meta-item">
								<span class="cybevents-meta-icon"><?php echo CyberEvents_Icons::svg( 'ticket', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<div>
									<strong><?php echo esc_html( $price_text ); ?></strong>
									<span><?php esc_html_e( 'Preis', 'wm-cyber-events-core' ); ?></span>
								</div>
							</div>
						<?php endif; ?>
						<?php if ( $show_capacity && $max_capacity > 0 ) : ?>
							<div class="cybevents-hero-meta-item cybevents-meta-seats">
								<span class="cybevents-meta-icon"><?php echo CyberEvents_Icons::svg( 'users', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<div>
									<?php /* translators: %d: Number of available seats */ ?>
									<strong><?php echo esc_html( sprintf( _n( '%d freier Platz', '%d freie Plätze', $available_seats, 'wm-cyber-events-core' ), $available_seats ) ); ?></strong>
									<?php /* translators: %d: Maximum event capacity */ ?>
									<span><?php echo esc_html( sprintf( __( 'von max. %d', 'wm-cyber-events-core' ), $max_capacity ) ); ?></span>
								</div>
							</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $show_countdown && $start_utc > time() ) : ?>
					<div class="cybevents-countdown-box" data-target-date="<?php echo esc_attr( gmdate( 'c', $start_utc ) ); ?>">
						<div class="cybevents-countdown-title"><?php esc_html_e( 'Beginn in:', 'wm-cyber-events-core' ); ?></div>
						<div class="cybevents-countdown-grid">
							<div class="cybevents-cd-unit"><span class="cybevents-cd-num" id="cyb-cd-days">--</span><span class="cybevents-cd-label"><?php esc_html_e( 'Tage', 'wm-cyber-events-core' ); ?></span></div>
							<div class="cybevents-cd-unit"><span class="cybevents-cd-num" id="cyb-cd-hours">--</span><span class="cybevents-cd-label"><?php esc_html_e( 'Std.', 'wm-cyber-events-core' ); ?></span></div>
							<div class="cybevents-cd-unit"><span class="cybevents-cd-num" id="cyb-cd-mins">--</span><span class="cybevents-cd-label"><?php esc_html_e( 'Min.', 'wm-cyber-events-core' ); ?></span></div>
							<div class="cybevents-cd-unit"><span class="cybevents-cd-num" id="cyb-cd-secs">--</span><span class="cybevents-cd-label"><?php esc_html_e( 'Sek.', 'wm-cyber-events-core' ); ?></span></div>
						</div>
					</div>
				<?php endif; ?>

				<div class="cybevents-hero-actions">
					<a href="<?php echo esc_url( $cta_url ); ?>" class="cybevents-btn cybevents-btn--primary cybevents-btn--lg">
						<?php echo esc_html( $cta_text ); ?> <?php echo CyberEvents_Icons::svg( 'arrow', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</a>
					<?php if ( $show_secondary_cta ) : ?>
						<a href="<?php echo esc_url( $secondary_cta_url ); ?>" class="cybevents-btn cybevents-btn--outline cybevents-btn--lg">
							<?php echo CyberEvents_Icons::svg( 'list', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $secondary_cta_text ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $show_calendar ) : ?>
						<a href="<?php echo esc_url( rest_url( 'cybevents/v1/calendar?event_id=' . $event->id ) ); ?>" class="cybevents-btn cybevents-btn--secondary cybevents-btn--lg">
							<?php echo CyberEvents_Icons::svg( 'calendar', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'In Kalender exportieren (.ics)', 'wm-cyber-events-core' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Server-side Render Callback for Calendar & .ics Widget Block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output.
	 */
	public static function render_calendar_widget_block( $attributes ) {
		self::enqueue_render_assets();
		$event_id = isset( $attributes['eventId'] ) ? absint( $attributes['eventId'] ) : 0;
		if ( ! $event_id ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cybevents_events WHERE status = %s ORDER BY id DESC LIMIT 1", 'active' ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		$event = CyberEvents_Database::get_event( $event_id );
		if ( ! $event ) {
			return '';
		}

		$card_style       = ! empty( $attributes['cardStyle'] ) ? sanitize_html_class( 'cybevents-cal--' . $attributes['cardStyle'] ) : 'cybevents-cal--glass-modern';
		$custom_title     = ! empty( $attributes['customTitle'] ) ? (string) $attributes['customTitle'] : (string) $event->title;
		$show_apple       = $attributes['showAppleIcs'] ?? true;
		$show_google      = $attributes['showGoogleCal'] ?? true;
		$show_outlook     = $attributes['showOutlookWeb'] ?? true;
		$show_office365   = $attributes['showOffice365'] ?? true;
		$show_timezone    = $attributes['showTimezone'] ?? true;
		$show_reminder    = $attributes['showReminderNotice'] ?? true;

		$start_ts   = strtotime( $event->start_date );
		$end_ts     = ! empty( $event->end_date ) ? strtotime( $event->end_date ) : ( $start_ts + 8 * 3600 );
		$day_num    = date_i18n( 'd', $start_ts );
		$month_abbr = strtoupper( date_i18n( 'M', $start_ts ) );
		$year_num   = date_i18n( 'Y', $start_ts );
		$time_str   = date_i18n( 'H:i', $start_ts ) . ' – ' . date_i18n( 'H:i', $end_ts ) . ' Uhr';

		// Calendar URLs
		$ics_url     = rest_url( 'cybevents/v1/calendar?event_id=' . $event->id );
		// The links need real UTC instants: $start_ts/$end_ts read the site's wall-clock time as UTC
		// (right for the date_i18n() display above, two hours off in the links until 2026-09-15). No
		// location fallback: the links named a client's venue for events without one.
		$utc_start   = CyberEvents_Calendar_Exporter::site_time_to_timestamp( $event->start_date );
		$utc_end     = ! empty( $event->end_date ) ? CyberEvents_Calendar_Exporter::site_time_to_timestamp( $event->end_date ) : ( $utc_start + 8 * 3600 );
		$gcal_start  = gmdate( 'Ymd\THis\Z', $utc_start );
		$gcal_end    = gmdate( 'Ymd\THis\Z', $utc_end );
		$location_q  = rawurlencode( (string) $event->location );
		$gcal_url    = 'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' . rawurlencode( $event->title ) . '&dates=' . $gcal_start . '/' . $gcal_end . '&details=' . rawurlencode( $event->description ?: $event->title ) . '&location=' . $location_q;
		$outlook_url = 'https://outlook.live.com/calendar/0/deeplink/compose?path=/calendar/action/compose&rru=addevent&subject=' . rawurlencode( $event->title ) . '&startdt=' . rawurlencode( gmdate( 'c', $utc_start ) ) . '&enddt=' . rawurlencode( gmdate( 'c', $utc_end ) ) . '&location=' . $location_q;
		$o365_url    = 'https://outlook.office.com/calendar/0/deeplink/compose?path=/calendar/action/compose&rru=addevent&subject=' . rawurlencode( $event->title ) . '&startdt=' . rawurlencode( gmdate( 'c', $utc_start ) ) . '&enddt=' . rawurlencode( gmdate( 'c', $utc_end ) ) . '&location=' . $location_q;

		ob_start();
		?>
		<div class="cybevents-cal-widget-wrap">
			<div class="cybevents-cal-card <?php echo esc_attr( $card_style ); ?>">
				<div class="cybevents-cal-date-badge">
					<span class="cybevents-cal-month"><?php echo esc_html( $month_abbr ); ?></span>
					<span class="cybevents-cal-day"><?php echo esc_html( $day_num ); ?></span>
					<span class="cybevents-cal-year"><?php echo esc_html( $year_num ); ?></span>
				</div>

				<div class="cybevents-cal-details">
					<h3 class="cybevents-cal-title"><?php echo esc_html( $custom_title ); ?></h3>
					<div class="cybevents-cal-meta">
						<span><?php echo CyberEvents_Icons::svg( 'clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $time_str ); ?></span>
						<?php if ( '' !== (string) $event->location ) : ?>
							<span><?php echo CyberEvents_Icons::svg( 'map-pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $event->location ); ?></span>
						<?php endif; ?>
						<?php if ( $show_timezone ) : ?>
							<?php // The site's time zone, not a fixed "Europe/Berlin" label. ?>
							<span style="opacity: 0.75; font-size: 13px;"><?php echo CyberEvents_Icons::svg( 'globe', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( wp_timezone_string() ); ?></span>
						<?php endif; ?>
					</div>
					<div class="cybevents-cal-actions">
						<?php if ( $show_apple ) : ?>
							<a href="<?php echo esc_url( $ics_url ); ?>" class="cybevents-btn cybevents-btn--primary">
								<?php echo CyberEvents_Icons::svg( 'download', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Apple / Outlook (.ics-Datei)', 'wm-cyber-events-core' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( $show_google ) : ?>
							<a href="<?php echo esc_url( $gcal_url ); ?>" target="_blank" rel="noopener noreferrer" class="cybevents-btn cybevents-btn--secondary">
								<?php echo CyberEvents_Icons::svg( 'calendar', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Google Kalender', 'wm-cyber-events-core' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( $show_outlook ) : ?>
							<a href="<?php echo esc_url( $outlook_url ); ?>" target="_blank" rel="noopener noreferrer" class="cybevents-btn cybevents-btn--secondary">
								<?php echo CyberEvents_Icons::svg( 'mail', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Outlook Web', 'wm-cyber-events-core' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( $show_office365 ) : ?>
							<a href="<?php echo esc_url( $o365_url ); ?>" target="_blank" rel="noopener noreferrer" class="cybevents-btn cybevents-btn--secondary">
								<?php echo CyberEvents_Icons::svg( 'building', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Microsoft 365', 'wm-cyber-events-core' ); ?>
							</a>
						<?php endif; ?>
					</div>
					<?php if ( $show_reminder ) : ?>
						<p class="cybevents-cal-notice">
							<?php esc_html_e( 'Die .ics-Datei enthält eine Erinnerung einen Tag vor Beginn.', 'wm-cyber-events-core' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Server-side Render Callback for Speaker & Expert Grid Block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output.
	 */
	public static function render_speaker_grid_block( $attributes ) {
		self::enqueue_render_assets();
		$event_id = isset( $attributes['eventId'] ) ? absint( $attributes['eventId'] ) : 0;
		if ( ! $event_id ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cybevents_events WHERE status = %s ORDER BY id DESC LIMIT 1", 'active' ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT speaker, title, raum, start_time FROM {$wpdb->prefix}cybevents_sessions WHERE event_id = %d AND speaker != '' ORDER BY start_time ASC",
				$event_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$heading      = ! empty( $attributes['heading'] ) ? (string) $attributes['heading'] : __( 'Referenten & Fachexperten', 'wm-cyber-events-core' );
		$subheading   = ! empty( $attributes['subheading'] ) ? (string) $attributes['subheading'] : '';
		$badge_text   = ! empty( $attributes['badgeText'] ) ? (string) $attributes['badgeText'] : __( 'Referenten', 'wm-cyber-events-core' );
		$columns      = ! empty( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 3;
		$card_style   = ! empty( $attributes['cardStyle'] ) ? sanitize_html_class( 'cybevents-speaker--' . $attributes['cardStyle'] ) : 'cybevents-speaker--glass-card';
		$avatar_style = ! empty( $attributes['avatarStyle'] ) ? sanitize_html_class( 'cybevents-avatar--' . $attributes['avatarStyle'] ) : 'cybevents-avatar--initials-gradient';
		$show_topics  = $attributes['showTopics'] ?? true;
		$show_room    = $attributes['showRoom'] ?? true;
		$show_time    = $attributes['showTime'] ?? true;

		ob_start();
		?>
		<section class="cybevents-speaker-section">
			<div class="cybevents-speaker-header">
				<?php if ( $badge_text ) : ?>
					<span class="cybevents-badge-official"><?php echo CyberEvents_Icons::svg( 'mic', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $badge_text ); ?></span>
				<?php endif; ?>
				<h2 class="cybevents-speaker-title"><?php echo esc_html( $heading ); ?></h2>
				<?php if ( $subheading ) : ?>
					<p class="cybevents-speaker-subheading" style="opacity: 0.85; margin: 4px 0 16px 0;"><?php echo esc_html( $subheading ); ?></p>
				<?php endif; ?>
			</div>

			<div class="cybevents-speaker-grid cybevents-speaker-grid--cols-<?php echo esc_attr( $columns ); ?>">
				<?php if ( ! empty( $sessions ) ) : ?>
					<?php foreach ( $sessions as $sess ) : ?>
						<?php
						$name_parts = explode( ' ', trim( $sess->speaker ) );
						$initials   = '';
						foreach ( $name_parts as $part ) {
							if ( ! in_array( strtolower( $part ), array( 'dr.', 'prof.', 'von', 'und', 'de' ), true ) ) {
								$initials .= mb_substr( $part, 0, 1 );
							}
						}
						$initials = mb_substr( $initials, 0, 2 ) ?: 'SP';
						?>
						<div class="cybevents-speaker-card <?php echo esc_attr( $card_style ); ?>">
							<div class="cybevents-speaker-avatar <?php echo esc_attr( $avatar_style ); ?>"><?php echo esc_html( strtoupper( $initials ) ); ?></div>
							<h3 class="cybevents-speaker-name"><?php echo esc_html( $sess->speaker ); ?></h3>
							<?php if ( $show_topics && ! empty( $sess->title ) ) : ?>
								<p class="cybevents-speaker-topic"><?php echo esc_html( $sess->title ); ?></p>
							<?php endif; ?>
							<?php if ( ( $show_room && ! empty( $sess->raum ) ) || $show_time ) : ?>
								<div class="cybevents-speaker-footer">
									<?php if ( $show_room && ! empty( $sess->raum ) ) : ?>
										<span class="cybevents-speaker-room"><?php echo CyberEvents_Icons::svg( 'building', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $sess->raum ); ?></span>
									<?php endif; ?>
									<?php if ( $show_time ) : ?>
										<span class="cybevents-speaker-time"><?php echo CyberEvents_Icons::svg( 'clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( date_i18n( 'H:i', strtotime( $sess->start_time ) ) ); ?> Uhr</span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php else : ?>
					<p class="cybevents-speaker-empty"><?php esc_html_e( 'Die Referentenliste wird in Kürze bekanntgegeben.', 'wm-cyber-events-core' ); ?></p>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Server-side Render Callback for Digital Ticket Pass Verifier Block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output.
	 */
	public static function render_ticket_pass_verifier_block( $attributes ) {
		self::enqueue_render_assets();
		$token            = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// The default read "OFFIZIELLER TEILNEHMER-PASS" until 2026-09-15; the plugin cannot say who made it official.
		$pass_title       = ! empty( $attributes['passTitle'] ) ? (string) $attributes['passTitle'] : __( 'TEILNEHMERPASS', 'wm-cyber-events-core' );
		$org_name         = ! empty( $attributes['organizationName'] ) ? (string) $attributes['organizationName'] : '';
		$card_style       = ! empty( $attributes['cardStyle'] ) ? sanitize_html_class( 'cybevents-pass--' . $attributes['cardStyle'] ) : 'cybevents-pass--badge-card';
		$show_print       = $attributes['showPrintButton'] ?? true;
		$show_qr          = $attributes['showQrMatrix'] ?? true;
		$show_inv         = $attributes['showInvoiceRef'] ?? true;
		$show_checkin     = $attributes['showCheckinStatus'] ?? true;

		if ( empty( $token ) && ! is_admin() ) {
			ob_start();
			?>
			<div class="cybevents-ticket-lookup-wrap">
				<div class="cybevents-ticket-lookup-card <?php echo esc_attr( $card_style ); ?>">
					<div class="cybevents-badge-official"><?php echo CyberEvents_Icons::svg( 'ticket', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Teilnehmerpass', 'wm-cyber-events-core' ); ?></div>
					<h3><?php esc_html_e( 'Ticket & Akkreditierung aufrufen', 'wm-cyber-events-core' ); ?></h3>
					<p><?php esc_html_e( 'Geben Sie Ihren 64-stelligen Sicherheits-Token aus der Bestätigungs-E-Mail ein:', 'wm-cyber-events-core' ); ?></p>
					<form method="get" class="cybevents-ticket-search-form">
						<input type="text" name="token" placeholder="<?php esc_attr_e( 'Token eingeben (z. B. c8b60990...)', 'wm-cyber-events-core' ); ?>" required class="cybevents-ticket-input" />
						<button type="submit" class="cybevents-btn cybevents-btn--primary"><?php echo CyberEvents_Icons::svg( 'search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Pass anzeigen', 'wm-cyber-events-core' ); ?></button>
					</form>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		$reg = ! empty( $token ) ? CyberEvents_Database::get_registration_by_token( $token ) : null;
		if ( ! $reg && ! is_admin() ) {
			return '<div class="cybevents-notice cybevents-notice--error"><p>' . esc_html__( 'Ungültiger oder nicht gefundener Buchungstoken.', 'wm-cyber-events-core' ) . '</p></div>';
		}

		// A pass is shown for confirmed registrations only. Until 2026-09-15 every registration found by
		// its token, unconfirmed, cancelled or bounced, was printed as "BESTÄTIGT".
		if ( $reg && 'confirmed' !== $reg->status ) {
			return '<div class="cybevents-notice cybevents-notice--warning"><p>' . esc_html__( 'Für diese Registrierung gibt es keinen Teilnehmerpass: sie ist nicht bestätigt oder wurde storniert.', 'wm-cyber-events-core' ) . '</p></div>';
		}

		$event = $reg ? CyberEvents_Database::get_event( (int) $reg->event_id ) : null;

		// Editor preview without a token: neutral placeholders (until 2026-09-15 the name of a former
		// federal minister and the name of a client).
		$attendee_name = $reg ? ( $reg->first_name . ' ' . $reg->last_name ) : __( 'Max Mustermann', 'wm-cyber-events-core' );
		$company       = $reg ? $reg->company : __( 'Beispiel GmbH', 'wm-cyber-events-core' );
		$invoice_num   = $reg ? $reg->invoice_number : '';
		$checked_in    = $reg ? ( 1 === (int) $reg->checked_in ) : false;
		$ticket_number = $reg ? '#' . (int) $reg->id : '#0000';
		$location      = $event ? (string) $event->location : '';
		if ( empty( $attributes['organizationName'] ) ) {
			$org_name = $event ? (string) $event->title : __( 'Veranstaltung', 'wm-cyber-events-core' );
		}

		$avatar_html = '';
		if ( $reg && ! empty( $reg->avatar_url ) ) {
			$avatar_html = sprintf(
				'<div class="cybevents-pass-avatar"><img src="%s" alt="%s" class="cybevents-pass-avatar-img" /></div>',
				esc_url( $reg->avatar_url ),
				esc_attr( $attendee_name )
			);
		} else {
			$first_n     = $reg ? $reg->first_name : 'Max';
			$last_n      = $reg ? $reg->last_name : 'Mustermann';
			$avatar_html = '<div class="cybevents-pass-avatar">' . CyberEvents_Database::get_avatar_fallback_svg( $first_n, $last_n, 64 ) . '</div>';
		}

		ob_start();
		?>
		<div class="cybevents-ticket-pass-container">
			<div class="cybevents-digital-pass <?php echo esc_attr( $card_style ); ?>" id="cybevents-printable-badge">
				<div class="cybevents-pass-header">
					<div class="cybevents-pass-logo"><?php echo esc_html( $org_name ); ?></div>
					<div class="cybevents-pass-type"><?php echo esc_html( $pass_title ); ?></div>
				</div>

				<div class="cybevents-pass-body">
					<div class="cybevents-pass-photo-col">
						<?php echo wp_kses( $avatar_html, CyberEvents_Icons::allowed_html() ); ?>
					</div>
					<div class="cybevents-pass-details">
						<div class="cybevents-pass-name"><?php echo esc_html( $attendee_name ); ?></div>
						<div class="cybevents-pass-company"><?php echo esc_html( $company ); ?></div>

						<div class="cybevents-pass-meta-grid">
							<?php if ( $show_checkin ) : ?>
								<div>
									<span class="cybevents-pass-label"><?php esc_html_e( 'Status', 'wm-cyber-events-core' ); ?></span>
									<span class="cybevents-pass-val"><?php echo $checked_in ? esc_html__( 'Eingecheckt', 'wm-cyber-events-core' ) : esc_html__( 'Bestätigt', 'wm-cyber-events-core' ); ?></span>
								</div>
							<?php endif; ?>
							<?php if ( $show_inv ) : ?>
								<div>
									<span class="cybevents-pass-label"><?php esc_html_e( 'Rechnungs-Nr.', 'wm-cyber-events-core' ); ?></span>
									<span class="cybevents-pass-val"><?php echo esc_html( $invoice_num ?: __( 'Keine', 'wm-cyber-events-core' ) ); ?></span>
								</div>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( $show_qr ) : ?>
						<?php // The plugin has no QR encoder. Until 2026-09-15 this showed a decorative SVG that encoded nothing and was labelled as an admission QR code; the ticket number is what staff can look up. ?>
						<div class="cybevents-pass-qr">
							<span class="cybevents-pass-val"><?php echo esc_html( $ticket_number ); ?></span>
							<span class="cybevents-qr-caption"><?php esc_html_e( 'Ticket-Nummer', 'wm-cyber-events-core' ); ?></span>
						</div>
					<?php endif; ?>
				</div>

				<div class="cybevents-pass-footer">
					<?php if ( '' !== $location ) : ?>
						<span><?php echo esc_html( $location ); ?></span>
					<?php endif; ?>
					<?php if ( $show_print ) : ?>
						<button type="button" onclick="window.print()" class="cybevents-btn-print"><?php esc_html_e( 'Pass drucken', 'wm-cyber-events-core' ); ?></button>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Server-side Render Callback for Security & DSGVO Trust Badge Block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output.
	 */
	public static function render_security_trust_badge_block( $attributes ) {
		self::enqueue_render_assets();
		// Until 2026-09-15 this block printed conformity statements (DSGVO, BFSG 2025 / BITV 2.0 / WCAG 2.2 AA,
		// BSI IT-Grundschutz, EN 16931 with GiroCode) and credited a client's standards. It now lists what
		// the plugin does; conformity of a site is not something a plugin can state.
		$heading        = ! empty( $attributes['heading'] ) ? (string) $attributes['heading'] : __( 'Datenschutz und Sicherheit', 'wm-cyber-events-core' );
		$subheading     = ! empty( $attributes['subheading'] ) ? (string) $attributes['subheading'] : '';
		$layout         = ! empty( $attributes['layout'] ) ? sanitize_html_class( 'cybevents-trust-layout--' . $attributes['layout'] ) : 'cybevents-trust-layout--grid-4-cols';
		$theme          = ! empty( $attributes['theme'] ) ? sanitize_html_class( 'cybevents-trust-theme--' . $attributes['theme'] ) : 'cybevents-trust-theme--dark-glow';
		$show_dsgvo     = $attributes['showDsgvo'] ?? true;
		$show_bitv      = $attributes['showBitv'] ?? true;
		$show_cdn       = $attributes['showCdnPolicy'] ?? true;
		$show_grund     = $attributes['showGrundschutz'] ?? true;
		$show_zugferd   = $attributes['showZugferd'] ?? true;
		$show_captcha   = ( $attributes['showFriendlyCaptcha'] ?? true ) && CyberEvents_Accessibility::captcha_widget_available();

		ob_start();
		?>
		<section class="cybevents-trust-section <?php echo esc_attr( $layout . ' ' . $theme ); ?>">
			<div class="cybevents-trust-header">
				<h2 class="cybevents-trust-title"><?php echo esc_html( $heading ); ?></h2>
				<?php if ( $subheading ) : ?>
					<p class="cybevents-trust-subheading" style="opacity: 0.85; margin: 4px 0 20px 0;"><?php echo esc_html( $subheading ); ?></p>
				<?php endif; ?>
			</div>

			<div class="cybevents-trust-grid">
				<?php if ( $show_dsgvo ) : ?>
					<div class="cybevents-trust-card">
						<div class="cybevents-trust-icon"><?php echo CyberEvents_Icons::svg( 'mail', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<h3><?php esc_html_e( 'Double Opt-in und Löschfristen', 'wm-cyber-events-core' ); ?></h3>
						<p><?php esc_html_e( 'Eine Anmeldung gilt erst nach Bestätigung über den E-Mail-Link. Unbestätigte Anmeldungen werden nach 14 Tagen gelöscht, Einträge im Audit-Log nach 90 Tagen pseudonymisiert.', 'wm-cyber-events-core' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( $show_bitv ) : ?>
					<div class="cybevents-trust-card">
						<div class="cybevents-trust-icon"><?php echo CyberEvents_Icons::svg( 'pen', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<h3><?php esc_html_e( 'Beschriftete Formulare', 'wm-cyber-events-core' ); ?></h3>
						<p><?php esc_html_e( 'Formularfelder mit sichtbaren Beschriftungen, markierten Pflichtfeldern und Rückmeldungen, die Screenreader vorlesen.', 'wm-cyber-events-core' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( $show_cdn ) : ?>
					<div class="cybevents-trust-card">
						<div class="cybevents-trust-icon"><?php echo CyberEvents_Icons::svg( 'globe', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<h3><?php esc_html_e( 'Eigene Skripte und Styles', 'wm-cyber-events-core' ); ?></h3>
						<p><?php esc_html_e( 'Das Plugin lädt seine Skripte, Styles und Icons vom eigenen Server, nicht von einem CDN.', 'wm-cyber-events-core' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( $show_grund ) : ?>
					<div class="cybevents-trust-card">
						<div class="cybevents-trust-icon"><?php echo CyberEvents_Icons::svg( 'lock', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<h3><?php esc_html_e( 'Audit-Log und Tokens', 'wm-cyber-events-core' ); ?></h3>
						<p><?php esc_html_e( 'Ein mit HMAC-SHA256 verkettetes Audit-Log macht nachträgliche Änderungen erkennbar. Teilnehmer-Tokens werden nur als SHA-256-Hash gespeichert.', 'wm-cyber-events-core' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( $show_zugferd ) : ?>
					<div class="cybevents-trust-card">
						<div class="cybevents-trust-icon"><?php echo CyberEvents_Icons::svg( 'document', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<h3><?php esc_html_e( 'Rechnungs-XML und DATEV', 'wm-cyber-events-core' ); ?></h3>
						<p><?php esc_html_e( 'Rechnungen als CII-XML nach dem Profil EN 16931 und Export als DATEV-Buchungsstapel.', 'wm-cyber-events-core' ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( $show_captcha ) : ?>
					<div class="cybevents-trust-card">
						<div class="cybevents-trust-icon"><?php echo CyberEvents_Icons::svg( 'shield', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<h3><?php esc_html_e( 'Friendly Captcha (Proof-of-Work)', 'wm-cyber-events-core' ); ?></h3>
						<p><?php esc_html_e( 'Privatsphäre-freundlicher Bot-Schutz ohne Nutzer-Tracking oder Bilderrätsel nach EU-Datenschutzrecht.', 'wm-cyber-events-core' ); ?></p>
					</div>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Server-side Render Callback for Events Overview Grid Block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output.
	 */
	public static function render_events_grid_block( $attributes ) {
		self::enqueue_render_assets();
		$limit           = isset( $attributes['limit'] ) ? absint( $attributes['limit'] ) : 6;
		$columns         = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 3;
		$card_style      = ! empty( $attributes['cardStyle'] ) ? sanitize_html_class( 'cybevents-grid-style--' . $attributes['cardStyle'] ) : 'cybevents-grid-style--enterprise-dark';
		$filter_status   = ! empty( $attributes['filterStatus'] ) ? sanitize_text_field( $attributes['filterStatus'] ) : 'active';
		$show_search     = $attributes['showSearch'] ?? true;
		$show_price      = $attributes['showPriceTag'] ?? true;
		$show_location   = $attributes['showLocation'] ?? true;
		$show_date       = $attributes['showDateBadge'] ?? true;
		$show_cal        = $attributes['showCalendarQuickLink'] ?? true;
		$show_desc       = $attributes['showDescription'] ?? true;
		$cta_button_text = ! empty( $attributes['ctaButtonText'] ) ? (string) $attributes['ctaButtonText'] : __( 'Zur Anmeldung', 'wm-cyber-events-core' );

		global $wpdb;
		// Events are 'active', 'draft' or 'archived' (the admin form). Until 2026-09-15 "all" asked for a
		// status 'completed' that no event carries and "archived" fell through to the active events, so both
		// options showed only the active ones.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 'all' === $filter_status ) {
			$events = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}cybevents_events WHERE status IN ('active', 'archived') ORDER BY start_date ASC LIMIT %d",
					$limit
				)
			);
		} elseif ( 'archived' === $filter_status ) {
			$events = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}cybevents_events WHERE status = 'archived' ORDER BY start_date DESC LIMIT %d",
					$limit
				)
			);
		} else {
			$events = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}cybevents_events WHERE status = 'active' ORDER BY start_date ASC LIMIT %d",
					$limit
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $events ) ) {
			$empty_text = 'archived' === $filter_status ? __( 'Es gibt keine archivierten Veranstaltungen.', 'wm-cyber-events-core' ) : __( 'Aktuell sind keine geplanten Veranstaltungen vorhanden.', 'wm-cyber-events-core' );
			return '<p class="cybevents-notice cybevents-notice--info">' . esc_html( $empty_text ) . '</p>';
		}

		$wrapper_id = 'cybevents-catalog-' . wp_rand( 100, 999 );

		ob_start();
		?>
		<div class="cybevents-catalog-wrap <?php echo esc_attr( $card_style ); ?>" id="<?php echo esc_attr( $wrapper_id ); ?>">
			<?php if ( $show_search ) : ?>
				<div class="cybevents-catalog-toolbar" style="margin-bottom: 24px;">
					<div class="cybevents-search-box" style="position: relative; max-width: 380px;">
						<input type="text" class="cybevents-input" placeholder="<?php esc_attr_e( 'Veranstaltung nach Titel oder Ort filtern...', 'wm-cyber-events-core' ); ?>" oninput="var q=this.value.toLowerCase(); document.querySelectorAll('#<?php echo esc_attr( $wrapper_id ); ?> .cybevents-event-card').forEach(function(el){ el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none'; });" style="padding-left: 36px; border-radius: 8px;" />
						<span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); opacity: 0.5;"><?php echo CyberEvents_Icons::svg( 'search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</div>
				</div>
			<?php endif; ?>

			<div class="cybevents-catalog-grid cybevents-catalog-grid--cols-<?php echo esc_attr( $columns ); ?>">
				<?php foreach ( $events as $ev ) : ?>
					<?php
					$start_ts  = strtotime( $ev->start_date );
					$date_str  = date_i18n( 'd. F Y', $start_ts );
					$price_str = ( (float) $ev->ticket_price > 0 ) ? number_format_i18n( (float) $ev->ticket_price, 2 ) . ' €' : __( 'Kostenfrei', 'wm-cyber-events-core' );
					$cal_url   = rest_url( 'cybevents/v1/calendar?event_id=' . $ev->id );
					?>
					<article class="cybevents-event-card">
						<div class="cybevents-event-card-header">
							<?php if ( $show_date ) : ?>
								<span class="cybevents-card-date-tag"><?php echo CyberEvents_Icons::svg( 'calendar', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $date_str ); ?></span>
							<?php endif; ?>
							<?php if ( $show_price ) : ?>
								<span class="cybevents-card-price-tag"><?php echo esc_html( $price_str ); ?></span>
							<?php endif; ?>
						</div>
						<h3 class="cybevents-card-title"><?php echo esc_html( $ev->title ); ?></h3>
						<?php if ( $show_location && '' !== (string) $ev->location ) : ?>
							<div class="cybevents-card-location"><?php echo CyberEvents_Icons::svg( 'map-pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $ev->location ); ?></div>
						<?php endif; ?>
						<?php if ( $show_desc && ! empty( $ev->description ) ) : ?>
							<p class="cybevents-card-desc"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $ev->description ), 18 ) ); ?></p>
						<?php endif; ?>
						<div class="cybevents-card-actions">
							<a href="#cybevents-registration-form" class="cybevents-btn cybevents-btn--primary cybevents-btn--sm">
								<?php echo esc_html( $cta_button_text ); ?>
							</a>
							<?php if ( $show_cal ) : ?>
								<a href="<?php echo esc_url( $cal_url ); ?>" class="cybevents-btn cybevents-btn--secondary cybevents-btn--sm" title="<?php esc_attr_e( 'In Kalender eintragen', 'wm-cyber-events-core' ); ?>">
									<?php echo CyberEvents_Icons::svg( 'calendar', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( '.ics', 'wm-cyber-events-core' ); ?>
								</a>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
