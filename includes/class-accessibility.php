<?php
/**
 * Public registration form and the Friendly Captcha check.
 *
 * The form uses visible labels, aria-required on required fields and a live region for feedback.
 * No conformity with an accessibility standard has been measured.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Accessibility {

	/**
	 * Whether a Friendly Captcha widget ships with the plugin.
	 *
	 * public/js/friendly-captcha.min.js is a 14-line placeholder that renders no widget and writes
	 * no solution. Until 2026-09-15 enabling the setting with both keys made the server demand a
	 * solution no browser could produce, so every registration answered 403.
	 *
	 * @return bool
	 */
	public static function captcha_widget_available() {
		return false;
	}

	/**
	 * Whether the captcha is switched on in the settings with both keys.
	 *
	 * @return bool
	 */
	public static function captcha_configured() {
		$settings = get_option( 'cybevents_settings', array() );
		return ! empty( $settings['enable_friendly_captcha'] ) && '' !== (string) ( $settings['friendly_captcha_api_key'] ?? '' ) && '' !== (string) ( $settings['friendly_captcha_sitekey'] ?? '' );
	}

	/**
	 * Verify Friendly Captcha solution.
	 *
	 * @param string $solution Solution token from form.
	 * @return bool True if valid or captcha disabled, false otherwise.
	 */
	public static function verify_friendly_captcha( $solution ) {
		$settings = get_option( 'cybevents_settings', array() );
		if ( empty( $settings['enable_friendly_captcha'] ) ) {
			return true;
		}

		$api_key = $settings['friendly_captcha_api_key'] ?? '';
		$sitekey = $settings['friendly_captcha_sitekey'] ?? '';

		if ( empty( $api_key ) || empty( $sitekey ) ) {
			return true;
		}

		if ( empty( $solution ) ) {
			return false;
		}

		$response = wp_remote_post(
			'https://api.friendlycaptcha.com/api/v1/siteverify',
			array(
				'body'    => wp_json_encode(
					array(
						'solution' => sanitize_text_field( $solution ),
						'secret'   => $api_key,
						'sitekey'  => $sitekey,
					)
				),
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $body['success'] ) && true === $body['success'];
	}

	/**
	 * Render the public registration form.
	 *
	 * @param int   $event_id Event ID.
	 * @param array $options  Optional block customization attributes.
	 * @return string HTML output.
	 */
	public static function render_registration_form( $event_id, $options = array() ) {
		global $wpdb;

		$event_id = absint( $event_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$event = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cybevents_events WHERE id = %d AND status = 'active'", $event_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $event ) {
			return '<p class="cybevents-notice cybevents-notice--error">' . esc_html__( 'Veranstaltung nicht gefunden oder nicht mehr aktiv.', 'wm-cyber-events-core' ) . '</p>';
		}

		$settings = get_option( 'cybevents_settings', array() );
		$nonce    = wp_create_nonce( 'cybevents_public_nonce' );

		$start_formatted = gmdate( 'd.m.Y', strtotime( $event->start_date ) );
		$location        = (string) $event->location; // Filled with a client's headquarters when empty until 2026-09-15.

		$show_key_facts      = $options['showKeyFacts'] ?? true;
		$show_stepper        = $options['showStepper'] ?? true;
		$show_company        = $options['showCompanyField'] ?? true;
		// Position, phone and dietary notes are not rendered: the registration route never stored them (the
		// block options showJobTitle, showPhoneField and showDietary are kept so saved blocks still parse).
		$show_avatar_upload  = $options['showAvatarUpload'] ?? true;
		$show_photo          = $options['showPhotoConsent'] ?? true;
		$custom_title        = ! empty( $options['customTitle'] ) ? (string) $options['customTitle'] : (string) $event->title;
		$custom_subtitle     = ! empty( $options['customSubtitle'] ) ? (string) $options['customSubtitle'] : '';
		$button_text         = ! empty( $options['buttonText'] ) ? (string) $options['buttonText'] : ( (float) $event->ticket_price > 0 ? __( 'Verbindlich anmelden', 'wm-cyber-events-core' ) : __( 'Kostenfrei anmelden', 'wm-cyber-events-core' ) );
		// Without a URL in the block the consent had no link to the privacy policy until 2026-09-15. The
		// WordPress privacy page is the default; get_privacy_policy_url() is '' when none is published.
		$privacy_url         = ! empty( $options['privacyPolicyUrl'] ) ? esc_url( $options['privacyPolicyUrl'] ) : esc_url( get_privacy_policy_url() );
		$form_style          = ! empty( $options['formStyle'] ) ? sanitize_html_class( $options['formStyle'] ) : 'card-glass';
		$border_radius       = ! empty( $options['borderRadius'] ) ? sanitize_html_class( 'cyb-radius-' . $options['borderRadius'] ) : 'cyb-radius-lg';

		ob_start();
		?>
		<div class="cybevents-form-container cybevents-style-<?php echo esc_attr( $form_style ); ?> <?php echo esc_attr( $border_radius ); ?>" id="cybevents-registration-form">
			<?php if ( $show_key_facts ) : ?>
				<!-- Event Showcase Key Facts Header -->
				<div class="cybevents-event-header">
					<?php // Two badges, "Offizielle Konferenz" and "DSGVO & BFSG 2025 Konform", stood here until 2026-09-15. ?>
					<h2 class="cybevents-event-title"><?php echo esc_html( $custom_title ); ?></h2>
					<?php if ( $custom_subtitle ) : ?>
						<p class="cybevents-event-subtitle" style="font-size: 14px; opacity: 0.85; margin: 4px 0 16px 0;"><?php echo esc_html( $custom_subtitle ); ?></p>
					<?php endif; ?>
					<div class="cybevents-event-meta-grid">
						<div class="cybevents-meta-item">
							<div class="cybevents-meta-icon"><?php echo CyberEvents_Icons::svg( 'calendar', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
							<div>
								<p class="cybevents-meta-label"><?php esc_html_e( 'Datum', 'wm-cyber-events-core' ); ?></p>
								<p class="cybevents-meta-value"><?php echo esc_html( $start_formatted ); ?></p>
							</div>
						</div>
						<?php if ( '' !== $location ) : ?>
							<div class="cybevents-meta-item">
							<div class="cybevents-meta-icon"><?php echo CyberEvents_Icons::svg( 'map-pin', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
								<div>
								<p class="cybevents-meta-label"><?php esc_html_e( 'Veranstaltungsort', 'wm-cyber-events-core' ); ?></p>
							<p class="cybevents-meta-value"><?php echo esc_html( $location ); ?></p>
						</div>
						</div>
						<?php endif; ?>
						<div class="cybevents-meta-item">
							<div class="cybevents-meta-icon"><?php echo CyberEvents_Icons::svg( 'users', 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
							<div>
								<p class="cybevents-meta-label"><?php esc_html_e( 'Einlass', 'wm-cyber-events-core' ); ?></p>
								<p class="cybevents-meta-value"><?php esc_html_e( 'Nach Bestätigung per E-Mail-Link', 'wm-cyber-events-core' ); ?></p>
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<!-- Stepped Enterprise Form -->
			<form id="cybevents-reg-form-<?php echo esc_attr( $event_id ); ?>" class="cybevents-form" method="post" action="" novalidate>
				<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" />
				<input type="hidden" name="_cybevents_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
				<input type="text" name="cybevents_hp" style="display:none !important;" tabindex="-1" autocomplete="off" aria-hidden="true" />

				<?php if ( $show_stepper ) : ?>
					<!-- Stepper Indicator -->
					<div class="cybevents-stepper" aria-hidden="true">
						<div class="cybevents-step-node is-active">
							<div class="cybevents-step-bubble">1</div>
							<span class="cybevents-step-label"><?php esc_html_e( 'Kontaktdaten', 'wm-cyber-events-core' ); ?></span>
						</div>
						<div class="cybevents-step-node">
							<div class="cybevents-step-bubble">2</div>
							<span class="cybevents-step-label"><?php esc_html_e( 'Einwilligung', 'wm-cyber-events-core' ); ?></span>
						</div>
						<div class="cybevents-step-node">
							<div class="cybevents-step-bubble">3</div>
							<span class="cybevents-step-label"><?php esc_html_e( 'Bestätigung', 'wm-cyber-events-core' ); ?></span>
						</div>
					</div>
				<?php endif; ?>

				<div class="cybevents-form-row">
					<div class="cybevents-field-group">
						<label for="cybevents-first-name" class="cybevents-label">
							<?php echo esc_html__( 'Vorname', 'wm-cyber-events-core' ); ?> <span class="cybevents-required" aria-hidden="true">*</span>
						</label>
						<input type="text" id="cybevents-first-name" name="first_name" class="cybevents-input" required autocomplete="given-name" aria-required="true" placeholder="<?php esc_attr_e( 'z. B. Erika', 'wm-cyber-events-core' ); ?>" />
					</div>

					<div class="cybevents-field-group">
						<label for="cybevents-last-name" class="cybevents-label">
							<?php echo esc_html__( 'Nachname', 'wm-cyber-events-core' ); ?> <span class="cybevents-required" aria-hidden="true">*</span>
						</label>
						<input type="text" id="cybevents-last-name" name="last_name" class="cybevents-input" required autocomplete="family-name" aria-required="true" placeholder="<?php esc_attr_e( 'z. B. Mustermann', 'wm-cyber-events-core' ); ?>" />
					</div>
				</div>

				<div class="cybevents-field-group">
					<label for="cybevents-email" class="cybevents-label">
						<?php echo esc_html__( 'Geschäftliche E-Mail-Adresse', 'wm-cyber-events-core' ); ?> <span class="cybevents-required" aria-hidden="true">*</span>
					</label>
					<input type="email" id="cybevents-email" name="email" class="cybevents-input" required autocomplete="email" aria-required="true" placeholder="name@example.org" />
				</div>

				<?php if ( $show_company ) : ?>
					<div class="cybevents-field-group">
						<label for="cybevents-company" class="cybevents-label">
							<?php echo esc_html__( 'Institution / Behörde / Unternehmen', 'wm-cyber-events-core' ); ?>
						</label>
						<input type="text" id="cybevents-company" name="company" class="cybevents-input" autocomplete="organization" placeholder="<?php esc_attr_e( 'z. B. Beispiel GmbH', 'wm-cyber-events-core' ); ?>" />
					</div>
				<?php endif; ?>

				<?php if ( $show_avatar_upload ) : ?>
					<div class="cybevents-field-group cybevents-avatar-field-group">
						<label for="cybevents-avatar-upload" class="cybevents-label">
							<?php echo esc_html__( 'Profilfoto für Teilnehmerpass (Optional)', 'wm-cyber-events-core' ); ?>
						</label>
						<div class="cybevents-avatar-dropzone">
							<div class="cybevents-avatar-preview-wrap" id="cybevents-avatar-preview-wrap">
								<div class="cybevents-avatar-placeholder" id="cybevents-avatar-placeholder">
									<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
								</div>
								<img id="cybevents-avatar-preview-img" src="" alt="<?php esc_attr_e( 'Profilbild Vorschau', 'wm-cyber-events-core' ); ?>" style="display: none; width: 100%; height: 100%; object-fit: cover; border-radius: 50%;" />
							</div>
							<div class="cybevents-avatar-controls">
								<input type="file" id="cybevents-avatar-upload" name="avatar" accept="image/jpeg,image/png,image/webp" class="cybevents-file-input" style="display: none;" />
								<button type="button" class="cybevents-file-btn" onclick="document.getElementById('cybevents-avatar-upload').click();">
									<?php echo CyberEvents_Icons::svg( 'camera', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php esc_html_e( 'Foto auswählen', 'wm-cyber-events-core' ); ?>
								</button>
								<span class="cybevents-file-hint" id="cybevents-file-hint"><?php esc_html_e( 'JPG, PNG oder WebP · max. 5 MB', 'wm-cyber-events-core' ); ?></span>
							</div>
						</div>
					</div>
				<?php endif; ?>

				<!-- Consent Cards -->
				<label class="cybevents-consent-card" for="cybevents-consent-privacy">
					<input type="checkbox" id="cybevents-consent-privacy" name="consent_privacy" value="1" required class="cybevents-checkbox" aria-required="true" />
					<div class="cybevents-consent-text">
						<strong><?php echo esc_html__( 'Datenschutz & Double Opt-In Einwilligung *', 'wm-cyber-events-core' ); ?></strong>
						<?php echo esc_html__( 'Ich willige in die Verarbeitung meiner Daten zur Konferenzteilnahme gemäß DSGVO ein. Zur Verifizierung erhalte ich einen Bestätigungslink per E-Mail.', 'wm-cyber-events-core' ); ?>
						<?php if ( $privacy_url ) : ?>
							<a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener" class="cybevents-privacy-link"><?php esc_html_e( 'Datenschutzerklärung lesen', 'wm-cyber-events-core' ); ?></a>
						<?php endif; ?>
					</div>
				</label>

				<?php if ( $show_photo ) : ?>
					<label class="cybevents-consent-card" for="cybevents-consent-photo">
						<input type="checkbox" id="cybevents-consent-photo" name="consent_photo" value="1" class="cybevents-checkbox" />
						<div class="cybevents-consent-text">
							<strong><?php echo esc_html__( 'Foto- & Videoaufnahmen (§§ 22, 23 KUG)', 'wm-cyber-events-core' ); ?></strong>
							<?php echo esc_html__( 'Ich willige ein, dass im Rahmen der Veranstaltung Bild- und Videoaufnahmen von mir für die Öffentlichkeitsarbeit des Veranstalters erstellt werden dürfen.', 'wm-cyber-events-core' ); ?>
						</div>
					</label>
				<?php endif; ?>

				<?php if ( self::captcha_widget_available() && ! empty( $settings['enable_friendly_captcha'] ) && ! empty( $settings['friendly_captcha_sitekey'] ) ) : ?>
					<div class="cybevents-captcha-container">
						<div class="frc-captcha" data-sitekey="<?php echo esc_attr( $settings['friendly_captcha_sitekey'] ); ?>" data-lang="<?php echo esc_attr( determine_locale() ); ?>"></div>
					</div>
				<?php endif; ?>

				<div class="cybevents-submit-group">
					<button type="submit" class="cybevents-btn-submit">
						<span><?php echo esc_html( $button_text ); ?></span>
						<span aria-hidden="true">→</span>
					</button>
				</div>

				<div id="cybevents-form-feedback" class="cybevents-feedback" role="alert" aria-live="polite"></div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
