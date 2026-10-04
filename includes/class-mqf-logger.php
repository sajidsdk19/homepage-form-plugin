<?php
/**
 * Small technical error log for the site administrator.
 *
 * Keeps the last entries in an option (shown on the settings screen) and also
 * writes to the PHP error log when WP_DEBUG_LOG is on.
 *
 * @package MovingQuoteForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Error logger.
 */
class MQF_Logger {

	const OPTION = 'mqf_error_log';
	const LIMIT  = 50;

	/**
	 * Hook the endpoint the browser uses to report Google API failures.
	 */
	public static function init() {
		add_action( 'wp_ajax_mqf_log_error', array( __CLASS__, 'ajax_log' ) );
		add_action( 'wp_ajax_nopriv_mqf_log_error', array( __CLASS__, 'ajax_log' ) );
	}

	/**
	 * Record an error.
	 *
	 * @param string $context Short machine-readable context, e.g. "google" or "email".
	 * @param string $message Technical message.
	 * @param string $url     Page the error happened on.
	 */
	public static function log( $context, $message, $url = '' ) {
		$entry = array(
			'time'    => time(),
			'context' => substr( sanitize_key( $context ), 0, 30 ),
			'message' => substr( sanitize_text_field( (string) $message ), 0, 400 ),
			'url'     => substr( esc_url_raw( (string) $url ), 0, 300 ),
		);

		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::LIMIT );
		update_option( self::OPTION, $log, false );

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[Moving Quote Form] %s: %s %s', $entry['context'], $entry['message'], $entry['url'] ) );
		}
	}

	/**
	 * Logged entries, newest first.
	 *
	 * @return array[]
	 */
	public static function entries() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Empty the log.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * A stable, non-reversible key for the visitor, used for rate limiting only.
	 *
	 * @return string
	 */
	public static function visitor_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( wp_hash( 'mqf-visitor|' . $ip ), 0, 20 );
	}

	/**
	 * Receive an error report from the browser (Google script blocked, key rejected, quota…).
	 *
	 * Reports are throttled per visitor so the endpoint cannot be used to flood the log.
	 */
	public static function ajax_log() {
		$code    = isset( $_POST['code'] ) ? sanitize_key( wp_unslash( $_POST['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $code || '' === $message ) {
			wp_send_json_error( null, 400 );
		}

		// Only accept reports about pages on this site.
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $url && wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
			$url = '';
		}

		$throttle = 'mqf_log_' . self::visitor_key() . '_' . substr( md5( $code ), 0, 8 );
		if ( get_transient( $throttle ) ) {
			wp_send_json_success();
		}
		set_transient( $throttle, 1, 10 * MINUTE_IN_SECONDS );

		// One site-wide entry per error code every few minutes is plenty.
		$global = 'mqf_log_all_' . substr( md5( $code ), 0, 8 );
		if ( ! get_transient( $global ) ) {
			set_transient( $global, 1, 5 * MINUTE_IN_SECONDS );
			self::log( 'google', $code . ': ' . $message, $url );
		}

		wp_send_json_success();
	}
}
