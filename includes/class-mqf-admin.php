<?php
/**
 * Admin settings screen.
 *
 * @package MovingQuoteForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page under "Moving Quotes".
 */
class MQF_Admin {

	const PAGE = 'mqf-settings';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_mqf_clear_log', array( __CLASS__, 'clear_log' ) );
		add_action( 'admin_notices', array( __CLASS__, 'missing_key_notice' ) );
	}

	/**
	 * Settings page URL.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'edit.php?post_type=' . MQF_Entries::POST_TYPE . '&page=' . self::PAGE );
	}

	/**
	 * Add the submenu page.
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . MQF_Entries::POST_TYPE,
			__( 'Moving Quote Form settings', 'moving-quote-form' ),
			__( 'Settings', 'moving-quote-form' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register the option with its sanitiser.
	 */
	public static function register() {
		register_setting(
			'mqf_settings_group',
			MQF_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'MQF_Settings', 'sanitize' ),
			)
		);
	}

	/**
	 * Is this the plugin's settings screen?
	 *
	 * @return bool
	 */
	private static function is_settings_screen() {
		return isset( $_GET['page'] ) && self::PAGE === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Admin assets for the settings screen.
	 */
	public static function assets() {
		if ( ! self::is_settings_screen() ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'mqf-admin', MQF_URL . 'assets/css/mqf-admin.css', array(), MQF_VERSION );
		wp_enqueue_script( 'mqf-admin', MQF_URL . 'assets/js/mqf-admin.js', array( 'jquery', 'wp-color-picker' ), MQF_VERSION, true );
	}

	/**
	 * Remind admins that suggestions are off until a key is added.
	 */
	public static function missing_key_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || MQF_Entries::POST_TYPE !== $screen->post_type || self::is_settings_screen() ) {
			return;
		}
		if ( '' !== MQF_Settings::api_key() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		printf(
			/* translators: %s: settings link */
			esc_html__( 'Moving Quote Form: address suggestions are off until you add a Google Maps API key. %s', 'moving-quote-form' ),
			'<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open settings', 'moving-quote-form' ) . '</a>'
		);
		echo '</p></div>';
	}

	/**
	 * Clear the error log.
	 */
	public static function clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'moving-quote-form' ), 403 );
		}
		check_admin_referer( 'mqf_clear_log' );
		MQF_Logger::clear();
		wp_safe_redirect( self::url() . '#mqf-log' );
		exit;
	}

	/**
	 * Field name inside the settings option.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return MQF_Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Text input row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $help  Help text (may contain safe HTML).
	 * @param string $type  Input type.
	 */
	private static function text_row( $key, $label, $help = '', $type = 'text' ) {
		$id = 'mqf-' . $key;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="' . esc_attr( (string) MQF_Settings::get( $key ) ) . '">';
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Row label.
	 * @param string $text  Checkbox text.
	 * @param string $help  Help text.
	 */
	private static function checkbox_row( $key, $label, $text, $help = '' ) {
		$id = 'mqf-' . $key;
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<label for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="1" ' . checked( 1, (int) MQF_Settings::get( $key ), false ) . '> ' . esc_html( $text ) . '</label>';
		if ( $help ) {
			echo '<p class="description">' . wp_kses_post( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Colour row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 */
	private static function color_row( $key, $label ) {
		$defaults = MQF_Settings::defaults();
		$id       = 'mqf-' . $key;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="text" class="mqf-color" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="' . esc_attr( (string) MQF_Settings::get( $key ) ) . '" data-default-color="' . esc_attr( $defaults[ $key ] ) . '">';
		echo '</td></tr>';
	}

	/**
	 * One row of the field builder.
	 *
	 * @param string $index Row index (or the __INDEX__ placeholder for the template).
	 * @param array  $field Field definition.
	 */
	private static function field_row( $index, $field ) {
		$base  = MQF_Settings::OPTION . '[fields][' . $index . ']';
		$types = MQF_Fields::types();
		?>
		<div class="mqf-fieldrow" data-mqf-fieldrow>
			<div class="mqf-fieldrow__main">
				<label class="mqf-fieldrow__cell mqf-fieldrow__cell--label">
					<span><?php esc_html_e( 'Label', 'moving-quote-form' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $base ); ?>[label]" value="<?php echo esc_attr( $field['label'] ); ?>" data-mqf-label>
				</label>
				<label class="mqf-fieldrow__cell">
					<span><?php esc_html_e( 'Type', 'moving-quote-form' ); ?></span>
					<select name="<?php echo esc_attr( $base ); ?>[type]" data-mqf-type>
						<?php foreach ( $types as $type => $type_label ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $field['type'], $type ); ?>><?php echo esc_html( $type_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="mqf-fieldrow__cell">
					<span><?php esc_html_e( 'Width', 'moving-quote-form' ); ?></span>
					<select name="<?php echo esc_attr( $base ); ?>[width]">
						<option value="half" <?php selected( $field['width'], 'half' ); ?>><?php esc_html_e( 'Half', 'moving-quote-form' ); ?></option>
						<option value="full" <?php selected( $field['width'], 'full' ); ?>><?php esc_html_e( 'Full', 'moving-quote-form' ); ?></option>
					</select>
				</label>
				<label class="mqf-fieldrow__cell mqf-fieldrow__cell--check">
					<input type="checkbox" name="<?php echo esc_attr( $base ); ?>[required]" value="1" <?php checked( 1, (int) $field['required'] ); ?>>
					<span><?php esc_html_e( 'Required', 'moving-quote-form' ); ?></span>
				</label>
				<div class="mqf-fieldrow__actions">
					<button type="button" class="button-link" data-mqf-up aria-label="<?php esc_attr_e( 'Move up', 'moving-quote-form' ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
					<button type="button" class="button-link" data-mqf-down aria-label="<?php esc_attr_e( 'Move down', 'moving-quote-form' ); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
					<button type="button" class="button-link mqf-fieldrow__remove" data-mqf-remove aria-label="<?php esc_attr_e( 'Remove field', 'moving-quote-form' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>
				</div>
			</div>
			<div class="mqf-fieldrow__extra">
				<label class="mqf-fieldrow__cell" data-mqf-show="placeholder">
					<span><?php esc_html_e( 'Placeholder (optional)', 'moving-quote-form' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $base ); ?>[placeholder]" value="<?php echo esc_attr( $field['placeholder'] ); ?>">
				</label>
				<label class="mqf-fieldrow__cell" data-mqf-show="options">
					<span><?php esc_html_e( 'Choices (one per line)', 'moving-quote-form' ); ?></span>
					<textarea name="<?php echo esc_attr( $base ); ?>[options]" rows="3"><?php echo esc_textarea( implode( "\n", (array) $field['options'] ) ); ?></textarea>
				</label>
				<label class="mqf-fieldrow__cell mqf-fieldrow__cell--check" data-mqf-show="future">
					<input type="checkbox" name="<?php echo esc_attr( $base ); ?>[future_only]" value="1" <?php checked( 1, (int) $field['future_only'] ); ?>>
					<span><?php esc_html_e( 'Do not allow past dates', 'moving-quote-form' ); ?></span>
				</label>
				<input type="hidden" name="<?php echo esc_attr( $base ); ?>[key]" value="<?php echo esc_attr( $field['key'] ); ?>">
			</div>
		</div>
		<?php
	}

	/**
	 * Render the settings page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$fields    = MQF_Settings::get( 'fields' );
		$fields    = MQF_Fields::sanitize( is_array( $fields ) ? $fields : array(), false );
		$log       = MQF_Logger::entries();
		$key_const = MQF_Settings::api_key_is_constant();
		$host      = wp_parse_url( home_url(), PHP_URL_HOST );
		$blank     = array(
			'key'         => '',
			'label'       => '',
			'type'        => 'text',
			'required'    => 0,
			'placeholder' => '',
			'options'     => array(),
			'width'       => 'half',
			'future_only' => 0,
		);
		?>
		<div class="wrap mqf-admin">
			<h1><?php esc_html_e( 'Moving Quote Form', 'moving-quote-form' ); ?></h1>
			<?php settings_errors(); ?>

			<div class="mqf-card mqf-card--usage">
				<h2><?php esc_html_e( 'How to add the form to a page', 'moving-quote-form' ); ?></h2>
				<ul>
					<li><strong><?php esc_html_e( 'Shortcode:', 'moving-quote-form' ); ?></strong> <code>[moving_quote_form]</code> — <?php esc_html_e( 'paste it into any page, or into an Elementor “Shortcode” widget.', 'moving-quote-form' ); ?></li>
					<li><strong><?php esc_html_e( 'Elementor:', 'moving-quote-form' ); ?></strong> <?php esc_html_e( 'search the widget panel for “Moving Quote Form” and drag it in.', 'moving-quote-form' ); ?></li>
					<li><strong><?php esc_html_e( 'Block editor:', 'moving-quote-form' ); ?></strong> <?php esc_html_e( 'add the “Moving Quote Form” block.', 'moving-quote-form' ); ?></li>
				</ul>
			</div>

			<form method="post" action="options.php" class="mqf-settings-form">
				<?php settings_fields( 'mqf_settings_group' ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::name( '_from_form' ) ); ?>" value="1">

				<div class="mqf-card">
					<h2><?php esc_html_e( 'Google address autocomplete', 'moving-quote-form' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="mqf-api_key"><?php esc_html_e( 'Google Maps API key', 'moving-quote-form' ); ?></label></th>
							<td>
								<?php if ( $key_const ) : ?>
									<input type="text" class="regular-text" value="<?php echo esc_attr( str_repeat( '•', 12 ) . substr( MQF_Settings::api_key(), -4 ) ); ?>" disabled>
									<p class="description"><?php echo wp_kses_post( __( 'The key is set in <code>wp-config.php</code> with the <code>MQF_GOOGLE_MAPS_API_KEY</code> constant.', 'moving-quote-form' ) ); ?></p>
								<?php else : ?>
									<input type="text" class="regular-text code" id="mqf-api_key" name="<?php echo esc_attr( self::name( 'api_key' ) ); ?>" value="<?php echo esc_attr( (string) MQF_Settings::get( 'api_key' ) ); ?>" autocomplete="off" spellcheck="false">
								<?php endif; ?>
								<p class="description">
									<?php echo wp_kses_post( __( 'In Google Cloud, enable <strong>Maps JavaScript API</strong> and <strong>Places API (New)</strong> for the project, with billing switched on.', 'moving-quote-form' ) ); ?>
								</p>
								<p class="description">
									<?php
									echo wp_kses_post(
										sprintf(
											/* translators: 1, 2: referrer patterns */
											__( 'Restrict the key before using it on a live site: Application restriction → <strong>Websites</strong> with %1$s and %2$s; API restriction → only the two APIs above. A browser key is always visible to visitors, so these restrictions are what keep it safe.', 'moving-quote-form' ),
											'<code>https://' . esc_html( $host ) . '/*</code>',
											'<code>https://*.' . esc_html( preg_replace( '/^www\./', '', (string) $host ) ) . '/*</code>'
										)
									);
									?>
								</p>
							</td>
						</tr>
						<?php
						self::text_row(
							'countries',
							__( 'Limit to countries', 'moving-quote-form' ),
							__( 'Two-letter country codes separated by commas, for example <code>gb</code> or <code>us, ca</code> (up to 15). Leave empty for worldwide results.', 'moving-quote-form' )
						);
						?>
						<tr>
							<th scope="row"><label for="mqf-place_types"><?php esc_html_e( 'Show suggestions for', 'moving-quote-form' ); ?></label></th>
							<td>
								<select id="mqf-place_types" name="<?php echo esc_attr( self::name( 'place_types' ) ); ?>">
									<option value="address" <?php selected( MQF_Settings::get( 'place_types' ), 'address' ); ?>><?php esc_html_e( 'Addresses, streets and postcodes (recommended)', 'moving-quote-form' ); ?></option>
									<option value="regions" <?php selected( MQF_Settings::get( 'place_types' ), 'regions' ); ?>><?php esc_html_e( 'Towns, cities and postcodes only', 'moving-quote-form' ); ?></option>
									<option value="all" <?php selected( MQF_Settings::get( 'place_types' ), 'all' ); ?>><?php esc_html_e( 'Everything, including businesses', 'moving-quote-form' ); ?></option>
								</select>
							</td>
						</tr>
						<?php
						self::checkbox_row(
							'require_suggestion',
							__( 'Typed addresses', 'moving-quote-form' ),
							__( 'Visitors must pick an address from the suggestions', 'moving-quote-form' ),
							__( 'When off, any typed text is accepted as a location.', 'moving-quote-form' )
						);
						self::checkbox_row(
							'manual_fallback',
							__( 'If Google is unavailable', 'moving-quote-form' ),
							__( 'Let visitors type the address by hand so they can still request a quote', 'moving-quote-form' ),
							__( 'Applies when the Google script cannot load, the key is rejected or the quota is used up. When off, the form shows an error instead. Either way the technical error is logged at the bottom of this page.', 'moving-quote-form' )
						);
						self::checkbox_row(
							'block_same_location',
							__( 'Same pickup and drop-off', 'moving-quote-form' ),
							__( 'Do not allow the same location in both fields', 'moving-quote-form' )
						);
						?>
					</table>
				</div>

				<div class="mqf-card">
					<h2><?php esc_html_e( 'Notifications and storage', 'moving-quote-form' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::text_row(
							'notify_email',
							__( 'Send quote requests to', 'moving-quote-form' ),
							__( 'Separate several addresses with commas.', 'moving-quote-form' )
						);
						self::text_row(
							'email_subject',
							__( 'Email subject', 'moving-quote-form' ),
							__( 'You can use <code>{name}</code>, <code>{pickup}</code>, <code>{dropoff}</code> and <code>{site}</code>.', 'moving-quote-form' )
						);
						self::checkbox_row(
							'store_entries',
							__( 'Save requests', 'moving-quote-form' ),
							__( 'Keep a copy of every quote request in WordPress', 'moving-quote-form' ),
							__( 'Saved requests appear under Moving Quotes → Quote Requests and can be exported as CSV. Recommended: it is your safety net if an email goes missing.', 'moving-quote-form' )
						);
						?>
					</table>
				</div>

				<div class="mqf-card">
					<h2><?php esc_html_e( 'Step 1 — location bar', 'moving-quote-form' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::text_row( 'pickup_placeholder', __( 'Pickup placeholder', 'moving-quote-form' ) );
						self::text_row( 'dropoff_placeholder', __( 'Drop-off placeholder', 'moving-quote-form' ) );
						self::text_row( 'button_text', __( 'Button text', 'moving-quote-form' ) );
						self::text_row( 'pickup_label', __( 'Pickup label', 'moving-quote-form' ), __( 'Used by screen readers, in step 2 and in the email.', 'moving-quote-form' ) );
						self::text_row( 'dropoff_label', __( 'Drop-off label', 'moving-quote-form' ) );
						?>
					</table>
				</div>

				<div class="mqf-card">
					<h2><?php esc_html_e( 'Step 2 — customer details form', 'moving-quote-form' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::text_row( 'step2_title', __( 'Heading', 'moving-quote-form' ) );
						self::text_row( 'submit_text', __( 'Submit button text', 'moving-quote-form' ) );
						self::text_row( 'back_text', __( '“Edit locations” link text', 'moving-quote-form' ) );
						?>
					</table>

					<h3><?php esc_html_e( 'Fields', 'moving-quote-form' ); ?></h3>
					<p class="description"><?php esc_html_e( 'The pickup and drop-off locations are always carried over from step 1. Add, remove or reorder the remaining fields here. The first Email field is used as the reply-to address of the notification.', 'moving-quote-form' ); ?></p>

					<div class="mqf-fieldrows" data-mqf-fieldrows data-next-index="<?php echo esc_attr( (string) count( $fields ) ); ?>">
						<?php
						foreach ( $fields as $i => $field ) {
							self::field_row( (string) $i, $field );
						}
						?>
					</div>
					<p><button type="button" class="button" data-mqf-add><?php esc_html_e( 'Add field', 'moving-quote-form' ); ?></button></p>
					<script type="text/html" id="mqf-fieldrow-template"><?php self::field_row( '__INDEX__', $blank ); ?></script>
				</div>

				<div class="mqf-card">
					<h2><?php esc_html_e( 'Messages', 'moving-quote-form' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::text_row( 'msg_pickup_required', __( 'Pickup is empty', 'moving-quote-form' ) );
						self::text_row( 'msg_dropoff_required', __( 'Drop-off is empty', 'moving-quote-form' ) );
						self::text_row( 'msg_select_suggestion', __( 'Typed but not selected', 'moving-quote-form' ) );
						self::text_row( 'msg_same_location', __( 'Same pickup and drop-off', 'moving-quote-form' ) );
						self::text_row( 'msg_google_fallback', __( 'Google unavailable (typing allowed)', 'moving-quote-form' ) );
						self::text_row( 'msg_google_blocked', __( 'Google unavailable (typing not allowed)', 'moving-quote-form' ) );
						self::text_row( 'msg_success', __( 'Request sent', 'moving-quote-form' ) );
						self::text_row( 'msg_error', __( 'Request failed', 'moving-quote-form' ) );
						?>
					</table>
				</div>

				<div class="mqf-card">
					<h2><?php esc_html_e( 'Colours', 'moving-quote-form' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Defaults match the dark navy quote bar. The font is inherited from your theme.', 'moving-quote-form' ); ?></p>
					<table class="form-table" role="presentation">
						<?php
						self::color_row( 'color_bar_bg', __( 'Bar / form background', 'moving-quote-form' ) );
						self::color_row( 'color_bar_text', __( 'Bar / form text', 'moving-quote-form' ) );
						self::color_row( 'color_field_bg', __( 'Field background', 'moving-quote-form' ) );
						self::color_row( 'color_field_text', __( 'Field text', 'moving-quote-form' ) );
						self::color_row( 'color_button_bg', __( 'Button background', 'moving-quote-form' ) );
						self::color_row( 'color_button_text', __( 'Button text', 'moving-quote-form' ) );
						self::color_row( 'color_accent', __( 'Accent (focus ring, highlights)', 'moving-quote-form' ) );
						?>
					</table>
				</div>

				<div class="mqf-card">
					<h2><?php esc_html_e( 'Advanced', 'moving-quote-form' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						self::checkbox_row(
							'use_history',
							__( 'Browser back button', 'moving-quote-form' ),
							__( 'Back returns from step 2 to the location bar', 'moving-quote-form' ),
							__( 'Turn this off if your theme uses its own AJAX page transitions and they conflict.', 'moving-quote-form' )
						);
						self::checkbox_row(
							'delete_on_uninstall',
							__( 'Uninstall', 'moving-quote-form' ),
							__( 'Delete all settings and saved quote requests when the plugin is deleted', 'moving-quote-form' )
						);
						?>
					</table>
				</div>

				<?php submit_button(); ?>
			</form>

			<div class="mqf-card" id="mqf-log">
				<h2><?php esc_html_e( 'Error log', 'moving-quote-form' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Technical problems with Google, email delivery or storage are recorded here (latest 50).', 'moving-quote-form' ); ?></p>
				<?php if ( empty( $log ) ) : ?>
					<p><?php esc_html_e( 'Nothing logged. All good.', 'moving-quote-form' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:170px;"><?php esc_html_e( 'When', 'moving-quote-form' ); ?></th>
								<th style="width:90px;"><?php esc_html_e( 'Area', 'moving-quote-form' ); ?></th>
								<th><?php esc_html_e( 'Details', 'moving-quote-form' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $log as $row ) : ?>
								<tr>
									<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $row['time'] ) ); ?></td>
									<td><?php echo esc_html( $row['context'] ); ?></td>
									<td>
										<?php echo esc_html( $row['message'] ); ?>
										<?php if ( ! empty( $row['url'] ) ) : ?>
											<br><span class="description"><?php echo esc_html( $row['url'] ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
						<input type="hidden" name="action" value="mqf_clear_log">
						<?php wp_nonce_field( 'mqf_clear_log' ); ?>
						<?php submit_button( __( 'Clear log', 'moving-quote-form' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
