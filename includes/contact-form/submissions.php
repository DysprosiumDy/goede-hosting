<?php
/**
 * Contact form: the private, read-only post type "Inzendingen", its admin
 * screens, the settings page (recipient, retention) and the daily cleanup.
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the post type.
 */
function goede_hosting_inzending_register() {
	$admin = 'manage_options';
	register_post_type(
		GOEDE_HOSTING_INZENDING,
		array(
			'labels'              => array(
				'name'               => __( 'Inzendingen', 'goede-hosting' ),
				'singular_name'      => __( 'Inzending', 'goede-hosting' ),
				'menu_name'          => __( 'Inzendingen', 'goede-hosting' ),
				'all_items'          => __( 'Alle inzendingen', 'goede-hosting' ),
				'edit_item'          => __( 'Inzending bekijken', 'goede-hosting' ),
				'view_item'          => __( 'Inzending bekijken', 'goede-hosting' ),
				'search_items'       => __( 'Inzendingen zoeken', 'goede-hosting' ),
				'not_found'          => __( 'Nog geen inzendingen.', 'goede-hosting' ),
				'not_found_in_trash' => __( 'Geen inzendingen in de prullenbak.', 'goede-hosting' ),
				'item_updated'       => __( 'Inzending bijgewerkt.', 'goede-hosting' ),
			),
			'description'         => __( 'Berichten verstuurd via het contactformulier.', 'goede-hosting' ),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'menu_position'       => 26,
			'menu_icon'           => 'dashicons-email-alt',
			'supports'            => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => false,
			'delete_with_user'    => false,
			'map_meta_cap'        => true,
			'capability_type'     => 'post',
			'capabilities'        => array(
				'create_posts'           => 'do_not_allow',
				'publish_posts'          => 'do_not_allow',
				'edit_posts'             => $admin,
				'edit_others_posts'      => $admin,
				'edit_private_posts'     => $admin,
				'edit_published_posts'   => $admin,
				'read_private_posts'     => $admin,
				'delete_posts'           => $admin,
				'delete_others_posts'    => $admin,
				'delete_private_posts'   => $admin,
				'delete_published_posts' => $admin,
			),
		)
	);
}
add_action( 'init', 'goede_hosting_inzending_register' );

/**
 * Whether the current admin request is about our post type.
 *
 * @return bool
 */
function goede_hosting_inzending_is_screen() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	return $screen && GOEDE_HOSTING_INZENDING === $screen->post_type;
}

/**
 * Meta value helper.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Field name without prefix.
 * @return string
 */
function goede_hosting_inzending_meta( $post_id, $key ) {
	$value = get_post_meta( $post_id, '_gh_' . $key, true );
	return is_scalar( $value ) ? (string) $value : '';
}

/**
 * Fields of a stored submission: key => label. Taken from the submission itself
 * (the fields at the time it was sent), else the current fields.
 *
 * @param int $post_id Post ID.
 * @return array<string, string>
 */
function goede_hosting_inzending_fields( $post_id ) {
	$stored = get_post_meta( $post_id, '_gh_velden', true );
	if ( is_array( $stored ) && $stored ) {
		return array_map( 'strval', $stored );
	}
	return wp_list_pluck( goede_hosting_contact_fields(), 'label' );
}

/**
 * List columns.
 *
 * @param array $columns Default columns.
 * @return array
 */
function goede_hosting_inzending_columns( $columns ) {
	return array(
		'cb'       => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox">',
		'gh_naam'  => __( 'Inzending', 'goede-hosting' ),
		'gh_email' => __( 'E-mail', 'goede-hosting' ),
		'gh_mail'  => __( 'Mail verzonden', 'goede-hosting' ),
		'gh_datum' => __( 'Datum', 'goede-hosting' ),
	);
}
add_filter( 'manage_' . GOEDE_HOSTING_INZENDING . '_posts_columns', 'goede_hosting_inzending_columns' );

/**
 * List column content.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 */
function goede_hosting_inzending_column( $column, $post_id ) {
	switch ( $column ) {
		case 'gh_naam':
			$title = get_the_title( $post_id );
			$title = '' !== $title ? $title : __( '(geen naam)', 'goede-hosting' );
			if ( 'trash' !== get_post_status( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
				printf( '<strong><a class="row-title" href="%1$s">%2$s</a></strong>', esc_url( (string) get_edit_post_link( $post_id ) ), esc_html( $title ) );
			} else {
				echo '<strong>' . esc_html( $title ) . '</strong>';
			}
			break;
		case 'gh_email':
			$email = goede_hosting_inzending_meta( $post_id, 'email' );
			if ( '' !== $email ) {
				printf( '<a href="%1$s">%2$s</a>', esc_url( 'mailto:' . $email, array( 'mailto' ) ), esc_html( $email ) );
			}
			break;
		case 'gh_mail':
			echo '1' === goede_hosting_inzending_meta( $post_id, 'mail_verzonden' ) ? esc_html__( 'Ja', 'goede-hosting' ) : '<strong>' . esc_html__( 'Nee', 'goede-hosting' ) . '</strong>';
			break;
		case 'gh_datum':
			echo esc_html( (string) get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post_id ) );
			break;
	}
}
add_action( 'manage_' . GOEDE_HOSTING_INZENDING . '_posts_custom_column', 'goede_hosting_inzending_column', 10, 2 );

/**
 * Date column sortable.
 *
 * @param array $columns Sortable columns.
 * @return array
 */
function goede_hosting_inzending_sortable( $columns ) {
	$columns['gh_datum'] = array( 'date', true );
	return $columns;
}
add_filter( 'manage_edit-' . GOEDE_HOSTING_INZENDING . '_sortable_columns', 'goede_hosting_inzending_sortable' );

/**
 * The name column carries the row actions.
 *
 * @param string $column Default primary column.
 * @param string $screen Screen ID.
 * @return string
 */
function goede_hosting_inzending_primary_column( $column, $screen ) {
	return 'edit-' . GOEDE_HOSTING_INZENDING === $screen ? 'gh_naam' : $column;
}
add_filter( 'list_table_primary_column', 'goede_hosting_inzending_primary_column', 10, 2 );

/**
 * Row actions: "Bekijken" instead of edit, no quick edit, no preview.
 *
 * @param array   $actions Row actions.
 * @param WP_Post $post    Post.
 * @return array
 */
function goede_hosting_inzending_row_actions( $actions, $post ) {
	if ( GOEDE_HOSTING_INZENDING !== $post->post_type ) {
		return $actions;
	}
	unset( $actions['inline hide-if-no-js'], $actions['view'], $actions['preview'] );
	if ( isset( $actions['edit'] ) ) {
		$actions['edit'] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( (string) get_edit_post_link( $post->ID ) ), esc_html__( 'Bekijken', 'goede-hosting' ) );
	}
	return $actions;
}
add_filter( 'post_row_actions', 'goede_hosting_inzending_row_actions', 10, 2 );

/**
 * No "Bewerken" in the bulk actions.
 *
 * @param array $actions Bulk actions.
 * @return array
 */
function goede_hosting_inzending_bulk_actions( $actions ) {
	unset( $actions['edit'] );
	return $actions;
}
add_filter( 'bulk_actions-edit-' . GOEDE_HOSTING_INZENDING, 'goede_hosting_inzending_bulk_actions' );

/**
 * Search also in the stored field values.
 *
 * @param string   $search Search SQL.
 * @param WP_Query $query  Query.
 * @return string
 */
function goede_hosting_inzending_search( $search, $query ) {
	global $wpdb;
	if ( ! is_admin() || ! $query->is_main_query() || GOEDE_HOSTING_INZENDING !== $query->get( 'post_type' ) ) {
		return $search;
	}
	$term = (string) $query->get( 's' );
	if ( '' === $term ) {
		return $search;
	}
	$keys = array( '_gh_email' );
	foreach ( array_keys( goede_hosting_contact_fields() ) as $key ) {
		$keys[] = '_gh_' . $key;
	}
	$keys         = array_values( array_unique( $keys ) );
	$like         = '%' . $wpdb->esc_like( $term ) . '%';
	$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
	// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the placeholder list is built above; table names come from $wpdb.
	return $wpdb->prepare(
		" AND ( {$wpdb->posts}.post_title LIKE %s OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = {$wpdb->posts}.ID AND m.meta_key IN ( {$placeholders} ) AND m.meta_value LIKE %s ) ) ",
		array_merge( array( $like ), $keys, array( $like ) )
	);
	// phpcs:enable
}
add_filter( 'posts_search', 'goede_hosting_inzending_search', 10, 2 );

/**
 * Blocks saving through the edit screen, quick edit and bulk edit: submissions are read-only.
 */
function goede_hosting_inzending_block_edit() {
	// phpcs:disable WordPress.Security.NonceVerification -- only reads the target to refuse the request.
	$ids = array();
	if ( isset( $_POST['post_ID'] ) ) {
		$ids[] = absint( wp_unslash( $_POST['post_ID'] ) );
	}
	if ( isset( $_POST['post_type'] ) && GOEDE_HOSTING_INZENDING === sanitize_key( wp_unslash( $_POST['post_type'] ) ) ) {
		$ids[] = -1;
	}
	if ( isset( $_REQUEST['bulk_edit'] ) && isset( $_REQUEST['post_type'] ) && GOEDE_HOSTING_INZENDING === sanitize_key( wp_unslash( $_REQUEST['post_type'] ) ) ) {
		$ids[] = -1;
	}
	// phpcs:enable
	foreach ( $ids as $id ) {
		if ( -1 === $id || ( $id && GOEDE_HOSTING_INZENDING === get_post_type( $id ) ) ) {
			wp_die( esc_html__( 'Inzendingen kunnen niet worden bewerkt.', 'goede-hosting' ), '', array( 'response' => 403 ) );
		}
	}
}
add_action( 'admin_action_editpost', 'goede_hosting_inzending_block_edit' );
add_action( 'wp_ajax_inline-save', 'goede_hosting_inzending_block_edit', 0 );

/**
 * Refuses bulk edit on the list screen.
 */
function goede_hosting_inzending_block_bulk_edit() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only refuses the request.
	if ( isset( $_REQUEST['bulk_edit'] ) ) {
		goede_hosting_inzending_block_edit();
	}
}
add_action( 'load-edit.php', 'goede_hosting_inzending_block_bulk_edit' );

/**
 * Detail view: replaces the publish box with a read-only meta box and an actions box.
 */
function goede_hosting_inzending_meta_boxes() {
	remove_meta_box( 'submitdiv', GOEDE_HOSTING_INZENDING, 'side' );
	remove_meta_box( 'slugdiv', GOEDE_HOSTING_INZENDING, 'normal' );
	add_meta_box( 'goede-hosting-inzending', __( 'Inzending', 'goede-hosting' ), 'goede_hosting_inzending_detail_box', GOEDE_HOSTING_INZENDING, 'normal', 'high' );
	add_meta_box( 'goede-hosting-inzending-acties', __( 'Acties', 'goede-hosting' ), 'goede_hosting_inzending_actions_box', GOEDE_HOSTING_INZENDING, 'side', 'high' );
}
add_action( 'add_meta_boxes_' . GOEDE_HOSTING_INZENDING, 'goede_hosting_inzending_meta_boxes' );

/**
 * Read-only detail box.
 *
 * @param WP_Post $post Post.
 */
function goede_hosting_inzending_detail_box( $post ) {
	$page   = goede_hosting_inzending_meta( $post->ID, 'pagina' );
	$mailed = '1' === goede_hosting_inzending_meta( $post->ID, 'mail_verzonden' );
	$to     = goede_hosting_inzending_meta( $post->ID, 'ontvanger' );

	$rows = array(
		__( 'Datum', 'goede-hosting' ) => esc_html( (string) get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post ) ),
	);
	foreach ( goede_hosting_inzending_fields( $post->ID ) as $key => $label ) {
		$value = goede_hosting_inzending_meta( $post->ID, $key );
		if ( is_email( $value ) ) {
			$html = '<a href="' . esc_url( 'mailto:' . $value, array( 'mailto' ) ) . '">' . esc_html( $value ) . '</a>';
		} elseif ( false !== strpos( $value, "\n" ) || strlen( $value ) > 120 ) {
			$html = '<div class="goede-hosting-inzending__long">' . nl2br( esc_html( $value ) ) . '</div>';
		} else {
			$html = esc_html( $value );
		}
		$rows[ $label ] = $html;
	}
	$rows[ __( 'Mail verzonden', 'goede-hosting' ) ]  = ( $mailed ? esc_html__( 'Ja', 'goede-hosting' ) : esc_html__( 'Nee', 'goede-hosting' ) ) . ( '' !== $to ? ' <span class="description">(' . esc_html( $to ) . ')</span>' : '' );
	$rows[ __( 'Verstuurd vanaf', 'goede-hosting' ) ] = '' !== $page ? '<a href="' . esc_url( $page ) . '">' . esc_html( $page ) . '</a>' : '';

	echo '<table class="form-table goede-hosting-inzending" role="presentation"><tbody>';
	foreach ( $rows as $label => $html ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values escaped above.
	}
	echo '</tbody></table>';
}

/**
 * Actions box: reply by e-mail, move to trash, back to the list.
 *
 * @param WP_Post $post Post.
 */
function goede_hosting_inzending_actions_box( $post ) {
	$email = goede_hosting_inzending_meta( $post->ID, 'email' );
	echo '<p>';
	if ( '' !== $email ) {
		/* translators: %s: site name. */
		$subject = sprintf( __( 'Re: je bericht via %s', 'goede-hosting' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		printf(
			'<a class="button button-primary" href="%1$s">%2$s</a> ',
			esc_url( 'mailto:' . $email . '?subject=' . rawurlencode( $subject ), array( 'mailto' ) ),
			esc_html__( 'Beantwoorden', 'goede-hosting' )
		);
	}
	if ( current_user_can( 'delete_post', $post->ID ) ) {
		printf(
			'<a class="button submitdelete" href="%1$s">%2$s</a>',
			esc_url( (string) get_delete_post_link( $post->ID ) ),
			esc_html__( 'Naar prullenbak', 'goede-hosting' )
		);
	}
	echo '</p>';
	printf(
		'<p><a href="%1$s">&larr; %2$s</a></p>',
		esc_url( admin_url( 'edit.php?post_type=' . GOEDE_HOSTING_INZENDING ) ),
		esc_html__( 'Alle inzendingen', 'goede-hosting' )
	);
	echo '<p class="description">' . esc_html__( 'Inzendingen zijn alleen-lezen.', 'goede-hosting' ) . '</p>';
}

/**
 * Admin styles on our screens.
 */
function goede_hosting_inzending_admin_assets() {
	if ( ! goede_hosting_inzending_is_screen() ) {
		return;
	}
	goede_hosting_enqueue_admin_style();
}
add_action( 'admin_enqueue_scripts', 'goede_hosting_inzending_admin_assets' );

// -----------------------------------------------------------------------------
// Settings: Inzendingen > Instellingen
// -----------------------------------------------------------------------------

/**
 * Registers the options.
 */
function goede_hosting_contact_register_settings() {
	register_setting(
		'goede_hosting_contact',
		'goede_hosting_contact_recipient',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
			'default'           => '',
			'show_in_rest'      => false,
		)
	);
	register_setting(
		'goede_hosting_contact',
		'goedehosting_inzending_bewaartermijn',
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'goede_hosting_contact_sanitize_retention',
			'default'           => 24,
			'show_in_rest'      => false,
		)
	);
}
add_action( 'admin_init', 'goede_hosting_contact_register_settings' );

/**
 * Sanitises the retention period (months, 0–240).
 *
 * @param mixed $value Raw input.
 * @return int
 */
function goede_hosting_contact_sanitize_retention( $value ) {
	return is_numeric( $value ) ? min( 240, max( 0, (int) $value ) ) : 24;
}

/**
 * Adds Inzendingen > Instellingen.
 */
function goede_hosting_contact_settings_menu() {
	add_submenu_page(
		'edit.php?post_type=' . GOEDE_HOSTING_INZENDING,
		__( 'Instellingen contactformulier', 'goede-hosting' ),
		__( 'Instellingen', 'goede-hosting' ),
		'manage_options',
		'goede-hosting-contactformulier',
		'goede_hosting_contact_settings_page'
	);
}
add_action( 'admin_menu', 'goede_hosting_contact_settings_menu' );

/**
 * Renders the settings page.
 */
function goede_hosting_contact_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$fallback = function_exists( 'goede_hosting_company' ) && '' !== goede_hosting_company( 'email' ) ? goede_hosting_company( 'email' ) : (string) get_option( 'admin_email' );

	echo '<div class="wrap"><h1>' . esc_html__( 'Instellingen contactformulier', 'goede-hosting' ) . '</h1>';
	echo '<form method="post" action="options.php">';
	settings_fields( 'goede_hosting_contact' );
	echo '<table class="form-table" role="presentation">';
	printf(
		'<tr><th scope="row"><label for="gh-ontvanger">%1$s</label></th><td><input type="email" class="regular-text" id="gh-ontvanger" name="goede_hosting_contact_recipient" value="%2$s" placeholder="%3$s"><p class="description">%4$s</p></td></tr>',
		esc_html__( 'Ontvanger', 'goede-hosting' ),
		esc_attr( (string) get_option( 'goede_hosting_contact_recipient', '' ) ),
		esc_attr( $fallback ),
		esc_html__( 'Waar berichten naartoe gaan. Leeg = het e-mailadres in Bedrijfsgegevens, anders het e-mailadres van de beheerder. Een formulierblok met een eigen ontvanger gaat voor.', 'goede-hosting' )
	);
	printf(
		'<tr><th scope="row"><label for="gh-bewaartermijn">%1$s</label></th><td><input type="number" class="small-text" id="gh-bewaartermijn" name="goedehosting_inzending_bewaartermijn" min="0" max="240" step="1" value="%2$s"> %3$s<p class="description">%4$s</p></td></tr>',
		esc_html__( 'Bewaartermijn inzendingen', 'goede-hosting' ),
		esc_attr( (string) goede_hosting_contact_retention() ),
		esc_html__( 'maanden', 'goede-hosting' ),
		esc_html__( 'Oudere inzendingen worden elke dag automatisch verwijderd. 0 = nooit verwijderen. Houd dit gelijk aan de termijn in de privacyverklaring.', 'goede-hosting' )
	);
	echo '</table>';
	submit_button();
	echo '</form></div>';
}

// -----------------------------------------------------------------------------
// Retention: daily cleanup
// -----------------------------------------------------------------------------

/**
 * Schedules the daily cleanup when missing.
 */
function goede_hosting_inzending_schedule() {
	if ( ! wp_next_scheduled( GOEDE_HOSTING_INZENDING_CRON ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', GOEDE_HOSTING_INZENDING_CRON );
	}
}
add_action( 'init', 'goede_hosting_inzending_schedule' );

/**
 * Deletes submissions older than the retention period (in batches).
 */
function goede_hosting_inzending_cleanup() {
	$months = goede_hosting_contact_retention();
	if ( $months <= 0 ) {
		return;
	}
	$cutoff = gmdate( 'Y-m-d H:i:s', (int) strtotime( '-' . $months . ' months' ) );
	for ( $batch = 0; $batch < 20; $batch++ ) {
		$ids = get_posts(
			array(
				'post_type'        => GOEDE_HOSTING_INZENDING,
				'post_status'      => array( 'private', 'publish', 'draft', 'pending', 'trash' ),
				'fields'           => 'ids',
				'posts_per_page'   => 100,
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'orderby'          => 'date',
				'order'            => 'ASC',
				'date_query'       => array(
					array(
						'column' => 'post_date_gmt',
						'before' => $cutoff,
					),
				),
			)
		);
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}
}
add_action( GOEDE_HOSTING_INZENDING_CRON, 'goede_hosting_inzending_cleanup' );
