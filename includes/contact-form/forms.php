<?php
/**
 * Forms: build forms in the block editor and place them anywhere.
 *
 *  - Post type gh_formulier (Goede Hosting > Formulieren). Its editor is the form
 *    builder: drag "Formulierveld" blocks in, set type, label, options and
 *    conditions in the sidebar; "Volgende stap" splits the form into steps;
 *    headings and paragraphs are shown as they are.
 *  - Block "Goede Hosting Formulier" (and [goedehosting_formulier id="…"]) shows a
 *    form on a page.
 *  - Form settings (sidebar, panel "Formulier"): button text, confirmation text
 *    and, for administrators, the recipient.
 *
 * The field definitions are read from the saved form post on every request and
 * every submission, so the browser can never add or change fields.
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/** Post type of forms. */
const GOEDE_HOSTING_FORMULIER = 'gh_formulier';

/**
 * Registers the post type, its settings and the blocks.
 */
function goede_hosting_forms_register() {
	register_post_type(
		GOEDE_HOSTING_FORMULIER,
		array(
			'labels'              => array(
				'name'               => __( 'Formulieren', 'goede-hosting' ),
				'singular_name'      => __( 'Formulier', 'goede-hosting' ),
				'menu_name'          => __( 'Formulieren', 'goede-hosting' ),
				'all_items'          => __( 'Formulieren', 'goede-hosting' ),
				'add_new'            => __( 'Nieuw formulier', 'goede-hosting' ),
				'add_new_item'       => __( 'Nieuw formulier', 'goede-hosting' ),
				'edit_item'          => __( 'Formulier bewerken', 'goede-hosting' ),
				'new_item'           => __( 'Nieuw formulier', 'goede-hosting' ),
				'search_items'       => __( 'Formulieren zoeken', 'goede-hosting' ),
				'not_found'          => __( 'Nog geen formulieren.', 'goede-hosting' ),
				'not_found_in_trash' => __( 'Geen formulieren in de prullenbak.', 'goede-hosting' ),
				'item_published'     => __( 'Formulier gepubliceerd.', 'goede-hosting' ),
				'item_updated'       => __( 'Formulier bijgewerkt.', 'goede-hosting' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => 'goede-hosting',
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => true,
			'rest_base'           => 'gh-formulieren',
			'supports'            => array( 'title', 'editor', 'revisions', 'custom-fields' ),
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'delete_with_user'    => false,
			// Editors and administrators (the same rights as for pages).
			'capability_type'     => 'page',
			'map_meta_cap'        => true,
			'template'            => goede_hosting_forms_default_template(),
		)
	);

	$meta = array(
		'gh_form_button'    => array( 'sanitize_text_field', 'edit_pages' ),
		'gh_form_success'   => array( 'sanitize_textarea_field', 'edit_pages' ),
		// The recipient decides where mail goes: administrators only.
		'gh_form_recipient' => array( 'sanitize_email', 'manage_options' ),
	);
	foreach ( $meta as $key => $def ) {
		register_post_meta(
			GOEDE_HOSTING_FORMULIER,
			$key,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => $def[0],
				'auth_callback'     => function () use ( $def ) {
					return current_user_can( $def[1] );
				},
			)
		);
	}

	$version = GOEDE_HOSTING_VERSION;
	wp_register_style( 'goede-hosting-forms', GOEDE_HOSTING_URL . 'assets/css/forms.css', array(), $version );
	wp_register_script( 'goede-hosting-forms', GOEDE_HOSTING_URL . 'assets/js/forms.js', array(), $version, array( 'in_footer' => true ) );
	wp_register_script(
		'goede-hosting-forms-editor',
		GOEDE_HOSTING_URL . 'assets/js/forms-editor.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-core-data', 'wp-server-side-render', 'wp-i18n', 'wp-plugins', 'wp-editor' ),
		$version,
		true
	);
	wp_add_inline_script(
		'goede-hosting-forms-editor',
		'window.goedeHostingForms = ' . wp_json_encode(
			array(
				'types'      => goede_hosting_form_field_types(),
				'operators'  => goede_hosting_form_logic_operators(),
				'extensions' => goede_hosting_form_allowed_extensions(),
				'maxUpload'  => goede_hosting_form_max_upload_mb(),
				'canMail'    => current_user_can( 'manage_options' ),
				'postType'   => GOEDE_HOSTING_FORMULIER,
				'newUrl'     => admin_url( 'post-new.php?post_type=' . GOEDE_HOSTING_FORMULIER ),
			)
		) . ';',
		'before'
	);

	$field_attrs = array(
		'type'        => array(
			'type'    => 'string',
			'default' => 'text',
		),
		'label'       => array(
			'type'    => 'string',
			'default' => '',
		),
		'key'         => array(
			'type'    => 'string',
			'default' => '',
		),
		'required'    => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'placeholder' => array(
			'type'    => 'string',
			'default' => '',
		),
		'help'        => array(
			'type'    => 'string',
			'default' => '',
		),
		'options'     => array(
			'type'    => 'string',
			'default' => '',
		),
		'default'     => array(
			'type'    => 'string',
			'default' => '',
		),
		'min'         => array(
			'type'    => 'string',
			'default' => '',
		),
		'max'         => array(
			'type'    => 'string',
			'default' => '',
		),
		'width'       => array(
			'type'    => 'string',
			'default' => 'full',
		),
		'accept'      => array(
			'type'    => 'string',
			'default' => '',
		),
		'maxSize'     => array(
			'type'    => 'number',
			'default' => 5,
		),
		'summary'     => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'logic'       => array(
			'type'    => 'object',
			'default' => array(),
		),
	);
	register_block_type(
		'goede-hosting/veld',
		array(
			'api_version'     => 3,
			'title'           => __( 'Formulierveld', 'goede-hosting' ),
			'description'     => __( 'Eén veld van een formulier: tekst, e-mail, keuzes, datum, bestand, toestemming …', 'goede-hosting' ),
			'category'        => 'widgets',
			'icon'            => 'feedback',
			'editor_script'   => 'goede-hosting-forms-editor',
			'editor_style'    => 'goede-hosting-forms',
			'attributes'      => $field_attrs,
			'supports'        => array(
				'html'     => false,
				'reusable' => false,
			),
			'render_callback' => '__return_empty_string',
		)
	);
	register_block_type(
		'goede-hosting/stap',
		array(
			'api_version'     => 3,
			'title'           => __( 'Volgende stap', 'goede-hosting' ),
			'description'     => __( 'Splitst het formulier: de velden hierna komen op een volgende stap.', 'goede-hosting' ),
			'category'        => 'widgets',
			'icon'            => 'controls-forward',
			'editor_script'   => 'goede-hosting-forms-editor',
			'editor_style'    => 'goede-hosting-forms',
			'attributes'      => array(
				'label' => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			'supports'        => array(
				'html'     => false,
				'reusable' => false,
			),
			'render_callback' => '__return_empty_string',
		)
	);
	register_block_type(
		'goede-hosting/formulier',
		array(
			'api_version'     => 3,
			'title'           => __( 'Goede Hosting Formulier', 'goede-hosting' ),
			'description'     => __( 'Toont een formulier uit Goede Hosting > Formulieren.', 'goede-hosting' ),
			'category'        => 'widgets',
			'icon'            => 'forms',
			'editor_script'   => 'goede-hosting-forms-editor',
			'editor_style'    => 'goede-hosting-forms',
			'attributes'      => array(
				'formId' => array(
					'type'    => 'number',
					'default' => 0,
				),
			),
			'supports'        => array( 'html' => false ),
			'render_callback' => 'goede_hosting_forms_render_block',
		)
	);
	add_shortcode( 'goedehosting_formulier', 'goede_hosting_forms_shortcode' );
}
add_action( 'init', 'goede_hosting_forms_register' );

/**
 * Starting fields of a new form.
 *
 * @return array Block template.
 */
function goede_hosting_forms_default_template() {
	return array(
		array(
			'goede-hosting/veld',
			array(
				'type'     => 'text',
				'label'    => __( 'Naam', 'goede-hosting' ),
				'key'      => 'naam',
				'required' => true,
				'summary'  => false,
			),
		),
		array(
			'goede-hosting/veld',
			array(
				'type'     => 'email',
				'label'    => __( 'E-mail', 'goede-hosting' ),
				'key'      => 'email',
				'required' => true,
			),
		),
		array(
			'goede-hosting/veld',
			array(
				'type'     => 'textarea',
				'label'    => __( 'Bericht', 'goede-hosting' ),
				'key'      => 'bericht',
				'required' => true,
			),
		),
	);
}

/**
 * In the form editor only form blocks, headings, paragraphs and separators.
 *
 * @param bool|string[]           $allowed Allowed block names.
 * @param WP_Block_Editor_Context $context Editor context.
 * @return bool|string[]
 */
function goede_hosting_forms_allowed_blocks( $allowed, $context ) {
	$in_form = isset( $context->post ) && $context->post instanceof WP_Post && GOEDE_HOSTING_FORMULIER === $context->post->post_type;
	if ( $in_form ) {
		return array( 'goede-hosting/veld', 'goede-hosting/stap', 'core/heading', 'core/paragraph', 'core/separator', 'core/list', 'core/list-item' );
	}
	// Elsewhere (pages, the site editor) the field and step blocks are not offered.
	if ( true === $allowed ) {
		$allowed = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
	}
	return is_array( $allowed ) ? array_values( array_diff( $allowed, array( 'goede-hosting/veld', 'goede-hosting/stap' ) ) ) : $allowed;
}
add_filter( 'allowed_block_types_all', 'goede_hosting_forms_allowed_blocks', 10, 2 );

/**
 * The field blocks only make sense inside a form.
 */
function goede_hosting_forms_editor_assets() {
	wp_enqueue_script( 'goede-hosting-forms-editor' );
	wp_enqueue_style( 'goede-hosting-forms' );
}
add_action( 'enqueue_block_editor_assets', 'goede_hosting_forms_editor_assets' );

// -----------------------------------------------------------------------------
// Reading a form
// -----------------------------------------------------------------------------

/**
 * A form post that can be shown and submitted (published, our post type).
 *
 * @param int $form_id Post ID.
 * @return WP_Post|null
 */
function goede_hosting_form_post( $form_id ) {
	$post = $form_id ? get_post( (int) $form_id ) : null;
	return $post instanceof WP_Post && GOEDE_HOSTING_FORMULIER === $post->post_type && 'publish' === $post->post_status ? $post : null;
}

/**
 * Items of a form in order: fields, steps and text blocks.
 *
 * @param int $form_id Form post ID.
 * @return array{fields: array<string, array>, items: array} items: array( kind field|step|html, key|label|html ).
 */
function goede_hosting_form_structure( $form_id ) {
	static $cache = array();
	$form_id      = (int) $form_id;
	if ( isset( $cache[ $form_id ] ) ) {
		return $cache[ $form_id ];
	}
	$post   = goede_hosting_form_post( $form_id );
	$raw    = array();
	$items  = array();
	$blocks = $post ? parse_blocks( $post->post_content ) : array();
	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? '';
		$a    = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		if ( 'goede-hosting/veld' === $name ) {
			$label = trim( (string) ( $a['label'] ?? '' ) );
			$key   = sanitize_key( (string) ( $a['key'] ?? '' ) );
			if ( '' === $key ) {
				$key = sanitize_key( remove_accents( $label ) );
			}
			if ( '' === $key ) {
				$key = 'veld';
			}
			$base = $key;
			for ( $n = 2; isset( $raw[ $key ] ); $n++ ) {
				$key = $base . '-' . $n;
			}
			$type        = (string) ( $a['type'] ?? 'text' );
			$raw[ $key ] = array(
				'label'        => '' !== $label ? $label : $key,
				'type'         => $type,
				'required'     => ! empty( $a['required'] ),
				'placeholder'  => (string) ( $a['placeholder'] ?? '' ),
				'help'         => (string) ( $a['help'] ?? '' ),
				'options'      => (string) ( $a['options'] ?? '' ),
				'default'      => (string) ( $a['default'] ?? '' ),
				'min'          => (string) ( $a['min'] ?? '' ),
				'max'          => (string) ( $a['max'] ?? '' ),
				'width'        => (string) ( $a['width'] ?? 'full' ),
				'accept'       => (string) ( $a['accept'] ?? '' ),
				'max_size'     => isset( $a['maxSize'] ) ? (float) $a['maxSize'] : 5,
				'summary'      => ! empty( $a['summary'] ),
				'logic'        => is_array( $a['logic'] ?? null ) ? $a['logic'] : array(),
				'autocomplete' => goede_hosting_form_guess_autocomplete( $type, $key ),
			);
			$items[]     = array(
				'kind' => 'field',
				'key'  => $key,
			);
		} elseif ( 'goede-hosting/stap' === $name ) {
			$items[] = array(
				'kind'  => 'step',
				'label' => (string) ( $a['label'] ?? '' ),
			);
		} elseif ( in_array( $name, array( 'core/heading', 'core/paragraph', 'core/separator', 'core/list' ), true ) ) {
			// Written by editors (edit_pages); still limited to post HTML.
			$items[] = array(
				'kind' => 'html',
				'html' => wp_kses_post( render_block( $block ) ),
			);
		}
	}
	$fields = goede_hosting_form_normalize_fields( $raw );
	// Drop items of fields the normaliser refused.
	$items             = array_values(
		array_filter(
			$items,
			function ( $item ) use ( $fields ) {
				return 'field' !== $item['kind'] || isset( $fields[ $item['key'] ] );
			}
		)
	);
	$cache[ $form_id ] = array(
		'fields' => $fields,
		'items'  => $items,
	);
	return $cache[ $form_id ];
}

/**
 * Normalised fields of a form.
 *
 * @param int $form_id Form post ID.
 * @return array<string, array>
 */
function goede_hosting_form_fields( $form_id ) {
	return goede_hosting_form_structure( $form_id )['fields'];
}

/**
 * Autocomplete hint for common fields (helps people fill in forms quickly).
 *
 * @param string $type Field type.
 * @param string $key  Field key.
 * @return string
 */
function goede_hosting_form_guess_autocomplete( $type, $key ) {
	if ( 'email' === $type ) {
		return 'email';
	}
	if ( 'tel' === $type ) {
		return 'tel';
	}
	if ( 'url' === $type ) {
		return 'url';
	}
	$map = array(
		'naam'       => 'name',
		'voornaam'   => 'given-name',
		'achternaam' => 'family-name',
		'bedrijf'    => 'organization',
		'postcode'   => 'postal-code',
		'plaats'     => 'address-level2',
		'woonplaats' => 'address-level2',
		'adres'      => 'street-address',
	);
	return 'text' === $type && isset( $map[ $key ] ) ? $map[ $key ] : '';
}

/**
 * Settings of a form.
 *
 * @param int $form_id Form post ID.
 * @return array{button: string, success: string, recipient: string}
 */
function goede_hosting_form_settings( $form_id ) {
	return array(
		'button'    => (string) get_post_meta( $form_id, 'gh_form_button', true ),
		'success'   => (string) get_post_meta( $form_id, 'gh_form_success', true ),
		'recipient' => sanitize_email( (string) get_post_meta( $form_id, 'gh_form_recipient', true ) ),
	);
}

// -----------------------------------------------------------------------------
// Showing a form
// -----------------------------------------------------------------------------

/**
 * Block render callback.
 *
 * @param array $attributes Block attributes.
 * @return string
 */
function goede_hosting_forms_render_block( $attributes ) {
	return goede_hosting_form( isset( $attributes['formId'] ) ? (int) $attributes['formId'] : 0 );
}

/**
 * Shortcode [goedehosting_formulier id="123"].
 *
 * @param array $atts Attributes.
 * @return string
 */
function goede_hosting_forms_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'goedehosting_formulier' );
	return goede_hosting_form( absint( $atts['id'] ) );
}

/**
 * HTML of a form (template goede-hosting/form.php in the theme, else the plugin's).
 *
 * @param int $form_id Form post ID.
 * @return string
 */
function goede_hosting_form( $form_id ) {
	$form = goede_hosting_form_post( $form_id );
	if ( ! $form ) {
		return current_user_can( 'edit_pages' ) ? '<p class="gh-form__missing">' . esc_html__( 'Kies een gepubliceerd formulier in de blokinstellingen.', 'goede-hosting' ) . '</p>' : '';
	}
	$structure = goede_hosting_form_structure( $form->ID );
	if ( ! $structure['fields'] ) {
		return '';
	}
	$settings = goede_hosting_form_settings( $form->ID );
	$data     = goede_hosting_contact_form_data(
		array(
			'button_text'  => $settings['button'],
			'success_text' => $settings['success'],
			'id'           => 'formulier-' . $form->ID,
			'form_id'      => $form->ID,
		)
	);

	// Steps: the items between "Volgende stap" blocks.
	$steps = array(
		array(
			'label' => '',
			'items' => array(),
		),
	);
	foreach ( $structure['items'] as $item ) {
		if ( 'step' === $item['kind'] ) {
			if ( $steps[ count( $steps ) - 1 ]['items'] ) {
				$steps[] = array(
					'label' => $item['label'],
					'items' => array(),
				);
			}
			continue;
		}
		$steps[ count( $steps ) - 1 ]['items'][] = $item;
	}
	$data['steps']     = $steps;
	$data['has_files'] = (bool) wp_list_filter( $structure['fields'], array( 'type' => 'file' ) );
	$data['logic']     = array_filter( wp_list_pluck( $structure['fields'], 'logic' ) );

	if ( apply_filters( 'goede_hosting_form_css', true, $form->ID ) ) {
		wp_enqueue_style( 'goede-hosting-forms' );
	}
	wp_enqueue_script( 'goede-hosting-forms' );
	return goede_hosting_render_template( 'form.php', $data );
}
