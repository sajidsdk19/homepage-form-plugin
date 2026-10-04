<?php
/**
 * Configurable step 2 fields: definitions, sanitisation, validation.
 *
 * @package MovingQuoteForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field configuration helpers.
 */
class MQF_Fields {

	/**
	 * Supported field types.
	 *
	 * @return array<string,string> type => label
	 */
	public static function types() {
		return array(
			'text'     => __( 'Text', 'moving-quote-form' ),
			'tel'      => __( 'Phone', 'moving-quote-form' ),
			'email'    => __( 'Email', 'moving-quote-form' ),
			'date'     => __( 'Date', 'moving-quote-form' ),
			'number'   => __( 'Number', 'moving-quote-form' ),
			'textarea' => __( 'Paragraph text', 'moving-quote-form' ),
			'select'   => __( 'Dropdown', 'moving-quote-form' ),
			'radio'    => __( 'Single choice (radio)', 'moving-quote-form' ),
			'checkbox' => __( 'Multiple choice (checkboxes)', 'moving-quote-form' ),
		);
	}

	/**
	 * Types that need a list of options.
	 *
	 * @return string[]
	 */
	public static function choice_types() {
		return array( 'select', 'radio', 'checkbox' );
	}

	/**
	 * Default step 2 fields.
	 *
	 * @return array[]
	 */
	public static function defaults() {
		return array(
			array(
				'key'         => 'full_name',
				'label'       => __( 'Full Name', 'moving-quote-form' ),
				'type'        => 'text',
				'required'    => 1,
				'placeholder' => '',
				'options'     => array(),
				'width'       => 'half',
				'future_only' => 0,
			),
			array(
				'key'         => 'phone',
				'label'       => __( 'Phone Number', 'moving-quote-form' ),
				'type'        => 'tel',
				'required'    => 1,
				'placeholder' => '',
				'options'     => array(),
				'width'       => 'half',
				'future_only' => 0,
			),
			array(
				'key'         => 'email',
				'label'       => __( 'Email Address', 'moving-quote-form' ),
				'type'        => 'email',
				'required'    => 1,
				'placeholder' => '',
				'options'     => array(),
				'width'       => 'half',
				'future_only' => 0,
			),
			array(
				'key'         => 'move_date',
				'label'       => __( 'Move Date', 'moving-quote-form' ),
				'type'        => 'date',
				'required'    => 0,
				'placeholder' => '',
				'options'     => array(),
				'width'       => 'half',
				'future_only' => 1,
			),
			array(
				'key'         => 'move_details',
				'label'       => __( 'Property / Move Details', 'moving-quote-form' ),
				'type'        => 'textarea',
				'required'    => 0,
				'placeholder' => __( 'e.g. 2-bedroom flat, 2nd floor, no lift', 'moving-quote-form' ),
				'options'     => array(),
				'width'       => 'full',
				'future_only' => 0,
			),
			array(
				'key'         => 'notes',
				'label'       => __( 'Additional Notes', 'moving-quote-form' ),
				'type'        => 'textarea',
				'required'    => 0,
				'placeholder' => '',
				'options'     => array(),
				'width'       => 'full',
				'future_only' => 0,
			),
		);
	}

	/**
	 * The active field list.
	 *
	 * @return array[]
	 */
	public static function get() {
		$fields = MQF_Settings::get( 'fields', array() );
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			$fields = self::defaults();
		}
		/**
		 * Filter the step 2 fields. Each field needs: key, label, type, required,
		 * placeholder, options (array), width (half|full), future_only.
		 *
		 * @param array[] $fields Field definitions.
		 */
		$fields = apply_filters( 'mqf_fields', $fields );
		return self::sanitize( $fields, false );
	}

	/**
	 * Clean a list of field definitions.
	 *
	 * @param mixed $raw           Raw list (from the settings form or a filter).
	 * @param bool  $options_as_text Whether options may arrive as a newline separated string.
	 * @return array[]
	 */
	public static function sanitize( $raw, $options_as_text = true ) {
		$types = self::types();
		$out   = array();
		$used  = array();

		if ( ! is_array( $raw ) ) {
			return $out;
		}

		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$label = isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : '';
			if ( '' === $label ) {
				continue;
			}

			$type = isset( $field['type'] ) && isset( $types[ $field['type'] ] ) ? $field['type'] : 'text';

			$key = isset( $field['key'] ) ? sanitize_key( str_replace( '-', '_', (string) $field['key'] ) ) : '';
			if ( '' === $key ) {
				$key = sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );
			}
			if ( '' === $key ) {
				$key = 'field';
			}
			$key  = substr( $key, 0, 40 );
			$base = $key;
			$n    = 2;
			while ( isset( $used[ $key ] ) ) {
				$key = $base . '_' . $n;
				++$n;
			}
			$used[ $key ] = true;

			$options = array();
			if ( in_array( $type, self::choice_types(), true ) ) {
				$raw_options = isset( $field['options'] ) ? $field['options'] : array();
				if ( is_string( $raw_options ) && $options_as_text ) {
					$raw_options = preg_split( '/\r\n|\r|\n/', $raw_options );
				}
				foreach ( (array) $raw_options as $option ) {
					$option = sanitize_text_field( (string) $option );
					if ( '' !== $option && ! in_array( $option, $options, true ) ) {
						$options[] = $option;
					}
				}
				if ( empty( $options ) ) {
					// A choice field without choices cannot be answered.
					$type = 'text';
				}
			}

			$out[] = array(
				'key'         => $key,
				'label'       => $label,
				'type'        => $type,
				'required'    => empty( $field['required'] ) ? 0 : 1,
				'placeholder' => isset( $field['placeholder'] ) ? sanitize_text_field( (string) $field['placeholder'] ) : '',
				'options'     => $options,
				'width'       => ( isset( $field['width'] ) && 'full' === $field['width'] ) ? 'full' : 'half',
				'future_only' => ( 'date' === $type && ! empty( $field['future_only'] ) ) ? 1 : 0,
			);
		}

		return $out;
	}

	/**
	 * Find the first field of a type.
	 *
	 * @param array[] $fields Field definitions.
	 * @param string  $type   Field type.
	 * @return string Field key, or '' when there is none.
	 */
	public static function first_of_type( $fields, $type ) {
		foreach ( $fields as $field ) {
			if ( $type === $field['type'] ) {
				return $field['key'];
			}
		}
		return '';
	}

	/**
	 * Key of the field holding the customer's name.
	 *
	 * @param array[] $fields Field definitions.
	 * @return string
	 */
	public static function name_key( $fields ) {
		foreach ( $fields as $field ) {
			if ( 'full_name' === $field['key'] ) {
				return 'full_name';
			}
		}
		foreach ( $fields as $field ) {
			if ( 'text' === $field['type'] && false !== strpos( $field['key'], 'name' ) ) {
				return $field['key'];
			}
		}
		return self::first_of_type( $fields, 'text' );
	}

	/**
	 * Today's date in the site timezone.
	 *
	 * @return string Y-m-d
	 */
	public static function today() {
		return function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/**
	 * Validate and clean one submitted value.
	 *
	 * @param array $field Field definition.
	 * @param mixed $raw   Raw submitted value (already unslashed).
	 * @return array{0:mixed,1:string} Clean value and an error message ('' when valid).
	 */
	public static function validate( $field, $raw ) {
		$type  = $field['type'];
		$label = $field['label'];

		if ( 'checkbox' === $type ) {
			$value = array();
			foreach ( (array) $raw as $item ) {
				$item = sanitize_text_field( (string) $item );
				if ( in_array( $item, $field['options'], true ) && ! in_array( $item, $value, true ) ) {
					$value[] = $item;
				}
			}
			if ( $field['required'] && empty( $value ) ) {
				/* translators: %s: field label */
				return array( $value, sprintf( __( 'Please choose at least one option for %s.', 'moving-quote-form' ), $label ) );
			}
			return array( $value, '' );
		}

		if ( is_array( $raw ) ) {
			$raw = '';
		}
		$raw = (string) $raw;

		if ( 'textarea' === $type ) {
			$value = sanitize_textarea_field( $raw );
			$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 3000 ) : substr( $value, 0, 3000 );
		} else {
			$value = sanitize_text_field( $raw );
			$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 200 ) : substr( $value, 0, 200 );
		}

		if ( '' === $value ) {
			if ( $field['required'] ) {
				/* translators: %s: field label */
				return array( $value, sprintf( __( '%s is required.', 'moving-quote-form' ), $label ) );
			}
			return array( $value, '' );
		}

		switch ( $type ) {
			case 'email':
				// Reject anything that is not already a clean address instead of silently "repairing" it.
				if ( ! is_email( $value ) || sanitize_email( $value ) !== $value ) {
					return array( '', __( 'Please enter a valid email address.', 'moving-quote-form' ) );
				}
				break;

			case 'tel':
				$digits = preg_replace( '/\D/', '', $value );
				if ( ! preg_match( '/^[0-9+()\-.\s]+$/', $value ) || strlen( $digits ) < 6 || strlen( $digits ) > 15 ) {
					return array( $value, __( 'Please enter a valid phone number.', 'moving-quote-form' ) );
				}
				break;

			case 'date':
				$date = DateTime::createFromFormat( '!Y-m-d', $value );
				if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
					return array( $value, __( 'Please enter a valid date.', 'moving-quote-form' ) );
				}
				if ( $field['future_only'] && $value < self::today() ) {
					return array( $value, __( 'Please choose a date that is not in the past.', 'moving-quote-form' ) );
				}
				break;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return array( $value, __( 'Please enter a number.', 'moving-quote-form' ) );
				}
				break;

			case 'select':
			case 'radio':
				if ( ! in_array( $value, $field['options'], true ) ) {
					return array( '', __( 'Please choose one of the available options.', 'moving-quote-form' ) );
				}
				break;
		}

		return array( $value, '' );
	}

	/**
	 * Turn a stored value into a display string.
	 *
	 * @param mixed  $value Stored value.
	 * @param string $type  Field type.
	 * @return string
	 */
	public static function display_value( $value, $type = 'text' ) {
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}
		$value = (string) $value;
		if ( 'date' === $type && '' !== $value ) {
			$date = DateTime::createFromFormat( '!Y-m-d', $value );
			if ( $date ) {
				return date_i18n( get_option( 'date_format' ), $date->getTimestamp() );
			}
		}
		return $value;
	}
}
