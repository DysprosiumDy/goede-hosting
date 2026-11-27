<?php
/**
 * Automatic updates from GitHub Releases.
 *
 * WordPress asks the "Update URI" host (github.com) through the
 * update_plugins_github.com filter. The latest release's goede-hosting.zip
 * asset is the package. The answer is cached for six hours, because the GitHub
 * API allows 60 unauthenticated requests per hour per IP and many sites on one
 * server share an IP.
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/** GitHub repository that holds the releases. */
const GOEDE_HOSTING_REPO = 'DysprosiumDy/goede-hosting';

/**
 * Latest release: version and package URL, cached.
 *
 * @return array{version:string, package:string}|null Null when unknown.
 */
function goede_hosting_latest_release() {
	$cached = get_site_transient( 'goede_hosting_latest_release' );
	if ( is_array( $cached ) ) {
		return $cached['version'] ? $cached : null;
	}

	$release  = array(
		'version' => '',
		'package' => '',
	);
	$response = wp_remote_get(
		'https://api.github.com/repos/' . GOEDE_HOSTING_REPO . '/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'Goede-Hosting-WordPress-Plugin',
			),
		)
	);
	$ok       = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
	if ( $ok ) {
		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! empty( $data->tag_name ) && ! empty( $data->assets ) && is_array( $data->assets ) ) {
			foreach ( $data->assets as $asset ) {
				if ( isset( $asset->name, $asset->browser_download_url ) && 'goede-hosting.zip' === $asset->name ) {
					$release['version'] = ltrim( (string) $data->tag_name, 'v' );
					$release['package'] = (string) $asset->browser_download_url;
					break;
				}
			}
		}
	}
	// Failures are cached shorter, so a GitHub hiccup doesn't block updates for long.
	set_site_transient( 'goede_hosting_latest_release', $release, $ok ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $release['version'] ? $release : null;
}

/**
 * Answers WordPress' update check for this plugin.
 *
 * @param array|false $update      Update data so far.
 * @param array       $plugin_data Plugin headers.
 * @param string      $plugin_file Plugin file relative to the plugins dir.
 * @return array|false
 */
function goede_hosting_update_check( $update, $plugin_data, $plugin_file ) {
	unset( $plugin_data );
	if ( plugin_basename( GOEDE_HOSTING_DIR . 'goede-hosting.php' ) !== $plugin_file ) {
		return $update;
	}
	$release = goede_hosting_latest_release();
	return array(
		'slug'         => 'goede-hosting',
		'version'      => $release ? $release['version'] : GOEDE_HOSTING_VERSION,
		'url'          => 'https://github.com/' . GOEDE_HOSTING_REPO,
		'package'      => $release ? $release['package'] : '',
		'requires_php' => '8.5',
	);
}
add_filter( 'update_plugins_github.com', 'goede_hosting_update_check', 10, 3 );

/**
 * Forget the cached release when someone clicks "Check again" on Dashboard > Updates.
 */
function goede_hosting_forget_release() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only clears a cache.
	if ( isset( $_GET['force-check'] ) ) {
		delete_site_transient( 'goede_hosting_latest_release' );
	}
}
add_action( 'load-update-core.php', 'goede_hosting_forget_release' );
