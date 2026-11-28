<?php
/**
 * Forms: field types. One place that knows, per type, how a field is
 * normalised, shown, read from the request, validated and stored, plus the
 * conditional logic (show/hide a field based on other answers), which runs the
 * same way in the browser (assets/js/forms.js) and here on the server.
 *
 * The server never trusts the browser: a submitted form is always validated
 * against the field definitions saved in WordPress (the form post or the
 * theme's filter), never against what the page sent along.
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field types: key => label (filter goede_hosting_form_field_types).
 *
 * @return array<string, string>
 */
function goede_hosting_form_field_types() {
	return (array) apply_filters(
		'goede_hosting_form_field_types',
		array(
			'text'       => __( 'Tekst (één regel)', 'goede-hosting' ),
			'textarea'   => __( 'Tekst (meerdere regels)', 'goede-hosting' ),
			'email'      => __( 'E-mailadres', 'goede-hosting' ),
			'tel'        => __( 'Telefoonnummer', 'goede-hosting' ),
			'url'        => __( 'Website (URL)', 'goede-hosting' ),
			'number'     => __( 'Getal', 'goede-hosting' ),
			'select'     => __( 'Keuzelijst', 'goede-hosting' ),
			'radio'      => __( 'Keuzerondjes (één keuze)', 'goede-hosting' ),
			'checkboxes' => __( 'Vinkjes (meerdere keuzes)', 'goede-hosting' ),
			'date'       => __( 'Datum', 'goede-hosting' ),
			'file'       => __( 'Bestand uploaden', 'goede-hosting' ),
			'consent'    => __( 'Toestemming (vinkje)', 'goede-hosting' ),
			'hidden'     => __( 'Verborgen veld', 'goede-hosting' ),
		)
	);
}

/**
 * Field types that hold one of a fixed list of options.
 *
 * @return string[]
 */
function goede_hosting_form_choice_types() {
	return array( 'select', 'radio', 'checkboxes' );
}

/**
 * Normalises a list of field definitions (from the theme's filter or a form
 * post). Unknown types become text, keys are made safe and unique, and every
 * field gets all properties.
 *
 * Properties: label, type, required, maxlength, autocomplete, placeholder,
 * summary, group, group_label, error, help, options (string[]), default, min,
 * max, step, width (full|half), accept (extensions), max_size (MB), rows,
 * logic (array: action show|hide, match all|any, rules [{field, op, value}]).
 *
 * @param array $fields key => definition.
 * @return array<string, array>
 */
function goede_hosting_form_normalize_fields( $fields ) {
	$types    = goede_hosting_form_field_types();
	$out      = array();
	$reserved = array( 'action', 'email_verzonden', 'velden', 'pagina', 'ontvanger', 'mail_verzonden', 'limiet', 'formulier', 'bestanden' );
	foreach ( is_array( $fields ) ? $fields : array() as $key => $field ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key || 0 === strpos( $key, 'gh_' ) || in_array( $key, $reserved, true ) || ! is_array( $field ) ) {
			continue;
		}
		$base = $key;
		for ( $n = 2; isset( $out[ $key ] ); $n++ ) {
			$key = $base . '-' . $n;
		}
		$field = wp_parse_args(
			$field,
			array(
				'label'        => $key,
				'type'         => 'text',
				'required'     => false,
				'maxlength'    => 0,
				'autocomplete' => '',
				'placeholder'  => '',
				'summary'      => false,
				'group'        => '',
				'group_label'  => '',
				'error'        => '',
				'help'         => '',
				'options'      => array(),
				'default'      => '',
				'min'          => '',
				'max'          => '',
				'step'         => '',
				'width'        => 'full',
				'accept'       => '',
				'max_size'     => 5,
				'rows'         => 6,
				'logic'        => array(),
			)
		);
		if ( ! isset( $types[ $field['type'] ] ) ) {
			$field['type'] = 'text';
		}
		$field['label']    = (string) $field['label'];
		$field['required'] = (bool) $field['required'] && 'hidden' !== $field['type'];
		$field['summary']  = (bool) $field['summary'];
		$field['width']    = 'half' === $field['width'] ? 'half' : 'full';
		$field['rows']     = max( 2, min( 20, (int) $field['rows'] ) );
		$field['max_size'] = max( 0.1, min( goede_hosting_form_max_upload_mb(), (float) $field['max_size'] ) );
		if ( (int) $field['maxlength'] <= 0 ) {
			$field['maxlength'] = 'textarea' === $field['type'] ? 5000 : ( 'email' === $field['type'] ? 254 : 500 );
		}
		$field['maxlength'] = max( 1, (int) $field['maxlength'] );

		$options = is_array( $field['options'] ) ? $field['options'] : preg_split( '/\r\n|\r|\n/', (string) $field['options'] );
		$options = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', $options ) ), 'strlen' ) ) );

		$field['options'] = in_array( $field['type'], goede_hosting_form_choice_types(), true ) ? array_slice( $options, 0, 100 ) : array();
		$field['logic']   = goede_hosting_form_normalize_logic( $field['logic'] );
		$out[ $key ]      = $field;
	}
	// Logic may only point at other fields of this form.
	foreach ( $out as $key => $field ) {
		if ( $field['logic'] ) {
			$out[ $key ]['logic']['rules'] = array_values(
				array_filter(
					$field['logic']['rules'],
					function ( $rule ) use ( $out, $key ) {
						return isset( $out[ $rule['field'] ] ) && $rule['field'] !== $key;
					}
				)
			);
			if ( ! $out[ $key ]['logic']['rules'] ) {
				$out[ $key ]['logic'] = array();
			}
		}
	}
	return $out;
}

/**
 * Largest upload the server accepts, in MB.
 *
 * @return float
 */
function goede_hosting_form_max_upload_mb() {
	return max( 0.1, round( wp_max_upload_size() / MB_IN_BYTES, 1 ) );
}

/**
 * Normalises conditional logic: array( action, match, rules ). Empty when there
 * are no usable rules.
 *
 * @param mixed $logic Raw logic.
 * @return array
 */
function goede_hosting_form_normalize_logic( $logic ) {
	if ( ! is_array( $logic ) || empty( $logic['rules'] ) || ! is_array( $logic['rules'] ) ) {
		return array();
	}
	$ops   = array_keys( goede_hosting_form_logic_operators() );
	$rules = array();
	foreach ( array_slice( $logic['rules'], 0, 10 ) as $rule ) {
		if ( ! is_array( $rule ) ) {
			continue;
		}
		$field = sanitize_key( (string) ( $rule['field'] ?? '' ) );
		$op    = (string) ( $rule['op'] ?? 'is' );
		if ( '' === $field || ! in_array( $op, $ops, true ) ) {
			continue;
		}
		$rules[] = array(
			'field' => $field,
			'op'    => $op,
			'value' => substr( trim( (string) ( $rule['value'] ?? '' ) ), 0, 200 ),
		);
	}
	if ( ! $rules ) {
		return array();
	}
	return array(
		'action' => isset( $logic['action'] ) && 'hide' === $logic['action'] ? 'hide' : 'show',
		'match'  => isset( $logic['match'] ) && 'any' === $logic['match'] ? 'any' : 'all',
		'rules'  => $rules,
	);
}

/**
 * Operators for conditional logic: key => label.
 *
 * @return array<string, string>
 */
function goede_hosting_form_logic_operators() {
	return array(
		'is'        => __( 'is', 'goede-hosting' ),
		'not'       => __( 'is niet', 'goede-hosting' ),
		'contains'  => __( 'bevat', 'goede-hosting' ),
		'empty'     => __( 'is leeg', 'goede-hosting' ),
		'not_empty' => __( 'is ingevuld', 'goede-hosting' ),
		'gt'        => __( 'is groter dan', 'goede-hosting' ),
		'lt'        => __( 'is kleiner dan', 'goede-hosting' ),
	);
}

/**
 * Whether one rule matches. Checkbox answers (arrays) match when any chosen
 * option matches; "is niet" matches when none does.
 *
 * @param array        $rule  Rule.
 * @param string|array $value Answer of the field the rule looks at.
 * @return bool
 */
function goede_hosting_form_rule_matches( $rule, $value ) {
	$values = array_map( 'strval', is_array( $value ) ? $value : ( '' === (string) $value ? array() : array( (string) $value ) ) );
	$target = (string) $rule['value'];
	$same   = function ( $a ) use ( $target ) {
		return 0 === strcasecmp( trim( $a ), trim( $target ) );
	};
	switch ( $rule['op'] ) {
		case 'empty':
			return ! $values;
		case 'not_empty':
			return (bool) $values;
		case 'not':
			return ! array_filter( $values, $same );
		case 'contains':
			foreach ( $values as $v ) {
				if ( '' !== $target && false !== stripos( $v, $target ) ) {
					return true;
				}
			}
			return false;
		case 'gt':
		case 'lt':
			if ( ! $values || ! is_numeric( str_replace( ',', '.', $values[0] ) ) || ! is_numeric( str_replace( ',', '.', $target ) ) ) {
				return false;
			}
			$a = (float) str_replace( ',', '.', $values[0] );
			$b = (float) str_replace( ',', '.', $target );
			return 'gt' === $rule['op'] ? $a > $b : $a < $b;
		default:
			return (bool) array_filter( $values, $same );
	}
}

/**
 * Which fields are visible for these answers. Runs in field order, so a field
 * that depends on a hidden field sees an empty answer (chains work).
 *
 * @param array $fields Normalised fields.
 * @param array $values key => answer.
 * @return array<string, bool> key => visible.
 */
function goede_hosting_form_visibility( $fields, $values ) {
	$visible  = array();
	$resolved = array();
	foreach ( $fields as $key => $field ) {
		$show = true;
		if ( $field['logic'] ) {
			$results = array();
			foreach ( $field['logic']['rules'] as $rule ) {
				$answer    = $resolved[ $rule['field'] ] ?? ( $values[ $rule['field'] ] ?? '' );
				$results[] = goede_hosting_form_rule_matches( $rule, $answer );
			}
			$match = 'any' === $field['logic']['match'] ? in_array( true, $results, true ) : ! in_array( false, $results, true );
			$show  = 'hide' === $field['logic']['action'] ? ! $match : $match;
		}
		$visible[ $key ]  = $show;
		$resolved[ $key ] = $show ? ( $values[ $key ] ?? '' ) : '';
	}
	return $visible;
}

/**
 * Reads and sanitises one field from the request. Returns a string, or an array
 * of chosen options for checkboxes. Files are handled in uploads.php.
 *
 * @param string $key   Field key.
 * @param array  $field Normalised field.
 * @return string|array
 */
function goede_hosting_form_read_value( $key, $field ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the handler verifies the nonce for every submission; values are only stored after that.
	if ( 'file' === $field['type'] ) {
		return '';
	}
	// A hidden field holds the value set in the builder; whatever the browser sends
	// instead is ignored (a visitor can change hidden inputs).
	if ( 'hidden' === $field['type'] ) {
		return sanitize_text_field( (string) $field['default'] );
	}
	if ( 'checkboxes' === $field['type'] ) {
		$raw = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST[ $key ] ) ) : array();
		// Only options that exist; in their own order.
		return array_values( array_intersect( $field['options'], array_map( 'trim', array_filter( $raw, 'is_string' ) ) ) );
	}
	if ( 'consent' === $field['type'] ) {
		return isset( $_POST[ $key ] ) && '1' === $_POST[ $key ] ? '1' : '';
	}
	$raw = '';
	if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
		$raw = 'textarea' === $field['type'] ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}
	// phpcs:enable
	// Submitted values are never run through do_shortcode() or the_content (a
	// WPForms CVE did that when repopulating a form); they are only ever escaped.
	$raw = trim( $raw );
	return function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, $field['maxlength'] ) : substr( $raw, 0, $field['maxlength'] );
}

/**
 * Validates one visible field. Returns an error message or ''.
 *
 * @param array        $field Normalised field.
 * @param string|array $value Sanitised value.
 * @return string
 */
function goede_hosting_form_validate_value( $field, $value ) {
	$empty = is_array( $value ) ? ! $value : '' === (string) $value;
	if ( $empty ) {
		if ( ! $field['required'] ) {
			return '';
		}
		if ( '' !== $field['error'] ) {
			return $field['error'];
		}
		if ( 'consent' === $field['type'] ) {
			return __( 'Vink dit aan om verder te gaan.', 'goede-hosting' );
		}
		if ( in_array( $field['type'], goede_hosting_form_choice_types(), true ) ) {
			return __( 'Maak een keuze.', 'goede-hosting' );
		}
		/* translators: %s: field label. */
		return sprintf( __( 'Vul je %s in.', 'goede-hosting' ), strtolower( $field['label'] ) );
	}
	switch ( $field['type'] ) {
		case 'email':
			return is_email( $value ) ? '' : __( 'Vul een geldig e-mailadres in.', 'goede-hosting' );
		case 'url':
			return wp_http_validate_url( $value ) ? '' : __( 'Vul een geldig webadres in (met https://).', 'goede-hosting' );
		case 'tel':
			return preg_match( '/^[0-9+()\s.\-]{6,30}$/', $value ) ? '' : __( 'Vul een geldig telefoonnummer in.', 'goede-hosting' );
		case 'number':
			$number = str_replace( ',', '.', $value );
			if ( ! is_numeric( $number ) ) {
				return __( 'Vul een getal in.', 'goede-hosting' );
			}
			if ( '' !== (string) $field['min'] && (float) $number < (float) $field['min'] ) {
				/* translators: %s: minimum. */
				return sprintf( __( 'Vul minimaal %s in.', 'goede-hosting' ), $field['min'] );
			}
			if ( '' !== (string) $field['max'] && (float) $number > (float) $field['max'] ) {
				/* translators: %s: maximum. */
				return sprintf( __( 'Vul maximaal %s in.', 'goede-hosting' ), $field['max'] );
			}
			return '';
		case 'date':
			$date = DateTime::createFromFormat( '!Y-m-d', $value );
			if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
				return __( 'Vul een geldige datum in.', 'goede-hosting' );
			}
			if ( '' !== (string) $field['min'] && $value < $field['min'] ) {
				return __( 'Deze datum is te vroeg.', 'goede-hosting' );
			}
			if ( '' !== (string) $field['max'] && $value > $field['max'] ) {
				return __( 'Deze datum is te laat.', 'goede-hosting' );
			}
			return '';
		case 'select':
		case 'radio':
			return in_array( $value, $field['options'], true ) ? '' : __( 'Kies een van de opties.', 'goede-hosting' );
	}
	return '';
}

/**
 * Value as stored and mailed: a string (chosen checkboxes on one line).
 *
 * @param array        $field Normalised field.
 * @param string|array $value Sanitised value.
 * @return string
 */
function goede_hosting_form_value_text( $field, $value ) {
	if ( is_array( $value ) ) {
		return implode( ', ', $value );
	}
	if ( 'consent' === $field['type'] ) {
		return '1' === $value ? __( 'Ja', 'goede-hosting' ) : '';
	}
	return (string) $value;
}

// -----------------------------------------------------------------------------
// Front end: controls
// -----------------------------------------------------------------------------

/**
 * Attribute string from an array (values escaped).
 *
 * @param array $attrs name => value; true for a boolean attribute, false/null to leave out.
 * @return string
 */
function goede_hosting_form_attrs( $attrs ) {
	$html = '';
	foreach ( $attrs as $name => $value ) {
		if ( false === $value || null === $value ) {
			continue;
		}
		$html .= true === $value ? ' ' . esc_attr( $name ) : ' ' . esc_attr( $name ) . '="' . esc_attr( (string) $value ) . '"';
	}
	return $html;
}

/**
 * The control of a field (input, textarea, select or option list). Labels of
 * options are escaped like every other text: nothing of a field is ever output
 * as raw HTML.
 *
 * @param array $field Field from the template data (key, id, value, error …).
 * @param array $attrs Extra attributes for the input / textarea / select.
 * @return string HTML.
 */
function goede_hosting_form_control( $field, $attrs = array() ) {
	$value     = $field['value'];
	$described = trim( ( '' !== $field['help'] ? $field['id'] . '-hulp ' : '' ) . ( '' !== $field['error'] ? $field['error_id'] : '' ) );
	$base      = array(
		'id'               => $field['id'],
		'name'             => $field['key'],
		'required'         => $field['required'],
		'aria-invalid'     => '' !== $field['error'] ? 'true' : false,
		'aria-describedby' => '' !== $described ? $described : false,
	);
	switch ( $field['type'] ) {
		case 'textarea':
			return '<textarea' . goede_hosting_form_attrs(
				array_merge(
					$base,
					array(
						'rows'        => $field['rows'],
						'maxlength'   => $field['maxlength'],
						'placeholder' => '' !== $field['placeholder'] ? $field['placeholder'] : false,
					),
					$attrs
				)
			) . '>' . esc_textarea( wp_specialchars_decode( (string) $value, ENT_QUOTES ) ) . '</textarea>';

		case 'select':
			$options = '<option value="">' . esc_html( '' !== $field['placeholder'] ? $field['placeholder'] : __( 'Maak een keuze', 'goede-hosting' ) ) . '</option>';
			foreach ( $field['options'] as $option ) {
				$options .= '<option value="' . esc_attr( $option ) . '"' . selected( (string) $value, $option, false ) . '>' . esc_html( $option ) . '</option>';
			}
			return '<select' . goede_hosting_form_attrs( array_merge( $base, $attrs ) ) . '>' . $options . '</select>';

		case 'radio':
		case 'checkboxes':
			$chosen = (array) $value;
			$list   = '';
			foreach ( $field['options'] as $i => $option ) {
				$list .= sprintf(
					'<label class="gh-choice"><input%s> <span>%s</span></label>',
					goede_hosting_form_attrs(
						array(
							'type'     => 'radio' === $field['type'] ? 'radio' : 'checkbox',
							'id'       => $field['id'] . '-' . $i,
							'name'     => 'radio' === $field['type'] ? $field['key'] : $field['key'] . '[]',
							'value'    => $option,
							'checked'  => in_array( $option, $chosen, true ),
							'required' => 'radio' === $field['type'] && $field['required'] && 0 === $i,
						)
					),
					esc_html( $option )
				);
			}
			return '<span class="gh-choices">' . $list . '</span>';

		case 'consent':
			return sprintf(
				'<label class="gh-choice gh-choice--consent"><input%s> <span>%s</span></label>',
				goede_hosting_form_attrs(
					array_merge(
						$base,
						array(
							'type'    => 'checkbox',
							'value'   => '1',
							'checked' => '1' === $value,
						)
					)
				),
				esc_html( '' !== $field['placeholder'] ? $field['placeholder'] : $field['label'] )
			);

		case 'file':
			$exts = goede_hosting_form_field_extensions( $field );
			return '<input' . goede_hosting_form_attrs(
				array_merge(
					$base,
					array(
						'type'          => 'file',
						'accept'        => '.' . implode( ',.', $exts ),
						'data-max-size' => (string) round( $field['max_size'] * MB_IN_BYTES ),
					),
					$attrs
				)
			) . '>';

		case 'hidden':
			return '<input' . goede_hosting_form_attrs(
				array(
					'type'  => 'hidden',
					'id'    => $field['id'],
					'name'  => $field['key'],
					'value' => (string) $value,
				)
			) . '>';
	}

	// text, email, tel, url, number, date.
	return '<input' . goede_hosting_form_attrs(
		array_merge(
			$base,
			array(
				'type'         => $field['type'],
				'value'        => (string) $value,
				'maxlength'    => in_array( $field['type'], array( 'number', 'date' ), true ) ? false : $field['maxlength'],
				'min'          => in_array( $field['type'], array( 'number', 'date' ), true ) && '' !== (string) $field['min'] ? $field['min'] : false,
				'max'          => in_array( $field['type'], array( 'number', 'date' ), true ) && '' !== (string) $field['max'] ? $field['max'] : false,
				'step'         => 'number' === $field['type'] ? ( '' !== (string) $field['step'] ? $field['step'] : 'any' ) : false,
				'inputmode'    => 'tel' === $field['type'] ? 'tel' : false,
				'autocomplete' => '' !== $field['autocomplete'] ? $field['autocomplete'] : false,
				'placeholder'  => '' !== $field['placeholder'] ? $field['placeholder'] : false,
			),
			$attrs
		)
	) . '>';
}
