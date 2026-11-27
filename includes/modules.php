<?php
/**
 * Module loader.
 *
 * Every feature is a module in includes/ that can be switched off per site:
 *
 *     add_filter( 'goede_hosting_modules', function ( $modules ) {
 *         return array_diff( $modules, array( 'whatsapp-button' ) );
 *     } );
 *
 * (in the theme's functions.php or a must-use plugin). Modules are loaded on
 * after_setup_theme, so a theme can switch them off and so a theme that still
 * ships its own copy of a feature (older Goede Hosting themes) is detected:
 * that module then stays off and the admin gets a notice.
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Known modules: file (in includes/), where it runs, and the function an older
 * theme defines when it still contains the same feature.
 *
 * @return array<string, array{file:string, admin_only:bool, legacy:string, label:string}>
 */
function goede_hosting_module_registry() {
	return array(
		'company'         => array(
			'file'       => 'company.php',
			'admin_only' => false,
			'legacy'     => 'goedehosting_company',
			'label'      => __( 'Bedrijfsgegevens', 'goede-hosting' ),
		),
		'contact-form'    => array(
			'file'       => 'contact-form/contact-form.php',
			'admin_only' => false,
			'legacy'     => 'goedehosting_contact_handle',
			'label'      => __( 'Contactformulier', 'goede-hosting' ),
		),
		'importer'        => array(
			'file'       => 'importer.php',
			'admin_only' => true,
			'legacy'     => 'goedehosting_importer_run',
			'label'      => __( 'Inhoud importeren', 'goede-hosting' ),
		),
		'whatsapp-button' => array(
			'file'       => 'whatsapp-button.php',
			'admin_only' => false,
			'legacy'     => 'goedehosting_whatsapp_button',
			'label'      => __( 'WhatsApp-knop', 'goede-hosting' ),
		),
	);
}

/**
 * Names of the modules switched on for this site (filter goede_hosting_modules).
 *
 * @return string[]
 */
function goede_hosting_enabled_modules() {
	$all     = array_keys( goede_hosting_module_registry() );
	$enabled = apply_filters( 'goede_hosting_modules', $all );
	return array_values( array_intersect( $all, is_array( $enabled ) ? $enabled : $all ) );
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
		if ( function_exists( $module['legacy'] ) ) {
			$GLOBALS['goede_hosting_legacy_modules'][] = $module['label'];
			continue;
		}
		if ( $module['admin_only'] && ! is_admin() && ! wp_doing_cron() ) {
			continue;
		}
		require_once GOEDE_HOSTING_DIR . 'includes/' . $module['file'];
		$GLOBALS['goede_hosting_loaded_modules'][] = $name;
	}
}
add_action( 'after_setup_theme', 'goede_hosting_load_modules', 1 );

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
}
