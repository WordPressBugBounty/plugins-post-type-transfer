<?php
/**
 * Plugin Name:       Post Type Transfer
 * Plugin URI:        https://wordpress.org/plugins/post-type-transfer/
 * Description:       This plugin will allow user to change post type to other public post types or page.
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            KrishaWeb
 * Author URI:        https://www.krishaweb.com/
 * Text Domain:       post-type-transfer
 * Domain Path:       /languages
 * Version:           1.5
 * License:           GPLv3 or later
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package           Post_Type_Transfer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
define( 'POST_TYPE_TRANSFER_VERSION', '1.5' );
require_once plugin_dir_path( __FILE__ ) . 'admin/class-post-type-transfer.php';
require_once plugin_dir_path( __FILE__ ) . 'admin/class-ptt-quick-edit.php';
require_once plugin_dir_path( __FILE__ ) . 'lib/block/class-ptt-gutenberg-metabox.php';

/**
 * Load plugin textdomain.
 */
function ptt_textdomain() {
	load_plugin_textdomain( 'post-type-transfer', false, basename( __DIR__ ) . '/languages' );
}
add_action( 'plugins_loaded', 'ptt_textdomain' );

/**
 * Plugin activate hook.
 */
function ptt_activate() {
	// Activation code here...
}
register_activation_hook( __FILE__, 'ptt_activate' );

/**
 * Plugin deactivate hook.
 */
function ptt_deactivate() {
	// Deactivation code here...
}
register_deactivation_hook( __FILE__, 'ptt_deactivate' );

/**
 * Plugin class init.
 */
function ptt_init() {
	// Generate the plugin instance.
	if ( ! class_exists( 'Post_Type_Transfer' ) ) {
		return;
	}
	// Initialize the plugin.
	new Post_Type_Transfer();
}
ptt_init();
