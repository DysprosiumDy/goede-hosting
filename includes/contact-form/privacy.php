<?php
/**
 * Contact form: WordPress personal data exporter and eraser (Tools > Export /
 * Erase Personal Data), matched on the sender's e-mail address (_gh_email).
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Submission IDs for an e-mail address (one page).
 *
 * @param string $email E-mail address.
 * @param int    $page  Page number (1-based).
 * @return int[]
 */
function goede_hosting_inzending_ids_by_email( $email, $page ) {
	$email = strtolower( trim( (string) $email ) );
	if ( '' === $email ) {
		return array();
	}
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'        => GOEDE_HOSTING_INZENDING,
				'post_status'      => array( 'private', 'publish', 'draft', 'pending', 'trash' ),
				'fields'           => 'ids',
				'posts_per_page'   => 50,
				'paged'            => max( 1, (int) $page ),
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- privacy requests are rare.
					array(
						'key'   => '_gh_email',
						'value' => $email,
					),
				),
			)
		)
	);
}

/**
 * Registers the exporter.
 *
 * @param array $exporters Exporters.
 * @return array
 */
function goede_hosting_inzending_register_exporter( $exporters ) {
	$exporters['goedehosting-inzendingen'] = array(
		'exporter_friendly_name' => __( 'Contactformulier (Goede Hosting)', 'goede-hosting' ),
		'callback'               => 'goede_hosting_inzending_exporter',
	);
	return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'goede_hosting_inzending_register_exporter' );

/**
 * Exports the submissions of an e-mail address.
 *
 * @param string $email E-mail address.
 * @param int    $page  Page number.
 * @return array
 */
function goede_hosting_inzending_exporter( $email, $page = 1 ) {
	$ids   = goede_hosting_inzending_ids_by_email( $email, $page );
	$items = array();
	foreach ( $ids as $id ) {
		$data = array(
			array(
				'name'  => __( 'Datum', 'goede-hosting' ),
				'value' => (string) get_the_date( 'Y-m-d H:i', $id ),
			),
		);
		foreach ( goede_hosting_inzending_fields( $id ) as $key => $label ) {
			$data[] = array(
				'name'  => $label,
				'value' => goede_hosting_inzending_meta( $id, $key ),
			);
		}
		$data[]  = array(
			'name'  => __( 'Verstuurd vanaf', 'goede-hosting' ),
			'value' => goede_hosting_inzending_meta( $id, 'pagina' ),
		);
		$items[] = array(
			'group_id'          => 'goedehosting-inzendingen',
			'group_label'       => __( 'Contactformulier', 'goede-hosting' ),
			'group_description' => __( 'Berichten die je via het contactformulier hebt verstuurd.', 'goede-hosting' ),
			'item_id'           => 'inzending-' . $id,
			'data'              => $data,
		);
	}
	return array(
		'data' => $items,
		'done' => count( $ids ) < 50,
	);
}

/**
 * Registers the eraser.
 *
 * @param array $erasers Erasers.
 * @return array
 */
function goede_hosting_inzending_register_eraser( $erasers ) {
	$erasers['goedehosting-inzendingen'] = array(
		'eraser_friendly_name' => __( 'Contactformulier (Goede Hosting)', 'goede-hosting' ),
		'callback'             => 'goede_hosting_inzending_eraser',
	);
	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'goede_hosting_inzending_register_eraser' );

/**
 * Deletes the submissions of an e-mail address.
 * Always reads page 1, because deleted posts drop out of the result.
 *
 * @param string $email E-mail address.
 * @param int    $page  Page number (unused).
 * @return array
 */
function goede_hosting_inzending_eraser( $email, $page = 1 ) {
	unset( $page );
	$ids      = goede_hosting_inzending_ids_by_email( $email, 1 );
	$removed  = false;
	$retained = false;
	$messages = array();
	foreach ( $ids as $id ) {
		if ( wp_delete_post( $id, true ) ) {
			$removed = true;
		} else {
			$retained   = true;
			$messages[] = __( 'Een inzending van het contactformulier kon niet worden verwijderd.', 'goede-hosting' );
		}
	}
	return array(
		'items_removed'  => $removed,
		'items_retained' => $retained,
		'messages'       => array_values( array_unique( $messages ) ),
		'done'           => count( $ids ) < 50 || $retained,
	);
}
