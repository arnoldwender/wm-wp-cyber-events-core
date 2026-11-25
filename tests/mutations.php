<?php
/**
 * Mutations for tests/run-mutations.sh: each entry undoes one correction of 2026-09-15.
 *
 * An entry is [description, [[file, search, replace], ...]]. Every search string must occur exactly
 * once in its file, otherwise the mutation counts as not applied: the list cannot rot silently when
 * the code moves. Two defences of the same property are mutated together in one entry.
 *
 * @package CyberEvents
 */

return [
	// Group A — cron, activation, migration.
	['cron_schedules filter not registered at file load', [
		['wm-cyber-events-core.php', "add_filter( 'cron_schedules', array( 'CyberEvents_Activator', 'register_cron_schedules' ) );\n", ''],
	]],
	['every_minute recurrence missing', [
		['includes/class-activator.php', "\$schedules['every_minute'] = array(", "\$schedules['every_minute_disabled'] = array("],
	]],
	['recurrence label translated before init', [
		['includes/class-activator.php', "'display'  => 'Every minute (WM Cyber Events queue)',", "'display'  => __( 'Every minute (WM Cyber Events queue)', 'wm-cyber-events-core' ),"],
	]],
	['deactivation leaves the retention event scheduled', [
		['includes/class-deactivator.php', "wp_clear_scheduled_hook( 'cybevents_gdpr_retention_cron' );", ''],
	]],
	['schema bump runs activate() and seeds demo data', [
		['wm-cyber-events-core.php', "CyberEvents_Activator::migrate();", "CyberEvents_Activator::activate();"],
	]],
	['dashboard repair runs activate()', [
		['includes/class-admin.php', "CyberEvents_Activator::migrate();", "CyberEvents_Activator::activate();"],
	]],
	['init no longer schedules missing cron events', [
		['wm-cyber-events-core.php', "\tCyberEvents_Activator::ensure_cron_events();\n", ""],
	]],
	['audit residual_mac column missing from the schema', [
		['includes/class-activator.php', "residual_mac varchar(64) NOT NULL DEFAULT '',\n", ''],
	]],

	// Group B — Brevo client and queue.
	['DOI mail without API key reports a simulated success', [
		['includes/class-brevo-api.php', "\t\t\treturn self::not_configured_error();\n\t\t}\n\n\t\t\$settings", "\t\t\treturn array( 'code' => 200, 'data' => array( 'simulated' => true ) );\n\t\t}\n\n\t\t\$settings"],
	]],
	['attribute update without API key reports a simulated success', [
		['includes/class-brevo-api.php', "\t\t\treturn self::not_configured_error();\n\t\t}\n\n\t\t\$payload", "\t\t\treturn array( 'code' => 200 );\n\t\t}\n\n\t\t\$payload"],
	]],
	['completed job keeps the tokens in its payload', [
		['includes/class-queue-runner.php', "'error_message' => null,\n\t\t\t\t\t\t'payload'       => self::scrubbed_payload( \$payload ),", "'error_message' => null,"],
	]],
	['finally failed job keeps the tokens in its payload', [
		['includes/class-queue-runner.php', "'error_message' => \$error,\n\t\t\t\t\t\t\t'payload'       => self::scrubbed_payload( \$payload ),", "'error_message' => \$error,"],
	]],
	['legacy job type brevo_send_doi not processed', [
		['includes/class-queue-runner.php', "\t\t\t\tcase 'brevo_send_doi': // Pushed under this name until 2026-09-15; no runner handled it.\n", ''],
	]],
	['queue claim does not check that the job is still pending', [
		['includes/class-queue-runner.php', "'id'     => \$job->id,\n\t\t\t\t\t'status' => 'pending',", "'id'     => \$job->id,"],
	]],
	['stale processing jobs never handed back', [
		['includes/class-queue-runner.php', "time() - self::STALE_PROCESSING_SECONDS", "time() - 31536000"],
	]],

	// Group C — registration, confirmation, selection, check-in, booking, ticket, webhook.
	['captcha enforced although no widget can produce a solution', [
		['includes/class-accessibility.php', "\tpublic static function captcha_widget_available() {\n\t\treturn false;", "\tpublic static function captcha_widget_available() {\n\t\treturn true;"],
	]],
	['posted avatar_url stored', [
		['includes/class-rest-api.php', "'avatar_url'      => \$avatar_url,", "'avatar_url'      => esc_url_raw( (string) \$request->get_param( 'avatar_url' ) ),"],
	]],
	['registration queued under a job type no runner handles', [
		['includes/class-rest-api.php', "'brevo_doi',\n\t\t\tarray(\n\t\t\t\t'registration_id' => \$result['registration_id'],", "'brevo_send_doi_v2',\n\t\t\tarray(\n\t\t\t\t'registration_id' => \$result['registration_id'],"],
	]],
	['DOI mail carries no confirmation link', [
		['includes/class-rest-api.php', "'redirection_url' => self::confirmation_url( \$result['raw_doi_token'], \$result['raw_token'] ),", "'redirection_url' => home_url( '/' ),"],
	]],
	['registration audit entry copies the e-mail address', [
		['includes/class-database.php', "wp_json_encode( array( 'event_id' => \$event_id ) )", "wp_json_encode( array( 'event_id' => \$event_id, 'email' => \$email ) )"],
	]],
	['DOI token reusable after confirmation', [
		['includes/class-database.php', "'doi_confirmed_at' => \$now,\n\t\t\t\t'doi_token_hash'   => null,", "'doi_confirmed_at' => \$now,"],
	]],
	['non-pending registrations confirmed by their DOI link', [
		['includes/class-database.php', "if ( 'pending_doi' !== \$reg->status ) {", "if ( false ) {"],
		['includes/class-database.php', "'id'     => \$reg->id,\n\t\t\t\t'status' => 'pending_doi',", "'id'     => \$reg->id,"],
	]],
	['confirmation passes on a participant token of another registration', [
		['includes/class-rest-api.php', "if ( '' !== (string) \$raw_token && hash_equals( (string) \$reg->token_hash, hash( 'sha256', (string) \$raw_token ) ) ) {", "if ( '' !== (string) \$raw_token ) {"],
	]],
	['no confirmation route', [
		['includes/class-rest-api.php', "'callback'            => array( __CLASS__, 'handle_confirm' ),", "'callback'            => array( __CLASS__, 'handle_get_session_selection' ),"],
	]],
	['script root without the cybevents/v1 namespace', [
		['wm-cyber-events-core.php', "'root'  => esc_url_raw( rest_url( 'cybevents/v1' ) ),", "'root'  => esc_url_raw( rest_url() ),"],
	]],
	['script reads booked_ids, which the route never sends', [
		['public/js/cybevents-public.js', "(data.booked_sessions || [])", "(data.booked_ids || [])"],
	]],
	['session selection open to unconfirmed registrations', [
		['includes/class-rest-api.php', "if ( 'confirmed' !== \$reg->status ) {\n\t\t\treturn new WP_REST_Response( array( 'success' => false, 'message' => 'Diese Registrierung ist nicht bestätigt; Sessions", "if ( false ) {\n\t\t\treturn new WP_REST_Response( array( 'success' => false, 'message' => 'Diese Registrierung ist nicht bestätigt; Sessions"],
	]],
	['session selection returns booked_ids', [
		['includes/class-rest-api.php', "'booked_sessions' => array_map( 'intval', \$booked ),", "'booked_ids'      => array_map( 'intval', \$booked ),"],
	]],
	['check-in admits unconfirmed registrations', [
		['includes/class-rest-api.php', "if ( 'confirmed' !== \$reg->status ) {\n\t\t\treturn new WP_REST_Response( array( 'success' => false, 'message' => 'Zugang verweigert", "if ( 'cancelled' === \$reg->status ) {\n\t\t\treturn new WP_REST_Response( array( 'success' => false, 'message' => 'Zugang verweigert"],
	]],
	['check-in update not conditional on checked_in = 0', [
		['includes/class-rest-api.php', "'id'         => \$reg->id,\n\t\t\t\t'checked_in' => 0,", "'id'         => \$reg->id,"],
	]],
	['check-in audit entry copies the e-mail address', [
		['includes/class-rest-api.php', "wp_json_encode( array( 'time' => \$now ) )", "wp_json_encode( array( 'time' => \$now, 'email' => \$reg->email ) )"],
	]],
	['unconfirmed registrations book seats', [
		['includes/class-rest-api.php', "if ( 'confirmed' !== \$reg->status ) {\n\t\t\treturn new WP_REST_Response( array( 'success' => false, 'message' => 'Diese Registrierung ist nicht bestätigt; es können", "if ( false ) {\n\t\t\treturn new WP_REST_Response( array( 'success' => false, 'message' => 'Diese Registrierung ist nicht bestätigt; es können"],
	]],
	['failed swap keeps the seats taken in the request', [
		['includes/class-rest-api.php', "foreach ( \$booked as \$undo_id ) {", "foreach ( array() as \$undo_id ) {"],
	]],
	['ticket cacheable by shared caches', [
		['includes/class-rest-api.php', "'private, no-store, max-age=0'", "'public, max-age=86400'"],
	]],
	['ticket shows a client venue when the event has no location', [
		['includes/class-rest-api.php', "'event_location'  => (string) \$event->location,", "'event_location'  => \$event->location ?: 'Kongresszentrum, Halle (Saale)',"],
	]],
	['ticket issued for unconfirmed registrations', [
		['includes/class-rest-api.php', "if ( ! \$reg || 'confirmed' !== \$reg->status ) {", "if ( ! \$reg ) {"],
	]],
	['webhook audit entry stores the payload with the address', [
		['includes/class-rest-api.php', "wp_json_encode( array( 'registrations' => count( (array) \$registrations ) ) )", "wp_json_encode( \$params )"],
	]],

	// Group D — privacy.
	['deleting a registration leaves its seats taken', [
		['includes/class-gdpr-manager.php', "\t\tself::release_sessions( \$reg );\n\t\tself::delete_avatar_file( (string) ( \$reg->avatar_url ?? '' ) );\n\n\t\t// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching\n\t\t\$deleted", "\t\tself::delete_avatar_file( (string) ( \$reg->avatar_url ?? '' ) );\n\n\t\t// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching\n\t\t\$deleted"],
	]],
	['eraser leaves the address in audit entries', [
		['includes/class-gdpr-manager.php', "foreach ( (array) \$audit_ids as \$audit_id ) {", "foreach ( array() as \$audit_id ) {"],
	]],
	['eraser leaves queued mail for the address', [
		['includes/class-gdpr-manager.php', "foreach ( (array) \$queue_ids as \$queue_id ) {", "foreach ( array() as \$queue_id ) {"],
	]],
	['eraser anonymises through a column that does not exist', [
		['includes/class-gdpr-manager.php', "'company'        => '',\n\t\t\t\t\t'avatar_url'     => '',", "'company'        => '',\n\t\t\t\t\t'dietary_requirements' => '',\n\t\t\t\t\t'avatar_url'     => '',"],
	]],
	['eraser returns a count where WordPress expects a boolean', [
		['includes/class-gdpr-manager.php', "'items_removed'  => \$removed_any,", "'items_removed'  => \$items_removed,"],
	]],
	['exporter joins wp_posts instead of the events table', [
		['includes/class-gdpr-manager.php', "LEFT JOIN {\$prefix}cybevents_events e ON r.event_id = e.id", "LEFT JOIN {\$wpdb->posts} e ON r.event_id = e.ID"],
	]],
	['exporter omits invoices', [
		['includes/class-gdpr-manager.php', "foreach ( (array) \$invoices as \$inv ) {", "foreach ( array() as \$inv ) {"],
	]],
	['retention purge deletes rows without releasing seats', [
		['includes/class-gdpr-manager.php', "foreach ( (array) \$expired as \$reg ) {\n\t\t\tif ( self::delete_registration( \$reg ) ) {", "foreach ( (array) \$expired as \$reg ) {\n\t\t\tif ( \$wpdb->delete( \"{\$wpdb->prefix}cybevents_registrations\", array( 'id' => (int) \$reg->id ) ) ) {"],
	]],
	['pseudonymised entries get no residual MAC', [
		['includes/class-database.php', "'residual_mac'     => self::audit_residual_mac( \$row ),", "'residual_mac'     => '',"],
	]],
	['verification skips the content of rows marked as pseudonymised', [
		['includes/class-database.php', "if ( '' === \$residual || ! hash_equals( self::audit_residual_mac( \$row ), \$residual ) ) {", "if ( false ) {"],
	]],
	['rotation pseudonymises invoice entries', [
		['includes/class-gdpr-manager.php', "WHERE event_type NOT IN ( 'invoice_created', 'invoice_cancelled' )\n\t\t\t\t   AND pseudonymized_at IS NULL", "WHERE event_type NOT IN ( 'none' )\n\t\t\t\t   AND pseudonymized_at IS NULL"],
	]],
	['self-service cancellation keeps the seats', [
		['includes/class-gdpr-manager.php', "\t\t// 1. Release all booked sessions atomically\n\t\tself::release_sessions( \$reg );\n", ''],
	]],
	['self-service cancellation audit entry copies the address', [
		['includes/class-gdpr-manager.php', "wp_json_encode( array( 'reason' => \$reason ) )", "wp_json_encode( array( 'reason' => \$reason, 'email' => \$reg->email ) )"],
	]],
	['avatar deletion accepts URLs outside the avatar directory', [
		['includes/class-gdpr-manager.php', "if ( 0 !== strpos( \$avatar_url, \$base_url ) ) {", "if ( false ) {"],
	]],
	['avatar deletion accepts any file name', [
		['includes/class-gdpr-manager.php', "'/^avatar_[A-Za-z0-9]+\\.(jpg|png|webp)\$/'", "'/.+/'"],
	]],

	// Group E — hub health and telemetry.
	['health HEALTHY without Brevo configuration', [
		['includes/class-cyber-events-spoke-adapter.php', "return 'NEEDS_CONFIG';", "return 'HEALTHY';"],
	]],
	['health ignores the queue event and failed jobs', [
		['includes/class-cyber-events-spoke-adapter.php', "if ( ! \$metrics['queue_event_scheduled'] || \$metrics['failed_queue_tasks'] > 0 ) {", "if ( false ) {"],
	]],
	['telemetry literal back', [
		['includes/class-cyber-events-spoke-adapter.php', "'audit_key_source'      => CyberEvents_Database::audit_key_source(),", "'audit_key_source'      => CyberEvents_Database::audit_key_source(),\n\t\t\t'db_isolation'          => 'INNODB_ROW_LOCKS_ACTIVE',"],
	]],
	['SBOM names a standard the code does not establish', [
		['includes/class-cyber-events-spoke-adapter.php', "'value' => 'attendee CSV, DATEV EXTF batch, CII XML draft for invoices, iCalendar',", "'value' => 'ZUGFeRD 2.3 / Factur-X / EN 16931',"],
	]],
	['SBOM declares the proprietary licence again, with an id and a name at once', [
		['includes/class-cyber-events-spoke-adapter.php', "'id' => 'GPL-2.0-or-later',", "'id'   => 'Proprietary',\n\t\t\t\t\t\t'name' => 'Proprietary Commercial License',"],
	]],
	['SBOM licence carries an id and a name at once again, which CycloneDX 1.6 does not accept', [
		['includes/class-cyber-events-spoke-adapter.php', "'id' => 'GPL-2.0-or-later',", "'id' => 'GPL-2.0-or-later',\n\t\t\t\t\t\t'name' => 'GNU General Public License v2.0 or later',"],
	]],

	// Group F — CSV import.
	['import records privacy consent for people who never saw the form', [
		['includes/class-csv-importer.php', "'consent_privacy'  => 0,", "'consent_privacy'  => 1,"],
	]],
	['CSV parsing relies on the deprecated implicit escape', [
		['includes/class-csv-importer.php', "\$row = str_getcsv( \$trimmed, \$delimiter, '\"', '' );", "\$row = str_getcsv( \$trimmed, \$delimiter );"],
	]],

	// Group G — admin, ticket block, captcha.
	['admin audit call with user and object swapped', [
		['includes/class-admin.php', "log_audit( 'event_deleted', \$event_id, get_current_user_id(),", "log_audit( 'event_deleted', get_current_user_id(), \$event_id,"],
	]],
	['DATEV link to a page that does not exist', [
		['includes/class-admin.php', "\t'<p><a href=\"' . esc_url( admin_url( 'admin.php?page=cybevents-datev-export' ) )", "\t'<p><a href=\"' . esc_url( admin_url( 'admin.php?page=cybevents-datev' ) )"],
	]],
	['factory reset followed by a settings save', [
		['includes/class-admin.php', "if ( ! \$is_demo_or_reset && isset( \$_POST['cybevents_settings_nonce'] )", "if ( isset( \$_POST['cybevents_settings_nonce'] )"],
	]],
	['settings save resets the invoice counter', [
		['includes/class-admin.php', "absint( \$previous['invoice_counter'] ?? 1000 )", "1000"],
	]],
	['token hashes embedded in the registrations list', [
		['includes/class-admin.php', "unset( \$r->token_hash, \$r->doi_token_hash );", ''],
	]],
	['manual DOI confirmation confirms any registration', [
		['includes/class-admin.php', "'id'     => \$reg_id,\n\t\t\t\t\t'status' => 'pending_doi',", "'id'     => \$reg_id,"],
	]],
	['ticket pass printed for unconfirmed registrations', [
		['includes/class-blocks-manager.php', "if ( \$reg && 'confirmed' !== \$reg->status ) {", "if ( false ) {"],
	]],
	['ticket pass shows a client venue', [
		['includes/class-blocks-manager.php', "\$location      = \$event ? (string) \$event->location : '';", "\$location      = 'Kongresszentrum, Halle (Saale)';"],
	]],
	['ticket preview shows a real person', [
		['includes/class-blocks-manager.php', "__( 'Max Mustermann', 'wm-cyber-events-core' )", "'Dr. Thomas de Maizière'"],
	]],
	['attendee modal saves an unknown status as confirmed', [
		['includes/class-admin.php', "? \$posted_status : (string) \$old_reg->status;", "? \$posted_status : 'confirmed';"],
	]],
	['attendee modal offers no bounced status', [
		['includes/class-admin.php', "\t\t\t\t\t\t\t\t\t\t<option value=\"bounced\"><?php esc_html_e( 'Unzustellbar (Bounced)', 'wm-cyber-events-core' ); ?></option>\n", ''],
	]],
	['captcha script enqueued without a widget', [
		['wm-cyber-events-core.php', " && CyberEvents_Accessibility::captcha_widget_available() ) {\n\t\twp_enqueue_script(", " ) {\n\t\twp_enqueue_script("],
	]],

	// Group H — invoices, credit notes, numbering, DATEV, iCalendar (second PR of 2026-09-15).
	['invoice without seller data', [
		['includes/class-invoicing.php', "if ( \$problems ) {\n\t\t\treturn new WP_Error( 'cybevents_seller_incomplete', 'Rechnung nicht erstellt:", "if ( false ) {\n\t\t\treturn new WP_Error( 'cybevents_seller_incomplete', 'Rechnung nicht erstellt:"],
	]],
	['tax truncated instead of rounded', [
		['includes/class-invoicing.php', "\$tax_cents = (int) round( \$net_cents * (float) \$rate / 100 );", "\$tax_cents = (int) ( \$net_cents * (float) \$rate / 100 );"],
	]],
	['guideline ID that no validator accepts', [
		['includes/class-invoicing.php', "const GUIDELINE_ID = 'urn:cen.eu:en16931:2017';", "const GUIDELINE_ID = 'urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:comfort';"],
	]],
	['XML without the delivery block', [
		['includes/class-invoicing.php', "\t\t\$x .= '<ram:ApplicableHeaderTradeDelivery>", "\t\t\$x .= '' . '<ram:ApplicableHeaderTradeDeliveryRemoved>"],
	]],
	['credit note written as type 380', [
		['includes/class-invoicing.php', "'type_code'          => self::TYPE_CREDIT_NOTE,", "'type_code'          => self::TYPE_INVOICE,"],
	]],
	['credit note without a reference to the original', [
		['includes/class-invoicing.php', "if ( self::TYPE_CREDIT_NOTE === \$type && ! empty( \$args['preceding_number'] ) ) {", "if ( false ) {"],
	]],
	['credit note number with the old -STORNO suffix', [
		['includes/class-invoicing.php', "\$storno_number = CyberEvents_Database::get_next_invoice_number();", "\$storno_number = CyberEvents_Database::get_next_invoice_number() . '-STORNO';"],
	]],
	['sequence recomputed from the table, so deleted numbers come back', [
		['includes/class-database.php', "\$current = get_option( self::INVOICE_SEQUENCE_OPTION, null );", "\$current = null;"],
	]],
	['fresh sequence ignores numbers already issued', [
		['includes/class-database.php', "\$current = max( \$current, (int) \$m[1] );", "\$current = \$current;"],
	]],
	['zero-rated invoice written as standard rated', [
		['includes/class-invoicing.php', "if ( \$tax_rate <= 0 ) {", "if ( false ) {"],
	]],
	['IBAN check digit not checked', [
		['includes/class-invoicing.php', "return 1 === \$remainder;", "return true;"],
	]],
	['DATEV "Erzeugt am" as a date only', [
		['includes/class-datev-exporter.php', "wp_date( 'YmdHis' ) . '000',", "gmdate( 'Ymd' ),"],
	]],
	['DATEV fiscal year start from the current year', [
		['includes/class-datev-exporter.php', "\$from->format( 'Y' ) . '0101',", "gmdate( 'Y' ) . '0101',"],
	]],
	['DATEV batch label names a client', [
		['includes/class-datev-exporter.php', "self::text_field( 'Rechnungen ' . \$df_formatted . '-' . \$dt_formatted, 30 )", "self::text_field( 'Buchungen Musterkunde', 30 )"],
	]],
	['DATEV text keeps a leading formula character', [
		['includes/class-datev-exporter.php', "if ( preg_match( '/^[=+\\-@]/', \$text ) ) {", "if ( false ) {"],
	]],
	['DATEV export in ISO-8859-1 (no euro sign)', [
		['includes/class-datev-exporter.php', "mb_convert_encoding( (string) \$csv, 'Windows-1252', 'UTF-8' )", "mb_convert_encoding( (string) \$csv, 'ISO-8859-1', 'UTF-8' )"],
	]],
	['DATEV batch across two fiscal years', [
		['includes/class-datev-exporter.php', "if ( \$from->format( 'Y' ) !== \$to->format( 'Y' ) ) {", "if ( false ) {"],
	]],
	['admin DATEV download through utf8_decode', [
		['includes/class-admin.php', "echo CyberEvents_DATEV_Exporter::to_windows_1252( \$csv );", "echo utf8_decode( \$csv );"],
	]],
	['iCalendar reads site time as UTC', [
		['includes/class-calendar-exporter.php', "return gmdate( 'Ymd\\THis\\Z', self::site_time_to_timestamp( \$datetime_str ) );", "return gmdate( 'Ymd\\THis\\Z', strtotime( \$datetime_str ) );"],
	]],
	['iCalendar escapes the session location twice', [
		['includes/class-calendar-exporter.php', "array( trim( (string) \$sess->raum ), \$location )", "array( trim( (string) \$sess->raum ), self::escape_ical_text( \$location ) )"],
	]],
	['iCalendar description with a literal backslash-n', [
		['includes/class-calendar-exporter.php', "\$sess->speaker . \"\\n\\n\" . \$sess_desc", "\$sess->speaker . '\\n\\n' . \$sess_desc"],
	]],
	['iCalendar invents a location', [
		['includes/class-calendar-exporter.php', "\$location    = trim( (string) \$event->location );", "\$location    = trim( (string) \$event->location ) ?: 'Halle (Saale)';"],
	]],
	['calendar block links read site time as UTC', [
		['includes/class-blocks-manager.php', "\$utc_start   = CyberEvents_Calendar_Exporter::site_time_to_timestamp( \$event->start_date );", "\$utc_start   = \$start_ts;"],
	]],
	['calendar block names a client venue', [
		['includes/class-blocks-manager.php', "<?php if ( '' !== (string) \$event->location ) : ?>\n\t\t\t\t\t\t\t<span>", "<?php if ( true ) : ?>\n\t\t\t\t\t\t\t<span>"],
		['includes/class-blocks-manager.php', "<?php echo esc_html( \$event->location ); ?></span>\n\t\t\t\t\t\t<?php endif; ?>\n\t\t\t\t\t\t<?php if ( \$show_timezone ) : ?>", "<?php echo esc_html( \$event->location ?: 'Kongresszentrum, Halle (Saale)' ); ?></span>\n\t\t\t\t\t\t<?php endif; ?>\n\t\t\t\t\t\t<?php if ( \$show_timezone ) : ?>"],
	]],
	['dashboard revenue counts credit notes', [
		['includes/class-admin.php', "SELECT COALESCE(SUM(gross_amount), 0) FROM {\$wpdb->prefix}cybevents_invoices WHERE invoice_type = 'standard' AND status = %s", "SELECT COALESCE(SUM(gross_amount), 0) FROM {\$wpdb->prefix}cybevents_invoices WHERE status = %s"],
	]],
	// Group I — honest texts (third PR of 2026-09-15).
	['honeypot field not checked', [
		['includes/class-rest-api.php', "if ( '' !== trim( (string) \$request->get_param( 'cybevents_hp' ) ) ) {", "if ( false ) {"],
	]],
	['conformity badge back on the registration form', [
		['includes/class-accessibility.php', "<?php // Two badges, \"Offizielle Konferenz\" and \"DSGVO & BFSG 2025 Konform\", stood here until 2026-09-15. ?>", "<span class=\"cybevents-badge-pill\"><?php esc_html_e( 'DSGVO & BFSG 2025 Konform', 'wm-cyber-events-core' ); ?></span>"],
	]],
	['registration form invents a client location', [
		['includes/class-accessibility.php', "\$location        = (string) \$event->location; // Filled", "\$location        = \$event->location ?: 'Halle (Saale) · Kongresszentrum'; // Filled"],
	]],
	['captcha container rendered without a widget', [
		['includes/class-accessibility.php', "self::captcha_widget_available() && ! empty( \$settings['enable_friendly_captcha'] )", "! empty( \$settings['enable_friendly_captcha'] )"],
	]],
	['trust block states BFSG conformity again', [
		['includes/class-blocks-manager.php', "<h3><?php esc_html_e( 'Beschriftete Formulare', 'wm-cyber-events-core' ); ?></h3>", "<h3><?php esc_html_e( 'BFSG 2025 & BITV 2.0', 'wm-cyber-events-core' ); ?></h3>"],
	]],
	['trust block shows a captcha card without a widget', [
		['includes/class-blocks-manager.php', "\$show_captcha   = ( \$attributes['showFriendlyCaptcha'] ?? true ) && CyberEvents_Accessibility::captcha_widget_available();", "\$show_captcha   = \$attributes['showFriendlyCaptcha'] ?? true;"],
	]],
	['hero promises catering', [
		['includes/class-blocks-manager.php', "<span><?php esc_html_e( 'Preis', 'wm-cyber-events-core' ); ?></span>", "<span><?php esc_html_e( 'Inkl. Catering & Tagungsunterlagen', 'wm-cyber-events-core' ); ?></span>"],
	]],
	['hero countdown reads site time as UTC', [
		['includes/class-blocks-manager.php', "data-target-date=\"<?php echo esc_attr( gmdate( 'c', \$start_utc ) ); ?>\"", "data-target-date=\"<?php echo esc_attr( gmdate( 'c', \$start_ts ) ); ?>\""],
	]],
	['hero offers free sign-up for a paid event', [
		['includes/class-blocks-manager.php', "__( 'Jetzt anmelden', 'wm-cyber-events-core' ) : __( 'Jetzt kostenfrei anmelden', 'wm-cyber-events-core' ) );", "__( 'Jetzt kostenfrei anmelden', 'wm-cyber-events-core' ) : __( 'Jetzt kostenfrei anmelden', 'wm-cyber-events-core' ) );"],
	]],
	['JSON-LD names a client as organizer', [
		['includes/class-events-manager.php', "if ( '' !== \$seller['name'] ) {", "\$seller['name'] = 'Beispielagentur GmbH';\n\t\tif ( true ) {"],
	]],
	['JSON-LD stamps site time as UTC', [
		['includes/class-events-manager.php', "\$as_iso = static fn( \$site_time ) => wp_date( 'c', CyberEvents_Calendar_Exporter::site_time_to_timestamp( \$site_time ) );", "\$as_iso = static fn( \$site_time ) => gmdate( 'c', strtotime( \$site_time ) );"],
	]],
	['venue without the flag counts as accessible', [
		['includes/class-venues-manager.php', "\$is_access  = ( '1' === (string) \$accessible ); // A venue", "\$is_access  = ( '' === \$accessible || '1' === (string) \$accessible ); // A venue"],
	]],
	['Spanish string back in the German dashboard', [
		['includes/class-admin.php', "<?php esc_html_e( 'Springen zu:', 'wm-cyber-events-core' ); ?>", "<?php esc_html_e( 'Saltar a:', 'wm-cyber-events-core' ); ?>"],
	]],
	['dashboard jump link without a target', [
		['includes/class-admin.php', "<div id=\"wm-sec-audit\" class=\"cybevents-admin-section\">", "<div class=\"cybevents-admin-section\">"],
	]],
	['emoji back in the session selector', [
		['includes/class-sessions-manager.php', "esc_html__( 'Für diese Session eingetragen', 'wm-cyber-events-core' )", "esc_html__( '✓ Für diese Session eingetragen', 'wm-cyber-events-core' )"],
	]],
	['script says the confirmation mail was already sent', [
		['public/js/cybevents-public.js', "Sie erhalten eine E-Mail mit einem Bestätigungslink.", "Wir haben Ihnen eine E-Mail mit einem Bestätigungslink gesendet."],
	]],
	['block description claims a lock mechanism', [
		['blocks/session-selector/block.json', "Plätze werden beim Speichern vergeben, solange die Session nicht ausgebucht ist.", "Live-Platzreservierung mit Row-Level InnoDB-Locks."],
	]],
	['seller IBAN not saved', [
		['includes/class-admin.php', "\t\t\t\t'seller_iban'              => strtoupper( str_replace( ' ', '', sanitize_text_field( wp_unslash( \$_POST['seller_iban'] ?? '' ) ) ) ),\n", ''],
	]],
	// Block editor and the renderers behind it (fourth PR of 2026-09-15).
	['editor offers a phone field the form never renders', [
		['blocks/editor.js', "set( { showCompanyField: val } ); }", "set( { showCompanyField: val } ); }\n} ),\nel( ToggleControl, {\nlabel: __( 'Telefonnummer Feld anzeigen', 'wm-cyber-events-core' ),\nchecked: attr.showPhoneField,\nonChange: function( val ) { set( { showPhoneField: val } ); }"],
	]],
	['agenda editor offers track filter buttons the renderer never draws', [
		['blocks/editor.js', "set( { showSearchBar: val } ); }", "set( { showSearchBar: val } ); }\n} ),\nel( ToggleControl, {\nlabel: __( 'Track-Filterbuttons anzeigen', 'wm-cyber-events-core' ),\nchecked: attr.showFilterButtons,\nonChange: function( val ) { set( { showFilterButtons: val } ); }"],
	]],
	['trust block editor offers a colour theme without CSS', [
		['blocks/editor.js', "value: 'federal-blue' }\n\t\t\t\t\t\t\t],", "value: 'federal-blue' },\n{ label: __( 'Minimal Outline', 'wm-cyber-events-core' ), value: 'outline' }\n\t\t\t\t\t\t\t],"],
	]],
	['calendar editor without the Microsoft 365 toggle', [
		['blocks/editor.js', "checked: attr.showOffice365,", "checked: attr.showOutlookWeb,"],
		['blocks/editor.js', "onChange: function( val ) { set( { showOffice365: val } ); }", "onChange: function( val ) { set( { showOutlookWeb: val } ); }"],
	]],
	['events grid "archived" falls back to the active events', [
		['includes/class-blocks-manager.php', "} elseif ( 'archived' === \$filter_status ) {", "} elseif ( false ) {"],
	]],
	['events grid "all" asks for a status no event carries', [
		['includes/class-blocks-manager.php', "WHERE status IN ('active', 'archived') ORDER BY", "WHERE status IN ('active', 'completed') ORDER BY"],
	]],
	['emoji back in the block editor', [
		['blocks/editor.js', "__( 'Angezeigte Karten', ", "__( '🏆 Angezeigte Karten', "],
	]],
	['editor preview claims row-level locks', [
		['blocks/editor.js', "__( 'Ein Platz wird beim Speichern vergeben, solange die Session nicht ausgebucht ist.', 'wm-cyber-events-core' )", "__( 'Live-Platzreservierung mit Row-Level InnoDB Locks.', 'wm-cyber-events-core' )"],
	]],
	['ticket pass calls itself official', [
		['includes/class-blocks-manager.php', ": __( 'TEILNEHMERPASS', 'wm-cyber-events-core' );", ": __( 'OFFIZIELLER TEILNEHMER-PASS', 'wm-cyber-events-core' );"],
	]],
	['consent without a privacy link when the block sets no URL', [
		['includes/class-accessibility.php', ": esc_url( get_privacy_policy_url() );", ": '';"],
	]],
	// Group J — what WordPress renders with the block.json defaults (fifth PR of 2026-09-15).
	['trust block default credits a client again', [
		['blocks/security-trust-badge/block.json', "\"subheading\": {\n\t\t\t\"type\": \"string\",\n\t\t\t\"default\": \"\"", "\"subheading\": {\n\t\t\t\"type\": \"string\",\n\t\t\t\"default\": \"Entwickelt nach den strengen Standards einer Bundesbehörde.\""],
	]],
	['hero block default offers free sign-up for every event', [
		['blocks/event-hero/block.json', "\"ctaText\": {\n\t\t\t\"type\": \"string\",\n\t\t\t\"default\": \"\"", "\"ctaText\": {\n\t\t\t\"type\": \"string\",\n\t\t\t\"default\": \"Jetzt kostenfrei anmelden\""],
	]],
	['ticket pass default names the demo summit', [
		['blocks/ticket-pass-verifier/block.json', "\"organizationName\": {\n\t\t\t\"type\": \"string\",\n\t\t\t\"default\": \"\"", "\"organizationName\": {\n\t\t\t\"type\": \"string\",\n\t\t\t\"default\": \"Demo-Konferenz 2026\""],
	]],
	['hero agenda button points to an id nothing carries', [
		['includes/class-blocks-manager.php', ": '#cybevents-agenda-' . (int) \$event->id;", ": '#cybevents-agenda';"],
	]],
	['event editor pre-fills a client venue', [
		['includes/class-events-manager.php', "\$location     = (string) get_post_meta( \$post->ID, '_cybevents_location', true );", "\$location     = get_post_meta( \$post->ID, '_cybevents_location', true ) ?: 'Kongresszentrum, Halle (Saale)';"],
	]],
	['session editor suggests a client person', [
		['includes/class-sessions-manager.php', "'z. B. Erika Mustermann (Beispiel GmbH)'", "'z.B. Dr. Max Beispiel (Kongresszentrum Halle (Saale))'"],
	]],
	['demo sessions written under keys the editor does not read', [
		['includes/class-seeder.php', "update_post_meta( \$sess_post_id, '_cybevents_parent_event_id', \$event_id );", "update_post_meta( \$sess_post_id, '_cybevents_event_id', \$event_id );"],
	]],
	['demo seed leaves the phantom event row of the save_post hook', [
		['includes/class-seeder.php', "\t\t\t\$wpdb->query( \$wpdb->prepare( \"DELETE FROM {\$wpdb->prefix}cybevents_events WHERE wp_post_id = %d AND id <> %d\", \$post_id, \$event_id ) );\n", ''],
	]],
	['demo purge leaves the seeded pages behind', [
		['includes/class-seeder.php', "\t\t\$deleted_pages = self::delete_demo_pages();\n", "\t\t\$deleted_pages = 0;\n"],
	]],
	['demo purge deletes a recorded ID that is no longer a page', [
		['includes/class-seeder.php', "\$page_id > 0 && 'page' === get_post_type( \$page_id ) && wp_delete_post", "\$page_id > 0 && wp_delete_post"],
	]],
	['seed forgets the workshop page', [
		['includes/class-seeder.php', 'array( $landing_page_id, $workshop_page_id )', 'array( $landing_page_id )'],
	]],
	['demo registration at a real domain', [
		['includes/class-seeder.php', "'email'      => 'erika.mustermann@example.com',", "'email'      => 'erika.mustermann@musterfirma.de',"],
	]],
	['demo event without the price meta the editor saves back', [
		['includes/class-seeder.php', "\t\t\tupdate_post_meta( \$post_id, '_cybevents_ticket_price', 250.00 );\n", ''],
	]],

	// Group K — admission waiting room (2026-09-16).
	["booking route runs before the waiting room", [
		['includes/class-rest-api.php', "\t\t\$admission = CyberEvents_Waiting_Room::admit(\n\t\t\tCyberEvents_Waiting_Room::GATE_BOOK,\n\t\t\t(int) \$request->get_param( 'event_id' )\n\t\t);\n\t\tif ( ! \$admission['admitted'] ) {\n\t\t\treturn CyberEvents_Waiting_Room::response( \$admission );\n\t\t}\n\n", ""],
	]],
	["registration route runs before the waiting room", [
		['includes/class-rest-api.php', "\t\t\$admission = CyberEvents_Waiting_Room::admit(\n\t\t\tCyberEvents_Waiting_Room::GATE_REGISTER,\n\t\t\t(int) \$request->get_param( 'event_id' )\n\t\t);\n\t\tif ( ! \$admission['admitted'] ) {\n\t\t\treturn CyberEvents_Waiting_Room::response( \$admission );\n\t\t}\n\n", ""],
	]],
	["waiting room counts one bucket for every event and gate", [
		['includes/class-waiting-room.php', "return 'wm' . sanitize_key( (string) \$gate ) . '_' . absint( \$event_id ) . '_' . (int) \$window;", "return 'wm_all_' . (int) \$window;"],
	]],
	["waiting room never expires its window", [
		['includes/class-waiting-room.php', "return (int) floor( current_datetime()->getTimestamp() / self::WINDOW_SECONDS );", "return 0;"],
	]],
	["waiting room admits the request that is exactly over the limit", [
		['includes/class-waiting-room.php', "if ( \$count > \$limit ) {", "if ( \$count > \$limit + 1 ) {"],
	]],
	["waiting room counts even when it is switched off", [
		['includes/class-waiting-room.php', "\t\tif ( \$limit <= 0 ) {\n\t\t\t// Off: no counter is written at all, so a site that never switched it on pays nothing.\n\t\t\treturn \$verdict;\n\t\t}\n\n", ""],
	]],
	["waiting room treats the default per-request cache as persistent", [
		['includes/class-waiting-room.php', "if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_incr' ) ) {", "if ( function_exists( 'wp_cache_incr' ) ) {"],
	]],
	["an evicted cache counter reads as an empty window", [
		['includes/class-waiting-room.php', "return false === \$count ? 1 : (int) \$count;", "return (int) \$count;"],
	]],
	["waiting room resets the counter on the first hit of a window", [
		['includes/class-waiting-room.php', "wp_cache_add( \$key, 0, self::CACHE_GROUP, self::WINDOW_SECONDS * 2 );", "wp_cache_set( \$key, 0, self::CACHE_GROUP, self::WINDOW_SECONDS * 2 );"],
	]],
	["a negative stored limit opens the gate instead of closing the waiting room", [
		['includes/class-waiting-room.php', "\t\tif ( \$limit < 0 ) {\n\t\t\treturn 0;\n\t\t}\n\n", ""],
	]],
	["stored limit not capped", [
		['includes/class-waiting-room.php', "return min( \$limit, self::MAX_LIMIT );", "return \$limit;"],
	]],
	["refusal answers without Retry-After", [
		['includes/class-waiting-room.php', "\$response->header( 'Retry-After', (string) \$retry_after );\n\n", ""],
	]],
	["refusal claims the seat is held", [
		['includes/class-waiting-room.php', "Sehr viele Anfragen gleichzeitig. Bitte versuchen Sie es in %d Sekunden erneut. Ein Platz ist damit nicht reserviert.", "Sehr viele Anfragen gleichzeitig. Ihr Platz ist reserviert, bitte versuchen Sie es in %d Sekunden erneut."],
	]],
	["settings screen hides which backend counts", [
		['includes/class-admin.php', "\t\t\t\t\t\t\t\t<?php if ( '' !== \$admission_status['database_backend_warning'] ) : ?>\n\t\t\t\t\t\t\t\t\t<div class=\"notice notice-warning inline\"><p><?php echo esc_html( \$admission_status['database_backend_warning'] ); ?></p></div>\n\t\t\t\t\t\t\t\t<?php endif; ?>\n", ""],
	]],
	["settings save resets the admission limit on every save", [
		['includes/class-admin.php', "'admission_per_minute'     => isset( \$_POST['admission_per_minute'] ) ? min( absint( \$_POST['admission_per_minute'] ), CyberEvents_Waiting_Room::MAX_LIMIT ) : absint( \$previous['admission_per_minute'] ?? 0 ),", "'admission_per_minute'     => absint( \$_POST['admission_per_minute'] ?? 0 ),"],
	]],
	["hub does not report the waiting room", [
		['includes/class-cyber-events-spoke-adapter.php', "\t\t\t'admission_limit'       => CyberEvents_Waiting_Room::limit(),\n\t\t\t'admission_backend'     => CyberEvents_Waiting_Room::backend(),\n", ""],
	]],
	["delete_registration reads a property off an id again", [
		['includes/class-gdpr-manager.php', "\t\t\$reg = self::registration_row( \$reg );\n\t\tif ( ! \$reg ) {\n\t\t\treturn false;\n\t\t}\n\n\t\tself::release_sessions( \$reg );", "\t\tself::release_sessions( \$reg );"],
	]],
	["release_sessions reads a property off an id again", [
		['includes/class-gdpr-manager.php', "\t\t\$reg = self::registration_row( \$reg );\n\t\tif ( ! \$reg ) {\n\t\t\treturn;\n\t\t}\n", ""],
	]],
	["registration_row turns a missing id into a row that is not there", [
		['includes/class-gdpr-manager.php', "\t\t\$id = absint( \$reg );\n\t\tif ( ! \$id ) {\n\t\t\treturn null;\n\t\t}\n\n", ""],
	]],

	// Group L — seat map and its live stream (2026-09-16).
	["seat map digest covers the title, so renaming a session looks like a booking", [
		['includes/class-seat-map.php', "return array( \$s['id'], \$s['capacity'], \$s['taken'] );", "return array( \$s['id'], \$s['title'], \$s['capacity'], \$s['taken'] );"],
	]],
	["seat map draws a cell per seat however large the session", [
		['includes/class-seat-map.php', "if ( \$capacity > self::MAX_CELLS || 0 === \$capacity ) {", "if ( false ) {"],
	]],
	["seat map divides by a capacity of zero", [
		['includes/class-seat-map.php', "\$filled = \$capacity > 0 ? ( self::WIDTH - 24 ) * ( \$taken / \$capacity ) : 0;", "\$filled = ( self::WIDTH - 24 ) * ( \$taken / \$capacity );"],
	]],
	["seat map paints taken and free the wrong way round", [
		['includes/class-seat-map.php', "\$seat < \$taken ? 'is-taken' : 'is-free',", "\$seat >= \$taken ? 'is-taken' : 'is-free',"],
	]],
	["seat map shows an unconfirmed registration its bookings", [
		['includes/class-seat-map.php', "\t\t\$reg = CyberEvents_Database::get_registration_by_token( (string) \$raw_token );\n\t\tif ( ! \$reg || 'confirmed' !== \$reg->status ) {\n\t\t\treturn array();\n\t\t}\n", "\t\t\$reg = CyberEvents_Database::get_registration_by_token( (string) \$raw_token );\n\t\tif ( ! \$reg ) {\n\t\t\treturn array();\n\t\t}\n"],
	]],
	["seat map lets an unconfirmed registration book", [
		['includes/class-seat-map.php', "return (bool) \$reg && 'confirmed' === \$reg->status;", "return (bool) \$reg;"],
	]],
	["seat map writes the participant token into the markup again", [
		['includes/class-seat-map.php', "data-live=\"%s\" data-can-book=\"%s\">',", "data-live=\"%s\" data-token=\"%s\">',"],
		['includes/class-seat-map.php', "\t\t\tarray() !== self::booked_session_ids( \$raw_token ) || ( '' !== (string) \$raw_token && self::may_book( \$raw_token ) ) ? '1' : '0'", "\t\t\tesc_attr( (string) \$raw_token )"],
	]],
	["seat map renders for an event that does not exist", [
		['includes/class-seat-map.php', "\t\t\$event = CyberEvents_Database::get_event( \$event_id );\n\t\tif ( ! \$event ) {\n\t\t\treturn '<p class=\"cybevents-notice cybevents-notice--warning\">' . esc_html__( 'Die Veranstaltung des Sitzplans existiert nicht.', 'wm-cyber-events-core' ) . '</p>';\n\t\t}\n\n", "\t\t\$event = CyberEvents_Database::get_event( \$event_id );\n\n"],
	]],
	["seat map drops the accessible table", [
		['includes/class-seat-map.php', "\t\t\$html .= '<table class=\"cybevents-seatmap-table\"><caption>'", "\t\t\$html .= '<div><span>'"],
	]],
	["seat stream never stops", [
		['includes/class-seat-map.php', "while ( time() - \$started < self::SSE_MAX_SECONDS ) {", "while ( true ) {"],
	]],
	["seat stream lets a proxy buffer it", [
		['includes/class-seat-map.php', "\t\theader( 'X-Accel-Buffering: no' );\n", ""],
	]],
	["seat stream writes on every tick instead of on a change", [
		['includes/class-seat-map.php', "if ( \$snapshot['digest'] !== \$last_digest ) {", "if ( true ) {"],
	]],
	["seat stream without a heartbeat", [
		['includes/class-seat-map.php', "\t\t\t} elseif ( time() - \$last_written >= self::SSE_HEARTBEAT_SECONDS ) {\n\t\t\t\t// A comment line keeps proxies from closing an idle connection; it is not an event.\n\t\t\t\techo \": keep-alive\\n\\n\";\n\t\t\t\t\$last_written = time();\n", "\t\t\t} elseif ( false ) {\n"],
	]],
	["seat stream route without a required event id", [
		['includes/class-rest-api.php', "\t\t\t\t'args'                => array(\n\t\t\t\t\t'event_id' => array(\n\t\t\t\t\t\t'required'          => true,\n\t\t\t\t\t\t'type'              => 'integer',\n\t\t\t\t\t\t'sanitize_callback' => 'absint',\n\t\t\t\t\t),\n\t\t\t\t),\n", ""],
	]],
	["seat map script computes occupancy itself", [
		['public/js/cybevents-seatmap.js', "var taken = i < session.taken;", "var taken = i < session.taken; session.taken++;"],
	]],
	["seat map script reads the token from the markup again", [
		['public/js/cybevents-seatmap.js', "var token = '1' === root.dataset.canBook ? tokenFromUrl() : '';", "var token = root.dataset.token || '';"],
	]],
	["seat map script leaves the map frozen without EventSource", [
		['public/js/cybevents-seatmap.js', "if (window.EventSource) {", "if (true) {"],
	]],
	["seat map block draws the live map inside the editor", [
		['includes/class-blocks-manager.php', "\t\tif ( is_admin() ) {\n\t\t\treturn '<div class=\"cybevents-gutenberg-preview-box\">\n\t\t\t\t<div class=\"cybevents-preview-header\">\n\t\t\t\t\t<span class=\"cybevents-badge-official\">' . esc_html__( 'Vorschau im Editor', 'wm-cyber-events-core' ) . '</span>\n\t\t\t\t\t<h3>' . esc_html__( 'Sitzplan der Veranstaltung', 'wm-cyber-events-core' ) . '</h3>", "\t\tif ( false ) {\n\t\t\treturn '<div class=\"cybevents-gutenberg-preview-box\">\n\t\t\t\t<div class=\"cybevents-preview-header\">\n\t\t\t\t\t<span class=\"cybevents-badge-official\">' . esc_html__( 'Vorschau im Editor', 'wm-cyber-events-core' ) . '</span>\n\t\t\t\t\t<h3>' . esc_html__( 'Sitzplan der Veranstaltung', 'wm-cyber-events-core' ) . '</h3>"],
	]],
	["seat map shortcode removed", [
		['wm-cyber-events-core.php', "add_shortcode( 'cybevents_seat_map', 'cybevents_seat_map_shortcode' );", ""],
	]],

	// Group M — v1.1.0: D-UNINSTALL, 11/0 editorScript blocks, blinded-IP REST rate limiting, ecosystem bridges.
	["uninstall drops tables without opt-in (violating D-UNINSTALL)", [
		['uninstall.php', "if ( ! \$cybevents_delete_data ) {\n\treturn;\n}", "if ( false ) {\n\treturn;\n}"],
	]],
	["seat-map block loses editorScript and disappears from Block Inserter", [
		['blocks/seat-map/block.json', "\t\"editorScript\": \"cybevents-blocks-editor-js\",\n", ""],
	]],
	["public REST permission callback admits requests past the rate limit", [
		['includes/class-rest-api.php', "if ( \$count >= \$limit ) {\n\t\t\treturn new WP_Error(", "if ( false ) {\n\t\t\treturn new WP_Error("],
	]],
	["public REST permission callback stores raw IP in transient key", [
		['includes/class-rest-api.php', "\$transient_key = 'cybevents_pub_' . \$blinded_ip;", "\$transient_key = 'cybevents_pub_' . \$ip;"],
	]],
	["upload-avatar accepts cancelled registration token", [
		['includes/class-rest-api.php', "if ( \$candidate && in_array( (string) \$candidate->status, array( 'pending_doi', 'confirmed' ), true ) ) {", "if ( \$candidate ) {"],
	]],
	["Block Bindings source wm-cybevents/metrics not registered on init", [
		['wm-cyber-events-core.php', "\tcybevents_register_ecosystem_bridges();\n", ""],
	]],

	// Group N — R2-B1 (ETag + 304 cached polling + Page Visibility) & R2-B8 (Multisite + autoload=false).
	["seat snapshot route missing required event_id", [
		['includes/class-rest-api.php', "'/seat-snapshot',\n\t\t\tarray(\n\t\t\t\t'methods'             => 'GET',\n\t\t\t\t'callback'            => array( __CLASS__, 'handle_seat_snapshot' ),\n\t\t\t\t'permission_callback' => array( __CLASS__, 'check_public_events_access' ),\n\t\t\t\t'args'                => array(\n\t\t\t\t\t'event_id' => array(\n\t\t\t\t\t\t'required'          => true,\n\t\t\t\t\t\t'type'              => 'integer',\n\t\t\t\t\t\t'minimum'           => 1,", "'/seat-snapshot',\n\t\t\tarray(\n\t\t\t\t'methods'             => 'GET',\n\t\t\t\t'callback'            => array( __CLASS__, 'handle_seat_snapshot' ),\n\t\t\t\t'permission_callback' => array( __CLASS__, 'check_public_events_access' ),\n\t\t\t\t'args'                => array(\n\t\t\t\t\t'event_id' => array(\n\t\t\t\t\t\t'required'          => false,\n\t\t\t\t\t\t'type'              => 'integer',\n\t\t\t\t\t\t'minimum'           => 1,"],
	]],
	["seat snapshot ignores If-None-Match and always returns 200", [
		['includes/class-rest-api.php', "if ( '' !== \$if_none && ( hash_equals( \$etag, \$if_none ) || hash_equals( 'W/' . \$etag, \$if_none ) ) ) {", "if ( false ) {"],
	]],
	["seat snapshot 304 returns non-empty body instead of null", [
		['includes/class-rest-api.php', "\t\t\treturn new WP_REST_Response(\n\t\t\t\tnull,\n\t\t\t\t304,", "\t\t\treturn new WP_REST_Response(\n\t\t\t\t\$snap,\n\t\t\t\t304,"],
	]],
	["seat snapshot endpoint bypasses cached_snapshot()", [
		['includes/class-rest-api.php', "\$snap    = CyberEvents_Seat_Map::cached_snapshot( (int) \$request->get_param( 'event_id' ) );", "\$snap    = CyberEvents_Seat_Map::snapshot( (int) \$request->get_param( 'event_id' ) );"],
	]],
	["booking does not invalidate seat snapshot cache", [
		['includes/class-rest-api.php', "\t\tif ( ! empty( \$booked ) || ! empty( \$to_cancel ) ) {\n\t\t\tCyberEvents_Seat_Map::invalidate_cache( (int) \$reg->event_id );\n\t\t}\n", ""],
	]],
	["self-cancellation does not invalidate seat snapshot cache", [
		['includes/class-rest-api.php', "\t\tif ( \$reg ) {\n\t\t\tCyberEvents_Seat_Map::invalidate_cache( (int) \$reg->event_id );\n\t\t}\n", ""],
		['includes/class-gdpr-manager.php', "\t\tif ( ! empty( \$session_ids ) && class_exists( 'CyberEvents_Seat_Map' ) ) {\n\t\t\tCyberEvents_Seat_Map::invalidate_cache( (int) \$reg->event_id );\n\t\t}\n", ""],
	]],
	["brevo webhook bounce does not invalidate seat snapshot cache", [
		['includes/class-rest-api.php', "\t\t\tforeach ( array_keys( \$invalidated_events ) as \$eid ) {\n\t\t\t\tCyberEvents_Seat_Map::invalidate_cache( \$eid );\n\t\t\t}\n", ""],
	]],
	["seat map render defaults to sse instead of etag-poll", [
		['includes/class-seat-map.php', "apply_filters( 'cybevents_seatmap_transport', 'etag-poll', \$event_id )", "apply_filters( 'cybevents_seatmap_transport', 'sse', \$event_id )"],
	]],
	["seat map render omits data-snapshot URL", [
		['includes/class-seat-map.php', "\t\t\tesc_attr( rest_url( 'cybevents/v1/seat-snapshot' ) ),", "\t\t\t'',"],
	]],
	["seat map script drops visibilitychange pause", [
		['public/js/cybevents-seatmap.js', "document.addEventListener('visibilitychange',", "document.addEventListener('noop-visibilitychange',"],
	]],
	["seat map script drops If-None-Match header on snapshot poll", [
		['public/js/cybevents-seatmap.js', "\t\t\t\theaders['If-None-Match'] = '\"' + root.dataset.digest + '\"';\n", ""],
	]],
	["seat map script processes 304 instead of ignoring it", [
		['public/js/cybevents-seatmap.js', "if (304 === response.status) {\n\t\t\t\t\t\treturn null;\n\t\t\t\t\t}", "if (false) {\n\t\t\t\t\t\treturn null;\n\t\t\t\t\t}"],
	]],
	["wp_initialize_site hook not registered", [
		['wm-cyber-events-core.php', "add_action( 'wp_initialize_site', 'cybevents_initialize_site', 900 );\n", ""],
	]],
	["wp_initialize_site activates even when plugin is not network-active", [
		['wm-cyber-events-core.php', "if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {\n\t\treturn;\n\t}", "if ( false ) {\n\t\treturn;\n\t}"],
	]],
	["wp_initialize_site forgets restore_current_blog()", [
		['wm-cyber-events-core.php', "\t\trestore_current_blog();\n", ""],
	]],
	["uninstall opt-in option autoloaded on every frontend request", [
		['includes/class-admin.php', "update_option( 'wm_cybevents_delete_data_on_uninstall', isset( \$_POST['wm_cybevents_delete_data_on_uninstall'] ) ? 1 : 0, false );", "update_option( 'wm_cybevents_delete_data_on_uninstall', isset( \$_POST['wm_cybevents_delete_data_on_uninstall'] ) ? 1 : 0 );"],
	]],

	// Group O — 1.2.0 / 1.2.4: Calendar Views icons & dynamic parameterization, editor canvas CSS, print rule.
	["Calendar Views toolbar shows an emoji again", [
		['includes/class-calendar-views.php', "<span class=\"cybevents-btn-icon\" aria-hidden=\"true\"><?php echo wp_kses( CyberEvents_Icons::svg( 'calendar' ), \$svg ); ?></span>", "<span class=\"cybevents-btn-icon\" aria-hidden=\"true\">🗓️</span>"],
	]],
	["Calendar Views ticker separator back to the diamond glyph", [
		['includes/class-calendar-views.php', "<span class=\"cybevents-ticker-sep\"><?php echo wp_kses( CyberEvents_Icons::svg( 'diamond', 8 ), \$svg ); ?></span>", "<span class=\"cybevents-ticker-sep\">◆</span>"],
	]],
	["Calendar Views echoes an icon without wp_kses", [
		['includes/class-calendar-views.php', "<?php echo wp_kses( CyberEvents_Icons::svg( 'search' ), \$svg ); ?>", "<?php echo CyberEvents_Icons::svg( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"],
	]],
	["Calendar Views asks for an icon name that does not exist and renders nothing", [
		['includes/class-calendar-views.php', "CyberEvents_Icons::svg( 'grid' )", "CyberEvents_Icons::svg( 'tracks' )"],
	]],
	["Calendar Views stage back to a second main landmark", [
		['includes/class-calendar-views.php', "<div class=\"cybevents-views-stage\" aria-live=\"polite\">", "<main class=\"cybevents-views-stage\" aria-live=\"polite\">"],
	]],
	["Calendar Views room list heading back to a fixed colour", [
		['includes/class-calendar-views.php', "<h4 class=\"cybevents-venue-subheading\">", "<h4 style=\"color:#00d4ff;font-size:1rem;margin:24px 0 12px 0;\">"],
	]],
	["activation seeds demo data automatically again", [
		['includes/class-activator.php', "\t\tself::ensure_cron_events();\n\t}\n\n\t/**\n\t * Schema migration", "\t\tself::ensure_cron_events();\n\t\tCyberEvents_Seeder::seed_demo_data( false );\n\t}\n\n\t/**\n\t * Schema migration"],
	]],
	["Calendar Views ignores requested month attribute and falls back to fixed 2026-10", [
		['includes/class-calendar-views.php', "\t\t\$target_ym     = self::resolve_target_month( \$attributes, \$events, \$sessions );", "\t\t\$target_ym     = '2026-10';"],
	]],
	["Calendar Views script hardcodes Oktober 2026 in period navigation", [
		['public/js/cybevents-public.js', "\t\t\tlabel.textContent = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(nextDate);", "\t\t\tlabel.textContent = delta > 0 ? 'November 2026' : 'Oktober 2026';"],
	]],
	["editor canvas hook for the frontend stylesheet not registered", [
		['wm-cyber-events-core.php', "add_action( 'enqueue_block_assets', 'cybevents_enqueue_editor_canvas_assets' );\n", ""],
	]],
	["editor canvas stylesheet enqueued on the frontend as well", [
		['wm-cyber-events-core.php', "\tif ( ! is_admin() ) {\n\t\treturn;\n\t}\n", "\tif ( false ) {\n\t\treturn;\n\t}\n"],
	]],
	["editor canvas without the theme preset", [
		['wm-cyber-events-core.php', "\t\twp_add_inline_style( 'cybevents-public', \$dynamic_theme_css );\n\t}\n}\nadd_action( 'enqueue_block_assets'", "\t}\n}\nadd_action( 'enqueue_block_assets'"],
	]],
	["print rule of the ticket pass back to every page", [
		['public/css/cybevents-public.css', "body:where(:has(#cybevents-printable-badge)) * { visibility: hidden; }", "body * { visibility: hidden; }"],
	]],
	["print rule with a bare :has() that outranks the rules showing the pass", [
		['public/css/cybevents-public.css', "body:where(:has(#cybevents-printable-badge)) * { visibility: hidden; }", "body:has(#cybevents-printable-badge) * { visibility: hidden; }"],
	]],
	["month day cells back to 1fr tracks that grow to the longest chip title", [
		['public/css/cybevents-public.css', "\tgrid-template-columns: repeat(7, minmax(0, 1fr));\n\tgap: 1px;", "\tgrid-template-columns: repeat(7, 1fr);\n\tgap: 1px;"],
	]],
	["month weekday header back to 1fr tracks", [
		['public/css/cybevents-public.css', "\tgrid-template-columns: repeat(7, minmax(0, 1fr));\n\tbackground: rgba(30, 41, 59, 0.8);", "\tgrid-template-columns: repeat(7, 1fr);\n\tbackground: rgba(30, 41, 59, 0.8);"],
	]],
	["month day cell can no longer shrink below its chip title", [
		['public/css/cybevents-public.css', "\tbackground: var(--cyb-surface-900);\n\tmin-width: 0;\n", "\tbackground: var(--cyb-surface-900);\n"],
	]],
];
