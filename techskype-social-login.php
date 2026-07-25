<?php
/**
 * Plugin Name:       TechSkype Social Login
 * Description:       Secure Google, Facebook, LinkedIn, Microsoft and Apple login for WordPress and WooCommerce.
 * Version:           1.4.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            TechSkype
 * Author URI:        https://www.techskype.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       techskype-social-login
 */

defined( 'ABSPATH' ) || exit;

define( 'TECHSKYPE_SOCIAL_LOGIN_VERSION', '1.4.2' );
define( 'TECHSKYPE_SOCIAL_LOGIN_FILE', __FILE__ );
define( 'TECHSKYPE_SOCIAL_LOGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TECHSKYPE_SOCIAL_LOGIN_URL', plugin_dir_url( __FILE__ ) );

require_once TECHSKYPE_SOCIAL_LOGIN_DIR . 'includes/class-techskype-oauth-providers.php';
require_once TECHSKYPE_SOCIAL_LOGIN_DIR . 'includes/class-techskype-social-login.php';

/**
 * Keep provider credentials out of WordPress's alloptions cache.
 */
function techskype_social_login_activate() {
	if ( false === get_option( TechSkype_Social_Login::OPTION_KEY, false ) ) {
		add_option( TechSkype_Social_Login::OPTION_KEY, array(), '', false );
	}
}
register_activation_hook( __FILE__, 'techskype_social_login_activate' );

TechSkype_Social_Login::instance();
