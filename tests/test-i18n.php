<?php
/**
 * Test Suite: WM Cyber Events Core — Internationalization (i18n) & EU Language Packs
 *
 * Verifies that all 24 Official EU language packages (.po & .mo) + English US
 * are present, valid gettext binary files, and load properly in WordPress.
 *
 * @package WM_Cyber_Events_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( __DIR__, 4 ) . '/wp-load.php';
}

echo "=== Running WM Cyber Events Core: i18n & EU Language Packs Test Suite ===\n\n";

$languages_dir = CYBEREVENTS_PLUGIN_DIR . 'languages/';
$pot_file      = $languages_dir . 'wm-cyber-events-core.pot';

$eu_locales = [
	'bg_BG', 'cs_CZ', 'da_DK', 'de_DE', 'el', 'en_GB', 'en_US', 'es_ES',
	'et', 'fi', 'fr_FR', 'ga', 'hr_HR', 'hu_HU', 'it_IT', 'lt_LT',
	'lv', 'mt_MT', 'nl_NL', 'pl_PL', 'pt_PT', 'ro_RO', 'sk_SK', 'sl_SI', 'sv_SE'
];

$GLOBALS['test_passed'] = 0;
$GLOBALS['test_total']  = 0;

function assert_test( bool $condition, string $description ): void {
	$GLOBALS['test_total']++;
	if ( $condition ) {
		$GLOBALS['test_passed']++;
		echo " [PASS] $description\n";
	} else {
		echo " [FAIL] $description\n";
	}
}

// 1. Verify POT Template
assert_test( file_exists( $pot_file ) && filesize( $pot_file ) > 1000, 'POT template file exists and contains valid template strings' );

// 2. Verify All 25 Locale Files (.po and .mo)
$missing_po = [];
$missing_mo = [];
$invalid_mo = [];

foreach ( $eu_locales as $locale ) {
	$po_file = $languages_dir . "wm-cyber-events-core-{$locale}.po";
	$mo_file = $languages_dir . "wm-cyber-events-core-{$locale}.mo";

	if ( ! file_exists( $po_file ) || filesize( $po_file ) < 100 ) {
		$missing_po[] = $locale;
	}

	if ( ! file_exists( $mo_file ) || filesize( $mo_file ) < 100 ) {
		$missing_mo[] = $locale;
	} else {
		// Check gettext binary magic number
		$handle = fopen( $mo_file, 'rb' );
		$magic  = bin2hex( fread( $handle, 4 ) );
		fclose( $handle );
		if ( $magic !== '950412de' && $magic !== 'de120495' ) {
			$invalid_mo[] = "{$locale} (magic: {$magic})";
		}
	}
}

assert_test( empty( $missing_po ), 'All 25 EU locale .po translation sources are present' );
assert_test( empty( $missing_mo ), 'All 25 EU locale .mo compiled binary files are present' );
assert_test( empty( $invalid_mo ), 'All .mo files possess valid GNU gettext binary headers' );

// 3. Test Runtime Translation Resolution
unload_textdomain( 'wm-cyber-events-core' );
$loaded_es = load_textdomain( 'wm-cyber-events-core', $languages_dir . 'wm-cyber-events-core-es_ES.mo' );
$trans_es  = __( 'Einstellungen', 'wm-cyber-events-core' );
assert_test( $loaded_es && $trans_es === 'Ajustes', "Spanish locale loads and translates 'Einstellungen' to '{$trans_es}'" );

unload_textdomain( 'wm-cyber-events-core' );
$loaded_fr = load_textdomain( 'wm-cyber-events-core', $languages_dir . 'wm-cyber-events-core-fr_FR.mo' );
$trans_fr  = __( 'Einstellungen', 'wm-cyber-events-core' );
assert_test( $loaded_fr && $trans_fr === 'Réglages', "French locale loads and translates 'Einstellungen' to '{$trans_fr}'" );

unload_textdomain( 'wm-cyber-events-core' );
$loaded_it = load_textdomain( 'wm-cyber-events-core', $languages_dir . 'wm-cyber-events-core-it_IT.mo' );
$trans_it  = __( 'Einstellungen', 'wm-cyber-events-core' );
assert_test( $loaded_it && $trans_it === 'Impostazioni', "Italian locale loads and translates 'Einstellungen' to '{$trans_it}'" );

unload_textdomain( 'wm-cyber-events-core' );
$loaded_de = load_textdomain( 'wm-cyber-events-core', $languages_dir . 'wm-cyber-events-core-de_DE.mo' );
$trans_de  = __( 'Einstellungen', 'wm-cyber-events-core' );
assert_test( $loaded_de && $trans_de === 'Einstellungen', "German locale retains verbatim German copy '{$trans_de}'" );

echo "\n--- Summary ---\n";
echo "Tests Passed: {$GLOBALS['test_passed']} / {$GLOBALS['test_total']}\n";

if ( $GLOBALS['test_passed'] === $GLOBALS['test_total'] && $GLOBALS['test_total'] > 0 ) {
	echo "\nAll i18n & EU Language Pack tests passed successfully!\n";
	exit( 0 );
} else {
	echo "\nSome tests failed.\n";
	exit( 1 );
}
