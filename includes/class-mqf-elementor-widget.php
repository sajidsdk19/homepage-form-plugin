<?php
/**
 * Elementor widget. Loaded only when Elementor fires its widget registration hook.
 *
 * @package MovingQuoteForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Moving Quote Form" Elementor widget.
 */
class MQF_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * Widget name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'mqf_quote_form';
	}

	/**
	 * Widget title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Moving Quote Form', 'moving-quote-form' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-map-pin';
	}

	/**
	 * Widget categories.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'quote', 'moving', 'address', 'autocomplete', 'form', 'pickup' );
	}

	/**
	 * Scripts the widget needs.
	 *
	 * @return string[]
	 */
	public function get_script_depends() {
		MQF_Renderer::register_assets();
		return array( 'mqf-frontend' );
	}

	/**
	 * Styles the widget needs.
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		MQF_Renderer::register_assets();
		return array( 'mqf-frontend' );
	}

	/**
	 * Controls.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'mqf_content',
			array(
				'label' => __( 'Quote form', 'moving-quote-form' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$text_controls = array(
			'pickup_placeholder'  => array( __( 'Pickup placeholder', 'moving-quote-form' ), 'pickup_placeholder' ),
			'dropoff_placeholder' => array( __( 'Drop-off placeholder', 'moving-quote-form' ), 'dropoff_placeholder' ),
			'button_text'         => array( __( 'Button text', 'moving-quote-form' ), 'button_text' ),
			'title'               => array( __( 'Step 2 heading', 'moving-quote-form' ), 'step2_title' ),
			'submit_text'         => array( __( 'Submit button text', 'moving-quote-form' ), 'submit_text' ),
		);

		foreach ( $text_controls as $id => $control ) {
			$this->add_control(
				$id,
				array(
					'label'       => $control[0],
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => '',
					'placeholder' => (string) MQF_Settings::get( $control[1] ),
					'label_block' => true,
				)
			);
		}

		$this->add_control(
			'notification_email',
			array(
				'label'       => __( 'Notification email', 'moving-quote-form' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'input_type'  => 'email',
				'default'     => '',
				'placeholder' => (string) MQF_Settings::get( 'notify_email' ),
				'description' => __( 'Leave empty to use the address from the plugin settings. Separate several addresses with commas.', 'moving-quote-form' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'mqf_fields_note',
			array(
				'type'            => \Elementor\Controls_Manager::RAW_HTML,
				'raw'             => sprintf(
					/* translators: %s: settings page link */
					__( 'The Google API key, the step 2 fields and the messages are managed in %s.', 'moving-quote-form' ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=mqf_quote&page=mqf-settings' ) ) . '" target="_blank">' . __( 'Moving Quotes → Settings', 'moving-quote-form' ) . '</a>'
				),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'mqf_style',
			array(
				'label' => __( 'Colours', 'moving-quote-form' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$colors = array(
			'bar_color'         => array( __( 'Bar background', 'moving-quote-form' ), '--mqf-bar-bg' ),
			'bar_text_color'    => array( __( 'Bar text', 'moving-quote-form' ), '--mqf-bar-text' ),
			'field_color'       => array( __( 'Field background', 'moving-quote-form' ), '--mqf-field-bg' ),
			'field_text_color'  => array( __( 'Field text', 'moving-quote-form' ), '--mqf-field-text' ),
			'button_color'      => array( __( 'Button background', 'moving-quote-form' ), '--mqf-button-bg' ),
			'button_text_color' => array( __( 'Button text', 'moving-quote-form' ), '--mqf-button-text' ),
			'accent_color'      => array( __( 'Accent', 'moving-quote-form' ), '--mqf-accent' ),
		);

		foreach ( $colors as $id => $color ) {
			$this->add_control(
				$id,
				array(
					'label'     => $color[0],
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => array(
						// Doubled class so the widget colour wins over the inline default from the plugin settings.
						'{{WRAPPER}} .mqf-quote.mqf-quote' => $color[1] . ': {{VALUE}} !important;',
					),
				)
			);
		}

		$this->end_controls_section();
	}

	/**
	 * Front-end output.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$args     = array();

		foreach ( array( 'pickup_placeholder', 'dropoff_placeholder', 'button_text', 'title', 'submit_text', 'notification_email' ) as $key ) {
			$args[ $key ] = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
		}

		// Colours are applied by Elementor through CSS variables, so every colour format it offers works.
		echo MQF_Renderer::render( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
