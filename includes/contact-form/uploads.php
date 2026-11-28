<?php
/**
 * Forms: file uploads.
 *
 * Safety rules (lessons from WPForms CVE-2026-10818 and friends):
 *  - Every check happens before a single byte is stored: the upload must be a
 *    real HTTP upload, within the size limit, with an extension on the field's
 *    list (which is always limited to a safe site-wide list: no SVG, HTML, PHP,
 *    JS …) and with content that matches that extension (WordPress' real MIME
 *    check; images must also be readable as images).
 *  - Files are only moved into place when the whole submission is valid.
 *  - They are stored under a random name ending in ".bestand" (never executable,
 *    never shown inline) in uploads/.ht-goedehosting-formulieren, which denies
 *    web access (.htaccess for Apache; the usual nginx rules deny "/.ht").
 *  - Only users who may edit the submission (administrators) can download a
 *    file, through an admin-post URL with a nonce; it is always sent as an
 *    attachment with nosniff.
 *  - Files are deleted together with their submission (retention, privacy
 *    eraser, emptying the trash).
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extensions any file field may accept at most (filter goede_hosting_form_upload_extensions).
 *
 * @return string[]
 */
function goede_hosting_form_allowed_extensions() {
	$safe = array( 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'zip' );
	$list = (array) apply_filters( 'goede_hosting_form_upload_extensions', $safe );
	// Never anything a browser or server could run, whatever a filter returns.
	$never = array( 'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp', 'exe', 'js', 'mjs', 'html', 'htm', 'shtml', 'xhtml', 'svg', 'svgz', 'xml', 'htaccess', 'swf' );
	return array_values( array_diff( array_unique( array_map( 'strtolower', array_map( 'sanitize_key', $list ) ) ), $never ) );
}

/**
 * Extensions one field accepts: its own list (comma or space separated) limited
 * to the safe list; a sensible default when empty.
 *
 * @param array $field Normalised field.
 * @return string[]
 */
function goede_hosting_form_field_extensions( $field ) {
	$own  = array_filter( array_map( 'strtolower', array_map( 'trim', preg_split( '/[\s,;.]+/', (string) $field['accept'] ) ) ) );
	$own  = $own ? $own : array( 'pdf', 'jpg', 'jpeg', 'png', 'docx' );
	$list = array_values( array_intersect( goede_hosting_form_allowed_extensions(), $own ) );
	return $list ? $list : array( 'pdf' );
}

/**
 * MIME types for a list of extensions (from WordPress' own list).
 *
 * @param string[] $extensions Extensions.
 * @return array<string, string> "ext" => mime, as wp_check_filetype_and_ext() wants it.
 */
function goede_hosting_form_mimes( $extensions ) {
	$mimes = array();
	foreach ( wp_get_mime_types() as $pattern => $mime ) {
		foreach ( explode( '|', $pattern ) as $ext ) {
			if ( in_array( $ext, $extensions, true ) ) {
				$mimes[ $ext ] = $mime;
			}
		}
	}
	return $mimes;
}

/**
 * Checks the uploads of the visible file fields. Nothing is stored yet.
 *
 * @param array $fields  Normalised fields.
 * @param array $visible key => visible.
 * @return array{errors: array<string, string>, pending: array<string, array>}
 */
function goede_hosting_form_check_uploads( $fields, $visible ) {
	$errors  = array();
	$pending = array();
	foreach ( $fields as $key => $field ) {
		if ( 'file' !== $field['type'] || empty( $visible[ $key ] ) ) {
			continue;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked by the handler; every part of the upload is validated in goede_hosting_form_check_one_upload().
		$file = isset( $_FILES[ $key ] ) && is_array( $_FILES[ $key ] ) ? $_FILES[ $key ] : null;
		$none = ! $file || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === $file['error'];
		if ( $none ) {
			if ( $field['required'] ) {
				/* translators: %s: field label. */
				$errors[ $key ] = '' !== $field['error'] ? $field['error'] : sprintf( __( 'Kies een bestand voor %s.', 'goede-hosting' ), strtolower( $field['label'] ) );
			}
			continue;
		}
		$error = goede_hosting_form_check_one_upload( $file, $field );
		if ( '' !== $error ) {
			$errors[ $key ] = $error;
			continue;
		}
		$pending[ $key ] = $file;
	}
	return array(
		'errors'  => $errors,
		'pending' => $pending,
	);
}

/**
 * All checks for one uploaded file. Returns an error message or ''.
 *
 * @param array $file  Entry of $_FILES.
 * @param array $field Normalised field.
 * @return string
 */
function goede_hosting_form_check_one_upload( $file, $field ) {
	$generic = __( 'Dit bestand kan niet worden geüpload. Probeer een ander bestand.', 'goede-hosting' );
	// One file per field: arrays (name="x[]") are refused.
	foreach ( array( 'name', 'tmp_name', 'size', 'error' ) as $part ) {
		if ( ! isset( $file[ $part ] ) || is_array( $file[ $part ] ) ) {
			return $generic;
		}
	}
	if ( UPLOAD_ERR_INI_SIZE === (int) $file['error'] || UPLOAD_ERR_FORM_SIZE === (int) $file['error'] ) {
		/* translators: %s: size in MB. */
		return sprintf( __( 'Dit bestand is te groot (maximaal %s MB).', 'goede-hosting' ), number_format_i18n( $field['max_size'], 1 ) );
	}
	if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return $generic;
	}
	$size = (int) $file['size'];
	if ( $size <= 0 || $size > $field['max_size'] * MB_IN_BYTES || filesize( $file['tmp_name'] ) !== $size ) {
		/* translators: %s: size in MB. */
		return sprintf( __( 'Dit bestand is te groot (maximaal %s MB).', 'goede-hosting' ), number_format_i18n( $field['max_size'], 1 ) );
	}

	$allowed = goede_hosting_form_field_extensions( $field );
	$name    = sanitize_file_name( wp_basename( (string) $file['name'] ) );
	$ext     = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
	/* translators: %s: list of extensions. */
	$type_error = sprintf( __( 'Dit bestandstype is niet toegestaan. Toegestaan: %s.', 'goede-hosting' ), implode( ', ', $allowed ) );
	if ( '' === $ext || ! in_array( $ext, $allowed, true ) ) {
		return $type_error;
	}
	// "file.php.jpg" style names: every part of the name must be harmless.
	$parts = explode( '.', strtolower( $name ) );
	array_shift( $parts );
	if ( array_intersect( $parts, array( 'php', 'phtml', 'phar', 'pht', 'cgi', 'pl', 'asp', 'aspx', 'jsp', 'exe', 'js', 'html', 'htm', 'svg' ) ) ) {
		return $type_error;
	}
	// Real content check: the file must be what its extension says.
	$check = wp_check_filetype_and_ext( $file['tmp_name'], $name, goede_hosting_form_mimes( $allowed ) );
	if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! in_array( strtolower( (string) $check['ext'] ), $allowed, true ) ) {
		return $type_error;
	}
	if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) && false === @getimagesize( $file['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a broken image is a validation result, not an error to show.
		return $type_error;
	}
	return '';
}

/**
 * Directory for uploaded files (created with deny rules on first use), or ''.
 *
 * @return string Absolute path without trailing slash.
 */
function goede_hosting_form_upload_dir() {
	$uploads = wp_upload_dir( null, false );
	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
		return '';
	}
	$dir = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . '/.ht-goedehosting-formulieren';
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return '';
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		file_put_contents( $dir . '/.htaccess', "# Goede Hosting forms: private uploads\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny static file in our own directory.
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- idem.
	}
	return $dir;
}

/**
 * Moves the checked uploads into place.
 *
 * @param array $pending key => $_FILES entry (already checked).
 * @return array<string, array{name: string, file: string, size: int, type: string}> file = name inside the upload dir.
 */
function goede_hosting_form_store_uploads( $pending ) {
	$stored = array();
	$dir    = $pending ? goede_hosting_form_upload_dir() : '';
	foreach ( $pending as $key => $file ) {
		if ( '' === $dir ) {
			break;
		}
		$name   = sanitize_file_name( wp_basename( (string) $file['name'] ) );
		$check  = wp_check_filetype( $name );
		$target = strtolower( wp_generate_password( 32, false, false ) ) . '.bestand';
		if ( ! @move_uploaded_file( $file['tmp_name'], $dir . '/' . $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is handled below.
			continue;
		}
		chmod( $dir . '/' . $target, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- our own file.
		$stored[ $key ] = array(
			'name' => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 180 ) : substr( $name, 0, 180 ),
			'file' => $target,
			'size' => (int) $file['size'],
			'type' => (string) $check['type'],
		);
	}
	return $stored;
}

/**
 * Absolute path of a stored file, or '' when it is not one of ours.
 *
 * @param string $file Stored name.
 * @return string
 */
function goede_hosting_form_file_path( $file ) {
	if ( ! is_string( $file ) || ! preg_match( '/^[a-z0-9]{32}\.bestand$/', $file ) ) {
		return '';
	}
	$dir  = goede_hosting_form_upload_dir();
	$path = '' !== $dir ? $dir . '/' . $file : '';
	return '' !== $path && is_file( $path ) ? $path : '';
}

/**
 * Deletes stored files.
 *
 * @param array $files Stored files (as in _gh_bestanden).
 */
function goede_hosting_form_delete_files( $files ) {
	foreach ( is_array( $files ) ? $files : array() as $file ) {
		$path = is_array( $file ) ? goede_hosting_form_file_path( $file['file'] ?? '' ) : '';
		if ( '' !== $path ) {
			wp_delete_file( $path );
		}
	}
}

/**
 * Files of a submission.
 *
 * @param int $entry_id Submission ID.
 * @return array
 */
function goede_hosting_form_entry_files( $entry_id ) {
	$files = get_post_meta( $entry_id, '_gh_bestanden', true );
	return is_array( $files ) ? $files : array();
}

/**
 * Removes the files when a submission is deleted for good.
 *
 * @param int $post_id Post ID.
 */
function goede_hosting_form_delete_entry_files( $post_id ) {
	if ( GOEDE_HOSTING_INZENDING === get_post_type( $post_id ) ) {
		goede_hosting_form_delete_files( goede_hosting_form_entry_files( $post_id ) );
	}
}
add_action( 'before_delete_post', 'goede_hosting_form_delete_entry_files' );

/**
 * Download link of a stored file (for the submission screen).
 *
 * @param int    $entry_id Submission ID.
 * @param string $key      Field key.
 * @return string Unescaped URL.
 */
function goede_hosting_form_download_url( $entry_id, $key ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'goede_hosting_bestand',
				'id'     => (int) $entry_id,
				'veld'   => $key,
			),
			admin_url( 'admin-post.php' )
		),
		'goede_hosting_bestand_' . (int) $entry_id . '_' . $key
	);
}

/**
 * Sends a stored file to an administrator (capability + nonce, always as a
 * download, never shown inline).
 */
function goede_hosting_form_download() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified with check_admin_referer() below.
	$entry_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
	$key      = isset( $_GET['veld'] ) ? sanitize_key( wp_unslash( $_GET['veld'] ) ) : '';
	// phpcs:enable
	check_admin_referer( 'goede_hosting_bestand_' . $entry_id . '_' . $key );
	if ( ! $entry_id || GOEDE_HOSTING_INZENDING !== get_post_type( $entry_id ) || ! current_user_can( 'edit_post', $entry_id ) ) {
		wp_die( esc_html__( 'Je hebt geen toegang tot dit bestand.', 'goede-hosting' ), 403 );
	}
	$files = goede_hosting_form_entry_files( $entry_id );
	$file  = isset( $files[ $key ] ) && is_array( $files[ $key ] ) ? $files[ $key ] : null;
	$path  = $file ? goede_hosting_form_file_path( $file['file'] ?? '' ) : '';
	if ( '' === $path ) {
		wp_die( esc_html__( 'Dit bestand bestaat niet (meer).', 'goede-hosting' ), 404 );
	}
	$name = sanitize_file_name( (string) $file['name'] );
	nocache_headers();
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
	header( 'Content-Length: ' . (string) filesize( $path ) );
	header( 'X-Content-Type-Options: nosniff' );
	header( "Content-Security-Policy: default-src 'none'; sandbox" );
	readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a private file.
	exit;
}
add_action( 'admin_post_goede_hosting_bestand', 'goede_hosting_form_download' );
