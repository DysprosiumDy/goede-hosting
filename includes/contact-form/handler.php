<?php
/**
 * Contact form: submission handling (admin-post.php?action=goedehosting_contact).
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/**
 * The visitor's IP address.
 *
 * REMOTE_ADDR, unless the site sits behind a proxy (Cloudflare, a load balancer)
 * that doesn't restore the real address: then name the header that proxy sends,
 * e.g. define( 'GOEDE_HOSTING_PROXY_IP_HEADER', 'HTTP_CF_CONNECTING_IP' ); in
 * wp-config.php, or use the filter goede_hosting_contact_proxy_ip_header. Only set
 * it when every request really passes that proxy, or visitors could fake their
 * address.
 *
 * @return string
 */
function goede_hosting_contact_client_ip() {
	$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$header = (string) apply_filters( 'goede_hosting_contact_proxy_ip_header', defined( 'GOEDE_HOSTING_PROXY_IP_HEADER' ) ? (string) GOEDE_HOSTING_PROXY_IP_HEADER : '' );
	$header = strtoupper( (string) preg_replace( '/[^A-Za-z0-9_]/', '', $header ) );
	if ( '' !== $header && isset( $_SERVER[ $header ] ) && is_string( $_SERVER[ $header ] ) ) {
		// X-Forwarded-For style lists: the first entry is the visitor.
		$parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
		$first = trim( $parts[0] );
		if ( false !== filter_var( $first, FILTER_VALIDATE_IP ) ) {
			$ip = $first;
		}
	}
	return $ip;
}

/**
 * Hashed client IP, only used for the rate limit (never stored with a submission).
 *
 * @return string
 */
function goede_hosting_contact_ip_hash() {
	$ip = goede_hosting_contact_client_ip();
	// IPv6: count per /64 network, one connection can pick any address inside it.
	if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
		$packed = inet_pton( $ip );
		if ( false !== $packed ) {
			$ip = bin2hex( substr( $packed, 0, 8 ) ) . '::/64';
		}
	}
	return wp_hash( 'goedehosting_ip|' . $ip );
}

/**
 * Counts one attempt in an hourly bucket.
 *
 * @param string $key   Transient key.
 * @param int    $limit Maximum attempts per hour.
 * @return bool False when the limit was already reached (the attempt is not counted).
 */
function goede_hosting_contact_count_attempt( $key, $limit ) {
	$rate = get_transient( $key );
	$rate = is_array( $rate ) && isset( $rate['count'], $rate['start'] ) ? $rate : array(
		'count' => 0,
		'start' => time(),
	);
	if ( (int) $rate['count'] >= $limit ) {
		return false;
	}
	$rate['count'] = (int) $rate['count'] + 1;
	set_transient( $key, $rate, max( 60, HOUR_IN_SECONDS - ( time() - (int) $rate['start'] ) ) );
	return true;
}

/**
 * Block names whose "recipient" attribute may set the recipient of a form on
 * that page (themes with their own contact block add theirs).
 *
 * @return string[]
 */
function goede_hosting_contact_form_blocks() {
	return (array) apply_filters( 'goede_hosting_contact_form_blocks', array( 'goede-hosting/contact-form' ) );
}

/**
 * Finds the recipient attribute of a contact form block (also in nested blocks).
 *
 * @param array    $blocks Parsed blocks.
 * @param string[] $names  Block names.
 * @return string|null Recipient, or null when no such block is found.
 */
function goede_hosting_contact_find_recipient( $blocks, $names ) {
	foreach ( $blocks as $block ) {
		if ( isset( $block['blockName'] ) && in_array( $block['blockName'], $names, true ) ) {
			return isset( $block['attrs']['recipient'] ) && is_string( $block['attrs']['recipient'] ) ? $block['attrs']['recipient'] : '';
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$found = goede_hosting_contact_find_recipient( $block['innerBlocks'], $names );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	return null;
}

/**
 * Recipient for a submission: the block's own address (pages by administrators
 * only), Inzendingen > Instellingen, the company e-mail, the site admin e-mail.
 *
 * @param int $post_id Post that holds the form.
 * @return string
 */
function goede_hosting_contact_recipient( $post_id ) {
	$candidates = array();
	$post       = $post_id ? get_post( $post_id ) : null;
	// The block's own recipient only counts on pages written and last edited by an
	// administrator; otherwise an author could make the site mail any address.
	$editor = $post ? (int) get_post_meta( $post->ID, '_edit_last', true ) : 0;
	if ( $post && 'page' === $post->post_type && 'publish' === $post->post_status && has_blocks( $post->post_content )
		&& user_can( (int) $post->post_author, 'manage_options' ) && ( ! $editor || user_can( $editor, 'manage_options' ) ) ) {
		$candidates[] = (string) goede_hosting_contact_find_recipient( parse_blocks( $post->post_content ), goede_hosting_contact_form_blocks() );
	}
	$candidates[] = (string) get_option( 'goede_hosting_contact_recipient', '' );
	if ( function_exists( 'goede_hosting_company' ) ) {
		$candidates[] = goede_hosting_company( 'email' );
	}
	$candidates[] = (string) get_option( 'admin_email' );

	$recipient = '';
	foreach ( $candidates as $candidate ) {
		$candidate = sanitize_email( $candidate );
		if ( is_email( $candidate ) ) {
			$recipient = $candidate;
			break;
		}
	}
	return (string) apply_filters( 'goede_hosting_contact_recipient', $recipient, $post_id );
}

/**
 * URL to send the visitor back to (without our query args).
 *
 * @param int $post_id Post that holds the form.
 * @return string
 */
function goede_hosting_contact_return_url( $post_id ) {
	$url  = '';
	$post = $post_id ? get_post( $post_id ) : null;
	if ( $post && 'publish' === $post->post_status ) {
		$url = (string) get_permalink( $post );
	}
	if ( '' === $url ) {
		$url = (string) wp_get_referer();
	}
	if ( '' === $url ) {
		$url = home_url( '/' );
	}
	$url = (string) strtok( $url, '#' );
	return remove_query_arg( array( 'verzonden', 'gh_fout' ), $url );
}

/**
 * Redirects back to the form.
 *
 * @param int    $post_id Post that holds the form.
 * @param string $arg     Query arg name.
 * @param string $value   Query arg value.
 */
function goede_hosting_contact_redirect( $post_id, $arg, $value ) {
	$anchor = (string) apply_filters( 'goede_hosting_contact_anchor', 'contactformulier' );
	wp_safe_redirect( add_query_arg( $arg, $value, goede_hosting_contact_return_url( $post_id ) ) . ( '' !== $anchor ? '#' . rawurlencode( $anchor ) : '' ) );
	exit;
}

/**
 * Redirects back with a fixed error code; stores nothing, so requests that never
 * passed the session checks can't fill the database.
 *
 * @param int    $post_id Post that holds the form.
 * @param string $code    Key of goede_hosting_contact_messages().
 */
function goede_hosting_contact_fail_code( $post_id, $code ) {
	goede_hosting_contact_redirect( $post_id, 'gh_fout', $code );
}

/**
 * Redirects back with the entered values and field errors (kept 15 minutes).
 *
 * @param int                  $post_id Post that holds the form.
 * @param array<string,string> $values  Entered values.
 * @param array<string,string> $errors  Field errors.
 * @param string               $code    Optional key of goede_hosting_contact_messages()
 *                                      for a general error (expired session …).
 */
function goede_hosting_contact_fail( $post_id, $values, $errors, $code = '' ) {
	$messages = goede_hosting_contact_messages();
	$token    = strtolower( wp_generate_password( 20, false, false ) );
	set_transient(
		'goedehosting_contact_' . $token,
		array(
			'values'  => $values,
			'errors'  => $errors,
			'general' => isset( $messages[ $code ] ) ? $messages[ $code ] : '',
		),
		15 * MINUTE_IN_SECONDS
	);
	goede_hosting_contact_redirect( $post_id, 'gh_fout', $token );
}

/**
 * Handles a submission.
 */
function goede_hosting_contact_handle() {
	$fields = goede_hosting_contact_fields();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is verified below, after reading the values to keep on error.
	$post_id = isset( $_POST['gh_pagina'] ) ? absint( wp_unslash( $_POST['gh_pagina'] ) ) : 0;

	$values = array();
	foreach ( $fields as $name => $field ) {
		$raw = '';
		if ( isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ) {
			$raw = 'textarea' === $field['type'] ? sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) ) : sanitize_text_field( wp_unslash( $_POST[ $name ] ) );
		}
		$raw             = trim( $raw );
		$values[ $name ] = function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, $field['maxlength'] ) : substr( $raw, 0, $field['maxlength'] );
	}

	$honeypot = isset( $_POST['gh_website'] ) && is_string( $_POST['gh_website'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['gh_website'] ) ) ) : '';
	$stamp    = isset( $_POST['gh_tijd'] ) && is_string( $_POST['gh_tijd'] ) ? sanitize_text_field( wp_unslash( $_POST['gh_tijd'] ) ) : '';
	$nonce    = isset( $_POST['gh_nonce'] ) && is_string( $_POST['gh_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['gh_nonce'] ) ) : '';
	// phpcs:enable

	// Bots that fill in the hidden field get a fake success and nothing is stored.
	if ( '' !== $honeypot ) {
		goede_hosting_contact_redirect( $post_id, 'verzonden', '1' );
	}

	// Every attempt counts toward the limit per IP address, also ones that fail the
	// checks below: those keep the entered values for a few minutes, so this caps
	// how much one visitor can store.
	if ( ! goede_hosting_contact_count_attempt( 'goedehosting_contact_rl_' . substr( goede_hosting_contact_ip_hash(), 0, 32 ), GOEDE_HOSTING_CONTACT_RATE_LIMIT ) ) {
		goede_hosting_contact_fail_code( $post_id, 'limiet' );
	}

	// Expired nonce or form timestamp, typically a page served from a cache: send the
	// visitor back with what they typed (the new URL is not cached, so the form
	// they get then is fresh).
	$parts = explode( '.', $stamp, 2 );
	$valid = 2 === count( $parts ) && ctype_digit( $parts[0] ) && hash_equals( goede_hosting_contact_stamp_hash( $parts[0] ), $parts[1] );
	$age   = $valid ? time() - (int) $parts[0] : 0;
	if ( ! wp_verify_nonce( $nonce, 'goedehosting_contact' ) || ( $valid && $age > GOEDE_HOSTING_CONTACT_MAX_AGE ) ) {
		goede_hosting_contact_fail( $post_id, $values, array(), 'sessie' );
	}
	if ( ! $valid || $age < GOEDE_HOSTING_CONTACT_MIN_SECONDS ) {
		goede_hosting_contact_fail( $post_id, $values, array(), 'snel' );
	}

	$errors = array();
	foreach ( $fields as $name => $field ) {
		if ( '' === $values[ $name ] ) {
			if ( $field['required'] ) {
				/* translators: %s: field label. */
				$errors[ $name ] = '' !== $field['error'] ? $field['error'] : sprintf( __( 'Vul je %s in.', 'goede-hosting' ), strtolower( $field['label'] ) );
			}
			continue;
		}
		if ( 'email' === $field['type'] && ! is_email( $values[ $name ] ) ) {
			$errors[ $name ] = __( 'Vul een geldig e-mailadres in.', 'goede-hosting' );
		} elseif ( 'url' === $field['type'] && ! wp_http_validate_url( $values[ $name ] ) ) {
			$errors[ $name ] = __( 'Vul een geldig webadres in (met https://).', 'goede-hosting' );
		}
	}
	$errors = (array) apply_filters( 'goede_hosting_contact_errors', $errors, $values, $fields );
	if ( $errors ) {
		goede_hosting_contact_fail( $post_id, $values, $errors );
	}

	// Site-wide cap (many addresses at once): above it the message is still stored
	// and the visitor sees the normal confirmation, but no mail goes out, so a flood
	// can neither fill the mailbox nor block the form for real visitors.
	$over_limit = ! goede_hosting_contact_count_attempt( 'goedehosting_contact_rl_site', GOEDE_HOSTING_CONTACT_SITE_LIMIT );

	$email_key = goede_hosting_contact_email_key( $fields );
	$email     = '' !== $email_key ? strtolower( $values[ $email_key ] ) : '';
	$recipient = goede_hosting_contact_recipient( $post_id );
	$source    = goede_hosting_contact_return_url( $post_id );

	$meta = array(
		'_gh_velden'         => wp_list_pluck( $fields, 'label' ),
		'_gh_email'          => $email,
		'_gh_pagina'         => esc_url_raw( $source ),
		'_gh_ontvanger'      => $recipient,
		'_gh_mail_verzonden' => '0',
		'_gh_limiet'         => $over_limit ? '1' : '0',
	);
	foreach ( $values as $name => $value ) {
		$meta[ '_gh_' . $name ] = $email_key === $name ? $email : $value;
	}

	$entry_id = wp_insert_post(
		array(
			'post_type'    => GOEDE_HOSTING_INZENDING,
			'post_status'  => 'private',
			'post_title'   => goede_hosting_contact_summary( $values, $fields ),
			'post_content' => '',
			'post_author'  => 0,
			'meta_input'   => $meta,
		),
		true
	);
	$entry_id = is_wp_error( $entry_id ) ? 0 : (int) $entry_id;

	$sent = $over_limit ? false : goede_hosting_contact_send_mail( $values, $fields, $recipient, $entry_id, $source );
	if ( $entry_id ) {
		update_post_meta( $entry_id, '_gh_mail_verzonden', $sent ? '1' : '0' );
	}

	if ( ! $entry_id && ! $sent ) {
		goede_hosting_contact_fail_code( $post_id, 'mislukt' );
	}

	do_action( 'goede_hosting_contact_submitted', $entry_id, $values, $sent );
	goede_hosting_contact_redirect( $post_id, 'verzonden', '1' );
}
add_action( 'admin_post_goedehosting_contact', 'goede_hosting_contact_handle' );
add_action( 'admin_post_nopriv_goedehosting_contact', 'goede_hosting_contact_handle' );

/**
 * Mails a submission to the recipient with Reply-To the sender.
 *
 * @param array<string,string> $values    Sanitised values.
 * @param array                $fields    Field definitions.
 * @param string               $recipient Recipient address.
 * @param int                  $entry_id  Stored submission ID (0 if storing failed).
 * @param string               $source    Page URL.
 * @return bool
 */
function goede_hosting_contact_send_mail( $values, $fields, $recipient, $entry_id, $source ) {
	if ( ! is_email( $recipient ) ) {
		return false;
	}
	$plain = function ( $text ) {
		return wp_specialchars_decode( (string) $text, ENT_QUOTES );
	};

	/* translators: %s: short description of the submission (name and summary fields). */
	$subject = sprintf( __( 'Contactformulier: %s', 'goede-hosting' ), $plain( goede_hosting_contact_summary( $values, $fields ) ) );

	$lines   = array( __( 'Er is een nieuw bericht verstuurd via het contactformulier.', 'goede-hosting' ), '' );
	$long    = array();
	$lines[] = __( 'Pagina:', 'goede-hosting' ) . ' ' . $source;
	foreach ( $fields as $key => $field ) {
		if ( 'textarea' === $field['type'] ) {
			$long[] = '';
			$long[] = $field['label'] . ':';
			$long[] = $plain( $values[ $key ] );
		} else {
			$lines[] = $field['label'] . ': ' . $plain( $values[ $key ] );
		}
	}
	$lines = array_merge( $lines, $long );
	if ( $entry_id ) {
		$lines[] = '';
		$lines[] = __( 'Bekijk de inzending:', 'goede-hosting' ) . ' ' . admin_url( 'post.php?post=' . $entry_id . '&action=edit' );
	}

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	$from    = goede_hosting_contact_from_header();
	if ( '' !== $from ) {
		$headers[] = $from;
	}
	$email_key = goede_hosting_contact_email_key( $fields );
	if ( '' !== $email_key && is_email( $values[ $email_key ] ) ) {
		// Header-safe display name: no quotes, angle brackets, commas or line breaks.
		$reply_name = trim( (string) preg_replace( '/[\s"<>,;]+/', ' ', $plain( goede_hosting_contact_sender_name( $values, $fields ) ) ) );
		$headers[]  = 'Reply-To: ' . ( '' !== $reply_name ? '"' . $reply_name . '" <' . $values[ $email_key ] . '>' : $values[ $email_key ] );
	}

	return (bool) wp_mail( $recipient, $subject, implode( "\n", $lines ), $headers );
}

/**
 * From header for the notification mail: the site name with an address on the
 * site's own domain, so SPF/DKIM of that domain can apply. Uses the company
 * e-mail when it is on the site's domain, otherwise wordpress@<domain> (what
 * WordPress itself would use). Filter goede_hosting_contact_from_email to pick
 * another address, or return '' to leave the From header to WordPress / an SMTP
 * plugin.
 *
 * @return string Header line, or ''.
 */
function goede_hosting_contact_from_header() {
	$host = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
	$host = (string) preg_replace( '/^www\./', '', $host );
	if ( '' === $host ) {
		return '';
	}
	$company = function_exists( 'goede_hosting_company' ) ? sanitize_email( goede_hosting_company( 'email' ) ) : '';
	$default = is_email( $company ) && strtolower( substr( strrchr( $company, '@' ), 1 ) ) === $host ? $company : 'wordpress@' . $host;
	$address = sanitize_email( (string) apply_filters( 'goede_hosting_contact_from_email', $default ) );
	if ( ! is_email( $address ) ) {
		return '';
	}
	$name = trim( (string) preg_replace( '/[\s"<>,;]+/', ' ', wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ) );
	return 'From: ' . ( '' !== $name ? '"' . $name . '" <' . $address . '>' : $address );
}
