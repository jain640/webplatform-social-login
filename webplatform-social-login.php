<?php
/**
 * Plugin Name:       WebPlatform Social Login
 * Plugin URI:        https://webplatform.co.in/plugins
 * Description:       Secure Google, Facebook, LinkedIn, Microsoft and Apple login for WordPress and WooCommerce.
 * Version:           1.4.5
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            WebPlatform
 * Author URI:        https://webplatform.co.in/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webplatform-social-login
 */

defined( 'ABSPATH' ) || exit;

define( 'WEBPLATFORM_SOCIAL_LOGIN_VERSION', '1.4.5' );
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

/**
 * Display the current WebPlatform brand on this plugin's settings screen.
 */
function webplatform_social_login_admin_brand() {
	if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['page'] ) ) {
		return;
	}

	$page = sanitize_key( wp_unslash( $_GET['page'] ) );
	if ( 'webplatform-social-login' !== $page ) {
		return;
	}
	?>
	<div class="webplatform-plugin-brand" style="display:flex;align-items:center;gap:12px;margin:16px 0 8px;padding:12px 16px;background:#fff;border:1px solid #dcdcde;border-radius:8px;box-sizing:border-box;max-width:1100px">
		<img src="<?php echo esc_url( WEBPLATFORM_SOCIAL_LOGIN_URL . 'assets/brand-icon.png' ); ?>" width="48" height="48" alt="" aria-hidden="true" style="display:block;width:48px;height:48px;object-fit:contain">
		<img src="<?php echo esc_url( WEBPLATFORM_SOCIAL_LOGIN_URL . 'assets/brand-wordmark.png' ); ?>" width="180" height="35" alt="<?php echo esc_attr__( 'WebPlatform', 'webplatform-social-login' ); ?>" style="display:block;width:180px;max-width:45vw;height:auto">
	</div>
	<?php
}
add_action( 'admin_notices', 'webplatform_social_login_admin_brand' );

WebPlatform_Social_Login::instance();
