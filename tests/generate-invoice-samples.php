<?php
/**
 * Writes the CII XML the plugin produces for an invoice and its credit note into a directory, using
 * the standalone harness (no WordPress). Used by tests/validate-invoice-xml.sh.
 *
 * Usage: php tests/generate-invoice-samples.php <output-dir>
 *
 * The amounts are the rounding case of the 2026-09-15 audit (net 45.50 at 19 %) plus a second rate,
 * so a validator sees BR-CO-15 and BR-S-08 on amounts that do not round evenly.
 *
 * @package CyberEvents
 */

require_once __DIR__ . '/bootstrap-harness.php';

$out = rtrim((string) ($argv[1] ?? ''), '/');
if ($out === '' || !is_dir($out)) {
    fwrite(STDERR, "usage: php tests/generate-invoice-samples.php <existing-output-dir>\n");
    exit(2);
}

update_option('cybevents_settings', [
    'invoice_prefix'    => 'CE-2026-',
    'invoice_counter'   => 1000,
    'seller_name'       => 'Beispiel Veranstaltungen GmbH',
    'seller_street'     => 'Hauptstraße 5',
    'seller_postcode'   => '10115',
    'seller_city'       => 'Berlin',
    'seller_country'    => 'DE',
    'seller_vat_id'     => 'DE123456789',
    'seller_tax_number' => '',
    'seller_email'      => 'rechnung@example.org',
    'seller_iban'       => 'DE89370400440532013000',
]);

$wpdb->insert('wp_cybevents_events', ['title' => 'Sommerforum', 'slug' => 'sommerforum', 'start_date' => '2026-10-15 09:00:00', 'end_date' => '2026-10-15 18:00:00', 'location' => 'Saal 1', 'status' => 'active']);
$event_id = $wpdb->insert_id;

$cases = [
    'invoice-19'  => ['net_amount' => 45.50, 'tax_rate' => 19, 'recipient_company' => 'Muster & Söhne AG', 'recipient_address' => "Musterweg 3\n04109 Leipzig"],
    'invoice-7'   => ['net_amount' => 33.33, 'tax_rate' => 7, 'recipient_company' => '', 'recipient_address' => ''],
];

$written = 0;
foreach ($cases as $name => $case) {
    $id = CyberEvents_Invoicing::create_invoice($case + ['event_id' => $event_id, 'recipient_name' => 'Erika Muster', 'recipient_email' => 'erika@example.org']);
    if (is_wp_error($id)) {
        fwrite(STDERR, "{$name}: " . $id->get_error_message() . "\n");
        exit(1);
    }
    file_put_contents("{$out}/{$name}.xml", $mock_db_tables['wp_cybevents_invoices'][$id]->zugferd_xml);
    $credit = CyberEvents_Invoicing::cancel_invoice($id, 'Absage');
    if (is_wp_error($credit)) {
        fwrite(STDERR, "{$name} credit note: " . $credit->get_error_message() . "\n");
        exit(1);
    }
    file_put_contents("{$out}/{$name}-credit-note.xml", $mock_db_tables['wp_cybevents_invoices'][$credit]->zugferd_xml);
    $written += 2;
}

echo "{$written} XML files written to {$out}\n";
