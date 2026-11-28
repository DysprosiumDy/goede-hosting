<?php
/**
 * Plugin Name: Goede Hosting
 * Description: Basisfunctionaliteit voor WordPress sites gebouwd door Goede Hosting, in modules: bedrijfsgegevens, formulieren (bouwen in de editor) met inzendingen, inhoud importeren en een WhatsApp-knop.
 * Version: 1.2.0
 * Requires at least: 6.6
 * Requires PHP: 8.5
 * Update URI: https://github.com/DysprosiumDy/goede-hosting
 * Text Domain: goede-hosting
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/** Current plugin version (keep equal to the Version header above). */
define( 'GOEDE_HOSTING_VERSION', '1.2.0' );

/** Plugin directory and URL (with trailing slash). */
define( 'GOEDE_HOSTING_DIR', plugin_dir_path( __FILE__ ) );
define( 'GOEDE_HOSTING_URL', plugin_dir_url( __FILE__ ) );

require_once GOEDE_HOSTING_DIR . 'includes/helpers.php';
require_once GOEDE_HOSTING_DIR . 'includes/updater.php';
require_once GOEDE_HOSTING_DIR . 'includes/modules.php';

/**
 * Front-end base assets (scroll reveal helpers).
 */
function goede_hosting_enqueue_assets() {
	wp_enqueue_style( 'goede-hosting', GOEDE_HOSTING_URL . 'assets/css/core.css', array(), GOEDE_HOSTING_VERSION );
	wp_enqueue_script(
		'goede-hosting',
		GOEDE_HOSTING_URL . 'assets/js/core.js',
		array(),
		GOEDE_HOSTING_VERSION,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'goede_hosting_enqueue_assets' );

register_activation_hook( __FILE__, 'goede_hosting_activate' );
register_deactivation_hook( __FILE__, 'goede_hosting_deactivate' );
