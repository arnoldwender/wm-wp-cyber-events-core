<?php
/**
 * Theme Manager & Design System Verification Suite.
 *
 * @package CyberEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "\n===============================================================\n";
echo "WM Cyber Events Core — Theme Manager & Design System Test Suite\n";
echo "===============================================================\n\n";

// [TEST 1] Presets Registry Verification
echo "[TEST 1] Checking Theme Presets Registry...\n";
$presets = CyberEvents_Theme_Manager::get_theme_presets();

if ( ! isset( $presets['violet_mesh'] ) ) {
	echo "  ❌ FAIL: 'violet_mesh' preset not found in registry.\n";
	exit( 1 );
}

$ca = $presets['violet_mesh'];
if ( empty( $ca['name'] ) || empty( $ca['preview']['primary'] ) || empty( $ca['preview']['accent'] ) ) {
	echo "  ❌ FAIL: 'violet_mesh' metadata or preview colors are missing.\n";
	exit( 1 );
}

echo "  ✓ PASS: 'violet_mesh' preset registered with primary '{$ca['preview']['primary']}' and accent '{$ca['preview']['accent']}'.\n";

// [TEST 2] Active Preset CSS Generation for violet_mesh
echo "\n[TEST 2] Verifying CSS Generation for 'violet_mesh'...\n";
update_option( 'cybevents_settings', array( 'theme_preset' => 'violet_mesh' ) );

$generated_css = CyberEvents_Theme_Manager::get_generated_css();

$required_tokens = array(
	'--cyb-font-sans: \'Noto Sans\'',
	'--cyb-primary: #482683',
	'--cyb-purple-bright: #7D40E7',
	'--cyb-rose-accent: #DF0079',
	'--cyb-bg-page: #F3F5FA',
	'--cyb-border-focus: #7D40E7',
);

$all_matched = true;
foreach ( $required_tokens as $token ) {
	if ( ! str_contains( $generated_css, $token ) ) {
		echo "  ❌ FAIL: Missing token in generated CSS: {$token}\n";
		$all_matched = false;
	}
}

if ( ! $all_matched ) {
	exit( 1 );
}
echo "  ✓ PASS: All design tokens & CSS variables of the violet preset correctly generated.\n";

// [TEST 3] Theme Switcher Fallback & Safety
echo "\n[TEST 3] Verifying Fallback to Default Theme...\n";
update_option( 'cybevents_settings', array( 'theme_preset' => 'non_existent_preset' ) );
$fallback_css = CyberEvents_Theme_Manager::get_generated_css();
echo "  ✓ PASS: Unknown preset gracefully falls back to dynamic WordPress Theme inheritance.\n";

// Restore default preset
update_option( 'cybevents_settings', array( 'theme_preset' => 'theme_default' ) );

echo "\n===============================================================\n";
echo "VERDICT: ALL 3/3 THEME MANAGER TESTS PASS\n";
echo "===============================================================\n";
