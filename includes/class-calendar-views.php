<?php
/**
 * Cyber Events Multi-View (Calendar Views Engine).
 *
 * Provides 7 dynamic, zero-CDN frontend views backed by stored events and sessions:
 * 1. Month Calendar Matrix View
 * 2. Week Timetable Grid View
 * 3. Day / Schedule Agenda View
 * 4. List Stream View
 * 5. Visual Card Grid View
 * 6. Venue & Room Navigator
 * 7. Multi-Room Session Matrix
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Calendar_Views {

	/** Palette classes defined in cybevents-public.css for card visual variety. */
	private const TRACK_STYLE_CLASSES = array(
		'cybevents-track--pqc',
		'cybevents-track--dora',
		'cybevents-track--kanzlei',
		'cybevents-track--cra',
		'cybevents-track--summit',
	);

	/**
	 * Initialize hooks and shortcodes.
	 */
	public static function init() {
		add_shortcode( 'cybevents_calendar_views', array( __CLASS__, 'render_shortcode' ) );
		add_shortcode( 'cybevents_calendar', array( __CLASS__, 'render_shortcode' ) );
		add_shortcode( 'wm_events_views', array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'default_view' => 'month',
				'show_toolbar' => 'true',
				'show_filter'  => 'true',
				'show_search'  => 'true',
				'show_ticker'  => 'true',
				'theme'        => 'enterprise-dark',
				'event_id'     => 0,
				'limit'        => 12,
				'month'        => '',
			),
			$atts,
			'cybevents_calendar_views'
		);

		return self::render(
			array(
				'defaultView' => sanitize_text_field( (string) $atts['default_view'] ),
				'showToolbar' => filter_var( $atts['show_toolbar'], FILTER_VALIDATE_BOOLEAN ),
				'showFilter'  => filter_var( $atts['show_filter'], FILTER_VALIDATE_BOOLEAN ),
				'showSearch'  => filter_var( $atts['show_search'], FILTER_VALIDATE_BOOLEAN ),
				'showTicker'  => filter_var( $atts['show_ticker'], FILTER_VALIDATE_BOOLEAN ),
				'theme'       => sanitize_text_field( (string) $atts['theme'] ),
				'eventId'     => absint( $atts['event_id'] ),
				'limit'       => absint( $atts['limit'] ),
				'month'       => sanitize_text_field( (string) $atts['month'] ),
			)
		);
	}

	/**
	 * Render the Multi-View Calendar Suite from stored database records.
	 *
	 * @param array $attributes Block/Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render( $attributes ) {
		global $wpdb;
		if ( function_exists( 'cybevents_enqueue_assets_for_render' ) ) {
			cybevents_enqueue_assets_for_render();
		}

		$allowed_views = array( 'month', 'week', 'day', 'list', 'photo', 'map', 'tracks' );
		$raw_view      = ! empty( $attributes['defaultView'] ) ? sanitize_key( (string) $attributes['defaultView'] ) : 'month';
		$default_view  = in_array( $raw_view, $allowed_views, true ) ? $raw_view : 'month';
		$show_toolbar  = $attributes['showToolbar'] ?? true;
		$show_filter   = $attributes['showFilter'] ?? true;
		$show_search   = $attributes['showSearch'] ?? true;
		$show_ticker   = $attributes['showTicker'] ?? true;
		$theme         = ! empty( $attributes['theme'] ) ? sanitize_html_class( (string) $attributes['theme'] ) : 'enterprise-dark';
		$event_id      = isset( $attributes['eventId'] ) ? absint( $attributes['eventId'] ) : 0;
		$limit         = max( 1, isset( $attributes['limit'] ) ? absint( $attributes['limit'] ) : 12 );

		// Fetch active events first, then sessions scoped to those active events.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $event_id > 0 ) {
			$events = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}cybevents_events WHERE id = %d AND status = %s",
					$event_id,
					'active'
				)
			);
		} else {
			$events = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}cybevents_events WHERE status = %s ORDER BY start_date ASC LIMIT %d",
					'active',
					$limit
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$events_by_id = array();
		foreach ( $events as $ev ) {
			$events_by_id[ (int) $ev->id ] = $ev;
		}

		$sessions = array();
		if ( ! empty( $events_by_id ) ) {
			$event_ids       = array_keys( $events_by_id );
			$in_placeholders = implode( ', ', array_fill( 0, count( $event_ids ), '%d' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$sessions = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}cybevents_sessions WHERE event_id IN ($in_placeholders) ORDER BY start_time ASC",
					...$event_ids
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			foreach ( $sessions as $session_row ) {
				$parent_event                = $events_by_id[ (int) $session_row->event_id ] ?? null;
				$session_row->event_title    = $parent_event ? (string) $parent_event->title : '';
				$session_row->event_location = $parent_event ? (string) $parent_event->location : '';
			}
		}

		$target_ym     = self::resolve_target_month( $attributes, $events, $sessions );
		$tz            = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$month_start   = new DateTimeImmutable( $target_ym . '-01 12:00:00', new DateTimeZone( 'UTC' ) );
		$target_year   = (int) $month_start->format( 'Y' );
		$target_month  = (int) $month_start->format( 'n' );
		$days_in_month = (int) $month_start->format( 't' );
		$first_iso_dow = (int) $month_start->format( 'N' );
		$leading_days  = $first_iso_dow - 1;
		$prev_end      = $month_start->modify( '-1 day' );
		$prev_last_day = (int) $prev_end->format( 'j' );
		$total_cells   = $leading_days + $days_in_month;
		$trailing_days = ( 7 - ( $total_cells % 7 ) ) % 7;
		$period_label  = date_i18n( 'F Y', $month_start->getTimestamp() );

		// Collect distinct rooms and track style classes.
		$rooms_by_slug   = array();
		$style_class_map = array();
		$style_index     = 0;
		foreach ( $sessions as $s ) {
			$room_label = trim( (string) ( $s->raum ?? '' ) );
			if ( '' !== $room_label ) {
				$room_slug = self::room_slug( $room_label );
				if ( ! isset( $rooms_by_slug[ $room_slug ] ) ) {
					$rooms_by_slug[ $room_slug ] = $room_label;
				}
			}
			$ev_key = (int) $s->event_id;
			if ( ! isset( $style_class_map[ $ev_key ] ) ) {
				$style_class_map[ $ev_key ] = self::TRACK_STYLE_CLASSES[ $style_index % count( self::TRACK_STYLE_CLASSES ) ];
				$style_index++;
			}
		}
		foreach ( $events as $ev ) {
			$ev_key = (int) $ev->id;
			if ( ! isset( $style_class_map[ $ev_key ] ) ) {
				$style_class_map[ $ev_key ] = self::TRACK_STYLE_CLASSES[ $style_index % count( self::TRACK_STYLE_CLASSES ) ];
				$style_index++;
			}
		}

		// Index calendar chips by YYYY-MM-DD inside the active target month.
		$month_items_by_day = array();
		foreach ( $sessions as $s ) {
			$s_ymd = substr( (string) ( $s->start_time ?? '' ), 0, 10 );
			if ( str_starts_with( $s_ymd, $target_ym . '-' ) ) {
				$d_num    = (int) substr( $s_ymd, 8, 2 );
				$parent   = $events_by_id[ (int) $s->event_id ] ?? null;
				$room     = trim( (string) ( $s->raum ?? '' ) );
				$speaker  = trim( (string) ( $s->speaker ?? '' ) );
				$start_ts = strtotime( (string) $s->start_time . ' UTC' );
				$end_ts   = ! empty( $s->end_time ) ? strtotime( (string) $s->end_time . ' UTC' ) : 0;
				$time_str = $start_ts ? date_i18n( 'H:i', $start_ts ) : '';
				$span_str = $start_ts ? date_i18n( 'd.m.Y · H:i', $start_ts ) . ( $end_ts ? ' – ' . date_i18n( 'H:i', $end_ts ) : '' ) : '';

				$month_items_by_day[ $d_num ][] = array(
					'event_id'    => (int) $s->event_id,
					'room_slug'   => '' !== $room ? self::room_slug( $room ) : 'general',
					'track_class' => $style_class_map[ (int) $s->event_id ] ?? 'cybevents-track--pqc',
					'time'        => $time_str,
					'title'       => (string) $s->title,
					'detail_time' => $span_str,
					'detail_room' => '' !== $room ? $room : ( $parent ? (string) $parent->location : '' ),
					'speaker'     => $speaker,
					'price'       => $parent ? self::format_price( (float) $parent->ticket_price ) : '',
					'is_featured' => false,
				);
			}
		}

		// Also place active events that fall in the target month when no session already represents them on that start day.
		foreach ( $events as $ev ) {
			$ev_start_ymd = substr( (string) ( $ev->start_date ?? '' ), 0, 10 );
			if ( str_starts_with( $ev_start_ymd, $target_ym . '-' ) ) {
				$d_num       = (int) substr( $ev_start_ymd, 8, 2 );
				$has_session = false;
				foreach ( $month_items_by_day[ $d_num ] ?? array() as $existing_item ) {
					if ( (int) $existing_item['event_id'] === (int) $ev->id ) {
						$has_session = true;
						break;
					}
				}
				if ( ! $has_session ) {
					$ev_ts = strtotime( (string) $ev->start_date . ' UTC' );
					$month_items_by_day[ $d_num ][] = array(
						'event_id'    => (int) $ev->id,
						'room_slug'   => 'general',
						'track_class' => $style_class_map[ (int) $ev->id ] ?? 'cybevents-track--summit',
						'time'        => $ev_ts ? date_i18n( 'H:i', $ev_ts ) : '',
						'title'       => (string) $ev->title,
						'detail_time' => $ev_ts ? date_i18n( 'd.m.Y · H:i', $ev_ts ) : '',
						'detail_room' => (string) $ev->location,
						'speaker'     => '',
						'price'       => self::format_price( (float) $ev->ticket_price ),
						'is_featured' => true,
					);
				}
			}
		}

		// Determine 7-day Monday..Sunday range for Week View inside/around the target month.
		$week_anchor_ymd = $target_ym . '-01';
		foreach ( $sessions as $s ) {
			$s_ymd = substr( (string) ( $s->start_time ?? '' ), 0, 10 );
			if ( str_starts_with( $s_ymd, $target_ym . '-' ) ) {
				$week_anchor_ymd = $s_ymd;
				break;
			}
		}
		if ( $week_anchor_ymd === $target_ym . '-01' ) {
			foreach ( $events as $ev ) {
				$e_ymd = substr( (string) ( $ev->start_date ?? '' ), 0, 10 );
				if ( str_starts_with( $e_ymd, $target_ym . '-' ) ) {
					$week_anchor_ymd = $e_ymd;
					break;
				}
			}
		}
		$anchor_dt     = new DateTimeImmutable( $week_anchor_ymd . ' 12:00:00', $tz );
		$week_monday   = $anchor_dt->modify( '-' . ( (int) $anchor_dt->format( 'N' ) - 1 ) . ' days' );
		$week_days     = array();
		for ( $w = 0; $w < 7; $w++ ) {
			$d_dt                = $week_monday->modify( '+' . $w . ' days' );
			$week_days[]         = array(
				'ymd'   => $d_dt->format( 'Y-m-d' ),
				'label' => date_i18n( 'D d. M', $d_dt->getTimestamp() ),
			);
		}

		// Group entries by date for Day / Agenda view.
		$agenda_by_day = array();
		foreach ( $sessions as $s ) {
			$ymd = substr( (string) ( $s->start_time ?? '' ), 0, 10 );
			if ( '' !== $ymd ) {
				$agenda_by_day[ $ymd ][] = array(
					'type'        => 'session',
					'event_id'    => (int) $s->event_id,
					'room'        => trim( (string) ( $s->raum ?? '' ) ),
					'title'       => (string) $s->title,
					'description' => trim( (string) ( $s->description ?? '' ) ),
					'speaker'     => trim( (string) ( $s->speaker ?? '' ) ),
					'start_time'  => (string) $s->start_time,
					'end_time'    => (string) $s->end_time,
					'event_title' => (string) ( $s->event_title ?? '' ),
				);
			}
		}
		if ( empty( $agenda_by_day ) ) {
			foreach ( $events as $ev ) {
				$ymd = substr( (string) ( $ev->start_date ?? '' ), 0, 10 );
				if ( '' !== $ymd ) {
					$agenda_by_day[ $ymd ][] = array(
						'type'        => 'event',
						'event_id'    => (int) $ev->id,
						'room'        => trim( (string) ( $ev->location ?? '' ) ),
						'title'       => (string) $ev->title,
						'description' => trim( (string) ( $ev->description ?? '' ) ),
						'speaker'     => '',
						'start_time'  => (string) $ev->start_date,
						'end_time'    => (string) $ev->end_date,
						'event_title' => (string) $ev->title,
					);
				}
			}
		}
		ksort( $agenda_by_day );

		// Group sessions by room for Venue & Room Navigator and Matrix view.
		$sessions_by_room = array();
		foreach ( $sessions as $s ) {
			$room_label                        = trim( (string) ( $s->raum ?? '' ) );
			$display_room                      = '' !== $room_label ? $room_label : __( 'Allgemein', 'wm-cyber-events-core' );
			$sessions_by_room[ $display_room ][] = $s;
		}

		// Distinct event locations for Venue Navigator.
		$locations = array();
		foreach ( $events as $ev ) {
			$loc = trim( (string) ( $ev->location ?? '' ) );
			if ( '' !== $loc && ! in_array( $loc, $locations, true ) ) {
				$locations[] = $loc;
			}
		}

		$uid = 'cyb-calendar-suite-' . wp_rand( 1000, 9999 );

		// Icons are decorative inline SVG from CyberEvents_Icons, escaped through wp_kses().
		$svg = CyberEvents_Icons::allowed_html();

		ob_start();
		?>
		<div class="cybevents-multi-view-wrapper cybevents-theme--<?php echo esc_attr( $theme ); ?>" id="<?php echo esc_attr( $uid ); ?>" data-current-view="<?php echo esc_attr( $default_view ); ?>" data-year="<?php echo esc_attr( (string) $target_year ); ?>" data-month="<?php echo esc_attr( (string) $target_month ); ?>">

			<?php if ( $show_ticker ) : ?>
				<div class="cybevents-telemetry-ticker-bar" role="region" aria-label="<?php esc_attr_e( 'Live Konferenz- und Agenda-Ticker', 'wm-cyber-events-core' ); ?>">
					<div class="cybevents-ticker-badge">
						<span class="cybevents-pulse-dot" aria-hidden="true"></span>
						<span><?php esc_html_e( 'AGENDA', 'wm-cyber-events-core' ); ?></span>
					</div>
					<div class="cybevents-ticker-track-wrap">
						<div class="cybevents-ticker-track">
							<?php
							$ticker_parts = array();
							foreach ( $events as $ev ) {
								$ev_ts   = strtotime( (string) $ev->start_date . ' UTC' );
								$ev_date = $ev_ts ? date_i18n( 'd.m.Y', $ev_ts ) : '';
								$ev_loc  = trim( (string) ( $ev->location ?? '' ) );
								$meta    = trim( $ev_date . ( '' !== $ev_loc ? ' · ' . $ev_loc : '' ) );
								$ticker_parts[] = array(
									'icon'  => 'calendar',
									'title' => (string) $ev->title,
									'meta'  => $meta,
								);
							}
							foreach ( array_slice( $sessions, 0, 5 ) as $s ) {
								$cap  = (int) $s->kapazitaet;
								$free = max( 0, $cap - (int) $s->belegt );
								$bits = array();
								if ( '' !== trim( (string) $s->raum ) ) {
									$bits[] = trim( (string) $s->raum );
								}
								if ( $cap > 0 ) {
									$bits[] = 0 === $free
										? __( 'Ausgebucht', 'wm-cyber-events-core' )
										: sprintf(
											/* translators: %d: Number of free seats */
											_n( '%d freier Platz', '%d freie Plätze', $free, 'wm-cyber-events-core' ),
											$free
										);
								}
								$ticker_parts[] = array(
									'icon'  => 'clock',
									'title' => (string) $s->title,
									'meta'  => implode( ' · ', $bits ),
								);
							}
							if ( empty( $ticker_parts ) ) :
								?>
								<span class="cybevents-ticker-item"><?php echo wp_kses( CyberEvents_Icons::svg( 'calendar', 14 ), $svg ); ?> <?php esc_html_e( 'Keine Veranstaltungen gefunden.', 'wm-cyber-events-core' ); ?></span>
								<?php
							else :
								foreach ( $ticker_parts as $idx => $part ) :
									if ( $idx > 0 ) :
										?>
										<span class="cybevents-ticker-sep"><?php echo wp_kses( CyberEvents_Icons::svg( 'diamond', 8 ), $svg ); ?></span>
										<?php
									endif;
									?>
									<span class="cybevents-ticker-item"><?php echo wp_kses( CyberEvents_Icons::svg( $part['icon'], 14 ), $svg ); ?> <strong><?php echo esc_html( $part['title'] ); ?></strong><?php echo '' !== $part['meta'] ? ': ' . esc_html( $part['meta'] ) : ''; ?></span>
									<?php
								endforeach;
							endif;
							?>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $show_toolbar ) : ?>
				<header class="cybevents-view-toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Ansichten und Filtersteuerung', 'wm-cyber-events-core' ); ?>">
					<div class="cybevents-toolbar-top">
						<div class="cybevents-view-buttons" role="tablist" aria-label="<?php esc_attr_e( 'Kalenderansichten', 'wm-cyber-events-core' ); ?>">
							<button type="button" class="cybevents-view-btn <?php echo ( 'month' === $default_view ) ? 'is-active' : ''; ?>" data-view="month" role="tab" aria-selected="<?php echo ( 'month' === $default_view ) ? 'true' : 'false'; ?>" onclick="window.cybSwitchView('<?php echo esc_attr( $uid ); ?>', 'month')">
								<span class="cybevents-btn-icon" aria-hidden="true"><?php echo wp_kses( CyberEvents_Icons::svg( 'calendar' ), $svg ); ?></span>
								<span class="cybevents-btn-label"><?php esc_html_e( 'Monat', 'wm-cyber-events-core' ); ?></span>
							</button>
							<button type="button" class="cybevents-view-btn <?php echo ( 'week' === $default_view ) ? 'is-active' : ''; ?>" data-view="week" role="tab" aria-selected="<?php echo ( 'week' === $default_view ) ? 'true' : 'false'; ?>" onclick="window.cybSwitchView('<?php echo esc_attr( $uid ); ?>', 'week')">
								<span class="cybevents-btn-icon" aria-hidden="true"><?php echo wp_kses( CyberEvents_Icons::svg( 'columns' ), $svg ); ?></span>
								<span class="cybevents-btn-label"><?php esc_html_e( 'Woche', 'wm-cyber-events-core' ); ?></span>
							</button>
							<button type="button" class="cybevents-view-btn <?php echo ( 'day' === $default_view ) ? 'is-active' : ''; ?>" data-view="day" role="tab" aria-selected="<?php echo ( 'day' === $default_view ) ? 'true' : 'false'; ?>" onclick="window.cybSwitchView('<?php echo esc_attr( $uid ); ?>', 'day')">
								<span class="cybevents-btn-icon" aria-hidden="true"><?php echo wp_kses( CyberEvents_Icons::svg( 'clock' ), $svg ); ?></span>
								<span class="cybevents-btn-label"><?php esc_html_e( 'Tag / Agenda', 'wm-cyber-events-core' ); ?></span>
							</button>
							<button type="button" class="cybevents-view-btn <?php echo ( 'list' === $default_view ) ? 'is-active' : ''; ?>" data-view="list" role="tab" aria-selected="<?php echo ( 'list' === $default_view ) ? 'true' : 'false'; ?>" onclick="window.cybSwitchView('<?php echo esc_attr( $uid ); ?>', 'list')">
								<span class="cybevents-btn-icon" aria-hidden="true"><?php echo wp_kses( CyberEvents_Icons::svg( 'list' ), $svg ); ?></span>
								<span class="cybevents-btn-label"><?php esc_html_e( 'Liste', 'wm-cyber-events-core' ); ?></span>
							</button>
							<button type="button" class="cybevents-view-btn <?php echo ( 'photo' === $default_view ) ? 'is-active' : ''; ?>" data-view="photo" role="tab" aria-selected="<?php echo ( 'photo' === $default_view ) ? 'true' : 'false'; ?>" onclick="window.cybSwitchView('<?php echo esc_attr( $uid ); ?>', 'photo')">
								<span class="cybevents-btn-icon" aria-hidden="true"><?php echo wp_kses( CyberEvents_Icons::svg( 'camera' ), $svg ); ?></span>
								<span class="cybevents-btn-label"><?php esc_html_e( 'Karten', 'wm-cyber-events-core' ); ?></span>
							</button>
							<button type="button" class="cybevents-view-btn <?php echo ( 'map' === $default_view ) ? 'is-active' : ''; ?>" data-view="map" role="tab" aria-selected="<?php echo ( 'map' === $default_view ) ? 'true' : 'false'; ?>" onclick="window.cybSwitchView('<?php echo esc_attr( $uid ); ?>', 'map')">
								<span class="cybevents-btn-icon" aria-hidden="true"><?php echo wp_kses( CyberEvents_Icons::svg( 'map-pin' ), $svg ); ?></span>
								<span class="cybevents-btn-label"><?php esc_html_e( 'Räume & Ort', 'wm-cyber-events-core' ); ?></span>
							</button>
							<button type="button" class="cybevents-view-btn <?php echo ( 'tracks' === $default_view ) ? 'is-active' : ''; ?>" data-view="tracks" role="tab" aria-selected="<?php echo ( 'tracks' === $default_view ) ? 'true' : 'false'; ?>" onclick="window.cybSwitchView('<?php echo esc_attr( $uid ); ?>', 'tracks')">
								<span class="cybevents-btn-icon" aria-hidden="true"><?php echo wp_kses( CyberEvents_Icons::svg( 'grid' ), $svg ); ?></span>
								<span class="cybevents-btn-label"><?php esc_html_e( 'Summit Tracks', 'wm-cyber-events-core' ); ?></span>
							</button>
						</div>

						<div class="cybevents-nav-controls">
							<button type="button" class="cybevents-nav-arrow" aria-label="<?php esc_attr_e( 'Vorheriger Zeitraum', 'wm-cyber-events-core' ); ?>" onclick="window.cybNavigatePeriod('<?php echo esc_attr( $uid ); ?>', -1)">‹</button>
							<span class="cybevents-current-period-label" id="<?php echo esc_attr( $uid ); ?>-period-label"><?php echo esc_html( $period_label ); ?></span>
							<button type="button" class="cybevents-nav-arrow" aria-label="<?php esc_attr_e( 'Nächster Zeitraum', 'wm-cyber-events-core' ); ?>" onclick="window.cybNavigatePeriod('<?php echo esc_attr( $uid ); ?>', 1)">›</button>
						</div>
					</div>

					<?php if ( $show_filter || $show_search ) : ?>
						<div class="cybevents-toolbar-filters">
							<?php if ( $show_search ) : ?>
								<div class="cybevents-filter-search">
									<input type="text" class="cybevents-search-field" placeholder="<?php esc_attr_e( 'Veranstaltung, Thema oder Dozent suchen...', 'wm-cyber-events-core' ); ?>" oninput="window.cybFilterKeyword('<?php echo esc_attr( $uid ); ?>', this.value)" aria-label="<?php esc_attr_e( 'Suchbegriff eingeben', 'wm-cyber-events-core' ); ?>">
									<span class="cybevents-search-icon" aria-hidden="true"><?php echo wp_kses( CyberEvents_Icons::svg( 'search' ), $svg ); ?></span>
								</div>
							<?php endif; ?>

							<?php if ( $show_filter ) : ?>
								<div class="cybevents-filter-selects">
									<select class="cybevents-select" onchange="window.cybFilterTrack('<?php echo esc_attr( $uid ); ?>', this.value)" aria-label="<?php esc_attr_e( 'Alle Veranstaltungen', 'wm-cyber-events-core' ); ?>">
										<option value="all"><?php esc_html_e( 'Alle Veranstaltungen', 'wm-cyber-events-core' ); ?></option>
										<?php foreach ( $events as $ev ) : ?>
											<option value="<?php echo esc_attr( 'event-' . (int) $ev->id ); ?>"><?php echo esc_html( (string) $ev->title ); ?></option>
										<?php endforeach; ?>
									</select>

									<select class="cybevents-select" onchange="window.cybFilterRoom('<?php echo esc_attr( $uid ); ?>', this.value)" aria-label="<?php esc_attr_e( 'Alle Säle & Räume', 'wm-cyber-events-core' ); ?>">
										<option value="all"><?php esc_html_e( 'Alle Säle & Räume', 'wm-cyber-events-core' ); ?></option>
										<?php foreach ( $rooms_by_slug as $room_slug => $room_label ) : ?>
											<option value="<?php echo esc_attr( $room_slug ); ?>"><?php echo esc_html( $room_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</header>
			<?php endif; ?>

			<!-- VIEW CONTAINER PANELS (a div: the page around the block has its own main landmark) -->
			<div class="cybevents-views-stage" aria-live="polite">

				<!-- 1. MONTH CALENDAR VIEW -->
				<section class="cybevents-view-pane cybevents-view-month <?php echo ( 'month' === $default_view ) ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $uid ); ?>-view-month" role="tabpanel" aria-label="<?php esc_attr_e( 'Monatskalenderansicht', 'wm-cyber-events-core' ); ?>">
					<div class="cybevents-month-grid">
						<div class="cybevents-month-header-row">
							<span><?php esc_html_e( 'Mo', 'wm-cyber-events-core' ); ?></span>
							<span><?php esc_html_e( 'Di', 'wm-cyber-events-core' ); ?></span>
							<span><?php esc_html_e( 'Mi', 'wm-cyber-events-core' ); ?></span>
							<span><?php esc_html_e( 'Do', 'wm-cyber-events-core' ); ?></span>
							<span><?php esc_html_e( 'Fr', 'wm-cyber-events-core' ); ?></span>
							<span><?php esc_html_e( 'Sa', 'wm-cyber-events-core' ); ?></span>
							<span><?php esc_html_e( 'So', 'wm-cyber-events-core' ); ?></span>
						</div>
						<div class="cybevents-month-cells">
							<?php for ( $p = $prev_last_day - $leading_days + 1; $p <= $prev_last_day; $p++ ) : ?>
								<div class="cybevents-day-cell is-other-month"><span class="cybevents-day-num"><?php echo esc_html( (string) $p ); ?></span></div>
							<?php endfor; ?>

							<?php
							for ( $d = 1; $d <= $days_in_month; $d++ ) :
								$day_items = $month_items_by_day[ $d ] ?? array();
								$has_event = ! empty( $day_items );
								?>
								<div class="cybevents-day-cell <?php echo $has_event ? 'has-event' : ''; ?>" data-day="<?php echo esc_attr( (string) $d ); ?>">
									<div class="cybevents-day-cell-top">
										<span class="cybevents-day-num"><?php echo esc_html( (string) $d ); ?></span>
									</div>
									<div class="cybevents-day-events-list">
										<?php foreach ( $day_items as $item ) : ?>
											<div class="cybevents-event-chip <?php echo esc_attr( $item['track_class'] ); ?>" data-event-id="<?php echo esc_attr( 'event-' . (int) $item['event_id'] ); ?>" data-room="<?php echo esc_attr( $item['room_slug'] ); ?>" data-detail-title="<?php echo esc_attr( $item['title'] ); ?>" data-detail-time="<?php echo esc_attr( $item['detail_time'] ); ?>" data-detail-room="<?php echo esc_attr( $item['detail_room'] ); ?>" data-detail-speaker="<?php echo esc_attr( $item['speaker'] ); ?>" data-detail-price="<?php echo esc_attr( $item['price'] ); ?>" data-detail-link="#cybevents-registration-form" onclick="window.cybOpenDetailFromElement('<?php echo esc_attr( $uid ); ?>', this)">
												<?php if ( '' !== $item['time'] ) : ?>
													<span class="cybevents-chip-time"><?php echo esc_html( $item['time'] ); ?></span>
												<?php endif; ?>
												<span class="cybevents-chip-title">
													<?php
													if ( $item['is_featured'] ) {
														echo wp_kses( CyberEvents_Icons::svg( 'star', 12 ), $svg ) . ' ';
													}
													echo esc_html( $item['title'] );
													?>
												</span>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endfor; ?>

							<?php for ( $n = 1; $n <= $trailing_days; $n++ ) : ?>
								<div class="cybevents-day-cell is-other-month"><span class="cybevents-day-num"><?php echo esc_html( (string) $n ); ?></span></div>
							<?php endfor; ?>
						</div>
					</div>
				</section>

				<!-- 2. WEEK TIMETABLE GRID VIEW -->
				<section class="cybevents-view-pane cybevents-view-week <?php echo ( 'week' === $default_view ) ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $uid ); ?>-view-week" role="tabpanel" aria-label="<?php esc_attr_e( 'Wochenplan- und Zeitrasteransicht', 'wm-cyber-events-core' ); ?>">
					<div class="cybevents-week-table-wrap">
						<div class="cybevents-week-table">
							<div class="cybevents-week-time-col">
								<div class="cybevents-week-corner"><?php esc_html_e( 'Uhrzeit', 'wm-cyber-events-core' ); ?></div>
								<?php for ( $hr = 8; $hr <= 18; $hr++ ) : ?>
									<div class="cybevents-hour-row"><?php echo esc_html( sprintf( '%02d:00', $hr ) ); ?></div>
								<?php endfor; ?>
							</div>
							<div class="cybevents-week-days-grid">
								<?php
								foreach ( $week_days as $wd ) :
									$day_sessions = array();
									foreach ( $sessions as $s ) {
										if ( substr( (string) ( $s->start_time ?? '' ), 0, 10 ) === $wd['ymd'] ) {
											$day_sessions[] = array(
												'event_id'  => (int) $s->event_id,
												'room'      => trim( (string) ( $s->raum ?? '' ) ),
												'title'     => (string) $s->title,
												'start'     => (string) $s->start_time,
												'end'       => (string) $s->end_time,
												'track_cls' => $style_class_map[ (int) $s->event_id ] ?? 'cybevents-track--pqc',
											);
										}
									}
									if ( empty( $day_sessions ) ) {
										foreach ( $events as $ev ) {
											if ( substr( (string) ( $ev->start_date ?? '' ), 0, 10 ) === $wd['ymd'] ) {
												$day_sessions[] = array(
													'event_id'  => (int) $ev->id,
													'room'      => trim( (string) ( $ev->location ?? '' ) ),
													'title'     => (string) $ev->title,
													'start'     => (string) $ev->start_date,
													'end'       => (string) $ev->end_date,
													'track_cls' => $style_class_map[ (int) $ev->id ] ?? 'cybevents-track--summit',
												);
											}
										}
									}
									?>
									<div class="cybevents-week-day-col <?php echo ! empty( $day_sessions ) ? 'is-summit-highlight' : ''; ?>">
										<div class="cybevents-week-day-header"><span><?php echo esc_html( $wd['label'] ); ?></span></div>
										<div class="cybevents-week-slot-area">
											<?php if ( empty( $day_sessions ) ) : ?>
												<div class="cybevents-week-empty"><?php esc_html_e( 'Keine Termine', 'wm-cyber-events-core' ); ?></div>
											<?php else : ?>
												<?php
												foreach ( $day_sessions as $slot ) :
													$s_ts   = strtotime( $slot['start'] . ' UTC' );
													$e_ts   = ! empty( $slot['end'] ) ? strtotime( $slot['end'] . ' UTC' ) : ( $s_ts ? $s_ts + 3600 : 0 );
													$s_hour = $s_ts ? ( (int) gmdate( 'G', $s_ts ) + ( (int) gmdate( 'i', $s_ts ) / 60 ) ) : 9.0;
													$dur_h  = ( $s_ts && $e_ts && $e_ts > $s_ts ) ? ( ( $e_ts - $s_ts ) / 3600 ) : 1.0;
													$top_px = max( 0, min( 600, (int) round( ( $s_hour - 8.0 ) * 60 ) ) );
													$ht_px  = max( 48, min( 300, (int) round( $dur_h * 60 ) ) );
													$time_t = $s_ts ? date_i18n( 'H:i', $s_ts ) . ( $e_ts ? '–' . date_i18n( 'H:i', $e_ts ) : '' ) : '';
													$r_slug = '' !== $slot['room'] ? self::room_slug( $slot['room'] ) : 'general';
													?>
													<div class="cybevents-week-event-card <?php echo esc_attr( $slot['track_cls'] ); ?>" data-event-id="<?php echo esc_attr( 'event-' . $slot['event_id'] ); ?>" data-room="<?php echo esc_attr( $r_slug ); ?>" style="top:<?php echo esc_attr( (string) $top_px ); ?>px;height:<?php echo esc_attr( (string) $ht_px ); ?>px;">
														<strong><?php echo esc_html( $slot['title'] ); ?></strong><br>
														<small><?php echo esc_html( $time_t . ( '' !== $slot['room'] ? ' · ' . $slot['room'] : '' ) ); ?></small>
													</div>
												<?php endforeach; ?>
											<?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				</section>

				<!-- 3. DAY / SCHEDULE AGENDA VIEW -->
				<section class="cybevents-view-pane cybevents-view-day <?php echo ( 'day' === $default_view ) ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $uid ); ?>-view-day" role="tabpanel" aria-label="<?php esc_attr_e( 'Tagesagenda und Zeitstrahlansicht', 'wm-cyber-events-core' ); ?>">
					<div class="cybevents-day-timeline-wrap">
						<?php if ( empty( $agenda_by_day ) ) : ?>
							<p class="cybevents-empty-state"><?php esc_html_e( 'Aktuell sind keine geplanten Veranstaltungen vorhanden.', 'wm-cyber-events-core' ); ?></p>
						<?php else : ?>
							<?php $day_keys = array_keys( $agenda_by_day ); ?>
							<div class="cybevents-day-selector-bar">
								<?php
								foreach ( $day_keys as $idx => $ymd ) :
									$pill_ts    = strtotime( $ymd . ' 12:00:00 UTC' );
									$pill_label = $pill_ts ? date_i18n( 'l, d. F Y', $pill_ts ) : $ymd;
									$panel_id   = 'day-' . $ymd;
									?>
									<button type="button" class="cybevents-day-pill <?php echo 0 === $idx ? 'is-active' : ''; ?>" onclick="window.cybSwitchTimelineDay(this, '<?php echo esc_attr( $panel_id ); ?>')"><?php echo esc_html( $pill_label ); ?></button>
								<?php endforeach; ?>
							</div>

							<?php
							foreach ( $agenda_by_day as $ymd => $day_entries ) :
								$is_first = ( $ymd === $day_keys[0] );
								$panel_id = 'day-' . $ymd;
								?>
								<div class="cybevents-day-panel" data-day-panel="<?php echo esc_attr( $panel_id ); ?>"<?php echo $is_first ? '' : ' hidden'; ?>>
									<div class="cybevents-agenda-stream">
										<?php
										foreach ( $day_entries as $entry_idx => $entry ) :
											$s_ts     = strtotime( $entry['start_time'] . ' UTC' );
											$e_ts     = ! empty( $entry['end_time'] ) ? strtotime( $entry['end_time'] . ' UTC' ) : 0;
											$t_start  = $s_ts ? date_i18n( 'H:i', $s_ts ) : '';
											$t_end    = $e_ts ? date_i18n( 'H:i', $e_ts ) : '';
											$r_slug   = '' !== $entry['room'] ? self::room_slug( $entry['room'] ) : 'general';
											$initials = self::speaker_initials( $entry['speaker'] );
											?>
											<div class="cybevents-agenda-item <?php echo 0 === $entry_idx ? 'cybevents-agenda-item--featured' : ''; ?>" data-event-id="<?php echo esc_attr( 'event-' . $entry['event_id'] ); ?>" data-room="<?php echo esc_attr( $r_slug ); ?>">
												<div class="cybevents-agenda-time">
													<span class="cybevents-time-start"><?php echo esc_html( $t_start ); ?></span>
													<?php if ( '' !== $t_end ) : ?>
														<span class="cybevents-time-end"><?php echo esc_html( $t_end ); ?></span>
													<?php endif; ?>
												</div>
												<div class="cybevents-agenda-node"><span class="cybevents-node-dot"></span></div>
												<div class="cybevents-agenda-card">
													<?php if ( '' !== $entry['room'] || '' !== $entry['event_title'] ) : ?>
														<div class="cybevents-card-meta">
															<?php if ( '' !== $entry['room'] ) : ?>
																<span class="cybevents-badge-stage"><?php echo esc_html( $entry['room'] ); ?></span>
															<?php endif; ?>
															<?php if ( '' !== $entry['event_title'] && 'session' === $entry['type'] ) : ?>
																<span class="cybevents-badge-info"><?php echo esc_html( $entry['event_title'] ); ?></span>
															<?php endif; ?>
														</div>
													<?php endif; ?>
													<h3 class="cybevents-agenda-title"><?php echo esc_html( $entry['title'] ); ?></h3>
													<?php if ( '' !== $entry['description'] ) : ?>
														<p class="cybevents-agenda-desc"><?php echo esc_html( $entry['description'] ); ?></p>
													<?php endif; ?>
													<?php if ( '' !== $entry['speaker'] ) : ?>
														<div class="cybevents-speaker-chip">
															<div class="cybevents-speaker-avatar"><?php echo esc_html( $initials ); ?></div>
															<div class="cybevents-speaker-info"><strong><?php echo esc_html( $entry['speaker'] ); ?></strong></div>
														</div>
													<?php endif; ?>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</section>

				<!-- 4. LIST STREAM VIEW -->
				<section class="cybevents-view-pane cybevents-view-list <?php echo ( 'list' === $default_view ) ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $uid ); ?>-view-list" role="tabpanel" aria-label="<?php esc_attr_e( 'Chronologische Listenansicht', 'wm-cyber-events-core' ); ?>">
					<div class="cybevents-list-stream">
						<?php if ( empty( $events ) ) : ?>
							<p class="cybevents-empty-state"><?php esc_html_e( 'Keine Veranstaltungen gefunden.', 'wm-cyber-events-core' ); ?></p>
						<?php else : ?>
							<?php
							foreach ( $events as $ev ) :
								$start_ts       = strtotime( (string) $ev->start_date . ' UTC' );
								$date_formatted = $start_ts ? date_i18n( 'l, d. F Y', $start_ts ) : '';
								$time_formatted = $start_ts ? date_i18n( 'H:i', $start_ts ) . ' Uhr' : '';
								$price_val      = self::format_price( (float) $ev->ticket_price );
								$cal_url        = rest_url( 'cybevents/v1/calendar?event_id=' . (int) $ev->id );
								$description    = trim( (string) ( $ev->description ?? '' ) );
								?>
								<article class="cybevents-list-card" data-event-id="<?php echo esc_attr( 'event-' . (int) $ev->id ); ?>">
									<?php if ( $start_ts ) : ?>
										<div class="cybevents-list-date-block">
											<span class="cybevents-list-month"><?php echo esc_html( date_i18n( 'M', $start_ts ) ); ?></span>
											<span class="cybevents-list-day"><?php echo esc_html( date_i18n( 'd', $start_ts ) ); ?></span>
											<span class="cybevents-list-year"><?php echo esc_html( date_i18n( 'Y', $start_ts ) ); ?></span>
										</div>
									<?php endif; ?>
									<div class="cybevents-list-content">
										<div class="cybevents-list-meta-bar">
											<?php if ( '' !== $date_formatted ) : ?>
												<span class="cybevents-badge-pill cybevents-pill--date"><?php echo wp_kses( CyberEvents_Icons::svg( 'calendar', 14 ), $svg ); ?> <?php echo esc_html( $date_formatted . ' · ' . $time_formatted ); ?></span>
											<?php endif; ?>
											<?php if ( '' !== trim( (string) $ev->location ) ) : ?>
												<span class="cybevents-badge-pill cybevents-pill--location"><?php echo wp_kses( CyberEvents_Icons::svg( 'map-pin', 14 ), $svg ); ?> <?php echo esc_html( (string) $ev->location ); ?></span>
											<?php endif; ?>
											<span class="cybevents-badge-pill cybevents-pill--price"><?php echo wp_kses( CyberEvents_Icons::svg( 'ticket', 14 ), $svg ); ?> <?php echo esc_html( $price_val ); ?></span>
										</div>
										<h3 class="cybevents-list-title"><a href="#cybevents-registration-form"><?php echo esc_html( (string) $ev->title ); ?></a></h3>
										<?php if ( '' !== $description ) : ?>
											<p class="cybevents-list-excerpt"><?php echo esc_html( $description ); ?></p>
										<?php endif; ?>
									</div>
									<div class="cybevents-list-actions">
										<a href="<?php echo esc_url( $cal_url ); ?>" class="cybevents-btn-secondary" download="<?php echo esc_attr( (string) $ev->slug ); ?>.ics">
											<?php echo wp_kses( CyberEvents_Icons::svg( 'download', 14 ), $svg ); ?> <?php esc_html_e( '.ICS Kalender', 'wm-cyber-events-core' ); ?>
										</a>
										<a href="#cybevents-registration-form" class="cybevents-btn-primary">
											<?php esc_html_e( 'Jetzt anmelden', 'wm-cyber-events-core' ); ?> <?php echo wp_kses( CyberEvents_Icons::svg( 'arrow', 14 ), $svg ); ?>
										</a>
									</div>
								</article>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</section>

				<!-- 5. PHOTO / VISUAL CARD GRID VIEW -->
				<section class="cybevents-view-pane cybevents-view-photo <?php echo ( 'photo' === $default_view ) ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $uid ); ?>-view-photo" role="tabpanel" aria-label="<?php esc_attr_e( 'Visuelle Fotokarten-Ansicht', 'wm-cyber-events-core' ); ?>">
					<?php if ( empty( $sessions ) && empty( $events ) ) : ?>
						<p class="cybevents-empty-state"><?php esc_html_e( 'Keine Veranstaltungen gefunden.', 'wm-cyber-events-core' ); ?></p>
					<?php else : ?>
						<div class="cybevents-photo-grid">
							<?php
							if ( ! empty( $sessions ) ) :
								foreach ( $sessions as $idx => $s ) :
									$parent      = $events_by_id[ (int) $s->event_id ] ?? null;
									$cap         = (int) $s->kapazitaet;
									$free        = max( 0, $cap - (int) $s->belegt );
									$s_ts        = strtotime( (string) $s->start_time . ' UTC' );
									$card_date   = $s_ts ? strtoupper( date_i18n( 'd. F Y · H:i', $s_ts ) ) : '';
									$room_label  = trim( (string) ( $s->raum ?? '' ) );
									$r_slug      = '' !== $room_label ? self::room_slug( $room_label ) : 'general';
									$speaker     = trim( (string) ( $s->speaker ?? '' ) );
									$desc        = trim( (string) ( $s->description ?? '' ) );
									$price_label = $parent ? self::format_price( (float) $parent->ticket_price ) : '';
									?>
									<div class="cybevents-photo-card <?php echo 0 === $idx ? 'is-featured' : ''; ?>" data-event-id="<?php echo esc_attr( 'event-' . (int) $s->event_id ); ?>" data-room="<?php echo esc_attr( $r_slug ); ?>">
										<div class="cybevents-photo-card-top">
											<span class="cybevents-tag-badge"><?php echo esc_html( '' !== $room_label ? $room_label : (string) ( $s->event_title ?? __( 'Veranstaltung', 'wm-cyber-events-core' ) ) ); ?></span>
											<?php if ( $cap > 0 ) : ?>
												<span class="cybevents-seats-badge">
													<?php echo wp_kses( CyberEvents_Icons::svg( 'dot', 10 ), $svg ); ?>
													<?php
													if ( 0 === $free ) {
														esc_html_e( 'Ausgebucht', 'wm-cyber-events-core' );
													} else {
														/* translators: %d: Number of free seats */
														echo esc_html( sprintf( _n( '%d freier Platz', '%d freie Plätze', $free, 'wm-cyber-events-core' ), $free ) );
													}
													?>
												</span>
											<?php endif; ?>
										</div>
										<div class="cybevents-photo-card-body">
											<?php if ( '' !== $card_date ) : ?>
												<span class="cybevents-card-date"><?php echo esc_html( $card_date ); ?></span>
											<?php endif; ?>
											<h3 class="cybevents-card-heading"><?php echo esc_html( (string) $s->title ); ?></h3>
											<?php if ( '' !== $desc ) : ?>
												<p class="cybevents-card-text"><?php echo esc_html( $desc ); ?></p>
											<?php endif; ?>
											<?php if ( '' !== $speaker ) : ?>
												<div class="cybevents-card-speaker">
													<div class="cybevents-speaker-mini-avatar"><?php echo esc_html( self::speaker_initials( $speaker ) ); ?></div>
													<span><?php echo esc_html( $speaker ); ?></span>
												</div>
											<?php endif; ?>
										</div>
										<div class="cybevents-photo-card-footer">
											<span class="cybevents-price-tag"><?php echo esc_html( $price_label ); ?></span>
											<a href="#cybevents-registration-form" class="cybevents-card-btn"><?php esc_html_e( 'Jetzt anmelden', 'wm-cyber-events-core' ); ?> <?php echo wp_kses( CyberEvents_Icons::svg( 'arrow', 14 ), $svg ); ?></a>
										</div>
									</div>
									<?php
								endforeach;
							else :
								foreach ( $events as $idx => $ev ) :
									$ev_ts       = strtotime( (string) $ev->start_date . ' UTC' );
									$card_date   = $ev_ts ? strtoupper( date_i18n( 'd. F Y · H:i', $ev_ts ) ) : '';
									$loc_label   = trim( (string) ( $ev->location ?? '' ) );
									$desc        = trim( (string) ( $ev->description ?? '' ) );
									$price_label = self::format_price( (float) $ev->ticket_price );
									?>
									<div class="cybevents-photo-card <?php echo 0 === $idx ? 'is-featured' : ''; ?>" data-event-id="<?php echo esc_attr( 'event-' . (int) $ev->id ); ?>" data-room="general">
										<div class="cybevents-photo-card-top">
											<?php if ( '' !== $loc_label ) : ?>
												<span class="cybevents-tag-badge"><?php echo esc_html( $loc_label ); ?></span>
											<?php else : ?>
												<span class="cybevents-tag-badge"><?php esc_html_e( 'Veranstaltung', 'wm-cyber-events-core' ); ?></span>
											<?php endif; ?>
											<?php if ( (int) $ev->max_capacity > 0 ) : ?>
												<?php /* translators: %d: Maximum event capacity */ ?>
												<span class="cybevents-seats-badge"><?php echo wp_kses( CyberEvents_Icons::svg( 'users', 14 ), $svg ); ?> <?php echo esc_html( sprintf( __( 'von max. %d', 'wm-cyber-events-core' ), (int) $ev->max_capacity ) ); ?></span>
											<?php endif; ?>
										</div>
										<div class="cybevents-photo-card-body">
											<?php if ( '' !== $card_date ) : ?>
												<span class="cybevents-card-date"><?php echo esc_html( $card_date ); ?></span>
											<?php endif; ?>
											<h3 class="cybevents-card-heading"><?php echo esc_html( (string) $ev->title ); ?></h3>
											<?php if ( '' !== $desc ) : ?>
												<p class="cybevents-card-text"><?php echo esc_html( $desc ); ?></p>
											<?php endif; ?>
										</div>
										<div class="cybevents-photo-card-footer">
											<span class="cybevents-price-tag"><?php echo esc_html( $price_label ); ?></span>
											<a href="#cybevents-registration-form" class="cybevents-card-btn"><?php esc_html_e( 'Jetzt anmelden', 'wm-cyber-events-core' ); ?> <?php echo wp_kses( CyberEvents_Icons::svg( 'arrow', 14 ), $svg ); ?></a>
										</div>
									</div>
									<?php
								endforeach;
							endif;
							?>
						</div>
					<?php endif; ?>
				</section>

				<!-- 6. SPATIAL VENUE & ROOM NAVIGATOR -->
				<section class="cybevents-view-pane cybevents-view-map <?php echo ( 'map' === $default_view ) ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $uid ); ?>-view-map" role="tabpanel" aria-label="<?php esc_attr_e( 'Standort- und Raumnavigator-Ansicht', 'wm-cyber-events-core' ); ?>">
					<div class="cybevents-map-layout">
						<div class="cybevents-venue-infobox">
							<span class="cybevents-badge-tag"><?php echo wp_kses( CyberEvents_Icons::svg( 'map-pin', 12 ), $svg ); ?> <?php esc_html_e( 'Veranstaltungsort', 'wm-cyber-events-core' ); ?></span>
							<?php if ( ! empty( $locations ) ) : ?>
								<h3 class="cybevents-venue-title"><?php echo esc_html( $locations[0] ); ?></h3>
								<?php if ( count( $locations ) > 1 ) : ?>
									<p class="cybevents-venue-address"><?php echo esc_html( implode( ' · ', $locations ) ); ?></p>
								<?php endif; ?>
							<?php else : ?>
								<h3 class="cybevents-venue-title"><?php esc_html_e( 'Veranstaltungsorte', 'wm-cyber-events-core' ); ?></h3>
							<?php endif; ?>

							<h4 class="cybevents-venue-subheading"><?php esc_html_e( 'Räume & Ort', 'wm-cyber-events-core' ); ?></h4>
							<?php if ( empty( $sessions_by_room ) ) : ?>
								<p class="cybevents-empty-state"><?php esc_html_e( 'Für diese Veranstaltung sind keine Sessions hinterlegt.', 'wm-cyber-events-core' ); ?></p>
							<?php else : ?>
								<div class="cybevents-room-buttons">
									<?php
									$room_names = array_keys( $sessions_by_room );
									foreach ( $room_names as $r_idx => $room_name ) :
										$room_slug = self::room_slug( $room_name );
										?>
										<button type="button" class="cybevents-room-btn <?php echo 0 === $r_idx ? 'is-active' : ''; ?>" data-room-key="<?php echo esc_attr( $room_slug ); ?>" data-room-label="<?php echo esc_attr( $room_name ); ?>" onclick="window.cybSelectRoom(this, '<?php echo esc_attr( $room_slug ); ?>', '<?php echo esc_attr( $uid ); ?>')">
											<strong><?php echo wp_kses( CyberEvents_Icons::svg( 'building' ), $svg ); ?> <?php echo esc_html( $room_name ); ?></strong>
											(<?php echo esc_html( (string) count( $sessions_by_room[ $room_name ] ) ); ?>)
										</button>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>

						<div class="cybevents-room-preview-card" id="<?php echo esc_attr( $uid ); ?>-room-preview">
							<?php if ( empty( $sessions_by_room ) ) : ?>
								<div class="cybevents-room-badge" id="<?php echo esc_attr( $uid ); ?>-room-name"><?php esc_html_e( 'Räume & Ort', 'wm-cyber-events-core' ); ?></div>
								<div class="cybevents-room-schedule" id="<?php echo esc_attr( $uid ); ?>-room-schedule">
									<p class="cybevents-empty-state"><?php esc_html_e( 'Für diese Veranstaltung sind keine Sessions hinterlegt.', 'wm-cyber-events-core' ); ?></p>
								</div>
							<?php else : ?>
								<?php $room_names = array_keys( $sessions_by_room ); ?>
								<div class="cybevents-room-badge" id="<?php echo esc_attr( $uid ); ?>-room-name"><?php echo esc_html( $room_names[0] ); ?></div>
								<?php
								foreach ( $sessions_by_room as $room_name => $room_sessions ) :
									$room_slug = self::room_slug( $room_name );
									$is_first  = ( $room_name === $room_names[0] );
									?>
									<div class="cybevents-room-panel" data-room-panel="<?php echo esc_attr( $room_slug ); ?>"<?php echo $is_first ? '' : ' hidden'; ?>>
										<div class="cybevents-room-schedule">
											<?php
											foreach ( $room_sessions as $rs ) :
												$rs_ts   = strtotime( (string) $rs->start_time . ' UTC' );
												$rs_time = $rs_ts ? date_i18n( 'd.m. H:i', $rs_ts ) : '';
												$rs_spk  = trim( (string) ( $rs->speaker ?? '' ) );
												?>
												<div class="cybevents-room-session-row">
													<span class="cybevents-room-time"><?php echo esc_html( $rs_time ); ?></span>
													<div>
														<strong><?php echo esc_html( (string) $rs->title ); ?></strong>
														<?php if ( '' !== $rs_spk ) : ?>
															<br><small><?php echo esc_html( $rs_spk ); ?></small>
														<?php endif; ?>
													</div>
												</div>
											<?php endforeach; ?>
										</div>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>
					</div>
				</section>

				<!-- 7. MULTI-ROOM MATRIX VIEW -->
				<section class="cybevents-view-pane cybevents-view-tracks <?php echo ( 'tracks' === $default_view ) ? 'is-active' : ''; ?>" id="<?php echo esc_attr( $uid ); ?>-view-tracks" role="tabpanel" aria-label="<?php esc_attr_e( 'Summit Multi-Track Matrix', 'wm-cyber-events-core' ); ?>">
					<div class="cybevents-tracks-matrix-wrap">
						<?php if ( empty( $sessions_by_room ) ) : ?>
							<p class="cybevents-empty-state"><?php esc_html_e( 'Für diese Veranstaltung sind keine Sessions hinterlegt.', 'wm-cyber-events-core' ); ?></p>
						<?php else : ?>
							<?php $col_rooms = array_slice( $sessions_by_room, 0, 4, true ); ?>
							<div class="cybevents-tracks-header-grid">
								<?php
								$col_idx = 1;
								foreach ( $col_rooms as $room_name => $room_sessions ) :
									$head_mod = sprintf( 'cybevents-track-head--%02d', $col_idx );
									?>
									<div class="cybevents-track-head <?php echo esc_attr( $head_mod ); ?>">
										<span class="cybevents-th-num"><?php echo esc_html( sprintf( '%02d', $col_idx ) ); ?></span>
										<h4><?php echo esc_html( $room_name ); ?></h4>
										<small><?php echo esc_html( (string) count( $room_sessions ) ); ?></small>
									</div>
									<?php
									$col_idx++;
								endforeach;
								?>
							</div>

							<div class="cybevents-tracks-slots-grid">
								<?php
								foreach ( $col_rooms as $room_name => $room_sessions ) :
									$r_slug = self::room_slug( $room_name );
									?>
									<div>
										<?php
										foreach ( $room_sessions as $rs ) :
											$s_ts   = strtotime( (string) $rs->start_time . ' UTC' );
											$e_ts   = ! empty( $rs->end_time ) ? strtotime( (string) $rs->end_time . ' UTC' ) : 0;
											$t_lbl  = $s_ts ? date_i18n( 'd.m. H:i', $s_ts ) . ( $e_ts ? ' – ' . date_i18n( 'H:i', $e_ts ) : '' ) : '';
											$t_cls  = $style_class_map[ (int) $rs->event_id ] ?? 'cybevents-track--pqc';
											$desc   = trim( (string) ( $rs->description ?? '' ) );
											$spk    = trim( (string) ( $rs->speaker ?? '' ) );
											?>
											<div class="cybevents-track-slot-card <?php echo esc_attr( $t_cls ); ?>" data-event-id="<?php echo esc_attr( 'event-' . (int) $rs->event_id ); ?>" data-room="<?php echo esc_attr( $r_slug ); ?>">
												<div class="cybevents-slot-time"><?php echo esc_html( $t_lbl . ' · ' . $room_name ); ?></div>
												<h5><?php echo esc_html( (string) $rs->title ); ?></h5>
												<?php if ( '' !== $desc ) : ?>
													<p><?php echo esc_html( $desc ); ?></p>
												<?php endif; ?>
												<?php if ( '' !== $spk ) : ?>
													<span class="cybevents-slot-speaker"><?php echo esc_html( $spk ); ?></span>
												<?php endif; ?>
											</div>
										<?php endforeach; ?>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				</section>

			</div>

			<!-- ACCESSIBLE POPUP MODAL -->
			<div class="cybevents-modal-backdrop" id="<?php echo esc_attr( $uid ); ?>-modal" style="display:none;" onclick="window.cybCloseModal('<?php echo esc_attr( $uid ); ?>')">
				<div class="cybevents-modal-dialog" onclick="event.stopPropagation();" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $uid ); ?>-modal-title">
					<button type="button" class="cybevents-modal-close" onclick="window.cybCloseModal('<?php echo esc_attr( $uid ); ?>')" aria-label="<?php esc_attr_e( 'Dialog schließen', 'wm-cyber-events-core' ); ?>"><?php echo wp_kses( CyberEvents_Icons::svg( 'close' ), $svg ); ?></button>
					<div class="cybevents-modal-body">
						<span class="cybevents-badge-pill cybevents-pill--date" id="<?php echo esc_attr( $uid ); ?>-modal-time">-</span>
						<h3 class="cybevents-modal-heading" id="<?php echo esc_attr( $uid ); ?>-modal-title">-</h3>
						<div class="cybevents-modal-meta">
							<p><?php echo wp_kses( CyberEvents_Icons::svg( 'map-pin' ), $svg ); ?> <strong><?php esc_html_e( 'Veranstaltungsort:', 'wm-cyber-events-core' ); ?></strong> <span id="<?php echo esc_attr( $uid ); ?>-modal-room">-</span></p>
							<p><?php echo wp_kses( CyberEvents_Icons::svg( 'mic' ), $svg ); ?> <strong><?php esc_html_e( 'Referenten', 'wm-cyber-events-core' ); ?>:</strong> <span id="<?php echo esc_attr( $uid ); ?>-modal-speaker">-</span></p>
							<p><?php echo wp_kses( CyberEvents_Icons::svg( 'ticket' ), $svg ); ?> <strong><?php esc_html_e( 'Preis', 'wm-cyber-events-core' ); ?>:</strong> <span id="<?php echo esc_attr( $uid ); ?>-modal-price">-</span></p>
						</div>
						<div class="cybevents-modal-actions">
							<a href="#cybevents-registration-form" class="cybevents-btn-primary" id="<?php echo esc_attr( $uid ); ?>-modal-cta">
								<?php esc_html_e( 'Jetzt anmelden', 'wm-cyber-events-core' ); ?> <?php echo wp_kses( CyberEvents_Icons::svg( 'arrow', 14 ), $svg ); ?>
							</a>
						</div>
					</div>
				</div>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Resolve target YYYY-MM month from request, attributes, stored records or current date.
	 *
	 * @param array $attributes Render attributes.
	 * @param array $events     Loaded active event rows.
	 * @param array $sessions   Loaded session rows.
	 * @return string Year-month string formatted as YYYY-MM.
	 */
	private static function resolve_target_month( array $attributes, array $events, array $sessions ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public calendar navigation query arg.
		$query_month = isset( $_GET['cyb_month'] ) ? sanitize_text_field( wp_unslash( $_GET['cyb_month'] ) ) : '';
		if ( preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $query_month ) ) {
			return $query_month;
		}

		$attr_month = isset( $attributes['month'] ) ? trim( (string) $attributes['month'] ) : '';
		if ( preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $attr_month ) ) {
			return $attr_month;
		}

		foreach ( $events as $ev ) {
			$ev_date = (string) ( $ev->start_date ?? '' );
			if ( preg_match( '/^(\d{4})-(0[1-9]|1[0-2])/', $ev_date, $m ) ) {
				return $m[1] . '-' . $m[2];
			}
		}

		foreach ( $sessions as $s ) {
			$s_date = (string) ( $s->start_time ?? '' );
			if ( preg_match( '/^(\d{4})-(0[1-9]|1[0-2])/', $s_date, $m ) ) {
				return $m[1] . '-' . $m[2];
			}
		}

		if ( function_exists( 'wp_date' ) ) {
			return wp_date( 'Y-m' );
		}

		return gmdate( 'Y-m' );
	}

	/**
	 * Normalize a room name into a stable slug.
	 *
	 * @param string $room Raw room label.
	 * @return string Normalized room key.
	 */
	private static function room_slug( string $room ): string {
		$slug = sanitize_title( $room );
		return '' !== $slug ? $slug : 'general';
	}

	/**
	 * Format an event price cleanly.
	 *
	 * @param float $price Event ticket price.
	 * @return string Formatted price or free label.
	 */
	private static function format_price( float $price ): string {
		if ( $price > 0 ) {
			return number_format_i18n( $price, 2 ) . ' €';
		}
		return __( 'Kostenfrei', 'wm-cyber-events-core' );
	}

	/**
	 * Compute two-letter initials from a speaker string.
	 *
	 * @param string $speaker Speaker name.
	 * @return string Uppercase initials.
	 */
	private static function speaker_initials( string $speaker ): string {
		$parts    = preg_split( '/\s+/', trim( $speaker ) ) ?: array();
		$initials = '';
		foreach ( $parts as $part ) {
			if ( ! in_array( strtolower( $part ), array( 'dr.', 'prof.', 'von', 'und', 'de', 'dipl.-ing.' ), true ) ) {
				$initials .= mb_substr( $part, 0, 1 );
			}
		}
		$initials = mb_substr( $initials, 0, 2 );
		return strtoupper( '' !== $initials ? $initials : 'SP' );
	}
}
