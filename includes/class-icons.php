<?php
/**
 * Decorative inline SVG icons for the frontend (replacing emoji, which render differently on every
 * system and are announced by screen readers with their Unicode names).
 *
 * The shapes are drawn for this plugin. Every icon is decorative: aria-hidden, not focusable, and
 * coloured with currentColor, so the text next to it carries the meaning.
 *
 * @package CyberEvents
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CyberEvents_Icons {

	/** SVG element bodies on a 24 x 24 grid, stroked. */
	const SHAPES = array(
		'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
		'map-pin'  => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
		'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14a5 5 0 0 1 3.5 6"/>',
		'ticket'   => '<path d="M3 7h18v3a2 2 0 0 0 0 4v3H3v-3a2 2 0 0 0 0-4z"/><path d="M14 7v10" stroke-dasharray="2 2"/>',
		'building' => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 7h2M14 7h2M8 11h2M14 11h2M8 15h2M14 15h2M11 21v-3h2v3"/>',
		'mic'      => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/>',
		'search'   => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
		'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
		'alert'    => '<path d="M12 3.5L22 20H2z"/><path d="M12 10v4.5M12 17.5v.5"/>',
		'globe'    => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
		'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 6.5L12 13l8.5-6.5"/>',
		'download' => '<path d="M12 4v11M7 10.5l5 5 5-5M4 20h16"/>',
		'lock'     => '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
		'shield'   => '<path d="M12 3l8 3v5.5c0 4.7-3.4 8.2-8 9.5-4.6-1.3-8-4.8-8-9.5V6z"/>',
		'document' => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
		'arrow'    => '<path d="M4 12h15M13 6l6 6-6 6"/>',
		'list'     => '<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
		'camera'   => '<path d="M4 8h3l2-2.5h6L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
		'pen'      => '<path d="M4 20l1-4.5L15.5 5l3.5 3.5L8.5 19z"/><path d="M13.5 7l3.5 3.5"/>',
		'dot'      => '<circle cx="12" cy="12" r="5" fill="currentColor" stroke="none"/>',
		// Calendar Views (1.2.0): the shapes that replaced its emoji; drawn on the same grid with the same pen.
		'close'      => '<path d="M6 6l12 12M18 6L6 18"/>',
		'diamond'    => '<path d="M12 3l9 9-9 9-9-9z" fill="currentColor" stroke="none"/>',
		'star'       => '<path d="M12 3.7l2.1 5.7 6.1.2-4.8 3.8 1.7 5.9-5.1-3.4-5.1 3.4 1.7-5.9-4.8-3.8 6.1-.2z"/>',
		'flag'       => '<path d="M5 21V4"/><path d="M5 4h12l-2.5 4.5L17 13H5"/>',
		'columns'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16M15 4v16"/>',
		'grid'       => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
		'scale'      => '<path d="M12 3v17M7 20h10M5 7h14"/><path d="M5 7l-3 6.5a3 3 0 0 0 6 0z"/><path d="M19 7l-3 6.5a3 3 0 0 0 6 0z"/>',
		'package'    => '<path d="M12 3l8 4.5v9L12 21l-8-4.5v-9z"/><path d="M4 7.5l8 4.5 8-4.5M12 12v9"/>',
		'train'      => '<rect x="6" y="3" width="12" height="13" rx="3"/><path d="M6 10h12M9.5 16L7 20.5M14.5 16l2.5 4.5M9.5 13h.01M14.5 13h.01"/>',
		'car'        => '<path d="M5 15.5V12l2-4.5h10l2 4.5v3.5z"/><path d="M5 12h14"/><circle cx="8" cy="17.5" r="1.8"/><circle cx="16" cy="17.5" r="1.8"/>',
		'accessible' => '<circle cx="11" cy="4.5" r="1.5"/><path d="M11 7.5v6h5l2 5"/><path d="M8.5 10.5a5.5 5.5 0 1 0 7 7"/>',
	);

	/**
	 * Inline SVG markup for an icon.
	 *
	 * @param string $name  Icon key from SHAPES.
	 * @param int    $size  Width and height in pixels.
	 * @param string $class Extra CSS class.
	 * @return string Empty string for an unknown name.
	 */
	public static function svg( $name, $size = 16, $class = '' ) {
		if ( ! isset( self::SHAPES[ $name ] ) ) {
			return '';
		}
		return sprintf(
			'<svg class="cybevents-icon%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
			'' !== $class ? ' ' . esc_attr( (string) $class ) : '',
			absint( $size ),
			self::SHAPES[ $name ]
		);
	}

	/**
	 * Allowed HTML + inline SVG tags for `wp_kses()` on badge, notice, and avatar fragments.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		$post_tags = function_exists( 'wp_kses_allowed_html' ) ? wp_kses_allowed_html( 'post' ) : array();
		$svg_tags  = array(
			'svg'    => array(
				'class'           => true,
				'width'           => true,
				'height'          => true,
				'viewbox'         => true,
				'viewBox'         => true,
				'fill'            => true,
				'stroke'          => true,
				'stroke-width'    => true,
				'stroke-linecap'  => true,
				'stroke-linejoin' => true,
				'aria-hidden'     => true,
				'aria-label'      => true,
				'role'            => true,
				'focusable'       => true,
				'xmlns'           => true,
			),
			'path'   => array(
				'd'                => true,
				'fill'             => true,
				'stroke'           => true,
				'stroke-width'     => true,
				'stroke-dasharray' => true,
			),
			'circle' => array(
				'cx'     => true,
				'cy'     => true,
				'r'      => true,
				'fill'   => true,
				'stroke' => true,
			),
			'rect'   => array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
				'fill'   => true,
				'stroke' => true,
			),
			'defs'   => array(),
			'lineargradient' => array(
				'id' => true,
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			),
			'linearGradient' => array(
				'id' => true,
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			),
			'stop'   => array(
				'offset'     => true,
				'stop-color' => true,
			),
			'text'   => array(
				'x'                 => true,
				'y'                 => true,
				'font-family'       => true,
				'font-size'         => true,
				'font-weight'       => true,
				'fill'              => true,
				'text-anchor'       => true,
				'dominant-baseline' => true,
			),
			'img'    => array(
				'src'     => true,
				'alt'     => true,
				'class'   => true,
				'style'   => true,
				'width'   => true,
				'height'  => true,
				'loading' => true,
			),
			'div'    => array(
				'id'    => true,
				'class' => true,
				'style' => true,
				'role'  => true,
			),
			'span'   => array(
				'id'          => true,
				'class'       => true,
				'style'       => true,
				'aria-hidden' => true,
			),
			'p'      => array(
				'class' => true,
				'style' => true,
			),
			'strong' => array(
				'class' => true,
			),
			'code'   => array(
				'class' => true,
			),
		);

		return array_merge( $post_tags, $svg_tags );
	}
}
