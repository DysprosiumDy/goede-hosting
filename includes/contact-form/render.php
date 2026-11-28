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
 * Which form a redirect after a submission is about (?gh_form=<id>; 0 or
 * missing for the theme's contact form).
 *
 * @return int
 */
function goede_hosting_contact_redirect_form() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	return isset( $_GET['gh_form'] ) ? absint( wp_unslash( $_GET['gh_form'] ) ) : 0;
}

/**
 * Error/value state from the redirect after a failed submission, for one form
 * (other forms on the same page show nothing).
 *
 * @param int $form_id Form post ID, 0 for the theme's contact form.
 * @return array{values:array<string,string|array>, errors:array<string,string>, general:string}
 */
function goede_hosting_contact_state( $form_id = 0 ) {
	$state = array(
		'values'  => array(),
		'errors'  => array(),
		'general' => '',
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup of a random token.
	$token = isset( $_GET['gh_fout'] ) ? sanitize_key( wp_unslash( $_GET['gh_fout'] ) ) : '';
	if ( '' === $token || goede_hosting_contact_redirect_form() !== (int) $form_id ) {
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
	if ( isset( $stored['errors'] ) && is_array( $stored['errors'] ) ) {
		$state['errors'] = array_map( 'strval', array_filter( $stored['errors'], 'is_scalar' ) );
	}
	if ( isset( $stored['values'] ) && is_array( $stored['values'] ) ) {
		foreach ( $stored['values'] as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$state['values'][ $key ] = (string) $value;
			} elseif ( is_array( $value ) ) {
				// Chosen checkboxes.
				$state['values'][ $key ] = array_map( 'strval', array_filter( $value, 'is_scalar' ) );
			}
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
 * @param array $args button_text, success_text, id, form_id (0 = the theme's contact form).
 * @return array
 */
function goede_hosting_contact_form_data( $args ) {
	$args    = wp_parse_args(
		$args,
		array(
			'button_text'  => '',
			'success_text' => '',
			'id'           => 'contactformulier',
			'form_id'      => 0,
		)
	);
	$form_id = (int) $args['form_id'];
	$state   = goede_hosting_contact_state( $form_id );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
	$sent    = isset( $_GET['verzonden'] ) && '1' === sanitize_key( wp_unslash( $_GET['verzonden'] ) ) && goede_hosting_contact_redirect_form() === $form_id && ! $state['errors'] && '' === $state['general'];
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
	$prefix = $form_id ? 'gh-' . $form_id . '-' : 'gh-';
	foreach ( goede_hosting_form_fields_for( $form_id ) as $key => $field ) {
		$default = 'checkboxes' === $field['type'] ? array_values( array_intersect( $field['options'], array_map( 'trim', explode( ',', (string) $field['default'] ) ) ) ) : (string) $field['default'];

		$field['key']      = $key;
		$field['id']       = $prefix . $key;
		$field['error_id'] = $prefix . $key . '-fout';
		$field['value']    = isset( $state['values'][ $key ] ) ? $state['values'][ $key ] : $default;
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
	<?php if ( $form_id ) : ?>
		<input type="hidden" name="gh_formulier" value="<?php echo esc_attr( (string) $form_id ); ?>">
	<?php endif; ?>
	<input type="hidden" name="gh_tijd" value="<?php echo esc_attr( $stamp . '.' . goede_hosting_contact_stamp_hash( $stamp ) ); ?>">
	<?php wp_nonce_field( 'goedehosting_contact', 'gh_nonce' ); ?>
	<div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden">
		<label><?php esc_html_e( 'Laat dit veld leeg', 'goede-hosting' ); ?> <input type="text" name="gh_website" value="" tabindex="-1" autocomplete="off"></label>
	</div>
	<?php
	$hidden = (string) ob_get_clean();

	$id = sanitize_html_class( $args['id'] );
	if ( $notice ) {
		$notice['id'] = $id . '-melding';
		goede_hosting_contact_focus_notice( $notice['id'] );
	}

	return array(
		'id'          => $id,
		'action'      => admin_url( 'admin-post.php' ),
		'button_text' => '' !== trim( $args['button_text'] ) ? $args['button_text'] : __( 'Versturen', 'goede-hosting' ),
		'notice'      => $notice,
		'hidden'      => $hidden,
		'fields'      => $fields,
		'rows'        => array_values( $groups ),
		'privacy_url' => goede_hosting_contact_privacy_url(),
		'form_id'     => $form_id,
	);
}

/**
 * URL of the privacy statement shown under the form: the WordPress privacy page
 * (Instellingen > Privacy), or what the filter goede_hosting_contact_privacy_url
 * returns ('' = no link).
 *
 * @return string Unescaped URL.
 */
function goede_hosting_contact_privacy_url() {
	return (string) apply_filters( 'goede_hosting_contact_privacy_url', (string) get_privacy_policy_url() );
}

/**
 * After a redirect with a message: move the keyboard / screen reader focus to it,
 * so it is announced (a role="alert" that is already there at page load often
 * isn't). Printed in the footer; no script file needed.
 *
 * @param string $notice_id Id of the notice element.
 */
function goede_hosting_contact_focus_notice( $notice_id ) {
	add_action(
		'wp_footer',
		function () use ( $notice_id ) {
			// After "load": the browser's jump to the #anchor would otherwise reset the focus.
			wp_print_inline_script_tag( '(function(){function f(){var n=document.getElementById(' . wp_json_encode( $notice_id ) . ');if(n){n.focus({preventScroll:true});}}if(document.readyState==="complete"){f();}else{window.addEventListener("load",f);}})();' );
		},
		20
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
