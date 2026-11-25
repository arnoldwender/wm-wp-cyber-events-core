<?php
/**
 * Invoices and credit notes with a CII XML (UN/CEFACT CrossIndustryInvoice, EN 16931 profile).
 *
 * The XML is a structured invoice document. No PDF is generated, so it is not a ZUGFeRD/Factur-X
 * hybrid file. Validated on 2026-09-15 with Mustang-CLI 2.26.0 (tests/validate-invoice-xml.sh).
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Invoicing {

	/** EN 16931 guideline ID (BT-24). Until 2026-09-15 an ID no validator accepts was written. */
	const GUIDELINE_ID = 'urn:cen.eu:en16931:2017';

	/** UNTDID 1001 document types (BT-3). */
	const TYPE_INVOICE     = '380';
	const TYPE_CREDIT_NOTE = '381';

	/**
	 * Escape string strictly for XML content (Protection against XML Injection).
	 *
	 * @param string $string Raw input string.
	 * @return string XML safe string.
	 */
	public static function xml_escape( $string ) {
		return htmlspecialchars( (string) $string, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Seller data from the settings. Until 2026-09-15 every invoice named a hardcoded client with an
	 * invalid VAT ID as seller.
	 *
	 * @return array
	 */
	public static function seller() {
		$s = get_option( 'cybevents_settings', array() );
		return array(
			'name'       => trim( (string) ( $s['seller_name'] ?? '' ) ),
			'street'     => trim( (string) ( $s['seller_street'] ?? '' ) ),
			'postcode'   => trim( (string) ( $s['seller_postcode'] ?? '' ) ),
			'city'       => trim( (string) ( $s['seller_city'] ?? '' ) ),
			'country'    => strtoupper( trim( (string) ( $s['seller_country'] ?? 'DE' ) ) ),
			'vat_id'     => strtoupper( str_replace( ' ', '', (string) ( $s['seller_vat_id'] ?? '' ) ) ),
			'tax_number' => trim( (string) ( $s['seller_tax_number'] ?? '' ) ),
			'email'      => trim( (string) ( $s['seller_email'] ?? '' ) ),
			'iban'       => strtoupper( str_replace( ' ', '', (string) ( $s['seller_iban'] ?? '' ) ) ),
		);
	}

	/**
	 * What is missing or malformed in the seller data, as field labels. Empty when an invoice can be
	 * issued.
	 *
	 * @param array $seller Seller data.
	 * @return string[]
	 */
	public static function seller_problems( array $seller ) {
		$problems = array();
		foreach ( array( 'name' => 'Name', 'street' => 'Straße', 'postcode' => 'PLZ', 'city' => 'Ort' ) as $key => $label ) {
			if ( '' === $seller[ $key ] ) {
				$problems[] = $label;
			}
		}
		if ( ! preg_match( '/^[A-Z]{2}$/', $seller['country'] ) ) {
			$problems[] = 'Land (ISO-3166-Code)';
		}
		// BR-S-02: a standard-rated invoice names the seller's VAT ID or tax number.
		if ( '' === $seller['vat_id'] && '' === $seller['tax_number'] ) {
			$problems[] = 'USt-IdNr. oder Steuernummer';
		} elseif ( '' !== $seller['vat_id'] && ! preg_match( '/^[A-Z]{2}[A-Z0-9]{2,12}$/', $seller['vat_id'] ) ) {
			$problems[] = 'USt-IdNr. (Format)';
		}
		if ( '' !== $seller['iban'] && ! self::iban_is_valid( $seller['iban'] ) ) {
			$problems[] = 'IBAN (Prüfziffer)';
		}
		return $problems;
	}

	/**
	 * ISO 13616 check (mod 97).
	 *
	 * @param string $iban IBAN without spaces.
	 * @return bool
	 */
	public static function iban_is_valid( $iban ) {
		if ( ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban ) ) {
			return false;
		}
		$rearranged = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$digits     = '';
		foreach ( str_split( $rearranged ) as $char ) {
			$digits .= ctype_alpha( $char ) ? (string) ( ord( $char ) - 55 ) : $char;
		}
		$remainder = 0;
		foreach ( str_split( $digits, 7 ) as $chunk ) {
			$remainder = (int) ( $remainder . $chunk ) % 97;
		}
		return 1 === $remainder;
	}

	/**
	 * Net, tax and gross in cents. The tax is rounded once and the gross is their sum, so the totals
	 * satisfy BR-CO-15. Until 2026-09-15 each amount was a float rounded separately in the XML
	 * (net 45.50 at 19 %: tax 8.65, gross 54.14).
	 *
	 * @param float $net      Net amount.
	 * @param float $rate     VAT rate in percent.
	 * @return array{net:int,tax:int,gross:int}
	 */
	public static function amounts_in_cents( $net, $rate ) {
		$net_cents = (int) round( (float) $net * 100 );
		$tax_cents = (int) round( $net_cents * (float) $rate / 100 );
		return array(
			'net'   => $net_cents,
			'tax'   => $tax_cents,
			'gross' => $net_cents + $tax_cents,
		);
	}

	/**
	 * Create an invoice with its CII XML.
	 *
	 * Amounts are computed from net amount and rate; tax_amount and gross_amount in $data are ignored.
	 *
	 * @param array $data Invoice payload.
	 * @return int|WP_Error Invoice ID on success.
	 */
	public static function create_invoice( $data ) {
		global $wpdb;

		$seller   = self::seller();
		$problems = self::seller_problems( $seller );
		if ( $problems ) {
			return new WP_Error( 'cybevents_seller_incomplete', 'Rechnung nicht erstellt: Rechnungssteller-Daten in den Einstellungen unvollständig (' . implode( ', ', $problems ) . ').' );
		}

		$tax_rate = round( (float) ( $data['tax_rate'] ?? 19.0 ), 2 );
		// Only standard-rated VAT (category S) is written; zero-rated and exempt invoices need an
		// exemption reason the plugin does not collect.
		if ( $tax_rate <= 0 ) {
			return new WP_Error( 'cybevents_tax_rate_unsupported', 'Rechnung nicht erstellt: nur Rechnungen mit Umsatzsteuer (Steuersatz größer 0) werden unterstützt.' );
		}

		$cents = self::amounts_in_cents( $data['net_amount'] ?? 0.0, $tax_rate );
		if ( $cents['net'] <= 0 ) {
			return new WP_Error( 'cybevents_amount_invalid', 'Rechnung nicht erstellt: der Nettobetrag muss größer 0 sein.' );
		}

		$invoice_number = CyberEvents_Database::get_next_invoice_number();
		if ( is_wp_error( $invoice_number ) ) {
			return $invoice_number;
		}

		$event     = ! empty( $data['event_id'] ) ? CyberEvents_Database::get_event( absint( $data['event_id'] ) ) : null;
		$issued_at = current_time( 'mysql' );
		$due_date  = current_datetime()->modify( '+14 days' )->format( 'Y-m-d H:i:s' );
		$currency  = strtoupper( sanitize_text_field( $data['currency'] ?? 'EUR' ) );
		$item      = (string) ( $data['item_description'] ?? '' );
		if ( '' === $item ) {
			// Until 2026-09-15 the default item text named a client's summit.
			$item = $event ? 'Teilnahme: ' . $event->title : 'Veranstaltungsteilnahme';
		}

		$xml = self::generate_zugferd_xml(
			array(
				'type_code'         => self::TYPE_INVOICE,
				'invoice_number'    => $invoice_number,
				'issued_at'         => $issued_at,
				'due_date'          => $due_date,
				// Date of supply (sec. 14 (4) no. 6 UStG, BT-72): the event date.
				'delivery_date'     => $event ? $event->start_date : $issued_at,
				'seller'            => $seller,
				'recipient_name'    => $data['recipient_name'] ?? '',
				'recipient_company' => $data['recipient_company'] ?? '',
				'recipient_email'   => $data['recipient_email'] ?? '',
				'recipient_address' => $data['recipient_address'] ?? '',
				'net_cents'         => $cents['net'],
				'tax_cents'         => $cents['tax'],
				'gross_cents'       => $cents['gross'],
				'tax_rate'          => $tax_rate,
				'currency'          => $currency,
				'item_description'  => $item,
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			"{$wpdb->prefix}cybevents_invoices",
			array(
				'registration_id'   => absint( $data['registration_id'] ?? 0 ),
				'event_id'          => absint( $data['event_id'] ?? 0 ),
				'invoice_number'    => $invoice_number,
				'invoice_type'      => 'standard',
				'recipient_name'    => sanitize_text_field( $data['recipient_name'] ?? '' ),
				'recipient_company' => sanitize_text_field( $data['recipient_company'] ?? '' ),
				'recipient_email'   => sanitize_email( $data['recipient_email'] ?? '' ),
				'recipient_address' => sanitize_textarea_field( $data['recipient_address'] ?? '' ),
				'net_amount'        => $cents['net'] / 100,
				'tax_amount'        => $cents['tax'] / 100,
				'gross_amount'      => $cents['gross'] / 100,
				'currency'          => $currency,
				'zugferd_xml'       => $xml,
				'status'            => 'issued',
				'issued_at'         => $issued_at,
				'due_date'          => $due_date,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $inserted ) {
			return new WP_Error( 'db_insert_failed', 'Rechnung konnte nicht gespeichert werden.' );
		}

		$invoice_id = $wpdb->insert_id;

		// Link to registration if present
		if ( ! empty( $data['registration_id'] ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				"{$wpdb->prefix}cybevents_registrations",
				array( 'invoice_number' => $invoice_number ),
				array( 'id' => absint( $data['registration_id'] ) )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		CyberEvents_Database::log_audit(
			'invoice_created',
			$invoice_id,
			get_current_user_id(),
			wp_json_encode(
				array(
					'invoice_number' => $invoice_number,
					'gross_amount'   => $cents['gross'] / 100,
				)
			)
		);

		return $invoice_id;
	}

	/**
	 * Cancel an invoice with a credit note (sec. 14 (2) UStG).
	 *
	 * The credit note gets its own number from the invoice sequence and a CII XML with type 381,
	 * positive amounts and a reference to the original invoice. The row stores negative amounts, as
	 * the plugin's revenue and DATEV export expect. Until 2026-09-15 credit notes had no XML and took
	 * the next invoice number with "-STORNO" appended, which the next invoice then used as well.
	 *
	 * @param int    $invoice_id Original invoice ID.
	 * @param string $reason Cancellation reason.
	 * @return int|WP_Error New credit note ID on success.
	 */
	public static function cancel_invoice( $invoice_id, $reason = '' ) {
		global $wpdb;

		$invoice_id = absint( $invoice_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$original = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cybevents_invoices WHERE id = %d", $invoice_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $original ) {
			return new WP_Error( 'not_found', 'Rechnung nicht gefunden.' );
		}

		if ( 'cancelled' === $original->status || 'storno' === $original->invoice_type ) {
			return new WP_Error( 'already_cancelled', 'Rechnung ist bereits storniert.' );
		}

		$seller   = self::seller();
		$problems = self::seller_problems( $seller );
		if ( $problems ) {
			return new WP_Error( 'cybevents_seller_incomplete', 'Gutschrift nicht erstellt: Rechnungssteller-Daten in den Einstellungen unvollständig (' . implode( ', ', $problems ) . ').' );
		}

		$net_cents   = (int) round( abs( (float) $original->net_amount ) * 100 );
		$tax_cents   = (int) round( abs( (float) $original->tax_amount ) * 100 );
		$gross_cents = $net_cents + $tax_cents;

		// The rate of the original: from its XML, or derived from its amounts for rows without one.
		$tax_rate = 0.0;
		if ( preg_match( '#<ram:RateApplicablePercent>([0-9.]+)</ram:RateApplicablePercent>#', (string) $original->zugferd_xml, $m ) ) {
			$tax_rate = (float) $m[1];
		} elseif ( $net_cents > 0 ) {
			$tax_rate = round( $tax_cents * 100 / $net_cents );
		}
		if ( $tax_rate <= 0 || $net_cents <= 0 ) {
			return new WP_Error( 'cybevents_tax_rate_unsupported', 'Gutschrift nicht erstellt: Steuersatz oder Betrag der Originalrechnung nicht bestimmbar.' );
		}

		$storno_number = CyberEvents_Database::get_next_invoice_number();
		if ( is_wp_error( $storno_number ) ) {
			return $storno_number;
		}
		$issued_at = current_time( 'mysql' );
		$event     = ! empty( $original->event_id ) ? CyberEvents_Database::get_event( (int) $original->event_id ) : null;

		$xml = self::generate_zugferd_xml(
			array(
				'type_code'          => self::TYPE_CREDIT_NOTE,
				'invoice_number'     => $storno_number,
				'issued_at'          => $issued_at,
				'due_date'           => $issued_at,
				'delivery_date'      => $event ? $event->start_date : (string) $original->issued_at,
				'seller'             => $seller,
				'recipient_name'     => $original->recipient_name,
				'recipient_company'  => $original->recipient_company,
				'recipient_email'    => $original->recipient_email,
				'recipient_address'  => $original->recipient_address,
				'net_cents'          => $net_cents,
				'tax_cents'          => $tax_cents,
				'gross_cents'        => $gross_cents,
				'tax_rate'           => $tax_rate,
				'currency'           => $original->currency,
				'item_description'   => 'Stornierung der Rechnung ' . $original->invoice_number . ( '' !== (string) $reason ? ': ' . sanitize_text_field( $reason ) : '' ),
				'preceding_number'   => $original->invoice_number,
				'preceding_issue_at' => $original->issued_at,
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			"{$wpdb->prefix}cybevents_invoices",
			array(
				'registration_id'     => $original->registration_id,
				'event_id'            => $original->event_id,
				'invoice_number'      => $storno_number,
				'invoice_type'        => 'storno',
				'recipient_name'      => $original->recipient_name,
				'recipient_company'   => $original->recipient_company,
				'recipient_email'     => $original->recipient_email,
				'recipient_address'   => $original->recipient_address,
				'net_amount'          => -1 * $net_cents / 100,
				'tax_amount'          => -1 * $tax_cents / 100,
				'gross_amount'        => -1 * $gross_cents / 100,
				'currency'            => $original->currency,
				'zugferd_xml'         => $xml,
				'status'              => 'issued',
				'storno_reference_id' => $invoice_id,
				'issued_at'           => $issued_at,
				'due_date'            => $issued_at,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', 'Storno-Beleg konnte nicht angelegt werden.' );
		}

		$storno_id = $wpdb->insert_id;

		// Mark original invoice as cancelled
		$wpdb->update(
			"{$wpdb->prefix}cybevents_invoices",
			array( 'status' => 'cancelled' ),
			array( 'id' => $invoice_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		CyberEvents_Database::log_audit(
			'invoice_cancelled',
			$invoice_id,
			get_current_user_id(),
			wp_json_encode(
				array(
					'storno_id'     => $storno_id,
					'storno_number' => $storno_number,
					'reason'        => sanitize_text_field( $reason ),
				)
			)
		);

		return $storno_id;
	}

	/**
	 * Amount in cents as a decimal string with two places.
	 *
	 * @param int $cents Cents.
	 * @return string
	 */
	private static function money( $cents ) {
		return number_format( $cents / 100, 2, '.', '' );
	}

	/**
	 * Date as CII format 102 (YYYYMMDD).
	 *
	 * @param string $datetime MySQL datetime.
	 * @return string
	 */
	private static function date_102( $datetime ) {
		return gmdate( 'Ymd', (int) strtotime( (string) $datetime ) );
	}

	/**
	 * CII XML (EN 16931 profile) for one line: an invoice (380) or a credit note (381).
	 *
	 * Element order follows the CrossIndustryInvoice 100 schema, which rejects a document with an
	 * element out of order; ApplicableHeaderTradeDelivery was missing until 2026-09-15.
	 *
	 * @param array $args Document parameters (see create_invoice() and cancel_invoice()).
	 * @return string XML document.
	 */
	public static function generate_zugferd_xml( $args ) {
		$e      = array( __CLASS__, 'xml_escape' );
		$seller = $args['seller'];
		$type   = (string) ( $args['type_code'] ?? self::TYPE_INVOICE );
		$cur    = self::xml_escape( strtoupper( (string) $args['currency'] ) );
		$rate   = number_format( (float) $args['tax_rate'], 2, '.', '' );
		$net    = self::money( (int) $args['net_cents'] );
		$tax    = self::money( (int) $args['tax_cents'] );
		$gross  = self::money( (int) $args['gross_cents'] );

		$buyer_company = trim( (string) $args['recipient_company'] );
		$buyer_person  = trim( (string) $args['recipient_name'] );
		$buyer_name    = '' !== $buyer_company ? $buyer_company : $buyer_person;
		$buyer_address = trim( preg_replace( '/\s*\R\s*/', ', ', (string) $args['recipient_address'] ) );
		$buyer_email   = sanitize_email( (string) $args['recipient_email'] );

		$x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$x .= '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">' . "\n";
		$x .= '<rsm:ExchangedDocumentContext><ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>' . self::GUIDELINE_ID . '</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter></rsm:ExchangedDocumentContext>' . "\n";
		$x .= '<rsm:ExchangedDocument><ram:ID>' . $e( $args['invoice_number'] ) . '</ram:ID><ram:TypeCode>' . $e( $type ) . '</ram:TypeCode><ram:IssueDateTime><udt:DateTimeString format="102">' . self::date_102( $args['issued_at'] ) . '</udt:DateTimeString></ram:IssueDateTime></rsm:ExchangedDocument>' . "\n";
		$x .= '<rsm:SupplyChainTradeTransaction>' . "\n";

		// Line (BG-25).
		$x .= '<ram:IncludedSupplyChainTradeLineItem>';
		$x .= '<ram:AssociatedDocumentLineDocument><ram:LineID>1</ram:LineID></ram:AssociatedDocumentLineDocument>';
		$x .= '<ram:SpecifiedTradeProduct><ram:Name>' . $e( $args['item_description'] ) . '</ram:Name></ram:SpecifiedTradeProduct>';
		$x .= '<ram:SpecifiedLineTradeAgreement><ram:NetPriceProductTradePrice><ram:ChargeAmount>' . $net . '</ram:ChargeAmount></ram:NetPriceProductTradePrice></ram:SpecifiedLineTradeAgreement>';
		$x .= '<ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="C62">1</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>';
		$x .= '<ram:SpecifiedLineTradeSettlement><ram:ApplicableTradeTax><ram:TypeCode>VAT</ram:TypeCode><ram:CategoryCode>S</ram:CategoryCode><ram:RateApplicablePercent>' . $rate . '</ram:RateApplicablePercent></ram:ApplicableTradeTax>';
		$x .= '<ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>' . $net . '</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation></ram:SpecifiedLineTradeSettlement>';
		$x .= '</ram:IncludedSupplyChainTradeLineItem>' . "\n";

		// Seller (BG-4) and buyer (BG-7).
		$x .= '<ram:ApplicableHeaderTradeAgreement>';
		$x .= '<ram:SellerTradeParty><ram:Name>' . $e( $seller['name'] ) . '</ram:Name>';
		$x .= '<ram:PostalTradeAddress><ram:PostcodeCode>' . $e( $seller['postcode'] ) . '</ram:PostcodeCode><ram:LineOne>' . $e( $seller['street'] ) . '</ram:LineOne><ram:CityName>' . $e( $seller['city'] ) . '</ram:CityName><ram:CountryID>' . $e( $seller['country'] ) . '</ram:CountryID></ram:PostalTradeAddress>';
		if ( '' !== $seller['email'] ) {
			$x .= '<ram:URIUniversalCommunication><ram:URIID schemeID="EM">' . $e( $seller['email'] ) . '</ram:URIID></ram:URIUniversalCommunication>';
		}
		if ( '' !== $seller['tax_number'] ) {
			$x .= '<ram:SpecifiedTaxRegistration><ram:ID schemeID="FC">' . $e( $seller['tax_number'] ) . '</ram:ID></ram:SpecifiedTaxRegistration>';
		}
		if ( '' !== $seller['vat_id'] ) {
			$x .= '<ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">' . $e( $seller['vat_id'] ) . '</ram:ID></ram:SpecifiedTaxRegistration>';
		}
		$x .= '</ram:SellerTradeParty>';
		$x .= '<ram:BuyerTradeParty><ram:Name>' . $e( $buyer_name ) . '</ram:Name>';
		if ( '' !== $buyer_company && '' !== $buyer_person ) {
			$x .= '<ram:DefinedTradeContact><ram:PersonName>' . $e( $buyer_person ) . '</ram:PersonName></ram:DefinedTradeContact>';
		}
		// The registration form collects no country (BT-55 is mandatory): the seller's country is
		// assumed. Until 2026-09-15 an empty address was filled with "Musterstraße 1".
		$x .= '<ram:PostalTradeAddress>' . ( '' !== $buyer_address ? '<ram:LineOne>' . $e( $buyer_address ) . '</ram:LineOne>' : '' ) . '<ram:CountryID>' . $e( $seller['country'] ) . '</ram:CountryID></ram:PostalTradeAddress>';
		if ( '' !== $buyer_email ) {
			$x .= '<ram:URIUniversalCommunication><ram:URIID schemeID="EM">' . $e( $buyer_email ) . '</ram:URIID></ram:URIUniversalCommunication>';
		}
		$x .= '</ram:BuyerTradeParty>';
		$x .= '</ram:ApplicableHeaderTradeAgreement>' . "\n";

		// Delivery (BG-13): date of supply.
		$x .= '<ram:ApplicableHeaderTradeDelivery><ram:ActualDeliverySupplyChainEvent><ram:OccurrenceDateTime><udt:DateTimeString format="102">' . self::date_102( $args['delivery_date'] ) . '</udt:DateTimeString></ram:OccurrenceDateTime></ram:ActualDeliverySupplyChainEvent></ram:ApplicableHeaderTradeDelivery>' . "\n";

		// Settlement (BG-22 and friends).
		$x .= '<ram:ApplicableHeaderTradeSettlement>';
		if ( self::TYPE_INVOICE === $type ) {
			$x .= '<ram:PaymentReference>' . $e( $args['invoice_number'] ) . '</ram:PaymentReference>';
		}
		$x .= '<ram:InvoiceCurrencyCode>' . $cur . '</ram:InvoiceCurrencyCode>';
		if ( self::TYPE_INVOICE === $type && '' !== $seller['iban'] ) {
			// 58 = SEPA credit transfer (BT-81), payee account (BT-84).
			$x .= '<ram:SpecifiedTradeSettlementPaymentMeans><ram:TypeCode>58</ram:TypeCode><ram:PayeePartyCreditorFinancialAccount><ram:IBANID>' . $e( $seller['iban'] ) . '</ram:IBANID></ram:PayeePartyCreditorFinancialAccount></ram:SpecifiedTradeSettlementPaymentMeans>';
		}
		$x .= '<ram:ApplicableTradeTax><ram:CalculatedAmount>' . $tax . '</ram:CalculatedAmount><ram:TypeCode>VAT</ram:TypeCode><ram:BasisAmount>' . $net . '</ram:BasisAmount><ram:CategoryCode>S</ram:CategoryCode><ram:RateApplicablePercent>' . $rate . '</ram:RateApplicablePercent></ram:ApplicableTradeTax>';
		$x .= '<ram:SpecifiedTradePaymentTerms><ram:DueDateDateTime><udt:DateTimeString format="102">' . self::date_102( $args['due_date'] ) . '</udt:DateTimeString></ram:DueDateDateTime></ram:SpecifiedTradePaymentTerms>';
		$x .= '<ram:SpecifiedTradeSettlementHeaderMonetarySummation><ram:LineTotalAmount>' . $net . '</ram:LineTotalAmount><ram:TaxBasisTotalAmount>' . $net . '</ram:TaxBasisTotalAmount><ram:TaxTotalAmount currencyID="' . $cur . '">' . $tax . '</ram:TaxTotalAmount><ram:GrandTotalAmount>' . $gross . '</ram:GrandTotalAmount><ram:DuePayableAmount>' . $gross . '</ram:DuePayableAmount></ram:SpecifiedTradeSettlementHeaderMonetarySummation>';
		if ( self::TYPE_CREDIT_NOTE === $type && ! empty( $args['preceding_number'] ) ) {
			// Preceding invoice reference (BG-3).
			$x .= '<ram:InvoiceReferencedDocument><ram:IssuerAssignedID>' . $e( $args['preceding_number'] ) . '</ram:IssuerAssignedID><ram:FormattedIssueDateTime><qdt:DateTimeString format="102">' . self::date_102( $args['preceding_issue_at'] ) . '</qdt:DateTimeString></ram:FormattedIssueDateTime></ram:InvoiceReferencedDocument>';
		}
		$x .= '</ram:ApplicableHeaderTradeSettlement>' . "\n";

		$x .= '</rsm:SupplyChainTradeTransaction>' . "\n";
		$x .= '</rsm:CrossIndustryInvoice>' . "\n";

		return $x;
	}
}
