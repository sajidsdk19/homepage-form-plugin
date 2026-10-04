<?php
/**
 * Gutenberg block (server-rendered, no build step).
 *
 * @package MovingQuoteForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Moving Quote Form" block.
 */
class MQF_Block {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Block attributes (mirrors the shortcode attributes).
	 *
	 * @return array
	 */
	public static function attributes() {
		$attributes = array();
		foreach ( array( 'pickupPlaceholder', 'dropoffPlaceholder', 'buttonText', 'notificationEmail', 'barColor', 'buttonColor', 'buttonTextColor', 'accentColor' ) as $name ) {
			$attributes[ $name ] = array(
				'type'    => 'string',
				'default' => '',
			);
		}
		return $attributes;
	}

	/**
	 * Register the block and its editor script.
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		MQF_Renderer::register_assets();

		wp_register_script(
			'mqf-block-editor',
			MQF_URL . 'assets/js/mqf-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			MQF_VERSION,
			true
		);

		register_block_type(
			'mqf/quote-form',
			array(
				'api_version'     => 2,
				'title'           => __( 'Moving Quote Form', 'moving-quote-form' ),
				'description'     => __( 'Pickup and drop-off bar with Google address autocomplete, followed by the customer details form.', 'moving-quote-form' ),
				'category'        => 'widgets',
				'icon'            => 'location-alt',
				'keywords'        => array( 'quote', 'moving', 'address' ),
				'attributes'      => self::attributes(),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
				'editor_script'   => 'mqf-block-editor',
				'editor_style'    => 'mqf-frontend',
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);
	}

	/**
	 * Render callback.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$get = static function ( $key ) use ( $attributes ) {
			return isset( $attributes[ $key ] ) ? (string) $attributes[ $key ] : '';
		};

		$class = '';
		if ( ! empty( $attributes['align'] ) ) {
			$class = 'align' . sanitize_html_class( $attributes['align'] );
		}

		return MQF_Renderer::render(
			array(
				'pickup_placeholder'  => $get( 'pickupPlaceholder' ),
				'dropoff_placeholder' => $get( 'dropoffPlaceholder' ),
				'button_text'         => $get( 'buttonText' ),
				'notification_email'  => $get( 'notificationEmail' ),
				'bar_color'           => $get( 'barColor' ),
				'button_color'        => $get( 'buttonColor' ),
				'button_text_color'   => $get( 'buttonTextColor' ),
				'accent_color'        => $get( 'accentColor' ),
				'class'               => $class,
			)
		);
	}
}
