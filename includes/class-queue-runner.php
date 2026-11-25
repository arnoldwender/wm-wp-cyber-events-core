<?php
/**
 * Background queue for Brevo calls, processed by the cron event cybevents_process_queue_cron
 * (every minute) or `wp cybevents queue-run`.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Queue_Runner {

	/** A job left in "processing" longer than this is handed back to the queue. */
	const STALE_PROCESSING_SECONDS = 900;

	/**
	 * Push a job into the asynchronous queue.
	 *
	 * @param string $job_type Job identifier.
	 * @param array  $payload Associative array of job data.
	 * @param int    $delay_seconds Initial delay in seconds.
	 * @return int Job ID.
	 */
	public static function push( $job_type, $payload, $delay_seconds = 0 ) {
		global $wpdb;

		$scheduled_at = gmdate( 'Y-m-d H:i:s', time() + absint( $delay_seconds ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			"{$wpdb->prefix}cybevents_queue",
			array(
				'job_type'     => sanitize_key( $job_type ),
				'payload'      => wp_json_encode( $payload ),
				'attempts'     => 0,
				'max_attempts' => 5,
				'status'       => 'pending',
				'scheduled_at' => $scheduled_at,
				'created_at'   => current_time( 'mysql', 1 ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		return $wpdb->insert_id;
	}

	/**
	 * Payload kept once a job has finished: the confirmation link carries the participant and DOI
	 * tokens in plain text, and until 2026-09-15 it stayed in the table forever.
	 *
	 * @param array $payload Original payload.
	 * @return string JSON.
	 */
	public static function scrubbed_payload( array $payload ) {
		return (string) wp_json_encode(
			array_filter(
				array( 'registration_id' => isset( $payload['registration_id'] ) ? absint( $payload['registration_id'] ) : null ),
				static fn( $value ) => null !== $value
			)
		);
	}

	/**
	 * Process pending queue jobs in small batches to respect rate limits.
	 *
	 * @param int $batch_size Maximum items per execution.
	 * @return int Number of processed jobs.
	 */
	public static function process_batch( $batch_size = 20 ) {
		global $wpdb;

		$table = "{$wpdb->prefix}cybevents_queue";
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// A runner that died after claiming a job left it in "processing" for good.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'pending' WHERE status = 'processing' AND processed_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - self::STALE_PROCESSING_SECONDS )
			)
		);

		// Fetch pending jobs ready to run
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' AND scheduled_at <= %s ORDER BY id ASC LIMIT %d",
				$now,
				absint( $batch_size )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( empty( $jobs ) ) {
			return 0;
		}

		$processed = 0;

		foreach ( $jobs as $job ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// Claim the job only if it is still pending: until 2026-09-15 two runners (cron and
			// WP-CLI) could both process the same job and send the mail twice.
			$claimed = $wpdb->update(
				$table,
				array(
					'status'       => 'processing',
					'processed_at' => $now,
				),
				array(
					'id'     => $job->id,
					'status' => 'pending',
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( 1 !== (int) $claimed ) {
				continue;
			}

			$payload = json_decode( (string) $job->payload, true );
			if ( ! is_array( $payload ) ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array( 'status' => 'failed', 'error_message' => 'Ungültiges Payload-JSON', 'payload' => '{}' ),
					array( 'id' => $job->id )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				continue;
			}

			$success = false;
			$error   = '';

			switch ( $job->job_type ) {
				case 'brevo_doi':
				case 'brevo_send_doi': // Pushed under this name until 2026-09-15; no runner handled it.
					$res = CyberEvents_Brevo_API::send_double_optin( $payload );
					if ( is_wp_error( $res ) ) {
						$error = $res->get_error_message();
					} else {
						$success = true;
					}
					break;

				case 'brevo_update_attributes':
					$res = CyberEvents_Brevo_API::update_contact_attributes(
						$payload['email'] ?? '',
						$payload['attributes'] ?? array()
					);
					if ( is_wp_error( $res ) ) {
						$error = $res->get_error_message();
					} else {
						$success = true;
					}
					break;

				default:
					$error = 'Unbekannter Job-Typ: ' . esc_html( $job->job_type );
					break;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $success ) {
				$wpdb->update(
					$table,
					array(
						'status'        => 'completed',
						'error_message' => null,
						'payload'       => self::scrubbed_payload( $payload ),
					),
					array( 'id' => $job->id )
				);
				$processed++;
			} else {
				$attempts = (int) $job->attempts + 1;
				if ( $attempts >= (int) $job->max_attempts ) {
					$wpdb->update(
						$table,
						array(
							'attempts'      => $attempts,
							'status'        => 'failed',
							'error_message' => $error,
							'payload'       => self::scrubbed_payload( $payload ),
						),
						array( 'id' => $job->id )
					);
				} else {
					// Exponential backoff: 2^(attempts) * 60 seconds
					$backoff_delay = pow( 2, $attempts ) * 60;
					$next_schedule = gmdate( 'Y-m-d H:i:s', time() + $backoff_delay );

					$wpdb->update(
						$table,
						array(
							'attempts'      => $attempts,
							'status'        => 'pending',
							'scheduled_at'  => $next_schedule,
							'error_message' => $error,
						),
						array( 'id' => $job->id )
					);
				}
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		return $processed;
	}
}
