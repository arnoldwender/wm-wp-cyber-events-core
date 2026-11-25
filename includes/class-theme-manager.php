<?php
/**
 * Theme & Design System Manager (Enterprise Template & Styling Engine).
 *
 * Implements:
 * 1. Default WordPress Theme Inheritance (Fonts, Colors, Button Radius, Form Inputs).
 * 2. Preset switcher (theme default, several colour presets, high contrast, custom).
 * 3. Custom Theme Upload & CSS Customizer Engine (MIME-checked, sanitized CSS upload).
 * 4. Template Hierarchy Overrides (theme/cybevents/single-cybevent.php & archive-cybevent.php).
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Theme_Manager {

	/**
	 * Available Theme Presets.
	 *
	 * @return array
	 */
	public static function get_theme_presets() {
		return array(
			'theme_default'    => array(
				'name'        => __( 'WordPress Theme-Standard (Automatisch vererbt)', 'wm-cyber-events-core' ),
				'badge'       => __( 'Empfohlen', 'wm-cyber-events-core' ),
				'description' => __( 'Übernimmt Typografie, Farben, Buttons und Abstände 1:1 vom aktiven WordPress-Theme (GeneratePress, Astra, Twenty Twenty-Four etc.).', 'wm-cyber-events-core' ),
				'preview'     => array(
					'primary'    => 'var(--wp--preset--color--primary, #2563eb)',
					'background' => 'var(--wp--preset--color--base, #ffffff)',
					'card'       => 'var(--wp--preset--color--base-2, #f8fafc)',
					'text'       => 'var(--wp--preset--color--contrast, #0f172a)',
				),
			),
			'violet_mesh'      => array(
				'name'        => __( 'Violett & Rosé (Dunkellila, Violett, Rosé-Akzent)', 'wm-cyber-events-core' ),
				'badge'       => __( 'Farbstark', 'wm-cyber-events-core' ),
				'description' => __( 'Dunkellila (#482683), leuchtendes Violett (#7D40E7), Rosé-Akzent (#DF0079), Noto Sans, sofern das Theme sie lädt, und ein dezenter Farbverlauf.', 'wm-cyber-events-core' ),
				'preview'     => array(
					'primary'    => '#482683',
					'accent'     => '#7D40E7',
					'background' => '#F3F5FA',
					'card'       => '#ffffff',
					'text'       => '#38454A',
				),
			),
			'enterprise_dark'  => array(
				'name'        => __( 'Enterprise Dark (Navy & Cyan)', 'wm-cyber-events-core' ),
				'badge'       => __( 'High-Tech Dark', 'wm-cyber-events-core' ),
				'description' => __( 'Bespoke Dark & Cobalt High-Tech Look mit Glow-Effekten, abgestimmt auf Cyber-Sicherheit & Konferenzen.', 'wm-cyber-events-core' ),
				'preview'     => array(
					'primary'    => '#0052cc',
					'accent'     => '#00b4d8',
					'background' => '#060d1a',
					'card'       => '#0b1528',
					'text'       => '#f8fafc',
				),
			),
			'clean_minimal'    => array(
				'name'        => __( 'Minimalist Modern (Slate & Monochrome)', 'wm-cyber-events-core' ),
				'badge'       => __( 'Modern', 'wm-cyber-events-core' ),
				'description' => __( 'Klares, schlichtes SaaS-Design mit reduzierten Graustufen, feinen Rändern und maximalem Weißraum.', 'wm-cyber-events-core' ),
				'preview'     => array(
					'primary'    => '#0f172a',
					'accent'     => '#3b82f6',
					'background' => '#ffffff',
					'card'       => '#f8fafc',
					'text'       => '#0f172a',
				),
			),
			'editorial_warm'   => array(
				'name'        => __( 'Editorial Warm (Klassisch & Warmton)', 'wm-cyber-events-core' ),
				'badge'       => __( 'Klassik', 'wm-cyber-events-core' ),
				'description' => __( 'Warme Elfenbein- und Burgunder-Töne für akademische Symposien und gehobene Fachkongresse.', 'wm-cyber-events-core' ),
				'preview'     => array(
					'primary'    => '#881337',
					'accent'     => '#d97706',
					'background' => '#fdfbf7',
					'card'       => '#ffffff',
					'text'       => '#1c1917',
				),
			),
			'high_contrast'    => array(
				'name'        => __( 'Hochkontrast (Schwarz auf Weiß)', 'wm-cyber-events-core' ),
				'badge'       => __( 'Hochkontrast', 'wm-cyber-events-core' ),
				'description' => __( 'Schwarzer Text auf Weiß (Kontrast 21:1) mit gelbem Akzent und verstärkten Fokus-Rahmen.', 'wm-cyber-events-core' ),
				'preview'     => array(
					'primary'    => '#000000',
					'accent'     => '#ffd000',
					'background' => '#ffffff',
					'card'       => '#ffffff',
					'text'       => '#000000',
				),
			),
			'custom'           => array(
				'name'        => __( 'Benutzerdefiniertes Theme (Eigene Farben & CSS)', 'wm-cyber-events-core' ),
				'badge'       => __( 'Individuell', 'wm-cyber-events-core' ),
				'description' => __( 'Freie Farbdefinition und Unterstützung für hochgeladene Custom-CSS-Stylesheets.', 'wm-cyber-events-core' ),
				'preview'     => array(
					'primary'    => '#6366f1',
					'accent'     => '#10b981',
					'background' => '#f8fafc',
					'card'       => '#ffffff',
					'text'       => '#0f172a',
				),
			),
		);
	}

	/**
	 * Get Current Active Theme Preset Name.
	 *
	 * @return string
	 */
	public static function get_active_preset() {
		$settings = get_option( 'cybevents_settings', array() );
		return $settings['theme_preset'] ?? 'theme_default';
	}

	/**
	 * Generate Dynamic Inline CSS Variables for the Active Preset.
	 *
	 * @return string CSS string.
	 */
	public static function get_generated_css() {
		$settings = get_option( 'cybevents_settings', array() );
		$preset   = $settings['theme_preset'] ?? 'theme_default';

		$css = '';

		switch ( $preset ) {
			case 'violet_mesh':
				$css .= "
:root, [data-cybevents-theme] {
	--cyb-font-sans: 'Noto Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
	--cyb-primary: #482683;
	--cyb-primary-hover: #371c66;
	--cyb-primary-active: #2b1452;
	--cyb-primary-subtle: #e8ecf7;
	--cyb-purple-deep: #482683;
	--cyb-purple-bright: #7D40E7;
	--cyb-purple-vivid: #A26DFF;
	--cyb-purple-pastel: #C8D1EB;
	--cyb-purple-soft: #8292C9;
	--cyb-purple-misty: #F3F5FA;
	--cyb-rose-accent: #DF0079;
	--cyb-gray-navy: #38454A;
	--cyb-navy-950: #1b0e33;
	--cyb-navy-900: #29154d;
	--cyb-navy-800: #3c2070;
	--cyb-cyan-500: #7D40E7;
	--cyb-cyan-600: #603cba;
	--cyb-bg-page: #F3F5FA;
	--cyb-bg-card: #FFFFFF;
	--cyb-bg-subtle: #E8ECF7;
	--cyb-text-main: #38454A;
	--cyb-text-muted: #5c5678;
	--cyb-text-light: #8292C9;
	--cyb-border: #D9DEEF;
	--cyb-border-focus: #7D40E7;
	--cyb-radius-sm: 6px;
	--cyb-radius-md: 10px;
	--cyb-radius-lg: 16px;
	--cyb-shadow-glow: 0 4px 20px rgba(125, 64, 231, 0.18);
	--cyb-gradient-brand: linear-gradient(135deg, #482683 0%, #7D40E7 60%, #DF0079 100%);
}";
				break;

			case 'enterprise_dark':
				$css .= "
:root, [data-cybevents-theme] {
	--cyb-font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
	--cyb-primary: #0052cc;
	--cyb-primary-hover: #003d99;
	--cyb-primary-active: #002b66;
	--cyb-primary-subtle: rgba(0, 82, 204, 0.15);
	--cyb-navy-950: #060d1a;
	--cyb-navy-900: #0b1528;
	--cyb-navy-800: #13233f;
	--cyb-navy-700: #1e3a63;
	--cyb-cyan-500: #00b4d8;
	--cyb-cyan-600: #0096c7;
	--cyb-bg-page: #060d1a;
	--cyb-bg-card: #0b1528;
	--cyb-bg-subtle: #13233f;
	--cyb-text-main: #f8fafc;
	--cyb-text-muted: #94a3b8;
	--cyb-text-light: #64748b;
	--cyb-border: rgba(255, 255, 255, 0.12);
	--cyb-border-focus: #00b4d8;
	--cyb-radius-sm: 8px;
	--cyb-radius-md: 12px;
	--cyb-radius-lg: 20px;
	--cyb-shadow-glow: 0 0 20px rgba(0, 180, 216, 0.35);
}";
				break;

			case 'clean_minimal':
				$css .= "
:root, [data-cybevents-theme] {
	--cyb-font-sans: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
	--cyb-primary: #0f172a;
	--cyb-primary-hover: #334155;
	--cyb-primary-active: #020617;
	--cyb-primary-subtle: #f1f5f9;
	--cyb-navy-950: #0f172a;
	--cyb-navy-900: #1e293b;
	--cyb-navy-800: #334155;
	--cyb-cyan-500: #2563eb;
	--cyb-bg-page: #ffffff;
	--cyb-bg-card: #ffffff;
	--cyb-bg-subtle: #f8fafc;
	--cyb-text-main: #0f172a;
	--cyb-text-muted: #64748b;
	--cyb-text-light: #94a3b8;
	--cyb-border: #e2e8f0;
	--cyb-border-focus: #0f172a;
	--cyb-radius-sm: 6px;
	--cyb-radius-md: 10px;
	--cyb-radius-lg: 14px;
	--cyb-shadow-glow: none;
}";
				break;

			case 'editorial_warm':
				$css .= "
:root, [data-cybevents-theme] {
	--cyb-font-sans: 'Playfair Display', Georgia, Cambria, 'Times New Roman', serif;
	--cyb-primary: #881337;
	--cyb-primary-hover: #4c0519;
	--cyb-primary-active: #2e020e;
	--cyb-primary-subtle: #ffe4e6;
	--cyb-navy-950: #1c1917;
	--cyb-navy-900: #292524;
	--cyb-navy-800: #44403c;
	--cyb-cyan-500: #d97706;
	--cyb-bg-page: #fdfbf7;
	--cyb-bg-card: #ffffff;
	--cyb-bg-subtle: #f5f0e8;
	--cyb-text-main: #1c1917;
	--cyb-text-muted: #57534e;
	--cyb-text-light: #78716c;
	--cyb-border: #e7e0d5;
	--cyb-border-focus: #881337;
	--cyb-radius-sm: 4px;
	--cyb-radius-md: 8px;
	--cyb-radius-lg: 12px;
	--cyb-shadow-glow: none;
}";
				break;

			case 'high_contrast':
				$css .= "
:root, [data-cybevents-theme] {
	--cyb-font-sans: system-ui, -apple-system, sans-serif;
	--cyb-primary: #000000;
	--cyb-primary-hover: #1f2937;
	--cyb-primary-active: #000000;
	--cyb-primary-subtle: #fef08a;
	--cyb-navy-950: #000000;
	--cyb-navy-900: #000000;
	--cyb-navy-800: #111827;
	--cyb-cyan-500: #ca8a04;
	--cyb-bg-page: #ffffff;
	--cyb-bg-card: #ffffff;
	--cyb-bg-subtle: #f3f4f6;
	--cyb-text-main: #000000;
	--cyb-text-muted: #1f2937;
	--cyb-text-light: #374151;
	--cyb-border: #000000;
	--cyb-border-focus: #000000;
	--cyb-radius-sm: 2px;
	--cyb-radius-md: 4px;
	--cyb-radius-lg: 6px;
	--cyb-shadow-glow: none;
}
.cybevents-input, .cybevents-btn, .cybevents-form {
	border-width: 2px !important;
}";
				break;

			case 'custom':
				$cust_primary = sanitize_hex_color( $settings['theme_custom_primary'] ?? '#0052cc' );
				$cust_accent  = sanitize_hex_color( $settings['theme_custom_accent'] ?? '#00b4d8' );
				$cust_bg      = sanitize_hex_color( $settings['theme_custom_bg'] ?? '#f8fafc' );
				$cust_card    = sanitize_hex_color( $settings['theme_custom_card'] ?? '#ffffff' );
				$cust_text    = sanitize_hex_color( $settings['theme_custom_text'] ?? '#0f172a' );
				$cust_radius  = absint( $settings['theme_custom_radius'] ?? 10 );

				$css .= "
:root, [data-cybevents-theme] {
	--cyb-primary: {$cust_primary};
	--cyb-cyan-500: {$cust_accent};
	--cyb-bg-page: {$cust_bg};
	--cyb-bg-card: {$cust_card};
	--cyb-text-main: {$cust_text};
	--cyb-border-focus: {$cust_primary};
	--cyb-radius-sm: " . max( 2, (int) ( $cust_radius * 0.6 ) ) . "px;
	--cyb-radius-md: {$cust_radius}px;
	--cyb-radius-lg: " . ( $cust_radius * 1.6 ) . "px;
}";
				break;

			case 'theme_default':
			default:
				// Dynamic Theme Inheritance: Automatically inherit fonts, colors, and button radii from the active theme
				$css .= "
:root, [data-cybevents-theme] {
	--cyb-font-sans: var(--wp--preset--font-family--body, var(--font-family-body, inherit));
	--cyb-primary: var(--wp--preset--color--primary, var(--accent, var(--global-color-1, #0052cc)));
	--cyb-primary-hover: var(--wp--preset--color--secondary, var(--accent-hover, #003d99));
	--cyb-primary-active: var(--wp--preset--color--primary, #002b66);
	--cyb-primary-subtle: var(--wp--preset--color--subtle, #e8f0fe);
	--cyb-navy-950: var(--wp--preset--color--contrast, #060d1a);
	--cyb-navy-900: var(--wp--preset--color--contrast-2, #0b1528);
	--cyb-cyan-500: var(--wp--preset--color--accent, #00b4d8);
	--cyb-bg-page: var(--wp--preset--color--base, var(--base, inherit));
	--cyb-bg-card: var(--wp--preset--color--base-2, var(--card-bg, #ffffff));
	--cyb-bg-subtle: var(--wp--preset--color--base-3, var(--bg-subtle, #f1f5f9));
	--cyb-text-main: var(--wp--preset--color--contrast, var(--text-color, inherit));
	--cyb-text-muted: var(--wp--preset--color--contrast-2, var(--text-muted, #475569));
	--cyb-border: var(--wp--preset--color--border, var(--border-color, #e2e8f0));
	--cyb-border-focus: var(--cyb-primary);
	--cyb-radius-sm: var(--wp--custom--radius--sm, 8px);
	--cyb-radius-md: var(--wp--custom--radius--md, 12px);
	--cyb-radius-lg: var(--wp--custom--radius--lg, 18px);
}";
				break;
		}

		// Append custom CSS rules if provided
		if ( ! empty( $settings['theme_custom_css'] ) ) {
			$css .= "\n/* Custom User Overrides */\n" . wp_strip_all_tags( $settings['theme_custom_css'] );
		}

		// Append uploaded custom theme CSS file if exists
		$upload_css = self::get_uploaded_theme_css();
		if ( ! empty( $upload_css ) ) {
			$css .= "\n/* Uploaded Theme Stylesheet */\n" . $upload_css;
		}

		return $css;
	}

	/**
	 * Get Content of Uploaded Theme CSS File.
	 *
	 * @return string
	 */
	public static function get_uploaded_theme_css() {
		$upload_dir = wp_upload_dir();
		$theme_file = trailingslashit( $upload_dir['basedir'] ) . 'cybevents-themes/custom-theme.css';

		if ( file_exists( $theme_file ) && is_readable( $theme_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFileSystemReading.file_get_contents_file_get_contents
			return file_get_contents( $theme_file );
		}

		return '';
	}

	/**
	 * Handle Theme CSS File Upload.
	 *
	 * @param array $file $_FILES item.
	 * @return array Status report.
	 */
	public static function handle_theme_upload( $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Keine gültige Upload-Datei übergeben.', 'wm-cyber-events-core' ),
			);
		}

		$filename = sanitize_file_name( $file['name'] ?? '' );
		$ext      = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( 'css' !== $ext ) {
			return array(
				'success' => false,
				'message' => __( 'Sicherheitsfehler: Es dürfen ausschließlich reine .css-Dateien hochgeladen werden.', 'wm-cyber-events-core' ),
			);
		}

		if ( $file['size'] > 2 * 1024 * 1024 ) {
			return array(
				'success' => false,
				'message' => __( 'Die CSS-Datei überschreitet das Limit von 2 MB.', 'wm-cyber-events-core' ),
			);
		}

		// Read and sanitize CSS content (strip php/script/dangerous HTML wrappers)
		// phpcs:ignore WordPress.WP.AlternativeFileSystemReading.file_get_contents_file_get_contents
		$raw_css = file_get_contents( $file['tmp_name'] );
		$clean_css = wp_strip_all_tags( $raw_css );

		// Prepare uploads directory
		$upload_dir = wp_upload_dir();
		$target_dir = trailingslashit( $upload_dir['basedir'] ) . 'cybevents-themes/';

		if ( ! file_exists( $target_dir ) ) {
			wp_mkdir_p( $target_dir );
			// Write index.php and .htaccess for security
			file_put_contents( $target_dir . 'index.php', '<?php // Silence is golden.' );
			file_put_contents( $target_dir . '.htaccess', "SetHandler default-handler\n<Files *.php>\nDeny from all\n</Files>" );
		}

		$target_file = $target_dir . 'custom-theme.css';
		// phpcs:ignore WordPress.WP.AlternativeFileSystemReading.file_put_contents_file_put_contents
		$saved = file_put_contents( $target_file, $clean_css );

		if ( false === $saved ) {
			return array(
				'success' => false,
				'message' => __( 'Konnte die Theme-Datei nicht auf dem Server speichern.', 'wm-cyber-events-core' ),
			);
		}

		CyberEvents_Database::log_audit(
			'theme_uploaded',
			0,
			get_current_user_id(),
			sprintf( 'Benutzerdefiniertes CSS-Theme hochgeladen (%s, %d Bytes).', $filename, strlen( $clean_css ) )
		);

		return array(
			'success' => true,
			'message' => __( 'Custom-Theme CSS erfolgreich hochgeladen und aktiviert.', 'wm-cyber-events-core' ),
			'url'     => trailingslashit( $upload_dir['baseurl'] ) . 'cybevents-themes/custom-theme.css',
		);
	}

	/**
	 * Delete Uploaded Theme CSS File.
	 *
	 * @return bool
	 */
	public static function delete_uploaded_theme() {
		$upload_dir  = wp_upload_dir();
		$target_file = trailingslashit( $upload_dir['basedir'] ) . 'cybevents-themes/custom-theme.css';

		if ( file_exists( $target_file ) ) {
			CyberEvents_Database::log_audit(
				'theme_deleted',
				0,
				get_current_user_id(),
				'Benutzerdefiniertes CSS-Theme gelöscht.'
			);
			wp_delete_file( $target_file );
			return ! file_exists( $target_file );
		}

		return false;
	}

	/**
	 * Template Hierarchy Loader.
	 * Checks if the active theme contains cybevents templates, allowing developers/users to override layouts.
	 *
	 * @param string $template Current template file.
	 * @return string Selected template.
	 */
	public static function template_loader( $template ) {
		if ( is_singular( 'cybevent' ) ) {
			$theme_file = locate_template( array( 'cybevents/single-cybevent.php', 'single-cybevent.php' ) );
			if ( $theme_file ) {
				return $theme_file;
			}
		} elseif ( is_post_type_archive( 'cybevent' ) ) {
			$theme_file = locate_template( array( 'cybevents/archive-cybevent.php', 'archive-cybevent.php' ) );
			if ( $theme_file ) {
				return $theme_file;
			}
		} elseif ( is_singular( 'cybsession' ) ) {
			$theme_file = locate_template( array( 'cybevents/single-cybsession.php', 'single-cybsession.php' ) );
			if ( $theme_file ) {
				return $theme_file;
			}
		}

		return $template;
	}
}
