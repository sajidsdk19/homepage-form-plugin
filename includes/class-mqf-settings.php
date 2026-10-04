<?php
/**
 * Settings storage, defaults and sanitisation.
 *
 * @package MovingQuoteForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for plugin settings.
 */
class MQF_Settings {

	const OPTION            = 'mqf_settings';
	const RECIPIENTS_OPTION = 'mqf_recipients';

	/**
	 * Cached merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Google.
			'api_key'               => '',
			'countries'             => '',
			'place_types'           => 'address',

			// Notifications.
			'notify_email'          => get_option( 'admin_email' ),
			'email_subject'         => __( 'New moving quote request from {name}', 'moving-quote-form' ),
			'store_entries'         => 1,

			// Step 1.
			'pickup_label'          => __( 'Pickup location', 'moving-quote-form' ),
			'dropoff_label'         => __( 'Drop-off location', 'moving-quote-form' ),
			'pickup_placeholder'    => __( 'Picking up from', 'moving-quote-form' ),
			'dropoff_placeholder'   => __( 'Dropping off at', 'moving-quote-form' ),
			'button_text'           => __( 'Instant Quote', 'moving-quote-form' ),

			// Step 2.
			'step2_title'           => __( 'Almost there — tell us about your move', 'moving-quote-form' ),
			'submit_text'           => __( 'Get My Quote', 'moving-quote-form' ),
			'back_text'             => __( 'Edit locations', 'moving-quote-form' ),
			'fields'                => MQF_Fields::defaults(),

			// Behaviour.
			'require_suggestion'    => 1,
			'manual_fallback'       => 1,
			'block_same_location'   => 1,
			'use_history'           => 1,

			// Messages.
			'msg_pickup_required'   => __( 'Please enter/select your pickup location.', 'moving-quote-form' ),
			'msg_dropoff_required'  => __( 'Please enter/select your drop-off location.', 'moving-quote-form' ),
			'msg_select_suggestion' => __( 'Please choose an address from the suggestions.', 'moving-quote-form' ),
			'msg_same_location'     => __( 'Pickup and drop-off locations cannot be the same.', 'moving-quote-form' ),
			'msg_google_fallback'   => __( 'Address suggestions are unavailable right now. Please type the full address instead.', 'moving-quote-form' ),
			'msg_google_blocked'    => __( 'Address lookup is unavailable right now. Please try again in a few minutes.', 'moving-quote-form' ),
			'msg_success'           => __( 'Thank you! Your quote request has been sent. We will be in touch shortly.', 'moving-quote-form' ),
			'msg_error'             => __( 'Sorry, something went wrong and your request was not sent. Please try again.', 'moving-quote-form' ),

			// Appearance.
			'color_bar_bg'          => '#06183a',
			'color_bar_text'        => '#ffffff',
			'color_field_bg'        => '#ffffff',
			'color_field_text'      => '#16213a',
			'color_button_bg'       => '#020b20',
			'color_button_text'     => '#ffffff',
			'color_accent'          => '#3b82f6',

			// Uninstall.
			'delete_on_uninstall'   => 0,
		);
	}

	/**
	 * Keys that hold plain single-line text.
	 *
	 * @return string[]
	 */
	public static function text_keys() {
		return array(
			'email_subject',
			'pickup_label',
			'dropoff_label',
			'pickup_placeholder',
			'dropoff_placeholder',
			'button_text',
			'step2_title',
			'submit_text',
			'back_text',
			'msg_pickup_required',
			'msg_dropoff_required',
			'msg_select_suggestion',
			'msg_same_location',
			'msg_google_fallback',
			'msg_google_blocked',
			'msg_success',
			'msg_error',
		);
	}

	/**
	 * Keys that hold a checkbox value.
	 *
	 * @return string[]
	 */
	public static function bool_keys() {
		return array(
			'store_entries',
			'require_suggestion',
			'manual_fallback',
			'block_same_location',
			'use_history',
			'delete_on_uninstall',
		);
	}

	/**
	 * Keys that hold a hex colour.
	 *
	 * @return string[]
	 */
	public static function color_keys() {
		return array(
			'color_bar_bg',
			'color_bar_text',
			'color_field_bg',
			'color_field_text',
			'color_button_bg',
			'color_button_text',
			'color_accent',
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved = get_option( self::OPTION, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			$settings = array_merge( self::defaults(), $saved );
			if ( empty( $settings['fields'] ) || ! is_array( $settings['fields'] ) ) {
				$settings['fields'] = MQF_Fields::defaults();
			}
			self::$cache = $settings;
		}
		return self::$cache;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback if the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Drop the in-memory cache (after the option is updated).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * The Google Maps API key. A wp-config.php constant wins over the saved setting.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'MQF_GOOGLE_MAPS_API_KEY' ) && MQF_GOOGLE_MAPS_API_KEY ) {
			return (string) MQF_GOOGLE_MAPS_API_KEY;
		}
		return (string) self::get( 'api_key' );
	}

	/**
	 * Whether the API key comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function api_key_is_constant() {
		return defined( 'MQF_GOOGLE_MAPS_API_KEY' ) && MQF_GOOGLE_MAPS_API_KEY;
	}

	/**
	 * Country codes to restrict autocomplete to.
	 *
	 * @return string[]
	 */
	public static function countries() {
		return self::parse_countries( self::get( 'countries' ) );
	}

	/**
	 * Parse a comma separated country list into at most 15 two-letter codes.
	 *
	 * @param string $raw Raw input.
	 * @return string[]
	 */
	public static function parse_countries( $raw ) {
		$codes = array();
		foreach ( preg_split( '/[\s,;]+/', strtolower( (string) $raw ) ) as $code ) {
			if ( preg_match( '/^[a-z]{2}$/', $code ) ) {
				$codes[ $code ] = $code;
			}
		}
		return array_slice( array_values( $codes ), 0, 15 );
	}

	/**
	 * Place types sent to Google for the chosen restriction.
	 *
	 * @return string[]
	 */
	public static function place_types() {
		switch ( self::get( 'place_types' ) ) {
			case 'all':
				$types = array();
				break;
			case 'regions':
				$types = array( '(regions)' );
				break;
			default:
				$types = array( 'street_address', 'premise', 'subpremise', 'route', 'postal_code' );
		}
		/**
		 * Filter the Google place types used to restrict suggestions (max 5).
		 *
		 * @param string[] $types Place types.
		 */
		return array_slice( array_values( (array) apply_filters( 'mqf_place_types', $types ) ), 0, 5 );
	}

	/**
	 * Parse a comma separated list of email addresses.
	 *
	 * @param string $raw Raw list.
	 * @return string[]
	 */
	public static function parse_emails( $raw ) {
		$emails = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $raw ) as $email ) {
			$email = sanitize_email( $email );
			if ( $email && is_email( $email ) ) {
				$emails[ strtolower( $email ) ] = $email;
			}
		}
		return array_values( $emails );
	}

	/**
	 * Default notification recipients.
	 *
	 * @return string[]
	 */
	public static function recipients() {
		$emails = self::parse_emails( self::get( 'notify_email' ) );
		if ( empty( $emails ) ) {
			$emails = self::parse_emails( get_option( 'admin_email' ) );
		}
		return $emails;
	}

	/**
	 * Remember a per-form recipient override and return an opaque key for it.
	 *
	 * The key (not the address) is printed in the page, so the override cannot be
	 * tampered with from the browser and the address is not exposed to scrapers.
	 *
	 * @param string $raw Comma separated email list from a shortcode/widget.
	 * @return string Empty string when there is no valid override.
	 */
	public static function register_recipients( $raw ) {
		$emails = self::parse_emails( $raw );
		if ( empty( $emails ) ) {
			return '';
		}
		$value = implode( ',', $emails );
		$key   = substr( wp_hash( 'mqf-recipients|' . strtolower( $value ) ), 0, 20 );
		$map   = get_option( self::RECIPIENTS_OPTION, array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		if ( ! isset( $map[ $key ] ) || $map[ $key ] !== $value ) {
			$map[ $key ] = $value;
			update_option( self::RECIPIENTS_OPTION, $map, false );
		}
		return $key;
	}

	/**
	 * Resolve a recipient key printed by register_recipients().
	 *
	 * @param string $key Opaque key.
	 * @return string[] Empty when the key is unknown.
	 */
	public static function recipients_for_key( $key ) {
		$key = preg_replace( '/[^a-f0-9]/', '', (string) $key );
		if ( '' === $key ) {
			return array();
		}
		$map = get_option( self::RECIPIENTS_OPTION, array() );
		if ( is_array( $map ) && isset( $map[ $key ] ) ) {
			return self::parse_emails( $map[ $key ] );
		}
		return array();
	}

	/**
	 * Sanitise the settings form.
	 *
	 * @param mixed $input Raw posted settings.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$current  = get_option( self::OPTION, array() );
		$current  = is_array( $current ) ? array_merge( $defaults, $current ) : $defaults;
		$input    = is_array( $input ) ? wp_unslash( $input ) : array();
		$out      = $current;

		// A programmatic update (not the settings screen) passes the full array through.
		$from_form = ! empty( $input['_from_form'] );

		if ( isset( $input['api_key'] ) ) {
			$out['api_key'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['api_key'] );
		}

		if ( isset( $input['countries'] ) ) {
			$out['countries'] = implode( ', ', self::parse_countries( $input['countries'] ) );
		}

		if ( isset( $input['place_types'] ) ) {
			$out['place_types'] = in_array( $input['place_types'], array( 'address', 'all', 'regions' ), true ) ? $input['place_types'] : 'address';
		}

		if ( isset( $input['notify_email'] ) ) {
			$emails = self::parse_emails( $input['notify_email'] );
			if ( empty( $emails ) ) {
				$emails = self::parse_emails( get_option( 'admin_email' ) );
				if ( function_exists( 'add_settings_error' ) && '' !== trim( (string) $input['notify_email'] ) ) {
					add_settings_error( self::OPTION, 'mqf_email', __( 'The notification email was not valid, so the site admin email is used instead.', 'moving-quote-form' ) );
				}
			}
			$out['notify_email'] = implode( ', ', $emails );
		}

		foreach ( self::text_keys() as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$value       = sanitize_text_field( (string) $input[ $key ] );
				$out[ $key ] = '' === $value ? $defaults[ $key ] : $value;
			}
		}

		foreach ( self::bool_keys() as $key ) {
			if ( $from_form ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			} elseif ( isset( $input[ $key ] ) ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			}
		}

		foreach ( self::color_keys() as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$color       = sanitize_hex_color( (string) $input[ $key ] );
				$out[ $key ] = $color ? $color : $defaults[ $key ];
			}
		}

		if ( isset( $input['fields'] ) || $from_form ) {
			$fields = MQF_Fields::sanitize( isset( $input['fields'] ) ? $input['fields'] : array() );
			if ( empty( $fields ) ) {
				$fields = MQF_Fields::defaults();
				if ( function_exists( 'add_settings_error' ) ) {
					add_settings_error( self::OPTION, 'mqf_fields', __( 'The form needs at least one field, so the default fields were restored.', 'moving-quote-form' ) );
				}
			}
			$out['fields'] = $fields;
		}

		unset( $out['_from_form'] );
		self::flush();
		return $out;
	}
}
