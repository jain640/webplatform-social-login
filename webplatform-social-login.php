<?php
/**
 * Plugin Name:       WebPlatform Social Login
 * Description:       Secure Google, Facebook, LinkedIn, Microsoft and Apple login for WordPress and WooCommerce.
 * Version:           1.4.4
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            jain640
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webplatform-social-login
 */

defined( 'ABSPATH' ) || exit;

define( 'WEBPLATFORM_SOCIAL_LOGIN_VERSION', '1.4.4' );
define( 'WEBPLATFORM_SOCIAL_LOGIN_FILE', __FILE__ );
define( 'WEBPLATFORM_SOCIAL_LOGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WEBPLATFORM_SOCIAL_LOGIN_URL', plugin_dir_url( __FILE__ ) );

require_once WEBPLATFORM_SOCIAL_LOGIN_DIR . 'includes/class-webplatform-oauth-providers.php';
require_once WEBPLATFORM_SOCIAL_LOGIN_DIR . 'includes/class-webplatform-social-login.php';

/**
 * Keep provider credentials out of WordPress's alloptions cache.
 */
function webplatform_social_login_activate() {
	if ( false === get_option( WebPlatform_Social_Login::OPTION_KEY, false ) ) {
		add_option( WebPlatform_Social_Login::OPTION_KEY, array(), '', false );
	}
}
register_activation_hook( __FILE__, 'webplatform_social_login_activate' );

WebPlatform_Social_Login::instance();
