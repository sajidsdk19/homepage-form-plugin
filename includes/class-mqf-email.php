<?php
/**
 * Notification email.
 *
 * @package MovingQuoteForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats and sends the quote notification.
 */
class MQF_Email {

	/**
	 * Google Maps link for a location.
	 *
	 * @param array $location Location data.
	 * @return string
	 */
	public static function maps_url( $location ) {
		if ( empty( $location['address'] ) ) {
			return '';
		}
		$args = array(
			'api'   => 1,
			'query' => $location['address'],
		);
		if ( ! empty( $location['place_id'] ) ) {
			$args['query_place_id'] = $location['place_id'];
		}
		return 'https://www.google.com/maps/search/?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Straight-line distance between the two locations, when coordinates are known.
	 *
	 * @param array $a Pickup.
	 * @param array $b Drop-off.
	 * @return float|null Kilometres.
	 */
	public static function distance_km( $a, $b ) {
		if ( '' === $a['lat'] || '' === $a['lng'] || '' === $b['lat'] || '' === $b['lng'] ) {
			return null;
		}
		$lat1 = deg2rad( (float) $a['lat'] );
		$lat2 = deg2rad( (float) $b['lat'] );
		$dlat = $lat2 - $lat1;
		$dlng = deg2rad( (float) $b['lng'] - (float) $a['lng'] );
		$h    = pow( sin( $dlat / 2 ), 2 ) + cos( $lat1 ) * cos( $lat2 ) * pow( sin( $dlng / 2 ), 2 );
		return 6371.0088 * 2 * asin( min( 1, sqrt( $h ) ) );
	}

	/**
	 * Human readable distance.
	 *
	 * @param array $a Pickup.
	 * @param array $b Drop-off.
	 * @return string '' when unknown.
	 */
	public static function distance_label( $a, $b ) {
		$km = self::distance_km( $a, $b );
		if ( null === $km ) {
			return '';
		}
		return sprintf(
			/* translators: 1: kilometres, 2: miles */
			__( 'about %1$s km (%2$s miles) in a straight line', 'moving-quote-form' ),
			number_format_i18n( $km, $km < 10 ? 1 : 0 ),
			number_format_i18n( $km * 0.621371, $km < 10 ? 1 : 0 )
		);
	}

	/**
	 * Rows shown in the email and in the admin entry view.
	 *
	 * @param array $entry Quote request.
	 * @return array[] Each row: label, value, url (optional).
	 */
	public static function rows( $entry ) {
		$rows = array(
			array(
				'label' => (string) MQF_Settings::get( 'pickup_label' ),
				'value' => $entry['pickup']['address'],
				'url'   => self::maps_url( $entry['pickup'] ),
			),
			array(
				'label' => (string) MQF_Settings::get( 'dropoff_label' ),
				'value' => $entry['dropoff']['address'],
				'url'   => self::maps_url( $entry['dropoff'] ),
			),
		);

		$distance = self::distance_label( $entry['pickup'], $entry['dropoff'] );
		if ( '' !== $distance ) {
			$rows[] = array(
				'label' => __( 'Distance', 'moving-quote-form' ),
				'value' => $distance,
			);
		}

		foreach ( $entry['fields'] as $field ) {
			$value = MQF_Fields::display_value( $field['value'], $field['type'] );
			$row   = array(
				'label' => $field['label'],
				'value' => '' === $value ? '—' : $value,
			);
			if ( '' !== $value && 'email' === $field['type'] ) {
				$row['url'] = 'mailto:' . $value;
			} elseif ( '' !== $value && 'tel' === $field['type'] ) {
				$row['url'] = 'tel:' . preg_replace( '/[^0-9+]/', '', $value );
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Send the notification.
	 *
	 * @param array    $entry      Quote request.
	 * @param string[] $recipients Recipient addresses.
	 * @param int      $post_id    Stored entry ID, 0 when not stored.
	 * @return bool
	 */
	public static function send( $entry, $recipients, $post_id = 0 ) {
		/**
		 * Filter the notification recipients.
		 *
		 * @param string[] $recipients Email addresses.
		 * @param array    $entry      Quote request.
		 */
		$recipients = array_filter( (array) apply_filters( 'mqf_email_recipients', $recipients, $entry ), 'is_email' );
		if ( empty( $recipients ) ) {
			return false;
		}

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$name    = '' !== $entry['name'] ? $entry['name'] : __( 'a customer', 'moving-quote-form' );
		$subject = strtr(
			(string) MQF_Settings::get( 'email_subject' ),
			array(
				'{name}'    => $name,
				'{pickup}'  => $entry['pickup']['address'],
				'{dropoff}' => $entry['dropoff']['address'],
				'{site}'    => $site,
			)
		);
		// Header injection guard: a subject is always a single line.
		$subject = trim( preg_replace( '/[\r\n]+/', ' ', $subject ) );
		$subject = apply_filters( 'mqf_email_subject', $subject, $entry );

		$rows = self::rows( $entry );

		$body  = '<!DOCTYPE html><html><body style="margin:0;padding:24px;background:#f3f5f9;font-family:Arial,Helvetica,sans-serif;color:#16213a;">';
		$body .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">';
		$body .= '<tr><td style="background:#06183a;color:#ffffff;padding:20px 24px;font-size:18px;font-weight:bold;">' . esc_html__( 'New moving quote request', 'moving-quote-form' ) . '</td></tr>';
		$body .= '<tr><td style="padding:8px 24px 20px;"><table role="presentation" cellpadding="0" cellspacing="0" width="100%">';

		foreach ( $rows as $row ) {
			$value = nl2br( esc_html( $row['value'] ) );
			if ( ! empty( $row['url'] ) ) {
				$value = '<a href="' . esc_url( $row['url'], array( 'https', 'http', 'mailto', 'tel' ) ) . '" style="color:#1d4ed8;">' . $value . '</a>';
			}
			$body .= '<tr>';
			$body .= '<td style="padding:12px 12px 12px 0;border-bottom:1px solid #e6e9f0;width:36%;vertical-align:top;font-size:13px;color:#5b6478;">' . esc_html( $row['label'] ) . '</td>';
			$body .= '<td style="padding:12px 0;border-bottom:1px solid #e6e9f0;vertical-align:top;font-size:15px;">' . $value . '</td>';
			$body .= '</tr>';
		}

		$body .= '</table></td></tr>';

		$footer = array();
		/* translators: %s: date and time */
		$footer[] = sprintf( esc_html__( 'Submitted on %s', 'moving-quote-form' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['time'] ) ) );
		if ( ! empty( $entry['page_url'] ) ) {
			/* translators: %s: page URL */
			$footer[] = sprintf( esc_html__( 'Sent from %s', 'moving-quote-form' ), '<a href="' . esc_url( $entry['page_url'] ) . '" style="color:#5b6478;">' . esc_html( $entry['page_url'] ) . '</a>' );
		}
		if ( $post_id ) {
			$footer[] = '<a href="' . esc_url( admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' ) ) . '" style="color:#5b6478;">' . esc_html__( 'View this request in WordPress', 'moving-quote-form' ) . '</a>';
		}
		$body .= '<tr><td style="padding:16px 24px;background:#f8f9fc;font-size:12px;color:#5b6478;line-height:1.6;">' . implode( '<br>', $footer ) . '</td></tr>';
		$body .= '</table></body></html>';

		$body = apply_filters( 'mqf_email_body', $body, $entry, $rows );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $entry['email'] && is_email( $entry['email'] ) ) {
			$reply_name = trim( preg_replace( '/[\r\n"<>,;:]+/', ' ', $entry['name'] ) );
			$headers[]  = '' !== $reply_name
				? 'Reply-To: "' . $reply_name . '" <' . $entry['email'] . '>'
				: 'Reply-To: ' . $entry['email'];
		}
		$headers = apply_filters( 'mqf_email_headers', $headers, $entry );

		return (bool) wp_mail( array_values( $recipients ), $subject, $body, $headers );
	}
}
