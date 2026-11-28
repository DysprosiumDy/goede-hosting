<?php
/**
 * Module "contact-form": a contact form whose submissions are mailed and stored
 * as private, read-only posts ("Inzendingen", post type gh_inzending).
 *
 * Use it with the block "Goede Hosting Contactformulier" or from PHP with
 * goede_hosting_contact_form( $args ). Adjustable per site:
 *
 *  - fields: filter goede_hosting_contact_fields (default: naam, e-mail, bericht);
 *  - markup: copy templates/contact-form.php to <theme>/goede-hosting/contact-form.php;
 *  - styling: assets/css/contact-form.css, off with
 *    add_filter( 'goede_hosting_contact_form_css', '__return_false' );
 *  - recipient and retention: Inzendingen > Instellingen.
 *
 * Flow: the form posts to admin-post.php (action goedehosting_contact). The
 * handler checks the honeypot and the limit per IP address (every attempt
 * counts), then the nonce and form timestamp (minimum fill time, maximum age),
 * validates the fields, stores the submission, mails it with Reply-To the sender
 * (unless the site-wide hourly cap is reached: then it is only stored) and
 * redirects back: ?verzonden=1 on success, ?gh_fout=<code> for limit or send
 * errors (nothing stored), ?gh_fout=<token> for an expired session, a too fast
 * submission or field errors (the entered values wait 15 minutes in a transient,
 * so nothing typed is lost). The IP address is only used, hashed, for the limit
 * and is not stored with the submission.
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/** Post type of stored submissions. */
const GOEDE_HOSTING_INZENDING = 'gh_inzending';

/** Daily cleanup cron hook. */
const GOEDE_HOSTING_INZENDING_CRON = 'goedehosting_inzendingen_opruimen';

/** Seconds a human needs at least to fill in the form. */
const GOEDE_HOSTING_CONTACT_MIN_SECONDS = 3;

/** Attempts per IP address (IPv6: per /64 network) per hour. */
const GOEDE_HOSTING_CONTACT_RATE_LIMIT = 5;

/**
 * Submissions for the whole site per hour that are mailed. Above it they are only
 * stored (spread-out abuse can't fill the mailbox or block the form).
 */
const GOEDE_HOSTING_CONTACT_SITE_LIMIT = 30;

/** Seconds a form stays valid after the page was generated (2 hours). */
const GOEDE_HOSTING_CONTACT_MAX_AGE = 7200;

/**
 * Form fields. Each field:
 *
 *  - label        string  Label (also used in the mail and the admin).
 *  - type         string  text | email | tel | url | textarea.
 *  - required     bool
 *  - maxlength    int
 *  - autocomplete string  Also marks the sender's name: name, given-name, family-name.
 *  - placeholder  string
 *  - summary      bool    Shown in the submission title, e.g. "Naam (summary)".
 *  - group        string  Fields with the same group are shown together (one legend).
 *  - group_label  string  Legend of the group (taken from its first field).
 *  - error        string  Message when a required field is empty.
 *
 * The first email field is the sender's address (Reply-To, privacy tools).
 *
 * @return array<string, array>
 */
function goede_hosting_contact_fields() {
	$fields = array(
		'naam'    => array(
			'label'        => __( 'Naam', 'goede-hosting' ),
			'type'         => 'text',
			'required'     => true,
			'maxlength'    => 200,
			'autocomplete' => 'name',
		),
		'email'   => array(
			'label'        => __( 'E-mail', 'goede-hosting' ),
			'type'         => 'email',
			'required'     => true,
			'maxlength'    => 254,
			'autocomplete' => 'email',
		),
		'bericht' => array(
			'label'     => __( 'Bericht', 'goede-hosting' ),
			'type'      => 'textarea',
			'required'  => true,
			'maxlength' => 5000,
			'error'     => __( 'Vul een bericht in.', 'goede-hosting' ),
		),
	);
	return goede_hosting_form_normalize_fields( apply_filters( 'goede_hosting_contact_fields', $fields ) );
}

/**
 * Fields of a submission: those of the form post with this ID, or (0) the
 * contact form of the theme.
 *
 * @param int $form_id Form post ID, 0 for the theme's contact form.
 * @return array<string, array>
 */
function goede_hosting_form_fields_for( $form_id ) {
	return $form_id ? goede_hosting_form_fields( $form_id ) : goede_hosting_contact_fields();
}

/**
 * Key of the sender's e-mail field ('' when the form has none).
 *
 * @param array|null $fields Fields (default: the current ones).
 * @return string
 */
function goede_hosting_contact_email_key( $fields = null ) {
	foreach ( null === $fields ? goede_hosting_contact_fields() : $fields as $key => $field ) {
		if ( 'email' === $field['type'] ) {
			return $key;
		}
	}
	return '';
}

/**
 * Sender's name from the name fields (autocomplete name / given-name / family-name).
 *
 * @param array<string,string> $values Values.
 * @param array|null           $fields Fields (default: the current ones).
 * @return string
 */
function goede_hosting_contact_sender_name( $values, $fields = null ) {
	$parts = array();
	foreach ( null === $fields ? goede_hosting_contact_fields() : $fields as $key => $field ) {
		if ( in_array( $field['autocomplete'], array( 'name', 'given-name', 'family-name' ), true ) && ! empty( $values[ $key ] ) ) {
			$parts[] = $values[ $key ];
		}
	}
	return trim( implode( ' ', $parts ) );
}

/**
 * Short description of a submission: "Name (summary fields)", falling back to the e-mail.
 *
 * @param array<string,string> $values Values.
 * @param array|null           $fields Fields (default: the current ones).
 * @return string
 */
function goede_hosting_contact_summary( $values, $fields = null ) {
	$fields  = null === $fields ? goede_hosting_contact_fields() : $fields;
	$name    = goede_hosting_contact_sender_name( $values, $fields );
	$extra   = array();
	$email_k = goede_hosting_contact_email_key( $fields );
	foreach ( $fields as $key => $field ) {
		if ( $field['summary'] && ! empty( $values[ $key ] ) ) {
			$extra[] = $values[ $key ];
		}
	}
	if ( '' === $name ) {
		$name = '' !== $email_k && ! empty( $values[ $email_k ] ) ? $values[ $email_k ] : __( '(geen naam)', 'goede-hosting' );
	}
	return $extra ? $name . ' (' . implode( ', ', $extra ) . ')' : $name;
}

/**
 * Fixed error messages, passed as ?gh_fout=<code> (nothing is stored for these).
 *
 * @return array<string, string>
 */
function goede_hosting_contact_messages() {
	return array(
		'sessie'  => __( 'Je sessie is verlopen. Je bericht staat er nog: verstuur het formulier opnieuw.', 'goede-hosting' ),
		'snel'    => __( 'Dat ging wel erg snel. Wacht een paar seconden en verstuur het formulier opnieuw.', 'goede-hosting' ),
		'limiet'  => __( 'Vanaf jouw verbinding zijn het afgelopen uur al veel berichten verstuurd. Probeer het later opnieuw.', 'goede-hosting' ),
		'mislukt' => __( 'Je bericht kon niet worden verstuurd. Probeer het later opnieuw.', 'goede-hosting' ),
	);
}

/**
 * Retention period for submissions in months (0 = never delete).
 *
 * @return int
 */
function goede_hosting_contact_retention() {
	$months = get_option( 'goedehosting_inzending_bewaartermijn', 24 );
	return is_numeric( $months ) ? max( 0, (int) $months ) : 24;
}

require_once __DIR__ . '/fields.php';
require_once __DIR__ . '/forms.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/handler.php';
require_once __DIR__ . '/submissions.php';
require_once __DIR__ . '/privacy.php';
