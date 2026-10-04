<?php
/**
 * Secure AJAX endpoint that receives the quote request.
 *
 * @package MovingQuoteForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles quote submissions.
 */
class MQF_Submission {

	const NONCE_ACTION = 'mqf_submit_quote';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_mqf_submit_quote', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_mqf_submit_quote', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_mqf_refresh_nonce', array( __CLASS__, 'refresh_nonce' ) );
		add_action( 'wp_ajax_nopriv_mqf_refresh_nonce', array( __CLASS__, 'refresh_nonce' ) );
	}

	/**
	 * Hand out a fresh nonce.
	 *
	 * Pages served from a full-page cache can carry an expired nonce; the script
	 * calls this once and retries, so cached pages keep working.
	 */
	public static function refresh_nonce() {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::NONCE_ACTION ) ) );
	}

	/**
	 * Send a JSON error and stop.
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $message Message for the visitor.
	 * @param int    $status  HTTP status.
	 * @param array  $extra   Extra response data.
	 */
	private static function fail( $code, $message, $status = 400, $extra = array() ) {
		wp_send_json_error(
			array_merge(
				array(
					'code'    => $code,
					'message' => $message,
				),
				$extra
			),
			$status
		);
	}

	/**
	 * Read and clean one location from the request.
	 *
	 * @param string $name "pickup" or "dropoff".
	 * @return array{address:string,place_id:string,lat:string,lng:string}
	 */
	private static function read_location( $name ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce is verified in handle() before this runs.
		$raw = isset( $_POST[ 'mqf_' . $name ] ) && is_array( $_POST[ 'mqf_' . $name ] ) ? wp_unslash( $_POST[ 'mqf_' . $name ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable

		$address = isset( $raw['address'] ) && is_scalar( $raw['address'] ) ? sanitize_text_field( (string) $raw['address'] ) : '';
		$address = function_exists( 'mb_substr' ) ? mb_substr( $address, 0, 300 ) : substr( $address, 0, 300 );

		$place_id = isset( $raw['place_id'] ) && is_scalar( $raw['place_id'] ) ? (string) $raw['place_id'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_\-]{10,400}$/', $place_id ) ) {
			$place_id = '';
		}

		$lat = isset( $raw['lat'] ) && is_scalar( $raw['lat'] ) ? (string) $raw['lat'] : '';
		$lng = isset( $raw['lng'] ) && is_scalar( $raw['lng'] ) ? (string) $raw['lng'] : '';
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) || abs( (float) $lat ) > 90 || abs( (float) $lng ) > 180 ) {
			$lat = '';
			$lng = '';
		} else {
			$lat = (string) round( (float) $lat, 7 );
			$lng = (string) round( (float) $lng, 7 );
		}

		return array(
			'address'  => $address,
			'place_id' => $place_id,
			'lat'      => $lat,
			'lng'      => $lng,
		);
	}

	/**
	 * Compare two locations.
	 *
	 * @param array $a First location.
	 * @param array $b Second location.
	 * @return bool
	 */
	private static function same_location( $a, $b ) {
		if ( '' !== $a['place_id'] && $a['place_id'] === $b['place_id'] ) {
			return true;
		}
		$norm = static function ( $text ) {
			return preg_replace( '/[^a-z0-9]+/', '', strtolower( remove_accents( $text ) ) );
		};
		$one = $norm( $a['address'] );
		return '' !== $one && $one === $norm( $b['address'] );
	}

	/**
	 * Process a submission.
	 */
	public static function handle() {
		nocache_headers();

		$generic = (string) MQF_Settings::get( 'msg_error' );

		// 1. Nonce.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			self::fail( 'invalid_nonce', __( 'Your session expired. Please try again.', 'moving-quote-form' ), 403 );
		}

		// 2. Spam traps. Bots that fill the hidden field get a fake success so they do not retry.
		$honeypot = isset( $_POST['mqf_website'] ) ? trim( (string) wp_unslash( $_POST['mqf_website'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' !== $honeypot ) {
			wp_send_json_success( array( 'message' => (string) MQF_Settings::get( 'msg_success' ) ) );
		}

		// 3. Duplicate submissions (double click, retry after a slow response).
		$token     = isset( $_POST['token'] ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', (string) wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$token_key = '';
		if ( strlen( $token ) >= 16 && strlen( $token ) <= 64 ) {
			$token_key = 'mqf_tok_' . md5( $token );
			$state     = get_transient( $token_key );
			if ( 'done' === $state ) {
				wp_send_json_success(
					array(
						'message'   => (string) MQF_Settings::get( 'msg_success' ),
						'duplicate' => true,
					)
				);
			}
			if ( 'processing' === $state ) {
				self::fail( 'in_progress', __( 'Your request is already being processed. Please wait a moment.', 'moving-quote-form' ), 409 );
			}
		}

		// 4. Rate limit per visitor.
		/**
		 * Filter the maximum number of submissions per visitor in a 10 minute window. 0 disables the limit.
		 *
		 * @param int $limit Maximum submissions.
		 */
		$limit    = (int) apply_filters( 'mqf_rate_limit', 10 );
		$rate_key = 'mqf_rate_' . MQF_Logger::visitor_key();
		$count    = (int) get_transient( $rate_key );
		if ( $limit > 0 && $count >= $limit ) {
			self::fail( 'rate_limited', __( 'Too many requests. Please wait a few minutes and try again.', 'moving-quote-form' ), 429 );
		}

		// 5. Locations.
		$pickup  = self::read_location( 'pickup' );
		$dropoff = self::read_location( 'dropoff' );
		$errors  = array();

		if ( '' === $pickup['address'] ) {
			$errors['pickup'] = (string) MQF_Settings::get( 'msg_pickup_required' );
		}
		if ( '' === $dropoff['address'] ) {
			$errors['dropoff'] = (string) MQF_Settings::get( 'msg_dropoff_required' );
		}

		// A typed address without a Google place is only accepted when the fallback policy allows it.
		$strict = MQF_Settings::get( 'require_suggestion' ) && ! MQF_Settings::get( 'manual_fallback' ) && '' !== MQF_Settings::api_key();
		if ( $strict ) {
			if ( ! isset( $errors['pickup'] ) && '' === $pickup['place_id'] ) {
				$errors['pickup'] = (string) MQF_Settings::get( 'msg_select_suggestion' );
			}
			if ( ! isset( $errors['dropoff'] ) && '' === $dropoff['place_id'] ) {
				$errors['dropoff'] = (string) MQF_Settings::get( 'msg_select_suggestion' );
			}
		}

		if ( empty( $errors ) && MQF_Settings::get( 'block_same_location' ) && self::same_location( $pickup, $dropoff ) ) {
			$errors['dropoff'] = (string) MQF_Settings::get( 'msg_same_location' );
		}

		// 6. Customer fields.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitised by MQF_Fields::validate().
		$posted = isset( $_POST['mqf_fields'] ) && is_array( $_POST['mqf_fields'] ) ? wp_unslash( $_POST['mqf_fields'] ) : array();
		$fields = MQF_Fields::get();
		$values = array();

		foreach ( $fields as $field ) {
			$raw                  = isset( $posted[ $field['key'] ] ) ? $posted[ $field['key'] ] : '';
			list( $clean, $error ) = MQF_Fields::validate( $field, $raw );
			if ( '' !== $error ) {
				$errors[ $field['key'] ] = $error;
			}
			$values[ $field['key'] ] = array(
				'label' => $field['label'],
				'type'  => $field['type'],
				'value' => $clean,
			);
		}

		if ( ! empty( $errors ) ) {
			self::fail( 'validation', __( 'Please check the highlighted fields and try again.', 'moving-quote-form' ), 422, array( 'fields' => $errors ) );
		}

		// 7. Assemble one complete quote request.
		$page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
		if ( $page_url && wp_parse_url( $page_url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$page_url = '';
		}

		$name_key  = MQF_Fields::name_key( $fields );
		$email_key = MQF_Fields::first_of_type( $fields, 'email' );
		$phone_key = MQF_Fields::first_of_type( $fields, 'tel' );
		$date_key  = MQF_Fields::first_of_type( $fields, 'date' );

		$entry = array(
			'pickup'    => $pickup,
			'dropoff'   => $dropoff,
			'fields'    => $values,
			'name'      => $name_key && isset( $values[ $name_key ] ) ? (string) $values[ $name_key ]['value'] : '',
			'email'     => $email_key && isset( $values[ $email_key ] ) ? (string) $values[ $email_key ]['value'] : '',
			'phone'     => $phone_key && isset( $values[ $phone_key ] ) ? (string) $values[ $phone_key ]['value'] : '',
			'move_date' => $date_key && isset( $values[ $date_key ] ) ? (string) $values[ $date_key ]['value'] : '',
			'page_url'  => $page_url,
			'time'      => time(),
		);

		/**
		 * Last chance to reject a submission (e.g. CAPTCHA check). Return a WP_Error to stop it.
		 *
		 * @param true|WP_Error $valid Current state.
		 * @param array         $entry The quote request.
		 */
		$valid = apply_filters( 'mqf_validate_submission', true, $entry );
		if ( is_wp_error( $valid ) ) {
			self::fail( 'rejected', $valid->get_error_message() ? $valid->get_error_message() : $generic, 400 );
		}

		if ( $token_key ) {
			set_transient( $token_key, 'processing', 2 * MINUTE_IN_SECONDS );
		}

		// 8. Store and notify.
		$post_id = 0;
		if ( MQF_Settings::get( 'store_entries' ) ) {
			$post_id = MQF_Entries::store( $entry );
			if ( ! $post_id ) {
				MQF_Logger::log( 'storage', 'Could not save the quote request to the database.', $page_url );
			}
		}

		$instance   = isset( $_POST['instance'] ) ? sanitize_text_field( wp_unslash( $_POST['instance'] ) ) : '';
		$recipients = MQF_Settings::recipients_for_key( $instance );
		if ( empty( $recipients ) ) {
			$recipients = MQF_Settings::recipients();
		}

		$sent = MQF_Email::send( $entry, $recipients, $post_id );
		if ( $post_id ) {
			update_post_meta( $post_id, '_mqf_email_sent', $sent ? 1 : 0 );
		}

		if ( ! $sent ) {
			MQF_Logger::log( 'email', 'wp_mail() could not send the quote notification to ' . implode( ', ', $recipients ) . '.', $page_url );

			// Nothing reached the business: tell the visitor honestly so they can retry or call.
			if ( ! $post_id ) {
				if ( $token_key ) {
					delete_transient( $token_key );
				}
				self::fail( 'send_failed', $generic, 500 );
			}
		}

		if ( $token_key ) {
			set_transient( $token_key, 'done', 15 * MINUTE_IN_SECONDS );
		}
		set_transient( $rate_key, $count + 1, 10 * MINUTE_IN_SECONDS );

		/**
		 * Fires after a quote request was accepted.
		 *
		 * @param array $entry   The quote request.
		 * @param int   $post_id Stored entry ID (0 when storage is off).
		 * @param bool  $sent    Whether the notification email was sent.
		 */
		do_action( 'mqf_quote_submitted', $entry, $post_id, $sent );

		wp_send_json_success( array( 'message' => (string) MQF_Settings::get( 'msg_success' ) ) );
	}
}
