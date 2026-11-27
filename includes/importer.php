<?php
/**
 * Module "importer": Weergave > Goede Hosting Importeer Inhoud (admin only).
 *
 * Imports a content package (a zip with manifest.json, pages/*.html and media/*;
 * format: see the README of the plugin) in two steps:
 *
 * 1. Upload: the zip is checked (ZipArchive, size limit, extension whitelist,
 *    no path traversal) and unpacked entry by entry into a private temp dir
 *    in the system temp dir, outside the web root (fallback: uploads; random
 *    name, web access denied). A daily cron job removes abandoned dirs. A per-user transient
 *    (1 hour) points to that dir. The preview shows per page whether it is
 *    created, updated or was changed by hand since the last import, and per
 *    media file whether it is new or already in the media library.
 * 2. Import: media go into the media library once (matched by sha1 in the
 *    meta _goedehosting_import_hash), {{media:…}} / {{media-id:…}} tokens in
 *    the block markup are replaced, pages are created/updated by path
 *    (parent slug + slug), the front page is set and the company details are
 *    merged. The temp dir is deleted afterwards.
 *
 * Nothing from the package is ever executed or included.
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;


/*
 * Two documented exceptions to the coding standard for this file:
 * - Plain PHP file functions: they only touch our own private temp dir (and read
 *   the uploaded zip). WP_Filesystem may need FTP credentials, which this screen
 *   can't ask for halfway through an import.
 * - camelCase properties: those are native ZipArchive / DOMDocument properties.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

if ( ! defined( 'GOEDE_HOSTING_IMPORT_MAX_BYTES' ) ) {
	define( 'GOEDE_HOSTING_IMPORT_MAX_BYTES', 20 * 1024 * 1024 );
}

/**
 * Admin page slug.
 *
 * @return string
 */
function goede_hosting_importer_slug() {
	return 'goedehosting-importeer';
}

/**
 * URL of the importer screen.
 *
 * @param array $args Extra query arguments.
 * @return string
 */
function goede_hosting_importer_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => goede_hosting_importer_slug() ), $args ), admin_url( 'themes.php' ) );
}

/**
 * Whether the current user may use the importer.
 *
 * @return bool
 */
function goede_hosting_importer_allowed() {
	return current_user_can( 'edit_theme_options' ) && current_user_can( 'upload_files' );
}

/**
 * Registers Weergave > Goede Hosting Importeer Inhoud.
 */
function goede_hosting_importer_menu() {
	if ( ! goede_hosting_importer_allowed() ) {
		return;
	}
	$hook = add_theme_page(
		__( 'Goede Hosting Importeer Inhoud', 'goede-hosting' ),
		__( 'Goede Hosting Importeer Inhoud', 'goede-hosting' ),
		'edit_theme_options',
		goede_hosting_importer_slug(),
		'goede_hosting_importer_render'
	);
	if ( $hook ) {
		add_action( 'load-' . $hook, 'goede_hosting_importer_load' );
	}
}
add_action( 'admin_menu', 'goede_hosting_importer_menu' );

// -----------------------------------------------------------------------------
// Per-user state (transients)
// -----------------------------------------------------------------------------

/**
 * Transient name for this user.
 *
 * @param string $what state, notice or result.
 * @return string
 */
function goede_hosting_importer_key( $what ) {
	return 'goedehosting_import_' . $what . '_' . get_current_user_id();
}

/**
 * Queues an admin notice for the next page view.
 *
 * @param string $type    error, warning, success or info.
 * @param string $message Plain text message.
 */
function goede_hosting_importer_notice( $type, $message ) {
	$notices   = get_transient( goede_hosting_importer_key( 'notice' ) );
	$notices   = is_array( $notices ) ? $notices : array();
	$notices[] = array(
		'type'    => $type,
		'message' => $message,
	);
	set_transient( goede_hosting_importer_key( 'notice' ), $notices, 10 * MINUTE_IN_SECONDS );
}

/**
 * Current upload state of this user, or null.
 *
 * @return array|null
 */
function goede_hosting_importer_state() {
	$state = get_transient( goede_hosting_importer_key( 'state' ) );
	if ( ! is_array( $state ) || empty( $state['dir'] ) || empty( $state['token'] ) ) {
		return null;
	}
	$dir = goede_hosting_importer_dir_path( $state['dir'] );
	if ( '' === $dir || ! is_dir( $dir ) ) {
		delete_transient( goede_hosting_importer_key( 'state' ) );
		return null;
	}
	$state['path'] = $dir;
	return $state;
}

/**
 * Drops the upload state of this user and deletes its temp dir.
 */
function goede_hosting_importer_forget() {
	$state = get_transient( goede_hosting_importer_key( 'state' ) );
	if ( is_array( $state ) && ! empty( $state['dir'] ) ) {
		goede_hosting_importer_rmdir( goede_hosting_importer_dir_path( $state['dir'] ) );
	}
	delete_transient( goede_hosting_importer_key( 'state' ) );
}

// -----------------------------------------------------------------------------
// Temp dir (system temp, fallback uploads)
// -----------------------------------------------------------------------------

/**
 * Possible base dirs (no trailing slash): the system temp dir first (outside the
 * web root, so the raw package files are never reachable by URL), then uploads.
 *
 * @return string[]
 */
function goede_hosting_importer_bases() {
	$bases = array();
	$temp  = get_temp_dir();
	if ( $temp ) {
		$bases[] = untrailingslashit( wp_normalize_path( $temp ) ) . '/goedehosting-import';
	}
	$uploads = wp_upload_dir( null, false );
	if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
		$bases[] = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
	}
	return $bases;
}

/**
 * Base dir for new temp dirs (the first usable one), or '' when none is writable.
 *
 * @return string
 */
function goede_hosting_importer_base() {
	static $base = null;
	if ( null === $base ) {
		$base = '';
		foreach ( goede_hosting_importer_bases() as $dir ) {
			if ( ( is_dir( $dir ) || wp_mkdir_p( $dir ) ) && wp_is_writable( $dir ) ) {
				$base = $dir;
				break;
			}
		}
	}
	return $base;
}

/**
 * Absolute path of a temp dir by its name, or '' when the name is not one of ours.
 *
 * @param string $name Dir name.
 * @return string
 */
function goede_hosting_importer_dir_path( $name ) {
	$base = goede_hosting_importer_base();
	if ( '' === $base || ! is_string( $name ) || ! preg_match( '/^goedehosting-import-[a-z0-9]{24}$/', $name ) ) {
		return '';
	}
	return $base . '/' . $name;
}

/**
 * Creates a new private temp dir. Returns its name or a WP_Error.
 *
 * @return string|WP_Error
 */
function goede_hosting_importer_make_dir() {
	$base = goede_hosting_importer_base();
	if ( '' !== $base && ! is_dir( $base ) ) {
		wp_mkdir_p( $base );
	}
	if ( '' === $base || ! wp_is_writable( $base ) ) {
		return new WP_Error( 'goedehosting_import_dir', __( 'De uploadmap is niet beschrijfbaar; het pakket kan niet worden uitgepakt.', 'goede-hosting' ) );
	}
	$name = 'goedehosting-import-' . strtolower( wp_generate_password( 24, false, false ) );
	$path = $base . '/' . $name;
	if ( ! wp_mkdir_p( $path . '/pages' ) || ! wp_mkdir_p( $path . '/media' ) ) {
		return new WP_Error( 'goedehosting_import_dir', __( 'De tijdelijke map voor het pakket kon niet worden aangemaakt.', 'goede-hosting' ) );
	}
	// Deny web access (Apache 2.2 and 2.4); index.php against directory listings elsewhere.
	$htaccess = "# Goede Hosting importer: temporary, private\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";
	file_put_contents( $path . '/.htaccess', $htaccess );
	file_put_contents( $path . '/index.php', "<?php\n// Silence is golden.\n" );
	return $name;
}

/**
 * Deletes one of our temp dirs recursively. Refuses anything else.
 *
 * @param string $dir Absolute path.
 */
function goede_hosting_importer_rmdir( $dir ) {
	if ( ! is_string( $dir ) || '' === $dir ) {
		return;
	}
	$dir = wp_normalize_path( $dir );
	if ( ! in_array( dirname( $dir ), goede_hosting_importer_bases(), true ) || ! preg_match( '/^goedehosting-import-[a-z0-9]{24}$/', basename( $dir ) ) || ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() && ! $item->isLink() ) {
			rmdir( $item->getPathname() );
		} else {
			wp_delete_file( $item->getPathname() );
		}
	}
	rmdir( $dir );
}

/**
 * Deletes temp dirs older than an hour (their transient has expired).
 */
function goede_hosting_importer_cleanup_stale() {
	foreach ( goede_hosting_importer_bases() as $base ) {
		$dirs = is_dir( $base ) ? glob( $base . '/goedehosting-import-*', GLOB_ONLYDIR ) : false;
		if ( ! is_array( $dirs ) ) {
			continue;
		}
		foreach ( $dirs as $dir ) {
			$time = filemtime( $dir );
			if ( false !== $time && $time < time() - HOUR_IN_SECONDS ) {
				goede_hosting_importer_rmdir( $dir );
			}
		}
	}
}
add_action( 'goedehosting_importer_cleanup', 'goede_hosting_importer_cleanup_stale' );

/**
 * Schedules the daily cleanup of abandoned temp dirs.
 */
function goede_hosting_importer_schedule_cleanup() {
	if ( ! wp_next_scheduled( 'goedehosting_importer_cleanup' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'goedehosting_importer_cleanup' );
	}
}
add_action( 'admin_init', 'goede_hosting_importer_schedule_cleanup' );

// -----------------------------------------------------------------------------
// Unpacking and validating
// -----------------------------------------------------------------------------

/**
 * Allowed media extensions => mime types.
 *
 * @return array
 */
function goede_hosting_importer_media_types() {
	return array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'svg'  => 'image/svg+xml',
	);
}

/**
 * Whether a package file name (without folder) is acceptable:
 * one dot, safe characters, whitelisted extension.
 *
 * @param string $name   File name.
 * @param array  $exts   Allowed extensions (lowercase).
 * @return bool
 */
function goede_hosting_importer_valid_name( $name, $exts ) {
	if ( ! is_string( $name ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}\.([A-Za-z0-9]{2,5})$/', $name, $m ) ) {
		return false;
	}
	return in_array( strtolower( $m[1] ), $exts, true );
}

/**
 * Unpacks the zip entry by entry into the temp dir (never extractTo()).
 *
 * @param string $zip_file Uploaded zip.
 * @param string $dir      Temp dir.
 * @return true|WP_Error
 */
function goede_hosting_importer_unpack( $zip_file, $dir ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'goedehosting_import_zip', __( 'De PHP-extensie ZipArchive (zip) ontbreekt op deze server, dus het pakket kan niet worden gelezen. Vraag je hostingpartij om de zip-extensie van PHP aan te zetten.', 'goede-hosting' ) );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_file ) ) {
		return new WP_Error( 'goedehosting_import_zip', __( 'Het bestand is geen geldig zip-bestand.', 'goede-hosting' ) );
	}
	if ( $zip->numFiles < 1 || $zip->numFiles > 500 ) {
		$zip->close();
		return new WP_Error( 'goedehosting_import_zip', __( 'Het pakket bevat geen of te veel bestanden.', 'goede-hosting' ) );
	}

	$media_exts = array_keys( goede_hosting_importer_media_types() );
	$entries    = array();
	$seen       = array();
	$total      = 0;
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$stat = $zip->statIndex( $i );
		$name = is_array( $stat ) && isset( $stat['name'] ) ? (string) $stat['name'] : '';
		// Path traversal and odd names: absolute paths, drive letters, backslashes, NUL, "." or ".." segments.
		if ( '' === $name || false !== strpos( $name, "\0" ) || false !== strpos( $name, '\\' ) || '/' === $name[0]
			|| preg_match( '#^[A-Za-z]:#', $name ) || preg_match( '#(^|/)\.{1,2}(/|$)#', $name ) ) {
			$zip->close();
			/* translators: %s: file name inside the zip */
			return new WP_Error( 'goedehosting_import_path', sprintf( __( 'Ongeldig pad in het pakket: %s', 'goede-hosting' ), sanitize_text_field( $name ) ) );
		}
		if ( '/' === substr( $name, -1 ) ) {
			if ( 'pages/' === $name || 'media/' === $name ) {
				continue;
			}
			$zip->close();
			/* translators: %s: folder name inside the zip */
			return new WP_Error( 'goedehosting_import_path', sprintf( __( 'Onverwachte map in het pakket: %s', 'goede-hosting' ), sanitize_text_field( $name ) ) );
		}
		$ok = false;
		if ( 'manifest.json' === $name ) {
			$ok = true;
		} elseif ( preg_match( '#^pages/([^/]+)$#', $name, $m ) ) {
			$ok = goede_hosting_importer_valid_name( $m[1], array( 'html' ) );
		} elseif ( preg_match( '#^media/([^/]+)$#', $name, $m ) ) {
			$ok = goede_hosting_importer_valid_name( $m[1], $media_exts );
		}
		if ( ! $ok ) {
			$zip->close();
			/* translators: %s: file name inside the zip */
			return new WP_Error( 'goedehosting_import_file', sprintf( __( 'Bestand niet toegestaan in het pakket: %s (alleen manifest.json, pages/*.html en media/*.jpg, .jpeg, .png, .webp of .svg).', 'goede-hosting' ), sanitize_text_field( $name ) ) );
		}
		$lower = strtolower( $name );
		if ( isset( $seen[ $lower ] ) ) {
			$zip->close();
			/* translators: %s: file name inside the zip */
			return new WP_Error( 'goedehosting_import_file', sprintf( __( 'Bestand komt dubbel voor in het pakket: %s', 'goede-hosting' ), sanitize_text_field( $name ) ) );
		}
		$seen[ $lower ] = true;
		$total         += (int) $stat['size'];
		if ( $total > GOEDE_HOSTING_IMPORT_MAX_BYTES ) {
			$zip->close();
			/* translators: %s: maximum size, e.g. "20 MB" */
			return new WP_Error( 'goedehosting_import_size', sprintf( __( 'Het uitgepakte pakket is groter dan %s.', 'goede-hosting' ), size_format( GOEDE_HOSTING_IMPORT_MAX_BYTES ) ) );
		}
		$entries[ $i ] = array(
			'name' => $name,
			'size' => (int) $stat['size'],
		);
	}
	if ( ! isset( $seen['manifest.json'] ) ) {
		$zip->close();
		return new WP_Error( 'goedehosting_import_manifest', __( 'manifest.json ontbreekt in het pakket.', 'goede-hosting' ) );
	}

	foreach ( $entries as $index => $entry ) {
		$data = $zip->getFromIndex( $index );
		if ( false === $data || strlen( $data ) !== $entry['size'] ) {
			$zip->close();
			/* translators: %s: file name inside the zip */
			return new WP_Error( 'goedehosting_import_read', sprintf( __( 'Bestand kon niet worden gelezen: %s', 'goede-hosting' ), $entry['name'] ) );
		}
		// The name is validated above: "manifest.json", "pages/<name>" or "media/<name>".
		if ( false === file_put_contents( $dir . '/' . $entry['name'], $data ) ) {
			$zip->close();
			/* translators: %s: file name inside the zip */
			return new WP_Error( 'goedehosting_import_write', sprintf( __( 'Bestand kon niet worden uitgepakt: %s', 'goede-hosting' ), $entry['name'] ) );
		}
	}
	$zip->close();
	return true;
}

/**
 * Sanitises SVG markup. Returns the cleaned markup, or false when it cannot be made safe.
 *
 * Strips script, foreignObject and similar elements, on* event attributes and
 * javascript:/vbscript:/non-image data: URLs; rejects DOCTYPE/ENTITY declarations.
 *
 * @param string $svg Raw SVG.
 * @return string|false
 */
function goede_hosting_importer_sanitize_svg( $svg ) {
	if ( ! class_exists( 'DOMDocument' ) || ! is_string( $svg ) || '' === trim( $svg ) ) {
		return false;
	}
	$svg = preg_replace( '/^\xEF\xBB\xBF/', '', $svg );
	if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', $svg ) ) {
		return false;
	}
	$previous = libxml_use_internal_errors( true );
	$dom      = new DOMDocument();
	$loaded   = $dom->loadXML( $svg, LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	if ( ! $loaded || ! $dom->documentElement || 'svg' !== strtolower( $dom->documentElement->localName ) ) {
		return false;
	}

	$bad_elements = array( 'script', 'foreignobject', 'iframe', 'embed', 'object', 'handler', 'listener', 'audio', 'video', 'base' );
	$xpath        = new DOMXPath( $dom );
	$remove       = array();
	foreach ( $xpath->query( '//processing-instruction()|//comment()' ) as $node ) {
		$remove[] = $node;
	}
	foreach ( $xpath->query( '//*' ) as $element ) {
		$tag = strtolower( $element->localName );
		if ( in_array( $tag, $bad_elements, true ) ) {
			$remove[] = $element;
			continue;
		}
		if ( 'style' === $tag && preg_match( '/@import|javascript:|expression\s*\(|behavior\s*:/i', $element->textContent ) ) {
			$remove[] = $element;
			continue;
		}
		$attributes = array();
		foreach ( $element->attributes as $attribute ) {
			$attributes[] = $attribute;
		}
		foreach ( $attributes as $attribute ) {
			$attr  = strtolower( $attribute->nodeName );
			$value = strtolower( (string) preg_replace( '/[\s\x00-\x1F]+/', '', html_entity_decode( $attribute->value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
			$drop  = 0 === strpos( $attr, 'on' )
				|| false !== strpos( $value, 'javascript:' )
				|| false !== strpos( $value, 'vbscript:' )
				|| ( 'style' === $attr && preg_match( '/expression\(|@import|behavior:/', $value ) );
			if ( ! $drop && in_array( $attr, array( 'href', 'xlink:href', 'src' ), true ) && 0 === strpos( $value, 'data:' ) ) {
				$drop = ! preg_match( '#^data:image/(png|jpe?g|gif|webp);#', $value );
			}
			if ( $drop ) {
				$element->removeAttributeNode( $attribute );
			}
		}
	}
	foreach ( $remove as $node ) {
		if ( $node->parentNode ) {
			$node->parentNode->removeChild( $node );
		}
	}
	$out = $dom->saveXML( $dom->documentElement );
	return is_string( $out ) && '' !== $out ? $out : false;
}

/**
 * Reads and validates an unpacked package.
 *
 * Returns array( 'manifest', 'company', 'media' => [ file => info ], 'pages' => [ key => info ] (parents first) )
 * or a WP_Error.
 *
 * @param string $dir Temp dir.
 * @return array|WP_Error
 */
function goede_hosting_importer_read_package( $dir ) {
	$raw      = file_get_contents( $dir . '/manifest.json' );
	$manifest = is_string( $raw ) ? json_decode( $raw, true ) : null;
	if ( ! is_array( $manifest ) ) {
		return new WP_Error( 'goedehosting_import_manifest', __( 'manifest.json is geen geldige JSON.', 'goede-hosting' ) );
	}
	if ( ! isset( $manifest['format'] ) || 1 !== (int) $manifest['format'] ) {
		return new WP_Error( 'goedehosting_import_manifest', __( 'Onbekend pakketformaat (verwacht: format 1).', 'goede-hosting' ) );
	}

	// Media.
	$types = goede_hosting_importer_media_types();
	$media = array();
	$list  = isset( $manifest['media'] ) && is_array( $manifest['media'] ) ? $manifest['media'] : array();
	if ( count( $list ) > 200 ) {
		return new WP_Error( 'goedehosting_import_manifest', __( 'Het pakket bevat te veel mediabestanden.', 'goede-hosting' ) );
	}
	foreach ( $list as $item ) {
		$file = is_array( $item ) && isset( $item['file'] ) && is_string( $item['file'] ) ? $item['file'] : '';
		if ( ! goede_hosting_importer_valid_name( $file, array_keys( $types ) ) || isset( $media[ $file ] ) ) {
			/* translators: %s: file name */
			return new WP_Error( 'goedehosting_import_media', sprintf( __( 'Ongeldig of dubbel mediabestand in manifest.json: %s', 'goede-hosting' ), sanitize_text_field( $file ) ) );
		}
		$path = $dir . '/media/' . $file;
		if ( ! is_file( $path ) ) {
			/* translators: %s: file name */
			return new WP_Error( 'goedehosting_import_media', sprintf( __( 'Mediabestand ontbreekt in het pakket: %s', 'goede-hosting' ), $file ) );
		}
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( 'svg' === $ext ) {
			$clean = goede_hosting_importer_sanitize_svg( (string) file_get_contents( $path ) );
			if ( false === $clean ) {
				/* translators: %s: file name */
				return new WP_Error( 'goedehosting_import_svg', sprintf( __( 'SVG-bestand afgekeurd (kon niet veilig worden gemaakt): %s', 'goede-hosting' ), $file ) );
			}
			file_put_contents( $path, $clean );
		} else {
			// The content must really be the image type the extension claims.
			$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $path ) : getimagesize( $path );
			if ( ! is_array( $size ) || empty( $size['mime'] ) || $size['mime'] !== $types[ $ext ] ) {
				/* translators: %s: file name */
				return new WP_Error( 'goedehosting_import_media', sprintf( __( 'Mediabestand is geen geldige afbeelding van het juiste type: %s', 'goede-hosting' ), $file ) );
			}
		}
		$media[ $file ] = array(
			'file'  => $file,
			'path'  => $path,
			'hash'  => sha1_file( $path ),
			'alt'   => isset( $item['alt'] ) && is_string( $item['alt'] ) ? sanitize_text_field( $item['alt'] ) : '',
			'title' => isset( $item['title'] ) && is_string( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
		);
	}

	// Pages.
	$list = isset( $manifest['pages'] ) && is_array( $manifest['pages'] ) ? $manifest['pages'] : array();
	if ( ! $list || count( $list ) > 200 ) {
		return new WP_Error( 'goedehosting_import_manifest', __( 'manifest.json bevat geen (of te veel) pagina\'s.', 'goede-hosting' ) );
	}
	$pages = array();
	$front = 0;
	foreach ( $list as $item ) {
		if ( ! is_array( $item ) ) {
			return new WP_Error( 'goedehosting_import_page', __( 'Ongeldige pagina in manifest.json.', 'goede-hosting' ) );
		}
		$key    = isset( $item['key'] ) && is_string( $item['key'] ) ? $item['key'] : '';
		$slug   = isset( $item['slug'] ) && is_string( $item['slug'] ) ? $item['slug'] : '';
		$title  = isset( $item['title'] ) && is_string( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
		$parent = isset( $item['parent'] ) && is_string( $item['parent'] ) && '' !== $item['parent'] ? $item['parent'] : null;
		$file   = isset( $item['file'] ) && is_string( $item['file'] ) ? $item['file'] : '';
		if ( '' === $key || sanitize_key( $key ) !== $key || isset( $pages[ $key ] ) ) {
			/* translators: %s: page key */
			return new WP_Error( 'goedehosting_import_page', sprintf( __( 'Ongeldige of dubbele paginasleutel: %s', 'goede-hosting' ), sanitize_text_field( $key ) ) );
		}
		if ( '' === $slug || sanitize_title( $slug ) !== $slug || '' === $title ) {
			/* translators: %s: page key */
			return new WP_Error( 'goedehosting_import_page', sprintf( __( 'Pagina "%s" heeft een ongeldige titel of slug.', 'goede-hosting' ), $key ) );
		}
		if ( ! preg_match( '#^pages/([^/]+)$#', $file, $m ) || ! goede_hosting_importer_valid_name( $m[1], array( 'html' ) ) || ! is_file( $dir . '/' . $file ) ) {
			/* translators: %s: page key */
			return new WP_Error( 'goedehosting_import_page', sprintf( __( 'Het bestand van pagina "%s" ontbreekt in het pakket.', 'goede-hosting' ), $key ) );
		}
		$content = (string) file_get_contents( $dir . '/' . $file );
		if ( '' === trim( $content ) || wp_check_invalid_utf8( $content ) !== $content ) {
			/* translators: %s: page key */
			return new WP_Error( 'goedehosting_import_page', sprintf( __( 'Pagina "%s" is leeg of geen geldige UTF-8.', 'goede-hosting' ), $key ) );
		}
		if ( preg_match_all( '/\{\{(media|media-id):([^}]*)\}\}/', $content, $tokens ) ) {
			foreach ( $tokens[2] as $token_file ) {
				if ( ! isset( $media[ $token_file ] ) ) {
					/* translators: 1: page key, 2: file name */
					return new WP_Error( 'goedehosting_import_page', sprintf( __( 'Pagina "%1$s" verwijst naar een mediabestand dat niet in het pakket zit: %2$s', 'goede-hosting' ), $key, sanitize_text_field( $token_file ) ) );
				}
			}
		}
		$is_front      = ! empty( $item['front_page'] );
		$front        += $is_front ? 1 : 0;
		$pages[ $key ] = array(
			'key'        => $key,
			'title'      => $title,
			'slug'       => $slug,
			'parent'     => $parent,
			'menu_order' => isset( $item['menu_order'] ) && is_numeric( $item['menu_order'] ) ? (int) $item['menu_order'] : 0,
			'front_page' => $is_front,
			'content'    => $content,
		);
	}
	if ( $front > 1 ) {
		return new WP_Error( 'goedehosting_import_page', __( 'Er is meer dan één pagina als voorpagina aangemerkt.', 'goede-hosting' ) );
	}

	// Parents by key: must exist, no cycles; order parents first and build the paths.
	$ordered = array();
	$paths   = array();
	$guard   = 0;
	$total   = count( $pages );
	$done    = 0;
	while ( $done < $total ) {
		if ( ++$guard > $total + 1 ) {
			return new WP_Error( 'goedehosting_import_page', __( 'De paginahiërarchie in manifest.json bevat een kringverwijzing.', 'goede-hosting' ) );
		}
		foreach ( $pages as $key => $page ) {
			if ( isset( $ordered[ $key ] ) ) {
				continue;
			}
			if ( null !== $page['parent'] && ! isset( $pages[ $page['parent'] ] ) ) {
				/* translators: 1: page key, 2: parent key */
				return new WP_Error( 'goedehosting_import_page', sprintf( __( 'Pagina "%1$s" heeft een onbekende bovenliggende pagina: %2$s', 'goede-hosting' ), $key, sanitize_text_field( $page['parent'] ) ) );
			}
			if ( null === $page['parent'] || isset( $ordered[ $page['parent'] ] ) ) {
				$page['path'] = ( null === $page['parent'] ? '' : $ordered[ $page['parent'] ]['path'] . '/' ) . $page['slug'];
				if ( isset( $paths[ $page['path'] ] ) ) {
					/* translators: %s: page path */
					return new WP_Error( 'goedehosting_import_page', sprintf( __( 'Twee pagina\'s hebben hetzelfde pad: /%s/', 'goede-hosting' ), $page['path'] ) );
				}
				$paths[ $page['path'] ] = true;
				$ordered[ $key ]        = $page;
				++$done;
			}
		}
	}

	// Company details: only known keys, scalar values.
	$company = array();
	if ( isset( $manifest['company'] ) && is_array( $manifest['company'] ) ) {
		foreach ( goede_hosting_importer_company_keys() as $key ) {
			if ( isset( $manifest['company'][ $key ] ) && is_scalar( $manifest['company'][ $key ] ) ) {
				$value           = (string) $manifest['company'][ $key ];
				$company[ $key ] = 'email' === $key ? sanitize_email( $value ) : sanitize_text_field( $value );
			}
		}
	}

	return array(
		'generated' => isset( $manifest['generated'] ) && is_string( $manifest['generated'] ) ? sanitize_text_field( $manifest['generated'] ) : '',
		'company'   => $company,
		'media'     => $media,
		'pages'     => $ordered,
	);
}

/**
 * Company keys the importer may set (option goedehosting_company): the fields of
 * the "company" module, none when that module is off.
 *
 * @return string[]
 */
function goede_hosting_importer_company_keys() {
	return function_exists( 'goede_hosting_company_keys' ) ? goede_hosting_company_keys() : array();
}

/**
 * Labels for the company keys.
 *
 * @return array<string, string>
 */
function goede_hosting_importer_company_labels() {
	return function_exists( 'goede_hosting_company_fields' ) ? wp_list_pluck( goede_hosting_company_fields(), 'label' ) : array();
}

// -----------------------------------------------------------------------------
// Matching existing content
// -----------------------------------------------------------------------------

/**
 * Existing page by path (parent slug + slug), ignoring the trash.
 *
 * @param string $path e.g. "extra-diensten/website-bouwen".
 * @return WP_Post|null
 */
function goede_hosting_importer_find_page( $path ) {
	$page = get_page_by_path( $path, OBJECT, 'page' );
	return ( $page instanceof WP_Post && 'trash' !== $page->post_status ) ? $page : null;
}

/**
 * What the import will do with a page: create, update or manual (changed by hand).
 *
 * @param array $page Package page.
 * @return array action, post (WP_Post|null), never_imported (bool).
 */
function goede_hosting_importer_page_action( $page ) {
	$post = goede_hosting_importer_find_page( $page['path'] );
	if ( ! $post ) {
		return array(
			'action'         => 'create',
			'post'           => null,
			'never_imported' => false,
		);
	}
	$stored = (string) get_post_meta( $post->ID, '_goedehosting_import_hash', true );
	$manual = '' === $stored || ! hash_equals( $stored, sha1( $post->post_content ) );
	return array(
		'action'         => $manual ? 'manual' : 'update',
		'post'           => $post,
		'never_imported' => '' === $stored,
	);
}

/**
 * Attachment ID already imported with this sha1, or 0.
 *
 * @param string $hash sha1 of the file.
 * @return int
 */
function goede_hosting_importer_find_media( $hash ) {
	$ids = get_posts(
		array(
			'post_type'        => 'attachment',
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
			'meta_key'         => '_goedehosting_import_hash', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'       => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);
	return $ids ? (int) $ids[0] : 0;
}

// -----------------------------------------------------------------------------
// Request handling (load-{page} hook, before any output)
// -----------------------------------------------------------------------------

/**
 * Handles the POST actions and redirects (post/redirect/get).
 */
function goede_hosting_importer_load() {
	if ( ! goede_hosting_importer_allowed() ) {
		wp_die( esc_html__( 'Je hebt geen toestemming om inhoud te importeren.', 'goede-hosting' ), 403 );
	}
	goede_hosting_importer_cleanup_stale();
	add_action( 'admin_enqueue_scripts', 'goede_hosting_enqueue_admin_style' );

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
	if ( 'POST' !== $method || ! isset( $_POST['goedehosting_import_step'] ) ) {
		return;
	}
	$step = sanitize_key( wp_unslash( $_POST['goedehosting_import_step'] ) );

	if ( 'upload' === $step ) {
		check_admin_referer( 'goedehosting_import_upload' );
		$result = goede_hosting_importer_handle_upload();
		if ( is_wp_error( $result ) ) {
			goede_hosting_importer_notice( 'error', $result->get_error_message() );
			wp_safe_redirect( goede_hosting_importer_url() );
		} else {
			wp_safe_redirect( goede_hosting_importer_url( array( 'stap' => 'voorbeeld' ) ) );
		}
		exit;
	}

	if ( 'cancel' === $step ) {
		check_admin_referer( 'goedehosting_import_run' );
		goede_hosting_importer_forget();
		goede_hosting_importer_notice( 'info', __( 'Import geannuleerd; het geüploade pakket is verwijderd.', 'goede-hosting' ) );
		wp_safe_redirect( goede_hosting_importer_url() );
		exit;
	}

	if ( 'import' === $step ) {
		check_admin_referer( 'goedehosting_import_run' );
		$state = goede_hosting_importer_state();
		$token = isset( $_POST['goedehosting_import_token'] ) ? sanitize_text_field( wp_unslash( $_POST['goedehosting_import_token'] ) ) : '';
		if ( ! $state || '' === $token || ! hash_equals( (string) $state['token'], $token ) ) {
			goede_hosting_importer_notice( 'error', __( 'Het geüploade pakket is verlopen of al geïmporteerd. Upload het opnieuw.', 'goede-hosting' ) );
			wp_safe_redirect( goede_hosting_importer_url() );
			exit;
		}
		$include = array();
		if ( isset( $_POST['goedehosting_include'] ) && is_array( $_POST['goedehosting_include'] ) ) {
			$include = array_map( 'sanitize_key', wp_unslash( $_POST['goedehosting_include'] ) );
		}
		$overwrite = ! empty( $_POST['goedehosting_overwrite_company'] );

		$result = goede_hosting_importer_run( $state['path'], $include, $overwrite );
		goede_hosting_importer_forget();
		if ( is_wp_error( $result ) ) {
			goede_hosting_importer_notice( 'error', $result->get_error_message() );
			wp_safe_redirect( goede_hosting_importer_url() );
			exit;
		}
		set_transient( goede_hosting_importer_key( 'result' ), $result, 30 * MINUTE_IN_SECONDS );
		wp_safe_redirect( goede_hosting_importer_url( array( 'stap' => 'resultaat' ) ) );
		exit;
	}
}

/**
 * Step 1: checks the upload, unpacks it and stores the state.
 *
 * @return true|WP_Error
 */
function goede_hosting_importer_handle_upload() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by the caller; every field of the upload array is validated below.
	$file = isset( $_FILES['goedehosting_package'] ) && is_array( $_FILES['goedehosting_package'] ) ? $_FILES['goedehosting_package'] : null;
	if ( ! $file || ! isset( $file['error'], $file['tmp_name'], $file['name'], $file['size'] ) || is_array( $file['error'] ) ) {
		return new WP_Error( 'goedehosting_import_upload', __( 'Kies eerst een pakket (zip-bestand) om te uploaden.', 'goede-hosting' ) );
	}
	if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
		$message = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true )
			? __( 'Het bestand is groter dan de server toestaat.', 'goede-hosting' )
			: __( 'Het uploaden is mislukt. Probeer het opnieuw.', 'goede-hosting' );
		return new WP_Error( 'goedehosting_import_upload', $message );
	}
	$tmp_name = (string) $file['tmp_name'];
	if ( ! is_uploaded_file( $tmp_name ) ) {
		return new WP_Error( 'goedehosting_import_upload', __( 'Het uploaden is mislukt. Probeer het opnieuw.', 'goede-hosting' ) );
	}
	$name = sanitize_file_name( wp_unslash( (string) $file['name'] ) );
	if ( 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
		return new WP_Error( 'goedehosting_import_upload', __( 'Upload een zip-bestand (goedehosting-inhoud-<datum>.zip).', 'goede-hosting' ) );
	}
	$size = filesize( $tmp_name );
	if ( false === $size || $size < 1 || $size > GOEDE_HOSTING_IMPORT_MAX_BYTES ) {
		/* translators: %s: maximum size, e.g. "20 MB" */
		return new WP_Error( 'goedehosting_import_upload', sprintf( __( 'Het pakket is leeg of groter dan %s.', 'goede-hosting' ), size_format( GOEDE_HOSTING_IMPORT_MAX_BYTES ) ) );
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'goedehosting_import_zip', __( 'De PHP-extensie ZipArchive (zip) ontbreekt op deze server, dus het pakket kan niet worden gelezen. Vraag je hostingpartij om de zip-extensie van PHP aan te zetten.', 'goede-hosting' ) );
	}

	goede_hosting_importer_forget();
	$dir_name = goede_hosting_importer_make_dir();
	if ( is_wp_error( $dir_name ) ) {
		return $dir_name;
	}
	$dir    = goede_hosting_importer_dir_path( $dir_name );
	$result = goede_hosting_importer_unpack( $tmp_name, $dir );
	if ( ! is_wp_error( $result ) ) {
		$result = goede_hosting_importer_read_package( $dir );
	}
	if ( is_wp_error( $result ) ) {
		goede_hosting_importer_rmdir( $dir );
		return $result;
	}
	set_transient(
		goede_hosting_importer_key( 'state' ),
		array(
			'dir'   => $dir_name,
			'token' => wp_generate_password( 32, false, false ),
			'name'  => $name,
			'time'  => time(),
		),
		HOUR_IN_SECONDS
	);
	return true;
}

// -----------------------------------------------------------------------------
// Step 2: the import itself
// -----------------------------------------------------------------------------

/**
 * Allows SVG for our own (sanitised) sideloads only.
 *
 * @param array $mimes Allowed mime types.
 * @return array
 */
function goede_hosting_importer_allow_svg( $mimes ) {
	$mimes['svg'] = 'image/svg+xml';
	return $mimes;
}

/**
 * Fixes the type check for sanitised SVGs (finfo may report text/xml or text/plain).
 *
 * @param array  $data     ext, type, proper_filename.
 * @param string $file     Full path.
 * @param string $filename File name.
 * @return array
 */
function goede_hosting_importer_svg_filetype( $data, $file, $filename ) {
	if ( 'svg' === strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) ) ) {
		$data['ext']  = 'svg';
		$data['type'] = 'image/svg+xml';
	}
	return $data;
}

/**
 * Puts one package media file into the media library.
 *
 * @param array $item Package media info.
 * @return int|WP_Error Attachment ID.
 */
function goede_hosting_importer_sideload( $item ) {
	$tmp = wp_tempnam( $item['file'] );
	if ( ! $tmp || ! copy( $item['path'], $tmp ) ) {
		return new WP_Error( 'goedehosting_import_media', __( 'Tijdelijk bestand kon niet worden aangemaakt.', 'goede-hosting' ) );
	}
	$is_svg = 'svg' === strtolower( pathinfo( $item['file'], PATHINFO_EXTENSION ) );
	if ( $is_svg ) {
		add_filter( 'upload_mimes', 'goede_hosting_importer_allow_svg' );
		add_filter( 'wp_check_filetype_and_ext', 'goede_hosting_importer_svg_filetype', 10, 3 );
	}
	$post_data = array();
	if ( '' !== $item['title'] ) {
		$post_data['post_title'] = $item['title'];
	}
	$id = media_handle_sideload(
		array(
			'name'     => $item['file'],
			'tmp_name' => $tmp,
		),
		0,
		null,
		$post_data
	);
	if ( $is_svg ) {
		remove_filter( 'upload_mimes', 'goede_hosting_importer_allow_svg' );
		remove_filter( 'wp_check_filetype_and_ext', 'goede_hosting_importer_svg_filetype', 10 );
	}
	if ( file_exists( $tmp ) ) {
		wp_delete_file( $tmp );
	}
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	update_post_meta( $id, '_goedehosting_import_hash', $item['hash'] );
	if ( '' !== $item['alt'] ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $item['alt'] );
	}
	return (int) $id;
}

/**
 * Replaces the media tokens in block markup.
 * "{{media-id:x}}" (quoted, in block attributes) becomes the bare number,
 * {{media-id:x}} the number and {{media:x}} the attachment URL.
 *
 * @param string $content Block markup.
 * @param array  $map     file => array( id, url ).
 * @return string
 */
function goede_hosting_importer_replace_tokens( $content, $map ) {
	return (string) preg_replace_callback(
		'/"\{\{media-id:([^}"]+)\}\}"|\{\{media-id:([^}"]+)\}\}|\{\{media:([^}"]+)\}\}/',
		function ( $m ) use ( $map ) {
			if ( isset( $m[1] ) && '' !== $m[1] ) {
				return (string) ( isset( $map[ $m[1] ] ) ? (int) $map[ $m[1] ]['id'] : 0 );
			}
			if ( isset( $m[2] ) && '' !== $m[2] ) {
				return (string) ( isset( $map[ $m[2] ] ) ? (int) $map[ $m[2] ]['id'] : 0 );
			}
			$file = isset( $m[3] ) ? $m[3] : '';
			// Safe inside JSON strings and HTML attributes alike.
			return isset( $map[ $file ] ) ? str_replace( array( '"', '\\', '<', '>' ), '', $map[ $file ]['url'] ) : '';
		},
		$content
	);
}

/**
 * Runs the import.
 *
 * @param string $dir          Temp dir.
 * @param array  $include_keys Keys of hand-changed pages that may be overwritten.
 * @param bool   $overwrite    Overwrite filled-in company details.
 * @return array|WP_Error Result summary.
 */
function goede_hosting_importer_run( $dir, $include_keys, $overwrite ) {
	$package = goede_hosting_importer_read_package( $dir );
	if ( is_wp_error( $package ) ) {
		return $package;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$result = array(
		'created'   => array(),
		'updated'   => array(),
		'skipped'   => array(),
		'media_new' => 0,
		'media_old' => 0,
		'errors'    => array(),
		'warnings'  => array(),
		'company'   => array(),
		'front'     => 0,
	);

	// Media first, so the pages can point to them.
	$map = array();
	foreach ( $package['media'] as $file => $item ) {
		$id = goede_hosting_importer_find_media( $item['hash'] );
		if ( $id ) {
			++$result['media_old'];
		} else {
			$id = goede_hosting_importer_sideload( $item );
			if ( is_wp_error( $id ) ) {
				/* translators: 1: file name, 2: error message */
				$result['errors'][] = sprintf( __( 'Mediabestand %1$s kon niet worden geïmporteerd: %2$s', 'goede-hosting' ), $file, $id->get_error_message() );
				continue;
			}
			++$result['media_new'];
		}
		$map[ $file ] = array(
			'id'  => $id,
			'url' => (string) wp_get_attachment_url( $id ),
		);
	}

	// Pages, parents first.
	$ids = array();
	foreach ( $package['pages'] as $key => $page ) {
		$plan = goede_hosting_importer_page_action( $page );
		$post = $plan['post'];
		$link = array(
			'title' => $page['title'],
			'path'  => $page['path'],
			'id'    => $post ? $post->ID : 0,
		);
		if ( 'manual' === $plan['action'] && ! in_array( $key, $include_keys, true ) ) {
			$ids[ $key ]         = $post->ID;
			$link['reason']      = __( 'handmatig gewijzigd, niet aangevinkt', 'goede-hosting' );
			$result['skipped'][] = $link;
			continue;
		}

		$content   = goede_hosting_importer_replace_tokens( $page['content'], $map );
		$parent_id = null === $page['parent'] ? 0 : ( isset( $ids[ $page['parent'] ] ) ? (int) $ids[ $page['parent'] ] : 0 );
		$data      = array(
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_title'    => $page['title'],
			'post_name'     => $page['slug'],
			'post_parent'   => $parent_id,
			'menu_order'    => $page['menu_order'],
			'post_content'  => $content,
			// The theme uses its own templates only: an old theme's page template
			// (still in _wp_page_template) would make wp_update_post() fail.
			'page_template' => 'default',
		);

		if ( $post && $post->post_content === $content && $post->post_title === $page['title'] && (int) $post->post_parent === $parent_id
			&& (int) $post->menu_order === $page['menu_order'] && 'publish' === $post->post_status ) {
			$ids[ $key ] = $post->ID;
			update_post_meta( $post->ID, '_goedehosting_import_hash', sha1( $post->post_content ) );
			update_post_meta( $post->ID, '_wp_page_template', 'default' );
			$link['reason']      = __( 'ongewijzigd', 'goede-hosting' );
			$result['skipped'][] = $link;
			continue;
		}

		if ( $post ) {
			$data['ID'] = $post->ID;
			$id         = wp_update_post( wp_slash( $data ), true );
		} else {
			$id = wp_insert_post( wp_slash( $data ), true );
		}
		if ( is_wp_error( $id ) || ! $id ) {
			/* translators: 1: page title, 2: error message */
			$result['errors'][] = sprintf( __( 'Pagina "%1$s" kon niet worden opgeslagen: %2$s', 'goede-hosting' ), $page['title'], is_wp_error( $id ) ? $id->get_error_message() : '' );
			continue;
		}
		$id          = (int) $id;
		$ids[ $key ] = $id;
		// Hash of what WordPress actually stored (filters may have touched it).
		$saved = get_post( $id );
		update_post_meta( $id, '_goedehosting_import_hash', sha1( $saved ? $saved->post_content : $content ) );
		update_post_meta( $id, '_goedehosting_import_key', $key );
		if ( $saved && $saved->post_name !== $page['slug'] ) {
			/* translators: 1: page title, 2: intended slug, 3: actual slug */
			$result['warnings'][] = sprintf( __( 'Pagina "%1$s" kreeg de slug "%3$s" in plaats van "%2$s" (de slug was al in gebruik).', 'goede-hosting' ), $page['title'], $page['slug'], $saved->post_name );
		}
		$link['id'] = $id;
		if ( $post ) {
			$result['updated'][] = $link;
		} else {
			$result['created'][] = $link;
		}
	}

	// Front page.
	foreach ( $package['pages'] as $key => $page ) {
		if ( $page['front_page'] && ! empty( $ids[ $key ] ) ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', (int) $ids[ $key ] );
			$result['front'] = (int) $ids[ $key ];
		}
	}

	// Company details: only empty fields, unless "overschrijven" was ticked.
	if ( $package['company'] ) {
		$current = get_option( 'goedehosting_company', array() );
		$current = is_array( $current ) ? $current : array();
		$new     = $current;
		foreach ( $package['company'] as $ckey => $value ) {
			$old = isset( $current[ $ckey ] ) && is_scalar( $current[ $ckey ] ) ? (string) $current[ $ckey ] : '';
			if ( ( $overwrite || '' === $old ) && $old !== $value ) {
				$new[ $ckey ]        = $value;
				$result['company'][] = $ckey;
			}
		}
		// Store every key, so the defaults no longer fill in fields that are meant to be empty.
		foreach ( goede_hosting_importer_company_keys() as $ckey ) {
			if ( ! isset( $new[ $ckey ] ) ) {
				$new[ $ckey ] = isset( $package['company'][ $ckey ] ) ? $package['company'][ $ckey ] : '';
			}
		}
		if ( $new !== $current ) {
			update_option( 'goedehosting_company', $new );
		}
	}

	return $result;
}

// -----------------------------------------------------------------------------
// Screen
// -----------------------------------------------------------------------------

/**
 * Renders the importer screen.
 */
function goede_hosting_importer_render() {
	if ( ! goede_hosting_importer_allowed() ) {
		wp_die( esc_html__( 'Je hebt geen toestemming om inhoud te importeren.', 'goede-hosting' ), 403 );
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view switch only.
	$step = isset( $_GET['stap'] ) ? sanitize_key( wp_unslash( $_GET['stap'] ) ) : '';

	echo '<div class="wrap goede-hosting-importer">';
	echo '<h1>' . esc_html__( 'Goede Hosting Importeer Inhoud', 'goede-hosting' ) . '</h1>';

	$notices = get_transient( goede_hosting_importer_key( 'notice' ) );
	delete_transient( goede_hosting_importer_key( 'notice' ) );
	if ( is_array( $notices ) ) {
		foreach ( $notices as $notice ) {
			$type = in_array( $notice['type'], array( 'error', 'warning', 'success', 'info' ), true ) ? $notice['type'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
	}

	if ( 'resultaat' === $step ) {
		goede_hosting_importer_render_result();
	} elseif ( 'voorbeeld' === $step && goede_hosting_importer_state() ) {
		goede_hosting_importer_render_preview( goede_hosting_importer_state() );
	} else {
		if ( 'voorbeeld' === $step ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Het geüploade pakket is verlopen (na een uur) of al geïmporteerd. Upload het opnieuw.', 'goede-hosting' ) . '</p></div>';
		}
		goede_hosting_importer_render_upload();
	}
	echo '</div>';
}

/**
 * Upload form.
 */
function goede_hosting_importer_render_upload() {
	$max = min( GOEDE_HOSTING_IMPORT_MAX_BYTES, (int) wp_max_upload_size() );
	echo '<p>' . esc_html__( 'Upload een inhoudspakket (goedehosting-inhoud-<datum>.zip). Je ziet eerst een voorbeeld van wat er wordt aangemaakt of bijgewerkt; er verandert pas iets als je op "Importeren" klikt.', 'goede-hosting' ) . '</p>';
	if ( ! class_exists( 'ZipArchive' ) ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html__( 'De PHP-extensie ZipArchive (zip) ontbreekt op deze server, dus pakketten kunnen niet worden gelezen. Vraag je hostingpartij om de zip-extensie van PHP aan te zetten.', 'goede-hosting' ) . '</p></div>';
	}
	echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( goede_hosting_importer_url() ) . '" class="goede-hosting-importer__upload">';
	wp_nonce_field( 'goedehosting_import_upload' );
	echo '<input type="hidden" name="goedehosting_import_step" value="upload">';
	echo '<p><label for="goedehosting-package">' . esc_html__( 'Pakket (zip)', 'goede-hosting' ) . '</label><br>';
	echo '<input type="file" id="goedehosting-package" name="goedehosting_package" accept=".zip,application/zip" required></p>';
	/* translators: %s: maximum upload size */
	echo '<p class="description">' . esc_html( sprintf( __( 'Maximale grootte: %s.', 'goede-hosting' ), size_format( $max ) ) ) . '</p>';
	submit_button( __( 'Uploaden en controleren', 'goede-hosting' ) );
	echo '</form>';
}

/**
 * Preview table + import form.
 *
 * @param array $state Upload state.
 */
function goede_hosting_importer_render_preview( $state ) {
	$package = goede_hosting_importer_read_package( $state['path'] );
	if ( is_wp_error( $package ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $package->get_error_message() ) . '</p></div>';
		goede_hosting_importer_forget();
		goede_hosting_importer_render_upload();
		return;
	}

	echo '<p>';
	/* translators: 1: zip file name, 2: date */
	echo esc_html( sprintf( __( 'Pakket: %1$s (gemaakt op %2$s). Controleer hieronder wat er gebeurt en klik dan op "Importeren".', 'goede-hosting' ), $state['name'], '' !== $package['generated'] ? $package['generated'] : '?' ) );
	echo '</p>';

	echo '<form method="post" action="' . esc_url( goede_hosting_importer_url() ) . '">';
	wp_nonce_field( 'goedehosting_import_run' );
	echo '<input type="hidden" name="goedehosting_import_token" value="' . esc_attr( $state['token'] ) . '">';

	// Pages.
	echo '<h2>' . esc_html__( 'Pagina\'s', 'goede-hosting' ) . '</h2>';
	echo '<table class="widefat striped goede-hosting-importer__table"><thead><tr>';
	echo '<th scope="col">' . esc_html__( 'Titel', 'goede-hosting' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Pad', 'goede-hosting' ) . '</th>';
	echo '<th scope="col">' . esc_html__( 'Actie', 'goede-hosting' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $package['pages'] as $key => $page ) {
		$plan = goede_hosting_importer_page_action( $page );
		echo '<tr><td><strong>' . esc_html( $page['title'] ) . '</strong>';
		if ( $page['front_page'] ) {
			echo ' <span class="goede-hosting-importer__tag">' . esc_html__( 'voorpagina', 'goede-hosting' ) . '</span>';
		}
		echo '</td><td><code>/' . esc_html( $page['path'] ) . '/</code></td><td>';
		if ( 'create' === $plan['action'] ) {
			echo '<span class="goede-hosting-importer__status is-new">' . esc_html__( 'aanmaken', 'goede-hosting' ) . '</span>';
		} elseif ( 'update' === $plan['action'] ) {
			echo '<span class="goede-hosting-importer__status is-update">' . esc_html__( 'bijwerken', 'goede-hosting' ) . '</span>';
		} else {
			echo '<span class="goede-hosting-importer__status is-manual">' . esc_html__( 'handmatig gewijzigd sinds laatste import', 'goede-hosting' ) . '</span>';
			if ( $plan['never_imported'] ) {
				echo ' <span class="description">' . esc_html__( '(bestaande pagina, nooit eerder geïmporteerd)', 'goede-hosting' ) . '</span>';
			}
			$id = 'goedehosting-include-' . $key;
			echo '<br><label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="goedehosting_include[]" value="' . esc_attr( $key ) . '"> ';
			echo esc_html__( 'Toch overschrijven met de inhoud uit het pakket', 'goede-hosting' ) . '</label>';
		}
		if ( $plan['post'] ) {
			$edit = get_edit_post_link( $plan['post']->ID );
			if ( $edit ) {
				echo ' <a href="' . esc_url( $edit ) . '">' . esc_html__( 'huidige pagina', 'goede-hosting' ) . '</a>';
			}
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';

	// Media.
	echo '<h2>' . esc_html__( 'Media', 'goede-hosting' ) . '</h2>';
	if ( ! $package['media'] ) {
		echo '<p>' . esc_html__( 'Dit pakket bevat geen mediabestanden.', 'goede-hosting' ) . '</p>';
	} else {
		echo '<table class="widefat striped goede-hosting-importer__table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Bestand', 'goede-hosting' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Alt-tekst', 'goede-hosting' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'goede-hosting' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $package['media'] as $file => $item ) {
			$id = goede_hosting_importer_find_media( $item['hash'] );
			echo '<tr><td><code>' . esc_html( $file ) . '</code></td><td>' . esc_html( $item['alt'] ) . '</td><td>';
			if ( $id ) {
				echo '<span class="goede-hosting-importer__status is-update">' . esc_html__( 'al aanwezig', 'goede-hosting' ) . '</span>';
				$edit = get_edit_post_link( $id );
				if ( $edit ) {
					echo ' <a href="' . esc_url( $edit ) . '">' . esc_html__( 'bekijken', 'goede-hosting' ) . '</a>';
				}
			} else {
				echo '<span class="goede-hosting-importer__status is-new">' . esc_html__( 'nieuw', 'goede-hosting' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// Company details.
	if ( $package['company'] ) {
		$current = get_option( 'goedehosting_company', array() );
		$current = is_array( $current ) ? $current : array();
		$labels  = goede_hosting_importer_company_labels();
		echo '<h2>' . esc_html__( 'Bedrijfsgegevens', 'goede-hosting' ) . '</h2>';
		echo '<p>' . esc_html__( 'Lege velden worden ingevuld met de waarden uit het pakket. Ingevulde velden blijven staan, tenzij je hieronder "overschrijven" aanvinkt.', 'goede-hosting' ) . '</p>';
		echo '<table class="widefat striped goede-hosting-importer__table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Veld', 'goede-hosting' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Huidige waarde', 'goede-hosting' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Waarde in pakket', 'goede-hosting' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $package['company'] as $ckey => $value ) {
			$old = isset( $current[ $ckey ] ) && is_scalar( $current[ $ckey ] ) ? (string) $current[ $ckey ] : '';
			echo '<tr><td>' . esc_html( isset( $labels[ $ckey ] ) ? $labels[ $ckey ] : $ckey ) . '</td>';
			echo '<td>' . ( '' === $old ? '<em>' . esc_html__( '(leeg)', 'goede-hosting' ) . '</em>' : esc_html( $old ) ) . '</td>';
			echo '<td>' . ( '' === $value ? '<em>' . esc_html__( '(leeg)', 'goede-hosting' ) . '</em>' : esc_html( $value ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><label><input type="checkbox" name="goedehosting_overwrite_company" value="1"> ';
		echo esc_html__( 'Bestaande bedrijfsgegevens overschrijven', 'goede-hosting' ) . '</label></p>';
	}

	$front = '';
	foreach ( $package['pages'] as $page ) {
		if ( $page['front_page'] ) {
			$front = $page['title'];
		}
	}
	if ( '' !== $front ) {
		/* translators: %s: page title */
		echo '<p>' . esc_html( sprintf( __( 'De pagina "%s" wordt ingesteld als voorpagina (Instellingen > Lezen).', 'goede-hosting' ), $front ) ) . '</p>';
	}

	echo '<p class="submit goede-hosting-importer__actions">';
	echo '<button type="submit" class="button button-primary" name="goedehosting_import_step" value="import">' . esc_html__( 'Importeren', 'goede-hosting' ) . '</button> ';
	echo '<button type="submit" class="button" name="goedehosting_import_step" value="cancel" formnovalidate>' . esc_html__( 'Annuleren', 'goede-hosting' ) . '</button>';
	echo '</p>';
	echo '</form>';
}

/**
 * Result of the last import.
 */
function goede_hosting_importer_render_result() {
	$result = get_transient( goede_hosting_importer_key( 'result' ) );
	if ( ! is_array( $result ) ) {
		echo '<p>' . esc_html__( 'Er is geen recent importresultaat.', 'goede-hosting' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( goede_hosting_importer_url() ) . '">' . esc_html__( 'Nieuw pakket uploaden', 'goede-hosting' ) . '</a></p>';
		return;
	}

	$type = $result['errors'] ? 'warning' : 'success';
	echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>';
	echo esc_html(
		sprintf(
			/* translators: 1: created, 2: updated, 3: skipped, 4: new media, 5: existing media */
			__( 'Import klaar: %1$d pagina\'s aangemaakt, %2$d bijgewerkt, %3$d overgeslagen; %4$d mediabestanden toegevoegd, %5$d waren al aanwezig.', 'goede-hosting' ),
			count( $result['created'] ),
			count( $result['updated'] ),
			count( $result['skipped'] ),
			(int) $result['media_new'],
			(int) $result['media_old']
		)
	);
	echo '</p></div>';

	foreach ( $result['errors'] as $message ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}
	foreach ( $result['warnings'] as $message ) {
		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}

	$groups = array(
		'created' => __( 'Aangemaakt', 'goede-hosting' ),
		'updated' => __( 'Bijgewerkt', 'goede-hosting' ),
		'skipped' => __( 'Overgeslagen', 'goede-hosting' ),
	);
	foreach ( $groups as $group => $label ) {
		if ( empty( $result[ $group ] ) ) {
			continue;
		}
		echo '<h2>' . esc_html( $label ) . '</h2><ul class="goede-hosting-importer__list">';
		foreach ( $result[ $group ] as $item ) {
			echo '<li><strong>' . esc_html( $item['title'] ) . '</strong> <code>/' . esc_html( $item['path'] ) . '/</code>';
			if ( ! empty( $item['reason'] ) ) {
				echo ' <span class="description">(' . esc_html( $item['reason'] ) . ')</span>';
			}
			if ( ! empty( $item['id'] ) && get_post( $item['id'] ) ) {
				echo ' &middot; <a href="' . esc_url( get_permalink( $item['id'] ) ) . '">' . esc_html__( 'bekijken', 'goede-hosting' ) . '</a>';
				$edit = get_edit_post_link( $item['id'] );
				if ( $edit ) {
					echo ' &middot; <a href="' . esc_url( $edit ) . '">' . esc_html__( 'bewerken', 'goede-hosting' ) . '</a>';
				}
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	if ( ! empty( $result['front'] ) && get_post( $result['front'] ) ) {
		/* translators: %s: page title */
		echo '<p>' . esc_html( sprintf( __( 'Voorpagina ingesteld op "%s".', 'goede-hosting' ), get_the_title( $result['front'] ) ) ) . '</p>';
	}
	if ( ! empty( $result['company'] ) ) {
		$labels = goede_hosting_importer_company_labels();
		$names  = array();
		foreach ( $result['company'] as $ckey ) {
			$names[] = isset( $labels[ $ckey ] ) ? $labels[ $ckey ] : $ckey;
		}
		/* translators: %s: comma separated field names */
		echo '<p>' . esc_html( sprintf( __( 'Bedrijfsgegevens bijgewerkt: %s.', 'goede-hosting' ), implode( ', ', $names ) ) ) . '</p>';
	} else {
		echo '<p>' . esc_html__( 'Bedrijfsgegevens: niets gewijzigd.', 'goede-hosting' ) . '</p>';
	}
	echo '<p><a class="button" href="' . esc_url( goede_hosting_importer_url() ) . '">' . esc_html__( 'Nieuw pakket uploaden', 'goede-hosting' ) . '</a></p>';
}

// -----------------------------------------------------------------------------
// Page templates left behind by a previous theme
// -----------------------------------------------------------------------------

/**
 * Resets page templates the active theme doesn't have (left behind by the
 * previous theme in _wp_page_template) to "default". Without this, saving such
 * a page fails with "Ongeldige paginatemplate". Runs once per theme.
 */
function goede_hosting_fix_page_templates() {
	$theme = get_stylesheet();
	if ( get_option( 'goede_hosting_templates_fixed' ) === $theme ) {
		return;
	}
	$valid = array_keys( wp_get_theme()->get_page_templates( null, 'page' ) );
	$ids   = get_posts(
		array(
			'post_type'      => 'page',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time cleanup.
		)
	);
	foreach ( $ids as $id ) {
		$template = get_post_meta( $id, '_wp_page_template', true );
		if ( '' !== $template && 'default' !== $template && ! in_array( $template, $valid, true ) ) {
			update_post_meta( $id, '_wp_page_template', 'default' );
		}
	}
	update_option( 'goede_hosting_templates_fixed', $theme, false );
}
add_action( 'admin_init', 'goede_hosting_fix_page_templates' );
