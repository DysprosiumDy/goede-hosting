<?php
/**
 * Module system.
 *
 * Every feature is a module that can be switched on or off in the WordPress admin
 * (Goede Hosting > Modules). A module describes itself: label, description, the
 * file to load, the modules it needs and where its settings are. Other plugins
 * add their own modules the same way:
 *
 *     add_action( 'goede_hosting_register_modules', function () {
 *         goede_hosting_register_module( 'mijn-module', array(
 *             'label'       => 'Mijn module',
 *             'description' => 'Wat de module doet.',
 *             'file'        => __DIR__ . '/mijn-module.php',
 *             'requires'    => array( 'company' ),
 *             'settings'    => 'admin.php?page=mijn-module',
 *         ) );
 *     } );
 *
 * Code always has the last word: the filter goede_hosting_modules (theme
 * functions.php or a must-use plugin) can switch modules off; the admin page then
 * shows them as "uitgezet in code". Modules are loaded on after_setup_theme, so a
 * theme can use the filter, and a theme that still ships its own copy of a feature
 * (older Goede Hosting themes) is detected: that module stays off with a notice.
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/** Option with the modules switched off in the admin (array of names). */
const GOEDE_HOSTING_MODULES_OPTION = 'goede_hosting_modules_off';

/**
 * Registers a module (call on the goede_hosting_register_modules action).
 *
 * @param string $name Unique slug (lowercase, dashes).
 * @param array  $args label, description, file (absolute path, or relative to
 *                     this plugin's includes/), admin_only (bool), requires
 *                     (module names), legacy (function an older theme defines
 *                     when it still has the feature), settings (admin URL path),
 *                     default (bool, on for new sites; default true).
 */
function goede_hosting_register_module( $name, $args ) {
	$name = sanitize_key( $name );
	if ( '' === $name || ! is_array( $args ) ) {
		return;
	}
	$args = wp_parse_args(
		$args,
		array(
			'label'       => $name,
			'description' => '',
			'file'        => '',
			'admin_only'  => false,
			'requires'    => array(),
			'legacy'      => '',
			'settings'    => '',
			'default'     => true,
		)
	);
	if ( '' !== $args['file'] && ! path_is_absolute( $args['file'] ) ) {
		$args['file'] = GOEDE_HOSTING_DIR . 'includes/' . ltrim( $args['file'], '/' );
	}
	$args['requires'] = array_values( array_filter( array_map( 'sanitize_key', (array) $args['requires'] ) ) );

	$GLOBALS['goede_hosting_module_registry'][ $name ] = $args;
}

/**
 * The plugin's own modules.
 */
function goede_hosting_register_core_modules() {
	goede_hosting_register_module(
		'company',
		array(
			'label'       => __( 'Bedrijfsgegevens', 'goede-hosting' ),
			'description' => __( 'E-mail, telefoon, WhatsApp, adres en KvK op één plek, te gebruiken in blokken en shortcodes.', 'goede-hosting' ),
			'file'        => 'company.php',
			'legacy'      => 'goedehosting_company',
			'settings'    => 'options-general.php?page=goedehosting-bedrijfsgegevens',
		)
	);
	goede_hosting_register_module(
		'contact-form',
		array(
			'label'       => __( 'Formulieren', 'goede-hosting' ),
			'description' => __( 'Formulieren bouwen in de editor (velden, voorwaarden, meerdere stappen, bestanden), het contactformulier van het thema, en alle inzendingen.', 'goede-hosting' ),
			'file'        => 'contact-form/contact-form.php',
			'legacy'      => 'goedehosting_contact_handle',
			'settings'    => 'edit.php?post_type=gh_formulier',
		)
	);
	goede_hosting_register_module(
		'importer',
		array(
			'label'       => __( 'Inhoud importeren', 'goede-hosting' ),
			'description' => __( 'Een inhoudspakket (pagina\'s, afbeeldingen, bedrijfsgegevens) importeren via Weergave.', 'goede-hosting' ),
			'file'        => 'importer.php',
			'admin_only'  => true,
			'legacy'      => 'goedehosting_importer_run',
			'settings'    => 'themes.php?page=goedehosting-importeer',
		)
	);
	goede_hosting_register_module(
		'whatsapp-button',
		array(
			'label'       => __( 'WhatsApp-knop', 'goede-hosting' ),
			'description' => __( 'Zwevende WhatsApp-knop rechtsonder; aan te zetten in Bedrijfsgegevens.', 'goede-hosting' ),
			'file'        => 'whatsapp-button.php',
			'requires'    => array( 'company' ),
			'legacy'      => 'goedehosting_whatsapp_button',
			'settings'    => 'options-general.php?page=goedehosting-bedrijfsgegevens',
		)
	);
}
add_action( 'goede_hosting_register_modules', 'goede_hosting_register_core_modules', 0 );

/**
 * All registered modules (registers them on first use).
 *
 * @return array<string, array>
 */
function goede_hosting_module_registry() {
	if ( ! isset( $GLOBALS['goede_hosting_module_registry'] ) ) {
		$GLOBALS['goede_hosting_module_registry'] = array();
		do_action( 'goede_hosting_register_modules' );
	}
	return $GLOBALS['goede_hosting_module_registry'];
}

/**
 * Modules switched off in the admin.
 *
 * @return string[]
 */
function goede_hosting_modules_switched_off() {
	$off = get_option( GOEDE_HOSTING_MODULES_OPTION, null );
	if ( null === $off ) {
		// Never saved: modules with default false start off.
		$off = array_keys( wp_list_filter( goede_hosting_module_registry(), array( 'default' => false ) ) );
	}
	return array_values( array_map( 'strval', (array) $off ) );
}

/**
 * Modules switched off in code (filter goede_hosting_modules).
 *
 * @return string[]
 */
function goede_hosting_modules_locked_off() {
	$all     = array_keys( goede_hosting_module_registry() );
	$enabled = apply_filters( 'goede_hosting_modules', $all );
	return array_values( array_diff( $all, is_array( $enabled ) ? $enabled : $all ) );
}

/**
 * Names of the modules switched on (admin page and code), with their
 * requirements met. A module whose required module is off stays off too.
 *
 * @return string[]
 */
function goede_hosting_enabled_modules() {
	$registry = goede_hosting_module_registry();
	$on       = array_diff( array_keys( $registry ), goede_hosting_modules_switched_off(), goede_hosting_modules_locked_off() );
	// Drop modules whose requirements are not on (repeat for chains).
	$changed = true;
	while ( $changed ) {
		$kept    = array_filter(
			$on,
			function ( $name ) use ( $registry, $on ) {
				return ! array_diff( $registry[ $name ]['requires'], $on );
			}
		);
		$changed = count( $kept ) !== count( $on );
		$on      = $kept;
	}
	return array_values( $on );
}

/**
 * Whether a module is loaded on this request.
 *
 * @param string $name Module name.
 * @return bool
 */
function goede_hosting_module_active( $name ) {
	return in_array( $name, $GLOBALS['goede_hosting_loaded_modules'] ?? array(), true );
}

/**
 * Loads the enabled modules.
 */
function goede_hosting_load_modules() {
	$GLOBALS['goede_hosting_loaded_modules'] = array();
	$GLOBALS['goede_hosting_legacy_modules'] = array();
	$registry                                = goede_hosting_module_registry();
	foreach ( goede_hosting_enabled_modules() as $name ) {
		$module = $registry[ $name ];
		if ( '' !== $module['legacy'] && function_exists( $module['legacy'] ) ) {
			$GLOBALS['goede_hosting_legacy_modules'][] = $module['label'];
			continue;
		}
		if ( $module['admin_only'] && ! is_admin() && ! wp_doing_cron() ) {
			continue;
		}
		if ( '' === $module['file'] || ! is_readable( $module['file'] ) ) {
			continue;
		}
		require_once $module['file'];
		$GLOBALS['goede_hosting_loaded_modules'][] = $name;
		do_action( 'goede_hosting_module_loaded', $name );
	}
}
add_action( 'after_setup_theme', 'goede_hosting_load_modules', 1 );

// -----------------------------------------------------------------------------
// Admin: Goede Hosting > Modules
// -----------------------------------------------------------------------------

/**
 * Top-level menu "Goede Hosting" with the Modules page.
 */
function goede_hosting_modules_menu() {
	add_menu_page(
		__( 'Goede Hosting', 'goede-hosting' ),
		__( 'Goede Hosting', 'goede-hosting' ),
		'manage_options',
		'goede-hosting',
		'goede_hosting_modules_page',
		'dashicons-cloud',
		58
	);
	add_submenu_page( 'goede-hosting', __( 'Modules', 'goede-hosting' ), __( 'Modules', 'goede-hosting' ), 'manage_options', 'goede-hosting', 'goede_hosting_modules_page' );
}
add_action( 'admin_menu', 'goede_hosting_modules_menu', 9 );

/**
 * Saves the module switches (admin-post: capability + nonce).
 */
function goede_hosting_modules_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'goede-hosting' ), 403 );
	}
	check_admin_referer( 'goede_hosting_modules' );

	$registry = goede_hosting_module_registry();
	$names    = array_keys( $registry );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value goes through sanitize_key.
	$posted = isset( $_POST['gh_modules'] ) && is_array( $_POST['gh_modules'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['gh_modules'] ) ) : array();
	$on     = array_values( array_intersect( $names, $posted ) );
	$before = array_diff( $names, goede_hosting_modules_switched_off() );
	// A module that is switched on now brings along what it needs ...
	foreach ( array_diff( $on, $before ) as $name ) {
		$on = array_merge( $on, $registry[ $name ]['requires'] );
	}
	$on = array_unique( array_intersect( $names, $on ) );
	// ... and a module that is switched off takes the modules that need it along.
	$changed = true;
	while ( $changed ) {
		$kept    = array_filter(
			$on,
			function ( $name ) use ( $registry, $on ) {
				return ! array_diff( $registry[ $name ]['requires'], $on );
			}
		);
		$changed = count( $kept ) !== count( $on );
		$on      = $kept;
	}
	$off = array_values( array_diff( $names, $on ) );
	update_option( GOEDE_HOSTING_MODULES_OPTION, $off, true );

	wp_safe_redirect( add_query_arg( 'opgeslagen', '1', admin_url( 'admin.php?page=goede-hosting' ) ) );
	exit;
}
add_action( 'admin_post_goede_hosting_modules', 'goede_hosting_modules_save' );

/**
 * Status text of one module on the Modules page.
 *
 * @param string $name   Module name.
 * @param array  $module Module definition.
 * @return string
 */
function goede_hosting_module_status( $name, $module ) {
	if ( in_array( $name, goede_hosting_modules_locked_off(), true ) ) {
		return __( 'Uitgezet in code (thema of must-use plugin)', 'goede-hosting' );
	}
	if ( goede_hosting_module_active( $name ) ) {
		return __( 'Aan', 'goede-hosting' );
	}
	if ( in_array( $name, goede_hosting_enabled_modules(), true ) ) {
		if ( '' !== $module['legacy'] && function_exists( $module['legacy'] ) ) {
			return __( 'Uit: het thema bevat dit onderdeel zelf nog', 'goede-hosting' );
		}
		return __( 'Aan', 'goede-hosting' );
	}
	if ( ! in_array( $name, goede_hosting_modules_switched_off(), true ) ) {
		return __( 'Uit: een benodigde module staat uit', 'goede-hosting' );
	}
	return __( 'Uit', 'goede-hosting' );
}

/**
 * The Modules page.
 */
function goede_hosting_modules_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$registry = goede_hosting_module_registry();
	$off      = goede_hosting_modules_switched_off();
	$locked   = goede_hosting_modules_locked_off();
	$labels   = wp_list_pluck( $registry, 'label' );

	echo '<div class="wrap goede-hosting-modules"><h1>' . esc_html__( 'Goede Hosting: modules', 'goede-hosting' ) . '</h1>';
	echo '<p>' . esc_html__( 'Zet onderdelen aan of uit. Gegevens en instellingen van een module blijven bewaard als je hem uitzet.', 'goede-hosting' ) . '</p>';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag only.
	if ( isset( $_GET['opgeslagen'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Modules opgeslagen.', 'goede-hosting' ) . '</p></div>';
	}
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="goede_hosting_modules">';
	wp_nonce_field( 'goede_hosting_modules' );
	echo '<table class="widefat striped"><thead><tr><td class="check-column"></td><th>' . esc_html__( 'Module', 'goede-hosting' ) . '</th><th>' . esc_html__( 'Status', 'goede-hosting' ) . '</th><th></th></tr></thead><tbody>';
	foreach ( $registry as $name => $module ) {
		$is_locked = in_array( $name, $locked, true );
		$needs     = array();
		foreach ( $module['requires'] as $required ) {
			$needs[] = $labels[ $required ] ?? $required;
		}
		$settings = '' !== $module['settings'] && goede_hosting_module_active( $name )
			? '<a href="' . esc_url( admin_url( $module['settings'] ) ) . '">' . esc_html__( 'Instellingen', 'goede-hosting' ) . '</a>'
			: '';
		printf(
			'<tr><th scope="row" class="check-column"><input type="checkbox" id="gh-module-%1$s" name="gh_modules[]" value="%1$s"%2$s%3$s></th><td><label for="gh-module-%1$s"><strong>%4$s</strong></label><br><span class="description">%5$s</span>%6$s</td><td>%7$s</td><td>%8$s</td></tr>',
			esc_attr( $name ),
			checked( ! in_array( $name, $off, true ) && ! $is_locked, true, false ),
			disabled( $is_locked, true, false ),
			esc_html( $module['label'] ),
			esc_html( $module['description'] ),
			/* translators: %s: module names. */
			$needs ? '<br><span class="description">' . esc_html( sprintf( __( 'Heeft nodig: %s', 'goede-hosting' ), implode( ', ', $needs ) ) ) . '</span>' : '',
			esc_html( goede_hosting_module_status( $name, $module ) ),
			$settings // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		);
	}
	echo '</tbody></table>';
	submit_button( __( 'Modules opslaan', 'goede-hosting' ) );
	echo '</form></div>';
}

/**
 * Notice when the active theme still contains a feature of this plugin.
 */
function goede_hosting_legacy_notice() {
	$legacy = $GLOBALS['goede_hosting_legacy_modules'] ?? array();
	if ( ! $legacy || ! current_user_can( 'update_themes' ) ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: list of features. */
				__( 'Goede Hosting: het actieve thema bevat zelf nog %s. Die onderdelen van de plugin staan daarom uit. Werk het thema bij, dan neemt de plugin ze over (instellingen en inzendingen blijven bewaard).', 'goede-hosting' ),
				implode( ', ', $legacy )
			)
		)
	);
}
add_action( 'admin_notices', 'goede_hosting_legacy_notice' );

/**
 * Plugin activation: nothing to set up; every module schedules its own cron jobs
 * when they are missing.
 */
function goede_hosting_activate() {
	delete_site_transient( 'goede_hosting_latest_release' );
}

/**
 * Plugin deactivation: removes the cron jobs (data and settings stay).
 */
function goede_hosting_deactivate() {
	wp_clear_scheduled_hook( 'goedehosting_inzendingen_opruimen' );
	wp_clear_scheduled_hook( 'goedehosting_importer_cleanup' );
	do_action( 'goede_hosting_deactivate' );
}
