<?php
/**
 * Plugin Name:       TechSkype Social Login
 * Plugin URI:        https://www.techskype.com/
 * Description:       Secure Sign in with Google integration for WordPress and WooCommerce.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            TechSkype
 * Author URI:        https://www.techskype.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       techskype-social-login
 */

defined( 'ABSPATH' ) || exit;

define( 'TECHSKYPE_SOCIAL_LOGIN_VERSION', '1.0.0' );
define( 'TECHSKYPE_SOCIAL_LOGIN_FILE', __FILE__ );
define( 'TECHSKYPE_SOCIAL_LOGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TECHSKYPE_SOCIAL_LOGIN_URL', plugin_dir_url( __FILE__ ) );

require_once TECHSKYPE_SOCIAL_LOGIN_DIR . 'includes/class-techskype-social-login.php';

TechSkype_Social_Login::instance();

