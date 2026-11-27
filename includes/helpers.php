<?php
/**
 * Shared helpers: icons and overridable templates.
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small inline line icon (16x16, stroke = currentColor, decorative).
 *
 * @param string $slug      arrow, mail, phone, whatsapp.
 * @param string $css_class Extra classes.
 * @return string SVG markup, '' for an unknown slug.
 */
function goede_hosting_icon( $slug, $css_class = '' ) {
	$paths = array(
		'arrow'    => '<path d="M3 8h9M8.5 4.5 12 8l-3.5 3.5"/>',
		'mail'     => '<rect x="2.5" y="3.8" width="11" height="8.4" rx="1.6"/><path d="m3 5 5 3.8L13 5"/>',
		'phone'    => '<rect x="4.5" y="2" width="7" height="12" rx="1.6"/><path d="M7.2 11.6h1.6"/>',
		'whatsapp' => '<path d="M2.4 13.6l.85-3.05A5.75 5.75 0 1 1 5.5 12.8l-3.1.8Z"/><path fill="currentColor" stroke="none" d="M6.05 4.9c-.17-.02-.4.02-.58.2-.38.38-.66.95-.5 1.66.22.98.9 2.05 1.86 2.93.97.88 2.06 1.43 3.02 1.55.62.08 1.13-.23 1.47-.62.16-.18.18-.42.13-.6l-.2-.55c-.06-.16-.22-.27-.4-.28l-1.06-.06a.48.48 0 0 0-.4.18l-.37.47c-.62-.28-1.27-.86-1.62-1.46l.43-.4a.48.48 0 0 0 .14-.42l-.2-1.06a.45.45 0 0 0-.36-.36Z"/>',
	);
	if ( ! isset( $paths[ $slug ] ) ) {
		return '';
	}
	return '<svg class="' . esc_attr( trim( 'gh-icon ' . $css_class ) ) . '" viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $slug ] . '</svg>';
}

/**
 * Path of a template: the theme's copy in goede-hosting/<name> when it has one,
 * otherwise the plugin's own in templates/<name>.
 *
 * @param string $name File name, e.g. "contact-form.php".
 * @return string
 */
function goede_hosting_template_path( $name ) {
	$theme = locate_template( 'goede-hosting/' . $name );
	return '' !== $theme ? $theme : GOEDE_HOSTING_DIR . 'templates/' . $name;
}

/**
 * Renders a template with variables and returns the output.
 *
 * @param string $name Template file name.
 * @param array  $vars Variables, available in the template as $args.
 * @return string
 */
function goede_hosting_render_template( $name, $vars ) {
	$args = $vars;
	ob_start();
	include goede_hosting_template_path( $name );
	return (string) ob_get_clean();
}

/**
 * Admin stylesheet of the plugin's screens.
 */
function goede_hosting_enqueue_admin_style() {
	wp_enqueue_style( 'goede-hosting-admin', GOEDE_HOSTING_URL . 'assets/css/admin.css', array(), GOEDE_HOSTING_VERSION );
}
