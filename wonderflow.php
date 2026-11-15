<?php
/**
 * Plugin Name:       Wonderflow
 * Description:       Sends the clinic website's forms and WhatsApp clicks to the Wonderflow CRM, with the origin of each visit.
 * Version:           0.1.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            WonderAds
 * License:           GPL-2.0-or-later
 * Text Domain:       wonderflow
 * Domain Path:       /languages
 *
 * @package Wonderflow
 */

defined( 'ABSPATH' ) || exit;

define( 'WONDERFLOW_VERSION', '0.1.1' );
define( 'WONDERFLOW_FILE', __FILE__ );
define( 'WONDERFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'WONDERFLOW_URL', plugin_dir_url( __FILE__ ) );

require_once WONDERFLOW_DIR . 'includes/class-wonderflow-settings.php';
require_once WONDERFLOW_DIR . 'includes/class-wonderflow-client.php';
require_once WONDERFLOW_DIR . 'includes/class-wonderflow-queue.php';
require_once WONDERFLOW_DIR . 'includes/class-wonderflow-attribution.php';
require_once WONDERFLOW_DIR . 'includes/class-wonderflow-forms.php';
require_once WONDERFLOW_DIR . 'includes/class-wonderflow-whatsapp.php';
require_once WONDERFLOW_DIR . 'includes/class-wonderflow-admin.php';

register_activation_hook( __FILE__, array( 'Wonderflow_Queue', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Wonderflow_Queue', 'deactivate' ) );

add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'wonderflow', false, dirname( plugin_basename( WONDERFLOW_FILE ) ) . '/languages' );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		Wonderflow_Queue::init();
		Wonderflow_Attribution::init();
		Wonderflow_Forms::init();
		Wonderflow_Whatsapp::init();
		if ( is_admin() ) {
			Wonderflow_Admin::init();
		}
	}
);
