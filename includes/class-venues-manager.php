<?php
/**
 * Venues & Locations Management Controller (Veranstaltungsorte).
 *
 * Venue taxonomy with address, capacity, a wheelchair-access flag and a map link.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Venues_Manager {

	/**
	 * Register Taxonomy for Event Venues / Locations.
	 */
	public static function register_taxonomy() {
		$labels = array(
			'name'                       => _x( 'Veranstaltungsorte', 'taxonomy general name', 'wm-cyber-events-core' ),
			'singular_name'              => _x( 'Veranstaltungsort', 'taxonomy singular name', 'wm-cyber-events-core' ),
			'search_items'               => __( 'Veranstaltungsorte durchsuchen', 'wm-cyber-events-core' ),
			'popular_items'              => __( 'Häufige Tagungsorte', 'wm-cyber-events-core' ),
			'all_items'                  => __( 'Alle Veranstaltungsorte', 'wm-cyber-events-core' ),
			'parent_item'                => null,
			'parent_item_colon'          => null,
			'edit_item'                  => __( 'Veranstaltungsort bearbeiten', 'wm-cyber-events-core' ),
			'update_item'                => __( 'Veranstaltungsort aktualisieren', 'wm-cyber-events-core' ),
			'add_new_item'               => __( 'Neuen Veranstaltungsort hinzufügen', 'wm-cyber-events-core' ),
			'new_item_name'              => __( 'Name des neuen Tagungsorts', 'wm-cyber-events-core' ),
			'separate_items_with_commas' => __( 'Orte mit Kommas trennen', 'wm-cyber-events-core' ),
			'add_or_remove_items'        => __( 'Orte hinzufügen oder entfernen', 'wm-cyber-events-core' ),
			'choose_from_most_used'      => __( 'Aus häufig genutzten Orten wählen', 'wm-cyber-events-core' ),
			'not_found'                  => __( 'Keine Veranstaltungsorte gefunden.', 'wm-cyber-events-core' ),
			'menu_name'                  => __( 'Veranstaltungsorte', 'wm-cyber-events-core' ),
		);

		$args = array(
			'hierarchical'          => false,
			'labels'                => $labels,
			'show_ui'               => true,
			'show_in_menu'          => false, // Registered explicitly in CyberEvents_Admin to maintain strict submenu order
			'show_admin_column'     => true,
			'query_var'             => true,
			'rewrite'               => array( 'slug' => 'veranstaltungsort' ),
			'show_in_rest'          => true,
			'rest_base'             => 'venues',
			'rest_controller_class' => 'WP_REST_Terms_Controller',
		);

		register_taxonomy( 'cybvenue', array( 'cybevent' ), $args );
	}

	/**
	 * Render Custom Meta Fields on Add Venue Screen.
	 */
	public static function render_add_form_fields() {
		wp_nonce_field( 'cybevents_save_venue_meta', 'cybevents_venue_meta_nonce' );
		?>
		<div class="form-field form-required">
			<label for="cyb_venue_city"><?php esc_html_e( 'Stadt / Ort:', 'wm-cyber-events-core' ); ?></label>
			<input type="text" name="cyb_venue_city" id="cyb_venue_city" placeholder="<?php esc_attr_e( 'z. B. Musterstadt', 'wm-cyber-events-core' ); ?>" required />
			<p><?php esc_html_e( 'Hauptstadt oder Gemeinde des Tagungsorts.', 'wm-cyber-events-core' ); ?></p>
		</div>

		<div class="form-field">
			<label for="cyb_venue_address"><?php esc_html_e( 'Straße & Hausnummer:', 'wm-cyber-events-core' ); ?></label>
			<input type="text" name="cyb_venue_address" id="cyb_venue_address" placeholder="<?php esc_attr_e( 'z. B. Musterstraße 1', 'wm-cyber-events-core' ); ?>" />
		</div>

		<div class="form-field">
			<label for="cyb_venue_zip"><?php esc_html_e( 'Postleitzahl (PLZ):', 'wm-cyber-events-core' ); ?></label>
			<input type="text" name="cyb_venue_zip" id="cyb_venue_zip" placeholder="z. B. 06108" style="width: 140px;" />
		</div>

		<div class="form-field">
			<label for="cyb_venue_country"><?php esc_html_e( 'Land:', 'wm-cyber-events-core' ); ?></label>
			<input type="text" name="cyb_venue_country" id="cyb_venue_country" value="Deutschland" />
		</div>

		<div class="form-field">
			<label for="cyb_venue_capacity"><?php esc_html_e( 'Maximale Raumkapazität (Personen):', 'wm-cyber-events-core' ); ?></label>
			<input type="number" name="cyb_venue_capacity" id="cyb_venue_capacity" min="1" max="50000" placeholder="z. B. 500" style="width: 140px;" />
		</div>

		<div class="form-field">
			<label for="cyb_venue_accessible" style="display: flex; align-items: center; gap: 8px;">
				<input type="checkbox" name="cyb_venue_accessible" id="cyb_venue_accessible" value="1" />
				<strong><?php esc_html_e( 'Rollstuhlgerecht zugänglich', 'wm-cyber-events-core' ); ?></strong>
			</label>
			<p><?php esc_html_e( 'Nur ankreuzen, wenn geprüft. Die Angabe erscheint in der Liste der Veranstaltungsorte und in der REST-Route /venues.', 'wm-cyber-events-core' ); ?></p>
		</div>

		<div class="form-field">
			<label for="cyb_venue_maps_url"><?php esc_html_e( 'Google Maps / Wegbeschreibung URL:', 'wm-cyber-events-core' ); ?></label>
			<input type="url" name="cyb_venue_maps_url" id="cyb_venue_maps_url" placeholder="https://maps.example.org/..." />
		</div>

		<div class="form-field">
			<label for="cyb_venue_website"><?php esc_html_e( 'Webseite des Tagungsorts:', 'wm-cyber-events-core' ); ?></label>
			<input type="url" name="cyb_venue_website" id="cyb_venue_website" placeholder="https://www.example.org" />
		</div>
		<?php
	}

	/**
	 * Render Custom Meta Fields on Edit Venue Screen.
	 *
	 * @param WP_Term $term Current term object.
	 */
	public static function render_edit_form_fields( $term ) {
		wp_nonce_field( 'cybevents_save_venue_meta', 'cybevents_venue_meta_nonce' );

		$city       = get_term_meta( $term->term_id, '_cyb_venue_city', true ) ?: '';
		$address    = get_term_meta( $term->term_id, '_cyb_venue_address', true ) ?: '';
		$zip        = get_term_meta( $term->term_id, '_cyb_venue_zip', true ) ?: '';
		$country    = get_term_meta( $term->term_id, '_cyb_venue_country', true ) ?: 'Deutschland';
		$capacity   = get_term_meta( $term->term_id, '_cyb_venue_capacity', true ) ?: '';
		$accessible = get_term_meta( $term->term_id, '_cyb_venue_accessible', true );
		$is_access  = ( '1' === (string) $accessible ); // A venue without the flag counted as accessible until 2026-09-15.
		$maps_url   = get_term_meta( $term->term_id, '_cyb_venue_maps_url', true ) ?: '';
		$website    = get_term_meta( $term->term_id, '_cyb_venue_website', true ) ?: '';
		?>
		<tr class="form-field form-required">
			<th scope="row"><label for="cyb_venue_city"><?php esc_html_e( 'Stadt / Ort:', 'wm-cyber-events-core' ); ?></label></th>
			<td>
				<input type="text" name="cyb_venue_city" id="cyb_venue_city" value="<?php echo esc_attr( $city ); ?>" required />
				<p class="description"><?php esc_html_e( 'Hauptstadt oder Gemeinde des Tagungsorts.', 'wm-cyber-events-core' ); ?></p>
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="cyb_venue_address"><?php esc_html_e( 'Straße & Hausnummer:', 'wm-cyber-events-core' ); ?></label></th>
			<td>
				<input type="text" name="cyb_venue_address" id="cyb_venue_address" value="<?php echo esc_attr( $address ); ?>" />
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="cyb_venue_zip"><?php esc_html_e( 'Postleitzahl (PLZ):', 'wm-cyber-events-core' ); ?></label></th>
			<td>
				<input type="text" name="cyb_venue_zip" id="cyb_venue_zip" value="<?php echo esc_attr( $zip ); ?>" style="width: 140px;" />
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="cyb_venue_country"><?php esc_html_e( 'Land:', 'wm-cyber-events-core' ); ?></label></th>
			<td>
				<input type="text" name="cyb_venue_country" id="cyb_venue_country" value="<?php echo esc_attr( $country ); ?>" />
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="cyb_venue_capacity"><?php esc_html_e( 'Maximale Raumkapazität:', 'wm-cyber-events-core' ); ?></label></th>
			<td>
				<input type="number" name="cyb_venue_capacity" id="cyb_venue_capacity" min="1" max="50000" value="<?php echo esc_attr( $capacity ); ?>" style="width: 140px;" />
				<p class="description"><?php esc_html_e( 'Personenkapazität des Tagungs- und Konferenzbereichs.', 'wm-cyber-events-core' ); ?></p>
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'Zugänglichkeit:', 'wm-cyber-events-core' ); ?></th>
			<td>
				<label style="display: flex; align-items: center; gap: 8px;">
					<input type="checkbox" name="cyb_venue_accessible" id="cyb_venue_accessible" value="1" <?php checked( $is_access ); ?> />
					<strong><?php esc_html_e( 'Rollstuhlgerecht zugänglich', 'wm-cyber-events-core' ); ?></strong>
				</label>
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="cyb_venue_maps_url"><?php esc_html_e( 'Google Maps Link:', 'wm-cyber-events-core' ); ?></label></th>
			<td>
				<input type="url" name="cyb_venue_maps_url" id="cyb_venue_maps_url" value="<?php echo esc_attr( $maps_url ); ?>" />
			</td>
		</tr>

		<tr class="form-field">
			<th scope="row"><label for="cyb_venue_website"><?php esc_html_e( 'Webseite des Tagungsorts:', 'wm-cyber-events-core' ); ?></label></th>
			<td>
				<input type="url" name="cyb_venue_website" id="cyb_venue_website" value="<?php echo esc_attr( $website ); ?>" />
			</td>
		</tr>
		<?php
	}

	/**
	 * Save Custom Meta Fields for Venue.
	 *
	 * @param int $term_id ID of the term.
	 */
	public static function save_term_meta( $term_id ) {
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		if ( ! isset( $_POST['cybevents_venue_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['cybevents_venue_meta_nonce'] ), 'cybevents_save_venue_meta' ) ) {
			return;
		}

		if ( isset( $_POST['cyb_venue_city'] ) ) {
			update_term_meta( $term_id, '_cyb_venue_city', sanitize_text_field( wp_unslash( $_POST['cyb_venue_city'] ) ) );
		}

		if ( isset( $_POST['cyb_venue_address'] ) ) {
			update_term_meta( $term_id, '_cyb_venue_address', sanitize_text_field( wp_unslash( $_POST['cyb_venue_address'] ) ) );
		}

		if ( isset( $_POST['cyb_venue_zip'] ) ) {
			update_term_meta( $term_id, '_cyb_venue_zip', sanitize_text_field( wp_unslash( $_POST['cyb_venue_zip'] ) ) );
		}

		if ( isset( $_POST['cyb_venue_country'] ) ) {
			update_term_meta( $term_id, '_cyb_venue_country', sanitize_text_field( wp_unslash( $_POST['cyb_venue_country'] ) ) );
		}

		if ( isset( $_POST['cyb_venue_capacity'] ) ) {
			update_term_meta( $term_id, '_cyb_venue_capacity', absint( $_POST['cyb_venue_capacity'] ) );
		}

		$accessible = isset( $_POST['cyb_venue_accessible'] ) ? '1' : '0';
		update_term_meta( $term_id, '_cyb_venue_accessible', $accessible );

		if ( isset( $_POST['cyb_venue_maps_url'] ) ) {
			update_term_meta( $term_id, '_cyb_venue_maps_url', esc_url_raw( wp_unslash( $_POST['cyb_venue_maps_url'] ) ) );
		}

		if ( isset( $_POST['cyb_venue_website'] ) ) {
			update_term_meta( $term_id, '_cyb_venue_website', esc_url_raw( wp_unslash( $_POST['cyb_venue_website'] ) ) );
		}
	}

	/**
	 * Add Custom Columns to Venue Admin Table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function set_custom_columns( $columns ) {
		$new_cols = array();
		foreach ( $columns as $k => $v ) {
			$new_cols[ $k ] = $v;
			if ( 'name' === $k ) {
				$new_cols['cyb_city']       = __( 'Stadt & Adresse', 'wm-cyber-events-core' );
				$new_cols['cyb_capacity']   = __( 'Kapazität', 'wm-cyber-events-core' );
				$new_cols['cyb_accessible'] = __( 'Rollstuhlgerecht', 'wm-cyber-events-core' );
			}
		}
		return $new_cols;
	}

	/**
	 * Render Custom Column Content for Venue Admin Table.
	 *
	 * @param string $content Existing content.
	 * @param string $column_name Column key.
	 * @param int    $term_id Term ID.
	 * @return string
	 */
	public static function render_custom_columns( $content, $column_name, $term_id ) {
		if ( 'cyb_city' === $column_name ) {
			$city    = get_term_meta( $term_id, '_cyb_venue_city', true );
			$address = get_term_meta( $term_id, '_cyb_venue_address', true );
			$zip     = get_term_meta( $term_id, '_cyb_venue_zip', true );

			$lines = array();
			if ( $city ) {
				$lines[] = '<strong>' . esc_html( $city ) . '</strong>';
			}
			if ( $address || $zip ) {
				$lines[] = '<span style="color: #64748b; font-size: 12px;">' . esc_html( trim( $zip . ' ' . $address ) ) . '</span>';
			}

			return ! empty( $lines ) ? implode( '<br>', $lines ) : '—';
		}

		if ( 'cyb_capacity' === $column_name ) {
			$capacity = get_term_meta( $term_id, '_cyb_venue_capacity', true );
			return $capacity ? sprintf( '<strong>%s</strong> Pax', number_format_i18n( (int) $capacity ) ) : '—';
		}

		if ( 'cyb_accessible' === $column_name ) {
			$accessible = get_term_meta( $term_id, '_cyb_venue_accessible', true );
			if ( '1' === (string) $accessible ) {
				return '<span style="display: inline-block; padding: 2px 8px; background: #dcfce7; color: #166534; border-radius: 4px; font-weight: 600; font-size: 11px;">' . esc_html__( 'Ja', 'wm-cyber-events-core' ) . '</span>';
			}
			return '<span style="display: inline-block; padding: 2px 8px; background: #f1f5f9; color: #64748b; border-radius: 4px; font-size: 11px;">' . esc_html__( 'Nicht angegeben', 'wm-cyber-events-core' ) . '</span>';
		}

		return $content;
	}

	/**
	 * Get full list of registered venues with metadata.
	 *
	 * @return array
	 */
	public static function get_all_venues() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'cybvenue',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$venues = array();
		foreach ( $terms as $term ) {
			$venues[] = self::get_venue_details( $term );
		}

		return $venues;
	}

	/**
	 * Get rich structured details for a specific venue term.
	 *
	 * @param WP_Term|int $term Term object or ID.
	 * @return array
	 */
	public static function get_venue_details( $term ) {
		if ( ! is_object( $term ) ) {
			$term = get_term( (int) $term, 'cybvenue' );
		}

		if ( ! $term || is_wp_error( $term ) ) {
			return array();
		}

		$city       = get_term_meta( $term->term_id, '_cyb_venue_city', true ) ?: '';
		$address    = get_term_meta( $term->term_id, '_cyb_venue_address', true ) ?: '';
		$zip        = get_term_meta( $term->term_id, '_cyb_venue_zip', true ) ?: '';
		$country    = get_term_meta( $term->term_id, '_cyb_venue_country', true ) ?: 'Deutschland';
		$capacity   = (int) ( get_term_meta( $term->term_id, '_cyb_venue_capacity', true ) ?: 0 );
		$accessible = get_term_meta( $term->term_id, '_cyb_venue_accessible', true );
		$is_access  = ( '1' === (string) $accessible );
		$maps_url   = get_term_meta( $term->term_id, '_cyb_venue_maps_url', true ) ?: '';
		$website    = get_term_meta( $term->term_id, '_cyb_venue_website', true ) ?: '';

		$full_address_parts = array_filter( array( $term->name, $address, trim( $zip . ' ' . $city ), $country ) );
		$display_name       = $term->name . ( $city ? ' (' . $city . ')' : '' );

		return array(
			'id'           => $term->term_id,
			'slug'         => $term->slug,
			'name'         => $term->name,
			'display_name' => $display_name,
			'description'  => $term->description,
			'city'         => $city,
			'address'      => $address,
			'zip'          => $zip,
			'country'      => $country,
			'full_address' => implode( ', ', $full_address_parts ),
			'capacity'     => $capacity,
			'accessible'   => $is_access,
			'maps_url'     => $maps_url ?: ( $city ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( implode( ', ', $full_address_parts ) ) : '' ),
			'website'      => $website,
		);
	}
}
