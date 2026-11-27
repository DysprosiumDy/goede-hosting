<?php
/**
 * Module "whatsapp-button": a floating WhatsApp button at the bottom right of
 * every page, linked to the WhatsApp number in Bedrijfsgegevens.
 *
 * Off until "Zwevende WhatsApp-knop tonen" is ticked in Settings >
 * Bedrijfsgegevens (company detail whatsapp_button) and a number is set. Needs
 * the "company" module. Styling: assets/css/whatsapp-button.css (a theme can
 * restyle .gh-whatsapp-button or dequeue the handle goede-hosting-whatsapp).
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the on/off setting to the company details.
 *
 * @param array $fields Company fields.
 * @return array
 */
function goede_hosting_whatsapp_button_field( $fields ) {
	$fields['whatsapp_button'] = array(
		'label' => __( 'Zwevende WhatsApp-knop tonen', 'goede-hosting' ),
		'type'  => 'checkbox',
		'help'  => __( 'Een ronde WhatsApp-knop rechtsonder op elke pagina, naar het WhatsApp-nummer hierboven.', 'goede-hosting' ),
	);
	return $fields;
}
add_filter( 'goede_hosting_company_fields', 'goede_hosting_whatsapp_button_field' );

/**
 * WhatsApp link when the button should show, else ''.
 *
 * @return string
 */
function goede_hosting_whatsapp_button_link() {
	if ( ! function_exists( 'goede_hosting_company' ) || '1' !== goede_hosting_company( 'whatsapp_button' ) ) {
		return '';
	}
	return (string) apply_filters( 'goede_hosting_whatsapp_button_link', goede_hosting_company_link( 'whatsapp' ) );
}

/**
 * Stylesheet, only when the button shows.
 */
function goede_hosting_whatsapp_button_assets() {
	if ( '' !== goede_hosting_whatsapp_button_link() ) {
		wp_enqueue_style( 'goede-hosting-whatsapp', GOEDE_HOSTING_URL . 'assets/css/whatsapp-button.css', array(), GOEDE_HOSTING_VERSION );
	}
}
add_action( 'wp_enqueue_scripts', 'goede_hosting_whatsapp_button_assets' );

/**
 * Prints the button.
 */
function goede_hosting_whatsapp_button() {
	$link = goede_hosting_whatsapp_button_link();
	if ( '' === $link ) {
		return;
	}
	$label = __( 'Chat met ons op WhatsApp', 'goede-hosting' );
	$html  = sprintf(
		'<a class="gh-whatsapp-button" href="%1$s" target="_blank" rel="noopener" aria-label="%2$s" title="%2$s">%3$s</a>',
		esc_url( $link ),
		esc_attr( $label ),
		goede_hosting_icon( 'whatsapp', 'gh-whatsapp-button__icon' )
	);
	echo apply_filters( 'goede_hosting_whatsapp_button_html', $html, $link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above; a filter must return safe HTML.
}
add_action( 'wp_footer', 'goede_hosting_whatsapp_button' );
