<?php
/**
 * Plugin Name:       WM Cyber Events Core
 * Plugin URI:        https://arnoldwender.com/wordpress-plugins/wm-cyber-events-core/
 * Description:       Veranstaltungen, Sessions und Anmeldungen mit Double Opt-in über Brevo, Check-in, Rechnungs-XML (EN 16931) und DATEV-Export.
 * Version:           1.2.3
 * Requires at least: 6.9
 * Tested up to:      7.1
 * Requires PHP:      8.3
 * Author:            Arnold Wender
 * Author URI:        https://arnoldwender.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wm-cyber-events-core
 * Domain Path:       /languages
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'CYBEREVENTS_VERSION' ) ) {
	define( 'CYBEREVENTS_VERSION', '1.2.3' );
}
if ( ! defined( 'CYBEREVENTS_PLUGIN_DIR' ) ) {
	define( 'CYBEREVENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'CYBEREVENTS_PLUGIN_URL' ) ) {
	define( 'CYBEREVENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Autoload includes and core components.
 */
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-activator.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-database.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-brevo-api.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-queue-runner.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-invoicing.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-datev-exporter.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-csv-importer.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-icons.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-accessibility.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-events-manager.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-venues-manager.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-sessions-manager.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-calendar-exporter.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-gdpr-manager.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-seeder.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-theme-manager.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-calendar-views.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-blocks-manager.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-waiting-room.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-seat-map.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-admin.php';
require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-cli.php';

/**
 * Register activation and deactivation hooks.
 */
register_activation_hook( __FILE__, array( 'CyberEvents_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CyberEvents_Deactivator', 'deactivate' ) );

/**
 * Initialize plugin tables and defaults when a new Multisite blog is created.
 *
 * @param WP_Site|int $new_site Newly initialized site object or blog ID.
 * @return void
 */
function cybevents_initialize_site( $new_site ): void {
	if ( ! is_multisite() ) {
		return;
	}

	if ( ! function_exists( 'is_plugin_active_for_network' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}

	$blog_id = is_object( $new_site ) && isset( $new_site->blog_id ) ? (int) $new_site->blog_id : absint( $new_site );
	if ( $blog_id <= 0 ) {
		return;
	}

	switch_to_blog( $blog_id );
	try {
		CyberEvents_Activator::activate();
	} finally {
		restore_current_blog();
	}
}
add_action( 'wp_initialize_site', 'cybevents_initialize_site', 900 );

// The queue runs every minute. Until 2026-09-15 nothing registered the 'every_minute' recurrence,
// WordPress refused the event, and no confirmation mail was ever sent. Registered at file load so
// the activation hook finds it too.
add_filter( 'cron_schedules', array( 'CyberEvents_Activator', 'register_cron_schedules' ) );

// Register as Spoke in WM Suite Hub
add_filter( 'wm_register_suite_module', function ( array $modules ): array {
	if ( ! class_exists( 'CyberEvents_Spoke_Adapter' ) && file_exists( CYBEREVENTS_PLUGIN_DIR . 'includes/class-cyber-events-spoke-adapter.php' ) ) {
		require_once CYBEREVENTS_PLUGIN_DIR . 'includes/class-cyber-events-spoke-adapter.php';
	}
	if ( class_exists( 'CyberEvents_Spoke_Adapter' ) ) {
		$modules[] = new CyberEvents_Spoke_Adapter();
	}
	return $modules;
} );

/**
 * Initialize Plugin Lifecycle.
 */
function cybevents_init() {
	// The audit chain added columns to wp_cybevents_audit_log. activate() only runs on
	// activation, so an already-active install would keep the old schema and every logged event
	// would fail on a missing entry_hash column.
	if ( version_compare( (string) get_option( 'cybevents_db_version', '0' ), CyberEvents_Activator::DB_VERSION, '<' ) ) {
		CyberEvents_Activator::migrate();
		update_option( 'cybevents_db_version', CyberEvents_Activator::DB_VERSION );
	}

	// Internationalization
	load_plugin_textdomain( 'wm-cyber-events-core', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// CPT & Taxonomy Registrations
	CyberEvents_Events_Manager::register_cpt();
	CyberEvents_Venues_Manager::register_taxonomy();
	CyberEvents_Sessions_Manager::register_cpt();

	// Gutenberg Native Blocks & Multi-View Calendar Suite
	CyberEvents_Calendar_Views::init();
	CyberEvents_Blocks_Manager::init();

	// GDPR (DSGVO Art. 17) Privacy Tools Integration (Exporters & Erasers)
	CyberEvents_GDPR_Manager::init();

	// Background Queue Cron Job Handler
	add_action( 'cybevents_process_queue_cron', array( 'CyberEvents_Queue_Runner', 'process_batch' ) );

	// GDPR Daily Retention Cron Job Handler
	add_action( 'cybevents_gdpr_retention_cron', array( 'CyberEvents_GDPR_Manager', 'run_retention_cleanup' ) );

	// Installs activated before the queue interval existed never got the event; schedule what is missing.
	CyberEvents_Activator::ensure_cron_events();

	// WP 6.5+ Block Bindings & GenerateBlocks 2.x Dynamic Tags
	cybevents_register_ecosystem_bridges();

	/**
	 * Fires after WM Cyber Events Core has initialized its CPTs, blocks, privacy hooks, and cron schedules.
	 *
	 * @since 1.1.0
	 * @param string $version Plugin version.
	 */
	do_action( 'wm_cybevents_ready', CYBEREVENTS_VERSION );
}
add_action( 'init', 'cybevents_init' );

/**
 * Resolve a live conference or registration metric for Block Bindings and GenerateBlocks Dynamic Tags.
 *
 * @param string               $metric Metric key.
 * @param array<string, mixed> $args   Optional context arguments such as `event_id`.
 * @return string
 */
function cybevents_get_metric_value( string $metric, array $args = array() ): string {
	global $wpdb;

	$metric   = sanitize_key( $metric );
	$event_id = isset( $args['event_id'] ) ? absint( $args['event_id'] ) : 0;
	$value    = '';

	switch ( $metric ) {
		case 'active_events':
			$value = (string) count( CyberEvents_Database::get_active_events() );
			break;
		case 'confirmed_registrations':
			if ( $event_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$value = (string) (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE event_id = %d AND status = %s", $event_id, 'confirmed' ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$value = (string) (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE status = %s", 'confirmed' ) );
			}
			break;
		case 'checked_in':
			if ( $event_id > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$value = (string) (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE event_id = %d AND checked_in = %d", $event_id, 1 ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$value = (string) (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE checked_in = %d", 1 ) );
			}
			break;
		case 'pending_doi':
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$value = (string) (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE status = %s", 'pending_doi' ) );
			break;
		case 'sessions_total':
			if ( $event_id > 0 ) {
				$value = (string) count( CyberEvents_Database::get_sessions_by_event( $event_id ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$value = (string) (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_sessions WHERE id > %d", 0 ) );
			}
			break;
		case 'event_title':
		case 'event_capacity':
		case 'event_free_seats':
			if ( 0 === $event_id ) {
				$active   = CyberEvents_Database::get_active_events();
				$event_id = isset( $active[0] ) ? (int) $active[0]->id : 0;
			}
			$ev = $event_id > 0 ? CyberEvents_Database::get_event( $event_id ) : null;
			if ( $ev ) {
				if ( 'event_title' === $metric ) {
					$value = (string) $ev->title;
				} elseif ( 'event_capacity' === $metric ) {
					$value = (string) (int) $ev->max_capacity;
				} else {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$confirmed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cybevents_registrations WHERE event_id = %d AND status = %s", (int) $ev->id, 'confirmed' ) );
					$value     = (string) max( 0, (int) $ev->max_capacity - $confirmed );
				}
			}
			break;
	}

	/**
	 * Filters a resolved WM Cyber Events Core metric value for Block Bindings and Dynamic Tags.
	 *
	 * @since 1.1.0
	 * @param string               $value  Resolved metric string.
	 * @param string               $metric Metric key.
	 * @param array<string, mixed> $args   Context arguments.
	 */
	return (string) apply_filters( 'wm_cybevents_metric_value', $value, $metric, $args );
}

/**
 * Register WP 6.5+ Block Bindings source (`wm-cybevents/metrics`) and GenerateBlocks 2.x Dynamic Tag (`wm_cybevents_metric`).
 */
function cybevents_register_ecosystem_bridges(): void {
	if ( function_exists( 'register_block_bindings_source' ) ) {
		if ( ! function_exists( 'get_all_registered_block_bindings_sources' ) || ! array_key_exists( 'wm-cybevents/metrics', get_all_registered_block_bindings_sources() ) ) {
			register_block_bindings_source(
				'wm-cybevents/metrics',
				array(
					'label'              => __( 'Cyber Events Kennzahlen', 'wm-cyber-events-core' ),
					'get_value_callback' => static function ( array $source_args, $block_instance = null ): string {
						$key      = isset( $source_args['key'] ) ? sanitize_key( (string) $source_args['key'] ) : 'active_events';
						$event_id = isset( $source_args['event_id'] ) ? absint( $source_args['event_id'] ) : 0;
						return cybevents_get_metric_value( $key, array( 'event_id' => $event_id ) );
					},
					'uses_context'       => array( 'postId', 'postType' ),
				)
			);
		}
	}

	if ( class_exists( 'GenerateBlocks_Register_Dynamic_Tag' ) ) {
		new GenerateBlocks_Register_Dynamic_Tag(
			array(
				'title'       => __( 'Cyber Events Kennzahl', 'wm-cyber-events-core' ),
				'tag'         => 'wm_cybevents_metric',
				'type'        => 'wm-cyber-events-core',
				'supports'    => array(),
				'description' => __( 'Gibt Live-Kennzahlen zu aktiven Konferenzen, Bestätigungen und freien Plätzen aus.', 'wm-cyber-events-core' ),
				'options'     => array(
					'key'      => array(
						'type'    => 'select',
						'label'   => __( 'Kennzahl', 'wm-cyber-events-core' ),
						'default' => 'active_events',
					),
					'event_id' => array(
						'type'    => 'text',
						'label'   => __( 'Veranstaltungs-ID (optional)', 'wm-cyber-events-core' ),
						'default' => '0',
					),
				),
				'return'      => static function ( array $options ): string {
					$key      = isset( $options['key'] ) ? sanitize_key( (string) $options['key'] ) : 'active_events';
					$event_id = isset( $options['event_id'] ) ? absint( $options['event_id'] ) : 0;
					return cybevents_get_metric_value( $key, array( 'event_id' => $event_id ) );
				},
			)
		);
	}
}

add_filter(
	'generateblocks_dynamic_tag_replacement',
	static function ( $output, $tag_data = array(), $context = null ) {
		unset( $context );
		if ( is_array( $tag_data ) && ( $tag_data['tag'] ?? '' ) === 'wm_cybevents_metric' ) {
			$opts     = is_array( $tag_data['options'] ?? null ) ? $tag_data['options'] : array();
			$key      = isset( $opts['key'] ) ? sanitize_key( (string) $opts['key'] ) : 'active_events';
			$event_id = isset( $opts['event_id'] ) ? absint( $opts['event_id'] ) : 0;
			return cybevents_get_metric_value( $key, array( 'event_id' => $event_id ) );
		}
		return $output;
	},
	10,
	2
);

// Modern WP 6.9+ Abilities API Registration
add_action( 'wp_abilities_api_categories_init', array( 'CyberEvents_Admin', 'register_ability_categories' ) );
add_action( 'wp_abilities_api_init', array( 'CyberEvents_Admin', 'register_abilities' ) );

/**
 * Register REST API Endpoints.
 */
add_action( 'rest_api_init', array( 'CyberEvents_REST_API', 'register_routes' ) );
add_action( 'admin_notices', array( 'CyberEvents_REST_API', 'warn_if_webhook_unconfigured' ) );

/**
 * Register Admin Interface & Lifecycle Hooks.
 */
add_action( 'admin_init', array( 'CyberEvents_Admin', 'handle_export_actions' ) );
add_action( 'admin_menu', array( 'CyberEvents_Admin', 'register_admin_menus' ) );
add_action( 'current_screen', array( 'CyberEvents_Admin', 'add_contextual_help' ) );
add_action( 'wp_dashboard_setup', array( 'CyberEvents_Admin', 'register_dashboard_widget' ) );
add_action( 'add_meta_boxes', array( 'CyberEvents_Events_Manager', 'register_meta_boxes' ) );
add_action( 'add_meta_boxes', array( 'CyberEvents_Sessions_Manager', 'register_meta_boxes' ) );
add_action( 'save_post', array( 'CyberEvents_Events_Manager', 'sync_cpt_to_table' ), 10, 2 );
add_action( 'save_post', array( 'CyberEvents_Sessions_Manager', 'sync_cpt_to_table' ), 10, 2 );
add_filter( 'manage_cybevent_posts_columns', array( 'CyberEvents_Events_Manager', 'set_custom_columns' ) );
add_action( 'manage_cybevent_posts_custom_column', array( 'CyberEvents_Events_Manager', 'render_custom_columns' ), 10, 2 );
add_filter( 'post_row_actions', array( 'CyberEvents_Events_Manager', 'filter_post_row_actions' ), 10, 2 );
add_filter( 'manage_cybsession_posts_columns', array( 'CyberEvents_Sessions_Manager', 'set_custom_columns' ) );
add_action( 'manage_cybsession_posts_custom_column', array( 'CyberEvents_Sessions_Manager', 'render_custom_columns' ), 10, 2 );
add_action( 'cybvenue_add_form_fields', array( 'CyberEvents_Venues_Manager', 'render_add_form_fields' ) );
add_action( 'cybvenue_edit_form_fields', array( 'CyberEvents_Venues_Manager', 'render_edit_form_fields' ), 10, 2 );
add_action( 'created_cybvenue', array( 'CyberEvents_Venues_Manager', 'save_term_meta' ) );
add_action( 'edited_cybvenue', array( 'CyberEvents_Venues_Manager', 'save_term_meta' ) );
add_filter( 'manage_edit-cybvenue_columns', array( 'CyberEvents_Venues_Manager', 'set_custom_columns' ) );
add_filter( 'manage_cybvenue_custom_column', array( 'CyberEvents_Venues_Manager', 'render_custom_columns' ), 10, 3 );
add_filter( 'parent_file', array( 'CyberEvents_Admin', 'fix_admin_parent_file' ) );
add_filter( 'submenu_file', array( 'CyberEvents_Admin', 'fix_admin_submenu_file' ) );

// Plugin Action Links
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=cybevents-dashboard' ) ) . '">' . esc_html__( 'Dashboard', 'wm-cyber-events-core' ) . '</a>';
	$docs_link     = '<a href="' . esc_url( admin_url( 'admin.php?page=cybevents-docs' ) ) . '">' . esc_html__( 'Handbuch', 'wm-cyber-events-core' ) . '</a>';
	array_unshift( $links, $settings_link, $docs_link );
	return $links;
} );

// Plugin Row Meta Links
add_filter( 'plugin_row_meta', function( $links, $file ) {
	if ( plugin_basename( __FILE__ ) === $file ) {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=cybevents-docs' ) ) . '">' . esc_html__( 'Dokumentation', 'wm-cyber-events-core' ) . '</a>';
		// A "GitHub Repository" link used to sit here pointing at a PRIVATE repository, so every
		// installation showed a link that returns 404 to everyone but its owner. Restore it when
		// (and only when) a public repository exists, the way wm-wp-newsticker now does.
	}
	return $links;
}, 10, 2 );

/**
 * Enqueue Enterprise Admin Assets.
 *
 * @param string $hook_suffix Current admin page hook.
 */
function cybevents_enqueue_admin_assets( $hook_suffix ) {
	$hook_suffix = (string) $hook_suffix;
	// Own screens (toplevel_page_cybevents-dashboard, cyber-events_page_cybevents-*), the CPT
	// editors, the page the hub dispatches (wm-suite_page_wm-cyber-events-core) and the
	// dashboard, where the widget renders from the family classes.
	$is_plugin_screen = strpos( $hook_suffix, 'cybevents' ) !== false || strpos( $hook_suffix, 'wm-cyber-events-core' ) !== false
		|| 'post.php' === $hook_suffix || 'post-new.php' === $hook_suffix;
	$is_dashboard     = ( 'index.php' === $hook_suffix );
	if ( ! $is_plugin_screen && ! $is_dashboard ) {
		return;
	}

	// Family sheets (canonical in the hub, byte-identical copies here): every WM plugin registers
	// the same two handles, so WordPress loads one copy however many plugins are active. Sheets
	// and scripts are versioned by file time: a file updated under an unchanged plugin version
	// was served from the browser cache (measured in the fleet 2026-09-14). The plugin's own
	// admin layer comes last; it moved from admin/css/ to assets/css/ so the family gate measures it.
	$root = CYBEREVENTS_PLUGIN_DIR . 'assets/css/';
	wp_enqueue_style(
		'wm-admin-tokens',
		CYBEREVENTS_PLUGIN_URL . 'assets/css/wm-admin-tokens.css',
		array(),
		(string) ( @filemtime( $root . 'wm-admin-tokens.css' ) ?: CYBEREVENTS_VERSION )
	);
	wp_enqueue_style(
		'wm-admin-ui',
		CYBEREVENTS_PLUGIN_URL . 'assets/css/wm-admin-ui.css',
		array( 'wm-admin-tokens' ),
		(string) ( @filemtime( $root . 'wm-admin-ui.css' ) ?: CYBEREVENTS_VERSION )
	);
	wp_enqueue_style(
		'cybevents-admin-css',
		CYBEREVENTS_PLUGIN_URL . 'assets/css/cybevents-admin.css',
		array( 'wm-admin-ui', 'dashicons' ),
		(string) ( @filemtime( $root . 'cybevents-admin.css' ) ?: CYBEREVENTS_VERSION )
	);

	if ( ! $is_plugin_screen ) {
		return;
	}

	wp_enqueue_script(
		'cybevents-admin-js',
		CYBEREVENTS_PLUGIN_URL . 'admin/js/cybevents-admin.js',
		array(),
		(string) ( @filemtime( CYBEREVENTS_PLUGIN_DIR . 'admin/js/cybevents-admin.js' ) ?: CYBEREVENTS_VERSION ),
		true
	);
}
add_action( 'admin_enqueue_scripts', 'cybevents_enqueue_admin_assets' );

/**
 * Determine whether the current frontend request actually renders Cyber Events content.
 */
function cybevents_current_request_has_events(): bool {
	if ( function_exists( 'is_singular' ) && is_singular( array( 'cybevent', 'cybsession' ) ) ) {
		return true;
	}
	if ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( array( 'cybevent', 'cybsession' ) ) ) {
		return true;
	}
	if ( function_exists( 'is_tax' ) && is_tax( 'cybvenue' ) ) {
		return true;
	}

	$post = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
	if ( ! is_object( $post ) && function_exists( 'get_post' ) ) {
		$post = get_post();
	}
	if ( ! is_object( $post ) || empty( $post->post_content ) ) {
		return false;
	}

	$content = (string) $post->post_content;
	if ( str_contains( $content, 'wp:wm-cyber-events/' ) ) {
		return true;
	}

	return 1 === preg_match(
		'/\[(?:cybevents_(?:registration|session_selector|agenda|seat_map|calendar_views|calendar)|wm_events_views)\b/',
		$content
	);
}

/**
 * Enqueue frontend assets lazily when a block or shortcode renders inside FSE templates or widgets.
 */
function cybevents_enqueue_assets_for_render(): void {
	if ( ( function_exists( 'is_admin' ) && is_admin() ) || ! function_exists( 'wp_enqueue_script' ) ) {
		return;
	}
	cybevents_enqueue_frontend_assets( true );
}

/**
 * Enqueue public frontend assets.
 */
function cybevents_enqueue_frontend_assets( bool $force = false ) {
	if ( ! $force && ! cybevents_current_request_has_events() ) {
		return;
	}

	static $assets_enqueued = false;
	if ( $assets_enqueued ) {
		return;
	}
	$assets_enqueued = true;

	wp_enqueue_style(
		'cybevents-public',
		CYBEREVENTS_PLUGIN_URL . 'public/css/cybevents-public.css',
		array(),
		CYBEREVENTS_VERSION
	);

	// Inject Dynamic Theme Variables (Preset / Custom Theme / CSS Uploader)
	$dynamic_theme_css = CyberEvents_Theme_Manager::get_generated_css();
	if ( ! empty( $dynamic_theme_css ) ) {
		wp_add_inline_style( 'cybevents-public', $dynamic_theme_css );
	}

	wp_enqueue_script(
		'cybevents-public',
		CYBEREVENTS_PLUGIN_URL . 'public/js/cybevents-public.js',
		array( 'wp-api-fetch' ),
		CYBEREVENTS_VERSION,
		true
	);

	$settings = get_option( 'cybevents_settings', array() );
	if ( ! empty( $settings['enable_friendly_captcha'] ) && CyberEvents_Accessibility::captcha_widget_available() ) {
		wp_enqueue_script(
			'friendly-captcha-selfhosted',
			CYBEREVENTS_PLUGIN_URL . 'public/js/friendly-captcha.min.js',
			array(),
			CYBEREVENTS_VERSION,
			true
		);
	}

	// The routes live under cybevents/v1. Until 2026-09-15 this was rest_url() alone, so the
	// registration form and the session selector posted to routes that do not exist.
	// Seat map: its own file, so a page without the block loads nothing extra beyond the tag.
	wp_enqueue_script(
		'cybevents-seatmap',
		CYBEREVENTS_PLUGIN_URL . 'public/js/cybevents-seatmap.js',
		array(),
		CYBEREVENTS_VERSION,
		true
	);

	wp_localize_script(
		'cybevents-public',
		'cybeventsSettings',
		array(
			'root'  => esc_url_raw( rest_url( 'cybevents/v1' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'cybevents_enqueue_frontend_assets' );

/**
 * Frontend stylesheet in the block editor canvas.
 *
 * The editor draws Calendar Views and the seat map with ServerSideRender, whose markup is the frontend's.
 * Until 1.2.0 the stylesheet was enqueued on wp_enqueue_scripts only, so the editor showed both blocks as
 * unstyled text. enqueue_block_assets also runs for the editor canvas, the iframe included; on the frontend
 * cybevents_enqueue_frontend_assets() has already added the sheet and the theme preset, so this acts in the
 * admin only. No script: the preview in the editor is not interactive. WordPress prints the sheet in the editor
 * page around the canvas as well (measured 2026-11-20); every rule targets the plugin's own classes and its
 * --wm-* properties match the admin sheet, so the editor's own interface does not change.
 */
function cybevents_enqueue_editor_canvas_assets(): void {
	if ( ! is_admin() ) {
		return;
	}

	wp_enqueue_style(
		'cybevents-public',
		CYBEREVENTS_PLUGIN_URL . 'public/css/cybevents-public.css',
		array(),
		CYBEREVENTS_VERSION
	);

	$dynamic_theme_css = CyberEvents_Theme_Manager::get_generated_css();
	if ( ! empty( $dynamic_theme_css ) ) {
		wp_add_inline_style( 'cybevents-public', $dynamic_theme_css );
	}
}
add_action( 'enqueue_block_assets', 'cybevents_enqueue_editor_canvas_assets' );

// Template Hierarchy Overrides (Allows themes to place single-cybevent.php or archive-cybevent.php)
add_filter( 'template_include', array( 'CyberEvents_Theme_Manager', 'template_loader' ) );

/**
 * Shortcode for Accessible Registration Form.
 *
 * @param array $atts Shortcode attributes.
 * @return string Form HTML.
 */
function cybevents_registration_form_shortcode( $atts ) {
	cybevents_enqueue_assets_for_render();
	$atts = shortcode_atts(
		array(
			'event_id' => 0,
		),
		$atts,
		'cybevents_registration'
	);

	$event_id = absint( $atts['event_id'] );
	if ( ! $event_id ) {
		return '<p class="cybevents-error">' . esc_html__( 'Bitte geben Sie eine gültige Event-ID an: [cybevents_registration event_id="123"]', 'wm-cyber-events-core' ) . '</p>';
	}

	return CyberEvents_Accessibility::render_registration_form( $event_id );
}
add_shortcode( 'cybevents_registration', 'cybevents_registration_form_shortcode' );

/**
 * Shortcode for Token-based Session Selection (Brevo DOI Landeseite).
 *
 * @return string Session Selector HTML.
 */
function cybevents_session_selector_shortcode() {
	cybevents_enqueue_assets_for_render();
	if ( ! isset( $_GET['token'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '<p class="cybevents-notice cybevents-notice--warning">' . esc_html__( 'Kein gültiger Bestätigungs-Token gefunden. Bitte nutzen Sie den Link aus Ihrer E-Mail.', 'wm-cyber-events-core' ) . '</p>';
	}

	$raw_token = sanitize_text_field( wp_unslash( $_GET['token'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$reg       = CyberEvents_Database::get_registration_by_token( $raw_token );

	if ( ! $reg ) {
		return '<p class="cybevents-notice cybevents-notice--error">' . esc_html__( 'Ungültiger oder abgelaufener Teilnehmer-Token.', 'wm-cyber-events-core' ) . '</p>';
	}

	return CyberEvents_Sessions_Manager::render_session_selection_ui( $reg );
}
add_shortcode( 'cybevents_session_selector', 'cybevents_session_selector_shortcode' );

/**
 * Shortcode for Public Conference Agenda Matrix.
 *
 * @param array $atts Shortcode attributes.
 * @return string Agenda HTML.
 */
function cybevents_agenda_shortcode( $atts ) {
	cybevents_enqueue_assets_for_render();
	return CyberEvents_Events_Manager::render_agenda( $atts );
}
add_shortcode( 'cybevents_agenda', 'cybevents_agenda_shortcode' );

/**
 * Shortcode for the Seat Map.
 *
 * @param array $atts Shortcode attributes.
 * @return string Seat map HTML.
 */
function cybevents_seat_map_shortcode( $atts ) {
	cybevents_enqueue_assets_for_render();
	$atts = shortcode_atts(
		array(
			'event_id'    => 0,
			'heading'     => '',
			'description' => '',
			'live'        => '1',
		),
		$atts,
		'cybevents_seat_map'
	);

	$event_id = absint( $atts['event_id'] );
	if ( ! $event_id ) {
		$active   = CyberEvents_Database::get_active_events();
		$event_id = isset( $active[0] ) ? (int) $active[0]->id : 0;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read; the token only marks the viewer's own seats.
	$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

	return CyberEvents_Seat_Map::render(
		$event_id,
		$token,
		array(
			'heading'     => (string) $atts['heading'],
			'description' => (string) $atts['description'],
			'live'        => '0' !== (string) $atts['live'] && 'false' !== strtolower( (string) $atts['live'] ),
		)
	);
}
add_shortcode( 'cybevents_seat_map', 'cybevents_seat_map_shortcode' );
