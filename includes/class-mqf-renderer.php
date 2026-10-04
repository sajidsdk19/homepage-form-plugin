<?php
/**
 * Front-end output: shortcode, assets and the form markup.
 *
 * @package MovingQuoteForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the quote component.
 */
class MQF_Renderer {

	const SHORTCODE = 'moving_quote_form';

	/**
	 * Counter so several forms can live on one page.
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'elementor/frontend/after_register_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'elementor/editor/after_enqueue_styles', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Register (not enqueue) assets. They are only loaded on pages that show the form.
	 */
	public static function register_assets() {
		if ( wp_script_is( 'mqf-frontend', 'registered' ) ) {
			return;
		}

		wp_register_style( 'mqf-frontend', MQF_URL . 'assets/css/mqf-frontend.css', array(), MQF_VERSION );
		wp_register_script( 'mqf-frontend', MQF_URL . 'assets/js/mqf-frontend.js', array(), MQF_VERSION, true );
		wp_add_inline_script( 'mqf-frontend', 'window.mqfConfig = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Configuration handed to the front-end script.
	 *
	 * The Google key is read from the plugin settings at runtime; it is never
	 * written into the JavaScript file itself.
	 *
	 * @return array
	 */
	public static function config() {
		$s      = MQF_Settings::all();
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		$config = array(
			'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
			'nonce'             => wp_create_nonce( MQF_Submission::NONCE_ACTION ),
			'apiKey'            => MQF_Settings::api_key(),
			'language'          => str_replace( '_', '-', $locale ),
			'countries'         => MQF_Settings::countries(),
			'types'             => MQF_Settings::place_types(),
			'requireSuggestion' => (bool) $s['require_suggestion'],
			'manualFallback'    => (bool) $s['manual_fallback'],
			'blockSameLocation' => (bool) $s['block_same_location'],
			'useHistory'        => (bool) $s['use_history'],
			'minChars'          => 3,
			'debounce'          => 220,
			'loadTimeout'       => 12000,
			'today'             => MQF_Fields::today(),
			'i18n'              => array(
				'pickupRequired'   => $s['msg_pickup_required'],
				'dropoffRequired'  => $s['msg_dropoff_required'],
				'selectSuggestion' => $s['msg_select_suggestion'],
				'sameLocation'     => $s['msg_same_location'],
				'googleFallback'   => $s['msg_google_fallback'],
				'googleBlocked'    => $s['msg_google_blocked'],
				'genericError'     => $s['msg_error'],
				'required'         => __( 'This field is required.', 'moving-quote-form' ),
				'invalidEmail'     => __( 'Please enter a valid email address.', 'moving-quote-form' ),
				'invalidPhone'     => __( 'Please enter a valid phone number.', 'moving-quote-form' ),
				'invalidDate'      => __( 'Please enter a valid date.', 'moving-quote-form' ),
				'pastDate'         => __( 'Please choose a date that is not in the past.', 'moving-quote-form' ),
				'invalidNumber'    => __( 'Please enter a number.', 'moving-quote-form' ),
				'chooseOption'     => __( 'Please choose at least one option.', 'moving-quote-form' ),
				'noResults'        => __( 'No matching addresses found.', 'moving-quote-form' ),
				'searching'        => __( 'Searching…', 'moving-quote-form' ),
				/* translators: %d: number of address suggestions */
				'resultsCount'     => __( '%d suggestions available. Use the up and down arrow keys to choose one.', 'moving-quote-form' ),
				'poweredBy'        => __( 'Powered by Google', 'moving-quote-form' ),
				'sending'          => __( 'Sending…', 'moving-quote-form' ),
			),
		);

		/**
		 * Filter the front-end configuration.
		 *
		 * @param array $config Configuration array.
		 */
		return apply_filters( 'mqf_frontend_config', $config );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'pickup_placeholder'  => '',
				'dropoff_placeholder' => '',
				'button_text'         => '',
				'title'               => '',
				'submit_text'         => '',
				'notification_email'  => '',
				'bar_color'           => '',
				'bar_text_color'      => '',
				'field_color'         => '',
				'field_text_color'    => '',
				'button_color'        => '',
				'button_text_color'   => '',
				'accent_color'        => '',
				'class'               => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		return self::render( $atts );
	}

	/**
	 * Pick an override when it is set, otherwise the saved setting.
	 *
	 * @param array  $args Overrides.
	 * @param string $key  Override key.
	 * @param string $setting Setting key.
	 * @return string
	 */
	private static function pick( $args, $key, $setting ) {
		if ( isset( $args[ $key ] ) && '' !== trim( (string) $args[ $key ] ) ) {
			return sanitize_text_field( (string) $args[ $key ] );
		}
		return (string) MQF_Settings::get( $setting );
	}

	/**
	 * Inline CSS custom properties for colours.
	 *
	 * @param array $args Overrides.
	 * @return string
	 */
	private static function style_vars( $args ) {
		$map = array(
			'--mqf-bar-bg'      => array( 'bar_color', 'color_bar_bg' ),
			'--mqf-bar-text'    => array( 'bar_text_color', 'color_bar_text' ),
			'--mqf-field-bg'    => array( 'field_color', 'color_field_bg' ),
			'--mqf-field-text'  => array( 'field_text_color', 'color_field_text' ),
			'--mqf-button-bg'   => array( 'button_color', 'color_button_bg' ),
			'--mqf-button-text' => array( 'button_text_color', 'color_button_text' ),
			'--mqf-accent'      => array( 'accent_color', 'color_accent' ),
		);

		$css = '';
		foreach ( $map as $var => $keys ) {
			$color = '';
			if ( ! empty( $args[ $keys[0] ] ) ) {
				$color = sanitize_hex_color( (string) $args[ $keys[0] ] );
			}
			if ( ! $color ) {
				$color = sanitize_hex_color( (string) MQF_Settings::get( $keys[1] ) );
			}
			if ( $color ) {
				$css .= $var . ':' . $color . ';';
			}
		}
		return $css;
	}

	/**
	 * Location pin icon.
	 *
	 * @return string
	 */
	private static function pin_icon() {
		return '<svg class="mqf-loc__icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>';
	}

	/**
	 * Markup for one location field in the bar.
	 *
	 * @param string $uid         Form instance id.
	 * @param string $name        "pickup" or "dropoff".
	 * @param string $label       Accessible label.
	 * @param string $placeholder Placeholder text.
	 * @return string
	 */
	private static function location_field( $uid, $name, $label, $placeholder ) {
		$id = $uid . '-' . $name;

		$html  = '<div class="mqf-loc" data-mqf-loc="' . esc_attr( $name ) . '">';
		$html .= '<label class="mqf-sr-only" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		$html .= '<div class="mqf-loc__control">';
		$html .= self::pin_icon();
		$html .= '<input type="text" class="mqf-loc__input" id="' . esc_attr( $id ) . '" name="mqf_' . esc_attr( $name ) . '_text"';
		$html .= ' placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" enterkeyhint="search"';
		$html .= ' role="combobox" aria-autocomplete="list" aria-expanded="false" aria-haspopup="listbox" aria-controls="' . esc_attr( $id ) . '-list" aria-describedby="' . esc_attr( $id ) . '-error" maxlength="300">';
		$html .= '<button type="button" class="mqf-loc__clear" data-mqf-clear hidden aria-label="' . esc_attr( sprintf( /* translators: %s: field label */ __( 'Clear %s', 'moving-quote-form' ), $label ) ) . '"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg></button>';
		$html .= '</div>';
		$html .= '<p class="mqf-error" id="' . esc_attr( $id ) . '-error" role="alert" hidden></p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Markup for one configurable step 2 field.
	 *
	 * @param string $uid   Form instance id.
	 * @param array  $field Field definition.
	 * @return string
	 */
	private static function detail_field( $uid, $field ) {
		$id       = $uid . '-f-' . $field['key'];
		$name     = 'mqf_fields[' . $field['key'] . ']';
		$required = ! empty( $field['required'] );
		$type     = $field['type'];
		$is_group = in_array( $type, array( 'radio', 'checkbox' ), true );

		$label_html = esc_html( $field['label'] );
		if ( $required ) {
			$label_html .= ' <span class="mqf-field__req" aria-hidden="true">*</span>';
		}

		$common  = ' data-mqf-field="' . esc_attr( $field['key'] ) . '" data-mqf-type="' . esc_attr( $type ) . '"';
		$common .= $required ? ' data-mqf-required="1"' : '';

		$html = '<div class="mqf-field mqf-field--' . esc_attr( $field['width'] ) . ' mqf-field--' . esc_attr( $type ) . '"' . $common . '>';

		$attrs  = ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"';
		$attrs .= $required ? ' required aria-required="true"' : '';
		$attrs .= ' aria-describedby="' . esc_attr( $id ) . '-error"';
		if ( '' !== $field['placeholder'] ) {
			$attrs .= ' placeholder="' . esc_attr( $field['placeholder'] ) . '"';
		}

		if ( $is_group ) {
			$html .= '<fieldset class="mqf-field__group" aria-describedby="' . esc_attr( $id ) . '-error">';
			$html .= '<legend class="mqf-field__label">' . $label_html . '</legend>';
			$html .= '<div class="mqf-field__choices">';
			foreach ( $field['options'] as $i => $option ) {
				$option_id   = $id . '-' . $i;
				$option_name = 'checkbox' === $type ? $name . '[]' : $name;
				$html       .= '<label class="mqf-choice" for="' . esc_attr( $option_id ) . '">';
				$html       .= '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $option_id ) . '" name="' . esc_attr( $option_name ) . '" value="' . esc_attr( $option ) . '">';
				$html       .= '<span>' . esc_html( $option ) . '</span></label>';
			}
			$html .= '</div></fieldset>';
		} else {
			$html .= '<label class="mqf-field__label" for="' . esc_attr( $id ) . '">' . $label_html . '</label>';

			if ( 'textarea' === $type ) {
				$html .= '<textarea class="mqf-input mqf-input--textarea" rows="3" maxlength="3000"' . $attrs . '></textarea>';
			} elseif ( 'select' === $type ) {
				$html .= '<select class="mqf-input mqf-input--select"' . $attrs . '>';
				$html .= '<option value="">' . esc_html( '' !== $field['placeholder'] ? $field['placeholder'] : __( 'Please select…', 'moving-quote-form' ) ) . '</option>';
				foreach ( $field['options'] as $option ) {
					$html .= '<option value="' . esc_attr( $option ) . '">' . esc_html( $option ) . '</option>';
				}
				$html .= '</select>';
			} else {
				$extra = ' maxlength="200"';
				if ( 'email' === $type ) {
					$extra .= ' autocomplete="email" inputmode="email"';
				} elseif ( 'tel' === $type ) {
					$extra .= ' autocomplete="tel" inputmode="tel"';
				} elseif ( 'number' === $type ) {
					$extra = ' inputmode="decimal" step="any"';
				} elseif ( 'date' === $type ) {
					$extra = $field['future_only'] ? ' min="' . esc_attr( MQF_Fields::today() ) . '" data-mqf-future="1"' : '';
				} elseif ( false !== strpos( $field['key'], 'name' ) ) {
					$extra .= ' autocomplete="name"';
				}
				$html .= '<input type="' . esc_attr( $type ) . '" class="mqf-input"' . $attrs . $extra . '>';
			}
		}

		$html .= '<p class="mqf-error" id="' . esc_attr( $id ) . '-error" role="alert" hidden></p>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render the whole component.
	 *
	 * @param array $args Per-instance overrides (see shortcode()).
	 * @return string
	 */
	public static function render( $args = array() ) {
		self::register_assets();
		wp_enqueue_style( 'mqf-frontend' );
		wp_enqueue_script( 'mqf-frontend' );

		++self::$count;
		$uid = 'mqf-' . self::$count;

		$pickup_label    = (string) MQF_Settings::get( 'pickup_label' );
		$dropoff_label   = (string) MQF_Settings::get( 'dropoff_label' );
		$pickup_ph       = self::pick( $args, 'pickup_placeholder', 'pickup_placeholder' );
		$dropoff_ph      = self::pick( $args, 'dropoff_placeholder', 'dropoff_placeholder' );
		$button_text     = self::pick( $args, 'button_text', 'button_text' );
		$title           = self::pick( $args, 'title', 'step2_title' );
		$submit_text     = self::pick( $args, 'submit_text', 'submit_text' );
		$back_text       = (string) MQF_Settings::get( 'back_text' );
		$recipient_key   = empty( $args['notification_email'] ) ? '' : MQF_Settings::register_recipients( $args['notification_email'] );
		$extra_class     = empty( $args['class'] ) ? '' : ' ' . implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $args['class'] ) ) );
		$fields          = MQF_Fields::get();

		ob_start();
		?>
<div class="mqf-quote<?php echo esc_attr( $extra_class ); ?>" id="<?php echo esc_attr( $uid ); ?>" data-mqf data-mqf-instance="<?php echo esc_attr( $recipient_key ); ?>" style="<?php echo esc_attr( self::style_vars( $args ) ); ?>">
		<?php if ( '' === MQF_Settings::api_key() && current_user_can( 'manage_options' ) ) : ?>
	<p class="mqf-admin-note">
			<?php
			printf(
				/* translators: %s: settings page link */
				esc_html__( 'Moving Quote Form (only admins see this): no Google Maps API key is set, so address suggestions are off. %s', 'moving-quote-form' ),
				'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . MQF_Entries::POST_TYPE . '&page=mqf-settings' ) ) . '">' . esc_html__( 'Add the key in settings.', 'moving-quote-form' ) . '</a>'
			);
			?>
	</p>
		<?php endif; ?>

	<form class="mqf-step mqf-step--locations" data-mqf-step="locations" novalidate autocomplete="off">
		<div class="mqf-bar">
			<?php echo self::location_field( $uid, 'pickup', $pickup_label, $pickup_ph ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<span class="mqf-bar__dots" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>
			<?php echo self::location_field( $uid, 'dropoff', $dropoff_label, $dropoff_ph ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<button type="submit" class="mqf-btn mqf-btn--quote" data-mqf-quote><?php echo esc_html( $button_text ); ?></button>
		</div>
		<p class="mqf-notice" data-mqf-notice role="status" hidden></p>
	</form>

	<form class="mqf-step mqf-step--details" data-mqf-step="details" novalidate hidden>
		<div class="mqf-panel">
			<h3 class="mqf-panel__title" data-mqf-details-title tabindex="-1"><?php echo esc_html( $title ); ?></h3>

			<div class="mqf-route">
				<div class="mqf-route__item">
					<span class="mqf-route__label"><?php echo esc_html( $pickup_label ); ?></span>
					<span class="mqf-route__value" data-mqf-summary="pickup"></span>
				</div>
				<div class="mqf-route__item">
					<span class="mqf-route__label"><?php echo esc_html( $dropoff_label ); ?></span>
					<span class="mqf-route__value" data-mqf-summary="dropoff"></span>
				</div>
				<button type="button" class="mqf-route__edit" data-mqf-back><?php echo esc_html( $back_text ); ?></button>
			</div>

			<div class="mqf-fields">
				<?php
				foreach ( $fields as $field ) {
					echo self::detail_field( $uid, $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>

			<div class="mqf-hp" aria-hidden="true">
				<label for="<?php echo esc_attr( $uid ); ?>-website"><?php esc_html_e( 'Leave this field empty', 'moving-quote-form' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $uid ); ?>-website" name="mqf_website" value="" tabindex="-1" autocomplete="off">
			</div>

			<p class="mqf-form-error" data-mqf-form-error role="alert" hidden></p>

			<div class="mqf-actions">
				<button type="submit" class="mqf-btn mqf-btn--submit" data-mqf-submit>
					<span class="mqf-btn__label" data-mqf-submit-label><?php echo esc_html( $submit_text ); ?></span>
					<span class="mqf-btn__spinner" aria-hidden="true"></span>
				</button>
			</div>
		</div>
	</form>

	<div class="mqf-step mqf-step--success" data-mqf-step="success" hidden>
		<div class="mqf-panel mqf-panel--success" role="status" tabindex="-1" data-mqf-success>
			<svg class="mqf-success__icon" viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M7.5 12.5l3 3 6-6.5"/></svg>
			<p class="mqf-success__text" data-mqf-success-text></p>
		</div>
	</div>

	<noscript><p class="mqf-noscript"><?php esc_html_e( 'Please enable JavaScript to request a quote.', 'moving-quote-form' ); ?></p></noscript>
	<div class="mqf-sr-only" data-mqf-live aria-live="polite"></div>
</div>
		<?php
		return (string) ob_get_clean();
	}
}
