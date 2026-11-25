<?php
/**
 * Admin Dashboard, Views, Modals & Enterprise Management Studio (Security Hardened).
 *
 * Admin screens: dashboard, registrations with the invoice XML viewer, CSV import, DATEV export,
 * audit log, settings and handbook.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Admin {

	/**
	 * Register Admin Dashboard Widget
	 */
	public static function register_dashboard_widget(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wm_cyber_events_widget',
			__( 'WM Cyber Events — Mission Control & Auslastung', 'wm-cyber-events-core' ),
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render Admin Dashboard Widget
	 */
	public static function render_dashboard_widget(): void {
		global $wpdb;
		$table_events = $wpdb->prefix . 'cybevents_events';
		$table_reg    = $wpdb->prefix . 'cybevents_registrations';
		
		$events_count = 0;
		$reg_count    = 0;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_events ) ) === $table_events ) {
			$events_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_events} WHERE id > %d", 0 ) );
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_reg ) ) === $table_reg ) {
			$reg_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table_reg} WHERE status = %s", 'confirmed' ) );
		}
		$pending_count = self::get_pending_registrations_count();
		?>
		<div class="wm-dash-widget-box cybevents-dash-widget" data-wm-theme="<?php echo esc_attr( function_exists( 'wm_get_suite_theme' ) ? wm_get_suite_theme() : 'cyber-events' ); ?>">
			<div class="wm-dash-banner is-valid">
				<span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>
				<div>
					<div class="wm-dash-banner-title">
						<?php esc_html_e( 'CyberEvents Enterprise Engine aktiv', 'wm-cyber-events-core' ); ?>
					</div>
					<div class="wm-dash-banner-sub"><?php esc_html_e( 'Kapazitätsmanagement, DATEV-Export und Check-in', 'wm-cyber-events-core' ); ?></div>
				</div>
			</div>

			<div class="wm-dash-kpi-grid">
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val"><?php echo esc_html( (string) $events_count ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Events', 'wm-cyber-events-core' ); ?></div>
				</div>
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val is-on"><?php echo esc_html( (string) $reg_count ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Bestätigte Gäste', 'wm-cyber-events-core' ); ?></div>
				</div>
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val <?php echo $pending_count > 0 ? 'is-warn' : ''; ?>"><?php echo esc_html( (string) $pending_count ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Ausstehende DOI', 'wm-cyber-events-core' ); ?></div>
				</div>
			</div>

			<div class="wm-dash-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-dashboard' ) ); ?>" class="wm-dash-btn">
					<?php esc_html_e( 'Mission Control öffnen', 'wm-cyber-events-core' ); ?> <span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Sanitize CSV cell against Formula Injection / DDE attacks (CWE-1236).
	 *
	 * @param mixed $value Cell content.
	 * @return string Neutralized cell string.
	 */
	public static function sanitize_csv_cell( $value ) {
		$str = (string) $value;
		if ( preg_match( '/^[=+\-@\t\r]/', $str ) ) {
			return "'" . $str;
		}
		return $str;
	}

	/**
	 * Pre-dispatch handler for file downloads and exports (admin_init).
	 *
	 * Ensures raw CSV and DATEV exports send HTTP headers before WordPress starts HTML output,
	 * while enforcing strict DSGVO compliance and audit logging.
	 */
	public static function handle_export_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 1. Handle Attendee List CSV Export
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['page'], $_GET['action'] ) && 'cybevents-registrations' === $_GET['page'] && 'export_csv' === $_GET['action'] ) {
			check_admin_referer( 'cybevents_export_csv' );

			if ( empty( $_GET['dsgvo_consent'] ) || '1' !== (string) $_GET['dsgvo_consent'] ) {
				wp_die(
					'<h1>' . esc_html__( 'DSGVO-Einverständnis erforderlich', 'wm-cyber-events-core' ) . '</h1>' .
					'<p>' . esc_html__( 'Dieser Export enthält schutzbedürftige personenbezogene Daten gemäß Art. 4 Nr. 1 DSGVO. Sie müssen vor dem Herunterladen bestätigen, dass Sie die Daten vertraulich behandeln, zweckgebunden verarbeiten und nach der Veranstaltung datenschutzkonform löschen.', 'wm-cyber-events-core' ) . '</p>' .
					'<p><a href="' . esc_url( admin_url( 'admin.php?page=cybevents-registrations' ) ) . '" class="button button-primary">' . esc_html__( 'Zurück zum Buchungsmanagement', 'wm-cyber-events-core' ) . '</a></p>',
					esc_html__( 'DSGVO-Schutz', 'wm-cyber-events-core' ),
					array( 'response' => 403 )
				);
			}

			CyberEvents_Database::log_audit(
				'dsgvo_csv_export_authorized',
				0,
				get_current_user_id(),
				'Teilnehmerdaten-Export (CSV) durch Admin autorisiert unter Bestätigung der DSGVO-Vertraulichkeitspflicht.'
			);

			self::export_attendees_csv();
		}

		// 2. Handle DATEV EXTF Export
		if ( isset( $_POST['cybevents_datev_nonce'] ) && check_admin_referer( 'cybevents_datev_action', 'cybevents_datev_nonce' ) ) {
			$date_from = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
			$date_to   = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );

			if ( empty( $_POST['dsgvo_consent'] ) || '1' !== (string) $_POST['dsgvo_consent'] ) {
				wp_die(
					'<h1>' . esc_html__( 'DSGVO-Einverständnis erforderlich', 'wm-cyber-events-core' ) . '</h1>' .
					'<p>' . esc_html__( 'Der Export von Finanz- und Debitorendaten erfordert die Bestätigung, dass die Daten vertraulich und nur für die Buchhaltung verwendet werden.', 'wm-cyber-events-core' ) . '</p>' .
					'<p><a href="' . esc_url( admin_url( 'admin.php?page=cybevents-datev-export' ) ) . '" class="button button-primary">' . esc_html__( 'Zurück zum DATEV Studio', 'wm-cyber-events-core' ) . '</a></p>',
					esc_html__( 'DSGVO-Schutz', 'wm-cyber-events-core' ),
					array( 'response' => 403 )
				);
			}

			if ( ! empty( $date_from ) && ! empty( $date_to ) ) {
				CyberEvents_Database::log_audit(
					'dsgvo_datev_export_authorized',
					0,
					get_current_user_id(),
					sprintf( 'DATEV EXTF Export Zeitraum %s bis %s autorisiert unter DSGVO-Bestätigung.', $date_from, $date_to )
				);

				$csv = CyberEvents_DATEV_Exporter::generate_export( $date_from, $date_to );
				if ( is_wp_error( $csv ) ) {
					wp_die(
						'<p>' . esc_html( $csv->get_error_message() ) . '</p><p><a href="' . esc_url( admin_url( 'admin.php?page=cybevents-datev-export' ) ) . '" class="button button-primary">' . esc_html__( 'Zurück zum DATEV Studio', 'wm-cyber-events-core' ) . '</a></p>',
						esc_html__( 'DATEV-Export', 'wm-cyber-events-core' ),
						array( 'response' => 400 )
					);
				}

				header( 'Content-Type: text/csv; charset=windows-1252' );
				header( 'Content-Disposition: attachment; filename=EXTF_Buchungsstapel_' . gmdate( 'Ymd_His' ) . '.csv' );
				header( 'X-Content-Type-Options: nosniff' );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a CSV download, not HTML.
				echo CyberEvents_DATEV_Exporter::to_windows_1252( $csv );
				exit;
			}
		}
		// 3. Handle Save Event (Dashboard Quick-Edit / Create)
		if ( isset( $_POST['cybevents_save_event_nonce'] ) && check_admin_referer( 'cybevents_save_event_action', 'cybevents_save_event_nonce' ) ) {
			global $wpdb;
			$event_id     = absint( $_POST['event_id'] ?? 0 );
			$wp_post_id   = absint( $_POST['wp_post_id'] ?? 0 );
			$title        = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
			$slug         = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
			$location     = sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) );
			$start_date   = sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) );
			$end_date     = sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) );
			$max_capacity = absint( $_POST['max_capacity'] ?? 500 );
			$ticket_price = (float) ( $_POST['ticket_price'] ?? 0.00 );
			$is_paid      = ! empty( $_POST['is_paid'] ) ? 1 : 0;
			$status       = in_array( $_POST['status'] ?? '', array( 'active', 'draft', 'archived' ), true ) ? $_POST['status'] : 'active';

			if ( ! empty( $title ) ) {
				// Normalize datetime strings (e.g. from 2026-10-15T09:00 to 2026-10-15 09:00:00)
				if ( strpos( $start_date, 'T' ) !== false ) {
					$start_date = str_replace( 'T', ' ', $start_date ) . ':00';
				}
				if ( strpos( $end_date, 'T' ) !== false ) {
					$end_date = str_replace( 'T', ' ', $end_date ) . ':00';
				}

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( ! $wp_post_id && $event_id ) {
					$wp_post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT wp_post_id FROM {$wpdb->prefix}cybevents_events WHERE id = %d", $event_id ) );
				}
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				$post_status = ( 'active' === $status ) ? 'publish' : 'draft';

				if ( $wp_post_id && get_post( $wp_post_id ) ) {
					wp_update_post(
						array(
							'ID'          => $wp_post_id,
							'post_title'  => $title,
							'post_name'   => $slug ?: sanitize_title( $title ),
							'post_status' => $post_status,
						)
					);
				} else {
					$wp_post_id = wp_insert_post(
						array(
							'post_title'   => $title,
							'post_name'    => $slug ?: sanitize_title( $title ),
							'post_status'  => $post_status,
							'post_type'    => 'cybevent',
							'post_content' => '<!-- wp:wm-cyber-events/event-hero {"eventId":' . $event_id . '} /-->',
						)
					);
				}

				if ( $wp_post_id && ! is_wp_error( $wp_post_id ) ) {
					update_post_meta( $wp_post_id, '_cybevents_location', $location );
					update_post_meta( $wp_post_id, '_cybevents_start_date', $start_date );
					update_post_meta( $wp_post_id, '_cybevents_end_date', $end_date );
					update_post_meta( $wp_post_id, '_cybevents_max_capacity', $max_capacity );
					update_post_meta( $wp_post_id, '_cybevents_ticket_price', $ticket_price );
					update_post_meta( $wp_post_id, '_cybevents_is_paid', $is_paid );
				}

				$data = array(
					'wp_post_id'   => $wp_post_id,
					'title'        => $title,
					'slug'         => $slug ?: sanitize_title( $title ),
					'location'     => $location,
					'start_date'   => $start_date ?: current_time( 'mysql' ),
					'end_date'     => $end_date ?: current_time( 'mysql' ),
					'max_capacity' => $max_capacity,
					'ticket_price' => $ticket_price,
					'is_paid'      => $is_paid,
					'status'       => $status,
				);

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( $event_id ) {
					$wpdb->update( "{$wpdb->prefix}cybevents_events", $data, array( 'id' => $event_id ) );
					CyberEvents_Database::log_audit( 'event_updated', $event_id, get_current_user_id(), sprintf( 'Veranstaltung #%d ("%s") im Admin-Dashboard aktualisiert.', $event_id, $title ) );
				} else {
					$wpdb->insert( "{$wpdb->prefix}cybevents_events", $data );
					$event_id = (int) $wpdb->insert_id;
					CyberEvents_Database::log_audit( 'event_created', $event_id, get_current_user_id(), sprintf( 'Neue Veranstaltung #%d ("%s") im Admin-Dashboard angelegt.', $event_id, $title ) );
				}
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				wp_safe_redirect( admin_url( 'admin.php?page=cybevents-dashboard&updated=1' ) );
				exit;
			}
		}

		// 4. Handle Delete Event
		if ( isset( $_GET['page'], $_GET['action'], $_GET['event_id'] ) && 'cybevents-dashboard' === $_GET['page'] && 'delete_event' === $_GET['action'] ) {
			$event_id = absint( $_GET['event_id'] );
			check_admin_referer( 'cybevents_delete_event_' . $event_id );

			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wp_post_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT wp_post_id FROM {$wpdb->prefix}cybevents_events WHERE id = %d", $event_id ) );
			if ( $wp_post_id ) {
				wp_trash_post( $wp_post_id );
			}

			$wpdb->delete( "{$wpdb->prefix}cybevents_events", array( 'id' => $event_id ), array( '%d' ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			CyberEvents_Database::log_audit( 'event_deleted', $event_id, get_current_user_id(), sprintf( 'Veranstaltung #%d gelöscht.', $event_id ) );

			wp_safe_redirect( admin_url( 'admin.php?page=cybevents-dashboard&deleted=1' ) );
			exit;
		}

		// 5. Handle Save Registration (Edit Attendee Modal)
		if ( isset( $_POST['cybevents_save_registration_nonce'] ) && check_admin_referer( 'cybevents_save_registration_action', 'cybevents_save_registration_nonce' ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-cyber-events-core' ) );
			}

			global $wpdb;
			$reg_id         = absint( $_POST['reg_id'] ?? 0 );
			$event_id       = absint( $_POST['event_id'] ?? 0 );
			$first_name     = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
			$last_name      = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
			$email          = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			$company        = sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) );
			$posted_status  = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
			$checked_in     = ! empty( $_POST['checked_in'] ) ? 1 : 0;
			$posted_payment = sanitize_key( wp_unslash( $_POST['payment_status'] ?? '' ) );
			$invoice_number = sanitize_text_field( wp_unslash( $_POST['invoice_number'] ?? '' ) );
			$consent_priv   = ! empty( $_POST['consent_privacy'] ) ? 1 : 0;
			$consent_photo  = ! empty( $_POST['consent_photo'] ) ? 1 : 0;

			if ( $reg_id && ! empty( $first_name ) && ! empty( $last_name ) && is_email( $email ) ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$old_reg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cybevents_registrations WHERE id = %d", $reg_id ) );
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

				if ( $old_reg ) {
					// A value the form does not know keeps what is stored. Until 2026-09-15 it became
					// 'confirmed' and 'free', and the status select had no 'bounced', so saving a bounced
					// registration in the modal confirmed it.
					$status         = in_array( $posted_status, array( 'confirmed', 'pending_doi', 'cancelled', 'bounced' ), true ) ? $posted_status : (string) $old_reg->status;
					$payment_status = in_array( $posted_payment, array( 'free', 'pending', 'paid', 'refunded' ), true ) ? $posted_payment : (string) $old_reg->payment_status;

					$checked_in_at = $old_reg->checked_in_at;
					if ( 1 === $checked_in && 0 === (int) $old_reg->checked_in ) {
						$checked_in_at = current_time( 'mysql' );
					} elseif ( 0 === $checked_in ) {
						$checked_in_at = null;
					}

					$doi_confirmed_at = $old_reg->doi_confirmed_at;
					if ( 'confirmed' === $status && 'pending_doi' === $old_reg->status && empty( $doi_confirmed_at ) ) {
						$doi_confirmed_at = current_time( 'mysql' );
					}

					$update_data = array(
						'event_id'         => $event_id ?: (int) $old_reg->event_id,
						'first_name'       => $first_name,
						'last_name'        => $last_name,
						'email'            => $email,
						'company'          => $company,
						'status'           => $status,
						'checked_in'       => $checked_in,
						'checked_in_at'    => $checked_in_at,
						'doi_confirmed_at' => $doi_confirmed_at,
						'payment_status'   => $payment_status,
						'invoice_number'   => $invoice_number,
						'consent_privacy'  => $consent_priv,
						'consent_photo'    => $consent_photo,
					);

					// Handle avatar update / delete / upload
					if ( ! empty( $_POST['delete_avatar'] ) ) {
						$update_data['avatar_url'] = '';
					} elseif ( ! empty( $_FILES['avatar']['name'] ) && ! empty( $_FILES['avatar']['tmp_name'] ) ) {
						$upload_res = CyberEvents_Database::handle_avatar_upload( $_FILES['avatar'] );
						if ( ! empty( $upload_res ) && ! is_wp_error( $upload_res ) && is_string( $upload_res ) ) {
							$update_data['avatar_url'] = $upload_res;
						}
					} elseif ( isset( $_POST['avatar_url'] ) ) {
						$update_data['avatar_url'] = esc_url_raw( wp_unslash( $_POST['avatar_url'] ) );
					}

					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$wpdb->prefix . 'cybevents_registrations',
						$update_data,
						array( 'id' => $reg_id )
					);
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

					CyberEvents_Database::log_audit(
						'registration_updated',
						$reg_id,
						get_current_user_id(),
						sprintf( 'Teilnehmerdaten für #%d im Admin-Panel aktualisiert.', $reg_id )
					);

					$redirect_url = add_query_arg(
						array(
							'page'    => 'cybevents-registrations',
							'updated' => '1',
						),
						admin_url( 'admin.php' )
					);
					if ( ! empty( $_POST['filter_event_id'] ) ) {
						$redirect_url = add_query_arg( 'event_id', absint( $_POST['filter_event_id'] ), $redirect_url );
					}
					wp_safe_redirect( $redirect_url );
					exit;
				}
			}
		}

		// 6. Handle Delete Registration
		if ( isset( $_GET['page'], $_GET['action'], $_GET['reg_id'] ) && 'cybevents-registrations' === $_GET['page'] && 'delete_reg' === $_GET['action'] ) {
			$reg_id = absint( $_GET['reg_id'] );
			check_admin_referer( 'cybevents_delete_reg_' . $reg_id );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-cyber-events-core' ) );
			}

			global $wpdb;
			// Through the GDPR helper so the booked seats are released and the avatar file goes with
			// the row; until 2026-09-15 the mapping rows were deleted and the seats stayed counted.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$reg_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cybevents_registrations WHERE id = %d", $reg_id ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $reg_row ) {
				CyberEvents_GDPR_Manager::delete_registration( $reg_row );
			}

			CyberEvents_Database::log_audit( 'registration_deleted', $reg_id, get_current_user_id(), sprintf( 'Teilnehmer #%d gelöscht.', $reg_id ) );

			$redirect_url = admin_url( 'admin.php?page=cybevents-registrations&deleted=1' );
			if ( ! empty( $_GET['event_id'] ) ) {
				$redirect_url = add_query_arg( 'event_id', absint( $_GET['event_id'] ), $redirect_url );
			}
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// 7. Handle Toggle Check-In
		if ( isset( $_GET['page'], $_GET['action'], $_GET['reg_id'] ) && 'cybevents-registrations' === $_GET['page'] && 'toggle_checkin' === $_GET['action'] ) {
			$reg_id = absint( $_GET['reg_id'] );
			check_admin_referer( 'cybevents_toggle_checkin_' . $reg_id );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-cyber-events-core' ) );
			}

			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$current_checkin = (int) $wpdb->get_var( $wpdb->prepare( "SELECT checked_in FROM {$wpdb->prefix}cybevents_registrations WHERE id = %d", $reg_id ) );
			$new_status      = ( 1 === $current_checkin ) ? 0 : 1;
			$checkin_time    = ( 1 === $new_status ) ? current_time( 'mysql' ) : null;

			$wpdb->update(
				"{$wpdb->prefix}cybevents_registrations",
				array(
					'checked_in'    => $new_status,
					'checked_in_at' => $checkin_time,
				),
				array( 'id' => $reg_id )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			CyberEvents_Database::log_audit(
				'checkin_toggled',
				$reg_id,
				get_current_user_id(),
				sprintf( 'Check-In Status für Teilnehmer #%d auf %s gesetzt.', $reg_id, 1 === $new_status ? 'Eingecheckt' : 'Nicht vor Ort' )
			);

			$redirect_url = admin_url( 'admin.php?page=cybevents-registrations&checkin_toggled=1' );
			if ( ! empty( $_GET['event_id'] ) ) {
				$redirect_url = add_query_arg( 'event_id', absint( $_GET['event_id'] ), $redirect_url );
			}
			wp_safe_redirect( $redirect_url );
			exit;
		}

		// 8. Handle Confirm DOI Manually
		if ( isset( $_GET['page'], $_GET['action'], $_GET['reg_id'] ) && 'cybevents-registrations' === $_GET['page'] && 'confirm_doi' === $_GET['action'] ) {
			$reg_id = absint( $_GET['reg_id'] );
			check_admin_referer( 'cybevents_confirm_doi_' . $reg_id );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-cyber-events-core' ) );
			}

			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// Pending registrations only, and the DOI token becomes unusable, as with the link.
			$wpdb->update(
				"{$wpdb->prefix}cybevents_registrations",
				array(
					'status'           => 'confirmed',
					'doi_confirmed_at' => current_time( 'mysql' ),
					'doi_token_hash'   => null,
				),
				array(
					'id'     => $reg_id,
					'status' => 'pending_doi',
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			CyberEvents_Database::log_audit(
				'doi_manually_confirmed',
				$reg_id,
				get_current_user_id(),
				sprintf( 'Double Opt-In für Teilnehmer #%d manuell durch Administrator bestätigt.', $reg_id )
			);

			$redirect_url = admin_url( 'admin.php?page=cybevents-registrations&doi_confirmed=1' );
			if ( ! empty( $_GET['event_id'] ) ) {
				$redirect_url = add_query_arg( 'event_id', absint( $_GET['event_id'] ), $redirect_url );
			}
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Get count of pending DOI registrations.
	 *
	 * @return int
	 */
	public static function get_pending_registrations_count() {
		global $wpdb;
		$table = $wpdb->prefix . 'cybevents_registrations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending_doi' ) );
		return (int) $count;
	}

	/**
	 * Fix active parent menu highlight for custom post types, taxonomy, and plugin pages.
	 *
	 * @param string $parent_file Current parent file.
	 * @return string
	 */
	public static function fix_admin_parent_file( $parent_file ) {
		global $current_screen, $plugin_page;
		if ( ! empty( $plugin_page ) && 0 === strpos( (string) $plugin_page, 'cybevents' ) ) {
			return 'cybevents-dashboard';
		}
		if ( $current_screen && ( in_array( $current_screen->post_type, array( 'cybevent', 'cybsession' ), true ) || 'cybvenue' === $current_screen->taxonomy ) ) {
			return 'cybevents-dashboard';
		}
		return $parent_file;
	}

	/**
	 * Fix active submenu highlight for custom post types and taxonomy.
	 *
	 * @param string $submenu_file Current submenu file.
	 * @return string
	 */
	public static function fix_admin_submenu_file( $submenu_file ) {
		global $current_screen, $pagenow;
		if ( $current_screen && 'cybvenue' === $current_screen->taxonomy ) {
			return 'edit-tags.php?taxonomy=cybvenue&post_type=cybevent';
		}
		if ( $current_screen && 'cybevent' === $current_screen->post_type ) {
			if ( 'post-new.php' === $pagenow || 'add' === $current_screen->action ) {
				return 'post-new.php?post_type=cybevent';
			}
			return 'edit.php?post_type=cybevent';
		}
		if ( $current_screen && 'cybsession' === $current_screen->post_type ) {
			if ( 'post-new.php' === $pagenow || 'add' === $current_screen->action ) {
				return 'post-new.php?post_type=cybsession';
			}
			return 'edit.php?post_type=cybsession';
		}
		return $submenu_file;
	}

	/**
	 * Family menu icon: a monochrome hub-and-spoke mark, filled shapes only so the WordPress
	 * svg-painter can recolour it with the admin colour scheme. Placeholder until the family
	 * icon is decided (hackaton plan D12).
	 */
	public static function family_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.6" fill="black"/><circle cx="10" cy="3" r="1.8" fill="black"/><circle cx="16.1" cy="13.5" r="1.8" fill="black"/><circle cx="3.9" cy="13.5" r="1.8" fill="black"/><rect x="9.3" y="4.5" width="1.4" height="3.4" fill="black"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(-60 10 10)"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(60 10 10)"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Register Admin Menus with strict logical workflow organization.
	 */
	public static function register_admin_menus() {
		// Top Level: Cyber Events
		add_menu_page(
			__( 'Cyber Events', 'wm-cyber-events-core' ),
			__( 'Cyber Events', 'wm-cyber-events-core' ),
			'manage_options',
			'cybevents-dashboard',
			array( __CLASS__, 'render_dashboard_page' ),
			self::family_menu_icon(),
			26
		);

		// 1. Dashboard & Live-Monitoring
		add_submenu_page(
			'cybevents-dashboard',
			__( 'Dashboard & KPIs', 'wm-cyber-events-core' ),
			__( 'Dashboard', 'wm-cyber-events-core' ),
			'manage_options',
			'cybevents-dashboard',
			array( __CLASS__, 'render_dashboard_page' )
		);

		// 2. Events: Alle Veranstaltungen
		add_submenu_page(
			'cybevents-dashboard',
			__( 'Alle Veranstaltungen', 'wm-cyber-events-core' ),
			__( 'Veranstaltungen', 'wm-cyber-events-core' ),
			'manage_options',
			'edit.php?post_type=cybevent'
		);

		// 3. Events: Neue Veranstaltung
		add_submenu_page(
			'cybevents-dashboard',
			__( 'Neue Veranstaltung erstellen', 'wm-cyber-events-core' ),
			__( 'Neue Veranstaltung', 'wm-cyber-events-core' ),
			'manage_options',
			'post-new.php?post_type=cybevent'
		);

		// 4. Locations: Veranstaltungsorte & Tagungsstätten
		add_submenu_page(
			'cybevents-dashboard',
			__( 'Veranstaltungsorte & Tagungsstätten', 'wm-cyber-events-core' ),
			__( 'Veranstaltungsorte', 'wm-cyber-events-core' ),
			'manage_options',
			'edit-tags.php?taxonomy=cybvenue&post_type=cybevent'
		);

		// 5. Agenda: Sessions & Tracks
		add_submenu_page(
			'cybevents-dashboard',
			__( 'Sessions & Agenda', 'wm-cyber-events-core' ),
			__( 'Sessions & Agenda', 'wm-cyber-events-core' ),
			'manage_options',
			'edit.php?post_type=cybsession'
		);

		// 6. Agenda: Neue Session
		add_submenu_page(
			'cybevents-dashboard',
			__( 'Neue Session erstellen', 'wm-cyber-events-core' ),
			__( 'Neue Session', 'wm-cyber-events-core' ),
			'manage_options',
			'post-new.php?post_type=cybsession'
		);

		// 6. Operations: Teilnehmer & Buchungen
		$pending_count = self::get_pending_registrations_count();
		$reg_label     = __( 'Teilnehmer & Buchungen', 'wm-cyber-events-core' );
		if ( $pending_count > 0 ) {
			$reg_label .= sprintf( ' <span class="awaiting-mod update-plugins count-%1$d"><span class="pending-count">%1$d</span></span>', $pending_count );
		}

		add_submenu_page(
			'cybevents-dashboard',
			__( 'Teilnehmer & Buchungen', 'wm-cyber-events-core' ),
			$reg_label,
			'manage_options',
			'cybevents-registrations',
			array( __CLASS__, 'render_registrations_page' )
		);

		// 7. Operations: CSV-Import & Onboarding
		add_submenu_page(
			'cybevents-dashboard',
			__( 'CSV-Import & Onboarding', 'wm-cyber-events-core' ),
			__( 'CSV-Import', 'wm-cyber-events-core' ),
			'manage_options',
			'cybevents-csv-import',
			array( __CLASS__, 'render_csv_import_page' )
		);

		// 8. Finance: DATEV export
		add_submenu_page(
			'cybevents-dashboard',
			__( 'Finanzen & DATEV-Export', 'wm-cyber-events-core' ),
			__( 'Finanzen & DATEV', 'wm-cyber-events-core' ),
			'manage_options',
			'cybevents-datev-export',
			array( __CLASS__, 'render_datev_page' )
		);

		// 9. Audit log
		add_submenu_page(
			'cybevents-dashboard',
			__( 'Audit-Log', 'wm-cyber-events-core' ),
			__( 'Audit-Log', 'wm-cyber-events-core' ),
			'manage_options',
			'cybevents-audit-log',
			array( __CLASS__, 'render_audit_page' )
		);

		// 10. System: Einstellungen & Theme Studio
		add_submenu_page(
			'cybevents-dashboard',
			__( 'Einstellungen & Theme Studio', 'wm-cyber-events-core' ),
			__( 'Einstellungen', 'wm-cyber-events-core' ),
			'manage_options',
			'cybevents-settings',
			array( __CLASS__, 'render_settings_page' )
		);

		// 11. Documentation & Handbook
		add_submenu_page(
			'cybevents-dashboard',
			__( 'Handbuch & Dokumentation', 'wm-cyber-events-core' ),
			__( 'Handbuch', 'wm-cyber-events-core' ),
			'manage_options',
			'cybevents-docs',
			array( __CLASS__, 'render_docs_page' )
		);
	}

	/**
	 * Register modern WordPress Abilities Categories.
	 */
	public static function register_ability_categories() {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category(
				'cybevents-events',
				array(
					'label'       => __( 'Events & Konferenzen', 'wm-cyber-events-core' ),
					'description' => __( 'Fähigkeiten für Event- und Konferenzmanagement.', 'wm-cyber-events-core' ),
				)
			);
		}
	}

	/**
	 * Register modern WordPress Abilities.
	 */
	public static function register_abilities() {
		if ( function_exists( 'wp_register_ability' ) ) {
			wp_register_ability(
				'cybevents/manage-registrations',
				array(
					'label'               => __( 'Teilnehmer verwalten', 'wm-cyber-events-core' ),
					'description'         => __( 'Erlaubt das Einsehen und Exportieren von Veranstaltungsanmeldungen ohne WordPress-Vollzugriff.', 'wm-cyber-events-core' ),
					'category'            => 'cybevents-events',
					'execute_callback'    => function( $input = array() ) {
						return array( 'status' => 'success' );
					},
					'permission_callback' => function( $user_id ) {
						return user_can( $user_id, 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Render Unified Navigation Tabs
	 */
	public static function render_nav_tabs( string $current_page ): void {
		// The icon of each tab is a dashicon rendered next to the label (no emoji in the strings).
		$tabs = [
			'cybevents-dashboard'     => [ 'label' => __( 'Dashboard & KPIs', 'wm-cyber-events-core' ), 'icon' => 'dashicons-dashboard' ],
			'cybevents-registrations' => [ 'label' => __( 'Teilnehmer & Buchungen', 'wm-cyber-events-core' ), 'icon' => 'dashicons-tickets-alt' ],
			'cybevents-csv-import'    => [ 'label' => __( 'CSV-Import & Onboarding', 'wm-cyber-events-core' ), 'icon' => 'dashicons-upload' ],
			'cybevents-datev-export'  => [ 'label' => __( 'Finanzen & DATEV', 'wm-cyber-events-core' ), 'icon' => 'dashicons-media-spreadsheet' ],
			'cybevents-audit-log'     => [ 'label' => __( 'Audit-Log', 'wm-cyber-events-core' ), 'icon' => 'dashicons-shield-alt' ],
			'cybevents-settings'      => [ 'label' => __( 'Einstellungen & Theme', 'wm-cyber-events-core' ), 'icon' => 'dashicons-admin-generic' ],
			'cybevents-docs'          => [ 'label' => __( 'Handbuch & Doku', 'wm-cyber-events-core' ), 'icon' => 'dashicons-book' ],
		];
		?>
		<nav class="wm-nav-tabs" aria-label="<?php esc_attr_e( 'Plugin-Navigation', 'wm-cyber-events-core' ); ?>">
			<?php foreach ( $tabs as $page => $data ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $page ) ); ?>"
				   class="wm-tab-link <?php echo $current_page === $page ? 'active' : ''; ?>"<?php echo $current_page === $page ? ' aria-current="page"' : ''; ?>>
					<span class="dashicons <?php echo esc_attr( $data['icon'] ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $data['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Executive Dashboard & Analytics Page.
	 */
	public static function render_dashboard_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-cyber-events-core' ) );
		}

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible" style="margin-top: 15px;"><p><strong>' . esc_html__( 'Veranstaltung erfolgreich gespeichert!', 'wm-cyber-events-core' ) . '</strong> ' . esc_html__( 'Die Daten wurden synchron in der Datenbank und im WordPress-System aktualisiert.', 'wm-cyber-events-core' ) . '</p></div>';
		}
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible" style="margin-top: 15px;"><p><strong>' . esc_html__( 'Veranstaltung gelöscht!', 'wm-cyber-events-core' ) . '</strong> ' . esc_html__( 'Der Datensatz wurde entfernt und der Beitrag in den Papierkorb verschoben.', 'wm-cyber-events-core' ) . '</p></div>';
		}

		if ( isset( $_POST['cybevents_seed_demo_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['cybevents_seed_demo_nonce'] ), 'cybevents_seed_demo' ) ) {
			CyberEvents_Seeder::seed_demo_data( true );
			echo '<div class="notice notice-success is-dismissible" style="margin-top: 15px;"><p><strong>' . esc_html__( 'Demodaten angelegt.', 'wm-cyber-events-core' ) . '</strong> ' . esc_html__( 'Beispielveranstaltung, Sessions und Beispiel-Teilnehmer wurden angelegt; Rechnungen nur, wenn die Rechnungssteller-Daten gesetzt sind.', 'wm-cyber-events-core' ) . '</p></div>';
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_events   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_events WHERE id > %d", 0 ) );
		$total_capacity = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(max_capacity), 0) FROM {$wpdb->prefix}cybevents_events WHERE id > %d", 0 ) );
		$total_regs     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE status = %s", 'confirmed' ) );
		$checked_in     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE checked_in = %d", 1 ) );
		$pending_doi    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE status = %s", 'pending_doi' ) );
		// Invoices in force: standard invoices not cancelled. Until 2026-09-15 the count and the sum took
		// every 'issued' row, which includes the negative credit notes, while the cancelled originals
		// were left out, so each cancellation was subtracted twice.
		$total_inv      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_invoices WHERE invoice_type = 'standard' AND status = %s", 'issued' ) );
		$total_storno   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_invoices WHERE invoice_type = 'standard' AND status = %s", 'cancelled' ) );
		$total_volume   = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(gross_amount), 0) FROM {$wpdb->prefix}cybevents_invoices WHERE invoice_type = 'standard' AND status = %s", 'issued' ) );
		$regs_24h       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE created_at >= %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$sessions_total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_sessions WHERE id > %d", 0 ) );

		// Ensure schema is up to date if any table is missing
		$queue_table = $wpdb->prefix . 'cybevents_queue';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) ) !== $queue_table ) {
			// Schema only: activate() also seeds demo data.
			CyberEvents_Activator::migrate();
		}

		$queue_sent    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_queue WHERE status IN (%s, %s)", 'sent', 'completed' ) );
		$queue_pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_queue WHERE status = %s", 'pending' ) );

		$events_list = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*,
				(SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations r WHERE r.event_id = e.id AND r.status = %s) AS confirmed_count,
				(SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations r WHERE r.event_id = e.id AND r.checked_in = %d) AS checked_in_count,
				(SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_sessions s WHERE s.event_id = e.id) AS session_count
				FROM {$wpdb->prefix}cybevents_events e ORDER BY e.id DESC LIMIT %d",
				'confirmed',
				1,
				50
			)
		);

		$recent_audits = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cybevents_audit_log ORDER BY id DESC LIMIT %d", 5 )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Telemetry calculations
		$display_capacity = max( $total_capacity, 100 );
		$auslastung_pct   = min( 100, round( ( $total_regs / max( 1, $display_capacity ) ) * 100 ) );
		$checkin_pct      = $total_regs > 0 ? min( 100, round( ( $checked_in / $total_regs ) * 100 ) ) : 0;
		$queue_total      = max( 1, $queue_sent + $queue_pending );
		$queue_pct        = min( 100, round( ( $queue_sent / $queue_total ) * 100, 1 ) );

		// Donut segments from the real counts (until 2026-09-14 the three arcs were fixed at
		// 94 / 4 / 2 % whatever the data). Circumference of r=38 rounded to 238.
		$donut_total = max( 1, $total_inv + $total_storno + $pending_doi );
		$seg_inv     = round( 238 * $total_inv / $donut_total, 1 );
		$seg_storno  = round( 238 * $total_storno / $donut_total, 1 );
		$seg_pending = round( 238 * $pending_doi / $donut_total, 1 );

		foreach ( $events_list as $ev ) {
			if ( empty( $ev->wp_post_id ) || ! get_post( $ev->wp_post_id ) ) {
				$ev->wp_post_id = CyberEvents_Events_Manager::get_or_create_cpt_for_event( $ev->id );
			}
			$ev->confirmed_count = (int) ( $ev->confirmed_count ?? 0 );
			$ev->attendee_count  = $ev->confirmed_count;
			$ev->edit_url        = admin_url( 'post.php?post=' . $ev->wp_post_id . '&action=edit' );
			$ev->view_url        = get_permalink( $ev->wp_post_id ) ?: home_url( '/veranstaltungen/' . $ev->slug );
			$ev->delete_url      = wp_nonce_url( admin_url( 'admin.php?page=cybevents-dashboard&action=delete_event&event_id=' . $ev->id ), 'cybevents_delete_event_' . $ev->id );
		}
		?>
		<div class="wrap wm-admin cybevents-admin-wrap">
			<!-- Enterprise Mission Control Command Header (WordPress Admin Native) -->
			<header class="wm-admin-header cybevents-enterprise-banner">
				<div class="cybevents-banner-content">
					<div class="cybevents-banner-title">
						<h1>
							<span class="dashicons dashicons-shield cybevents-logo-icon" aria-hidden="true"></span>
							<span><?php esc_html_e( 'CyberEvents Command Console', 'wm-cyber-events-core' ); ?></span>
							<span class="cybevents-version-tag"><?php esc_html_e( 'Enterprise 2026', 'wm-cyber-events-core' ); ?></span>
						</h1>
						<p><?php esc_html_e( 'Veranstaltungen, Sessions und Anmeldungen mit Double Opt-in über Brevo, Check-in, Rechnungs-XML und DATEV-Export.', 'wm-cyber-events-core' ); ?></p>
					</div>
					<div class="cybevents-banner-actions">
						<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-cyber-events-core' ); ?>">
							<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
							<?php esc_html_e( 'WM Suite', 'wm-cyber-events-core' ); ?>
							<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-cyber-events-core' ) : esc_html__( 'Standalone', 'wm-cyber-events-core' ); ?></span>
						</span>
						<?php // A static system status with a green dot stood here until 2026-09-15; nothing measured it. ?>
						<form method="post">
							<?php wp_nonce_field( 'cybevents_seed_demo', 'cybevents_seed_demo_nonce' ); ?>
							<button type="submit" class="button button-secondary cybevents-btn-refresh-demo">
								<?php esc_html_e( 'Demo-Daten auffrischen', 'wm-cyber-events-core' ); ?>
							</button>
						</form>
					</div>
				</div>
			</header>

			<?php self::render_nav_tabs( 'cybevents-dashboard' ); ?>

			<!-- Quick Jump Navigation -->
			<nav class="wm-jump-nav">
				<span class="wm-jump-nav-label"><?php esc_html_e( 'Springen zu:', 'wm-cyber-events-core' ); ?></span>
				<a href="#wm-sec-guide" class="wm-jump-link"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span><?php esc_html_e( 'Kurzanleitung', 'wm-cyber-events-core' ); ?></a>
				<a href="#wm-sec-telemetry" class="wm-jump-link"><span class="dashicons dashicons-chart-area" aria-hidden="true"></span><?php esc_html_e( 'Kennzahlen', 'wm-cyber-events-core' ); ?></a>
				<a href="#wm-sec-events" class="wm-jump-link"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><?php esc_html_e( 'Veranstaltungen', 'wm-cyber-events-core' ); ?></a>
				<a href="#wm-sec-invoices" class="wm-jump-link"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span><?php esc_html_e( 'Rechnungen', 'wm-cyber-events-core' ); ?></a>
				<a href="#wm-sec-audit" class="wm-jump-link"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><?php esc_html_e( 'Audit-Protokoll', 'wm-cyber-events-core' ); ?></a>
			</nav>

			<!-- Quick Plain Language Guide -->
			<section id="wm-sec-guide" class="wm-quick-guide">
				<div class="wm-quick-guide-title">
					<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
					<span><?php esc_html_e( 'Was macht CyberEvents, und was finden Sie hier?', 'wm-cyber-events-core' ); ?></span>
				</div>
				<p class="wm-quick-guide-desc">
					<?php esc_html_e( 'Das Plugin verwaltet Veranstaltungen mit Sessions und Anmeldungen. Teilnehmer bestätigen ihre Anmeldung über einen Double-Opt-in-Link, den Brevo versendet (API-Schlüssel nötig), wählen danach Sessions, deren Plätze nicht überbucht werden, und werden am Einlass über ihr Token eingecheckt. Rechnungen entstehen als CII-XML (EN 16931) und lassen sich als DATEV-Buchungsstapel exportieren.', 'wm-cyber-events-core' ); ?>
				</p>
				<div class="wm-quick-guide-actions">
					<strong><?php esc_html_e( 'Schnellzugriff:', 'wm-cyber-events-core' ); ?></strong>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-registrations' ) ); ?>" class="wm-preset-pill-btn">
						<span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'Teilnehmer und Anmeldungen', 'wm-cyber-events-core' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-datev-export' ) ); ?>" class="wm-preset-pill-btn">
						<span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
						<?php esc_html_e( 'Rechnungen als DATEV-Stapel exportieren', 'wm-cyber-events-core' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-settings' ) ); ?>" class="wm-preset-pill-btn">
						<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
						<?php esc_html_e( 'Einstellungen', 'wm-cyber-events-core' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-docs' ) ); ?>" class="wm-preset-pill-btn">
						<span class="dashicons dashicons-book" aria-hidden="true"></span>
						<?php esc_html_e( 'Handbuch', 'wm-cyber-events-core' ); ?>
					</a>
				</div>
			</section>

			<!-- Mission Control Telemetry Cockpit (WordPress Admin Style Light Cards) -->
			<div id="wm-sec-telemetry" class="cybevents-telemetry-grid">
				<!-- Widget 1: Event Auslastung Radial Gauge -->
				<div class="cybevents-telemetry-card cybevents-telemetry-card--gauge">
					<div class="cybevents-telemetry-header">
						<span class="cybevents-telemetry-title"><?php esc_html_e( 'Event Auslastung', 'wm-cyber-events-core' ); ?></span>
						<span class="cybevents-telemetry-badge <?php echo esc_attr( $auslastung_pct >= 90 ? 'cybevents-telemetry-badge--critical' : 'cybevents-telemetry-badge--optimal' ); ?>">
							<?php echo $auslastung_pct >= 90 ? esc_html__( 'Kritisch', 'wm-cyber-events-core' ) : esc_html__( 'Optimal', 'wm-cyber-events-core' ); ?>
						</span>
					</div>
					<div class="cybevents-gauge-container">
						<svg class="cybevents-radial-gauge" viewBox="0 0 120 70">
							<path class="cybevents-gauge-bg" d="M 10,65 A 50,50 0 0,1 110,65" fill="none" stroke-width="12" />
							<path class="cybevents-gauge-fill" d="M 10,65 A 50,50 0 0,1 110,65" fill="none" stroke-width="12"
								stroke-dasharray="157"
								stroke-dashoffset="<?php echo esc_attr( 157 - ( 157 * ( $auslastung_pct / 100 ) ) ); ?>" />
						</svg>
						<div class="cybevents-gauge-val">
							<span class="cybevents-gauge-num"><?php echo esc_html( $auslastung_pct ); ?>%</span>
						</div>
					</div>
					<div class="cybevents-telemetry-footer">
						<span><?php esc_html_e( 'Max. Kapazität:', 'wm-cyber-events-core' ); ?> <strong><?php echo esc_html( number_format_i18n( $display_capacity ) ); ?></strong></span>
						<span><?php esc_html_e( 'Gebucht:', 'wm-cyber-events-core' ); ?> <strong><?php echo esc_html( number_format_i18n( $total_regs ) ); ?></strong></span>
					</div>
				</div>

				<!-- Widget 2: Live Check-In Counter & Area Sparkline -->
				<div class="cybevents-telemetry-card cybevents-telemetry-card--spark">
					<div class="cybevents-telemetry-header">
						<span class="cybevents-telemetry-title"><?php esc_html_e( 'Live Check-In Counter', 'wm-cyber-events-core' ); ?></span>
						<span class="cybevents-telemetry-subtag"><?php echo esc_html( $checkin_pct ); ?>% <?php esc_html_e( 'Anwesend', 'wm-cyber-events-core' ); ?></span>
					</div>
					<div class="cybevents-telemetry-main-num">
						<?php echo esc_html( number_format_i18n( $checked_in ) ); ?> <span class="cybevents-num-sub">/ <?php echo esc_html( number_format_i18n( $total_regs ) ); ?></span>
					</div>
					<div class="cybevents-telemetry-footer cybevents-tags-row">
						<span class="cybevents-mini-stat"><span class="dot dot--blue"></span> <?php esc_html_e( 'Registriert:', 'wm-cyber-events-core' ); ?> <?php echo esc_html( $total_regs ); ?></span>
						<span class="cybevents-mini-stat"><span class="dot dot--green"></span> <?php esc_html_e( 'Eingecheckt:', 'wm-cyber-events-core' ); ?> <?php echo esc_html( $checked_in ); ?></span>
						<span class="cybevents-mini-stat"><span class="dot dot--amber"></span> <?php esc_html_e( 'Wartend:', 'wm-cyber-events-core' ); ?> <?php echo esc_html( max( 0, $total_regs - $checked_in ) ); ?></span>
					</div>
				</div>

				<!-- Widget 3: registrations of the last 24 hours (measured; the card used to print an invented "145.8 ops/sek") -->
				<div class="cybevents-telemetry-card">
					<div class="cybevents-telemetry-header">
						<span class="cybevents-telemetry-title"><?php esc_html_e( 'Neue Anmeldungen (24 h)', 'wm-cyber-events-core' ); ?></span>
						<span class="cybevents-telemetry-subtag"><?php echo esc_html( number_format_i18n( $sessions_total ) ); ?> <?php esc_html_e( 'Sessions', 'wm-cyber-events-core' ); ?></span>
					</div>
					<div class="cybevents-telemetry-main-num">
						<?php echo esc_html( number_format_i18n( $regs_24h ) ); ?> <span class="cybevents-num-sub"><?php esc_html_e( 'in 24 h', 'wm-cyber-events-core' ); ?></span>
					</div>
					<div class="cybevents-telemetry-footer">
						<span><?php esc_html_e( 'Ausstehende DOI:', 'wm-cyber-events-core' ); ?> <strong><?php echo esc_html( number_format_i18n( $pending_doi ) ); ?></strong></span>
						<span><?php esc_html_e( 'Eingecheckt:', 'wm-cyber-events-core' ); ?> <strong class="cybevents-text-ok"><?php echo esc_html( number_format_i18n( $checked_in ) ); ?></strong></span>
					</div>
				</div>

				<!-- Widget 4: Brevo CRM Queue Telemetry -->
				<div class="cybevents-telemetry-card">
					<div class="cybevents-telemetry-header">
						<span class="cybevents-telemetry-title"><?php esc_html_e( 'Brevo CRM Queue Telemetrie', 'wm-cyber-events-core' ); ?></span>
						<span class="cybevents-telemetry-subtag"><?php echo esc_html( number_format_i18n( $queue_sent + $queue_pending ) ); ?> <?php esc_html_e( 'Jobs', 'wm-cyber-events-core' ); ?></span>
					</div>
					<div class="cybevents-telemetry-main-num">
						<?php echo esc_html( $queue_pct ); ?>% <span class="cybevents-num-sub"><?php esc_html_e( 'Verarbeitung', 'wm-cyber-events-core' ); ?></span>
					</div>
					<div class="cybevents-telemetry-footer cybevents-tags-row">
						<span class="cybevents-mini-stat"><span class="dot dot--blue"></span> <?php esc_html_e( 'Verarbeitet:', 'wm-cyber-events-core' ); ?> <?php echo esc_html( number_format_i18n( $queue_sent ) ); ?></span>
						<span class="cybevents-mini-stat"><span class="dot dot--amber"></span> <?php esc_html_e( 'Wartend:', 'wm-cyber-events-core' ); ?> <?php echo esc_html( number_format_i18n( $queue_pending ) ); ?></span>
					</div>
				</div>
			</div>

			<!-- Quick Actions Toolbar -->
			<div class="cybevents-admin-section cybevents-quick-actions-section">
				<div class="cybevents-section-header-compact">
					<h2><?php esc_html_e( 'Schnellaktionen', 'wm-cyber-events-core' ); ?></h2>
				</div>
				<div class="cybevents-quick-actions">
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=cybevent' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Neue Konferenz anlegen', 'wm-cyber-events-core' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=cybevent' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Alle Veranstaltungen', 'wm-cyber-events-core' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-registrations' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Teilnehmerliste & Check-in', 'wm-cyber-events-core' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-csv-import' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'CSV-Batch Import', 'wm-cyber-events-core' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-datev-export' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'DATEV-Export', 'wm-cyber-events-core' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-audit-log' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Audit-Protokoll', 'wm-cyber-events-core' ); ?>
					</a>
				</div>
			</div>

			<!-- Full-Width Main Section: Events Overview & Capacity Monitoring Table -->
			<div id="wm-sec-events" class="cybevents-admin-section cybevents-events-monitor-section">
				<div class="cybevents-section-header">
					<div>
						<h2><?php esc_html_e( 'Veranstaltungsübersicht & Auslastungs-Monitoring', 'wm-cyber-events-core' ); ?></h2>
						<p class="cybevents-section-sub"><?php esc_html_e( 'Bestätigte Anmeldungen je Veranstaltung im Verhältnis zur eingetragenen Kapazität.', 'wm-cyber-events-core' ); ?></p>
					</div>
					<div class="cybevents-header-controls">
						<input type="text" id="cybevents-event-search" class="cybevents-search-input" placeholder="<?php esc_attr_e( 'Event suchen...', 'wm-cyber-events-core' ); ?>" />
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=cybevent' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Neue Konferenz', 'wm-cyber-events-core' ); ?>
						</a>
					</div>
				</div>
				<div class="cybevents-table-wrap">
					<table class="cybevents-table" id="cybevents-events-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Konferenz / Event', 'wm-cyber-events-core' ); ?></th>
								<th><?php esc_html_e( 'Zeitraum', 'wm-cyber-events-core' ); ?></th>
								<th><?php esc_html_e( 'Ort', 'wm-cyber-events-core' ); ?></th>
								<th><?php esc_html_e( 'Sessions', 'wm-cyber-events-core' ); ?></th>
								<th><?php esc_html_e( 'Kapazität & Belegung', 'wm-cyber-events-core' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wm-cyber-events-core' ); ?></th>
								<th class="cybevents-th-actions"><?php esc_html_e( 'Aktionen', 'wm-cyber-events-core' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $events_list ) ) : ?>
								<tr><td colspan="7"><?php esc_html_e( 'Keine Veranstaltungen gefunden.', 'wm-cyber-events-core' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $events_list as $ev ) : ?>
									<?php
									$cap      = (int) $ev->max_capacity; // A capacity of 0 was displayed as 100 seats until 2026-09-15.
									$conf     = (int) $ev->confirmed_count;
									$pct      = $cap > 0 ? min( 100, round( ( $conf / $cap ) * 100 ) ) : 0;
									$fill_cls = $pct >= 90 ? 'cybevents-capacity-fill--full' : ( $pct >= 70 ? 'cybevents-capacity-fill--high' : '' );
									?>
									<tr class="cybevents-event-row">
										<td>
											<a href="<?php echo esc_url( $ev->edit_url ); ?>" class="cybevents-event-title-link" title="<?php esc_attr_e( 'Im Gutenberg Block-Editor bearbeiten', 'wm-cyber-events-core' ); ?>">
												<strong><?php echo esc_html( $ev->title ); ?></strong>
											</a>
											<div class="row-actions">
												<span class="edit"><a href="<?php echo esc_url( $ev->edit_url ); ?>"><?php esc_html_e( 'Bearbeiten', 'wm-cyber-events-core' ); ?></a> | </span>
												<span class="inline"><button type="button" class="button-link cybevents-btn-quick-edit" data-event="<?php echo esc_attr( wp_json_encode( $ev ) ); ?>"><?php esc_html_e( 'Schnellbearbeitung', 'wm-cyber-events-core' ); ?></button> | </span>
												<span class="view"><a href="<?php echo esc_url( $ev->view_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Vorschau', 'wm-cyber-events-core' ); ?></a> | </span>
												<span class="attendees"><a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-registrations&event_id=' . $ev->id ) ); ?>"><?php /* translators: %d: Total attendee count */ echo esc_html( sprintf( __( 'Teilnehmer (%d)', 'wm-cyber-events-core' ), (int) $ev->confirmed_count ) ); ?></a> | </span>
												<span class="delete"><a href="<?php echo esc_url( $ev->delete_url ); ?>" class="cybevents-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Möchten Sie diese Veranstaltung wirklich unwiderruflich löschen?', 'wm-cyber-events-core' ) ); ?>');"><?php esc_html_e( 'Löschen', 'wm-cyber-events-core' ); ?></a></span>
											</div>
										</td>
										<td><?php echo esc_html( gmdate( 'd.m.Y', strtotime( $ev->start_date ) ) ); ?></td>
										<td><?php echo esc_html( $ev->location ?: '—' ); ?></td>
										<td>
											<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=cybsession' ) ); ?>" class="cybevents-badge">
												<?php /* translators: %d: number of sessions */ echo esc_html( sprintf( __( '%d Sessions', 'wm-cyber-events-core' ), (int) $ev->session_count ) ); ?>
											</a>
										</td>
										<td>
											<div class="cybevents-capacity-meter">
												<div class="cybevents-capacity-text">
													<span><strong><?php echo esc_html( $conf ); ?></strong> <?php echo $cap > 0 ? esc_html( sprintf( /* translators: %d: capacity */ __( '/ %d Plätze', 'wm-cyber-events-core' ), $cap ) ) : esc_html__( '(ohne Kapazitätsgrenze)', 'wm-cyber-events-core' ); ?></span>
													<span><?php echo esc_html( $pct ); ?>%</span>
												</div>
												<div class="cybevents-capacity-bar">
													<div class="cybevents-capacity-fill <?php echo esc_attr( $fill_cls ); ?>" style="width: <?php echo esc_attr( $pct ); ?>%;"></div>
												</div>
											</div>
										</td>
										<td>
											<span class="cybevents-badge cybevents-badge--confirmed">
												● <?php echo esc_html( ucfirst( $ev->status ) ); ?>
											</span>
										</td>
										<td class="cybevents-td-actions">
											<div class="cybevents-action-buttons">
												<a href="<?php echo esc_url( $ev->edit_url ); ?>" class="button button-small button-primary" title="<?php esc_attr_e( 'Im Gutenberg Editor bearbeiten', 'wm-cyber-events-core' ); ?>"><?php esc_html_e( 'Editor', 'wm-cyber-events-core' ); ?></a>
												<button type="button" class="button button-small button-secondary cybevents-btn-quick-edit" data-event="<?php echo esc_attr( wp_json_encode( $ev ) ); ?>" title="<?php esc_attr_e( 'Schnellbearbeitung öffnen', 'wm-cyber-events-core' ); ?>"><?php esc_html_e( 'Quick', 'wm-cyber-events-core' ); ?></button>
												<a href="<?php echo esc_url( $ev->view_url ); ?>" target="_blank" rel="noopener" class="button button-small" title="<?php esc_attr_e( 'Frontend-Seite anzeigen', 'wm-cyber-events-core' ); ?>" aria-label="<?php esc_attr_e( 'Frontend-Seite anzeigen', 'wm-cyber-events-core' ); ?>"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></a>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Bottom 2-column grid: invoices and DATEV export -->
			<div id="wm-sec-invoices" class="cybevents-dashboard-bottom-grid">
				<!-- Card 1: invoices -->
				<div class="cybevents-admin-section">
					<h2><?php esc_html_e( 'Rechnungen', 'wm-cyber-events-core' ); ?></h2>
					<p class="cybevents-section-sub"><?php esc_html_e( 'Gültige und stornierte Rechnungen sowie unbestätigte Anmeldungen.', 'wm-cyber-events-core' ); ?></p>
					
					<div class="cybevents-donut-panel">
						<div class="cybevents-donut-chart-wrap">
							<svg class="cybevents-donut-svg" viewBox="0 0 100 100">
								<circle class="donut-bg" cx="50" cy="50" r="38" stroke-width="16" fill="transparent" />
								<circle class="donut-segment donut-segment--cyan" cx="50" cy="50" r="38" stroke-width="16" fill="transparent"
									stroke-dasharray="<?php echo esc_attr( (string) $seg_inv ); ?> 238" stroke-dashoffset="0" />
								<circle class="donut-segment donut-segment--rose" cx="50" cy="50" r="38" stroke-width="16" fill="transparent"
									stroke-dasharray="<?php echo esc_attr( (string) $seg_storno ); ?> 238" stroke-dashoffset="-<?php echo esc_attr( (string) $seg_inv ); ?>" />
								<circle class="donut-segment donut-segment--amber" cx="50" cy="50" r="38" stroke-width="16" fill="transparent"
									stroke-dasharray="<?php echo esc_attr( (string) $seg_pending ); ?> 238" stroke-dashoffset="-<?php echo esc_attr( (string) ( $seg_inv + $seg_storno ) ); ?>" />
							</svg>
							<div class="cybevents-donut-center">
								<span class="cybevents-donut-amount"><?php echo esc_html( number_format( $total_volume, 0, ',', '.' ) ); ?> €</span>
								<span class="cybevents-donut-sub"><?php esc_html_e( 'Brutto, gültige Rechnungen', 'wm-cyber-events-core' ); ?></span>
							</div>
						</div>
						<div class="cybevents-donut-legend">
							<div class="cybevents-legend-item">
								<span class="legend-color legend-color--cyan"></span>
								<span><?php esc_html_e( 'Gültige Rechnungen', 'wm-cyber-events-core' ); ?> (<?php echo esc_html( $total_inv ); ?>)</span>
							</div>
							<div class="cybevents-legend-item">
								<span class="legend-color legend-color--rose"></span>
								<span><?php esc_html_e( 'Storniert', 'wm-cyber-events-core' ); ?> (<?php echo esc_html( $total_storno ); ?>)</span>
							</div>
							<div class="cybevents-legend-item">
								<span class="legend-color legend-color--amber"></span>
								<span><?php esc_html_e( 'Unbestätigte Anmeldungen', 'wm-cyber-events-core' ); ?> (<?php echo esc_html( $pending_doi ); ?>)</span>
							</div>
						</div>
					</div>
				</div>

				<!-- Card 2: DATEV export and audit log -->
				<div class="cybevents-admin-section">
					<h2><?php esc_html_e( 'DATEV-Export & Audit-Log', 'wm-cyber-events-core' ); ?></h2>
					<p class="cybevents-section-sub"><?php esc_html_e( 'Buchungsstapel der Rechnungen und Gutschriften eines Zeitraums; Konten und Steuerschlüssel mit der Steuerberatung abstimmen.', 'wm-cyber-events-core' ); ?></p>
					
					<div class="cybevents-compliance-hub-content">
						<div class="cybevents-kpi-sub-grid">
							<div class="cybevents-fact-box">
								<span class="cybevents-fact-label"><?php esc_html_e( 'DATEV Format', 'wm-cyber-events-core' ); ?></span>
								<strong class="cybevents-fact-value"><?php esc_html_e( 'Kategorie 21 (v13)', 'wm-cyber-events-core' ); ?></strong>
								<span class="cybevents-fact-note cybevents-fact-note--ok"><?php esc_html_e( 'Windows-1252', 'wm-cyber-events-core' ); ?></span>
							</div>
							<div class="cybevents-fact-box">
								<span class="cybevents-fact-label"><?php esc_html_e( 'Audit-Log', 'wm-cyber-events-core' ); ?></span>
								<strong class="cybevents-fact-value"><?php esc_html_e( 'HMAC-SHA256-Kette', 'wm-cyber-events-core' ); ?></strong>
								<span class="cybevents-fact-note cybevents-fact-note--info"><?php esc_html_e( 'Pseudonymisierung nach 90 Tagen', 'wm-cyber-events-core' ); ?></span>
							</div>
						</div>

						<div class="cybevents-actions-row">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-datev-export' ) ); ?>" class="button button-primary">
								<?php esc_html_e( 'DATEV Export Studio öffnen', 'wm-cyber-events-core' ); ?>
							</a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-audit-log' ) ); ?>" class="button button-secondary">
								<?php esc_html_e( 'Audit-Protokoll einsehen', 'wm-cyber-events-core' ); ?>
							</a>
						</div>
					</div>
				</div>
			</div>

			<!-- Bottom section: latest audit log entries -->
			<div id="wm-sec-audit" class="cybevents-admin-section">
				<div class="cybevents-section-header">
					<div>
						<h2><?php esc_html_e( 'Audit-Protokoll', 'wm-cyber-events-core' ); ?></h2>
						<p class="cybevents-section-sub"><?php esc_html_e( 'Fortlaufend verkettetes Audit-Log: jeder Eintrag signiert seinen Vorgänger mit, sodass nachträgliche Änderungen, Löschungen und Einschleusungen auffallen. Manipulationsnachweis, keine Unveränderbarkeit — wer die wp-config.php liest, hält den Schlüssel.', 'wm-cyber-events-core' ); ?></p>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-audit-log' ) ); ?>" class="button button-secondary">
						<?php esc_html_e( 'Vollständiges Audit-Log öffnen', 'wm-cyber-events-core' ); ?>
					</a>
				</div>
				<div class="cybevents-table-wrap">
					<table class="cybevents-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Zeitstempel', 'wm-cyber-events-core' ); ?></th>
								<th><?php esc_html_e( 'Ereignis-Typ', 'wm-cyber-events-core' ); ?></th>
								<th><?php esc_html_e( 'Benutzer / System', 'wm-cyber-events-core' ); ?></th>
								<th><?php esc_html_e( 'Objekt-ID', 'wm-cyber-events-core' ); ?></th>
								<th><?php esc_html_e( 'Audit-Details & Nachweis', 'wm-cyber-events-core' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $recent_audits ) ) : ?>
								<tr><td colspan="5"><?php esc_html_e( 'Bislang keine Audit-Einträge protokolliert.', 'wm-cyber-events-core' ); ?></td></tr>
							<?php else : ?>
								<?php foreach ( $recent_audits as $log ) : ?>
									<tr>
										<td><code><?php echo esc_html( $log->created_at ); ?></code></td>
										<td><span class="cybevents-badge cybevents-badge--audit">● <?php echo esc_html( $log->event_type ); ?></span></td>
										<td><?php echo esc_html( $log->user_id ? 'Admin (#' . $log->user_id . ')' : 'System' ); ?></td>
										<td>#<?php echo esc_html( $log->object_id ); ?></td>
										<td><?php echo esc_html( $log->details ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Interactive Event Quick-Edit Modal -->
			<div class="cybevents-modal-backdrop" id="cybevents-event-edit-modal" role="dialog" aria-modal="true" aria-labelledby="cybevents-edit-modal-title">
				<div class="cybevents-modal-dialog">
					<div class="cybevents-modal-header">
						<h3 id="cybevents-edit-modal-title"><?php esc_html_e( 'Veranstaltung bearbeiten / Schnellkonfiguration', 'wm-cyber-events-core' ); ?></h3>
						<button type="button" class="cybevents-modal-close" id="cybevents-event-edit-close" aria-label="<?php esc_attr_e( 'Schließen', 'wm-cyber-events-core' ); ?>">&times;</button>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-dashboard' ) ); ?>">
						<?php wp_nonce_field( 'cybevents_save_event_action', 'cybevents_save_event_nonce' ); ?>
						<input type="hidden" name="event_id" id="edit_event_id" value="0" />
						<input type="hidden" name="wp_post_id" id="edit_wp_post_id" value="0" />
						<div class="cybevents-modal-body">
							<div class="cybevents-modal-form-grid">
								<div class="cybevents-field--full">
									<label for="edit_event_title" class="cybevents-field-label"><?php esc_html_e( 'Titel der Konferenz / Veranstaltung:', 'wm-cyber-events-core' ); ?></label>
									<input type="text" id="edit_event_title" name="title" required class="widefat" />
								</div>
								<div class="cybevents-field--full">
									<label for="edit_event_slug" class="cybevents-field-label"><?php esc_html_e( 'URL-Slug:', 'wm-cyber-events-core' ); ?></label>
									<input type="text" id="edit_event_slug" name="slug" class="widefat" />
								</div>
								<div class="cybevents-field--full">
									<label for="edit_event_location" class="cybevents-field-label"><?php esc_html_e( 'Tagungsort / Location:', 'wm-cyber-events-core' ); ?></label>
									<input type="text" id="edit_event_location" name="location" class="widefat" />
								</div>
								<div>
									<label for="edit_event_start_date" class="cybevents-field-label"><?php esc_html_e( 'Beginn (Datum & Zeit):', 'wm-cyber-events-core' ); ?></label>
									<input type="datetime-local" id="edit_event_start_date" name="start_date" required class="widefat" />
								</div>
								<div>
									<label for="edit_event_end_date" class="cybevents-field-label"><?php esc_html_e( 'Ende (Datum & Zeit):', 'wm-cyber-events-core' ); ?></label>
									<input type="datetime-local" id="edit_event_end_date" name="end_date" required class="widefat" />
								</div>
								<div>
									<label for="edit_event_max_capacity" class="cybevents-field-label"><?php esc_html_e( 'Max. Teilnehmerkapazität:', 'wm-cyber-events-core' ); ?></label>
									<input type="number" id="edit_event_max_capacity" name="max_capacity" min="1" max="10000" class="widefat" />
								</div>
								<div>
									<label for="edit_event_ticket_price" class="cybevents-field-label"><?php esc_html_e( 'Ticketpreis (Netto in €):', 'wm-cyber-events-core' ); ?></label>
									<input type="number" step="0.01" id="edit_event_ticket_price" name="ticket_price" class="widefat" />
								</div>
								<div>
									<label for="edit_event_status" class="cybevents-field-label"><?php esc_html_e( 'Status:', 'wm-cyber-events-core' ); ?></label>
									<select id="edit_event_status" name="status" class="widefat">
										<option value="active"><?php esc_html_e( 'Aktiv (Veröffentlicht)', 'wm-cyber-events-core' ); ?></option>
										<option value="draft"><?php esc_html_e( 'Entwurf (Draft)', 'wm-cyber-events-core' ); ?></option>
										<option value="archived"><?php esc_html_e( 'Archiviert', 'wm-cyber-events-core' ); ?></option>
									</select>
								</div>
								<div class="cybevents-field--inline">
									<label class="cybevents-check-label">
										<input type="checkbox" id="edit_event_is_paid" name="is_paid" value="1" />
										<?php esc_html_e( 'Kostenpflichtig', 'wm-cyber-events-core' ); ?>
									</label>
								</div>
							</div>
						</div>
						<div class="cybevents-modal-footer cybevents-modal-footer--split">
							<a href="#" id="edit_event_gutenberg_link" class="button button-link cybevents-link-inline"><?php esc_html_e( 'Im Gutenberg Block-Editor öffnen', 'wm-cyber-events-core' ); ?></a>
							<div class="cybevents-modal-footer-actions">
								<button type="button" class="button button-secondary" id="cybevents-event-edit-cancel"><?php esc_html_e( 'Abbrechen', 'wm-cyber-events-core' ); ?></button>
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Änderungen speichern', 'wm-cyber-events-core' ); ?></button>
							</div>
						</div>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Attendees / Registrations Studio Page.
	 */
	public static function render_registrations_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-cyber-events-core' ) );
		}

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible" style="margin-top: 15px;"><p><strong>' . esc_html__( 'Teilnehmerdaten erfolgreich aktualisiert!', 'wm-cyber-events-core' ) . '</strong> ' . esc_html__( 'Die Registrierungsdaten und der Status wurden in der Datenbank gespeichert.', 'wm-cyber-events-core' ) . '</p></div>';
		}
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible" style="margin-top: 15px;"><p><strong>' . esc_html__( 'Teilnehmer gelöscht!', 'wm-cyber-events-core' ) . '</strong> ' . esc_html__( 'Die Registrierung und alle zugehörigen Session-Buchungen wurden entfernt.', 'wm-cyber-events-core' ) . '</p></div>';
		}
		if ( isset( $_GET['checkin_toggled'] ) ) {
			echo '<div class="notice notice-success is-dismissible" style="margin-top: 15px;"><p><strong>' . esc_html__( 'Check-In Status aktualisiert!', 'wm-cyber-events-core' ) . '</strong> ' . esc_html__( 'Der Anwesenheitsstatus des Teilnehmers wurde umgeschaltet.', 'wm-cyber-events-core' ) . '</p></div>';
		}
		if ( isset( $_GET['doi_confirmed'] ) ) {
			echo '<div class="notice notice-success is-dismissible" style="margin-top: 15px;"><p><strong>' . esc_html__( 'Double Opt-In bestätigt!', 'wm-cyber-events-core' ) . '</strong> ' . esc_html__( 'Die Registrierung wurde manuell als bestätigt markiert.', 'wm-cyber-events-core' ) . '</p></div>';
		}

		global $wpdb;

		$filter_event_id    = absint( $_GET['event_id'] ?? 0 );
		$filter_event_title = '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_events = $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM {$wpdb->prefix}cybevents_events WHERE id > %d ORDER BY title ASC", 0 ) );

		if ( $filter_event_id ) {
			$filter_event_title = $wpdb->get_var( $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}cybevents_events WHERE id = %d", $filter_event_id ) );
			$records            = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.*, e.title as event_title, i.zugferd_xml, i.gross_amount,
					(SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registration_sessions s WHERE s.registration_id = r.id) as session_count
					FROM {$wpdb->prefix}cybevents_registrations r
					LEFT JOIN {$wpdb->prefix}cybevents_events e ON e.id = r.event_id
					LEFT JOIN {$wpdb->prefix}cybevents_invoices i ON i.invoice_number = r.invoice_number
					WHERE r.event_id = %d
					ORDER BY r.id DESC LIMIT 200",
					$filter_event_id
				)
			);
		} else {
			$records = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.*, e.title as event_title, i.zugferd_xml, i.gross_amount,
					(SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registration_sessions s WHERE s.registration_id = r.id) as session_count
					FROM {$wpdb->prefix}cybevents_registrations r
					LEFT JOIN {$wpdb->prefix}cybevents_events e ON e.id = r.event_id
					LEFT JOIN {$wpdb->prefix}cybevents_invoices i ON i.invoice_number = r.invoice_number
					ORDER BY r.id DESC LIMIT %d",
					200
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$export_url = wp_nonce_url( admin_url( 'admin.php?page=cybevents-registrations&action=export_csv' ), 'cybevents_export_csv' );
		?>
		<div class="wrap wm-admin cybevents-admin-wrap">
			<header class="wm-admin-header cybevents-enterprise-banner">
				<div class="cybevents-banner-content">
					<div class="cybevents-banner-title">
						<h1>
							<span class="dashicons dashicons-tickets-alt cybevents-banner-icon" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Teilnehmer & Buchungsmanagement', 'wm-cyber-events-core' ); ?></span>
						</h1>
						<p><?php esc_html_e( 'Alle Anmeldungen mit Double-Opt-in-Status, Session-Buchungen und Rechnungen.', 'wm-cyber-events-core' ); ?></p>
					</div>
					<div>
						<button type="button" id="cybevents-btn-open-export" class="cybevents-btn-enterprise">
							<?php esc_html_e( 'Namensschilder / Teilnehmerliste (CSV)', 'wm-cyber-events-core' ); ?>
						</button>
					</div>
					<div class="cybevents-banner-actions">
						<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-cyber-events-core' ); ?>">
							<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
							<?php esc_html_e( 'WM Suite', 'wm-cyber-events-core' ); ?>
							<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-cyber-events-core' ) : esc_html__( 'Standalone', 'wm-cyber-events-core' ); ?></span>
						</span>
					</div>
				</div>
			</header>

			<?php self::render_nav_tabs( 'cybevents-registrations' ); ?>

			<?php if ( $filter_event_id && $filter_event_title ) : ?>
				<div class="cybevents-filter-notice">
					<span><?php /* translators: 1: Event title, 2: Event ID */ echo esc_html( sprintf( __( 'Gefiltert nach Konferenz: "%1$s" (#%2$d)', 'wm-cyber-events-core' ), $filter_event_title, $filter_event_id ) ); ?></span>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-registrations' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Filter aufheben', 'wm-cyber-events-core' ); ?></a>
				</div>
			<?php endif; ?>

			<!-- Filter & Live Search Bar -->
			<div class="cybevents-filter-bar">
				<div class="cybevents-filter-pills">
					<button class="cybevents-filter-pill is-active" data-status="all"><?php esc_html_e( 'Alle Teilnehmer', 'wm-cyber-events-core' ); ?></button>
					<button class="cybevents-filter-pill" data-status="confirmed"><?php esc_html_e( 'Bestätigt', 'wm-cyber-events-core' ); ?></button>
					<button class="cybevents-filter-pill" data-status="pending_doi"><?php esc_html_e( 'Ausstehend DOI', 'wm-cyber-events-core' ); ?></button>
					<button class="cybevents-filter-pill" data-status="cancelled"><?php esc_html_e( 'Storniert', 'wm-cyber-events-core' ); ?></button>
				</div>
				<div class="cybevents-search-box">
					<span class="dashicons dashicons-search cybevents-search-icon" aria-hidden="true"></span>
					<input type="text" id="cybevents-table-search" class="cybevents-search-input" placeholder="<?php esc_attr_e( 'Name, E-Mail oder Firma suchen...', 'wm-cyber-events-core' ); ?>" />
				</div>
			</div>

			<!-- Enterprise Table -->
			<div class="cybevents-table-wrap">
				<table class="cybevents-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Teilnehmer', 'wm-cyber-events-core' ); ?></th>
							<th><?php esc_html_e( 'Organisation / Firma', 'wm-cyber-events-core' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wm-cyber-events-core' ); ?></th>
							<th><?php esc_html_e( 'Check-In', 'wm-cyber-events-core' ); ?></th>
							<th><?php esc_html_e( 'Sessions', 'wm-cyber-events-core' ); ?></th>
							<th><?php esc_html_e( 'Rechnung', 'wm-cyber-events-core' ); ?></th>
							<th><?php esc_html_e( 'Anmeldedatum', 'wm-cyber-events-core' ); ?></th>
							<th class="cybevents-th-actions"><?php esc_html_e( 'Aktionen', 'wm-cyber-events-core' ); ?></th>
						</tr>
					</thead>
					<tbody class="cybevents-table-body">
						<?php if ( empty( $records ) ) : ?>
							<tr><td colspan="8"><?php esc_html_e( 'Keine Registrierungen vorhanden.', 'wm-cyber-events-core' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $records as $r ) : ?>
								<?php
								// The row is embedded as JSON for the edit modal; the token hashes do not belong in the DOM.
								unset( $r->token_hash, $r->doi_token_hash );
								$initials           = strtoupper( mb_substr( $r->first_name, 0, 1 ) . mb_substr( $r->last_name, 0, 1 ) );
								$toggle_checkin_url = wp_nonce_url(
									admin_url( 'admin.php?page=cybevents-registrations&action=toggle_checkin&reg_id=' . $r->id . ( $filter_event_id ? '&event_id=' . $filter_event_id : '' ) ),
									'cybevents_toggle_checkin_' . $r->id
								);
								$delete_reg_url     = wp_nonce_url(
									admin_url( 'admin.php?page=cybevents-registrations&action=delete_reg&reg_id=' . $r->id . ( $filter_event_id ? '&event_id=' . $filter_event_id : '' ) ),
									'cybevents_delete_reg_' . $r->id
								);
								$confirm_doi_url    = wp_nonce_url(
									admin_url( 'admin.php?page=cybevents-registrations&action=confirm_doi&reg_id=' . $r->id . ( $filter_event_id ? '&event_id=' . $filter_event_id : '' ) ),
									'cybevents_confirm_doi_' . $r->id
								);
								?>
								<tr data-status="<?php echo esc_attr( $r->status ); ?>">
									<td>
										<div class="cybevents-user-cell">
											<div class="cybevents-avatar">
												<?php if ( ! empty( $r->avatar_url ) ) : ?>
													<img alt="<?php echo esc_attr( trim( $r->first_name . ' ' . $r->last_name ) ); ?>" src="<?php echo esc_url( $r->avatar_url ); ?>" />
												<?php else : ?>
													<span><?php echo esc_html( $initials ); ?></span>
												<?php endif; ?>
											</div>
											<div class="cybevents-user-info">
												<a href="#" class="cybevents-event-title-link cybevents-btn-edit-registration" data-registration="<?php echo esc_attr( wp_json_encode( $r ) ); ?>" title="<?php esc_attr_e( 'Teilnehmerdaten bearbeiten', 'wm-cyber-events-core' ); ?>">
													<strong><?php echo esc_html( $r->first_name . ' ' . $r->last_name ); ?></strong>
												</a>
												<span><?php echo esc_html( $r->email ); ?></span>
												<div class="row-actions">
													<span class="edit"><button type="button" class="button-link cybevents-btn-edit-registration" data-registration="<?php echo esc_attr( wp_json_encode( $r ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'wm-cyber-events-core' ); ?></button> | </span>
													<span class="checkin"><a href="<?php echo esc_url( $toggle_checkin_url ); ?>"><?php echo 1 === (int) $r->checked_in ? esc_html__( 'Auschecken', 'wm-cyber-events-core' ) : esc_html__( 'Einchecken', 'wm-cyber-events-core' ); ?></a> | </span>
													<?php if ( 'pending_doi' === $r->status ) : ?>
														<span class="doi"><a href="<?php echo esc_url( $confirm_doi_url ); ?>"><?php esc_html_e( 'DOI bestätigen', 'wm-cyber-events-core' ); ?></a> | </span>
													<?php endif; ?>
													<span class="delete"><a href="<?php echo esc_url( $delete_reg_url ); ?>" class="cybevents-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Möchten Sie diesen Teilnehmer wirklich unwiderruflich löschen?', 'wm-cyber-events-core' ) ); ?>');"><?php esc_html_e( 'Löschen', 'wm-cyber-events-core' ); ?></a></span>
												</div>
											</div>
										</div>
									</td>
									<td>
										<strong><?php echo esc_html( $r->company ?: '—' ); ?></strong>
										<?php if ( ! empty( $r->event_title ) ) : ?>
											<div class="cybevents-cell-note">
												<?php echo esc_html( $r->event_title ); ?>
											</div>
										<?php endif; ?>
									</td>
									<td>
										<span class="cybevents-badge cybevents-badge--<?php echo esc_attr( $r->status ); ?>">
											<?php echo esc_html( $r->status ); ?>
										</span>
									</td>
									<td>
										<a href="<?php echo esc_url( $toggle_checkin_url ); ?>" title="<?php esc_attr_e( 'Status umschalten', 'wm-cyber-events-core' ); ?>">
											<?php if ( 1 === (int) $r->checked_in ) : ?>
												<span class="cybevents-badge cybevents-badge--confirmed" title="<?php echo esc_attr( $r->checked_in_at ); ?>">
													<?php esc_html_e( 'Eingecheckt', 'wm-cyber-events-core' ); ?>
												</span>
											<?php else : ?>
												<span class="cybevents-badge cybevents-badge--free">
													<?php esc_html_e( 'Nicht vor Ort', 'wm-cyber-events-core' ); ?>
												</span>
											<?php endif; ?>
										</a>
									</td>
									<td>
										<span class="cybevents-badge cybevents-badge--free">
											<?php echo esc_html( $r->session_count ); ?> gebucht
										</span>
									</td>
									<td>
										<?php if ( ! empty( $r->invoice_number ) ) : ?>
											<div>
												<strong><?php echo esc_html( $r->invoice_number ); ?></strong>
												<?php if ( ! empty( $r->gross_amount ) ) : ?>
													<div class="cybevents-amount-ok"><?php echo esc_html( number_format( (float) $r->gross_amount, 2, ',', '.' ) ); ?> € (<?php echo esc_html( $r->payment_status ); ?>)</div>
												<?php endif; ?>
												<?php if ( ! empty( $r->zugferd_xml ) ) : ?>
													<button type="button" class="cybevents-btn-view-xml button button-small" data-xml="<?php echo esc_attr( $r->zugferd_xml ); ?>">
														<?php esc_html_e( 'Rechnungs-XML', 'wm-cyber-events-core' ); ?>
													</button>
												<?php endif; ?>
											</div>
										<?php else : ?>
											<span class="cybevents-cell-note"><?php esc_html_e( 'Kostenfrei / Keine', 'wm-cyber-events-core' ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( gmdate( 'd.m.Y H:i', strtotime( $r->created_at ) ) ); ?></td>
									<td class="cybevents-td-actions">
										<div class="cybevents-action-buttons">
											<button type="button" class="button button-small cybevents-btn-edit-registration" data-registration="<?php echo esc_attr( wp_json_encode( $r ) ); ?>" title="<?php esc_attr_e( 'Bearbeiten', 'wm-cyber-events-core' ); ?>">
												<?php esc_html_e( 'Edit', 'wm-cyber-events-core' ); ?>
											</button>
											<a href="<?php echo esc_url( $toggle_checkin_url ); ?>" class="button button-small" title="<?php echo 1 === (int) $r->checked_in ? esc_attr__( 'Auschecken', 'wm-cyber-events-core' ) : esc_attr__( 'Einchecken', 'wm-cyber-events-core' ); ?>"><span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span><span class="screen-reader-text"><?php echo 1 === (int) $r->checked_in ? esc_html__( 'Auschecken', 'wm-cyber-events-core' ) : esc_html__( 'Einchecken', 'wm-cyber-events-core' ); ?></span></a>
											<a href="<?php echo esc_url( $delete_reg_url ); ?>" class="button button-small cybevents-btn-danger-text" title="<?php esc_attr_e( 'Löschen', 'wm-cyber-events-core' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Möchten Sie diesen Teilnehmer wirklich löschen?', 'wm-cyber-events-core' ) ); ?>');"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Löschen', 'wm-cyber-events-core' ); ?></span></a>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<!-- Interactive Attendee Edit Modal -->
			<div class="cybevents-modal-backdrop" id="cybevents-registration-edit-modal" role="dialog" aria-modal="true" aria-labelledby="cybevents-reg-edit-modal-title">
				<div class="cybevents-modal-dialog">
					<div class="cybevents-modal-header">
						<h3 id="cybevents-reg-edit-modal-title"><?php esc_html_e( 'Teilnehmerdaten bearbeiten / Teilnehmerverwaltung', 'wm-cyber-events-core' ); ?></h3>
						<button type="button" class="cybevents-modal-close" id="cybevents-reg-edit-close" aria-label="<?php esc_attr_e( 'Schließen', 'wm-cyber-events-core' ); ?>">&times;</button>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=cybevents-registrations' ) ); ?>" enctype="multipart/form-data">
						<?php wp_nonce_field( 'cybevents_save_registration_action', 'cybevents_save_registration_nonce' ); ?>
						<input type="hidden" name="reg_id" id="edit_reg_id" value="0" />
						<input type="hidden" name="filter_event_id" value="<?php echo esc_attr( $filter_event_id ); ?>" />
						<div class="cybevents-modal-body">
							<div class="cybevents-modal-form-grid">
								<div class="cybevents-avatar-box">
									<div id="edit_reg_avatar_preview" class="cybevents-avatar cybevents-avatar--lg">
										<img id="edit_reg_avatar_img" src="" alt="" style="display: none;" />
										<span id="edit_reg_avatar_initials">?</span>
									</div>
									<div>
										<label for="edit_reg_avatar_file" class="cybevents-field-label"><?php esc_html_e( 'Profilfoto / Teilnehmer-Passbild (JPG, PNG, WebP · max. 5 MB):', 'wm-cyber-events-core' ); ?></label>
										<div class="cybevents-avatar-controls">
											<input type="file" id="edit_reg_avatar_file" name="avatar" accept="image/jpeg,image/png,image/webp" />
											<input type="hidden" id="edit_reg_avatar_url" name="avatar_url" value="" />
											<input type="hidden" id="edit_reg_delete_avatar" name="delete_avatar" value="0" />
											<button type="button" id="edit_reg_btn_delete_avatar" class="button button-link-delete cybevents-btn-danger-text" style="display: none;"><?php esc_html_e( 'Foto entfernen', 'wm-cyber-events-core' ); ?></button>
										</div>
									</div>
								</div>
								<div>
									<label for="edit_reg_first_name" class="cybevents-field-label"><?php esc_html_e( 'Vorname:', 'wm-cyber-events-core' ); ?></label>
									<input type="text" id="edit_reg_first_name" name="first_name" required class="widefat" />
								</div>
								<div>
									<label for="edit_reg_last_name" class="cybevents-field-label"><?php esc_html_e( 'Nachname:', 'wm-cyber-events-core' ); ?></label>
									<input type="text" id="edit_reg_last_name" name="last_name" required class="widefat" />
								</div>
								<div class="cybevents-field--full">
									<label for="edit_reg_email" class="cybevents-field-label"><?php esc_html_e( 'E-Mail-Adresse:', 'wm-cyber-events-core' ); ?></label>
									<input type="email" id="edit_reg_email" name="email" required class="widefat" />
								</div>
								<div class="cybevents-field--full">
									<label for="edit_reg_company" class="cybevents-field-label"><?php esc_html_e( 'Organisation / Firma / Behörde:', 'wm-cyber-events-core' ); ?></label>
									<input type="text" id="edit_reg_company" name="company" class="widefat" />
								</div>
								<div class="cybevents-field--full">
									<label for="edit_reg_event_id" class="cybevents-field-label"><?php esc_html_e( 'Zugewiesene Konferenz:', 'wm-cyber-events-core' ); ?></label>
									<select id="edit_reg_event_id" name="event_id" class="widefat">
										<?php if ( ! empty( $all_events ) ) : ?>
											<?php foreach ( $all_events as $ev_item ) : ?>
												<option value="<?php echo esc_attr( $ev_item->id ); ?>"><?php echo esc_html( $ev_item->title ); ?> (#<?php echo esc_html( $ev_item->id ); ?>)</option>
											<?php endforeach; ?>
										<?php endif; ?>
									</select>
								</div>
								<div>
									<label for="edit_reg_status" class="cybevents-field-label"><?php esc_html_e( 'Anmeldestatus:', 'wm-cyber-events-core' ); ?></label>
									<select id="edit_reg_status" name="status" class="widefat">
										<option value="confirmed"><?php esc_html_e( 'Bestätigt (Confirmed)', 'wm-cyber-events-core' ); ?></option>
										<option value="pending_doi"><?php esc_html_e( 'Ausstehend (Pending DOI)', 'wm-cyber-events-core' ); ?></option>
										<option value="cancelled"><?php esc_html_e( 'Storniert (Cancelled)', 'wm-cyber-events-core' ); ?></option>
										<option value="bounced"><?php esc_html_e( 'Unzustellbar (Bounced)', 'wm-cyber-events-core' ); ?></option>
									</select>
								</div>
								<div>
									<label for="edit_reg_checked_in" class="cybevents-field-label"><?php esc_html_e( 'Check-In Status (Vor Ort):', 'wm-cyber-events-core' ); ?></label>
									<select id="edit_reg_checked_in" name="checked_in" class="widefat">
										<option value="0"><?php esc_html_e( 'Nein / Nicht vor Ort', 'wm-cyber-events-core' ); ?></option>
										<option value="1"><?php esc_html_e( 'Ja / Eingecheckt', 'wm-cyber-events-core' ); ?></option>
									</select>
								</div>
								<div>
									<label for="edit_reg_invoice_number" class="cybevents-field-label"><?php esc_html_e( 'Rechnungsnummer:', 'wm-cyber-events-core' ); ?></label>
									<input type="text" id="edit_reg_invoice_number" name="invoice_number" class="widefat" />
								</div>
								<div>
									<label for="edit_reg_payment_status" class="cybevents-field-label"><?php esc_html_e( 'Zahlungsstatus:', 'wm-cyber-events-core' ); ?></label>
									<select id="edit_reg_payment_status" name="payment_status" class="widefat">
										<option value="free"><?php esc_html_e( 'Kostenfrei (Free)', 'wm-cyber-events-core' ); ?></option>
										<option value="pending"><?php esc_html_e( 'Ausstehend (Pending)', 'wm-cyber-events-core' ); ?></option>
										<option value="paid"><?php esc_html_e( 'Bezahlt (Paid)', 'wm-cyber-events-core' ); ?></option>
										<option value="refunded"><?php esc_html_e( 'Erstattet (Refunded)', 'wm-cyber-events-core' ); ?></option>
									</select>
								</div>
								<div class="cybevents-check-row">
									<label class="cybevents-check-label">
										<input type="checkbox" id="edit_reg_consent_privacy" name="consent_privacy" value="1" />
										<?php esc_html_e( 'DSGVO-Datenschutz zugestimmt', 'wm-cyber-events-core' ); ?>
									</label>
									<label class="cybevents-check-label">
										<input type="checkbox" id="edit_reg_consent_photo" name="consent_photo" value="1" />
										<?php esc_html_e( 'Foto- & Videoaufnahmen zugestimmt', 'wm-cyber-events-core' ); ?>
									</label>
								</div>
							</div>
						</div>
						<div class="cybevents-modal-footer">
							<button type="button" class="button button-secondary" id="cybevents-reg-edit-cancel"><?php esc_html_e( 'Abbrechen', 'wm-cyber-events-core' ); ?></button>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Änderungen speichern', 'wm-cyber-events-core' ); ?></button>
						</div>
					</form>
				</div>
			</div>

			<!-- DSGVO Compliance Export Confirmation Modal -->
			<div id="cybevents-export-modal" class="cybevents-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="cybevents-export-modal-title">
				<div class="cybevents-modal">
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="page" value="cybevents-registrations" />
						<input type="hidden" name="action" value="export_csv" />
						<?php wp_nonce_field( 'cybevents_export_csv' ); ?>

						<div class="cybevents-modal-header">
							<h3 id="cybevents-export-modal-title"><?php esc_html_e( 'DSGVO-Datenschutz & Export-Einverständnis', 'wm-cyber-events-core' ); ?></h3>
							<button type="button" id="cybevents-export-modal-close" class="cybevents-modal-close" aria-label="<?php esc_attr_e( 'Schließen', 'wm-cyber-events-core' ); ?>">&times;</button>
						</div>

						<div class="cybevents-modal-body-html">
							<div class="cybevents-dsgvo-notice-box">
								<div class="cybevents-dsgvo-notice-title">
									<span class="dashicons dashicons-warning" aria-hidden="true"></span>
									<span><?php esc_html_e( 'Schutz personenbezogener Daten (Art. 4 & 5 DSGVO)', 'wm-cyber-events-core' ); ?></span>
								</div>
								<p><?php esc_html_e( 'Dieser Export generiert eine CSV-Datei mit schutzwürdigen personenbezogenen Teilnehmerdaten (Vollständige Namen, Firmen, geschäftliche E-Mail-Adressen, Rechnungsreferenzen und Check-In-Status).', 'wm-cyber-events-core' ); ?></p>
								<ul class="cybevents-dsgvo-list">
									<li><strong><?php esc_html_e( 'Zweckbindung (Art. 5 Abs. 1 lit. b DSGVO):', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Die Daten dürfen ausschließlich zur operativen Durchführung der Veranstaltung (z. B. Namensschilddruck, Akkreditierung am Einlass) genutzt werden.', 'wm-cyber-events-core' ); ?></li>
									<li><strong><?php esc_html_e( 'Vertraulichkeit & Schutz (Art. 5 Abs. 1 lit. f DSGVO):', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Eine Weitergabe an unbefugte Dritte oder Speicherung auf unverschlüsselten Mobilgeräten ist untersagt.', 'wm-cyber-events-core' ); ?></li>
									<li><strong><?php esc_html_e( 'Löschpflicht (Art. 17 DSGVO):', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Nach Abschluss der Konferenz ist die exportierte lokale Datei unverzüglich und datenschutzkonform zu löschen.', 'wm-cyber-events-core' ); ?></li>
								</ul>
							</div>

							<label class="cybevents-dsgvo-checkbox-wrap" for="cybevents-dsgvo-export-consent">
								<input type="checkbox" id="cybevents-dsgvo-export-consent" name="dsgvo_consent" value="1" required />
								<span class="cybevents-dsgvo-checkbox-label">
									<strong><?php esc_html_e( 'Ich bestätige das DSGVO-Einverständnis:', 'wm-cyber-events-core' ); ?></strong>
									<?php esc_html_e( 'Ich nehme zur Kenntnis, dass dieser Export vertrauliche personenbezogene Daten enthält. Ich verpflichte mich zur zweckgebundenen, sorgfältigen Verarbeitung und zur strikten Einhaltung der gesetzlichen Datenschutzanforderungen.', 'wm-cyber-events-core' ); ?>
								</span>
							</label>
						</div>

						<div class="cybevents-modal-footer">
							<button type="button" id="cybevents-btn-cancel-export" class="cybevents-btn-secondary">
								<?php esc_html_e( 'Abbrechen', 'wm-cyber-events-core' ); ?>
							</button>
							<button type="submit" id="cybevents-btn-confirm-export" class="cybevents-btn-enterprise" disabled>
								<?php esc_html_e( 'CSV-Export jetzt sicher herunterladen', 'wm-cyber-events-core' ); ?>
							</button>
						</div>
					</form>
				</div>
			</div>

			<!-- Invoice XML modal viewer -->
			<div id="cybevents-xml-modal" class="cybevents-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="cybevents-xml-modal-title">
				<div class="cybevents-modal">
					<div class="cybevents-modal-header">
						<h3 id="cybevents-xml-modal-title"><?php esc_html_e( 'Rechnungs-XML (CII, EN 16931)', 'wm-cyber-events-core' ); ?></h3>
						<button type="button" id="cybevents-xml-modal-close" class="cybevents-modal-close" aria-label="<?php esc_attr_e( 'Schließen', 'wm-cyber-events-core' ); ?>">&times;</button>
					</div>
					<pre id="cybevents-xml-content" class="cybevents-modal-body"></pre>
					<div class="cybevents-modal-footer">
						<button type="button" id="cybevents-btn-copy-xml" class="cybevents-btn-enterprise">
							<?php esc_html_e( 'XML in Zwischenablage kopieren', 'wm-cyber-events-core' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Export attendee list as CSV for Badges / Sign-in desks (Neutralizes Formula Injection CWE-1236).
	 */
	public static function export_attendees_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'wm-cyber-events-core' ) );
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT id, first_name, last_name, company, email, status, invoice_number, checked_in FROM {$wpdb->prefix}cybevents_registrations WHERE status = %s ORDER BY last_name ASC", 'confirmed' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=teilnehmer-' . gmdate( 'Y-m-d' ) . '.csv' ); // Named a client until 2026-09-15.
		header( 'X-Content-Type-Options: nosniff' );

		$output = fopen( 'php://output', 'w' );
		// Enclosure and escape explicit (PHP 8.4 deprecates the implicit backslash escape; empty = RFC 4180).
		fputcsv( $output, array( 'ID', 'Vorname', 'Nachname', 'Firma', 'E-Mail', 'Status', 'Rechnungsnummer', 'Eingecheckt' ), ';', '"', '' );

		foreach ( $results as $row ) {
			fputcsv(
				$output,
				array(
					(int) $row->id,
					self::sanitize_csv_cell( $row->first_name ),
					self::sanitize_csv_cell( $row->last_name ),
					self::sanitize_csv_cell( $row->company ),
					self::sanitize_csv_cell( $row->email ),
					self::sanitize_csv_cell( $row->status ),
					self::sanitize_csv_cell( $row->invoice_number ),
					(int) $row->checked_in === 1 ? 'JA' : 'NEIN',
				),
				';',
				'"',
				''
			);
		}
		// php://output is the HTTP response stream, not the filesystem; WP_Filesystem does not apply.
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * CSV Import Page (TF-01 with Upload Validation & Verification).
	 */
	public static function render_csv_import_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-cyber-events-core' ) );
		}

		global $wpdb;

		$message = '';
		$report  = null;

		if ( isset( $_POST['cybevents_csv_nonce'] ) && check_admin_referer( 'cybevents_csv_action', 'cybevents_csv_nonce' ) ) {
			$event_id = absint( $_POST['event_id'] ?? 0 );
			$csv_data = '';

			// 1. Secure File Upload Handling
			if ( ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
				$tmp_file  = sanitize_text_field( wp_unslash( $_FILES['csv_file']['tmp_name'] ) );
				$file_name = sanitize_file_name( wp_unslash( $_FILES['csv_file']['name'] ?? '' ) );
				$file_size = (int) ( $_FILES['csv_file']['size'] ?? 0 );

				$ext = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );

				if ( ! is_uploaded_file( $tmp_file ) || 'csv' !== $ext ) {
					$message = '<div class="notice notice-error"><p>' . esc_html__( 'Sicherheitsfehler: Nur echte CSV-Dateien sind zulässig.', 'wm-cyber-events-core' ) . '</p></div>';
				} elseif ( $file_size > 5 * 1024 * 1024 ) {
					$message = '<div class="notice notice-error"><p>' . esc_html__( 'Die Datei ist zu groß (maximal 5 MB erlaubt).', 'wm-cyber-events-core' ) . '</p></div>';
				} else {
					$csv_data = file_get_contents( $tmp_file );
				}
			} elseif ( ! empty( $_POST['csv_raw'] ) ) {
				$csv_data = sanitize_textarea_field( wp_unslash( $_POST['csv_raw'] ) );
			}

			if ( empty( $message ) ) {
				if ( $event_id && ! empty( $csv_data ) ) {
					$report = CyberEvents_CSV_Importer::import_csv( $event_id, $csv_data, false );
				} else {
					$message = '<div class="notice notice-error"><p>' . esc_html__( 'Bitte wählen Sie ein Event und stellen Sie CSV-Daten bereit.', 'wm-cyber-events-core' ) . '</p></div>';
				}
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$events = $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM {$wpdb->prefix}cybevents_events WHERE id > %d ORDER BY id DESC", 0 ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		?>
		<div class="wrap wm-admin cybevents-admin-wrap">
			<header class="wm-admin-header cybevents-enterprise-banner">
				<div class="cybevents-banner-content">
					<div class="cybevents-banner-title">
						<h1>
							<span class="dashicons dashicons-upload cybevents-banner-icon" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Teilnehmer CSV-Batch Import (Dublettenabwehr TF-01)', 'wm-cyber-events-core' ); ?></span>
						</h1>
						<p><?php esc_html_e( 'Automatischer Import von Teilnehmerlisten aus externen Systemen mit strikter Vermeidung doppelter Registrierungen.', 'wm-cyber-events-core' ); ?></p>
					</div>
					<div class="cybevents-banner-actions">
						<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-cyber-events-core' ); ?>">
							<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
							<?php esc_html_e( 'WM Suite', 'wm-cyber-events-core' ); ?>
							<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-cyber-events-core' ) : esc_html__( 'Standalone', 'wm-cyber-events-core' ); ?></span>
						</span>
					</div>
				</div>
			</header>

			<?php self::render_nav_tabs( 'cybevents-csv-import' ); ?>

			<?php echo wp_kses_post( $message ); ?>

			<?php if ( $report ) : ?>
				<div class="cybevents-admin-section cybevents-section--ok">
					<h2><?php esc_html_e( 'Import-Zusammenfassung', 'wm-cyber-events-core' ); ?></h2>
					<div class="cybevents-kpi-grid">
						<div class="cybevents-kpi-card">
							<span class="cybevents-kpi-title"><?php esc_html_e( 'Gesamtzeilen', 'wm-cyber-events-core' ); ?></span>
							<span class="cybevents-kpi-num"><?php echo esc_html( $report['total'] ); ?></span>
						</div>
						<div class="cybevents-kpi-card">
							<span class="cybevents-kpi-title"><?php esc_html_e( 'Neu Importiert', 'wm-cyber-events-core' ); ?></span>
							<span class="cybevents-kpi-num cybevents-kpi-num--ok"><?php echo esc_html( $report['imported'] ); ?></span>
						</div>
						<div class="cybevents-kpi-card">
							<span class="cybevents-kpi-title"><?php esc_html_e( 'Dubletten abgewiesen', 'wm-cyber-events-core' ); ?></span>
							<span class="cybevents-kpi-num cybevents-kpi-num--warn"><?php echo esc_html( $report['skipped'] ); ?></span>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<div class="cybevents-admin-section">
				<h2><?php esc_html_e( 'Neue Teilnehmerliste importieren', 'wm-cyber-events-core' ); ?></h2>
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'cybevents_csv_action', 'cybevents_csv_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="event_id"><?php esc_html_e( 'Ziel-Veranstaltung *', 'wm-cyber-events-core' ); ?></label></th>
							<td>
								<select name="event_id" id="event_id" required class="regular-text">
									<option value=""><?php esc_html_e( '— Veranstaltung auswählen —', 'wm-cyber-events-core' ); ?></option>
									<?php foreach ( $events as $ev ) : ?>
										<option value="<?php echo esc_attr( $ev->id ); ?>"><?php echo esc_html( $ev->title ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_file"><?php esc_html_e( 'CSV-Datei auswählen', 'wm-cyber-events-core' ); ?></label></th>
							<td>
								<input type="file" name="csv_file" id="csv_file" accept=".csv" />
								<p class="description"><?php esc_html_e( 'Format: Vorname;Nachname;E-Mail;Firma (Semikolon oder Komma getrennt, UTF-8)', 'wm-cyber-events-core' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_raw"><?php esc_html_e( 'Oder CSV-Text einfügen', 'wm-cyber-events-core' ); ?></label></th>
							<td>
								<textarea name="csv_raw" id="csv_raw" rows="6" class="large-text code" placeholder="Max;Mustermann;max@musterfirma.de;Muster AG"></textarea>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'CSV-Import jetzt ausführen', 'wm-cyber-events-core' ), 'primary' ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * DATEV Export Studio Page (Req. #32).
	 */
	public static function render_datev_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-cyber-events-core' ) );
		}
		?>
		<div class="wrap wm-admin cybevents-admin-wrap">
			<header class="wm-admin-header cybevents-enterprise-banner">
				<div class="cybevents-banner-content">
					<div class="cybevents-banner-title">
						<h1>
							<span class="dashicons dashicons-media-spreadsheet cybevents-banner-icon" aria-hidden="true"></span>
							<span><?php esc_html_e( 'DATEV EXTF Buchungsstapel Studio', 'wm-cyber-events-core' ); ?></span>
						</h1>
						<p><?php esc_html_e( 'Standardisierter Finanzexport (Kategorie 21, Formatversion 700 / 125 Felder) für Steuerberater und DATEV Rechnungswesen.', 'wm-cyber-events-core' ); ?></p>
					</div>
					<div class="cybevents-banner-actions">
						<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-cyber-events-core' ); ?>">
							<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
							<?php esc_html_e( 'WM Suite', 'wm-cyber-events-core' ); ?>
							<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-cyber-events-core' ) : esc_html__( 'Standalone', 'wm-cyber-events-core' ); ?></span>
						</span>
					</div>
				</div>
			</header>

			<?php self::render_nav_tabs( 'cybevents-datev-export' ); ?>

			<div class="cybevents-admin-section">
				<h2><?php esc_html_e( 'Exportzeitraum festlegen', 'wm-cyber-events-core' ); ?></h2>
				
				<div class="cybevents-date-presets">
					<button type="button" class="cybevents-date-preset button" data-preset="current_month"><?php esc_html_e( 'Laufender Monat', 'wm-cyber-events-core' ); ?></button>
					<button type="button" class="cybevents-date-preset button" data-preset="prev_month"><?php esc_html_e( 'Vormonat', 'wm-cyber-events-core' ); ?></button>
					<button type="button" class="cybevents-date-preset button" data-preset="year"><?php esc_html_e( 'Gesamtes Jahr 2026', 'wm-cyber-events-core' ); ?></button>
				</div>

				<form method="post">
					<?php wp_nonce_field( 'cybevents_datev_action', 'cybevents_datev_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="datev_date_from"><?php esc_html_e( 'Datum von *', 'wm-cyber-events-core' ); ?></label></th>
							<td><input type="date" name="date_from" id="datev_date_from" value="<?php echo esc_attr( gmdate( 'Y-m-01' ) ); ?>" required class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="datev_date_to"><?php esc_html_e( 'Datum bis *', 'wm-cyber-events-core' ); ?></label></th>
							<td><input type="date" name="date_to" id="datev_date_to" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required class="regular-text" /></td>
						</tr>
					</table>

					<div class="cybevents-dsgvo-notice-box cybevents-dsgvo-notice-box--plain">
						<label class="cybevents-dsgvo-checkbox-wrap cybevents-dsgvo-checkbox-wrap--plain" for="datev_dsgvo_consent">
							<input type="checkbox" name="dsgvo_consent" id="datev_dsgvo_consent" value="1" required />
							<span class="cybevents-dsgvo-checkbox-label">
								<strong><?php esc_html_e( 'Bestätigung für den Export von Debitorendaten:', 'wm-cyber-events-core' ); ?></strong>
								<?php esc_html_e( 'Ich bestätige, dass der Export von Finanz- und Rechnungsempfängerdaten personenbezogene Daten enthält und ausschließlich zur gesetzlich vorgeschriebenen Buchführung und steuerlichen Erfassung an die autorisierte Steuerkanzlei weitergeleitet wird.', 'wm-cyber-events-core' ); ?>
							</span>
						</label>
					</div>

					<?php submit_button( __( 'DATEV EXTF Buchungsstapel (.csv) herunterladen', 'wm-cyber-events-core' ), 'primary' ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Audit Log View (GDPR / Compliance).
	 */
	public static function render_audit_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-cyber-events-core' ) );
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cybevents_audit_log ORDER BY id DESC LIMIT %d", 100 ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		?>
		<div class="wrap wm-admin cybevents-admin-wrap">
			<header class="wm-admin-header cybevents-enterprise-banner">
				<div class="cybevents-banner-content">
					<div class="cybevents-banner-title">
						<h1>
							<span class="dashicons dashicons-shield-alt cybevents-banner-icon" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Audit-Protokoll', 'wm-cyber-events-core' ); ?></span>
						</h1>
						<p><?php esc_html_e( 'Verketteter Prüfpfad aller sicherheitsrelevanten Vorgänge, Double Opt-Ins, Stornierungen und IDOR-Abwehrmaßnahmen.', 'wm-cyber-events-core' ); ?></p>
					</div>
					<div class="cybevents-banner-actions">
						<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-cyber-events-core' ); ?>">
							<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
							<?php esc_html_e( 'WM Suite', 'wm-cyber-events-core' ); ?>
							<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-cyber-events-core' ) : esc_html__( 'Standalone', 'wm-cyber-events-core' ); ?></span>
						</span>
					</div>
				</div>
			</header>

			<?php self::render_nav_tabs( 'cybevents-audit-log' ); ?>

			<?php
			// Measured on page load. Until this release the table had no hash column at all.
			$audit_integrity = CyberEvents_Database::verify_audit_chain();
			?>
			<div class="notice notice-<?php echo $audit_integrity['valid'] ? 'success' : 'error'; ?> inline" style="margin: 12px 0;">
				<p>
					<strong>
						<?php
						$audit_label = $audit_integrity['valid']
							? sprintf(
								/* translators: 1: entries verified by content, 2: pseudonymised entries */
								__( 'Audit-Kette integer — %1$d Einträge inhaltlich geprüft, %2$d pseudonymisiert (nur Kettenposition prüfbar).', 'wm-cyber-events-core' ),
								(int) $audit_integrity['checked'],
								(int) $audit_integrity['pseudonymized']
							)
							: __( 'Audit-Kette NICHT integer', 'wm-cyber-events-core' );
						echo esc_html( $audit_label );
						?>
					</strong>
				</p>
				<?php if ( ! $audit_integrity['valid'] ) : ?>
					<p><?php echo esc_html( (string) $audit_integrity['error'] ); ?></p>
				<?php endif; ?>
				<?php if ( $audit_integrity['unchained_legacy'] > 0 ) : ?>
					<p>
						<?php
						/* translators: %d: number of audit entries recorded before the chain existed */
						$legacy_text = __( '%d Eintrag/Einträge stammen aus der Zeit vor der Verkettung und tragen keine prüfbare Signatur. Sie gelten als ungeprüft, nicht als integer.', 'wm-cyber-events-core' );
						echo esc_html( sprintf( $legacy_text, (int) $audit_integrity['unchained_legacy'] ) );
						?>
					</p>
				<?php endif; ?>
				<?php if ( 'wp-config' !== $audit_integrity['key_source'] ) : ?>
					<p><?php esc_html_e( 'AUTH_KEY ist nicht definiert: der Kettenschlüssel stammt aus der Datenbank — also von dort, wo ein Angreifer ohnehin schreiben würde. Setzen Sie AUTH_KEY in der wp-config.php.', 'wm-cyber-events-core' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="cybevents-table-wrap">
				<table class="cybevents-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Zeitpunkt', 'wm-cyber-events-core' ); ?></th>
							<th><?php esc_html_e( 'Ereignistyp', 'wm-cyber-events-core' ); ?></th>
							<th><?php esc_html_e( 'Referenz-ID', 'wm-cyber-events-core' ); ?></th>
							<th><?php esc_html_e( 'IP-Adresse', 'wm-cyber-events-core' ); ?></th>
							<th><?php esc_html_e( 'Audit-Details (JSON)', 'wm-cyber-events-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $logs ) ) : ?>
							<tr><td colspan="5"><?php esc_html_e( 'Keine Log-Einträge vorhanden.', 'wm-cyber-events-core' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $logs as $l ) : ?>
								<?php
								$type_cls = 'cybevents-badge--free';
								if ( strpos( $l->event_type, 'blocked' ) !== false || strpos( $l->event_type, 'failed' ) !== false ) {
									$type_cls = 'cybevents-badge--cancelled';
								} elseif ( strpos( $l->event_type, 'confirmed' ) !== false || strpos( $l->event_type, 'created' ) !== false ) {
									$type_cls = 'cybevents-badge--confirmed';
								}
								?>
								<tr>
									<td><code><?php echo esc_html( $l->created_at ); ?></code></td>
									<td>
										<span class="cybevents-badge <?php echo esc_attr( $type_cls ); ?>">
											<?php echo esc_html( $l->event_type ); ?>
										</span>
									</td>
									<td>#<?php echo esc_html( $l->object_id ); ?></td>
									<td><code><?php echo esc_html( $l->ip_address ); ?></code></td>
									<td><code><?php echo esc_html( $l->details ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Tabbed Settings Studio Page.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-cyber-events-core' ) );
		}

		// Handle Seed Demo Data
		if ( isset( $_POST['cybevents_seed_demo'] ) && check_admin_referer( 'cybevents_demo_action', 'cybevents_demo_nonce' ) ) {
			$res = CyberEvents_Seeder::seed_demo_data( true );
			/* translators: 1: event title, 2: number of sessions, 3: number of registrations */
			$seeded_text = __( 'Veranstaltung "%1$s" mit %2$d Sessions und %3$d Teilnehmern angelegt.', 'wm-cyber-events-core' );
			echo '<div class="notice notice-success is-dismissible" style="margin-top: 15px;"><p><strong>' . esc_html__( 'Demodaten erfolgreich importiert!', 'wm-cyber-events-core' ) . '</strong> ' . esc_html( sprintf( $seeded_text, $res['event_title'], count( $res['sessions'] ), count( $res['registrations'] ) ) ) . '</p></div>';
		}

		// Handle Delete Demo Data
		if ( isset( $_POST['cybevents_delete_demo'] ) && check_admin_referer( 'cybevents_demo_action', 'cybevents_demo_nonce' ) ) {
			$deleted = CyberEvents_Seeder::delete_demo_data();
			/* translators: 1: events deleted, 2: sessions deleted, 3: registrations deleted, 4: invoices deleted */
			$deleted_text = __( '%1$d Events, %2$d Sessions, %3$d Registrierungen und %4$d Rechnungen wurden gelöscht.', 'wm-cyber-events-core' );
			echo '<div class="notice notice-info is-dismissible" style="margin-top: 15px;"><p><strong>' . esc_html__( 'Demodaten bereinigt!', 'wm-cyber-events-core' ) . '</strong> ' . esc_html( sprintf( $deleted_text, (int) $deleted['events'], (int) $deleted['sessions'], (int) $deleted['registrations'], (int) $deleted['invoices'] ) ) . '</p></div>';
		}

		// Handle Factory Reset
		if ( isset( $_POST['cybevents_factory_reset'] ) && check_admin_referer( 'cybevents_reset_action', 'cybevents_reset_nonce' ) ) {
			CyberEvents_Seeder::reset_to_factory_defaults();
			echo '<div class="notice notice-warning is-dismissible" style="margin-top: 15px;"><p><strong>' . esc_html__( 'Werkseinstellungen wiederhergestellt!', 'wm-cyber-events-core' ) . '</strong> ' . esc_html__( 'Alle Tabellen wurden geleert und die Einstellungen zurückgesetzt.', 'wm-cyber-events-core' ) . '</p></div>';
		}

		// The demo and reset buttons sit in the same form and post the settings nonce as well. Until
		// 2026-09-15 pressing Factory Reset ran the reset and then wrote the form values back.
		$is_demo_or_reset = isset( $_POST['cybevents_seed_demo'] ) || isset( $_POST['cybevents_delete_demo'] ) || isset( $_POST['cybevents_factory_reset'] );
		if ( ! $is_demo_or_reset && isset( $_POST['cybevents_settings_nonce'] ) && check_admin_referer( 'cybevents_save_settings', 'cybevents_settings_nonce' ) ) {
			$previous = get_option( 'cybevents_settings', array() );
			$theme_preset  = sanitize_key( $_POST['theme_preset'] ?? 'theme_default' );
			$valid_presets = array_keys( CyberEvents_Theme_Manager::get_theme_presets() );
			if ( ! in_array( $theme_preset, $valid_presets, true ) ) {
				$theme_preset = 'theme_default';
			}

			// Handle Custom CSS File Upload
			if ( ! empty( $_FILES['theme_css_file']['name'] ) && ! empty( $_FILES['theme_css_file']['tmp_name'] ) ) {
				$upload_res = CyberEvents_Theme_Manager::handle_theme_upload( $_FILES['theme_css_file'] );
				if ( ! $upload_res['success'] ) {
					echo '<div class="notice notice-error"><p>' . esc_html( $upload_res['message'] ) . '</p></div>';
				} else {
					echo '<div class="notice notice-success"><p>' . esc_html( $upload_res['message'] ) . '</p></div>';
				}
			}

			// Handle Theme CSS File Delete
			if ( ! empty( $_POST['delete_uploaded_theme'] ) ) {
				CyberEvents_Theme_Manager::delete_uploaded_theme();
				echo '<div class="notice notice-info"><p>' . esc_html__( 'Hochgeladene Theme-Datei wurde entfernt.', 'wm-cyber-events-core' ) . '</p></div>';
			}

			$new_settings = array(
				'theme_preset'             => $theme_preset,
				'theme_custom_primary'     => sanitize_hex_color( $_POST['theme_custom_primary'] ?? '#0052cc' ) ?: '#0052cc',
				'theme_custom_accent'      => sanitize_hex_color( $_POST['theme_custom_accent'] ?? '#00b4d8' ) ?: '#00b4d8',
				'theme_custom_bg'          => sanitize_hex_color( $_POST['theme_custom_bg'] ?? '#f8fafc' ) ?: '#f8fafc',
				'theme_custom_card'        => sanitize_hex_color( $_POST['theme_custom_card'] ?? '#ffffff' ) ?: '#ffffff',
				'theme_custom_text'        => sanitize_hex_color( $_POST['theme_custom_text'] ?? '#0f172a' ) ?: '#0f172a',
				'theme_custom_radius'      => absint( $_POST['theme_custom_radius'] ?? 10 ),
				'theme_custom_css'         => sanitize_textarea_field( wp_unslash( $_POST['theme_custom_css'] ?? '' ) ),
				'brevo_api_key'            => sanitize_text_field( wp_unslash( $_POST['brevo_api_key'] ?? '' ) ),
				'brevo_webhook_secret'     => sanitize_text_field( wp_unslash( $_POST['brevo_webhook_secret'] ?? '' ) ),
				'brevo_doi_template_id'    => absint( $_POST['brevo_doi_template_id'] ?? 1 ),
				'brevo_list_id'            => absint( $_POST['brevo_list_id'] ?? 2 ),
				'staff_scanner_key'        => sanitize_text_field( wp_unslash( $_POST['staff_scanner_key'] ?? '' ) ),
				// Admissions per minute in front of registration and booking; 0 switches the waiting
				// room off, which is the default.
				'admission_per_minute'     => isset( $_POST['admission_per_minute'] ) ? min( absint( $_POST['admission_per_minute'] ), CyberEvents_Waiting_Room::MAX_LIMIT ) : absint( $previous['admission_per_minute'] ?? 0 ),
				'invoice_prefix'           => sanitize_text_field( wp_unslash( $_POST['invoice_prefix'] ?? 'CE-2026-' ) ),
				// No field for the counter or the account length: keep what is stored (both were reset to
				// 1000 and 4 by every save until 2026-09-15).
				'invoice_counter'          => isset( $_POST['invoice_counter'] ) ? absint( $_POST['invoice_counter'] ) : absint( $previous['invoice_counter'] ?? 1000 ),
				'friendly_captcha_sitekey' => sanitize_text_field( wp_unslash( $_POST['friendly_captcha_sitekey'] ?? '' ) ),
				'friendly_captcha_api_key' => sanitize_text_field( wp_unslash( $_POST['friendly_captcha_api_key'] ?? '' ) ),
				'enable_friendly_captcha'  => isset( $_POST['enable_friendly_captcha'] ) ? 1 : 0,
				'datev_beraternummer'      => sanitize_text_field( wp_unslash( $_POST['datev_beraternummer'] ?? '1001' ) ),
				'datev_mandantennummer'    => sanitize_text_field( wp_unslash( $_POST['datev_mandantennummer'] ?? '10001' ) ),
				'datev_sachkontenlaenge'   => isset( $_POST['datev_sachkontenlaenge'] ) ? sanitize_text_field( wp_unslash( $_POST['datev_sachkontenlaenge'] ) ) : (string) ( $previous['datev_sachkontenlaenge'] ?? '4' ),
				'datev_erloeskonto'        => sanitize_text_field( wp_unslash( $_POST['datev_erloeskonto'] ?? '8400' ) ),
				'datev_forderungen'        => sanitize_text_field( wp_unslash( $_POST['datev_forderungen'] ?? '1400' ) ),
				// Seller of every invoice (BG-4). Until 2026-09-15 a client's company was hardcoded.
				'seller_name'              => sanitize_text_field( wp_unslash( $_POST['seller_name'] ?? '' ) ),
				'seller_street'            => sanitize_text_field( wp_unslash( $_POST['seller_street'] ?? '' ) ),
				'seller_postcode'          => sanitize_text_field( wp_unslash( $_POST['seller_postcode'] ?? '' ) ),
				'seller_city'              => sanitize_text_field( wp_unslash( $_POST['seller_city'] ?? '' ) ),
				'seller_country'           => strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['seller_country'] ?? 'DE' ) ), 0, 2 ) ),
				'seller_vat_id'            => strtoupper( str_replace( ' ', '', sanitize_text_field( wp_unslash( $_POST['seller_vat_id'] ?? '' ) ) ) ),
				'seller_tax_number'        => sanitize_text_field( wp_unslash( $_POST['seller_tax_number'] ?? '' ) ),
				'seller_email'             => sanitize_email( wp_unslash( $_POST['seller_email'] ?? '' ) ),
				'seller_iban'              => strtoupper( str_replace( ' ', '', sanitize_text_field( wp_unslash( $_POST['seller_iban'] ?? '' ) ) ) ),
				'delete_data_on_uninstall' => isset( $_POST['wm_cybevents_delete_data_on_uninstall'] ) ? 1 : 0,
			);
			update_option( 'cybevents_settings', $new_settings );
			update_option( 'wm_cybevents_delete_data_on_uninstall', isset( $_POST['wm_cybevents_delete_data_on_uninstall'] ) ? 1 : 0, false );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Einstellungen sicher gespeichert.', 'wm-cyber-events-core' ) . '</p></div>';
		}

		$s = get_option( 'cybevents_settings', array() );
		$delete_on_uninstall = (bool) get_option( 'wm_cybevents_delete_data_on_uninstall', false ) || ! empty( $s['delete_data_on_uninstall'] );
		$active_preset = $s['theme_preset'] ?? 'theme_default';
		$theme_presets = CyberEvents_Theme_Manager::get_theme_presets();
		$uploaded_theme_css = CyberEvents_Theme_Manager::get_uploaded_theme_css();
		?>
		<div class="wrap wm-admin cybevents-admin-wrap">
			<header class="wm-admin-header cybevents-enterprise-banner">
				<div class="cybevents-banner-content">
					<div class="cybevents-banner-title">
						<h1>
							<span class="dashicons dashicons-admin-generic cybevents-banner-icon" aria-hidden="true"></span>
							<span><?php esc_html_e( 'Enterprise Konfiguration & Sicherheit', 'wm-cyber-events-core' ); ?></span>
						</h1>
						<p><?php esc_html_e( 'Design, Brevo, Rechnungen und DATEV, Bot-Schutz und Demodaten.', 'wm-cyber-events-core' ); ?></p>
					</div>
					<div class="cybevents-banner-actions">
						<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-cyber-events-core' ); ?>">
							<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
							<?php esc_html_e( 'WM Suite', 'wm-cyber-events-core' ); ?>
							<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-cyber-events-core' ) : esc_html__( 'Standalone', 'wm-cyber-events-core' ); ?></span>
						</span>
					</div>
				</div>
			</header>

			<?php self::render_nav_tabs( 'cybevents-settings' ); ?>

			<form method="post" class="cybevents-admin-section" enctype="multipart/form-data">
				<?php wp_nonce_field( 'cybevents_save_settings', 'cybevents_settings_nonce' ); ?>
				
				<!-- Tab Navigation -->
				<div class="cybevents-settings-tabs">
					<button type="button" class="cybevents-tab-btn is-active" data-tab="theme"><?php esc_html_e( 'Design & Theme Studio', 'wm-cyber-events-core' ); ?></button>
					<button type="button" class="cybevents-tab-btn" data-tab="brevo"><?php esc_html_e( 'Brevo CRM & Webhooks', 'wm-cyber-events-core' ); ?></button>
					<button type="button" class="cybevents-tab-btn" data-tab="invoicing"><?php esc_html_e( 'Rechnungen & DATEV', 'wm-cyber-events-core' ); ?></button>
					<button type="button" class="cybevents-tab-btn" data-tab="captcha"><?php esc_html_e( 'Bot-Schutz (Friendly Captcha)', 'wm-cyber-events-core' ); ?></button>
					<button type="button" class="cybevents-tab-btn" data-tab="demo"><?php esc_html_e( 'Demodaten & Reset', 'wm-cyber-events-core' ); ?></button>
				</div>

				<!-- Tab 0: Design & Theme Studio -->
				<div id="tab-theme" class="cybevents-tab-pane is-active">
					<h2><?php esc_html_e( 'Frontend Design-System & Theme-Vererbung', 'wm-cyber-events-core' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Wählen Sie, wie Veranstaltungen und Buchungsformulare im Frontend gestaltet werden. Standardmäßig erbt das Plugin nahtlos alle Schriften, Farben und Buttons vom aktiven WordPress-Theme.', 'wm-cyber-events-core' ); ?></p>

					<!-- Theme Preset Cards Grid -->
					<div class="cybevents-theme-grid">
						<?php foreach ( $theme_presets as $key => $preset ) : ?>
							<?php $is_selected = ( $key === $active_preset ); ?>
							<label class="cybevents-theme-card <?php echo $is_selected ? 'is-selected' : ''; ?>" for="theme_preset_<?php echo esc_attr( $key ); ?>">
								<div class="cybevents-theme-card-header">
									<div class="cybevents-theme-card-radio">
										<input type="radio" name="theme_preset" id="theme_preset_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( true, $is_selected ); ?> />
										<span class="cybevents-theme-card-title"><?php echo esc_html( $preset['name'] ); ?></span>
									</div>
									<span class="cybevents-theme-badge <?php echo 'theme_default' === $key ? 'cybevents-theme-badge--recommended' : ''; ?>"><?php echo esc_html( $preset['badge'] ); ?></span>
								</div>

								<div class="cybevents-theme-palette-preview">
									<?php if ( ! empty( $preset['preview']['primary'] ) ) : ?>
										<span class="cybevents-palette-dot" style="background: <?php echo esc_attr( $preset['preview']['primary'] ); ?>;" title="<?php esc_attr_e( 'Primärfarbe', 'wm-cyber-events-core' ); ?>"></span>
									<?php endif; ?>
									<?php if ( ! empty( $preset['preview']['accent'] ) ) : ?>
										<span class="cybevents-palette-dot" style="background: <?php echo esc_attr( $preset['preview']['accent'] ); ?>;" title="<?php esc_attr_e( 'Akzentfarbe', 'wm-cyber-events-core' ); ?>"></span>
									<?php endif; ?>
									<?php if ( ! empty( $preset['preview']['card'] ) ) : ?>
										<span class="cybevents-palette-dot" style="background: <?php echo esc_attr( $preset['preview']['card'] ); ?>;" title="<?php esc_attr_e( 'Kartenhintergrund', 'wm-cyber-events-core' ); ?>"></span>
									<?php endif; ?>
									<?php if ( ! empty( $preset['preview']['text'] ) ) : ?>
										<span class="cybevents-palette-dot" style="background: <?php echo esc_attr( $preset['preview']['text'] ); ?>;" title="<?php esc_attr_e( 'Textfarbe', 'wm-cyber-events-core' ); ?>"></span>
									<?php endif; ?>
								</div>

								<p class="cybevents-theme-card-desc"><?php echo esc_html( $preset['description'] ); ?></p>
							</label>
						<?php endforeach; ?>
					</div>

					<!-- Custom Theme Options Section -->
					<div id="cybevents-custom-theme-box" class="cybevents-custom-theme-box">
						<h3><?php esc_html_e( 'Eigene Theme-Farben & Ränder (Custom Theme Customizer)', 'wm-cyber-events-core' ); ?></h3>
						<div class="cybevents-custom-color-grid">
							<div>
								<label for="theme_custom_primary" class="cybevents-field-label"><?php esc_html_e( 'Primärfarbe (Buttons & Akzente):', 'wm-cyber-events-core' ); ?></label>
								<input type="color" id="theme_custom_primary" name="theme_custom_primary" value="<?php echo esc_attr( $s['theme_custom_primary'] ?? '#0052cc' ); ?>" class="cybevents-color-input" />
							</div>
							<div>
								<label for="theme_custom_accent" class="cybevents-field-label"><?php esc_html_e( 'Akzentfarbe (Badges & Glow):', 'wm-cyber-events-core' ); ?></label>
								<input type="color" id="theme_custom_accent" name="theme_custom_accent" value="<?php echo esc_attr( $s['theme_custom_accent'] ?? '#00b4d8' ); ?>" class="cybevents-color-input" />
							</div>
							<div>
								<label for="theme_custom_bg" class="cybevents-field-label"><?php esc_html_e( 'Seitenhintergrund:', 'wm-cyber-events-core' ); ?></label>
								<input type="color" id="theme_custom_bg" name="theme_custom_bg" value="<?php echo esc_attr( $s['theme_custom_bg'] ?? '#f8fafc' ); ?>" class="cybevents-color-input" />
							</div>
							<div>
								<label for="theme_custom_card" class="cybevents-field-label"><?php esc_html_e( 'Kartenhintergrund:', 'wm-cyber-events-core' ); ?></label>
								<input type="color" id="theme_custom_card" name="theme_custom_card" value="<?php echo esc_attr( $s['theme_custom_card'] ?? '#ffffff' ); ?>" class="cybevents-color-input" />
							</div>
							<div>
								<label for="theme_custom_text" class="cybevents-field-label"><?php esc_html_e( 'Haupttextfarbe:', 'wm-cyber-events-core' ); ?></label>
								<input type="color" id="theme_custom_text" name="theme_custom_text" value="<?php echo esc_attr( $s['theme_custom_text'] ?? '#0f172a' ); ?>" class="cybevents-color-input" />
							</div>
							<div>
								<label for="theme_custom_radius" class="cybevents-field-label"><?php esc_html_e( 'Eckenabrundung (px):', 'wm-cyber-events-core' ); ?></label>
								<input type="number" id="theme_custom_radius" name="theme_custom_radius" min="0" max="32" value="<?php echo esc_attr( $s['theme_custom_radius'] ?? 10 ); ?>" class="cybevents-radius-input" />
							</div>
						</div>

						<h3><?php esc_html_e( 'Custom CSS-Theme hochladen (.css)', 'wm-cyber-events-core' ); ?></h3>
						<div class="cybevents-css-dropzone">
							<div>
								<input type="file" name="theme_css_file" id="theme_css_file" accept=".css,text/css" />
								<p class="description"><?php esc_html_e( 'Laden Sie eine maßgeschneiderte .css-Datei hoch (z.B. agentur-theme.css). Diese wird isoliert geladen und automatisch validiert.', 'wm-cyber-events-core' ); ?></p>
							</div>
							<?php if ( ! empty( $uploaded_theme_css ) ) : ?>
								<div class="cybevents-uploaded-theme">
									<span><?php esc_html_e( 'Aktives Custom-Theme vorhanden', 'wm-cyber-events-core' ); ?></span>
									<button type="submit" name="delete_uploaded_theme" value="1" class="button button-link-delete cybevents-btn-danger-text" onclick="return confirm('<?php echo esc_js( __( 'Möchten Sie die hochgeladene CSS-Theme-Datei wirklich löschen?', 'wm-cyber-events-core' ) ); ?>');"><?php esc_html_e( 'Theme löschen', 'wm-cyber-events-core' ); ?></button>
								</div>
							<?php endif; ?>
						</div>

						<h3><?php esc_html_e( 'Zusätzliches Benutzerdefiniertes CSS (Inline Editor)', 'wm-cyber-events-core' ); ?></h3>
						<textarea name="theme_custom_css" id="theme_custom_css" rows="6" class="large-text code" placeholder="<?php esc_attr_e( '/* Eigene CSS-Regeln hier eintragen, z.B.: */&#10;.cybevents-event-card { box-shadow: 0 10px 25px rgba(0,0,0,0.05); }', 'wm-cyber-events-core' ); ?>"><?php echo esc_textarea( $s['theme_custom_css'] ?? '' ); ?></textarea>
					</div>
				</div>

				<!-- Tab 1: Brevo & Staff Access -->
				<div id="tab-brevo" class="cybevents-tab-pane">
					<h2><?php esc_html_e( 'Brevo API v3 & Webhook-Authentifizierung', 'wm-cyber-events-core' ); ?></h2>
					<table class="form-table">
						<tr>
							<th><label for="brevo_api_key"><?php esc_html_e( 'Brevo API Key (v3)', 'wm-cyber-events-core' ); ?></label></th>
							<td>
								<input type="password" name="brevo_api_key" id="brevo_api_key" value="<?php echo esc_attr( $s['brevo_api_key'] ?? '' ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Wird für den asynchronen Double Opt-In E-Mail-Versand und Attribut-Sync genutzt.', 'wm-cyber-events-core' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="brevo_webhook_secret"><?php esc_html_e( 'Webhook Shared Secret / Signatur-Token', 'wm-cyber-events-core' ); ?></label></th>
							<td>
								<input type="password" name="brevo_webhook_secret" id="brevo_webhook_secret" value="<?php echo esc_attr( $s['brevo_webhook_secret'] ?? '' ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Timing-Attack-sicherer Token zur Validierung eingehender Brevo Webhook-Payloads.', 'wm-cyber-events-core' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="staff_scanner_key"><?php esc_html_e( 'Staff Scanner API Key (Einlass-Kontrolle)', 'wm-cyber-events-core' ); ?></label></th>
							<td>
								<input type="password" name="staff_scanner_key" id="staff_scanner_key" value="<?php echo esc_attr( $s['staff_scanner_key'] ?? '' ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Authentifizierungstoken für mobile Scanner-Geräte des Einlass-Personals.', 'wm-cyber-events-core' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="admission_per_minute"><?php esc_html_e( 'Warteraum: Zulassungen pro Minute', 'wm-cyber-events-core' ); ?></label></th>
							<td>
								<?php $admission_status = CyberEvents_Waiting_Room::status(); ?>
								<input type="number" name="admission_per_minute" id="admission_per_minute" value="<?php echo esc_attr( (string) ( $s['admission_per_minute'] ?? 0 ) ); ?>" min="0" max="<?php echo esc_attr( (string) CyberEvents_Waiting_Room::MAX_LIMIT ); ?>" class="small-text" />
								<p class="description">
									<?php esc_html_e( 'Anfragen an Anmeldung und Session-Buchung, die pro Minute und Veranstaltung durchgelassen werden. 0 schaltet den Warteraum ab (Standard). Darüber antwortet die Route mit HTTP 503 und Retry-After, bevor eine Datenbankabfrage läuft — ein Platz ist damit nicht reserviert.', 'wm-cyber-events-core' ); ?>
								</p>
								<p class="description">
									<?php
									printf(
										/* translators: %s: name of the counter backend in use */
										esc_html__( 'Zähler-Backend dieser Installation: %s.', 'wm-cyber-events-core' ),
										'object-cache' === $admission_status['backend'] ? esc_html__( 'persistenter Objekt-Cache', 'wm-cyber-events-core' ) : esc_html__( 'Datenbank (Transients)', 'wm-cyber-events-core' )
									);
									?>
								</p>
								<?php if ( '' !== $admission_status['database_backend_warning'] ) : ?>
									<div class="notice notice-warning inline"><p><?php echo esc_html( $admission_status['database_backend_warning'] ); ?></p></div>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><label for="brevo_doi_template_id"><?php esc_html_e( 'DOI Template ID', 'wm-cyber-events-core' ); ?></label></th>
							<td><input type="number" name="brevo_doi_template_id" id="brevo_doi_template_id" value="<?php echo esc_attr( $s['brevo_doi_template_id'] ?? 1 ); ?>" class="small-text" /></td>
						</tr>
						<tr>
							<th><label for="brevo_list_id"><?php esc_html_e( 'Brevo Kontaktliste ID', 'wm-cyber-events-core' ); ?></label></th>
							<td><input type="number" name="brevo_list_id" id="brevo_list_id" value="<?php echo esc_attr( $s['brevo_list_id'] ?? 2 ); ?>" class="small-text" /></td>
						</tr>
					</table>
				</div>

				<!-- Tab 2: Invoicing & DATEV -->
				<div id="tab-invoicing" class="cybevents-tab-pane">
					<h2><?php esc_html_e( 'Rechnungen (CII-XML) & DATEV', 'wm-cyber-events-core' ); ?></h2>
					<table class="form-table">
						<tr>
							<th><label for="invoice_prefix"><?php esc_html_e( 'Rechnungsnummer-Präfix', 'wm-cyber-events-core' ); ?></label></th>
							<td><input type="text" name="invoice_prefix" id="invoice_prefix" value="<?php echo esc_attr( $s['invoice_prefix'] ?? 'CE-2026-' ); ?>" class="regular-text" /></td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Rechnungssteller', 'wm-cyber-events-core' ); ?></h3>
					<?php $seller_problems = CyberEvents_Invoicing::seller_problems( CyberEvents_Invoicing::seller() ); ?>
					<?php if ( $seller_problems ) : ?>
						<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( /* translators: %s: comma-separated list of missing fields */ __( 'Ohne diese Angaben werden keine Rechnungen und Gutschriften erstellt: %s.', 'wm-cyber-events-core' ), implode( ', ', $seller_problems ) ) ); ?></p></div>
					<?php endif; ?>
					<table class="form-table">
						<?php
						$seller_fields = array(
							'seller_name'       => __( 'Name / Firma', 'wm-cyber-events-core' ),
							'seller_street'     => __( 'Straße und Hausnummer', 'wm-cyber-events-core' ),
							'seller_postcode'   => __( 'PLZ', 'wm-cyber-events-core' ),
							'seller_city'       => __( 'Ort', 'wm-cyber-events-core' ),
							'seller_country'    => __( 'Land (ISO-Code, z. B. DE)', 'wm-cyber-events-core' ),
							'seller_vat_id'     => __( 'USt-IdNr.', 'wm-cyber-events-core' ),
							'seller_tax_number' => __( 'Steuernummer (falls keine USt-IdNr.)', 'wm-cyber-events-core' ),
							'seller_email'      => __( 'E-Mail für Rechnungen', 'wm-cyber-events-core' ),
							'seller_iban'       => __( 'IBAN für Überweisungen (optional)', 'wm-cyber-events-core' ),
						);
						foreach ( $seller_fields as $field_key => $field_label ) :
							$field_default = 'seller_country' === $field_key ? 'DE' : '';
							?>
							<tr>
								<th><label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $field_label ); ?></label></th>
								<td><input type="<?php echo 'seller_email' === $field_key ? 'email' : 'text'; ?>" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( $s[ $field_key ] ?? $field_default ); ?>" class="regular-text" /></td>
							</tr>
						<?php endforeach; ?>
					</table>

					<h3><?php esc_html_e( 'DATEV', 'wm-cyber-events-core' ); ?></h3>
					<table class="form-table">
						<tr>
							<th><label for="datev_beraternummer"><?php esc_html_e( 'DATEV Beraternummer', 'wm-cyber-events-core' ); ?></label></th>
							<td><input type="text" name="datev_beraternummer" id="datev_beraternummer" value="<?php echo esc_attr( $s['datev_beraternummer'] ?? '1001' ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="datev_mandantennummer"><?php esc_html_e( 'DATEV Mandantennummer', 'wm-cyber-events-core' ); ?></label></th>
							<td><input type="text" name="datev_mandantennummer" id="datev_mandantennummer" value="<?php echo esc_attr( $s['datev_mandantennummer'] ?? '10001' ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="datev_erloeskonto"><?php esc_html_e( 'Erlöskonto (z.B. 8400 / 4400)', 'wm-cyber-events-core' ); ?></label></th>
							<td><input type="text" name="datev_erloeskonto" id="datev_erloeskonto" value="<?php echo esc_attr( $s['datev_erloeskonto'] ?? '8400' ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="datev_forderungen"><?php esc_html_e( 'Forderungskonto (z.B. 1400 / 1200)', 'wm-cyber-events-core' ); ?></label></th>
							<td><input type="text" name="datev_forderungen" id="datev_forderungen" value="<?php echo esc_attr( $s['datev_forderungen'] ?? '1400' ); ?>" class="regular-text" /></td>
						</tr>
					</table>
				</div>

				<!-- Tab 3: Bot Protection -->
				<div id="tab-captcha" class="cybevents-tab-pane">
					<h2><?php esc_html_e( 'Bot-Schutz (Friendly Captcha)', 'wm-cyber-events-core' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Das Plugin enthält noch kein Captcha-Widget: die Einstellung wird gespeichert, das Formular wird aber ohne Captcha verarbeitet.', 'wm-cyber-events-core' ); ?></p>
					<table class="form-table">
						<tr>
							<th><label for="enable_friendly_captcha"><?php esc_html_e( 'Friendly Captcha aktivieren', 'wm-cyber-events-core' ); ?></label></th>
							<td><input type="checkbox" name="enable_friendly_captcha" id="enable_friendly_captcha" value="1" <?php checked( 1, $s['enable_friendly_captcha'] ?? 0 ); ?> /></td>
						</tr>
						<tr>
							<th><label for="friendly_captcha_sitekey"><?php esc_html_e( 'Sitekey', 'wm-cyber-events-core' ); ?></label></th>
							<td><input type="text" name="friendly_captcha_sitekey" id="friendly_captcha_sitekey" value="<?php echo esc_attr( $s['friendly_captcha_sitekey'] ?? '' ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th><label for="friendly_captcha_api_key"><?php esc_html_e( 'API Key / Secret', 'wm-cyber-events-core' ); ?></label></th>
							<td><input type="password" name="friendly_captcha_api_key" id="friendly_captcha_api_key" value="<?php echo esc_attr( $s['friendly_captcha_api_key'] ?? '' ); ?>" class="regular-text" /></td>
						</tr>
					</table>
				</div>

				<!-- Tab 4: Demodaten-Management & Werkseinstellungen -->
				<div id="tab-demo" class="cybevents-tab-pane">
					<h2><?php esc_html_e( 'Demodaten-Management & Werkseinstellungen (Reset)', 'wm-cyber-events-core' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Beispieldaten einspielen, wieder entfernen oder das Plugin auf Werkseinstellungen zurücksetzen.', 'wm-cyber-events-core' ); ?></p>

					<div class="cybevents-demo-box">
						<h3>
							<span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( '1. Demodaten importieren', 'wm-cyber-events-core' ); ?>
						</h3>
						<p>
							<?php esc_html_e( 'Legt eine Beispielkonferenz mit Sessions und Beispiel-Teilnehmern an. Rechnungen entstehen nur, wenn die Rechnungssteller-Daten gesetzt sind.', 'wm-cyber-events-core' ); ?>
						</p>
						<button type="submit" name="cybevents_seed_demo" value="1" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Möchten Sie Beispieldaten jetzt importieren?', 'wm-cyber-events-core' ) ); ?>');">
							<?php esc_html_e( 'Demodaten importieren (Konferenzen, Sessions, Teilnehmer, Rechnungen)', 'wm-cyber-events-core' ); ?>
						</button>
						<?php wp_nonce_field( 'cybevents_demo_action', 'cybevents_demo_nonce' ); ?>
					</div>

					<div class="cybevents-demo-box cybevents-demo-box--warn">
						<h3>
							<span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e( '2. Demodaten bereinigen (Nur Testdaten löschen)', 'wm-cyber-events-core' ); ?>
						</h3>
						<p>
							<?php esc_html_e( 'Löscht ausschließlich generierte Demo-Konferenzen, Demo-Sessions, Demo-Teilnehmer und Demo-Rechnungen. Reale Veranstaltungen und Buchungen bleiben vollständig unberührt.', 'wm-cyber-events-core' ); ?>
						</p>
						<button type="submit" name="cybevents_delete_demo" value="1" class="button cybevents-btn-warn" onclick="return confirm('<?php echo esc_js( __( 'Möchten Sie alle Demodaten sicher entfernen? Echte Daten bleiben erhalten.', 'wm-cyber-events-core' ) ); ?>');">
							<?php esc_html_e( 'Demodaten bereinigen (Nur Testdaten)', 'wm-cyber-events-core' ); ?>
						</button>
					</div>

					<div class="cybevents-demo-box cybevents-demo-box--danger">
						<h3>
							<span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php esc_html_e( '3. Auf Werkseinstellungen zurücksetzen (Factory Reset)', 'wm-cyber-events-core' ); ?>
						</h3>
						<p>
							<strong><?php esc_html_e( 'Achtung (Irreversibel):', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Leert alle Event-, Session-, Registrierungs-, Rechnungs- und Audit-Log-Tabellen, löscht alle cybevent- und cybsession-Beiträge und setzt sämtliche Einstellungen auf den Auslieferungszustand zurück.', 'wm-cyber-events-core' ); ?>
						</p>
						<?php wp_nonce_field( 'cybevents_reset_action', 'cybevents_reset_nonce' ); ?>
						<button type="submit" name="cybevents_factory_reset" value="1" class="wm-btn wm-btn-danger" onclick="return confirm('<?php echo esc_js( __( 'WARNUNG: Sind Sie absolut sicher? Dies löscht ALLE Events, Registrierungen, Rechnungen und setzt die Einstellungen zurück!', 'wm-cyber-events-core' ) ); ?>');">
							<?php esc_html_e( 'Vollständiger Factory Reset (Alles löschen & auf Werkseinstellungen zurück)', 'wm-cyber-events-core' ); ?>
						</button>
					</div>

					<div class="cybevents-demo-box">
						<h3>
							<span class="dashicons dashicons-admin-plugins" aria-hidden="true"></span> <?php esc_html_e( '4. Verhalten bei Plugin-Deinstallation', 'wm-cyber-events-core' ); ?>
						</h3>
						<p>
							<label for="wm_cybevents_delete_data_on_uninstall" style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">
								<input type="checkbox" name="wm_cybevents_delete_data_on_uninstall" id="wm_cybevents_delete_data_on_uninstall" value="1" <?php checked( true, $delete_on_uninstall ); ?> />
								<span>
									<strong><?php esc_html_e( 'Alle Tabellen und Einstellungen bei Deinstallation löschen', 'wm-cyber-events-core' ); ?></strong><br />
									<?php esc_html_e( 'Standardmäßig bleiben alle Veranstaltungs-, Registrierungs-, Rechnungs- und Audit-Daten beim Löschen des Plugins erhalten (Aufbewahrungspflichten). Aktivieren Sie diese Option nur, wenn beim Entfernen des Plugins sämtliche Tabellen endgültig gelöscht werden sollen.', 'wm-cyber-events-core' ); ?>
								</span>
							</label>
						</p>
					</div>
				</div>

				<?php submit_button( __( 'Alle Einstellungen speichern', 'wm-cyber-events-core' ), 'primary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Add WordPress Native Contextual Help Tabs
	 */
	public static function add_contextual_help() {
		$screen = get_current_screen();
		if ( ! $screen || ! str_contains( (string) $screen->id, 'cybevents' ) ) {
			return;
		}

		$screen->add_help_tab( array(
			'id'      => 'cybevents_help_concurrency',
			'title'   => __( 'Platzbuchung', 'wm-cyber-events-core' ),
			'content' => '<p>' . esc_html__( 'Ein Platz wird mit einem einzigen bedingten UPDATE gebucht (belegt = belegt + 1, nur solange belegt < kapazitaet). Von zwei gleichzeitigen Anfragen auf den letzten Platz ändert nur eine die Zeile; die andere erhält die Meldung „ausgebucht“.', 'wm-cyber-events-core' ) . '</p>',
		) );

		$screen->add_help_tab( array(
			'id'      => 'wm_cybevents_help_zugferd',
			'title'   => __( 'Rechnungs-XML', 'wm-cyber-events-core' ),
			'content' => '<p>' . esc_html__( 'Rechnungen und Gutschriften werden als CII-XML nach dem Profil EN 16931 erzeugt und im Test mit dem Validator Mustang geprüft. Es wird kein PDF erzeugt, das XML ist also keine ZUGFeRD-Hybriddatei. Ob die Rechnungen im Einzelfall die gesetzlichen Anforderungen erfüllen, prüft die Steuerberatung.', 'wm-cyber-events-core' ) . '</p>',
		) );

		$screen->add_help_tab( array(
			'id'      => 'wm_cybevents_help_datev',
			'title'   => __( 'DATEV EXTF & Buchhaltung', 'wm-cyber-events-core' ),
			'content' => '<p>' . esc_html__( 'Exportiert Rechnungen und Gutschriften eines Zeitraums als Buchungsstapel im DATEV-Format (EXTF 700, Kategorie 21) in Windows-1252. Forderungs- und Erlöskonto kommen aus den Einstellungen; jede Zeile trägt den Steuerschlüssel 3. Konten und Schlüssel mit der Steuerberatung abstimmen.', 'wm-cyber-events-core' ) . '</p>',
		) );

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'Handbuch & API:', 'wm-cyber-events-core' ) . '</strong></p>' .
			'<p><a href="' . esc_url( admin_url( 'admin.php?page=cybevents-docs' ) ) . '" class="button button-secondary">' . esc_html__( 'Vollständiges Handbuch', 'wm-cyber-events-core' ) . '</a></p>' .
			'<p>' . esc_html__( 'Entwickelt von WM Suite.', 'wm-cyber-events-core' ) . '</p>'
		);
	}

	/**
	 * Render In-Plugin Handbook & Documentation Page
	 */
	public static function render_docs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unzureichende Berechtigungen.', 'wm-cyber-events-core' ) );
		}
		?>
		<div class="wrap wm-admin cybevents-admin-wrap">
			<header class="wm-admin-header cybevents-enterprise-banner">
				<div class="cybevents-banner-content">
					<div class="cybevents-banner-title">
						<h1>
								<span class="dashicons dashicons-book cybevents-banner-icon" aria-hidden="true"></span>
								<span><?php esc_html_e( 'WM Cyber Events Core — Administrator- & Entwickler-Handbuch', 'wm-cyber-events-core' ); ?></span>
								<span class="cybevents-version-tag"><?php esc_html_e( 'Handbuch', 'wm-cyber-events-core' ); ?></span>
							</h1>
							<p>
							<?php esc_html_e( 'Platzbuchung, Rechnungs-XML, DATEV-Export, Brevo Double Opt-in, Shortcodes und Theme-Presets.', 'wm-cyber-events-core' ); ?>
						</p>
					</div>
					<div class="cybevents-banner-actions">
						<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-cyber-events-core' ); ?>">
							<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
							<?php esc_html_e( 'WM Suite', 'wm-cyber-events-core' ); ?>
							<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-cyber-events-core' ) : esc_html__( 'Standalone', 'wm-cyber-events-core' ); ?></span>
						</span>
					</div>
				</div>
			</header>

			<?php self::render_nav_tabs( 'cybevents-docs' ); ?>

			<div class="wm-grid-2 cybevents-doc-grid">
				<!-- Card 1: Concurrency & Booking Core -->
				<div class="wm-card">
					<h2>
						<span class="dashicons dashicons-shield-alt cybevents-icon-accent" aria-hidden="true"></span>
						<?php esc_html_e( '1. Platzbuchung ohne Überbuchung', 'wm-cyber-events-core' ); ?>
					</h2>
					<p class="cybevents-doc-text">
						<?php esc_html_e( 'Der Belegungszähler wird in einer einzigen bedingten Anweisung erhöht; von zwei gleichzeitigen Anfragen auf den letzten Platz ändert nur eine die Zeile:', 'wm-cyber-events-core' ); ?>
					</p>
					<pre>UPDATE {$wpdb->prefix}cybevents_sessions
SET belegt = belegt + 1
WHERE id = %d AND belegt &lt; kapazitaet;

-- 1 betroffene Zeile = Platz gesichert
--  0 = ausgebucht oder Session unbekannt (kein Platz!)
--  false = Datenbankfehler (nicht mit 0 verwechseln)</pre>
				</div>

				<!-- Card 2: E-Invoicing & Compliance -->
				<div class="wm-card">
					<h2>
						<span class="dashicons dashicons-media-spreadsheet cybevents-icon-accent" aria-hidden="true"></span>
						<?php esc_html_e( '2. Rechnungs-XML & DATEV', 'wm-cyber-events-core' ); ?>
					</h2>
					<ul class="cybevents-doc-list">
						<li><strong><?php esc_html_e( 'CII-XML (EN 16931):', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Rechnungen und Gutschriften mit Rechnungssteller aus den Einstellungen, Leistungsdatum und fortlaufender Nummer. Kein PDF, keine Leitweg-ID.', 'wm-cyber-events-core' ); ?></li>
						<li><strong><?php esc_html_e( 'Gutschriften:', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Eigene Nummer, Typ 381 mit Verweis auf die Originalrechnung.', 'wm-cyber-events-core' ); ?></li>
						<li><strong><?php esc_html_e( 'DATEV EXTF 700:', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Buchungsstapel eines Zeitraums innerhalb eines Kalenderjahres, Windows-1252. Konten und Steuerschlüssel mit der Steuerberatung abstimmen.', 'wm-cyber-events-core' ); ?></li>
					</ul>
				</div>
			</div>

			<div class="wm-grid-2 cybevents-doc-grid">
				<!-- Card 3: Brevo CRM & Background Queue -->
				<div class="wm-card">
					<h2>
						<span class="dashicons dashicons-email-alt cybevents-icon-accent" aria-hidden="true"></span>
						<?php esc_html_e( '3. Brevo CRM Double Opt-In & Background Queue', 'wm-cyber-events-core' ); ?>
					</h2>
					<ul class="cybevents-doc-list">
						<li><strong><?php esc_html_e( 'Double Opt-In (DOI):', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Brevo versendet die Bestätigungs-E-Mail mit einem persönlichen Link; der Link bestätigt die Anmeldung einmalig. Unbestätigte Anmeldungen werden nach 14 Tagen gelöscht.', 'wm-cyber-events-core' ); ?></li>
						<li><strong><?php esc_html_e( 'Warteschlange:', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Aufrufe an Brevo laufen über eine Datenbank-Warteschlange, die WP-Cron jede Minute abarbeitet (oder wp cybevents queue-run), mit bis zu fünf Versuchen.', 'wm-cyber-events-core' ); ?></li>
					</ul>
				</div>

				<!-- Card 4: Shortcodes & Blocks -->
				<div class="wm-card">
					<h2>
						<span class="dashicons dashicons-shortcode cybevents-icon-accent" aria-hidden="true"></span>
						<?php esc_html_e( '4. Shortcodes & Gutenberg Integration', 'wm-cyber-events-core' ); ?>
					</h2>
					<ul class="cybevents-doc-list">
						<li><code>[cybevents_registration event_id="123"]</code> — <?php esc_html_e( 'Barrierefreies Registrierungsformular.', 'wm-cyber-events-core' ); ?></li>
						<li><code>[cybevents_agenda]</code> — <?php esc_html_e( 'Interaktive Konferenz-Agenda & Track-Übersicht.', 'wm-cyber-events-core' ); ?></li>
						<li><code>[cybevents_session_selector]</code> — <?php esc_html_e( 'Token-basierte Session-Auswahl nach DOI-Bestätigung.', 'wm-cyber-events-core' ); ?></li>
					</ul>
				</div>
			</div>

			<!-- Card 5: Developer Hooks -->
			<div class="wm-card cybevents-doc-card">
				<h2>
					<span class="dashicons dashicons-editor-code cybevents-icon-accent" aria-hidden="true"></span>
					<?php esc_html_e( '5. WP-CLI für Administratoren', 'wm-cyber-events-core' ); ?>
				</h2>
				<?php // Until 2026-09-15 this card showed a hook cybevents_registration_created that the plugin never fires; it offers no hooks of its own. ?>
				<pre><code>wp cybevents list                 <span class="wm-code-cm"># Veranstaltungen mit bestätigten Anmeldungen</span>
wp cybevents queue-run            <span class="wm-code-cm"># Brevo-Warteschlange abarbeiten</span>
wp cybevents verify-audit         <span class="wm-code-cm"># Audit-Kette prüfen, Exit-Code 1 bei Bruch</span>
wp cybevents demodaten --import   <span class="wm-code-cm"># oder --delete, --reset</span></code></pre>
			</div>

			<!-- Card 6: Theme Studio & Design Tokens -->
			<div class="wm-card cybevents-doc-card">
				<h2>
					<span class="dashicons dashicons-art cybevents-icon-purple" aria-hidden="true"></span>
					<?php esc_html_e( '6. Theme-Presets & Design Tokens', 'wm-cyber-events-core' ); ?>
				</h2>
				<p class="cybevents-doc-text">
					<?php esc_html_e( 'Das Frontend passt sich flexibel an jedes WordPress-Theme an oder nutzt vorgefertigte Design-Presets:', 'wm-cyber-events-core' ); ?>
				</p>
				<ul class="cybevents-doc-list">
					<li><strong><?php esc_html_e( 'Presets:', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Mehrere Farb-Presets, darunter eines in Dunkellila, Violett und Rose (#482683, #7D40E7, #DF0079).', 'wm-cyber-events-core' ); ?></li>
					<li><strong><?php esc_html_e( 'Theme-Vererbung (Standard):', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Erbt Schriftarten, Rahmenradien und Primärfarben nahtlos vom aktiven Theme (GeneratePress, Astra, Twenty Twenty-Four).', 'wm-cyber-events-core' ); ?></li>
					<li><strong><?php esc_html_e( 'CSS Custom Properties:', 'wm-cyber-events-core' ); ?></strong> Alle Formulare und Agenden verwenden Design Tokens wie <code>var(--cyb-primary)</code>, <code>var(--cyb-font-sans)</code> und <code>var(--cyb-radius-md)</code>.</li>
				</ul>
			</div>

			<!-- Card 7: Gotchas -->
			<div class="wm-card">
				<h2>
					<span class="dashicons dashicons-info cybevents-icon-accent" aria-hidden="true"></span>
					<?php esc_html_e( '7. Gotchas & Best Practices', 'wm-cyber-events-core' ); ?>
				</h2>
				<ul class="cybevents-doc-list">
					<li><strong><?php esc_html_e( 'InnoDB:', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Die Tabellen werden mit ENGINE=InnoDB angelegt.', 'wm-cyber-events-core' ); ?></li>
					<li><strong><?php esc_html_e( 'Löschfristen:', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Unbestätigte Anmeldungen werden nach 14 Tagen gelöscht, Einträge im Audit-Log nach 90 Tagen pseudonymisiert (außer Rechnungsereignissen). Bestätigte Anmeldungen und Rechnungen löscht das Plugin nicht automatisch; dafür gibt es keine Einstellung.', 'wm-cyber-events-core' ); ?></li>
					<li><strong><?php esc_html_e( 'WP-Cron:', 'wm-cyber-events-core' ); ?></strong> <?php esc_html_e( 'Warteschlange und Löschfristen laufen über WP-Cron; auf Seiten ohne regelmäßige Besucher braucht es einen echten Cronjob.', 'wm-cyber-events-core' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}
