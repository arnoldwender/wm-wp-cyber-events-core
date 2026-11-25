<?php
/**
 * Session CPT and Management Controller.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Sessions_Manager {

	/**
	 * Register Custom Post Type for Sessions/Tracks.
	 */
	public static function register_cpt() {
		$labels = array(
			'name'               => _x( 'Sessions & Tracks', 'post type general name', 'wm-cyber-events-core' ),
			'singular_name'      => _x( 'Session', 'post type singular name', 'wm-cyber-events-core' ),
			'menu_name'          => _x( 'Sessions', 'admin menu', 'wm-cyber-events-core' ),
			'all_items'          => __( 'Alle Sessions', 'wm-cyber-events-core' ),
			'add_new_item'       => __( 'Neue Session erstellen', 'wm-cyber-events-core' ),
			'edit_item'          => __( 'Session bearbeiten', 'wm-cyber-events-core' ),
			'view_item'          => __( 'Session ansehen', 'wm-cyber-events-core' ),
			'search_items'       => __( 'Sessions durchsuchen', 'wm-cyber-events-core' ),
			'not_found'          => __( 'Keine Sessions gefunden.', 'wm-cyber-events-core' ),
			'not_found_in_trash' => __( 'Keine Sessions im Papierkorb.', 'wm-cyber-events-core' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'sessions' ),
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'show_in_rest'       => true,
			'supports'           => array( 'title', 'editor', 'custom-fields' ),
		);

		register_post_type( 'cybsession', $args );
	}

	/**
	 * Register Meta Boxes for Session CPT.
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'cybevents_session_details',
			__( 'Session-Daten', 'wm-cyber-events-core' ),
			array( __CLASS__, 'render_meta_box' ),
			'cybsession',
			'normal',
			'high'
		);
	}

	/**
	 * Render Session Meta Box in Post Editor.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'cybevents_save_session_meta', 'cybevents_session_meta_nonce' );
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$events = $wpdb->get_results( $wpdb->prepare( "SELECT id, title FROM {$wpdb->prefix}cybevents_events WHERE id > %d ORDER BY id DESC", 0 ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$event_id   = (int) get_post_meta( $post->ID, '_cybevents_parent_event_id', true );
		$speaker    = get_post_meta( $post->ID, '_cybevents_speaker', true );
		$room       = (string) get_post_meta( $post->ID, '_cybevents_room', true ); // Pre-filled with a room name until 2026-09-15.
		$start_time = get_post_meta( $post->ID, '_cybevents_start_time', true ) ?: current_time( 'Y-m-d 10:00' );
		$end_time   = get_post_meta( $post->ID, '_cybevents_end_time', true ) ?: current_time( 'Y-m-d 11:30' );
		$capacity   = (int) ( get_post_meta( $post->ID, '_cybevents_capacity', true ) ?: 40 );

		$start_val = str_replace( ' ', 'T', substr( $start_time, 0, 16 ) );
		$end_val   = str_replace( ' ', 'T', substr( $end_time, 0, 16 ) );
		?>
		<div class="cybevents-meta-box-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; padding: 12px 0;">
			<div>
				<label for="_cybevents_parent_event_id" style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Zugehörige Konferenz:', 'wm-cyber-events-core' ); ?></label>
				<select id="_cybevents_parent_event_id" name="_cybevents_parent_event_id" class="widefat" style="padding: 6px 10px;">
					<option value="0"><?php esc_html_e( '— Keine Konferenz ausgewählt —', 'wm-cyber-events-core' ); ?></option>
					<?php foreach ( $events as $ev ) : ?>
						<option value="<?php echo esc_attr( $ev->id ); ?>" <?php selected( $ev->id, $event_id ); ?>>
							#<?php echo esc_html( $ev->id ); ?> — <?php echo esc_html( $ev->title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div>
				<label for="_cybevents_speaker" style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Referent / Speaker & Institution:', 'wm-cyber-events-core' ); ?></label>
				<input type="text" id="_cybevents_speaker" name="_cybevents_speaker" value="<?php echo esc_attr( $speaker ); ?>" placeholder="<?php esc_attr_e( 'z. B. Erika Mustermann (Beispiel GmbH)', 'wm-cyber-events-core' ); ?>" class="widefat" style="padding: 6px 10px;" />
			</div>

			<div>
				<label for="_cybevents_room" style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Raum / Saal / Track:', 'wm-cyber-events-core' ); ?></label>
				<input type="text" id="_cybevents_room" name="_cybevents_room" value="<?php echo esc_attr( $room ); ?>" class="widefat" style="padding: 6px 10px;" />
			</div>

			<div>
				<label for="_cybevents_capacity" style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Workshop-Kapazität:', 'wm-cyber-events-core' ); ?></label>
				<input type="number" id="_cybevents_capacity" name="_cybevents_capacity" value="<?php echo esc_attr( $capacity ); ?>" min="1" max="1000" class="widefat" style="padding: 6px 10px;" />
			</div>

			<div>
				<label for="_cybevents_start_time" style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Beginn (Datum & Uhrzeit):', 'wm-cyber-events-core' ); ?></label>
				<input type="datetime-local" id="_cybevents_start_time" name="_cybevents_start_time" value="<?php echo esc_attr( $start_val ); ?>" class="widefat" style="padding: 6px 10px;" />
			</div>

			<div>
				<label for="_cybevents_end_time" style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Ende (Datum & Uhrzeit):', 'wm-cyber-events-core' ); ?></label>
				<input type="datetime-local" id="_cybevents_end_time" name="_cybevents_end_time" value="<?php echo esc_attr( $end_val ); ?>" class="widefat" style="padding: 6px 10px;" />
			</div>
		</div>
		<?php
	}

	/**
	 * Add custom columns to Session list table in wp-admin.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function set_custom_columns( $columns ) {
		$new_cols = array();
		foreach ( $columns as $k => $v ) {
			$new_cols[ $k ] = $v;
			if ( 'title' === $k ) {
				$new_cols['cyb_speaker']   = __( 'Speaker', 'wm-cyber-events-core' );
				$new_cols['cyb_room']      = __( 'Raum / Track', 'wm-cyber-events-core' );
				$new_cols['cyb_time']      = __( 'Zeitfenster', 'wm-cyber-events-core' );
				$new_cols['cyb_occupancy'] = __( 'Belegung', 'wm-cyber-events-core' );
			}
		}
		return $new_cols;
	}

	/**
	 * Render custom column content for Session list table.
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_custom_columns( $column, $post_id ) {
		global $wpdb;

		if ( 'cyb_speaker' === $column ) {
			$spk = get_post_meta( $post_id, '_cybevents_speaker', true );
			echo esc_html( $spk ?: '—' );
		} elseif ( 'cyb_room' === $column ) {
			$room = get_post_meta( $post_id, '_cybevents_room', true );
			echo esc_html( $room ?: '—' );
		} elseif ( 'cyb_time' === $column ) {
			$start = get_post_meta( $post_id, '_cybevents_start_time', true );
			$end   = get_post_meta( $post_id, '_cybevents_end_time', true );
			if ( $start ) {
				echo esc_html( gmdate( 'd.m.Y H:i', strtotime( $start ) ) . ' - ' . ( $end ? gmdate( 'H:i', strtotime( $end ) ) : '' ) );
			} else {
				echo '—';
			}
		} elseif ( 'cyb_occupancy' === $column ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$s_row = $wpdb->get_row( $wpdb->prepare( "SELECT kapazitaet, belegt FROM {$wpdb->prefix}cybevents_sessions WHERE wp_post_id = %d", $post_id ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $s_row ) {
				$pct = min( 100, round( ( (int) $s_row->belegt / max( 1, (int) $s_row->kapazitaet ) ) * 100 ) );
				echo '<strong>' . esc_html( $s_row->belegt ) . '</strong> / ' . esc_html( $s_row->kapazitaet ) . ' (' . esc_html( $pct ) . '%)';
			} else {
				echo '—';
			}
		}
	}

	/**
	 * Sync Session CPT to relational database table.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 */
	public static function sync_cpt_to_table( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( 'cybsession' !== $post->post_type ) {
			return;
		}

		// Save Meta Box values if submitted
		if ( isset( $_POST['cybevents_session_meta_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['cybevents_session_meta_nonce'] ), 'cybevents_save_session_meta' ) ) {
			if ( isset( $_POST['_cybevents_parent_event_id'] ) ) {
				update_post_meta( $post_id, '_cybevents_parent_event_id', absint( $_POST['_cybevents_parent_event_id'] ) );
			}
			if ( isset( $_POST['_cybevents_speaker'] ) ) {
				update_post_meta( $post_id, '_cybevents_speaker', sanitize_text_field( wp_unslash( $_POST['_cybevents_speaker'] ) ) );
			}
			if ( isset( $_POST['_cybevents_room'] ) ) {
				update_post_meta( $post_id, '_cybevents_room', sanitize_text_field( wp_unslash( $_POST['_cybevents_room'] ) ) );
			}
			if ( isset( $_POST['_cybevents_start_time'] ) ) {
				update_post_meta( $post_id, '_cybevents_start_time', sanitize_text_field( wp_unslash( $_POST['_cybevents_start_time'] ) ) );
			}
			if ( isset( $_POST['_cybevents_end_time'] ) ) {
				update_post_meta( $post_id, '_cybevents_end_time', sanitize_text_field( wp_unslash( $_POST['_cybevents_end_time'] ) ) );
			}
			if ( isset( $_POST['_cybevents_capacity'] ) ) {
				update_post_meta( $post_id, '_cybevents_capacity', absint( $_POST['_cybevents_capacity'] ) );
			}
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cybevents_sessions WHERE wp_post_id = %d", $post_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$event_id    = absint( get_post_meta( $post_id, '_cybevents_parent_event_id', true ) );
		$title       = sanitize_text_field( $post->post_title );
		$description = wp_kses_post( $post->post_content );
		$speaker     = sanitize_text_field( get_post_meta( $post_id, '_cybevents_speaker', true ) );
		$start_time  = sanitize_text_field( get_post_meta( $post_id, '_cybevents_start_time', true ) );
		$end_time    = sanitize_text_field( get_post_meta( $post_id, '_cybevents_end_time', true ) );
		$capacity    = absint( get_post_meta( $post_id, '_cybevents_capacity', true ) );
		$room        = sanitize_text_field( get_post_meta( $post_id, '_cybevents_room', true ) );

		if ( empty( $start_time ) ) {
			$start_time = current_time( 'mysql' );
		}
		if ( empty( $end_time ) ) {
			$end_time = current_time( 'mysql' );
		}
		if ( ! $capacity ) {
			$capacity = 30; // Default capacity
		}

		$data = array(
			'wp_post_id'  => $post_id,
			'event_id'    => $event_id,
			'title'       => $title,
			'description' => $description,
			'speaker'     => $speaker,
			'start_time'  => $start_time,
			'end_time'    => $end_time,
			'kapazitaet'  => $capacity,
			'raum'        => $room,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $exists ) {
			$wpdb->update( "{$wpdb->prefix}cybevents_sessions", $data, array( 'wp_post_id' => $post_id ) );
		} else {
			$data['belegt'] = 0;
			$wpdb->insert( "{$wpdb->prefix}cybevents_sessions", $data );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Render session selection UI for confirmed participants.
	 *
	 * @param object $registration Registration record.
	 * @return string HTML output.
	 */
	public static function render_session_selection_ui( $registration ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, 
				(SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registration_sessions b WHERE b.session_id = s.id AND b.registration_id = %d) as is_booked
				FROM {$wpdb->prefix}cybevents_sessions s 
				WHERE s.event_id = %d 
				ORDER BY s.start_time ASC",
				$registration->id,
				$registration->event_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		ob_start();
		?>
		<div class="cybevents-session-app-container" id="cybevents-session-app">
			<!-- Participant Welcome Banner -->
			<div class="cybevents-participant-welcome">
				<div class="cybevents-participant-info">
					<h2>
						<?php
						/* translators: 1: First name, 2: Last name */
						printf( esc_html__( 'Willkommen, %1$s %2$s!', 'wm-cyber-events-core' ), esc_html( $registration->first_name ), esc_html( $registration->last_name ) );
						?>
					</h2>
					<p><?php esc_html_e( 'Wählen Sie Ihre Sessions. Ein Platz wird beim Speichern vergeben, solange die Session nicht ausgebucht ist.', 'wm-cyber-events-core' ); ?></p>
				</div>
				<div>
					<span class="cybevents-status-chip">
						<?php echo CyberEvents_Icons::svg( 'check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Anmeldung bestätigt', 'wm-cyber-events-core' ); ?>
					</span>
				</div>
			</div>

			<!-- Live Sessions Grid -->
			<form id="cybevents-session-form" class="cybevents-session-form">
				<div class="cybevents-sessions-list">
					<?php foreach ( $sessions as $sess ) : ?>
						<?php
						$cap       = (int) $sess->kapazitaet;
						$occ       = (int) $sess->belegt;
						$free      = max( 0, $cap - $occ );
						$is_booked = ( 1 === (int) $sess->is_booked );
						$is_full   = ( $free <= 0 && ! $is_booked );
						$time_str  = gmdate( 'H:i', strtotime( $sess->start_time ) ) . ' – ' . gmdate( 'H:i', strtotime( $sess->end_time ) ) . ' Uhr';
						?>
						<div class="cybevents-session-card <?php echo $is_booked ? 'is-booked' : ''; ?> <?php echo $is_full ? 'is-full' : ''; ?>" id="session-card-<?php echo esc_attr( $sess->id ); ?>">
							<div class="cybevents-session-header">
								<div>
									<h3 class="cybevents-session-title"><?php echo esc_html( $sess->title ); ?></h3>
									<div class="cybevents-session-meta-strip">
										<span class="cybevents-meta-pill"><?php echo CyberEvents_Icons::svg( 'clock', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $time_str ); ?></span>
										<?php if ( ! empty( $sess->raum ) ) : ?>
											<span class="cybevents-meta-pill"><?php echo CyberEvents_Icons::svg( 'building', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $sess->raum ); ?></span>
										<?php endif; ?>
										<?php if ( ! empty( $sess->speaker ) ) : ?>
											<span class="cybevents-meta-pill"><?php echo CyberEvents_Icons::svg( 'mic', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $sess->speaker ); ?></span>
										<?php endif; ?>
									</div>
								</div>
								<div>
									<span class="cybevents-capacity-badge <?php echo $is_full ? 'cybevents-capacity-badge--full' : ''; ?>">
										<?php
										if ( $is_full ) {
											esc_html_e( 'Ausgebucht', 'wm-cyber-events-core' );
										} else {
											/* translators: %d: Number of available seats */
											printf( esc_html( _n( '%d freier Platz', '%d freie Plätze', $free, 'wm-cyber-events-core' ) ), absint( $free ) );
										}
										?>
									</span>
								</div>
							</div>

							<?php if ( ! empty( $sess->description ) ) : ?>
								<p class="cybevents-session-desc"><?php echo esc_html( $sess->description ); ?></p>
							<?php endif; ?>

							<label class="cybevents-consent-card" for="session-cb-<?php echo esc_attr( $sess->id ); ?>" style="margin-bottom: 0;">
								<input type="checkbox" id="session-cb-<?php echo esc_attr( $sess->id ); ?>" name="sessions[]" value="<?php echo esc_attr( $sess->id ); ?>" class="cybevents-checkbox session-checkbox" <?php checked( $is_booked ); ?> <?php disabled( $is_full ); ?> />
								<div class="cybevents-consent-text">
									<strong><?php echo $is_booked ? esc_html__( 'Für diese Session eingetragen', 'wm-cyber-events-core' ) : esc_html__( 'Diesen Programmpunkt auswählen', 'wm-cyber-events-core' ); ?></strong>
									<span><?php esc_html_e( 'Der Platz wird beim Speichern vergeben, solange die Session nicht ausgebucht ist.', 'wm-cyber-events-core' ); ?></span>
								</div>
							</label>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Sticky Floating Action Dock -->
				<div class="cybevents-floating-dock" id="cybevents-action-dock">
					<div class="cybevents-dock-summary">
						<span class="cybevents-dock-pill" id="cybevents-selected-count">0</span>
						<span><?php esc_html_e( 'Sessions ausgewählt', 'wm-cyber-events-core' ); ?></span>
					</div>
					<button type="submit" class="cybevents-dock-btn" id="cybevents-save-sessions-btn">
						<span><?php esc_html_e( 'Auswahl verbindlich speichern', 'wm-cyber-events-core' ); ?></span>
					</button>
				</div>

				<div id="cybevents-session-feedback" class="cybevents-feedback" role="alert" aria-live="polite"></div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
