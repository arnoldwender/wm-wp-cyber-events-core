<?php
/**
 * Brevo (Sendinblue) API v3 Integration Client (Security Hardened).
 *
 * Implements Open-Redirect & SSRF defense on DOI callback URLs,
 * and rate-limit compliant payloads.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Brevo_API {

	const API_BASE = 'https://api.brevo.com/v3';

	/**
	 * Get the configured API key.
	 *
	 * @return string
	 */
	private static function get_api_key() {
		$settings = get_option( 'cybevents_settings', array() );
		return isset( $settings['brevo_api_key'] ) ? trim( $settings['brevo_api_key'] ) : '';
	}

	/**
	 * Whether a Brevo API key is stored.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::get_api_key();
	}

	/**
	 * Error for a call that cannot be made without an API key.
	 *
	 * @return WP_Error
	 */
	private static function not_configured_error() {
		return new WP_Error(
			'cybevents_brevo_not_configured',
			'Kein Brevo-API-Schlüssel hinterlegt: an Brevo wurde nichts übermittelt.',
			array( 'status' => 503 )
		);
	}

	/**
	 * Validate and sanitize DOI redirection URL (Protects against Open Redirect).
	 *
	 * @param string $url URL to validate.
	 * @return string Safe validated URL matching site host.
	 */
	public static function validate_safe_redirect_url( $url ) {
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$target_host = wp_parse_url( $url, PHP_URL_HOST );

		if ( empty( $target_host ) || strtolower( $target_host ) === strtolower( $site_host ) ) {
			return esc_url_raw( $url );
		}

		// Foreign host: fall back to the front page (the slug /session-auswahl/ used until
		// 2026-09-15 exists on no install unless someone created it by hand).
		return home_url( '/' );
	}

	/**
	 * Send Double Opt-In request to Brevo API v3 (Req. #15, #19).
	 *
	 * @param array $args Data array including email, attributes, token, etc.
	 * @return array|WP_Error
	 */
	public static function send_double_optin( $args ) {
		$api_key = self::get_api_key();
		if ( empty( $api_key ) ) {
			// Until 2026-09-15 a missing key returned a simulated success: the job was marked
			// completed and the admin saw a sent mail that never left the server.
			return self::not_configured_error();
		}

		$settings        = get_option( 'cybevents_settings', array() );
		$template_id     = isset( $settings['brevo_doi_template_id'] ) ? absint( $settings['brevo_doi_template_id'] ) : 1;
		$list_id         = isset( $settings['brevo_list_id'] ) ? absint( $settings['brevo_list_id'] ) : 2;
		$redirection_url = self::validate_safe_redirect_url( $args['redirection_url'] ?? home_url() );

		$payload = array(
			'email'          => sanitize_email( $args['email'] ),
			'attributes'     => array(
				'FIRSTNAME'   => sanitize_text_field( $args['first_name'] ?? '' ),
				'LASTNAME'    => sanitize_text_field( $args['last_name'] ?? '' ),
				'COMPANY'     => sanitize_text_field( $args['company'] ?? '' ),
				'EVENT_TITLE' => sanitize_text_field( $args['event_title'] ?? '' ),
			),
			'includeListIds' => array( $list_id ),
			'templateId'     => $template_id,
			'redirectionUrl' => $redirection_url,
		);

		$response = wp_remote_post(
			self::API_BASE . '/contacts/doubleOptinConfirmation',
			array(
				'headers' => array(
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return array(
				'code' => $code,
				'data' => $body,
			);
		}

		return new WP_Error(
			'brevo_api_error',
			isset( $body['message'] ) ? $body['message'] : 'Brevo API Error (' . $code . ')',
			array( 'status' => $code )
		);
	}

	/**
	 * Update contact custom attributes in Brevo (e.g. booked sessions).
	 *
	 * @param string $email Contact email.
	 * @param array  $attributes Key-value array of custom attributes.
	 * @return array|WP_Error
	 */
	public static function update_contact_attributes( $email, $attributes ) {
		$api_key = self::get_api_key();
		if ( empty( $api_key ) ) {
			return self::not_configured_error();
		}

		$payload = array(
			'attributes' => $attributes,
		);

		$response = wp_remote_request(
			self::API_BASE . '/contacts/' . rawurlencode( sanitize_email( $email ) ),
			array(
				'method'  => 'PUT',
				'headers' => array(
					'api-key'      => $api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'code' => $code );
		}

		return new WP_Error(
			'brevo_update_error',
			'Failed to update Brevo contact attributes',
			array( 'status' => $code )
		);
	}
}
