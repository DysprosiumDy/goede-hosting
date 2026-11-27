<?php
/**
 * Contact form: front end (state after a redirect, template data, block).
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Signature of the form timestamp, so the fill time can't be faked.
 *
 * @param string $stamp Unix time as string.
 * @return string
 */
function goede_hosting_contact_stamp_hash( $stamp ) {
	return substr( wp_hash( 'goedehosting_contact|' . $stamp, 'nonce' ), 0, 16 );
}

/**
 * Error/value state from the redirect after a failed submission.
 *
 * @return array{values:array<string,string>, errors:array<string,string>, general:string}
 */
function goede_hosting_contact_state() {
	$state = array(
		'values'  => array(),
		'errors'  => array(),
		'general' => '',
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup of a random token.
	$token = isset( $_GET['gh_fout'] ) ? sanitize_key( wp_unslash( $_GET['gh_fout'] ) ) : '';
	if ( '' === $token ) {
		return $state;
	}
	$messages = goede_hosting_contact_messages();
	if ( isset( $messages[ $token ] ) ) {
		$state['general'] = $messages[ $token ];
		return $state;
	}
	$stored = get_transient( 'goedehosting_contact_' . $token );
	if ( ! is_array( $stored ) ) {
		$state['general'] = __( 'Er ging iets mis bij het versturen. Probeer het opnieuw.', 'goede-hosting' );
		return $state;
	}
	foreach ( array( 'values', 'errors' ) as $part ) {
		if ( isset( $stored[ $part ] ) && is_array( $stored[ $part ] ) ) {
			$state[ $part ] = array_map( 'strval', array_filter( $stored[ $part ], 'is_scalar' ) );
		}
	}
	$state['general'] = isset( $stored['general'] ) && is_string( $stored['general'] ) ? $stored['general'] : '';
	return $state;
}

/**
 * The input or textarea of a field, with value, limits and error reference.
 * Templates call this, so they only decide the markup around it.
 *
 * @param array $field Field from the template data ($args['fields'][ $key ]).
 * @param array $attrs Extra attributes (class, placeholder, rows …).
 * @return string HTML.
 */
function goede_hosting_contact_control( $field, $attrs = array() ) {
	$base = array(
		'id'        => $field['id'],
		'name'      => $field['key'],
		'maxlength' => (string) $field['maxlength'],
	);
	if ( '' !== $field['autocomplete'] ) {
		$base['autocomplete'] = $field['autocomplete'];
	}
	if ( '' !== $field['placeholder'] ) {
		$base['placeholder'] = $field['placeholder'];
	}
	if ( '' !== $field['error'] ) {
		$base['aria-invalid']     = 'true';
		$base['aria-describedby'] = $field['error_id'];
	}
	$attrs = array_merge( $base, $attrs );

	$html = '';
	foreach ( $attrs as $name => $value ) {
		$html .= ' ' . esc_attr( $name ) . '="' . esc_attr( (string) $value ) . '"';
	}
	if ( $field['required'] ) {
		$html .= ' required';
	}
	if ( 'textarea' === $field['type'] ) {
		if ( ! isset( $attrs['rows'] ) ) {
			$html .= ' rows="6"';
		}
		return '<textarea' . $html . '>' . esc_textarea( wp_specialchars_decode( $field['value'], ENT_QUOTES ) ) . '</textarea>';
	}
	return '<input type="' . esc_attr( $field['type'] ) . '"' . $html . ' value="' . esc_attr( $field['value'] ) . '">';
}

/**
 * Error message of a field ('' when none).
 *
 * @param array  $field     Field from the template data.
 * @param string $css_class Class of the element.
 * @return string HTML.
 */
function goede_hosting_contact_error( $field, $css_class = 'gh-form__error' ) {
	if ( '' === $field['error'] ) {
		return '';
	}
	return '<span class="' . esc_attr( $css_class ) . '" id="' . esc_attr( $field['error_id'] ) . '">' . esc_html( $field['error'] ) . '</span>';
}

/**
 * Data for the form template.
 *
 * @param array $args button_text, success_text, id.
 * @return array
 */
function goede_hosting_contact_form_data( $args ) {
	$args  = wp_parse_args(
		$args,
		array(
			'button_text'  => '',
			'success_text' => '',
			'id'           => 'contactformulier',
		)
	);
	$state = goede_hosting_contact_state();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
	$sent    = isset( $_GET['verzonden'] ) && '1' === sanitize_key( wp_unslash( $_GET['verzonden'] ) ) && ! $state['errors'] && '' === $state['general'];
	$post_id = is_singular() ? get_queried_object_id() : (int) get_the_ID();
	$stamp   = (string) time();

	$notice = null;
	if ( $sent ) {
		$notice = array(
			'type' => 'success',
			'text' => '' !== trim( $args['success_text'] ) ? $args['success_text'] : __( 'Bedankt! Je bericht is verstuurd.', 'goede-hosting' ),
		);
	} elseif ( $state['errors'] || '' !== $state['general'] ) {
		$notice = array(
			'type' => 'error',
			'text' => '' !== $state['general'] ? $state['general'] : __( 'Controleer de gemarkeerde velden en probeer het opnieuw.', 'goede-hosting' ),
		);
	}

	$fields = array();
	$groups = array();
	foreach ( goede_hosting_contact_fields() as $key => $field ) {
		$field['key']      = $key;
		$field['id']       = 'gh-' . $key;
		$field['error_id'] = 'gh-' . $key . '-fout';
		$field['value']    = isset( $state['values'][ $key ] ) ? $state['values'][ $key ] : '';
		$field['error']    = isset( $state['errors'][ $key ] ) ? $state['errors'][ $key ] : '';
		$fields[ $key ]    = $field;

		// Layout rows: a single field, or a group of fields under one legend.
		$group = '' !== $field['group'] ? $field['group'] : '';
		if ( '' !== $group && isset( $groups[ $group ] ) ) {
			$groups[ $group ]['fields'][] = $key;
			continue;
		}
		$row = array(
			'group'    => $group,
			'label'    => '' !== $field['group_label'] ? $field['group_label'] : $field['label'],
			'required' => $field['required'],
			'fields'   => array( $key ),
		);
		if ( '' !== $group ) {
			$groups[ $group ] = $row;
		} else {
			$groups[ '_' . $key ] = $row;
		}
	}

	ob_start();
	?>
	<input type="hidden" name="action" value="goedehosting_contact">
	<input type="hidden" name="gh_pagina" value="<?php echo esc_attr( (string) $post_id ); ?>">
	<input type="hidden" name="gh_tijd" value="<?php echo esc_attr( $stamp . '.' . goede_hosting_contact_stamp_hash( $stamp ) ); ?>">
	<?php wp_nonce_field( 'goedehosting_contact', 'gh_nonce' ); ?>
	<div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">
		<label><?php esc_html_e( 'Laat dit veld leeg', 'goede-hosting' ); ?> <input type="text" name="gh_website" value="" tabindex="-1" autocomplete="off"></label>
	</div>
	<?php
	$hidden = (string) ob_get_clean();

	return array(
		'id'          => sanitize_html_class( $args['id'] ),
		'action'      => admin_url( 'admin-post.php' ),
		'button_text' => '' !== trim( $args['button_text'] ) ? $args['button_text'] : __( 'Versturen', 'goede-hosting' ),
		'notice'      => $notice,
		'hidden'      => $hidden,
		'fields'      => $fields,
		'rows'        => array_values( $groups ),
	);
}

/**
 * The contact form (rendered with the overridable template).
 *
 * @param array $args button_text, success_text, id (anchor; default contactformulier).
 * @return string
 */
function goede_hosting_contact_form( $args = array() ) {
	if ( apply_filters( 'goede_hosting_contact_form_css', true ) ) {
		wp_enqueue_style( 'goede-hosting-contact-form' );
	}
	return goede_hosting_render_template( 'contact-form.php', goede_hosting_contact_form_data( $args ) );
}

// -----------------------------------------------------------------------------
// Block "Goede Hosting Contactformulier"
// -----------------------------------------------------------------------------

/**
 * Registers the stylesheet, the editor script and the block.
 */
function goede_hosting_contact_register_block() {
	wp_register_style( 'goede-hosting-contact-form', GOEDE_HOSTING_URL . 'assets/css/contact-form.css', array(), GOEDE_HOSTING_VERSION );
	wp_register_script(
		'goede-hosting-contact-form-editor',
		GOEDE_HOSTING_URL . 'assets/js/contact-form-block.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
		GOEDE_HOSTING_VERSION,
		true
	);
	register_block_type(
		'goede-hosting/contact-form',
		array(
			'api_version'     => 3,
			'title'           => __( 'Goede Hosting Contactformulier', 'goede-hosting' ),
			'description'     => __( 'Contactformulier. Berichten worden gemaild en bewaard onder Inzendingen.', 'goede-hosting' ),
			'category'        => 'widgets',
			'icon'            => 'email',
			'editor_script'   => 'goede-hosting-contact-form-editor',
			'render_callback' => 'goede_hosting_contact_render_block',
			'supports'        => array(
				'html'     => false,
				'multiple' => false,
			),
			'attributes'      => array(
				'buttonText'  => array(
					'type'    => 'string',
					'default' => '',
				),
				'successText' => array(
					'type'    => 'string',
					'default' => '',
				),
				'recipient'   => array(
					'type'    => 'string',
					'default' => '',
				),
			),
		)
	);
}
add_action( 'init', 'goede_hosting_contact_register_block' );

/**
 * Block render callback.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function goede_hosting_contact_render_block( $attributes ) {
	$a = is_array( $attributes ) ? $attributes : array();
	return goede_hosting_contact_form(
		array(
			'button_text'  => isset( $a['buttonText'] ) ? (string) $a['buttonText'] : '',
			'success_text' => isset( $a['successText'] ) ? (string) $a['successText'] : '',
		)
	);
}
