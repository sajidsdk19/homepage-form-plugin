<?php
/**
 * Stored quote requests (custom post type), admin list and CSV export.
 *
 * @package MovingQuoteForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quote request storage.
 */
class MQF_Entries {

	const POST_TYPE = 'mqf_quote';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );

		if ( is_admin() ) {
			add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
			add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
			add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'meta_boxes' ) );
			add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
			add_action( 'manage_posts_extra_tablenav', array( __CLASS__, 'export_button' ) );
			add_action( 'admin_post_mqf_export_csv', array( __CLASS__, 'export_csv' ) );
			add_filter( 'bulk_actions-edit-' . self::POST_TYPE, array( __CLASS__, 'bulk_actions' ) );
		}
	}

	/**
	 * Register the private post type that holds quote requests.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Quote Requests', 'moving-quote-form' ),
					'singular_name'      => __( 'Quote Request', 'moving-quote-form' ),
					'menu_name'          => __( 'Moving Quotes', 'moving-quote-form' ),
					'all_items'          => __( 'Quote Requests', 'moving-quote-form' ),
					'edit_item'          => __( 'Quote Request', 'moving-quote-form' ),
					'view_item'          => __( 'View Quote Request', 'moving-quote-form' ),
					'search_items'       => __( 'Search Quote Requests', 'moving-quote-form' ),
					'not_found'          => __( 'No quote requests yet.', 'moving-quote-form' ),
					'not_found_in_trash' => __( 'No quote requests in the Trash.', 'moving-quote-form' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'menu_icon'           => 'dashicons-location-alt',
				'menu_position'       => 26,
				'supports'            => array( 'title' ),
				'capability_type'     => 'page',
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Save a quote request.
	 *
	 * @param array $entry Quote request.
	 * @return int Post ID, 0 on failure.
	 */
	public static function store( $entry ) {
		$title = '' !== $entry['name'] ? $entry['name'] : __( 'Quote request', 'moving-quote-form' );
		$title = sprintf( '%s — %s → %s', $title, $entry['pickup']['address'], $entry['dropoff']['address'] );
		$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 200 ) : substr( $title, 0, 200 );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => wp_slash( $title ),
				'post_author' => 0,
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, '_mqf_entry', wp_slash( $entry ) );
		update_post_meta( $post_id, '_mqf_name', wp_slash( $entry['name'] ) );
		update_post_meta( $post_id, '_mqf_email', wp_slash( $entry['email'] ) );
		update_post_meta( $post_id, '_mqf_phone', wp_slash( $entry['phone'] ) );

		return (int) $post_id;
	}

	/**
	 * Read a stored quote request.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get( $post_id ) {
		$entry = get_post_meta( $post_id, '_mqf_entry', true );
		if ( ! is_array( $entry ) || empty( $entry['pickup'] ) || empty( $entry['dropoff'] ) ) {
			return null;
		}
		$entry = array_merge(
			array(
				'fields'    => array(),
				'name'      => '',
				'email'     => '',
				'phone'     => '',
				'move_date' => '',
				'page_url'  => '',
				'time'      => 0,
			),
			$entry
		);
		foreach ( array( 'pickup', 'dropoff' ) as $loc ) {
			$entry[ $loc ] = array_merge(
				array(
					'address'  => '',
					'place_id' => '',
					'lat'      => '',
					'lng'      => '',
				),
				(array) $entry[ $loc ]
			);
		}
		return $entry;
	}

	/**
	 * List table columns.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		return array(
			'cb'            => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'title'         => __( 'Request', 'moving-quote-form' ),
			'mqf_contact'   => __( 'Contact', 'moving-quote-form' ),
			'mqf_pickup'    => __( 'Pickup', 'moving-quote-form' ),
			'mqf_dropoff'   => __( 'Drop-off', 'moving-quote-form' ),
			'mqf_move_date' => __( 'Move date', 'moving-quote-form' ),
			'date'          => __( 'Received', 'moving-quote-form' ),
		);
	}

	/**
	 * List table cell output.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function column( $column, $post_id ) {
		$entry = self::get( $post_id );
		if ( ! $entry ) {
			echo '—';
			return;
		}

		switch ( $column ) {
			case 'mqf_contact':
				$lines = array();
				if ( '' !== $entry['phone'] ) {
					$lines[] = '<a href="' . esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $entry['phone'] ), array( 'tel' ) ) . '">' . esc_html( $entry['phone'] ) . '</a>';
				}
				if ( '' !== $entry['email'] ) {
					$lines[] = '<a href="' . esc_url( 'mailto:' . $entry['email'], array( 'mailto' ) ) . '">' . esc_html( $entry['email'] ) . '</a>';
				}
				echo $lines ? wp_kses_post( implode( '<br>', $lines ) ) : '—';
				if ( '0' === (string) get_post_meta( $post_id, '_mqf_email_sent', true ) ) {
					echo '<br><span style="color:#b32d2e;">' . esc_html__( 'Notification email failed', 'moving-quote-form' ) . '</span>';
				}
				break;

			case 'mqf_pickup':
				echo esc_html( $entry['pickup']['address'] );
				break;

			case 'mqf_dropoff':
				echo esc_html( $entry['dropoff']['address'] );
				break;

			case 'mqf_move_date':
				echo '' !== $entry['move_date'] ? esc_html( MQF_Fields::display_value( $entry['move_date'], 'date' ) ) : '—';
				break;
		}
	}

	/**
	 * Quote requests are records, not editable content: keep the row actions simple.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'], $actions['view'] );
		if ( isset( $actions['edit'] ) ) {
			$actions['edit'] = '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html__( 'View', 'moving-quote-form' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Remove the bulk "Edit" action.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		unset( $actions['edit'] );
		return $actions;
	}

	/**
	 * Meta boxes on the single request screen.
	 */
	public static function meta_boxes() {
		add_meta_box( 'mqf-entry', __( 'Quote request details', 'moving-quote-form' ), array( __CLASS__, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Read-only details of one request.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_meta_box( $post ) {
		$entry = self::get( $post->ID );
		if ( ! $entry ) {
			echo '<p>' . esc_html__( 'No details were stored for this request.', 'moving-quote-form' ) . '</p>';
			return;
		}

		if ( '0' === (string) get_post_meta( $post->ID, '_mqf_email_sent', true ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The notification email for this request could not be sent. Check your site’s email setup (an SMTP plugin usually fixes this).', 'moving-quote-form' ) . '</p></div>';
		}

		echo '<table class="widefat striped" style="border:0;"><tbody>';
		foreach ( MQF_Email::rows( $entry ) as $row ) {
			$value = nl2br( esc_html( $row['value'] ) );
			if ( ! empty( $row['url'] ) ) {
				$value = '<a href="' . esc_url( $row['url'], array( 'https', 'http', 'mailto', 'tel' ) ) . '" target="_blank" rel="noopener noreferrer">' . $value . '</a>';
			}
			echo '<tr><th scope="row" style="width:220px;">' . esc_html( $row['label'] ) . '</th><td>' . wp_kses_post( $value ) . '</td></tr>';
		}

		foreach ( array( 'pickup', 'dropoff' ) as $loc ) {
			if ( '' !== $entry[ $loc ]['place_id'] || '' !== $entry[ $loc ]['lat'] ) {
				$label = 'pickup' === $loc ? __( 'Pickup Google data', 'moving-quote-form' ) : __( 'Drop-off Google data', 'moving-quote-form' );
				$bits  = array();
				if ( '' !== $entry[ $loc ]['place_id'] ) {
					$bits[] = 'Place ID: ' . $entry[ $loc ]['place_id'];
				}
				if ( '' !== $entry[ $loc ]['lat'] ) {
					$bits[] = 'Lat/Lng: ' . $entry[ $loc ]['lat'] . ', ' . $entry[ $loc ]['lng'];
				}
				echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><code>' . esc_html( implode( ' · ', $bits ) ) . '</code></td></tr>';
			}
		}

		if ( '' !== $entry['page_url'] ) {
			echo '<tr><th scope="row">' . esc_html__( 'Sent from', 'moving-quote-form' ) . '</th><td><a href="' . esc_url( $entry['page_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $entry['page_url'] ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * "Export CSV" button above the list table.
	 *
	 * @param string $which "top" or "bottom".
	 */
	public static function export_button( $which ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 'top' !== $which || ! $screen || self::POST_TYPE !== $screen->post_type || ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=mqf_export_csv' ), 'mqf_export_csv' );
		echo '<div class="alignleft actions"><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Export CSV', 'moving-quote-form' ) . '</a></div>';
	}

	/**
	 * Stop spreadsheet apps from running a cell as a formula.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	private static function csv_cell( $value ) {
		$value = (string) $value;
		// Numbers and phone numbers (digits, spaces, +, -, brackets, dots) cannot run as formulas.
		if ( is_numeric( $value ) || preg_match( '/^[+\-]?[0-9 ().\-]+$/', $value ) ) {
			return $value;
		}
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Stream all quote requests as CSV.
	 */
	public static function export_csv() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You are not allowed to export quote requests.', 'moving-quote-form' ), 403 );
		}
		check_admin_referer( 'mqf_export_csv' );

		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		// Field columns: current configuration first, then any older fields found in stored requests.
		$field_columns = array();
		foreach ( MQF_Fields::get() as $field ) {
			$field_columns[ $field['key'] ] = $field['label'];
		}
		$entries = array();
		foreach ( $ids as $id ) {
			$entry = self::get( $id );
			if ( ! $entry ) {
				continue;
			}
			foreach ( $entry['fields'] as $key => $field ) {
				if ( ! isset( $field_columns[ $key ] ) ) {
					$field_columns[ $key ] = isset( $field['label'] ) ? $field['label'] : $key;
				}
			}
			$entries[ $id ] = $entry;
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="moving-quotes-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel reads accented characters correctly.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$header = array_merge(
			array(
				__( 'Received', 'moving-quote-form' ),
				__( 'Pickup address', 'moving-quote-form' ),
				__( 'Pickup place ID', 'moving-quote-form' ),
				__( 'Pickup latitude', 'moving-quote-form' ),
				__( 'Pickup longitude', 'moving-quote-form' ),
				__( 'Drop-off address', 'moving-quote-form' ),
				__( 'Drop-off place ID', 'moving-quote-form' ),
				__( 'Drop-off latitude', 'moving-quote-form' ),
				__( 'Drop-off longitude', 'moving-quote-form' ),
			),
			array_values( $field_columns ),
			array( __( 'Page', 'moving-quote-form' ) )
		);
		fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $header ), ',', '"', '\\' );

		foreach ( $entries as $id => $entry ) {
			$row = array(
				get_the_date( 'Y-m-d H:i', $id ),
				$entry['pickup']['address'],
				$entry['pickup']['place_id'],
				$entry['pickup']['lat'],
				$entry['pickup']['lng'],
				$entry['dropoff']['address'],
				$entry['dropoff']['place_id'],
				$entry['dropoff']['lat'],
				$entry['dropoff']['lng'],
			);
			foreach ( array_keys( $field_columns ) as $key ) {
				$row[] = isset( $entry['fields'][ $key ] ) ? MQF_Fields::display_value( $entry['fields'][ $key ]['value'] ) : '';
			}
			$row[] = $entry['page_url'];
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $row ), ',', '"', '\\' );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
