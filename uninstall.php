<?php
/**
 * Uninstall: remove plugin data only when the site owner opted in.
 *
 * @package MovingQuoteForm
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$mqf_settings = get_option( 'mqf_settings', array() );

if ( is_array( $mqf_settings ) && ! empty( $mqf_settings['delete_on_uninstall'] ) ) {
	$mqf_posts = get_posts(
		array(
			'post_type'      => 'mqf_quote',
			'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'trash' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	foreach ( $mqf_posts as $mqf_post_id ) {
		wp_delete_post( $mqf_post_id, true );
	}

	delete_option( 'mqf_settings' );
	delete_option( 'mqf_recipients' );
	delete_option( 'mqf_error_log' );
}
