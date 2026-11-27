<?php
/**
 * Module "company": Bedrijfsgegevens, stored once in Settings > Bedrijfsgegevens
 * (option goedehosting_company) and used everywhere:
 *
 *  - PHP: goede_hosting_company( 'email' ), goede_hosting_company_link( 'whatsapp' );
 *  - core Paragraph, Heading and Button blocks: block bindings source
 *    "goedehosting/company" (keys: see goede_hosting_company_binding_keys());
 *  - any text: [goedehosting_email], [goedehosting_telefoon],
 *    [goedehosting_whatsapp tekst="…"], [goedehosting_kvk], [goedehosting_adres]
 *    and [goedehosting_jaar] (optional link="nee").
 *
 * All values are empty until filled in (or set by a content package import).
 * Extra fields: filter goede_hosting_company_fields.
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions: key => label, input type, help text, shortcode hints.
 * Types: text, email, tel, checkbox.
 *
 * @return array<string, array{label:string, type:string, help:string, codes:string[]}>
 */
function goede_hosting_company_fields() {
	$no_link = __( 'zonder link', 'goede-hosting' );
	$fields  = array(
		'email'      => array(
			'label' => __( 'E-mailadres', 'goede-hosting' ),
			'type'  => 'email',
			'help'  => __( 'Ook het adres waar het contactformulier naartoe gaat, tenzij daar een ander adres is ingesteld.', 'goede-hosting' ),
			'codes' => array( '[goedehosting_email]', $no_link . ': [goedehosting_email link="nee"]' ),
		),
		'phone'      => array(
			'label' => __( 'Telefoonnummer (zoals getoond)', 'goede-hosting' ),
			'type'  => 'text',
			'help'  => '',
			'codes' => array( '[goedehosting_telefoon]', $no_link . ': [goedehosting_telefoon link="nee"]' ),
		),
		'phone_e164' => array(
			'label' => __( 'Telefoonnummer (internationaal, voor de link)', 'goede-hosting' ),
			'type'  => 'tel',
			'help'  => __( 'Met + en landcode, zonder spaties, bijvoorbeeld +31201234567.', 'goede-hosting' ),
			'codes' => array(),
		),
		'whatsapp'   => array(
			'label' => __( 'WhatsApp-nummer', 'goede-hosting' ),
			'type'  => 'text',
			'help'  => __( 'Alleen cijfers, met landcode, zonder +, bijvoorbeeld 31612345678. Leeg laten om WhatsApp te verbergen.', 'goede-hosting' ),
			'codes' => array( '[goedehosting_whatsapp tekst="Stuur een WhatsApp-bericht"]' ),
		),
		'address1'   => array(
			'label' => __( 'Adres: straat en huisnummer', 'goede-hosting' ),
			'type'  => 'text',
			'help'  => '',
			'codes' => array( '[goedehosting_adres]' ),
		),
		'address2'   => array(
			'label' => __( 'Adres: postcode en plaats', 'goede-hosting' ),
			'type'  => 'text',
			'help'  => '',
			'codes' => array(),
		),
		'kvk'        => array(
			'label' => __( 'KvK-nummer', 'goede-hosting' ),
			'type'  => 'text',
			'help'  => '',
			'codes' => array( '[goedehosting_kvk]' ),
		),
	);
	$fields  = apply_filters( 'goede_hosting_company_fields', $fields );
	foreach ( $fields as $key => $field ) {
		$fields[ $key ] = wp_parse_args(
			$field,
			array(
				'label' => $key,
				'type'  => 'text',
				'help'  => '',
				'codes' => array(),
			)
		);
	}
	return $fields;
}

/**
 * Keys of the company details (also the keys a content package may set).
 *
 * @return string[]
 */
function goede_hosting_company_keys() {
	return array_keys( goede_hosting_company_fields() );
}

/**
 * All company details, or one of them. Unknown or unset keys are ''.
 *
 * @param string|null $key Detail key, or null for the full array.
 * @return array<string, string>|string
 */
function goede_hosting_company( $key = null ) {
	$stored = get_option( 'goedehosting_company', array() );
	$stored = is_array( $stored ) ? $stored : array();
	$values = array();
	foreach ( goede_hosting_company_keys() as $field ) {
		$values[ $field ] = isset( $stored[ $field ] ) && is_scalar( $stored[ $field ] ) ? (string) $stored[ $field ] : '';
	}
	if ( null === $key ) {
		return $values;
	}
	return isset( $values[ $key ] ) ? $values[ $key ] : '';
}

/**
 * Link for a company detail: mailto:, tel: or https://wa.me/… URL, or ''.
 * The returned URL is not escaped; escape it on output.
 *
 * @param string $key email, phone (telefoon) or whatsapp.
 * @return string
 */
function goede_hosting_company_link( $key ) {
	$c = goede_hosting_company();
	switch ( $key ) {
		case 'email':
			return '' !== $c['email'] ? 'mailto:' . $c['email'] : '';
		case 'phone':
		case 'phone_e164':
		case 'telefoon':
			return '' !== $c['phone_e164'] ? 'tel:' . $c['phone_e164'] : '';
		case 'whatsapp':
			return '' !== $c['whatsapp'] ? 'https://wa.me/' . $c['whatsapp'] : '';
	}
	return '';
}

/**
 * Values offered to the block bindings source.
 *
 * @return array<string, array{label:string, value:string}>
 */
function goede_hosting_company_binding_keys() {
	$c   = goede_hosting_company();
	$out = array(
		'email'         => array(
			'label' => __( 'E-mailadres', 'goede-hosting' ),
			'value' => $c['email'],
		),
		'email_link'    => array(
			'label' => __( 'E-mailadres (link)', 'goede-hosting' ),
			'value' => goede_hosting_company_link( 'email' ),
		),
		'telefoon'      => array(
			'label' => __( 'Telefoonnummer', 'goede-hosting' ),
			'value' => $c['phone'],
		),
		'telefoon_link' => array(
			'label' => __( 'Telefoonnummer (link)', 'goede-hosting' ),
			'value' => goede_hosting_company_link( 'phone' ),
		),
		'whatsapp_link' => array(
			'label' => __( 'WhatsApp (link)', 'goede-hosting' ),
			'value' => goede_hosting_company_link( 'whatsapp' ),
		),
		'kvk'           => array(
			'label' => __( 'KvK-nummer', 'goede-hosting' ),
			'value' => $c['kvk'],
		),
	);
	// Extra fields from the filter are available under their own key.
	foreach ( goede_hosting_company_fields() as $key => $field ) {
		if ( ! isset( $out[ $key ] ) && 'checkbox' !== $field['type'] ) {
			$out[ $key ] = array(
				'label' => $field['label'],
				'value' => $c[ $key ],
			);
		}
	}
	return $out;
}

// -----------------------------------------------------------------------------
// Settings screen
// -----------------------------------------------------------------------------

/**
 * Sanitises the company details.
 *
 * @param mixed $in Raw input.
 * @return array<string, string>
 */
function goede_hosting_company_sanitize( $in ) {
	$in  = is_array( $in ) ? $in : array();
	$out = array();
	foreach ( goede_hosting_company_fields() as $key => $field ) {
		$raw = isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? (string) $in[ $key ] : '';
		switch ( $key ) {
			case 'email':
				$out[ $key ] = sanitize_email( $raw );
				break;
			case 'phone_e164':
				$e164        = ltrim( (string) preg_replace( '/[^\d+]/', '', str_replace( '(0)', '', $raw ) ), '+' );
				$out[ $key ] = '' !== $e164 ? '+' . $e164 : '';
				break;
			case 'whatsapp':
				$out[ $key ] = (string) preg_replace( '/\D/', '', $raw );
				break;
			default:
				if ( 'checkbox' === $field['type'] ) {
					$out[ $key ] = '' !== $raw && '0' !== $raw ? '1' : '';
				} elseif ( 'email' === $field['type'] ) {
					$out[ $key ] = sanitize_email( $raw );
				} else {
					$out[ $key ] = sanitize_text_field( $raw );
				}
		}
	}
	return $out;
}

/**
 * Registers the option.
 */
function goede_hosting_company_register_setting() {
	register_setting(
		'goedehosting_company',
		'goedehosting_company',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'goede_hosting_company_sanitize',
			'default'           => array(),
			'show_in_rest'      => false,
		)
	);
}
add_action( 'admin_init', 'goede_hosting_company_register_setting' );

/**
 * Adds Settings > Bedrijfsgegevens.
 */
function goede_hosting_company_menu() {
	add_options_page(
		__( 'Bedrijfsgegevens', 'goede-hosting' ),
		__( 'Bedrijfsgegevens', 'goede-hosting' ),
		'manage_options',
		'goedehosting-bedrijfsgegevens',
		'goede_hosting_company_page'
	);
}
add_action( 'admin_menu', 'goede_hosting_company_menu' );

/**
 * "Shortcode: [x] · zonder link: [y]" under a field; each code is selectable with one click.
 *
 * @param string[] $codes Codes, optionally prefixed with "label: ".
 * @return string Escaped HTML.
 */
function goede_hosting_company_shortcode_hint( $codes ) {
	if ( ! $codes ) {
		return '';
	}
	$parts = array();
	foreach ( $codes as $code ) {
		$label = '';
		if ( false !== strpos( $code, ': ' ) ) {
			list( $label, $code ) = explode( ': ', $code, 2 );
			$label               .= ': ';
		}
		$parts[] = esc_html( $label ) . '<code class="goede-hosting-sc" style="user-select:all">' . esc_html( $code ) . '</code>';
	}
	return '<p class="description">' . esc_html__( 'Shortcode:', 'goede-hosting' ) . ' ' . implode( ' &middot; ', $parts ) . '</p>';
}

/**
 * Renders the settings screen.
 */
function goede_hosting_company_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$c = goede_hosting_company();

	echo '<div class="wrap"><h1>' . esc_html__( 'Bedrijfsgegevens', 'goede-hosting' ) . '</h1>';
	echo '<p>' . esc_html__( 'Deze gegevens worden op de hele site gebruikt: in blokken, via blokkoppelingen en met de shortcodes hieronder. Pas ze hier aan en ze veranderen overal.', 'goede-hosting' ) . '</p>';
	echo '<p>' . esc_html__( 'Het huidige jaar (bijvoorbeeld in de footer):', 'goede-hosting' ) . ' <code class="goede-hosting-sc" style="user-select:all">[goedehosting_jaar]</code></p>';
	echo '<form method="post" action="options.php">';
	settings_fields( 'goedehosting_company' );
	echo '<table class="form-table" role="presentation">';
	foreach ( goede_hosting_company_fields() as $key => $f ) {
		$help  = '' !== $f['help'] ? '<p class="description">' . esc_html( $f['help'] ) . '</p>' : '';
		$codes = goede_hosting_company_shortcode_hint( $f['codes'] );
		if ( 'checkbox' === $f['type'] ) {
			printf(
				'<tr><th scope="row">%2$s</th><td><label><input type="checkbox" id="gh-%1$s" name="goedehosting_company[%1$s]" value="1"%3$s> %2$s</label>%4$s%5$s</td></tr>',
				esc_attr( $key ),
				esc_html( $f['label'] ),
				checked( '1', $c[ $key ], false ),
				$help, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				$codes // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper.
			);
			continue;
		}
		printf(
			'<tr><th scope="row"><label for="gh-%1$s">%2$s</label></th><td><input type="%3$s" class="regular-text" id="gh-%1$s" name="goedehosting_company[%1$s]" value="%4$s">%5$s%6$s</td></tr>',
			esc_attr( $key ),
			esc_html( $f['label'] ),
			esc_attr( in_array( $f['type'], array( 'email', 'tel' ), true ) ? $f['type'] : 'text' ),
			esc_attr( $c[ $key ] ),
			$help, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			$codes // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper.
		);
	}
	echo '</table>';
	submit_button();
	echo '</form></div>';

	// Copy shortcodes as plain text, so the editor doesn't paste them as inline code.
	wp_print_inline_script_tag(
		"document.addEventListener('copy',function(e){var s=window.getSelection();if(!s||!s.anchorNode)return;var n=s.anchorNode.nodeType===1?s.anchorNode:s.anchorNode.parentNode;if(!n.closest||!n.closest('.goede-hosting-sc'))return;e.clipboardData.setData('text/plain',s.toString());e.preventDefault();});"
	);
}

// -----------------------------------------------------------------------------
// Shortcodes
// -----------------------------------------------------------------------------

/**
 * Whether a shortcode asks for plain text (link="nee").
 *
 * @param array $atts Parsed attributes.
 * @return bool
 */
function goede_hosting_sc_no_link( $atts ) {
	return in_array( strtolower( trim( (string) $atts['link'] ) ), array( 'nee', 'no', '0', 'false' ), true );
}

/**
 * [goedehosting_email] → linked address; link="nee" → address only.
 *
 * @param array|string $atts Attributes.
 * @return string
 */
function goede_hosting_sc_email( $atts ) {
	$atts  = shortcode_atts( array( 'link' => 'ja' ), $atts, 'goedehosting_email' );
	$email = goede_hosting_company( 'email' );
	if ( '' === $email || goede_hosting_sc_no_link( $atts ) ) {
		return esc_html( $email );
	}
	return '<a href="' . esc_url( goede_hosting_company_link( 'email' ), array( 'mailto' ) ) . '">' . esc_html( $email ) . '</a>';
}
add_shortcode( 'goedehosting_email', 'goede_hosting_sc_email' );

/**
 * [goedehosting_telefoon] → linked number; link="nee" → number only.
 *
 * @param array|string $atts Attributes.
 * @return string
 */
function goede_hosting_sc_phone( $atts ) {
	$atts  = shortcode_atts( array( 'link' => 'ja' ), $atts, 'goedehosting_telefoon' );
	$phone = goede_hosting_company( 'phone' );
	$link  = goede_hosting_company_link( 'phone' );
	if ( '' === $phone || '' === $link || goede_hosting_sc_no_link( $atts ) ) {
		return esc_html( $phone );
	}
	return '<a href="' . esc_url( $link, array( 'tel' ) ) . '">' . esc_html( $phone ) . '</a>';
}
add_shortcode( 'goedehosting_telefoon', 'goede_hosting_sc_phone' );

/**
 * [goedehosting_whatsapp tekst="Stuur een WhatsApp-bericht"] → WhatsApp link; link="nee" → text only.
 *
 * @param array|string $atts Attributes.
 * @return string
 */
function goede_hosting_sc_whatsapp( $atts ) {
	$atts = shortcode_atts(
		array(
			'tekst' => __( 'WhatsApp', 'goede-hosting' ),
			'link'  => 'ja',
		),
		$atts,
		'goedehosting_whatsapp'
	);
	$link = goede_hosting_company_link( 'whatsapp' );
	if ( '' === $link || goede_hosting_sc_no_link( $atts ) ) {
		return esc_html( $atts['tekst'] );
	}
	return '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $atts['tekst'] ) . '</a>';
}
add_shortcode( 'goedehosting_whatsapp', 'goede_hosting_sc_whatsapp' );

/**
 * [goedehosting_kvk] → KvK number (plain text).
 *
 * @return string
 */
function goede_hosting_sc_kvk() {
	return esc_html( goede_hosting_company( 'kvk' ) );
}
add_shortcode( 'goedehosting_kvk', 'goede_hosting_sc_kvk' );

/**
 * [goedehosting_adres] → address on one line; scheiding="<br>" for two lines.
 *
 * @param array|string $atts Attributes.
 * @return string
 */
function goede_hosting_sc_address( $atts ) {
	$atts  = shortcode_atts( array( 'scheiding' => ', ' ), $atts, 'goedehosting_adres' );
	$parts = array_filter( array( goede_hosting_company( 'address1' ), goede_hosting_company( 'address2' ) ), 'strlen' );
	$glue  = '<br>' === strtolower( trim( (string) $atts['scheiding'] ) ) ? '<br>' : esc_html( (string) $atts['scheiding'] );
	return implode( $glue, array_map( 'esc_html', $parts ) );
}
add_shortcode( 'goedehosting_adres', 'goede_hosting_sc_address' );

/**
 * [goedehosting_jaar] → current year (site time zone).
 *
 * @return string
 */
function goede_hosting_sc_year() {
	return esc_html( wp_date( 'Y' ) );
}
add_shortcode( 'goedehosting_jaar', 'goede_hosting_sc_year' );

// -----------------------------------------------------------------------------
// Block bindings source (core Paragraph / Heading / Button)
// -----------------------------------------------------------------------------

/**
 * Value for the block bindings source goedehosting/company.
 * Args: {"key":"whatsapp_link","tekst":"Hallo …"} adds a suggested message.
 *
 * @param array $source_args Binding args.
 * @return string|null
 */
function goede_hosting_company_binding_value( $source_args ) {
	$fields = goede_hosting_company_binding_keys();
	$key    = isset( $source_args['key'] ) && is_string( $source_args['key'] ) ? $source_args['key'] : '';
	if ( ! isset( $fields[ $key ] ) ) {
		return null;
	}
	$value = $fields[ $key ]['value'];
	if ( 'whatsapp_link' === $key && '' !== $value && ! empty( $source_args['tekst'] ) && is_string( $source_args['tekst'] ) ) {
		$value .= '?text=' . rawurlencode( $source_args['tekst'] );
	}
	return $value;
}

/**
 * Registers the block bindings source.
 */
function goede_hosting_company_register_binding() {
	if ( function_exists( 'register_block_bindings_source' ) ) {
		register_block_bindings_source(
			'goedehosting/company',
			array(
				'label'              => __( 'Bedrijfsgegevens', 'goede-hosting' ),
				'get_value_callback' => 'goede_hosting_company_binding_value',
			)
		);
	}
}
add_action( 'init', 'goede_hosting_company_register_binding' );
