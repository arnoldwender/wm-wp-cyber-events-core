<?php
/**
 * Admission waiting room: a virtual queue in front of the seat booking.
 *
 * @package    CyberEvents
 * @subpackage CyberEvents/includes
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts admissions per minute and turns the ones over the limit away before they reach MySQL.
 *
 * A ticket drop is the moment this plugin is least able to defend itself: every request walks the
 * session table, takes a row lock and writes a row. The seat counter itself cannot be oversold — the
 * conditional UPDATE in CyberEvents_Database::book_session_atomic() settles that — but ten thousand
 * requests for the last fifty seats still cost ten thousand round trips to the database, and the ones
 * that arrive after the seats are gone cost exactly as much as the ones that get them.
 *
 * So this sits in front. It counts requests per gate and per event in a one-minute window and answers
 * everything over the limit with 503 and a Retry-After, before a single query runs. What it does not
 * do: hold a place in line. A request turned away is turned away, and the answer says when to come
 * back; it does not promise the caller a seat at that time. Calling that a queue would be a promise
 * the counter cannot keep.
 *
 * Where the counter lives depends on the site. With a persistent object cache (Redis, Memcached) it
 * is an atomic increment in that cache and the refused request touches no database at all. Without
 * one it is a transient, which is a row in the options table: the heavy work is still skipped, but
 * the counter itself costs a read and a write. backend() says which of the two is in use and the
 * settings screen shows it, because the difference is the whole point of the feature.
 */
class CyberEvents_Waiting_Room {

	/** Object-cache group of the per-window counters. */
	const CACHE_GROUP = 'cybevents_admission';

	/** Length of one counting window in seconds. */
	const WINDOW_SECONDS = 60;

	/** Settings key holding the admissions allowed per window. 0 switches the waiting room off. */
	const SETTING = 'admission_per_minute';

	/** Highest limit the settings screen accepts; above this the counter is not what is protecting you. */
	const MAX_LIMIT = 100000;

	/** Gates that count separately, so a registration burst cannot lock out seat booking. */
	const GATE_BOOK     = 'book';
	const GATE_REGISTER = 'register';

	/**
	 * Admissions allowed per window, from the settings. 0 means off.
	 *
	 * @return int
	 */
	public static function limit() {
		$options = get_option( 'cybevents_settings', array() );
		$limit   = isset( $options[ self::SETTING ] ) ? (int) $options[ self::SETTING ] : 0;

		if ( $limit < 0 ) {
			return 0;
		}

		return min( $limit, self::MAX_LIMIT );
	}

	/**
	 * Whether any request is counted at all.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return self::limit() > 0;
	}

	/**
	 * Where the counters live: 'object-cache' with a persistent cache, 'database' otherwise.
	 *
	 * WordPress only reports a real backend through wp_using_ext_object_cache(); the default cache is
	 * per-request and would reset the counter on every request, which is the same as no waiting room.
	 *
	 * @return string
	 */
	public static function backend() {
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_incr' ) ) {
			return 'object-cache';
		}

		return 'database';
	}

	/**
	 * Current counting window, as a whole number of windows since the epoch.
	 *
	 * @return int
	 */
	public static function window() {
		return (int) floor( current_datetime()->getTimestamp() / self::WINDOW_SECONDS );
	}

	/**
	 * Seconds left in the current window: what a refused caller is told to wait.
	 *
	 * @return int
	 */
	public static function seconds_to_next_window() {
		$elapsed = current_datetime()->getTimestamp() % self::WINDOW_SECONDS;

		return max( 1, self::WINDOW_SECONDS - $elapsed );
	}

	/**
	 * Key of one counter.
	 *
	 * @param string $gate     Gate identifier.
	 * @param int    $event_id Event the request is for, 0 for requests that name none.
	 * @param int    $window   Window number.
	 * @return string
	 */
	public static function counter_key( $gate, $event_id, $window ) {
		return 'wm' . sanitize_key( (string) $gate ) . '_' . absint( $event_id ) . '_' . (int) $window;
	}

	/**
	 * Count this request and say whether it may go on.
	 *
	 * @param string $gate     Gate identifier (GATE_BOOK, GATE_REGISTER).
	 * @param int    $event_id Event the request is for; 0 counts against the gate's shared bucket.
	 * @return array{admitted:bool, count:int, limit:int, retry_after:int, backend:string, gate:string, event_id:int}
	 */
	public static function admit( $gate, $event_id = 0 ) {
		$limit   = self::limit();
		$backend = self::backend();
		$verdict = array(
			'admitted'    => true,
			'count'       => 0,
			'limit'       => $limit,
			'retry_after' => 0,
			'backend'     => $backend,
			'gate'        => (string) $gate,
			'event_id'    => absint( $event_id ),
		);

		if ( $limit <= 0 ) {
			// Off: no counter is written at all, so a site that never switched it on pays nothing.
			return $verdict;
		}

		$count = self::increment( $gate, $event_id, $backend );

		$verdict['count'] = $count;

		if ( $count > $limit ) {
			$verdict['admitted']    = false;
			$verdict['retry_after'] = self::seconds_to_next_window();
		}

		return $verdict;
	}

	/**
	 * Add one to the counter of the current window and return the new value.
	 *
	 * @param string $gate     Gate identifier.
	 * @param int    $event_id Event id.
	 * @param string $backend  Backend from backend().
	 * @return int
	 */
	private static function increment( $gate, $event_id, $backend ) {
		$window = self::window();
		$key    = self::counter_key( $gate, $event_id, $window );

		if ( 'object-cache' === $backend ) {
			// add() only writes when the key is absent, so two requests racing on the first hit of a
			// window cannot both reset it to zero. incr() is atomic in every persistent backend.
			wp_cache_add( $key, 0, self::CACHE_GROUP, self::WINDOW_SECONDS * 2 );
			$count = wp_cache_incr( $key, 1, self::CACHE_GROUP );

			// A backend that answers false (key evicted between add and incr) must not read as
			// "nobody has arrived yet": count this request as the first one of a new window.
			return false === $count ? 1 : (int) $count;
		}

		$transient = 'cybevents_adm_' . md5( $key );
		$count     = (int) get_transient( $transient ) + 1;
		set_transient( $transient, $count, self::WINDOW_SECONDS * 2 );

		return $count;
	}

	/**
	 * How many admissions the current window has seen, without counting this call.
	 *
	 * @param string $gate     Gate identifier.
	 * @param int    $event_id Event id.
	 * @return int
	 */
	public static function depth( $gate, $event_id = 0 ) {
		$key = self::counter_key( $gate, $event_id, self::window() );

		if ( 'object-cache' === self::backend() ) {
			return (int) wp_cache_get( $key, self::CACHE_GROUP );
		}

		return (int) get_transient( 'cybevents_adm_' . md5( $key ) );
	}

	/**
	 * Drop the counter of the current window (admin action, tests).
	 *
	 * @param string $gate     Gate identifier.
	 * @param int    $event_id Event id.
	 * @return void
	 */
	public static function reset( $gate, $event_id = 0 ) {
		$key = self::counter_key( $gate, $event_id, self::window() );

		if ( 'object-cache' === self::backend() ) {
			wp_cache_delete( $key, self::CACHE_GROUP );
			return;
		}

		delete_transient( 'cybevents_adm_' . md5( $key ) );
	}

	/**
	 * The answer a refused request gets: 503 with Retry-After, and no promise of a seat.
	 *
	 * @param array $verdict Verdict from admit().
	 * @return WP_REST_Response
	 */
	public static function response( array $verdict ) {
		$retry_after = max( 1, (int) ( $verdict['retry_after'] ?? self::WINDOW_SECONDS ) );

		$response = new WP_REST_Response(
			array(
				'success'     => false,
				'waiting'     => true,
				'message'     => sprintf(
					/* translators: %d: seconds to wait before trying again */
					__( 'Sehr viele Anfragen gleichzeitig. Bitte versuchen Sie es in %d Sekunden erneut. Ein Platz ist damit nicht reserviert.', 'wm-cyber-events-core' ),
					$retry_after
				),
				'retry_after' => $retry_after,
			),
			503
		);

		$response->header( 'Retry-After', (string) $retry_after );

		return $response;
	}

	/**
	 * What the settings screen and the hub report about the waiting room.
	 *
	 * @return array{enabled:bool, limit:int, backend:string, window_seconds:int, database_backend_warning:string}
	 */
	public static function status() {
		$backend = self::backend();

		return array(
			'enabled'                  => self::is_enabled(),
			'limit'                    => self::limit(),
			'backend'                  => $backend,
			'window_seconds'           => self::WINDOW_SECONDS,
			'database_backend_warning' => 'database' === $backend
				? __( 'Ohne persistenten Objekt-Cache (Redis, Memcached) zählt die Warteschlange in der Datenbank: abgewiesene Anfragen sparen die Buchungsabfragen, kosten aber selbst einen Lese- und einen Schreibzugriff auf die Optionstabelle.', 'wm-cyber-events-core' )
				: '',
		);
	}
}
