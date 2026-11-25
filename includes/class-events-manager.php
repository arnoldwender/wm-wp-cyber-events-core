<?php
/**
 * Event CPT and Management Controller.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Events_Manager {

	/**
	 * Register Custom Post Type for Events.
	 */
	public static function register_cpt() {
		$labels = array(
			'name'               => _x( 'Veranstaltungen', 'post type general name', 'wm-cyber-events-core' ),
			'singular_name'      => _x( 'Veranstaltung', 'post type singular name', 'wm-cyber-events-core' ),
			'menu_name'          => _x( 'Cyber Events', 'admin menu', 'wm-cyber-events-core' ),
			'name_admin_bar'     => _x( 'Veranstaltung', 'add new on admin bar', 'wm-cyber-events-core' ),
			'add_new'            => _x( 'Neu erstellen', 'event', 'wm-cyber-events-core' ),
			'add_new_item'       => __( 'Neue Veranstaltung erstellen', 'wm-cyber-events-core' ),
			'new_item'           => __( 'Neue Veranstaltung', 'wm-cyber-events-core' ),
			'edit_item'          => __( 'Veranstaltung bearbeiten', 'wm-cyber-events-core' ),
			'view_item'          => __( 'Veranstaltung ansehen', 'wm-cyber-events-core' ),
			'all_items'          => __( 'Alle Veranstaltungen', 'wm-cyber-events-core' ),
			'search_items'       => __( 'Veranstaltungen durchsuchen', 'wm-cyber-events-core' ),
			'not_found'          => __( 'Keine Veranstaltungen gefunden.', 'wm-cyber-events-core' ),
			'not_found_in_trash' => __( 'Keine Veranstaltungen im Papierkorb.', 'wm-cyber-events-core' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'veranstaltungen' ),
			'capability_type'    => 'post',
			'has_archive'        => true,
			'hierarchical'       => false,
			'menu_position'      => null,
			'show_in_rest'       => true,
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
		);

		register_post_type( 'cybevent', $args );
	}

	/**
	 * Register Meta Boxes for Event CPT.
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'cybevents_event_details',
			__( 'Veranstaltungsdaten', 'wm-cyber-events-core' ),
			array( __CLASS__, 'render_meta_box' ),
			'cybevent',
			'normal',
			'high'
		);
	}

	/**
	 * Render Event Meta Box in Post Editor.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'cybevents_save_event_meta', 'cybevents_event_meta_nonce' );

		// Empty for a new event. Until 2026-09-15 a client's venue was pre-filled and saved into every
		// event whose editor left the field alone.
		$location     = (string) get_post_meta( $post->ID, '_cybevents_location', true );
		$start_date   = get_post_meta( $post->ID, '_cybevents_start_date', true ) ?: current_time( 'Y-m-d 09:00' );
		$end_date     = get_post_meta( $post->ID, '_cybevents_end_date', true ) ?: current_time( 'Y-m-d 18:00' );
		$max_capacity = (int) ( get_post_meta( $post->ID, '_cybevents_max_capacity', true ) ?: 500 );
		$ticket_price = (float) ( get_post_meta( $post->ID, '_cybevents_ticket_price', true ) ?: 0.00 );
		$is_paid      = (int) get_post_meta( $post->ID, '_cybevents_is_paid', true );

		$venues       = class_exists( 'CyberEvents_Venues_Manager' ) ? CyberEvents_Venues_Manager::get_all_venues() : array();
		$cur_terms    = wp_get_post_terms( $post->ID, 'cybvenue' );
		$cur_venue_id = ( ! empty( $cur_terms ) && ! is_wp_error( $cur_terms ) ) ? (int) $cur_terms[0]->term_id : 0;

		// Convert MySQL datetime to datetime-local input format
		$start_val = str_replace( ' ', 'T', substr( $start_date, 0, 16 ) );
		$end_val   = str_replace( ' ', 'T', substr( $end_date, 0, 16 ) );
		?>
		<div class="cybevents-meta-box-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; padding: 12px 0;">
			<div style="grid-column: 1 / -1;">
				<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
					<label for="_cybevents_venue_id" style="font-weight: 600;"><?php esc_html_e( 'Veranstaltungsort:', 'wm-cyber-events-core' ); ?></label>
					<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=cybvenue&post_type=cybevent' ) ); ?>" target="_blank" rel="noopener noreferrer" style="font-size: 12px; text-decoration: none;"><?php esc_html_e( 'Veranstaltungsorte verwalten ↗', 'wm-cyber-events-core' ); ?></a>
				</div>
				<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
					<div>
						<select id="_cybevents_venue_id" name="_cybevents_venue_id" class="widefat" style="padding: 6px 10px; font-size: 13px;">
							<option value="0"><?php esc_html_e( '— Hinterlegten Tagungsort wählen —', 'wm-cyber-events-core' ); ?></option>
							<?php foreach ( $venues as $v_item ) : ?>
								<option value="<?php echo esc_attr( $v_item['id'] ); ?>" <?php selected( $cur_venue_id, $v_item['id'] ); ?> data-name="<?php echo esc_attr( $v_item['name'] ); ?>" data-full="<?php echo esc_attr( $v_item['full_address'] ); ?>" data-city="<?php echo esc_attr( $v_item['city'] ); ?>" data-capacity="<?php echo esc_attr( $v_item['capacity'] ); ?>">
									<?php echo esc_html( $v_item['name'] ); ?><?php echo $v_item['city'] ? ' (' . esc_html( $v_item['city'] ) . ')' : ''; ?><?php echo $v_item['capacity'] ? ' · ' . esc_html( $v_item['capacity'] ) . ' Pax' : ''; ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<input type="text" id="_cybevents_location" name="_cybevents_location" value="<?php echo esc_attr( $location ); ?>" placeholder="<?php esc_attr_e( 'Manueller Ort / Raum / Adresse...', 'wm-cyber-events-core' ); ?>" class="widefat" style="padding: 6px 10px; font-size: 13px;" />
					</div>
				</div>
				<p class="description" style="margin-top: 4px; font-size: 12px;"><?php esc_html_e( 'Wählen Sie einen hinterlegten Ort aus der Liste oder passen Sie die Ortsbezeichnung/Raumangabe individuell an.', 'wm-cyber-events-core' ); ?></p>
			</div>

			<div>
				<label for="_cybevents_start_date" style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Beginn (Datum & Uhrzeit):', 'wm-cyber-events-core' ); ?></label>
				<input type="datetime-local" id="_cybevents_start_date" name="_cybevents_start_date" value="<?php echo esc_attr( $start_val ); ?>" class="widefat" style="padding: 6px 10px;" />
			</div>

			<div>
				<label for="_cybevents_end_date" style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Ende (Datum & Uhrzeit):', 'wm-cyber-events-core' ); ?></label>
				<input type="datetime-local" id="_cybevents_end_date" name="_cybevents_end_date" value="<?php echo esc_attr( $end_val ); ?>" class="widefat" style="padding: 6px 10px;" />
			</div>

			<div>
				<label for="_cybevents_max_capacity" style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Max. Teilnehmerkapazität:', 'wm-cyber-events-core' ); ?></label>
				<input type="number" id="_cybevents_max_capacity" name="_cybevents_max_capacity" value="<?php echo esc_attr( $max_capacity ); ?>" min="1" max="10000" class="widefat" style="padding: 6px 10px;" />
			</div>

			<div>
				<label for="_cybevents_ticket_price" style="display: block; font-weight: 600; margin-bottom: 6px;"><?php esc_html_e( 'Ticketpreis (Netto in €):', 'wm-cyber-events-core' ); ?></label>
				<input type="number" step="0.01" id="_cybevents_ticket_price" name="_cybevents_ticket_price" value="<?php echo esc_attr( $ticket_price ); ?>" class="widefat" style="padding: 6px 10px;" />
			</div>

			<div style="display: flex; align-items: center; margin-top: 24px;">
				<label style="display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
					<input type="checkbox" name="_cybevents_is_paid" value="1" <?php checked( 1, $is_paid ); ?> />
					<?php // Stored only: the registration flow creates no invoice (audit 5.1). ?>
					<?php esc_html_e( 'Kostenpflichtige Veranstaltung', 'wm-cyber-events-core' ); ?>
				</label>
			</div>
		</div>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var venueSel = document.getElementById('_cybevents_venue_id');
			var locInput = document.getElementById('_cybevents_location');
			var capInput = document.getElementById('_cybevents_max_capacity');
			if (venueSel && locInput) {
				venueSel.addEventListener('change', function() {
					var opt = venueSel.options[venueSel.selectedIndex];
					if (opt && opt.value !== '0') {
						var fullAddr = opt.getAttribute('data-full') || opt.getAttribute('data-name');
						if (fullAddr) {
							locInput.value = fullAddr;
						}
						var cap = parseInt(opt.getAttribute('data-capacity'), 10);
						if (cap > 0 && capInput && (!capInput.value || capInput.value == '500')) {
							capInput.value = cap;
						}
					}
				});
			}
		});
		</script>
		<?php
	}

	/**
	 * Add custom columns to Event list table in wp-admin.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function set_custom_columns( $columns ) {
		$new_cols = array();
		foreach ( $columns as $k => $v ) {
			$new_cols[ $k ] = $v;
			if ( 'title' === $k ) {
				$new_cols['cyb_period']   = __( 'Zeitraum', 'wm-cyber-events-core' );
				$new_cols['cyb_location'] = __( 'Veranstaltungsort', 'wm-cyber-events-core' );
				$new_cols['cyb_capacity'] = __( 'Kapazität & Belegung', 'wm-cyber-events-core' );
			}
		}
		return $new_cols;
	}

	/**
	 * Render Custom Columns for Event list table.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_custom_columns( $column, $post_id ) {
		global $wpdb;

		if ( 'cyb_period' === $column ) {
			$start = get_post_meta( $post_id, '_cybevents_start_date', true );
			echo esc_html( $start ? gmdate( 'd.m.Y H:i', strtotime( $start ) ) : '—' );
		} elseif ( 'cyb_location' === $column ) {
			$terms = wp_get_post_terms( $post_id, 'cybvenue' );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				$term      = $terms[0];
				$edit_link = admin_url( 'term.php?taxonomy=cybvenue&tag_ID=' . $term->term_id . '&post_type=cybevent' );
				echo '<a href="' . esc_url( $edit_link ) . '"><strong>' . esc_html( $term->name ) . '</strong></a>';
				$city = get_term_meta( $term->term_id, '_cyb_venue_city', true );
				if ( $city ) {
					echo '<br><span style="color: #64748b; font-size: 11px;">' . esc_html( $city ) . '</span>';
				}
			} else {
				$loc = get_post_meta( $post_id, '_cybevents_location', true );
				echo esc_html( $loc ?: '—' );
			}
		} elseif ( 'cyb_capacity' === $column ) {
			$cap = (int) ( get_post_meta( $post_id, '_cybevents_max_capacity', true ) ?: 500 );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ev_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cybevents_events WHERE wp_post_id = %d", $post_id ) );
			$conf  = $ev_id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE event_id = %d AND status = 'confirmed'", $ev_id ) ) : 0;
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$pct = min( 100, round( ( $conf / max( 1, $cap ) ) * 100 ) );
			echo '<strong>' . esc_html( $conf ) . '</strong> / ' . esc_html( $cap ) . ' (' . esc_html( $pct ) . '%)';
		}
	}

	/**
	 * Add custom row actions for cybevent post type.
	 *
	 * @param array   $actions An array of row action links.
	 * @param WP_Post $post    The post object.
	 * @return array Modified actions.
	 */
	public static function filter_post_row_actions( $actions, $post ) {
		if ( ! $post || 'cybevent' !== $post->post_type ) {
			return $actions;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ev_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cybevents_events WHERE wp_post_id = %d", $post->ID ) );
		$count = 0;
		if ( $ev_id ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE event_id = %d AND status = 'confirmed'", $ev_id ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $ev_id ) {
			$actions['attendees'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=cybevents-registrations&event_id=' . $ev_id ) ),
				/* translators: %d: Confirmed attendee count */
				sprintf( esc_html__( 'Teilnehmer (%d)', 'wm-cyber-events-core' ), $count )
			);
		}

		return $actions;
	}

	/**
	 * Get or create WordPress CPT Post for a relational Event row.
	 *
	 * @param int $event_id Relational Event ID.
	 * @return int WordPress Post ID.
	 */
	public static function get_or_create_cpt_for_event( $event_id ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( ! $event_id ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ev = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cybevents_events WHERE id = %d", $event_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $ev ) {
			return 0;
		}

		if ( ! empty( $ev->wp_post_id ) ) {
			$existing_post = get_post( $ev->wp_post_id );
			if ( $existing_post && 'cybevent' === $existing_post->post_type ) {
				return (int) $ev->wp_post_id;
			}
		}

		// Create CPT post
		$post_id = wp_insert_post(
			array(
				'post_title'   => $ev->title ?: 'Cyber Security Event ' . $ev->id,
				'post_name'    => $ev->slug ?: 'cyber-event-' . $ev->id,
				'post_content' => ! empty( $ev->description ) ? $ev->description : '<!-- wp:wm-cyber-events/event-hero {"eventId":' . $event_id . '} /-->',
				'post_status'  => ( 'active' === $ev->status ) ? 'publish' : 'draft',
				'post_type'    => 'cybevent',
			)
		);

		if ( $post_id && ! is_wp_error( $post_id ) ) {
			update_post_meta( $post_id, '_cybevents_location', $ev->location );
			update_post_meta( $post_id, '_cybevents_start_date', $ev->start_date );
			update_post_meta( $post_id, '_cybevents_end_date', $ev->end_date );
			update_post_meta( $post_id, '_cybevents_max_capacity', $ev->max_capacity );
			update_post_meta( $post_id, '_cybevents_ticket_price', $ev->ticket_price );
			update_post_meta( $post_id, '_cybevents_is_paid', $ev->is_paid );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				"{$wpdb->prefix}cybevents_events",
				array( 'wp_post_id' => $post_id ),
				array( 'id' => $event_id ),
				array( '%d' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $post_id;
		}

		return 0;
	}

	/**
	 * Sync CPT saves to relational table.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post Object.
	 */
	public static function sync_cpt_to_table( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( 'cybevent' !== $post->post_type ) {
			return;
		}

		// Save Meta Box values if submitted
		if ( isset( $_POST['cybevents_event_meta_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['cybevents_event_meta_nonce'] ), 'cybevents_save_event_meta' ) ) {
			if ( isset( $_POST['_cybevents_venue_id'] ) ) {
				$venue_id = absint( $_POST['_cybevents_venue_id'] );
				if ( $venue_id > 0 ) {
					wp_set_object_terms( $post_id, array( $venue_id ), 'cybvenue' );
				} else {
					wp_set_object_terms( $post_id, array(), 'cybvenue' );
				}
			}
			if ( isset( $_POST['_cybevents_location'] ) ) {
				update_post_meta( $post_id, '_cybevents_location', sanitize_text_field( wp_unslash( $_POST['_cybevents_location'] ) ) );
			}
			if ( isset( $_POST['_cybevents_start_date'] ) ) {
				update_post_meta( $post_id, '_cybevents_start_date', sanitize_text_field( wp_unslash( $_POST['_cybevents_start_date'] ) ) );
			}
			if ( isset( $_POST['_cybevents_end_date'] ) ) {
				update_post_meta( $post_id, '_cybevents_end_date', sanitize_text_field( wp_unslash( $_POST['_cybevents_end_date'] ) ) );
			}
			if ( isset( $_POST['_cybevents_max_capacity'] ) ) {
				update_post_meta( $post_id, '_cybevents_max_capacity', absint( $_POST['_cybevents_max_capacity'] ) );
			}
			if ( isset( $_POST['_cybevents_ticket_price'] ) ) {
				update_post_meta( $post_id, '_cybevents_ticket_price', (float) $_POST['_cybevents_ticket_price'] );
			}
			update_post_meta( $post_id, '_cybevents_is_paid', ! empty( $_POST['_cybevents_is_paid'] ) ? 1 : 0 );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cybevents_events WHERE wp_post_id = %d", $post_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$title        = sanitize_text_field( $post->post_title );
		$slug         = sanitize_title( $post->post_name );
		$description  = wp_kses_post( $post->post_content );
		$location     = sanitize_text_field( get_post_meta( $post_id, '_cybevents_location', true ) );
		$start_date   = sanitize_text_field( get_post_meta( $post_id, '_cybevents_start_date', true ) );
		$end_date     = sanitize_text_field( get_post_meta( $post_id, '_cybevents_end_date', true ) );
		$capacity     = absint( get_post_meta( $post_id, '_cybevents_max_capacity', true ) );
		$ticket_price = (float) get_post_meta( $post_id, '_cybevents_ticket_price', true );
		$is_paid      = absint( get_post_meta( $post_id, '_cybevents_is_paid', true ) );

		if ( empty( $start_date ) ) {
			$start_date = current_time( 'mysql' );
		}
		if ( empty( $end_date ) ) {
			$end_date = current_time( 'mysql' );
		}

		$data = array(
			'wp_post_id'   => $post_id,
			'title'        => $title,
			'slug'         => $slug,
			'description'  => $description,
			'location'     => $location,
			'start_date'   => $start_date,
			'end_date'     => $end_date,
			'max_capacity' => $capacity,
			'is_paid'      => $is_paid,
			'ticket_price' => $ticket_price,
			'status'       => ( 'publish' === $post->post_status ) ? 'active' : 'draft',
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $exists ) {
			$wpdb->update( "{$wpdb->prefix}cybevents_events", $data, array( 'wp_post_id' => $post_id ) );
		} else {
			$wpdb->insert( "{$wpdb->prefix}cybevents_events", $data );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Render Public Conference Agenda Schedule Matrix [cybevents_agenda].
	 *
	 * @param array $atts Shortcode or Block attributes.
	 * @return string HTML output.
	 */
	public static function render_agenda( $atts ) {
		$atts = shortcode_atts(
			array(
				'event_id'             => 0,
				'eventId'              => 0,
				'layoutStyle'          => 'matrix-timeline',
				'heading'              => '',
				'subheading'           => '',
				'showFilterButtons'    => true,
				'showTrackBadges'      => true,
				'showRoomInfo'         => true,
				'showSpeakerAvatars'   => true,
				'showExportButton'     => true,
				'showSessionExport'    => true,
				'showCapacityProgress' => true,
				'showSearchBar'        => true,
			),
			$atts,
			'cybevents_agenda'
		);

		global $wpdb;
		$event_id = (int) ( $atts['eventId'] ?: $atts['event_id'] );

		if ( ! $event_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$event_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cybevents_events WHERE status = %s ORDER BY start_date ASC LIMIT 1", 'active' ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		if ( ! $event_id ) {
			return '<div class="cybevents-notice cybevents-notice-info"><p>' . esc_html__( 'Aktuell sind keine Konferenzpläne verfügbar.', 'wm-cyber-events-core' ) . '</p></div>';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$event = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cybevents_events WHERE id = %d", $event_id )
		);

		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cybevents_sessions WHERE event_id = %d ORDER BY start_time ASC",
				$event_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $event ) {
			return '';
		}

		$calendar_url = rest_url( 'cybevents/v1/calendar?event_id=' . $event->id );
		$layout_style = sanitize_html_class( 'cybevents-agenda--layout-' . $atts['layoutStyle'] );
		$heading      = ! empty( $atts['heading'] ) ? (string) $atts['heading'] : (string) $event->title;
		$subheading   = ! empty( $atts['subheading'] ) ? (string) $atts['subheading'] : '';

		ob_start();
		?>
		<div class="cybevents-agenda-wrapper <?php echo esc_attr( $layout_style ); ?>" id="cybevents-agenda-<?php echo esc_attr( $event->id ); ?>" data-event-id="<?php echo esc_attr( $event->id ); ?>">
			<!-- Agenda Header -->
			<div class="cybevents-agenda-header">
				<div class="cybevents-agenda-title-block">
					<span class="cybevents-badge-official">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
						<?php esc_html_e( 'Programm', 'wm-cyber-events-core' ); ?>
					</span>
					<h2 class="cybevents-agenda-title"><?php echo esc_html( $heading ); ?></h2>
					<?php if ( $subheading ) : ?>
						<p class="cybevents-agenda-subheading" style="opacity: 0.85; margin: 4px 0 12px 0;"><?php echo esc_html( $subheading ); ?></p>
					<?php endif; ?>
					<p class="cybevents-agenda-meta">
						<?php if ( '' !== (string) $event->location ) : ?><span><?php echo CyberEvents_Icons::svg( 'map-pin', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $event->location ); ?></span><?php endif; ?>
						<span><?php echo CyberEvents_Icons::svg( 'calendar', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( date_i18n( 'd. F Y', strtotime( $event->start_date ) ) ); ?></span>
					</p>
				</div>
				<?php if ( ! empty( $atts['showExportButton'] ) ) : ?>
					<div class="cybevents-agenda-actions">
						<a href="<?php echo esc_url( $calendar_url ); ?>" class="cybevents-btn-secondary" download="<?php echo esc_attr( sanitize_title( $event->title ) ); ?>.ics">
							<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
							<?php esc_html_e( 'In Kalender exportieren (.ics)', 'wm-cyber-events-core' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $atts['showSearchBar'] ) && ! empty( $sessions ) ) : ?>
				<div class="cybevents-agenda-toolbar" style="margin-bottom: 20px;">
					<div class="cybevents-search-box" style="position: relative; max-width: 360px;">
						<input type="text" class="cybevents-input cybevents-agenda-search-input" placeholder="<?php esc_attr_e( 'Thema, Referent oder Raum suchen...', 'wm-cyber-events-core' ); ?>" oninput="var q=this.value.toLowerCase(); document.querySelectorAll('#cybevents-agenda-<?php echo esc_attr( $event->id ); ?> .cybevents-agenda-card').forEach(function(el){ el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none'; });" style="padding-left: 36px; border-radius: 8px;" />
						<span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); opacity: 0.5;"><?php echo CyberEvents_Icons::svg( 'search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					</div>
				</div>
			<?php endif; ?>

			<!-- Sessions Timeline Matrix -->
			<div class="cybevents-agenda-timeline" role="feed" aria-label="<?php esc_attr_e( 'Konferenz-Agenda', 'wm-cyber-events-core' ); ?>">
				<?php if ( empty( $sessions ) ) : ?>
					<p class="cybevents-empty-text"><?php esc_html_e( 'Detailliertes Programm wird in Kürze bekanntgegeben.', 'wm-cyber-events-core' ); ?></p>
				<?php else : ?>
					<?php foreach ( $sessions as $s ) : ?>
						<article class="cybevents-agenda-card" aria-labelledby="session-title-<?php echo esc_attr( $s->id ); ?>">
							<div class="cybevents-agenda-time-col">
								<time class="cybevents-time-start" datetime="<?php echo esc_attr( $s->start_time ); ?>">
									<?php echo esc_html( date_i18n( 'H:i', strtotime( $s->start_time ) ) ); ?>
								</time>
								<span class="cybevents-time-sep">–</span>
								<time class="cybevents-time-end" datetime="<?php echo esc_attr( $s->end_time ); ?>">
									<?php echo esc_html( date_i18n( 'H:i', strtotime( $s->end_time ) ) ); ?> Uhr
								</time>
								<?php if ( ! empty( $atts['showRoomInfo'] ) && ! empty( $s->raum ) ) : ?>
									<span class="cybevents-room-tag"><?php echo CyberEvents_Icons::svg( 'building', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( $s->raum ); ?></span>
								<?php endif; ?>
							</div>

							<div class="cybevents-agenda-body-col">
								<h3 class="cybevents-agenda-session-title" id="session-title-<?php echo esc_attr( $s->id ); ?>">
									<?php echo esc_html( $s->title ); ?>
								</h3>
								<?php if ( ! empty( $s->speaker ) ) : ?>
									<div class="cybevents-agenda-speaker">
										<?php if ( ! empty( $atts['showSpeakerAvatars'] ) ) : ?>
											<?php
											$name_parts = explode( ' ', trim( $s->speaker ) );
											$initials   = '';
											foreach ( $name_parts as $part ) {
												if ( ! in_array( strtolower( $part ), array( 'dr.', 'prof.', 'von', 'und', 'de' ), true ) ) {
													$initials .= mb_substr( $part, 0, 1 );
												}
											}
											$initials = mb_substr( $initials, 0, 2 ) ?: 'SP';
											?>
											<span class="cybevents-speaker-mini-avatar"><?php echo esc_html( strtoupper( $initials ) ); ?></span>
										<?php else : ?>
											<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
										<?php endif; ?>
										<strong><?php echo esc_html( $s->speaker ); ?></strong>
									</div>
								<?php endif; ?>
								<?php if ( ! empty( $s->description ) ) : ?>
									<div class="cybevents-agenda-desc">
										<?php echo wp_kses_post( wpautop( $s->description ) ); ?>
									</div>
								<?php endif; ?>
							</div>
						</article>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
		$agenda_html = ob_get_clean();

		// Append JSON-LD Structured Data for AI & Search Engines
		$json_ld = self::render_schema_json_ld( $event->id );

		return $agenda_html . "\n" . $json_ld;
	}

	/**
	 * Render Schema.org JSON-LD Structured Data for AI & Search Engines (BusinessEvent & subEvent).
	 *
	 * @param int $event_id Event ID.
	 * @return string JSON-LD script tag.
	 */
	public static function render_schema_json_ld( $event_id ) {
		$event = CyberEvents_Database::get_event( $event_id );
		if ( ! $event ) {
			return '';
		}

		// Until 2026-09-15 every event named a client as organizer (with its URL), every location was put
		// in "Halle (Saale)" with a client's venue as fallback, and times were stamped as UTC although
		// they are the site's local time. Now only stored data is used; what is not stored is left out.
		$as_iso = static fn( $site_time ) => wp_date( 'c', CyberEvents_Calendar_Exporter::site_time_to_timestamp( $site_time ) );
		$place  = static fn( $name ) => '' !== trim( (string) $name ) ? array( '@type' => 'Place', 'name' => trim( (string) $name ) ) : null;

		$sessions   = CyberEvents_Database::get_sessions_by_event( $event_id );
		$sub_events = array();

		foreach ( (array) $sessions as $sess ) {
			$sub_event = array(
				'@type'       => 'BusinessEvent',
				'name'        => $sess->title,
				'description' => wp_strip_all_tags( $sess->description ?: $sess->title ),
				'startDate'   => $as_iso( $sess->start_time ),
				'endDate'     => $as_iso( $sess->end_time ),
			);
			$sess_place = $place( implode( ', ', array_filter( array( trim( (string) $sess->raum ), trim( (string) $event->location ) ), 'strlen' ) ) );
			if ( $sess_place ) {
				$sub_event['location'] = $sess_place;
			}
			if ( ! empty( $sess->speaker ) ) {
				$sub_event['performer'] = array(
					'@type' => 'Person',
					'name'  => $sess->speaker,
				);
			}
			$sub_events[] = $sub_event;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$confirmed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE event_id = %d AND status = 'confirmed'", $event->id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sold_out = (int) $event->max_capacity > 0 && $confirmed >= (int) $event->max_capacity;

		$schema = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'BusinessEvent',
			'name'                => $event->title,
			'description'         => wp_strip_all_tags( $event->description ?: $event->title ),
			'startDate'           => $as_iso( $event->start_date ),
			'endDate'             => $as_iso( $event->end_date ),
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'offers'              => array(
				'@type'         => 'Offer',
				'price'         => number_format( (float) ( $event->ticket_price ?: 0.00 ), 2, '.', '' ),
				'priceCurrency' => 'EUR',
				'availability'  => $sold_out ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
			),
		);

		$event_place = $place( $event->location );
		if ( $event_place ) {
			$schema['location'] = $event_place;
		}

		// The organizer is the invoicing party from the settings, when one is set.
		$seller = CyberEvents_Invoicing::seller();
		if ( '' !== $seller['name'] ) {
			$schema['organizer'] = array(
				'@type' => 'Organization',
				'name'  => $seller['name'],
				'url'   => home_url( '/' ),
			);
		}

		if ( ! empty( $sub_events ) ) {
			$schema['subEvent'] = $sub_events;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}
}
