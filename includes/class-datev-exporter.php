<?php
/**
 * DATEV-Format export of invoices as a Buchungsstapel (EXTF, version 700, category 21, format
 * version 13).
 *
 * Accounts and tax key come from the settings and are not validated against the chart of accounts:
 * whether a BU-Schlüssel may be sent together with an Automatikkonto such as 8400 (SKR03) is for the
 * tax advisor to decide (the key 3 is written for every row, whatever the invoice's rate).
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_DATEV_Exporter {

	/**
	 * Text for a DATEV text field: no quotes or line breaks, at most $max characters, and a leading
	 * formula character neutralised (the file is often opened in a spreadsheet; recipient names come
	 * from a public form).
	 *
	 * @param string $text Raw text.
	 * @param int    $max  Maximum length in characters.
	 * @return string
	 */
	public static function text_field( $text, $max ) {
		$text = trim( preg_replace( '/[\r\n\t"]+/', ' ', (string) $text ) );
		if ( preg_match( '/^[=+\-@]/', $text ) ) {
			$text = "'" . $text;
		}
		return mb_substr( $text, 0, $max, 'UTF-8' );
	}

	/**
	 * Convert the UTF-8 export to Windows-1252, the encoding DATEV reads. Until 2026-09-15 the admin
	 * used utf8_decode(), deprecated since PHP 8.2, which writes "?" for the euro sign and for every
	 * character outside ISO-8859-1.
	 *
	 * @param string $csv UTF-8 CSV.
	 * @return string
	 */
	public static function to_windows_1252( $csv ) {
		return (string) mb_convert_encoding( (string) $csv, 'Windows-1252', 'UTF-8' );
	}

	/**
	 * Generate DATEV EXTF CSV content (UTF-8) from invoices within a date range.
	 *
	 * @param string $date_from YYYY-MM-DD.
	 * @param string $date_to   YYYY-MM-DD.
	 * @return string|WP_Error CSV content.
	 */
	public static function generate_export( $date_from, $date_to ) {
		global $wpdb;

		$from = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date_from );
		$to   = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date_to );
		if ( ! $from || ! $to || $from->format( 'Y-m-d' ) !== $date_from || $to->format( 'Y-m-d' ) !== $date_to || $from > $to ) {
			return new WP_Error( 'cybevents_datev_range', 'Ungültiger Zeitraum.' );
		}
		// One Buchungsstapel belongs to one fiscal year. The fiscal year is assumed to be the calendar
		// year; until 2026-09-15 its start was taken from the current year whatever the period.
		if ( $from->format( 'Y' ) !== $to->format( 'Y' ) ) {
			return new WP_Error( 'cybevents_datev_range', 'Ein Buchungsstapel umfasst ein Wirtschaftsjahr: Beginn und Ende des Zeitraums müssen im selben Jahr liegen.' );
		}

		$settings        = get_option( 'cybevents_settings', array() );
		$beraternummer   = ! empty( $settings['datev_beraternummer'] ) ? $settings['datev_beraternummer'] : '1001';
		$mandantennummer = ! empty( $settings['datev_mandantennummer'] ) ? $settings['datev_mandantennummer'] : '10001';
		$sachkonten_len  = ! empty( $settings['datev_sachkontenlaenge'] ) ? $settings['datev_sachkontenlaenge'] : '4';
		$erloeskonto     = ! empty( $settings['datev_erloeskonto'] ) ? $settings['datev_erloeskonto'] : '8400';
		$forderungen     = ! empty( $settings['datev_forderungen'] ) ? $settings['datev_forderungen'] : '1400';

		$df_formatted = $from->format( 'Ymd' );
		$dt_formatted = $to->format( 'Ymd' );

		// Header line 1: 31 positional fields. "Erzeugt am" is YYYYMMDDHHMMSSmmm (it was YYYYMMDD
		// until 2026-09-15); the batch label has at most 30 characters and named a client.
		$h1 = array(
			'"EXTF"',
			'700',
			'21',
			'"Buchungsstapel"',
			'13',
			wp_date( 'YmdHis' ) . '000',
			'',
			'""',
			'""',
			'""',
			$beraternummer,
			$mandantennummer,
			$from->format( 'Y' ) . '0101',
			$sachkonten_len,
			$df_formatted,
			$dt_formatted,
			'"' . self::text_field( 'Rechnungen ' . $df_formatted . '-' . $dt_formatted, 30 ) . '"',
			'""',
			'1',
			'',
			'0',
			'"EUR"',
			'',
			'',
			'',
			'',
			'""',
			'""',
			'""',
			'""',
			'""',
		);

		// Header Line 2: Column Names (125 columns)
		$h2 = array(
			'Umsatz (ohne Soll/Haben-Kz)',
			'Soll/Haben-Kennzeichen',
			'WKZ Umsatz',
			'Kurs',
			'Basis-Umsatz',
			'WKZ Basis-Umsatz',
			'Konto',
			'Gegenkonto (ohne BU-Schlüssel)',
			'BU-Schlüssel',
			'Belegdatum',
			'Belegfeld 1',
			'Belegfeld 2',
			'Skonto',
			'Buchungstext',
		);

		// Fill up header line 2 to 125 columns
		for ( $i = count( $h2 ); $i < 125; $i++ ) {
			$h2[] = 'Freifeld_' . ( $i + 1 );
		}

		$lines   = array();
		$lines[] = implode( ';', $h1 );
		$lines[] = implode( ';', $h2 );

		// Fetch Invoices in range
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$invoices = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cybevents_invoices WHERE issued_at >= %s AND issued_at <= %s ORDER BY id ASC",
				$date_from . ' 00:00:00',
				$date_to . ' 23:59:59'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( $invoices as $inv ) {
			$row = array_fill( 0, 125, '""' );

			$amount_formatted = number_format( abs( (float) $inv->gross_amount ), 2, ',', '' );
			$sh               = ( 'storno' === $inv->invoice_type || (float) $inv->gross_amount < 0 ) ? '"H"' : '"S"';
			$belegdatum       = gmdate( 'dm', strtotime( $inv->issued_at ) );

			$row[0]  = '"' . $amount_formatted . '"'; // Umsatz
			$row[1]  = $sh;                            // S/H
			$row[2]  = '"EUR"';                        // Währung
			$row[6]  = '"' . $forderungen . '"';       // Konto (Forderungen)
			$row[7]  = '"' . $erloeskonto . '"';       // Gegenkonto (Erlöse)
			$row[8]  = '"3"';                          // BU-Schlüssel (see the class comment)
			$row[9]  = '"' . $belegdatum . '"';        // Belegdatum (TTMM)
			$row[10] = '"' . self::text_field( $inv->invoice_number, 36 ) . '"';                                // Belegfeld 1 (Rechnungsnr)
			$row[13] = '"' . self::text_field( trim( $inv->recipient_name . ' ' . $inv->recipient_company ), 60 ) . '"'; // Buchungstext

			$lines[] = implode( ';', $row );
		}

		return implode( "\r\n", $lines );
	}
}
