<?php
/**
 * iCalendar (RFC 5545 vCalendar 2.0) Generator.
 *
 * Generates standards-compliant .ics calendar files for Microsoft Outlook,
 * Apple Calendar, Google Calendar, and mobile calendar apps.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Calendar_Exporter {

	/**
	 * Unix timestamp of a stored event or session time. The times are stored as wall-clock times of the
	 * site's time zone; until 2026-09-15 they were read as UTC, so calendar entries moved by the
	 * site's offset (two hours in a German summer).
	 *
	 * @param string $datetime_str MySQL datetime string in the site's time zone.
	 * @return int
	 */
	public static function site_time_to_timestamp( $datetime_str ) {
		$dt = date_create_immutable( (string) $datetime_str, wp_timezone() );
		return $dt ? $dt->getTimestamp() : (int) strtotime( (string) $datetime_str );
	}

	/**
	 * Format a stored site-time datetime as iCal UTC (YYYYMMDDTHHMMSSZ).
	 *
	 * @param string $datetime_str MySQL datetime string in the site's time zone.
	 * @return string Formatted iCal timestamp.
	 */
	public static function format_ical_date( $datetime_str ) {
		return gmdate( 'Ymd\THis\Z', self::site_time_to_timestamp( $datetime_str ) );
	}

	/**
	 * Escape string for iCalendar property values according to RFC 5545.
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	public static function escape_ical_text( $text ) {
		$text = str_replace( '\\', '\\\\', (string) $text );
		$text = str_replace( ';', '\;', $text );
		$text = str_replace( ',', '\,', $text );
		$text = str_replace( array( "\r\n", "\n", "\r" ), '\n', $text );
		return $text;
	}

	/**
	 * Fold an iCalendar line so that no physical line exceeds 75 octets (RFC 5545 Section 3.1).
	 *
	 * Uses mb_strcut to prevent truncating multibyte UTF-8 code points.
	 *
	 * @param string $line Logical iCalendar property line.
	 * @return string Folded iCalendar line with CRLF and leading whitespace.
	 */
	public static function fold_line( $line ) {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}

		$folded = '';
		$first  = true;

		while ( strlen( $line ) > 0 ) {
			$limit = $first ? 75 : 74; // Continuation lines begin with a single space (1 byte)
			$chunk = mb_strcut( $line, 0, $limit, 'UTF-8' );
			$line  = substr( $line, strlen( $chunk ) );

			if ( $first ) {
				$folded .= $chunk;
				$first   = false;
			} else {
				$folded .= "\r\n " . $chunk;
			}
		}

		return $folded;
	}

	/**
	 * Generate .ics calendar feed for an event and its booked sessions.
	 *
	 * @param object $event Event DB row.
	 * @param array  $sessions Array of session DB rows.
	 * @param string $attendee_name Attendee name if personalized.
	 * @return string RFC 5545 .ics document string.
	 */
	public static function generate_ics( $event, $sessions = array(), $attendee_name = '' ) {
		$now_utc     = gmdate( 'Ymd\THis\Z' );
		$site_domain = wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost';

		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//WM Suite//WM Cyber Events Core ' . CYBEREVENTS_VERSION . '//DE',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::escape_ical_text( $event->title ),
		);
		// Only a named zone is a valid hint; the times themselves are UTC.
		$timezone = wp_timezone_string();
		if ( false !== strpos( $timezone, '/' ) ) {
			$lines[] = 'X-WR-TIMEZONE:' . $timezone;
		}

		// 1. Main Conference Master Entry
		$event_uid   = 'event-' . $event->id . '-' . md5( $event->created_at ?? $now_utc ) . '@' . $site_domain;
		$event_start = self::format_ical_date( $event->start_date );
		$event_end   = self::format_ical_date( $event->end_date );
		// Raw location, escaped where it is written (the session line escaped it a second time until
		// 2026-09-15). No invented fallback: an event without a location gets no LOCATION.
		$location    = trim( (string) $event->location );
		$description = self::escape_ical_text( wp_strip_all_tags( $event->description ?: $event->title ) );

		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $event_uid;
		$lines[] = 'DTSTAMP:' . $now_utc;
		$lines[] = 'DTSTART:' . $event_start;
		$lines[] = 'DTEND:' . $event_end;
		$lines[] = 'SUMMARY:' . self::escape_ical_text( $event->title );
		if ( '' !== $location ) {
			$lines[] = 'LOCATION:' . self::escape_ical_text( $location );
		}
		$lines[] = 'DESCRIPTION:' . $description;
		$lines[] = 'STATUS:CONFIRMED';

		// 24h Reminder Alarm
		$lines[] = 'BEGIN:VALARM';
		$lines[] = 'TRIGGER:-P1D';
		$lines[] = 'ACTION:DISPLAY';
		$lines[] = 'DESCRIPTION:Morgen Konferenz: ' . self::escape_ical_text( $event->title );
		$lines[] = 'END:VALARM';
		$lines[] = 'END:VEVENT';

		// 2. Individual Sub-Sessions (if attendee has booked specific workshops)
		if ( ! empty( $sessions ) ) {
			foreach ( $sessions as $sess ) {
				$sess_uid   = 'session-' . $sess->id . '-' . md5( $sess->created_at ?? $now_utc ) . '@' . $site_domain;
				$sess_start = self::format_ical_date( $sess->start_time );
				$sess_end   = self::format_ical_date( $sess->end_time );
				$sess_loc   = implode( ' · ', array_filter( array( trim( (string) $sess->raum ), $location ), 'strlen' ) );

				// A real line break, escaped once below; until 2026-09-15 the text carried a literal
				// "\n" and a client's name when no speaker was set.
				$sess_desc = wp_strip_all_tags( $sess->description ?: $sess->title );
				if ( '' !== trim( (string) $sess->speaker ) ) {
					$sess_desc = 'Referent: ' . $sess->speaker . "\n\n" . $sess_desc;
				}

				$lines[] = 'BEGIN:VEVENT';
				$lines[] = 'UID:' . $sess_uid;
				$lines[] = 'DTSTAMP:' . $now_utc;
				$lines[] = 'DTSTART:' . $sess_start;
				$lines[] = 'DTEND:' . $sess_end;
				$lines[] = 'SUMMARY:' . self::escape_ical_text( '[Session] ' . $sess->title );
				if ( '' !== $sess_loc ) {
					$lines[] = 'LOCATION:' . self::escape_ical_text( $sess_loc );
				}
				$lines[] = 'DESCRIPTION:' . self::escape_ical_text( $sess_desc );
				$lines[] = 'STATUS:CONFIRMED';

				// 15-Minute Workshop Reminder Alarm
				$lines[] = 'BEGIN:VALARM';
				$lines[] = 'TRIGGER:-PT15M';
				$lines[] = 'ACTION:DISPLAY';
				$lines[] = 'DESCRIPTION:Session beginnt in 15 Minuten: ' . self::escape_ical_text( $sess->title );
				$lines[] = 'END:VALARM';
				$lines[] = 'END:VEVENT';
			}
		}

		$lines[] = 'END:VCALENDAR';

		$folded_lines = array_map( array( __CLASS__, 'fold_line' ), $lines );
		return implode( "\r\n", $folded_lines ) . "\r\n";
	}
}
