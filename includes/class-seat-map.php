<?php
/**
 * Seat map: the capacity of every session of an event drawn as an SVG, kept current over SSE.
 *
 * @package    CyberEvents
 * @subpackage CyberEvents/includes
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One cell per seat, one row per session, and a stream that says when a cell changed colour.
 *
 * The plugin stores capacity, not furniture: a session has `kapazitaet` and `belegt`, and no table
 * knows seat 14 in row F. So this draws what exists — a seat is a cell, a session is a row of cells,
 * a filled cell is a booked seat — and does not invent numbered chairs the booking code could not
 * honour. Clicking a row books or releases a seat through the same `POST /book-sessions` the session
 * selector uses, so the conditional UPDATE still decides who gets the last one.
 *
 * The specification asks for "WebSocket / Server-Sent Events". WordPress has no socket server, so it
 * is SSE: `GET /seat-stream` holds the request open and writes an event whenever the occupancy digest
 * changes. That costs one PHP process per connected viewer for as long as the stream lives, which is
 * why it lives for SSE_MAX_SECONDS and then closes with a `retry` the browser obeys. The stream
 * carries occupancy numbers only — no names, no tokens, nothing personal — which is why it may be
 * read without one.
 */
class CyberEvents_Seat_Map {

	/** Edge of one seat cell, in SVG user units. */
	const CELL = 18;

	/** Gap between cells. */
	const GAP = 4;

	/** Seats drawn per line before the row wraps. */
	const PER_LINE = 20;

	/** Space above each session row for its label. */
	const LABEL_HEIGHT = 26;

	/** Space between two session rows. */
	const ROW_GAP = 18;

	/** Width of the drawing. */
	const WIDTH = self::PER_LINE * ( self::CELL + self::GAP ) + 20;

	/** Seats above which a session is drawn as a bar with a number instead of one cell per seat. */
	const MAX_CELLS = 400;

	/** How long one SSE connection is held open before the browser is asked to reconnect. */
	const SSE_MAX_SECONDS = 30;

	/** Seconds between two occupancy reads inside a stream. */
	const SSE_TICK_SECONDS = 2;

	/** Seconds after which a stream sends a comment even though nothing changed, to keep proxies from closing it. */
	const SSE_HEARTBEAT_SECONDS = 10;

	/** Object cache group for short-lived occupancy snapshots. */
	const SNAPSHOT_CACHE_GROUP = 'cybevents_seats';

	/** TTL in seconds for cached occupancy snapshots. */
	const SNAPSHOT_CACHE_TTL = 3;

	/**
	 * Short-lived (3s) cached occupancy snapshot for ETag polling.
	 *
	 * Uses the object cache (`cybevents_seats` group) and falls back to a 3-second transient when
	 * no external persistent object cache is active.
	 *
	 * @param int $event_id Event id.
	 * @return array{event_id:int, sessions:array<int, array<string, mixed>>, digest:string, generated_at:string}
	 */
	public static function cached_snapshot( $event_id ): array {
		$event_id  = absint( $event_id );
		$cache_key = 'cybevents_seat_snap_' . $event_id;
		$found     = false;
		$cached    = wp_cache_get( $cache_key, self::SNAPSHOT_CACHE_GROUP, false, $found );

		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			$transient = get_transient( $cache_key );
			if ( is_array( $transient ) ) {
				wp_cache_set( $cache_key, $transient, self::SNAPSHOT_CACHE_GROUP, self::SNAPSHOT_CACHE_TTL );
				return $transient;
			}
		}

		$snapshot = self::snapshot( $event_id );
		wp_cache_set( $cache_key, $snapshot, self::SNAPSHOT_CACHE_GROUP, self::SNAPSHOT_CACHE_TTL );

		if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			set_transient( $cache_key, $snapshot, self::SNAPSHOT_CACHE_TTL );
		}

		return $snapshot;
	}

	/**
	 * Invalidate the cached occupancy snapshot for an event after bookings or cancellations.
	 *
	 * @param int $event_id Event id.
	 * @return void
	 */
	public static function invalidate_cache( $event_id ): void {
		$event_id  = absint( $event_id );
		$cache_key = 'cybevents_seat_snap_' . $event_id;

		wp_cache_delete( $cache_key, self::SNAPSHOT_CACHE_GROUP );
		delete_transient( $cache_key );
	}

	/**
	 * Occupancy of every session of an event, plus a digest that changes when any of it changes.
	 *
	 * @param int $event_id Event id.
	 * @return array{event_id:int, sessions:array<int, array<string, mixed>>, digest:string, generated_at:string}
	 */
	public static function snapshot( $event_id ) {
		$event_id = absint( $event_id );
		$sessions = array();

		foreach ( (array) CyberEvents_Database::get_sessions_by_event( $event_id ) as $session ) {
			$capacity = max( 0, (int) $session->kapazitaet );
			$taken    = max( 0, min( (int) $session->belegt, $capacity ) );

			$sessions[] = array(
				'id'         => (int) $session->id,
				'title'      => (string) $session->title,
				'room'       => (string) ( $session->raum ?? '' ),
				'start_time' => (string) ( $session->start_time ?? '' ),
				'capacity'   => $capacity,
				'taken'      => $taken,
				'free'       => max( 0, $capacity - $taken ),
			);
		}

		return array(
			'event_id'     => $event_id,
			'sessions'     => $sessions,
			// Only the numbers go into the digest: a renamed session must not look like a booking.
			'digest'       => hash( 'sha256', wp_json_encode( array_map( static function ( $s ) {
				return array( $s['id'], $s['capacity'], $s['taken'] );
			}, $sessions ) ) ),
			'generated_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Sessions the participant of this token has booked.
	 *
	 * @param string $raw_token Participant token.
	 * @return array<int, int>
	 */
	public static function booked_session_ids( $raw_token ) {
		global $wpdb;

		if ( '' === (string) $raw_token ) {
			return array();
		}

		$reg = CyberEvents_Database::get_registration_by_token( (string) $raw_token );
		if ( ! $reg || 'confirmed' !== $reg->status ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT session_id FROM {$wpdb->prefix}cybevents_registration_sessions WHERE registration_id = %d", (int) $reg->id ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Whether this token belongs to a confirmed registration, i.e. whether the viewer may book at all.
	 *
	 * @param string $raw_token Participant token.
	 * @return bool
	 */
	public static function may_book( $raw_token ) {
		if ( '' === (string) $raw_token ) {
			return false;
		}

		$reg = CyberEvents_Database::get_registration_by_token( (string) $raw_token );

		return (bool) $reg && 'confirmed' === $reg->status;
	}

	/**
	 * Height the drawing needs for one session.
	 *
	 * @param array $session Session from snapshot().
	 * @return int
	 */
	public static function row_height( array $session ) {
		$lines = self::lines_for( (int) $session['capacity'] );

		return self::LABEL_HEIGHT + $lines * ( self::CELL + self::GAP ) + self::ROW_GAP;
	}

	/**
	 * Lines of cells a capacity needs; a capacity above MAX_CELLS is drawn as a single bar.
	 *
	 * @param int $capacity Seats.
	 * @return int
	 */
	public static function lines_for( $capacity ) {
		$capacity = max( 0, (int) $capacity );

		if ( 0 === $capacity ) {
			return 1;
		}

		if ( $capacity > self::MAX_CELLS ) {
			return 1;
		}

		return (int) ceil( $capacity / self::PER_LINE );
	}

	/**
	 * The map as SVG.
	 *
	 * @param int    $event_id  Event id.
	 * @param string $raw_token Participant token, for marking the seats this person holds.
	 * @return string
	 */
	public static function render_svg( $event_id, $raw_token = '' ) {
		$snapshot = self::snapshot( $event_id );
		$booked   = self::booked_session_ids( $raw_token );

		if ( array() === $snapshot['sessions'] ) {
			return '<p class="cybevents-notice cybevents-notice--warning">' . esc_html__( 'Für diese Veranstaltung sind keine Sessions hinterlegt.', 'wm-cyber-events-core' ) . '</p>';
		}

		$height = 10;
		foreach ( $snapshot['sessions'] as $session ) {
			$height += self::row_height( $session );
		}

		$svg  = sprintf(
			'<svg class="cybevents-seatmap-svg" viewBox="0 0 %1$d %2$d" width="100%%" height="%2$d" role="img" aria-label="%3$s" xmlns="http://www.w3.org/2000/svg">',
			self::WIDTH,
			$height,
			esc_attr__( 'Sitzplan: belegte und freie Plätze je Session', 'wm-cyber-events-core' )
		);

		$y = 10;
		foreach ( $snapshot['sessions'] as $session ) {
			$svg .= self::render_row( $session, $y, in_array( (int) $session['id'], $booked, true ) );
			$y   += self::row_height( $session );
		}

		$svg .= '</svg>';

		return $svg;
	}

	/**
	 * One session: its label and its seats.
	 *
	 * @param array $session   Session from snapshot().
	 * @param int   $y         Top of this row.
	 * @param bool  $is_booked Whether the viewer holds a seat in it.
	 * @return string
	 */
	private static function render_row( array $session, $y, $is_booked ) {
		$capacity = (int) $session['capacity'];
		$taken    = (int) $session['taken'];

		$label = sprintf(
			/* translators: 1: session title, 2: seats taken, 3: capacity */
			__( '%1$s — %2$d von %3$d Plätzen belegt', 'wm-cyber-events-core' ),
			$session['title'],
			$taken,
			$capacity
		);

		$row  = sprintf(
			'<g class="cybevents-seatmap-row%1$s" data-session-id="%2$d" data-capacity="%3$d" data-taken="%4$d" role="group" aria-label="%5$s" tabindex="0">',
			$is_booked ? ' is-booked' : '',
			(int) $session['id'],
			$capacity,
			$taken,
			esc_attr( $label )
		);

		$row .= sprintf(
			'<text class="cybevents-seatmap-label" x="4" y="%d">%s</text>',
			$y + 16,
			esc_html( $label )
		);

		$top = $y + self::LABEL_HEIGHT;

		if ( $capacity > self::MAX_CELLS || 0 === $capacity ) {
			// One bar with the number: four hundred rectangles are a drawing nobody reads, and zero
			// seats have nothing to draw at all.
			$filled = $capacity > 0 ? ( self::WIDTH - 24 ) * ( $taken / $capacity ) : 0;
			$row   .= sprintf( '<rect class="cybevents-seatmap-bar-track" x="4" y="%d" width="%d" height="%d" rx="4" />', $top, self::WIDTH - 24, self::CELL );
			$row   .= sprintf( '<rect class="cybevents-seatmap-bar-fill" x="4" y="%d" width="%d" height="%d" rx="4" />', $top, (int) round( $filled ), self::CELL );
		} else {
			for ( $seat = 0; $seat < $capacity; $seat++ ) {
				$column = $seat % self::PER_LINE;
				$line   = intdiv( $seat, self::PER_LINE );
				$row   .= sprintf(
					'<rect class="cybevents-seatmap-seat %1$s" x="%2$d" y="%3$d" width="%4$d" height="%4$d" rx="3" data-seat="%5$d" />',
					$seat < $taken ? 'is-taken' : 'is-free',
					4 + $column * ( self::CELL + self::GAP ),
					$top + $line * ( self::CELL + self::GAP ),
					self::CELL,
					$seat + 1
				);
			}
		}

		return $row . '</g>';
	}

	/**
	 * The whole widget: the drawing, a legend, and the attributes the script needs.
	 *
	 * @param int    $event_id  Event id.
	 * @param string $raw_token Participant token, or ''.
	 * @param array  $args      heading, description, live (bool).
	 * @return string
	 */
	public static function render( $event_id, $raw_token = '', array $args = array() ) {
		$event_id = absint( $event_id );
		if ( ! $event_id ) {
			return '<p class="cybevents-notice cybevents-notice--warning">' . esc_html__( 'Für den Sitzplan fehlt die Veranstaltung.', 'wm-cyber-events-core' ) . '</p>';
		}

		$event = CyberEvents_Database::get_event( $event_id );
		if ( ! $event ) {
			return '<p class="cybevents-notice cybevents-notice--warning">' . esc_html__( 'Die Veranstaltung des Sitzplans existiert nicht.', 'wm-cyber-events-core' ) . '</p>';
		}

		$snapshot  = self::snapshot( $event_id );
		$live      = ! isset( $args['live'] ) || (bool) $args['live'];
		$heading   = '' !== (string) ( $args['heading'] ?? '' ) ? (string) $args['heading'] : (string) $event->title;
		$transport = (string) apply_filters( 'cybevents_seatmap_transport', 'etag-poll', $event_id );

		// The token is NOT written into the markup. It already travels in the URL, which is where the
		// script reads it; a copy in an attribute would sit in whatever cache holds the page, and this
		// plugin already had to fix exactly that on the ticket route. What the markup says is only
		// whether this viewer may act at all.
		$html  = sprintf(
			'<section class="cybevents-seatmap" data-event-id="%d" data-stream="%s" data-snapshot="%s" data-transport="%s" data-digest="%s" data-live="%s" data-can-book="%s">',
			$event_id,
			esc_attr( rest_url( 'cybevents/v1/seat-stream' ) ),
			esc_attr( rest_url( 'cybevents/v1/seat-snapshot' ) ),
			esc_attr( $transport ),
			esc_attr( $snapshot['digest'] ),
			$live ? '1' : '0',
			array() !== self::booked_session_ids( $raw_token ) || ( '' !== (string) $raw_token && self::may_book( $raw_token ) ) ? '1' : '0'
		);

		$html .= '<h3 class="cybevents-seatmap-heading">' . esc_html( $heading ) . '</h3>';

		if ( '' !== (string) ( $args['description'] ?? '' ) ) {
			$html .= '<p class="cybevents-seatmap-description">' . esc_html( (string) $args['description'] ) . '</p>';
		}

		$html .= self::render_svg( $event_id, $raw_token );

		$html .= '<ul class="cybevents-seatmap-legend">';
		$html .= '<li><span class="cybevents-seatmap-key is-free" aria-hidden="true"></span>' . esc_html__( 'frei', 'wm-cyber-events-core' ) . '</li>';
		$html .= '<li><span class="cybevents-seatmap-key is-taken" aria-hidden="true"></span>' . esc_html__( 'belegt', 'wm-cyber-events-core' ) . '</li>';
		$html .= '<li><span class="cybevents-seatmap-key is-booked" aria-hidden="true"></span>' . esc_html__( 'von Ihnen gebucht', 'wm-cyber-events-core' ) . '</li>';
		$html .= '</ul>';

		// The numbers are also there as text, because a screen reader gets nothing from the rectangles.
		$html .= '<table class="cybevents-seatmap-table"><caption>' . esc_html__( 'Belegung je Session', 'wm-cyber-events-core' ) . '</caption><thead><tr>'
			. '<th scope="col">' . esc_html__( 'Session', 'wm-cyber-events-core' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Belegt', 'wm-cyber-events-core' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Frei', 'wm-cyber-events-core' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $snapshot['sessions'] as $session ) {
			$html .= sprintf(
				'<tr data-session-row="%1$d"><th scope="row">%2$s</th><td data-cell="taken">%3$d</td><td data-cell="free">%4$d</td></tr>',
				(int) $session['id'],
				esc_html( $session['title'] ),
				(int) $session['taken'],
				(int) $session['free']
			);
		}

		$html .= '</tbody></table>';

		$html .= '<p class="cybevents-seatmap-status" role="status" aria-live="polite" data-idle="' . esc_attr__( 'Belegung wird laufend aktualisiert.', 'wm-cyber-events-core' ) . '" data-static="' . esc_attr__( 'Stand bei Seitenaufruf; die Belegung wird nicht laufend aktualisiert.', 'wm-cyber-events-core' ) . '"></p>';

		return $html . '</section>';
	}

	/**
	 * Stream the occupancy of an event as Server-Sent Events until SSE_MAX_SECONDS are up.
	 *
	 * Writes directly and exits: a REST response object cannot hold a connection open. One connected
	 * viewer occupies one PHP process for the life of the stream, which is why the life is short and
	 * the browser is told to come back.
	 *
	 * @param int $event_id Event id.
	 * @return void
	 */
	public static function stream( $event_id ) {
		$event_id = absint( $event_id );

		// Headers a proxy needs to leave the stream alone.
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		// How long the browser waits before reconnecting after this stream closes.
		echo 'retry: ' . (int) ( self::SSE_TICK_SECONDS * 1000 ) . "\n\n";

		$started      = time();
		$last_digest  = '';
		$last_written = 0;

		while ( time() - $started < self::SSE_MAX_SECONDS ) {
			if ( connection_aborted() ) {
				break;
			}

			$snapshot = self::snapshot( $event_id );

			if ( $snapshot['digest'] !== $last_digest ) {
				echo "event: seats\n";
				echo 'data: ' . wp_json_encode( $snapshot ) . "\n\n";
				$last_digest  = $snapshot['digest'];
				$last_written = time();
			} elseif ( time() - $last_written >= self::SSE_HEARTBEAT_SECONDS ) {
				// A comment line keeps proxies from closing an idle connection; it is not an event.
				echo ": keep-alive\n\n";
				$last_written = time();
			}

			flush();
			sleep( self::SSE_TICK_SECONDS );
		}

		exit;
	}
}
