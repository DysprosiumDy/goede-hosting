<?php
/**
 * Plugin Name: Goede Hosting
 * Description: Basisfunctionaliteit voor WordPress sites gebouwd door Goede Hosting.
 * Version: 1.0.2
 * Requires PHP: 8.5
 * Update URI: https://github.com/DysprosiumDy/goede-hosting
 */

// Ideas: current year (footer)


if(!defined('ABSPATH'))
	exit;

// Current plugin version
define('GOEDE_HOSTING_VERSION', '1.0.5');

// Load CSS and JavaScript
add_action('wp_enqueue_scripts', function(){
	$base = plugin_dir_url( __FILE__ ).'assets/';
	
	wp_enqueue_style(
		'goede-hosting',
		$base.'css/core.css',
		array(),
		GOEDE_HOSTING_VERSION,
	);
	
	wp_enqueue_script(
		'goede-hosting',
		$base . 'js/core.js',
		array(),
		GOEDE_HOSTING_VERSION,
		true,
	);
} );

// New approach for auto-updates
add_filter('update_plugins_github.com', function ($update, $plugin_data, $plugin_file, $locales) {
	if (plugin_basename(__FILE__) !== $plugin_file)
		return $update;

	$version = GOEDE_HOSTING_VERSION;
	$package = '';

	$response = wp_remote_get(
		'https://api.github.com/repos/DysprosiumDy/goede-hosting/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'Goede-Hosting-WordPress-Plugin',
			),
		)
	);

	if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
		$release = json_decode(wp_remote_retrieve_body($response));

		if (!empty($release->tag_name) && !empty($release->assets)) {
			foreach ($release->assets as $asset) {
				if ('goede-hosting.zip' === $asset->name) {
					$package = $asset->browser_download_url;
					$version = ltrim($release->tag_name, 'v');
					break;
				}
			}
		}
	}

	return array(
		'slug'         => 'goede-hosting',
		'version'      => $version,
		'url'          => 'https://github.com/DysprosiumDy/goede-hosting',
		'package'      => $package,
		'requires_php' => '8.5',
	);
}, 10, 4);