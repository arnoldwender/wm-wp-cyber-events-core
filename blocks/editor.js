/**
 * Gutenberg Block Editor Integration for Cyber Events Suite.
 *
 * Inspector controls and editor preview cards for the dynamic blocks. The markup on the page comes
 * from the PHP render callbacks: a control the renderer does not read, or a style option without a
 * rule in the frontend stylesheet, is not offered here (tests/test-suite.php, I11 and I12).
 *
 * @package CyberEvents
 */

( function( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var InspectorControls = wp.blockEditor.InspectorControls || wp.editor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var RangeControl = wp.components.RangeControl;
	var ServerSideRender = wp.serverSideRender;

	var eventsData = ( window.cybeventsGutenbergData && window.cybeventsGutenbergData.events ) || [];

	var eventSelectOptions = [
		{ label: __( '— Neueste aktive Veranstaltung (Automatisch) —', 'wm-cyber-events-core' ), value: 0 }
	];

	if ( eventsData.length > 0 ) {
		eventsData.forEach( function( ev ) {
			eventSelectOptions.push( {
				label: ev.label,
				value: ev.value
			} );
		} );
	}

	function getSelectedEventLabel( eventId ) {
		var label = __( 'Automatisch (Aktuelle Konferenz)', 'wm-cyber-events-core' );
		eventsData.forEach( function( ev ) {
			if ( parseInt( ev.value, 10 ) === parseInt( eventId, 10 ) ) {
				label = ev.label;
			}
		} );
		return label;
	}

	/* ==========================================================================
	   1. Block: Event Hero & Countdown Banner
	   ========================================================================== */
	registerBlockType( 'wm-cyber-events/event-hero', {
		edit: function( props ) {
			var attr = props.attributes;
			var set = props.setAttributes;
			var eventTitle = getSelectedEventLabel( attr.eventId );

			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Veranstaltungs-Auswahl', 'wm-cyber-events-core' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Ziel-Konferenz', 'wm-cyber-events-core' ),
							value: attr.eventId,
							options: eventSelectOptions,
							onChange: function( val ) { set( { eventId: parseInt( val, 10 ) } ); },
							help: __( 'Wählen Sie die Konferenz, deren Daten im Hero geladen werden.', 'wm-cyber-events-core' )
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Design & Layout-Presets', 'wm-cyber-events-core' ), initialOpen: false },
						el( SelectControl, {
							label: __( 'Theme-Preset', 'wm-cyber-events-core' ),
							value: attr.presetStyle,
							options: [
								{ label: __( 'Enterprise Dark & Glow (Standard)', 'wm-cyber-events-core' ), value: 'dark-enterprise' },
								{ label: __( 'Federal Blue', 'wm-cyber-events-core' ), value: 'federal-blue' },
								{ label: __( 'Cyberpunk Cyan Glow', 'wm-cyber-events-core' ), value: 'cyber-glow' },
								{ label: __( 'Clean White & Modern', 'wm-cyber-events-core' ), value: 'minimal-light' },
								{ label: __( 'Glassmorphism Blur', 'wm-cyber-events-core' ), value: 'glassmorphism' }
							],
							onChange: function( val ) { set( { presetStyle: val } ); }
						} ),
						el( SelectControl, {
							label: __( 'Eckenabrundung (Border Radius)', 'wm-cyber-events-core' ),
							value: attr.borderRadius,
							options: [
								{ label: __( 'Ohne (0px)', 'wm-cyber-events-core' ), value: 'none' },
								{ label: __( 'Mittel (8px)', 'wm-cyber-events-core' ), value: 'md' },
								{ label: __( 'Groß (16px)', 'wm-cyber-events-core' ), value: 'lg' },
								{ label: __( 'Sehr groß (24px)', 'wm-cyber-events-core' ), value: 'xl' }
							],
							onChange: function( val ) { set( { borderRadius: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Leucht- & Glow-Effekt aktivieren', 'wm-cyber-events-core' ),
							checked: attr.enableGlow,
							onChange: function( val ) { set( { enableGlow: val } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Texte & Call-to-Action Buttons', 'wm-cyber-events-core' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Badge / Dachzeile', 'wm-cyber-events-core' ),
							value: attr.badgeText,
							onChange: function( val ) { set( { badgeText: val } ); }
						} ),
						el( TextControl, {
							label: __( 'Individuelle Hauptüberschrift (Optional)', 'wm-cyber-events-core' ),
							value: attr.customHeading,
							placeholder: __( 'Standard: Titel der Veranstaltung', 'wm-cyber-events-core' ),
							onChange: function( val ) { set( { customHeading: val } ); }
						} ),
						el( TextareaControl, {
							label: __( 'Individueller Einleitungstext / Lead', 'wm-cyber-events-core' ),
							value: attr.customLead,
							placeholder: __( 'Standard: Beschreibung der Veranstaltung', 'wm-cyber-events-core' ),
							onChange: function( val ) { set( { customLead: val } ); }
						} ),
						el( TextControl, {
							label: __( 'Primärer CTA-Button Text', 'wm-cyber-events-core' ),
							value: attr.ctaText,
							onChange: function( val ) { set( { ctaText: val } ); }
						} ),
						el( TextControl, {
							label: __( 'Primärer CTA-Button Ziel-Link / Anker', 'wm-cyber-events-core' ),
							value: attr.ctaUrl,
							onChange: function( val ) { set( { ctaUrl: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Sekundären Button anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showSecondaryCta,
							onChange: function( val ) { set( { showSecondaryCta: val } ); }
						} ),
						attr.showSecondaryCta ? el( TextControl, {
							label: __( 'Sekundärer Button Text', 'wm-cyber-events-core' ),
							value: attr.secondaryCtaText,
							onChange: function( val ) { set( { secondaryCtaText: val } ); }
						} ) : null,
						attr.showSecondaryCta ? el( TextControl, {
							label: __( 'Sekundärer Button Ziel-Link', 'wm-cyber-events-core' ),
							value: attr.secondaryCtaUrl,
							onChange: function( val ) { set( { secondaryCtaUrl: val } ); }
						} ) : null
					),
					el(
						PanelBody,
						{ title: __( 'Sichtbarkeit von Elementen', 'wm-cyber-events-core' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Live Countdown-Timer anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showCountdown,
							onChange: function( val ) { set( { showCountdown: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Freie Plätze / Kapazitäts-Pill anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showCapacityPill,
							onChange: function( val ) { set( { showCapacityPill: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Status-Pill (Geöffnet/Ausgebucht) anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showStatusPill,
							onChange: function( val ) { set( { showStatusPill: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Veranstaltungsort anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showLocation,
							onChange: function( val ) { set( { showLocation: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Datum & Uhrzeit anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showDateTime,
							onChange: function( val ) { set( { showDateTime: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Ticketpreis / Kostenfrei-Badge anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showPrice,
							onChange: function( val ) { set( { showPrice: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Kalender-Export Button (.ics) anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showCalendarButton,
							onChange: function( val ) { set( { showCalendarButton: val } ); }
						} )
					)
				),
				el(
					'div',
					{ key: 'preview', className: 'cybevents-gutenberg-preview' },
					el(
						'div',
						{ className: 'cybevents-gutenberg-card cybevents-gutenberg-card--hero cybevents-preset--' + ( attr.presetStyle || 'dark-enterprise' ) },
						el( 'div', { className: 'cybevents-gutenberg-badge' }, attr.badgeText || __( 'Kopfbereich', 'wm-cyber-events-core' ) ),
						el( 'h3', { className: 'cybevents-gutenberg-title' }, attr.customHeading || eventTitle ),
						el(
							'p',
							{ className: 'cybevents-gutenberg-desc' },
							attr.customLead || __( 'Standard: Beschreibung der Veranstaltung, dazu Datum, Ort, Preis, freie Plätze, Countdown und Anmelde-Button.', 'wm-cyber-events-core' )
						),
						el(
							'div',
							{ className: 'cybevents-gutenberg-tags' },
							attr.showCountdown ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Countdown', 'wm-cyber-events-core' ) ) : null,
							attr.showCapacityPill ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Freie Plätze', 'wm-cyber-events-core' ) ) : null,
							el( 'span', { className: 'cybevents-gutenberg-tag' }, sprintf( /* translators: %s: style preset key */ __( 'Stil: %s', 'wm-cyber-events-core' ), attr.presetStyle || 'dark-enterprise' ) ),
							el( 'span', { className: 'cybevents-gutenberg-tag' }, sprintf( /* translators: %s: button label */ __( 'Button: %s', 'wm-cyber-events-core' ), attr.ctaText || __( 'Jetzt anmelden', 'wm-cyber-events-core' ) ) )
						)
					)
				)
			];
		},
		save: function() { return null; }
	} );

	/* ==========================================================================
	   2. Block: Registration Form
	   ========================================================================== */
	registerBlockType( 'wm-cyber-events/registration-form', {
		edit: function( props ) {
			var attr = props.attributes;
			var set = props.setAttributes;
			var eventTitle = getSelectedEventLabel( attr.eventId );

			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Konferenz-Auswahl', 'wm-cyber-events-core' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Ziel-Veranstaltung', 'wm-cyber-events-core' ),
							value: attr.eventId,
							options: eventSelectOptions,
							onChange: function( val ) { set( { eventId: parseInt( val, 10 ) } ); },
							help: __( 'Wählen Sie das Event aus, für das sich Besucher anmelden.', 'wm-cyber-events-core' )
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Formular-Design & Theme', 'wm-cyber-events-core' ), initialOpen: false },
						el( SelectControl, {
							label: __( 'Formular-Stil', 'wm-cyber-events-core' ),
							value: attr.formStyle,
							options: [
								{ label: __( 'Cyber Glassmorphism (Standard)', 'wm-cyber-events-core' ), value: 'card-glass' },
								{ label: __( 'Clean White Studio', 'wm-cyber-events-core' ), value: 'clean-white' }
							],
							onChange: function( val ) { set( { formStyle: val } ); }
						} ),
						el( SelectControl, {
							label: __( 'Eckenabrundung', 'wm-cyber-events-core' ),
							value: attr.borderRadius,
							options: [
								{ label: __( 'Mittel (8px)', 'wm-cyber-events-core' ), value: 'md' },
								{ label: __( 'Groß (16px)', 'wm-cyber-events-core' ), value: 'lg' },
								{ label: __( 'Sehr groß (24px)', 'wm-cyber-events-core' ), value: 'xl' }
							],
							onChange: function( val ) { set( { borderRadius: val } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Formularfelder & Erfassungsoptionen', 'wm-cyber-events-core' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Individueller Formulartitel', 'wm-cyber-events-core' ),
							value: attr.customTitle,
							placeholder: __( 'Standard: Titel der Veranstaltung', 'wm-cyber-events-core' ),
							onChange: function( val ) { set( { customTitle: val } ); }
						} ),
						el( TextControl, {
							label: __( 'Individueller Untertitel / Hinweis', 'wm-cyber-events-core' ),
							value: attr.customSubtitle,
							placeholder: __( 'Standard: kein Untertitel', 'wm-cyber-events-core' ),
							onChange: function( val ) { set( { customSubtitle: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Organisation / Firma Feld anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showCompanyField,
							onChange: function( val ) { set( { showCompanyField: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Foto- & Videoaufnahmen Einwilligung anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showPhotoConsent,
							onChange: function( val ) { set( { showPhotoConsent: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Key Facts Header im Formular anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showKeyFacts,
							onChange: function( val ) { set( { showKeyFacts: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Schrittanzeige (Stepper) anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showStepper,
							onChange: function( val ) { set( { showStepper: val } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Button-Beschriftung & DSGVO-Links', 'wm-cyber-events-core' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Absende-Button Beschriftung', 'wm-cyber-events-core' ),
							value: attr.buttonText,
							onChange: function( val ) { set( { buttonText: val } ); }
						} ),
						el( TextControl, {
							label: __( 'Individuelle Datenschutz-URL (Optional)', 'wm-cyber-events-core' ),
							value: attr.privacyPolicyUrl,
							placeholder: __( 'Standard: Datenschutzseite aus Einstellungen > Datenschutz', 'wm-cyber-events-core' ),
							onChange: function( val ) { set( { privacyPolicyUrl: val } ); }
						} )
					)
				),
				el(
					'div',
					{ key: 'preview', className: 'cybevents-gutenberg-preview' },
					el(
						'div',
						{ className: 'cybevents-gutenberg-card cybevents-gutenberg-card--form' },
						el( 'div', { className: 'cybevents-gutenberg-badge' }, __( 'Anmeldeformular', 'wm-cyber-events-core' ) ),
						el( 'h3', { className: 'cybevents-gutenberg-title' }, attr.customTitle || eventTitle ),
						el(
							'p',
							{ className: 'cybevents-gutenberg-desc' },
							attr.customSubtitle || __( 'Anmeldung mit Double Opt-in: Brevo sendet den Bestätigungslink, erst danach gilt die Anmeldung.', 'wm-cyber-events-core' )
						),
						el(
							'div',
							{ className: 'cybevents-gutenberg-tags' },
							el( 'span', { className: 'cybevents-gutenberg-tag' }, sprintf( /* translators: %s: style preset key */ __( 'Stil: %s', 'wm-cyber-events-core' ), attr.formStyle || 'card-glass' ) ),
							el( 'span', { className: 'cybevents-gutenberg-tag' }, sprintf( /* translators: %s: button label */ __( 'Button: %s', 'wm-cyber-events-core' ), attr.buttonText || __( 'Standard nach Ticketpreis', 'wm-cyber-events-core' ) ) )
						)
					)
				)
			];
		},
		save: function() { return null; }
	} );

	/* ==========================================================================
	   3. Block: Agenda Schedule Matrix
	   ========================================================================== */
	registerBlockType( 'wm-cyber-events/agenda-matrix', {
		edit: function( props ) {
			var attr = props.attributes;
			var set = props.setAttributes;
			var eventTitle = getSelectedEventLabel( attr.eventId );

			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Konferenz-Auswahl', 'wm-cyber-events-core' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Ziel-Veranstaltung', 'wm-cyber-events-core' ),
							value: attr.eventId,
							options: eventSelectOptions,
							onChange: function( val ) { set( { eventId: parseInt( val, 10 ) } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Überschrift & Untertitel', 'wm-cyber-events-core' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Überschrift', 'wm-cyber-events-core' ),
							value: attr.heading,
							onChange: function( val ) { set( { heading: val } ); }
						} ),
						el( TextControl, {
							label: __( 'Untertitel / Beschreibung', 'wm-cyber-events-core' ),
							value: attr.subheading,
							onChange: function( val ) { set( { subheading: val } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Suche, Angaben & Export', 'wm-cyber-events-core' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Live-Suchfeld für Sessions anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showSearchBar,
							onChange: function( val ) { set( { showSearchBar: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Raum- und Ortsangaben anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showRoomInfo,
							onChange: function( val ) { set( { showRoomInfo: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Referenten-Avatare anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showSpeakerAvatars,
							onChange: function( val ) { set( { showSpeakerAvatars: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Gesamten Kalender-Export (.ics) anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showExportButton,
							onChange: function( val ) { set( { showExportButton: val } ); }
						} )
					)
				),
				el(
					'div',
					{ key: 'preview', className: 'cybevents-gutenberg-preview' },
					el(
						'div',
						{ className: 'cybevents-gutenberg-card cybevents-gutenberg-card--agenda' },
						el( 'div', { className: 'cybevents-gutenberg-badge' }, __( 'Programm', 'wm-cyber-events-core' ) ),
						el( 'h3', { className: 'cybevents-gutenberg-title' }, attr.heading || eventTitle ),
						el(
							'p',
							{ className: 'cybevents-gutenberg-desc' },
							attr.subheading || __( 'Sessions in zeitlicher Reihenfolge mit Raum, Referent, Beschreibung, Suche und .ics-Export.', 'wm-cyber-events-core' )
						),
						el(
							'div',
							{ className: 'cybevents-gutenberg-tags' },
							attr.showSearchBar ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Suche', 'wm-cyber-events-core' ) ) : null,
							attr.showExportButton ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( '.ics-Download', 'wm-cyber-events-core' ) ) : null
						)
					)
				)
			];
		},
		save: function() { return null; }
	} );

	/* ==========================================================================
	   4. Block: Speaker & Expert Grid
	   ========================================================================== */
	registerBlockType( 'wm-cyber-events/speaker-grid', {
		edit: function( props ) {
			var attr = props.attributes;
			var set = props.setAttributes;
			var eventTitle = getSelectedEventLabel( attr.eventId );

			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Konferenz-Auswahl', 'wm-cyber-events-core' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Ziel-Veranstaltung', 'wm-cyber-events-core' ),
							value: attr.eventId,
							options: eventSelectOptions,
							onChange: function( val ) { set( { eventId: parseInt( val, 10 ) } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Layout & Spalten', 'wm-cyber-events-core' ), initialOpen: false },
						el( RangeControl, {
							label: __( 'Spaltenanzahl (Desktop)', 'wm-cyber-events-core' ),
							value: attr.columns,
							min: 2,
							max: 6,
							onChange: function( val ) { set( { columns: val } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Texte & Detail-Anzeigen', 'wm-cyber-events-core' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Badge / Dachzeile', 'wm-cyber-events-core' ),
							value: attr.badgeText,
							onChange: function( val ) { set( { badgeText: val } ); }
						} ),
						el( TextControl, {
							label: __( 'Überschrift', 'wm-cyber-events-core' ),
							value: attr.heading,
							onChange: function( val ) { set( { heading: val } ); }
						} ),
						el( TextControl, {
							label: __( 'Untertitel', 'wm-cyber-events-core' ),
							value: attr.subheading,
							onChange: function( val ) { set( { subheading: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Vortragsthema der Session anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showTopics,
							onChange: function( val ) { set( { showTopics: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Raumangabe anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showRoom,
							onChange: function( val ) { set( { showRoom: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Uhrzeit anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showTime,
							onChange: function( val ) { set( { showTime: val } ); }
						} )
					)
				),
				el(
					'div',
					{ key: 'preview', className: 'cybevents-gutenberg-preview' },
					el(
						'div',
						{ className: 'cybevents-gutenberg-card cybevents-gutenberg-card--speaker' },
						el( 'div', { className: 'cybevents-gutenberg-badge' }, attr.badgeText || __( 'Referenten', 'wm-cyber-events-core' ) ),
						el( 'h3', { className: 'cybevents-gutenberg-title' }, attr.heading || __( 'Referenten & Fachexperten', 'wm-cyber-events-core' ) ),
						el(
							'p',
							{ className: 'cybevents-gutenberg-desc' },
							attr.subheading || ( __( 'Konferenz: ', 'wm-cyber-events-core' ) + eventTitle )
						),
						el(
							'div',
							{ className: 'cybevents-gutenberg-tags' },
							el( 'span', { className: 'cybevents-gutenberg-tag' }, sprintf( /* translators: %d: number of columns */ __( '%d Spalten', 'wm-cyber-events-core' ), attr.columns || 3 ) ),
							attr.showTopics ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Vortragsthemen', 'wm-cyber-events-core' ) ) : null
						)
					)
				)
			];
		},
		save: function() { return null; }
	} );

	/* ==========================================================================
	   5. Block: Calendar & .ics Widget
	   ========================================================================== */
	registerBlockType( 'wm-cyber-events/calendar-widget', {
		edit: function( props ) {
			var attr = props.attributes;
			var set = props.setAttributes;
			var eventTitle = getSelectedEventLabel( attr.eventId );

			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Konferenz-Auswahl', 'wm-cyber-events-core' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Ziel-Veranstaltung', 'wm-cyber-events-core' ),
							value: attr.eventId,
							options: eventSelectOptions,
							onChange: function( val ) { set( { eventId: parseInt( val, 10 ) } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Titel', 'wm-cyber-events-core' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Individueller Titel', 'wm-cyber-events-core' ),
							value: attr.customTitle,
							placeholder: __( 'Standard: Titel der Veranstaltung', 'wm-cyber-events-core' ),
							onChange: function( val ) { set( { customTitle: val } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Kalender-Dienste & Optionen', 'wm-cyber-events-core' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Apple / Outlook (.ics Datei) anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showAppleIcs,
							onChange: function( val ) { set( { showAppleIcs: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Google Kalender Direkt-Link anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showGoogleCal,
							onChange: function( val ) { set( { showGoogleCal: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Outlook Web Direkt-Link anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showOutlookWeb,
							onChange: function( val ) { set( { showOutlookWeb: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Microsoft 365 Direkt-Link anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showOffice365,
							onChange: function( val ) { set( { showOffice365: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Zeitzonen-Hinweis anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showTimezone,
							onChange: function( val ) { set( { showTimezone: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Hinweis auf die Erinnerung in der .ics-Datei anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showReminderNotice,
							onChange: function( val ) { set( { showReminderNotice: val } ); }
						} )
					)
				),
				el(
					'div',
					{ key: 'preview', className: 'cybevents-gutenberg-preview' },
					el(
						'div',
						{ className: 'cybevents-gutenberg-card cybevents-gutenberg-card--calendar' },
						el( 'div', { className: 'cybevents-gutenberg-badge' }, __( 'Kalender', 'wm-cyber-events-core' ) ),
						el( 'h3', { className: 'cybevents-gutenberg-title' }, attr.customTitle || eventTitle ),
						el(
							'p',
							{ className: 'cybevents-gutenberg-desc' },
							__( '.ics-Datei mit einer Erinnerung einen Tag vor Beginn und Links zu Google Kalender, Outlook Web und Microsoft 365.', 'wm-cyber-events-core' )
						),
						el(
							'div',
							{ className: 'cybevents-gutenberg-tags' },
							attr.showAppleIcs ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( '.ics-Datei', 'wm-cyber-events-core' ) ) : null,
							attr.showGoogleCal ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Google Kalender', 'wm-cyber-events-core' ) ) : null,
							attr.showOutlookWeb ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Outlook Web', 'wm-cyber-events-core' ) ) : null,
							attr.showOffice365 ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Microsoft 365', 'wm-cyber-events-core' ) ) : null
						)
					)
				)
			];
		},
		save: function() { return null; }
	} );

	/* ==========================================================================
	   6. Block: Tokenized Session Selector
	   ========================================================================== */
	registerBlockType( 'wm-cyber-events/session-selector', {
		edit: function() {
			// Until 2026-09-15 this panel offered a display style, a heading, a description, overlap warnings
			// and a seat counter. The renderer takes none of them (render_session_selection_ui() receives only
			// the registration), so the block has no settings; the attributes stay in block.json for saved blocks.
			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Session-Auswahl', 'wm-cyber-events-core' ), initialOpen: true },
						el( 'p', {}, __( 'Dieser Block hat keine Einstellungen. Er zeigt die Sessions zu dem Token aus dem Bestätigungslink; ohne Token erscheint ein Hinweis.', 'wm-cyber-events-core' ) )
					)
				),
				el(
					'div',
					{ key: 'preview', className: 'cybevents-gutenberg-preview' },
					el(
						'div',
						{ className: 'cybevents-gutenberg-card cybevents-gutenberg-card--session' },
						el( 'div', { className: 'cybevents-gutenberg-badge' }, __( 'Session-Auswahl', 'wm-cyber-events-core' ) ),
						el( 'h3', { className: 'cybevents-gutenberg-title' }, __( 'Session-Auswahl für bestätigte Anmeldungen', 'wm-cyber-events-core' ) ),
						el(
							'p',
							{ className: 'cybevents-gutenberg-desc' },
							__( 'Ein Platz wird beim Speichern vergeben, solange die Session nicht ausgebucht ist.', 'wm-cyber-events-core' )
						)
					)
				)
			];
		},
		save: function() { return null; }
	} );

	/* ==========================================================================
	   7. Block: Digital Ticket Pass Verifier
	   ========================================================================== */
	registerBlockType( 'wm-cyber-events/ticket-pass-verifier', {
		edit: function( props ) {
			var attr = props.attributes;
			var set = props.setAttributes;

			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Ticketpass-Optionen', 'wm-cyber-events-core' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Pass-Überschrift', 'wm-cyber-events-core' ),
							value: attr.passTitle,
							onChange: function( val ) { set( { passTitle: val } ); }
						} ),
						el( TextControl, {
							label: __( 'Kopfzeile (Standard: Titel der Veranstaltung)', 'wm-cyber-events-core' ),
							value: attr.organizationName,
							onChange: function( val ) { set( { organizationName: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Drucken-Button (Print Badge) anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showPrintButton,
							onChange: function( val ) { set( { showPrintButton: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Ticketnummer anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showQrMatrix,
							onChange: function( val ) { set( { showQrMatrix: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Rechnungsnummer / Referenz anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showInvoiceRef,
							onChange: function( val ) { set( { showInvoiceRef: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Check-In Status anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showCheckinStatus,
							onChange: function( val ) { set( { showCheckinStatus: val } ); }
						} )
					)
				),
				el(
					'div',
					{ key: 'preview', className: 'cybevents-gutenberg-preview' },
					el(
						'div',
						{ className: 'cybevents-gutenberg-card cybevents-gutenberg-card--ticket' },
						el( 'div', { className: 'cybevents-gutenberg-badge' }, __( 'Teilnehmerpass', 'wm-cyber-events-core' ) ),
						el( 'h3', { className: 'cybevents-gutenberg-title' }, attr.passTitle || __( 'TEILNEHMERPASS', 'wm-cyber-events-core' ) ),
						el(
							'p',
							{ className: 'cybevents-gutenberg-desc' },
							__( 'Pass für bestätigte Anmeldungen, aufgerufen mit dem Token aus der Bestätigungs-E-Mail: Name, Firma, Status, Rechnungsnummer, Ticketnummer und Druckfunktion.', 'wm-cyber-events-core' )
						),
						el(
							'div',
							{ className: 'cybevents-gutenberg-tags' },
							attr.showQrMatrix ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Ticketnummer', 'wm-cyber-events-core' ) ) : null,
							attr.showPrintButton ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Druckfunktion', 'wm-cyber-events-core' ) ) : null,
							el( 'span', { className: 'cybevents-gutenberg-tag' }, attr.organizationName || __( 'Titel der Veranstaltung', 'wm-cyber-events-core' ) )
						)
					)
				)
			];
		},
		save: function() { return null; }
	} );

	/* ==========================================================================
	   8. Block: Security & Compliance Trust Badges
	   ========================================================================== */
	registerBlockType( 'wm-cyber-events/security-trust-badge', {
		edit: function( props ) {
			var attr = props.attributes;
			var set = props.setAttributes;

			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Layout & Farbschema', 'wm-cyber-events-core' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Raster-Layout', 'wm-cyber-events-core' ),
							value: attr.layout,
							options: [
								{ label: __( '4 Spalten Grid (Standard)', 'wm-cyber-events-core' ), value: 'grid-4-cols' },
								{ label: __( '2 Spalten Grid (Groß)', 'wm-cyber-events-core' ), value: 'grid-2-cols' }
							],
							onChange: function( val ) { set( { layout: val } ); }
						} ),
						el( SelectControl, {
							label: __( 'Farbthema', 'wm-cyber-events-core' ),
							value: attr.theme,
							options: [
								{ label: __( 'Dark Cyber Glow (Standard)', 'wm-cyber-events-core' ), value: 'dark-glow' },
								{ label: __( 'Federal Blue', 'wm-cyber-events-core' ), value: 'federal-blue' }
							],
							onChange: function( val ) { set( { theme: val } ); }
						} ),
						el( TextControl, {
							label: __( 'Überschrift', 'wm-cyber-events-core' ),
							value: attr.heading,
							onChange: function( val ) { set( { heading: val } ); }
						} ),
						el( TextControl, {
							label: __( 'Untertitel', 'wm-cyber-events-core' ),
							value: attr.subheading,
							onChange: function( val ) { set( { subheading: val } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Angezeigte Karten', 'wm-cyber-events-core' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Double Opt-in und Löschfristen', 'wm-cyber-events-core' ),
							checked: attr.showDsgvo,
							onChange: function( val ) { set( { showDsgvo: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Beschriftete Formulare', 'wm-cyber-events-core' ),
							checked: attr.showBitv,
							onChange: function( val ) { set( { showBitv: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Eigene Skripte und Styles', 'wm-cyber-events-core' ),
							checked: attr.showCdnPolicy,
							onChange: function( val ) { set( { showCdnPolicy: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Audit-Log und Tokens', 'wm-cyber-events-core' ),
							checked: attr.showGrundschutz,
							onChange: function( val ) { set( { showGrundschutz: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Rechnungs-XML und DATEV', 'wm-cyber-events-core' ),
							checked: attr.showZugferd,
							onChange: function( val ) { set( { showZugferd: val } ); }
						} )
					)
				),
				el(
					'div',
					{ key: 'preview', className: 'cybevents-gutenberg-preview' },
					el(
						'div',
						{ className: 'cybevents-gutenberg-card cybevents-gutenberg-card--trust' },
						el( 'div', { className: 'cybevents-gutenberg-badge' }, __( 'Datenschutz und Sicherheit', 'wm-cyber-events-core' ) ),
						el( 'h3', { className: 'cybevents-gutenberg-title' }, attr.heading || __( 'Datenschutz und Sicherheit', 'wm-cyber-events-core' ) ),
						el(
							'p',
							{ className: 'cybevents-gutenberg-desc' },
							attr.subheading || __( 'Karten zu dem, was das Plugin tut. Ob die Website rechtliche Vorgaben erfüllt, bewertet der Block nicht.', 'wm-cyber-events-core' )
						),
						el(
							'div',
							{ className: 'cybevents-gutenberg-tags' },
							attr.showDsgvo ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Double Opt-in', 'wm-cyber-events-core' ) ) : null,
							attr.showBitv ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Formulare', 'wm-cyber-events-core' ) ) : null,
							attr.showCdnPolicy ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Eigene Assets', 'wm-cyber-events-core' ) ) : null,
							attr.showGrundschutz ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Audit-Log', 'wm-cyber-events-core' ) ) : null,
							attr.showZugferd ? el( 'span', { className: 'cybevents-gutenberg-tag' }, __( 'Rechnungs-XML', 'wm-cyber-events-core' ) ) : null
						)
					)
				)
			];
		},
		save: function() { return null; }
	} );

	/* ==========================================================================
	   9. Block: Events Catalog & Overview Grid
	   ========================================================================== */
	registerBlockType( 'wm-cyber-events/events-grid', {
		edit: function( props ) {
			var attr = props.attributes;
			var set = props.setAttributes;

			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Abfrage & Filterung', 'wm-cyber-events-core' ), initialOpen: true },
						el( RangeControl, {
							label: __( 'Maximale Anzahl Konferenzen', 'wm-cyber-events-core' ),
							value: attr.limit,
							min: 1,
							max: 24,
							onChange: function( val ) { set( { limit: val } ); }
						} ),
						el( RangeControl, {
							label: __( 'Spaltenanzahl (Desktop)', 'wm-cyber-events-core' ),
							value: attr.columns,
							min: 1,
							max: 4,
							onChange: function( val ) { set( { columns: val } ); }
						} ),
						el( SelectControl, {
							label: __( 'Status-Filter', 'wm-cyber-events-core' ),
							value: attr.filterStatus,
							options: [
								{ label: __( 'Nur aktive Veranstaltungen', 'wm-cyber-events-core' ), value: 'active' },
								{ label: __( 'Aktive und archivierte Veranstaltungen', 'wm-cyber-events-core' ), value: 'all' },
								{ label: __( 'Nur archivierte Veranstaltungen', 'wm-cyber-events-core' ), value: 'archived' }
							],
							onChange: function( val ) { set( { filterStatus: val } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Sichtbare Metadaten & Buttons', 'wm-cyber-events-core' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Live-Suchleiste anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showSearch,
							onChange: function( val ) { set( { showSearch: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Preisschild anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showPriceTag,
							onChange: function( val ) { set( { showPriceTag: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Standort / Tagungsort anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showLocation,
							onChange: function( val ) { set( { showLocation: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Datums-Badge anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showDateBadge,
							onChange: function( val ) { set( { showDateBadge: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Direkten .ics Kalenderlink anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showCalendarQuickLink,
							onChange: function( val ) { set( { showCalendarQuickLink: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Beschreibungstext anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showDescription,
							onChange: function( val ) { set( { showDescription: val } ); }
						} ),
						el( TextControl, {
							label: __( 'CTA-Button Beschriftung', 'wm-cyber-events-core' ),
							value: attr.ctaButtonText,
							onChange: function( val ) { set( { ctaButtonText: val } ); }
						} )
					)
				),
				el(
					'div',
					{ key: 'preview', className: 'cybevents-gutenberg-preview' },
					el(
						'div',
						{ className: 'cybevents-gutenberg-card cybevents-gutenberg-card--events-grid' },
						el( 'div', { className: 'cybevents-gutenberg-badge' }, __( 'Veranstaltungen', 'wm-cyber-events-core' ) ),
						el( 'h3', { className: 'cybevents-gutenberg-title' }, __( 'Veranstaltungsübersicht', 'wm-cyber-events-core' ) ),
						el(
							'p',
							{ className: 'cybevents-gutenberg-desc' },
							__( 'Raster der Veranstaltungen mit Datum, Preis, Ort, Kurzbeschreibung, Anmelde-Button und .ics-Link.', 'wm-cyber-events-core' )
						),
						el(
							'div',
							{ className: 'cybevents-gutenberg-tags' },
							el( 'span', { className: 'cybevents-gutenberg-tag' }, sprintf( /* translators: %d: maximum number of events */ __( 'Max. %d Veranstaltungen', 'wm-cyber-events-core' ), attr.limit || 6 ) ),
							el( 'span', { className: 'cybevents-gutenberg-tag' }, sprintf( /* translators: %d: number of columns */ __( '%d Spalten', 'wm-cyber-events-core' ), attr.columns || 3 ) ),
							el( 'span', { className: 'cybevents-gutenberg-tag' }, sprintf( /* translators: %s: button label */ __( 'Button: %s', 'wm-cyber-events-core' ), attr.ctaButtonText || __( 'Zur Anmeldung', 'wm-cyber-events-core' ) ) )
						)
					)
				)
			];
		},
		save: function() { return null; }
	} );

	/* ==========================================================================
	   10. SEAT MAP BLOCK (wm-cyber-events/seat-map)
	   ========================================================================== */
	registerBlockType( 'wm-cyber-events/seat-map', {
		title: __( 'Cyber Events Sitzplan', 'wm-cyber-events-core' ),
		icon: 'screenoptions',
		category: 'cyber-events',
		edit: function( props ) {
			var attr = props.attributes;
			var set = props.setAttributes;

			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Sitzplan-Einstellungen', 'wm-cyber-events-core' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Veranstaltung auswählen', 'wm-cyber-events-core' ),
							value: attr.eventId,
							options: eventSelectOptions,
							onChange: function( val ) { set( { eventId: parseInt( val, 10 ) || 0 } ); }
						} ),
						el( TextControl, {
							label: __( 'Überschrift (optional)', 'wm-cyber-events-core' ),
							value: attr.heading,
							onChange: function( val ) { set( { heading: val } ); }
						} ),
						el( TextareaControl, {
							label: __( 'Beschreibungstext (optional)', 'wm-cyber-events-core' ),
							value: attr.description,
							onChange: function( val ) { set( { description: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Live-Aktualisierung aktivieren', 'wm-cyber-events-core' ),
							checked: attr.live,
							onChange: function( val ) { set( { live: val } ); }
						} )
					)
				),
				ServerSideRender
					? el( ServerSideRender, {
						key: 'ssr',
						block: 'wm-cyber-events/seat-map',
						attributes: attr
					} )
					: el(
						'div',
						{ key: 'preview', className: 'cybevents-gutenberg-preview' },
						el(
							'div',
							{ className: 'cybevents-gutenberg-card cybevents-gutenberg-card--session' },
							el( 'div', { className: 'cybevents-gutenberg-badge' }, __( 'Sitzplan', 'wm-cyber-events-core' ) ),
							el( 'h3', { className: 'cybevents-gutenberg-title' }, attr.heading || getSelectedEventLabel( attr.eventId ) ),
							el(
								'p',
								{ className: 'cybevents-gutenberg-desc' },
								__( 'Grafische Belegungsübersicht aller Sessions einer Veranstaltung mit barrierefreier Datentabelle.', 'wm-cyber-events-core' )
							)
						)
					)
			];
		},
		save: function() { return null; }
	} );

	/* ==========================================================================
	   11. MULTI-VIEW CALENDAR & AGENDA SUITE (wm-cyber-events/calendar-views)
	   ========================================================================== */
	registerBlockType( 'wm-cyber-events/calendar-views', {
		title: __( 'Cyber Events Multi-View Kalender', 'wm-cyber-events-core' ),
		icon: 'calendar-alt',
		category: 'cyber-events',
		edit: function( props ) {
			var attr = props.attributes;
			var set = props.setAttributes;

			return [
				el(
					InspectorControls,
					{ key: 'inspector' },
					el(
						PanelBody,
						{ title: __( 'Ansichten & Datenquelle', 'wm-cyber-events-core' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Veranstaltung auswählen', 'wm-cyber-events-core' ),
							value: attr.eventId,
							options: eventSelectOptions,
							onChange: function( val ) { set( { eventId: parseInt( val, 10 ) || 0 } ); }
						} ),
						el( SelectControl, {
							label: __( 'Standardansicht', 'wm-cyber-events-core' ),
							value: attr.defaultView,
							options: [
								{ label: __( 'Monatskalender', 'wm-cyber-events-core' ), value: 'month' },
								{ label: __( 'Wochenplan', 'wm-cyber-events-core' ), value: 'week' },
								{ label: __( 'Tagesagenda', 'wm-cyber-events-core' ), value: 'day' },
								{ label: __( 'Listenansicht', 'wm-cyber-events-core' ), value: 'list' },
								{ label: __( 'Kartenansicht', 'wm-cyber-events-core' ), value: 'photo' },
								{ label: __( 'Raumübersicht', 'wm-cyber-events-core' ), value: 'map' },
								{ label: __( 'Track-Matrix', 'wm-cyber-events-core' ), value: 'tracks' }
							],
							onChange: function( val ) { set( { defaultView: val } ); }
						} ),
						el( RangeControl, {
							label: __( 'Maximale Anzahl Einträge', 'wm-cyber-events-core' ),
							value: attr.limit,
							min: 1,
							max: 50,
							onChange: function( val ) { set( { limit: val } ); }
						} ),
						el( TextControl, {
							label: __( 'Monat (JJJJ-MM, optional)', 'wm-cyber-events-core' ),
							value: attr.month || '',
							onChange: function( val ) { set( { month: val } ); }
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Bedienleiste & Filter', 'wm-cyber-events-core' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Ansichts-Umschalter anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showToolbar,
							onChange: function( val ) { set( { showToolbar: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Track- und Raumfilter anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showFilter,
							onChange: function( val ) { set( { showFilter: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Suchfeld anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showSearch,
							onChange: function( val ) { set( { showSearch: val } ); }
						} ),
						el( ToggleControl, {
							label: __( 'Meldungsleiste anzeigen', 'wm-cyber-events-core' ),
							checked: attr.showTicker,
							onChange: function( val ) { set( { showTicker: val } ); }
						} )
					)
				),
				ServerSideRender
					? el( ServerSideRender, {
						key: 'ssr',
						block: 'wm-cyber-events/calendar-views',
						attributes: attr
					} )
					: el(
						'div',
						{ key: 'preview', className: 'cybevents-gutenberg-preview' },
						el(
							'div',
							{ className: 'cybevents-gutenberg-card cybevents-gutenberg-card--cal' },
							el( 'div', { className: 'cybevents-gutenberg-badge' }, __( 'Kalender-Ansichten', 'wm-cyber-events-core' ) ),
							el( 'h3', { className: 'cybevents-gutenberg-title' }, __( 'Multi-View Kalender & Agenda', 'wm-cyber-events-core' ) ),
							el(
								'p',
								{ className: 'cybevents-gutenberg-desc' },
								__( 'Umschaltbare Monats-, Wochen-, Tages-, Listen-, Karten-, Raum- und Track-Ansicht.', 'wm-cyber-events-core' )
							)
						)
					)
			];
		},
		save: function() { return null; }
	} );

} )( window.wp );
