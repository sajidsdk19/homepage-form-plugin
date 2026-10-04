<?php
/**
 * Plugin Name:       Moving Quote Form & Location Autocomplete
 * Description:       Two-step moving quote form. Step 1 collects pickup and drop-off locations with Google address autocomplete; step 2 collects the customer's details and emails one complete quote request to the site owner.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Sajid Khan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       moving-quote-form
 *
 * @package MovingQuoteForm
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MQF_VERSION', '1.0.0' );
define( 'MQF_FILE', __FILE__ );
define( 'MQF_DIR', plugin_dir_path( __FILE__ ) );
define( 'MQF_URL', plugin_dir_url( __FILE__ ) );

require_once MQF_DIR . 'includes/class-mqf-settings.php';
require_once MQF_DIR . 'includes/class-mqf-fields.php';
require_once MQF_DIR . 'includes/class-mqf-logger.php';
require_once MQF_DIR . 'includes/class-mqf-renderer.php';
require_once MQF_DIR . 'includes/class-mqf-email.php';
require_once MQF_DIR . 'includes/class-mqf-entries.php';
require_once MQF_DIR . 'includes/class-mqf-submission.php';
require_once MQF_DIR . 'includes/class-mqf-block.php';
require_once MQF_DIR . 'includes/class-mqf-admin.php';

/**
 * Boot the plugin.
 */
function mqf_boot() {
	MQF_Renderer::init();
	MQF_Entries::init();
	MQF_Submission::init();
	MQF_Logger::init();
	MQF_Block::init();

	if ( is_admin() ) {
		MQF_Admin::init();
	}
}
add_action( 'plugins_loaded', 'mqf_boot' );

/**
 * Register the Elementor widget when Elementor is active.
 *
 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
 */
function mqf_register_elementor_widget( $widgets_manager ) {
	require_once MQF_DIR . 'includes/class-mqf-elementor-widget.php';
	$widgets_manager->register( new MQF_Elementor_Widget() );
}
add_action( 'elementor/widgets/register', 'mqf_register_elementor_widget' );

/**
 * Store default settings on activation so the form works straight away.
 */
function mqf_activate() {
	if ( false === get_option( MQF_Settings::OPTION, false ) ) {
		add_option( MQF_Settings::OPTION, MQF_Settings::defaults() );
	}
}
register_activation_hook( __FILE__, 'mqf_activate' );

/**
 * Add a Settings link on the Plugins screen.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
function mqf_action_links( $links ) {
	$url = admin_url( 'edit.php?post_type=' . MQF_Entries::POST_TYPE . '&page=mqf-settings' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'moving-quote-form' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'mqf_action_links' );
