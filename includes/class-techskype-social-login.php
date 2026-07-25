<?php
/**
 * Main plugin controller.
 *
 * @package TechSkypeSocialLogin
 */

defined( 'ABSPATH' ) || exit;

final class TechSkype_Social_Login {
	const OPTION_KEY   = 'techskype_social_login_settings';
	const COOKIE_NAME  = 'techskype_social_login_nonce';
	const REST_NS      = 'techskype-social-login/v1';
	const GOOGLE_CERTS = 'https://www.googleapis.com/oauth2/v3/certs';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * OAuth provider manager.
	 *
	 * @var TechSkype_OAuth_Providers
	 */
	private $oauth;

	/**
	 * Get the singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		$this->oauth = new TechSkype_OAuth_Providers( $this );
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'init', array( $this, 'replace_legacy_login_output' ), 20 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_secure_option_storage' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'prepare_login_assets' ) );
		add_action( 'login_form', array( $this, 'render_wordpress_login' ) );
		add_action( 'register_form', array( $this, 'render_wordpress_login' ) );
		add_action( 'comment_form_must_log_in_after', array( $this, 'render_wordpress_login' ) );
		add_action( 'bp_before_account_details_fields', array( $this, 'render_wordpress_login' ) );
		add_action( 'bp_before_sidebar_login_form', array( $this, 'render_wordpress_login' ) );
		add_action( 'after_signup_form', array( $this, 'render_wordpress_login' ) );
		add_action( 'woocommerce_login_form_end', array( $this, 'render_woocommerce_login' ) );
		add_action( 'woocommerce_register_form_end', array( $this, 'render_woocommerce_login' ) );
		add_action( 'admin_notices', array( $this, 'legacy_plugin_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TECHSKYPE_SOCIAL_LOGIN_FILE ), array( $this, 'settings_link' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_privacy_eraser' ) );
	}

	/**
	 * Ensure credential-bearing settings are never autoloaded.
	 */
	public function maybe_secure_option_storage() {
		if ( '1.1' === get_option( 'techskype_social_login_storage_version' ) ) {
			return;
		}

		if ( function_exists( 'wp_set_option_autoload_values' ) ) {
			wp_set_option_autoload_values( array( self::OPTION_KEY => false ) );
		} else {
			global $wpdb;
			$wpdb->update(
				$wpdb->options,
				array( 'autoload' => 'no' ),
				array( 'option_name' => self::OPTION_KEY ),
				array( '%s' ),
				array( '%s' )
			);
		}
		update_option( 'techskype_social_login_storage_version', '1.1', false );
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults() {
		return array_merge(
			array(
			'client_id'        => '',
			'button_text'      => 'continue_with',
			'button_theme'     => 'outline',
			'button_size'      => 'large',
			'wordpress_login'  => 1,
			'woocommerce'      => 1,
			'create_users'     => 1,
			'link_existing'    => 0,
			'default_role'     => get_role( 'customer' ) ? 'customer' : 'subscriber',
			'redirect_url'     => '',
			'allowed_domains'  => '',
			),
			TechSkype_OAuth_Providers::defaults()
		);
	}

	/**
	 * Read settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settings() {
		$settings = wp_parse_args( get_option( self::OPTION_KEY, array() ), $this->defaults() );
		foreach ( array( 'facebook_secret', 'linkedin_secret', 'microsoft_secret', 'apple_private_key' ) as $secret_key ) {
			$settings[ $secret_key ] = $this->decrypt_secret( (string) $settings[ $secret_key ] );
		}
		return $settings;
	}

	/**
	 * Read settings for provider integrations.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings() {
		return $this->settings();
	}

	/**
	 * Register front-end assets without loading them globally.
	 */
	public function register_assets() {
		wp_register_script(
			'google-identity-services',
			'https://accounts.google.com/gsi/client',
			array(),
			null,
			true
		);
		wp_script_add_data( 'google-identity-services', 'async', true );
		wp_script_add_data( 'google-identity-services', 'defer', true );

		wp_register_script(
			'techskype-social-login',
			TECHSKYPE_SOCIAL_LOGIN_URL . 'assets/js/social-login.js',
			array( 'google-identity-services' ),
			TECHSKYPE_SOCIAL_LOGIN_VERSION,
			true
		);
		wp_register_style(
			'techskype-social-login',
			TECHSKYPE_SOCIAL_LOGIN_URL . 'assets/css/social-login.css',
			array(),
			TECHSKYPE_SOCIAL_LOGIN_VERSION
		);
	}

	/**
	 * Register shortcode.
	 */
	public function register_shortcode() {
		add_shortcode( 'techskype_social_login', array( $this, 'shortcode' ) );
	}

	/**
	 * Remove the obsolete plugin's buttons when both plugins are active.
	 */
	public function replace_legacy_login_output() {
		remove_action( 'login_form', 'wsl_render_auth_widget_in_wp_login_form' );
		remove_action( 'register_form', 'wsl_render_auth_widget_in_wp_register_form' );
		remove_action( 'comment_form_top', 'wsl_render_auth_widget_in_comment_form' );
		remove_action( 'comment_form_must_log_in_after', 'wsl_render_auth_widget_in_comment_form' );
		remove_action( 'bp_before_account_details_fields', 'wsl_render_auth_widget_in_wp_login_form' );
		remove_action( 'bp_before_sidebar_login_form', 'wsl_render_auth_widget_in_wp_login_form' );
		remove_action( 'after_signup_form', 'wsl_render_auth_widget_in_wp_register_form' );
	}

	/**
	 * Register and preload styles needed by wp-login.php.
	 */
	public function prepare_login_assets() {
		$this->register_assets();
		if ( ! empty( $this->settings()['wordpress_login'] ) ) {
			wp_enqueue_style( 'techskype-social-login' );
		}
	}

	/**
	 * Render shortcode.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts = array() ) {
		if ( is_user_logged_in() ) {
			return '';
		}

		$settings = $this->settings();
		$atts = shortcode_atts(
			array(
				'redirect' => '',
			),
			$atts,
			'techskype_social_login'
		);

		$redirect = $this->safe_redirect_url( $atts['redirect'] );
		if ( empty( $redirect ) ) {
			$redirect = $this->safe_redirect_url( $settings['redirect_url'] );
		}
		if ( empty( $redirect ) ) {
			$redirect = home_url( '/' );
		}

		wp_enqueue_style( 'techskype-social-login' );
		$oauth_buttons = $this->oauth->buttons_html( $redirect );
		$google       = '';
		$attribute    = '';
		if ( ! empty( $settings['client_id'] ) ) {
			wp_enqueue_script( 'techskype-social-login' );
			wp_localize_script(
				'techskype-social-login',
				'techSkypeSocialLogin',
				array(
					'clientId'      => $settings['client_id'],
					'nonceUrl'      => rest_url( self::REST_NS . '/nonce' ),
					'loginUrl'      => rest_url( self::REST_NS . '/google' ),
					'redirectUrl'   => $redirect,
					'buttonText'    => $settings['button_text'],
					'buttonTheme'   => $settings['button_theme'],
					'buttonSize'    => $settings['button_size'],
					'genericError'  => __( 'Google login could not be completed. Please try again.', 'techskype-social-login' ),
					'networkError'  => __( 'The login service is temporarily unavailable.', 'techskype-social-login' ),
				)
			);
			$google    = '<div class="techskype-google-button"></div>';
			$attribute = ' data-techskype-google-login';
		}

		if ( ! $google && ! $oauth_buttons ) {
			return current_user_can( 'manage_options' )
				? '<p class="techskype-social-login-error">' . esc_html__( 'Configure at least one provider in TechSkype Social Login settings.', 'techskype-social-login' ) . '</p>'
				: '';
		}

		return '<div class="techskype-social-login"' . $attribute . '>' . $google . '<div class="techskype-provider-buttons">' . $oauth_buttons . '</div><p class="techskype-social-login-status" role="alert" aria-live="polite"></p></div>';
	}

	/**
	 * Add button to WooCommerce login and registration forms.
	 */
	public function render_woocommerce_login() {
		$settings = $this->settings();
		if ( ! empty( $settings['woocommerce'] ) ) {
			echo wp_kses_post( do_shortcode( '[techskype_social_login]' ) );
		}
	}

	/**
	 * Add providers to the standard WordPress login and registration forms.
	 */
	public function render_wordpress_login() {
		if ( ! empty( $this->settings()['wordpress_login'] ) ) {
			echo wp_kses_post( do_shortcode( '[techskype_social_login]' ) );
		}
	}

	/**
	 * Prompt administrators to deactivate the replaced plugin.
	 */
	public function legacy_plugin_notice() {
		if ( ! current_user_can( 'activate_plugins' ) || ! defined( 'WORDPRESS_SOCIAL_LOGIN_PLUGIN_URL' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					wp_kses_post( __( 'TechSkype Social Login has replaced WordPress Social Login. Deactivate the old plugin to prevent unnecessary assets and maintenance conflicts. <a href="%s">Manage plugins</a>.', 'techskype-social-login' ) ),
					esc_url( admin_url( 'plugins.php' ) )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register REST routes.
	 */
	public function register_rest_routes() {
		$this->oauth->register_routes();
		register_rest_route(
			self::REST_NS,
			'/nonce',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_login_nonce' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::REST_NS,
			'/google',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'google_login' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'credential' => array(
						'required' => true,
						'type'     => 'string',
					),
					'nonce'      => array(
						'required' => true,
						'type'     => 'string',
					),
					'redirect'   => array(
						'type' => 'string',
					),
				),
			)
		);
	}

	/**
	 * Complete a verified non-Google provider login.
	 *
	 * @param string               $provider Provider slug.
	 * @param array<string, mixed> $identity Verified provider identity.
	 * @param string               $redirect Redirect.
	 * @return string|WP_Error
	 */
	public function complete_social_login( $provider, $identity, $redirect ) {
		$provider = sanitize_key( $provider );
		$email    = sanitize_email( $identity['email'] ?? '' );
		if ( ! $email || empty( $identity['id'] ) || empty( $identity['email_verified'] ) ) {
			return new WP_Error( 'unverified_email', __( 'The provider did not return a verified email address.', 'techskype-social-login' ), array( 'status' => 403 ) );
		}

		$settings        = $this->settings();
		$allowed_domains = array_filter( array_map( 'trim', explode( ',', strtolower( $settings['allowed_domains'] ) ) ) );
		if ( $allowed_domains ) {
			$email_domain = strtolower( substr( strrchr( $email, '@' ), 1 ) );
			if ( ! in_array( $email_domain, $allowed_domains, true ) ) {
				return new WP_Error( 'domain_not_allowed', __( 'This email domain is not permitted.', 'techskype-social-login' ), array( 'status' => 403 ) );
			}
		}

		$meta_key = 'techskype_' . $provider . '_id';
		$user     = get_users(
			array(
				'meta_key'   => $meta_key,
				'meta_value' => sanitize_text_field( $identity['id'] ),
				'number'     => 1,
					'count_total' => false,
				)
			);
		$connected_user = ! empty( $user );
		$user           = $connected_user ? $user[0] : get_user_by( 'email', $email );
		if ( $user && ! $connected_user && empty( $settings['link_existing'] ) ) {
			return new WP_Error(
				'manual_link_required',
				__( 'An account already uses this email. Sign in normally or ask an administrator to connect the social account.', 'techskype-social-login' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $user ) {
			if ( empty( $settings['create_users'] ) ) {
				return new WP_Error( 'registration_disabled', __( 'No account exists for this email address.', 'techskype-social-login' ), array( 'status' => 403 ) );
			}
			$user = $this->create_user_from_identity( $identity );
			if ( is_wp_error( $user ) ) {
				return $user;
			}
		}

		$connected_id = get_user_meta( $user->ID, $meta_key, true );
		if ( $connected_id && ! hash_equals( (string) $connected_id, (string) $identity['id'] ) ) {
			return new WP_Error( 'account_mismatch', __( 'This email is connected to another social account.', 'techskype-social-login' ), array( 'status' => 403 ) );
		}
		update_user_meta( $user->ID, $meta_key, sanitize_text_field( $identity['id'] ) );
		update_user_meta( $user->ID, 'techskype_' . $provider . '_picture', esc_url_raw( $identity['picture'] ?? '' ) );

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		do_action( 'techskype_social_login_authenticated', $user->ID, $provider, $identity );

		return $this->safe_redirect_url( $redirect ) ?: home_url( '/' );
	}

	/**
	 * Issue a short-lived nonce tied to an HttpOnly cookie.
	 *
	 * @return WP_REST_Response
	 */
	public function create_login_nonce() {
		$nonce = wp_generate_password( 48, false, false );
		$this->set_nonce_cookie( $nonce, time() + 10 * MINUTE_IN_SECONDS );

		$response = new WP_REST_Response( array( 'nonce' => $nonce ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	/**
	 * Process a Google credential.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function google_login( WP_REST_Request $request ) {
		if ( is_user_logged_in() ) {
			return new WP_Error( 'already_logged_in', __( 'You are already signed in.', 'techskype-social-login' ), array( 'status' => 400 ) );
		}

		$nonce        = (string) $request->get_param( 'nonce' );
		$cookie_nonce = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';
		if ( empty( $nonce ) || empty( $cookie_nonce ) || ! hash_equals( $cookie_nonce, $nonce ) ) {
			return new WP_Error( 'invalid_login_nonce', __( 'The login request expired. Please try again.', 'techskype-social-login' ), array( 'status' => 403 ) );
		}

		$claims = $this->verify_google_token( (string) $request->get_param( 'credential' ), $nonce );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}

		$email = sanitize_email( $claims['email'] );
		$user  = get_user_by( 'email', $email );
		$new_user = false;
		if ( ! $user ) {
			$settings = $this->settings();
			if ( empty( $settings['create_users'] ) ) {
				return new WP_Error( 'registration_disabled', __( 'No account exists for this email address.', 'techskype-social-login' ), array( 'status' => 403 ) );
			}

			$user = $this->create_user_from_claims( $claims );
			if ( is_wp_error( $user ) ) {
				return $user;
			}
			$new_user = true;
		}

		$existing_google_sub = get_user_meta( $user->ID, 'techskype_google_sub', true );
		if ( $existing_google_sub && ! hash_equals( (string) $existing_google_sub, (string) $claims['sub'] ) ) {
			return new WP_Error( 'account_mismatch', __( 'This email is connected to another Google account.', 'techskype-social-login' ), array( 'status' => 403 ) );
		}
		if ( ! $new_user && ! $existing_google_sub && ! $this->google_is_authoritative_for_email( $claims ) ) {
			return new WP_Error(
				'manual_link_required',
				__( 'For security, this existing account must be connected to Google by an administrator.', 'techskype-social-login' ),
				array( 'status' => 403 )
			);
		}

		update_user_meta( $user->ID, 'techskype_google_sub', sanitize_text_field( $claims['sub'] ) );
		update_user_meta( $user->ID, 'techskype_google_picture', esc_url_raw( $claims['picture'] ?? '' ) );
		$this->set_nonce_cookie( '', time() - HOUR_IN_SECONDS );

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );

		$redirect = $this->safe_redirect_url( (string) $request->get_param( 'redirect' ) );
		if ( empty( $redirect ) ) {
			$redirect = home_url( '/' );
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'redirect' => $redirect,
			)
		);
	}

	/**
	 * Validate and decode an RS256 Google ID token.
	 *
	 * @param string $token JWT.
	 * @param string $nonce Expected nonce.
	 * @return array<string, mixed>|WP_Error
	 */
	private function verify_google_token( $token, $nonce ) {
		if ( ! function_exists( 'openssl_verify' ) ) {
			return new WP_Error( 'openssl_unavailable', __( 'Google login requires the PHP OpenSSL extension.', 'techskype-social-login' ), array( 'status' => 503 ) );
		}

		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return $this->authentication_error();
		}

		$header    = json_decode( $this->base64url_decode( $parts[0] ), true );
		$claims    = json_decode( $this->base64url_decode( $parts[1] ), true );
		$signature = $this->base64url_decode( $parts[2] );
		if ( ! is_array( $header ) || ! is_array( $claims ) || false === $signature || 'RS256' !== ( $header['alg'] ?? '' ) || empty( $header['kid'] ) ) {
			return $this->authentication_error();
		}

		$keys = $this->google_keys();
		if ( is_wp_error( $keys ) || empty( $keys[ $header['kid'] ] ) ) {
			return new WP_Error( 'key_unavailable', __( 'Google login verification is temporarily unavailable.', 'techskype-social-login' ), array( 'status' => 503 ) );
		}

		$verified = openssl_verify( $parts[0] . '.' . $parts[1], $signature, $keys[ $header['kid'] ], OPENSSL_ALGO_SHA256 );
		if ( 1 !== $verified ) {
			return $this->authentication_error();
		}

		$settings = $this->settings();
		$now      = time();
		$issuer   = (string) ( $claims['iss'] ?? '' );
		$audience = $claims['aud'] ?? '';
		$audience = is_array( $audience ) ? $audience : array( $audience );

		if (
			! in_array( $issuer, array( 'accounts.google.com', 'https://accounts.google.com' ), true ) ||
			! in_array( $settings['client_id'], $audience, true ) ||
			empty( $claims['sub'] ) ||
			empty( $claims['email'] ) ||
			empty( $claims['email_verified'] ) ||
			empty( $claims['exp'] ) ||
			(int) $claims['exp'] < $now - 60 ||
			( ! empty( $claims['iat'] ) && (int) $claims['iat'] > $now + 60 ) ||
			empty( $claims['nonce'] ) ||
			! hash_equals( (string) $claims['nonce'], $nonce )
		) {
			return $this->authentication_error();
		}

		$allowed_domains = array_filter( array_map( 'trim', explode( ',', strtolower( $settings['allowed_domains'] ) ) ) );
		if ( $allowed_domains ) {
			$email_domain = strtolower( substr( strrchr( (string) $claims['email'], '@' ), 1 ) );
			if ( ! in_array( $email_domain, $allowed_domains, true ) ) {
				return new WP_Error( 'domain_not_allowed', __( 'This email domain is not permitted.', 'techskype-social-login' ), array( 'status' => 403 ) );
			}
		}

		return $claims;
	}

	/**
	 * Determine whether Google remains authoritative for an email address.
	 *
	 * Google is authoritative for Gmail addresses and Google Workspace
	 * addresses carrying the hosted-domain claim.
	 *
	 * @param array<string, mixed> $claims Verified claims.
	 * @return bool
	 */
	private function google_is_authoritative_for_email( $claims ) {
		$email = strtolower( (string) ( $claims['email'] ?? '' ) );
		return str_ends_with( $email, '@gmail.com' ) || ! empty( $claims['hd'] );
	}

	/**
	 * Retrieve and cache Google's signing keys.
	 *
	 * @return array<string, string>|WP_Error
	 */
	private function google_keys() {
		$cached = get_transient( 'techskype_google_signing_keys' );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}

		$response = wp_safe_remote_get(
			self::GOOGLE_CERTS,
			array(
				'timeout'     => 10,
				'redirection' => 2,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'google_keys_unavailable' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['keys'] ) || ! is_array( $body['keys'] ) ) {
			return new WP_Error( 'google_keys_invalid' );
		}

		$keys = array();
		foreach ( $body['keys'] as $jwk ) {
			if ( empty( $jwk['kid'] ) || 'RSA' !== ( $jwk['kty'] ?? '' ) || empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
				continue;
			}
			$pem = $this->jwk_to_pem( $jwk );
			if ( $pem ) {
				$keys[ $jwk['kid'] ] = $pem;
			}
		}

		if ( ! $keys ) {
			return new WP_Error( 'google_keys_invalid' );
		}

		$max_age       = HOUR_IN_SECONDS;
		$cache_control = wp_remote_retrieve_header( $response, 'cache-control' );
		if ( preg_match( '/max-age=(\d+)/', $cache_control, $matches ) ) {
			$max_age = max( 300, min( DAY_IN_SECONDS, (int) $matches[1] ) );
		}
		set_transient( 'techskype_google_signing_keys', $keys, $max_age );
		return $keys;
	}

	/**
	 * Convert an RSA JWK to a PEM public key.
	 *
	 * @param array<string, string> $jwk Key.
	 * @return string|false
	 */
	private function jwk_to_pem( $jwk ) {
		$modulus  = $this->base64url_decode( $jwk['n'] );
		$exponent = $this->base64url_decode( $jwk['e'] );
		if ( false === $modulus || false === $exponent ) {
			return false;
		}

		$rsa_key = $this->asn1_sequence( $this->asn1_integer( $modulus ) . $this->asn1_integer( $exponent ) );
		$oid     = hex2bin( '300d06092a864886f70d0101010500' );
		$public  = $this->asn1_sequence( $oid . "\x03" . $this->asn1_length( strlen( $rsa_key ) + 1 ) . "\x00" . $rsa_key );

		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $public ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/**
	 * ASN.1 integer.
	 *
	 * @param string $value Binary value.
	 * @return string
	 */
	private function asn1_integer( $value ) {
		$value = ltrim( $value, "\x00" );
		if ( '' === $value ) {
			$value = "\x00";
		}
		if ( ord( $value[0] ) > 0x7f ) {
			$value = "\x00" . $value;
		}
		return "\x02" . $this->asn1_length( strlen( $value ) ) . $value;
	}

	/**
	 * ASN.1 sequence.
	 *
	 * @param string $value Binary value.
	 * @return string
	 */
	private function asn1_sequence( $value ) {
		return "\x30" . $this->asn1_length( strlen( $value ) ) . $value;
	}

	/**
	 * Encode an ASN.1 length.
	 *
	 * @param int $length Length.
	 * @return string
	 */
	private function asn1_length( $length ) {
		if ( $length < 128 ) {
			return chr( $length );
		}
		$temp = '';
		while ( $length > 0 ) {
			$temp   = chr( $length & 0xff ) . $temp;
			$length = $length >> 8;
		}
		return chr( 0x80 | strlen( $temp ) ) . $temp;
	}

	/**
	 * Decode base64url.
	 *
	 * @param string $value Value.
	 * @return string|false
	 */
	private function base64url_decode( $value ) {
		$remainder = strlen( $value ) % 4;
		if ( $remainder ) {
			$value .= str_repeat( '=', 4 - $remainder );
		}
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	/**
	 * Create a WordPress user from verified claims.
	 *
	 * @param array<string, mixed> $claims Claims.
	 * @return WP_User|WP_Error
	 */
	private function create_user_from_claims( $claims ) {
		return $this->create_user_from_identity(
			array(
				'id'         => $claims['sub'],
				'email'      => $claims['email'],
				'name'       => $claims['name'] ?? '',
				'first_name' => $claims['given_name'] ?? '',
				'last_name'  => $claims['family_name'] ?? '',
				'picture'    => $claims['picture'] ?? '',
			)
		);
	}

	/**
	 * Create a user from normalized provider identity data.
	 *
	 * @param array<string, mixed> $identity Identity.
	 * @return WP_User|WP_Error
	 */
	private function create_user_from_identity( $identity ) {
		$email      = sanitize_email( $identity['email'] );
		$base_login = sanitize_user( strstr( $email, '@', true ), true );
		$base_login = $base_login ?: 'google-user';
		$user_login = $base_login;
		$suffix     = 1;
		while ( username_exists( $user_login ) ) {
			$user_login = $base_login . '-' . $suffix++;
		}

		$settings = $this->settings();
		$roles    = wp_roles()->get_names();
		$role     = isset( $roles[ $settings['default_role'] ] ) ? $settings['default_role'] : get_option( 'default_role', 'subscriber' );
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $user_login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => sanitize_text_field( $identity['name'] ?? $user_login ),
				'first_name'   => sanitize_text_field( $identity['first_name'] ?? '' ),
				'last_name'    => sanitize_text_field( $identity['last_name'] ?? '' ),
				'role'         => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		do_action( 'techskype_social_login_user_created', $user_id, $identity );
		return get_user_by( 'id', $user_id );
	}

	/**
	 * Generic authentication error.
	 *
	 * @return WP_Error
	 */
	private function authentication_error() {
		return new WP_Error( 'invalid_google_token', __( 'Google could not verify this login.', 'techskype-social-login' ), array( 'status' => 403 ) );
	}

	/**
	 * Set or clear nonce cookie.
	 *
	 * @param string $value Value.
	 * @param int    $expires Expiry.
	 */
	private function set_nonce_cookie( $value, $expires ) {
		setcookie(
			self::COOKIE_NAME,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Permit only local redirects.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function safe_redirect_url( $url ) {
		$url = esc_url_raw( $url );
		return $url ? wp_validate_redirect( $url, '' ) : '';
	}

	/**
	 * Validate a local redirect for provider manager.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function safe_local_redirect( $url ) {
		return $this->safe_redirect_url( $url );
	}

	/**
	 * Add settings link.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function settings_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=techskype-social-login' ) ) . '">' . esc_html__( 'Settings', 'techskype-social-login' ) . '</a>' );
		return $links;
	}

	/**
	 * Add settings page.
	 */
	public function register_settings_page() {
		add_options_page(
			__( 'TechSkype Social Login', 'techskype-social-login' ),
			__( 'TechSkype Social Login', 'techskype-social-login' ),
			'manage_options',
			'techskype-social-login',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			'techskype_social_login',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->defaults(),
			)
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ) {
		$defaults      = $this->defaults();
		$current       = $this->settings();
		$button_texts  = array( 'signin_with', 'signup_with', 'continue_with', 'signin' );
		$button_themes = array( 'outline', 'filled_blue', 'filled_black' );
		$button_sizes  = array( 'large', 'medium', 'small' );
		$roles         = wp_roles()->get_names();

		$output = array(
			'client_id'       => preg_match( '/^[0-9]+-[a-z0-9_-]+\.apps\.googleusercontent\.com$/i', $input['client_id'] ?? '' ) ? sanitize_text_field( $input['client_id'] ) : '',
			'button_text'     => in_array( $input['button_text'] ?? '', $button_texts, true ) ? $input['button_text'] : $defaults['button_text'],
			'button_theme'    => in_array( $input['button_theme'] ?? '', $button_themes, true ) ? $input['button_theme'] : $defaults['button_theme'],
			'button_size'     => in_array( $input['button_size'] ?? '', $button_sizes, true ) ? $input['button_size'] : $defaults['button_size'],
			'wordpress_login' => empty( $input['wordpress_login'] ) ? 0 : 1,
			'woocommerce'     => empty( $input['woocommerce'] ) ? 0 : 1,
			'create_users'    => empty( $input['create_users'] ) ? 0 : 1,
			'link_existing'   => empty( $input['link_existing'] ) ? 0 : 1,
			'default_role'    => isset( $roles[ $input['default_role'] ?? '' ] ) ? sanitize_key( $input['default_role'] ) : $defaults['default_role'],
			'redirect_url'    => $this->safe_redirect_url( $input['redirect_url'] ?? '' ),
			'allowed_domains' => implode( ',', array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', strtolower( $input['allowed_domains'] ?? '' ) ) ) ) ) ),
		);

		foreach ( array( 'facebook', 'linkedin', 'microsoft' ) as $provider ) {
			$output[ $provider . '_enabled' ] = empty( $input[ $provider . '_enabled' ] ) ? 0 : 1;
			$output[ $provider . '_id' ]      = sanitize_text_field( $input[ $provider . '_id' ] ?? '' );
			$new_secret = trim( (string) ( $input[ $provider . '_secret' ] ?? '' ) );
			$secret = '' !== $new_secret ? sanitize_text_field( $new_secret ) : $current[ $provider . '_secret' ];
			$output[ $provider . '_secret' ] = $this->encrypt_secret( $secret );
		}
		$output['apple_enabled'] = empty( $input['apple_enabled'] ) ? 0 : 1;
		$output['apple_id']      = sanitize_text_field( $input['apple_id'] ?? '' );
		$output['apple_team_id'] = sanitize_text_field( $input['apple_team_id'] ?? '' );
		$output['apple_key_id']  = sanitize_text_field( $input['apple_key_id'] ?? '' );
		$new_private_key         = trim( (string) ( $input['apple_private_key'] ?? '' ) );
		$private_key = '' !== $new_private_key
			? preg_replace( '/\r\n?/', "\n", $new_private_key )
			: $current['apple_private_key'];
		$output['apple_private_key'] = $this->encrypt_secret( $private_key );

		return $output;
	}

	/**
	 * Encrypt a provider secret at rest using WordPress authentication salts.
	 *
	 * @param string $value Plain value.
	 * @return string
	 */
	private function encrypt_secret( $value ) {
		if ( '' === $value || ! function_exists( 'openssl_encrypt' ) ) {
			return $value;
		}
		$key        = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return false === $ciphertext ? $value : 'enc:' . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a stored provider secret.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	private function decrypt_secret( $value ) {
		if ( ! str_starts_with( $value, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return $value;
		}
		$payload = base64_decode( substr( $value, 4 ), true );
		if ( false === $payload || strlen( $payload ) < 29 ) {
			return '';
		}
		$key        = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
		$iv         = substr( $payload, 0, 12 );
		$tag        = substr( $payload, 12, 16 );
		$ciphertext = substr( $payload, 28 );
		$plain      = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = $this->settings();
		$roles    = wp_roles()->get_names();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'TechSkype Social Login', 'techskype-social-login' ); ?></h1>
				<p><?php esc_html_e( 'Configure secure social login for WordPress and WooCommerce.', 'techskype-social-login' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'techskype_social_login' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="techskype-client-id"><?php esc_html_e( 'Google Client ID', 'techskype-social-login' ); ?></label></th>
						<td><input class="regular-text code" id="techskype-client-id" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[client_id]" value="<?php echo esc_attr( $settings['client_id'] ); ?>"><p class="description"><?php esc_html_e( 'Use a Web application OAuth client. No Client Secret is required. Leave empty to disable Google.', 'techskype-social-login' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Button', 'techskype-social-login' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[button_text]">
								<option value="continue_with" <?php selected( $settings['button_text'], 'continue_with' ); ?>><?php esc_html_e( 'Continue with Google', 'techskype-social-login' ); ?></option>
								<option value="signin_with" <?php selected( $settings['button_text'], 'signin_with' ); ?>><?php esc_html_e( 'Sign in with Google', 'techskype-social-login' ); ?></option>
								<option value="signup_with" <?php selected( $settings['button_text'], 'signup_with' ); ?>><?php esc_html_e( 'Sign up with Google', 'techskype-social-login' ); ?></option>
							</select>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[button_theme]">
								<option value="outline" <?php selected( $settings['button_theme'], 'outline' ); ?>><?php esc_html_e( 'Outline', 'techskype-social-login' ); ?></option>
								<option value="filled_blue" <?php selected( $settings['button_theme'], 'filled_blue' ); ?>><?php esc_html_e( 'Blue', 'techskype-social-login' ); ?></option>
								<option value="filled_black" <?php selected( $settings['button_theme'], 'filled_black' ); ?>><?php esc_html_e( 'Black', 'techskype-social-login' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Account creation', 'techskype-social-login' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[create_users]" value="1" <?php checked( $settings['create_users'] ); ?>> <?php esc_html_e( 'Create a WordPress account when the verified email is new', 'techskype-social-login' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Existing accounts', 'techskype-social-login' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[link_existing]" value="1" <?php checked( $settings['link_existing'] ); ?>> <?php esc_html_e( 'Connect a provider when its verified email matches an existing WordPress account', 'techskype-social-login' ); ?></label><p class="description"><?php esc_html_e( 'Leave disabled for the strictest account-linking policy.', 'techskype-social-login' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="techskype-default-role"><?php esc_html_e( 'New user role', 'techskype-social-login' ); ?></label></th>
						<td><select id="techskype-default-role" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_role]"><?php foreach ( $roles as $role_key => $role_name ) : ?><option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $settings['default_role'], $role_key ); ?>><?php echo esc_html( translate_user_role( $role_name ) ); ?></option><?php endforeach; ?></select></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WordPress login', 'techskype-social-login' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[wordpress_login]" value="1" <?php checked( $settings['wordpress_login'] ); ?>> <?php esc_html_e( 'Show on the standard WordPress login and registration forms', 'techskype-social-login' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WooCommerce', 'techskype-social-login' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[woocommerce]" value="1" <?php checked( $settings['woocommerce'] ); ?>> <?php esc_html_e( 'Show on WooCommerce login and registration forms', 'techskype-social-login' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="techskype-redirect"><?php esc_html_e( 'Redirect after login', 'techskype-social-login' ); ?></label></th>
						<td><input class="regular-text" type="url" id="techskype-redirect" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[redirect_url]" value="<?php echo esc_attr( $settings['redirect_url'] ); ?>"><p class="description"><?php esc_html_e( 'Must be a URL on this website. Leave empty for the homepage.', 'techskype-social-login' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="techskype-domains"><?php esc_html_e( 'Allowed email domains', 'techskype-social-login' ); ?></label></th>
						<td><input class="regular-text" id="techskype-domains" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[allowed_domains]" value="<?php echo esc_attr( $settings['allowed_domains'] ); ?>"><p class="description"><?php esc_html_e( 'Optional comma-separated list. Leave empty to allow all verified provider accounts.', 'techskype-social-login' ); ?></p></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Additional providers', 'techskype-social-login' ); ?></h2>
				<p><?php esc_html_e( 'Create a web application with each provider and copy the exact callback URL shown below.', 'techskype-social-login' ); ?></p>
				<table class="form-table" role="presentation">
					<?php foreach ( array( 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn (OpenID Connect)', 'microsoft' => 'Microsoft' ) as $provider => $label ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $provider . '_enabled]' ); ?>" value="1" <?php checked( $settings[ $provider . '_enabled' ] ); ?>> <?php esc_html_e( 'Enable', 'techskype-social-login' ); ?></label>
								<p><label><?php esc_html_e( 'Client ID', 'techskype-social-login' ); ?><br><input class="regular-text code" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $provider . '_id]' ); ?>" value="<?php echo esc_attr( $settings[ $provider . '_id' ] ); ?>"></label></p>
								<p><label><?php esc_html_e( 'Client Secret', 'techskype-social-login' ); ?><br><input class="regular-text code" type="password" autocomplete="new-password" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $provider . '_secret]' ); ?>" placeholder="<?php echo $settings[ $provider . '_secret' ] ? esc_attr__( 'Saved — leave blank to keep', 'techskype-social-login' ) : ''; ?>"></label></p>
								<p><strong><?php esc_html_e( 'Callback URL:', 'techskype-social-login' ); ?></strong> <code><?php echo esc_html( $this->oauth->callback_url( $provider ) ); ?></code></p>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Apple', 'techskype-social-login' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[apple_enabled]" value="1" <?php checked( $settings['apple_enabled'] ); ?>> <?php esc_html_e( 'Enable', 'techskype-social-login' ); ?></label>
							<p><label><?php esc_html_e( 'Services ID', 'techskype-social-login' ); ?><br><input class="regular-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[apple_id]" value="<?php echo esc_attr( $settings['apple_id'] ); ?>"></label></p>
							<p><label><?php esc_html_e( 'Team ID', 'techskype-social-login' ); ?><br><input class="regular-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[apple_team_id]" value="<?php echo esc_attr( $settings['apple_team_id'] ); ?>"></label></p>
							<p><label><?php esc_html_e( 'Key ID', 'techskype-social-login' ); ?><br><input class="regular-text code" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[apple_key_id]" value="<?php echo esc_attr( $settings['apple_key_id'] ); ?>"></label></p>
							<p><label><?php esc_html_e( 'Private key (.p8 contents)', 'techskype-social-login' ); ?><br><textarea class="large-text code" rows="5" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[apple_private_key]" placeholder="<?php echo $settings['apple_private_key'] ? esc_attr__( 'Saved — leave blank to keep', 'techskype-social-login' ) : ''; ?>"></textarea></label></p>
							<p><strong><?php esc_html_e( 'Return URL:', 'techskype-social-login' ); ?></strong> <code><?php echo esc_html( $this->oauth->callback_url( 'apple' ) ); ?></code></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
				<h2><?php esc_html_e( 'Shortcode', 'techskype-social-login' ); ?></h2>
				<p><code>[techskype_social_login]</code></p>
				<?php $this->render_configuration_guide(); ?>
			</div>
			<?php
		}

		/**
		 * Render provider setup examples using this site's callback URLs.
		 */
		private function render_configuration_guide() {
			$scheme      = wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ?: 'https';
			$host        = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			$origin      = $scheme . '://' . $host;
			$base_domain = preg_replace( '/^www\./i', '', (string) $host );
			?>
			<hr>
			<h2><?php esc_html_e( 'Provider configuration examples', 'techskype-social-login' ); ?></h2>
			<p><?php esc_html_e( 'Use the exact URLs below. A different scheme, hostname, path, or trailing slash can cause a redirect mismatch.', 'techskype-social-login' ); ?></p>

			<details open>
				<summary><strong><?php esc_html_e( 'Google', 'techskype-social-login' ); ?></strong></summary>
				<ol>
					<li><?php printf( wp_kses_post( __( 'Open <a href="%s" target="_blank" rel="noopener noreferrer">Google Auth Platform → Clients</a> and create a <strong>Web application</strong>.', 'techskype-social-login' ) ), esc_url( 'https://console.cloud.google.com/auth/clients' ) ); ?></li>
					<li><?php esc_html_e( 'Add this Authorized JavaScript origin:', 'techskype-social-login' ); ?> <code><?php echo esc_html( $origin ); ?></code></li>
					<li><?php esc_html_e( 'No redirect URI or Client Secret is required for the Google Identity Services button.', 'techskype-social-login' ); ?></li>
					<li><?php esc_html_e( 'Example Client ID:', 'techskype-social-login' ); ?> <code>123456789012-example.apps.googleusercontent.com</code></li>
				</ol>
			</details>

			<details>
				<summary><strong><?php esc_html_e( 'Facebook', 'techskype-social-login' ); ?></strong></summary>
				<ol>
					<li><?php printf( wp_kses_post( __( 'Open <a href="%s" target="_blank" rel="noopener noreferrer">Meta for Developers</a>, create a Consumer app, and add Facebook Login for Web.', 'techskype-social-login' ) ), esc_url( 'https://developers.facebook.com/apps/' ) ); ?></li>
					<li><?php esc_html_e( 'App domain:', 'techskype-social-login' ); ?> <code><?php echo esc_html( $base_domain ); ?></code></li>
					<li><?php esc_html_e( 'Website URL:', 'techskype-social-login' ); ?> <code><?php echo esc_html( trailingslashit( $origin ) ); ?></code></li>
					<li><?php esc_html_e( 'Valid OAuth Redirect URI:', 'techskype-social-login' ); ?> <code><?php echo esc_html( $this->oauth->callback_url( 'facebook' ) ); ?></code></li>
					<li><?php esc_html_e( 'Permissions used:', 'techskype-social-login' ); ?> <code>email</code>, <code>public_profile</code></li>
					<li><?php esc_html_e( 'Copy the App ID and App Secret from App settings → Basic.', 'techskype-social-login' ); ?></li>
				</ol>
			</details>

			<details>
				<summary><strong><?php esc_html_e( 'LinkedIn OpenID Connect', 'techskype-social-login' ); ?></strong></summary>
				<ol>
					<li><?php printf( wp_kses_post( __( 'Create an app in the <a href="%s" target="_blank" rel="noopener noreferrer">LinkedIn Developer Portal</a> and associate it with a LinkedIn Page.', 'techskype-social-login' ) ), esc_url( 'https://www.linkedin.com/developers/apps' ) ); ?></li>
					<li><?php esc_html_e( 'Under Products, request “Sign In with LinkedIn using OpenID Connect”.', 'techskype-social-login' ); ?></li>
					<li><?php esc_html_e( 'Authorized redirect URL:', 'techskype-social-login' ); ?> <code><?php echo esc_html( $this->oauth->callback_url( 'linkedin' ) ); ?></code></li>
					<li><?php esc_html_e( 'Scopes used:', 'techskype-social-login' ); ?> <code>openid profile email</code></li>
					<li><?php esc_html_e( 'Copy the Client ID and Primary Client Secret from the Auth tab.', 'techskype-social-login' ); ?></li>
				</ol>
			</details>

			<details>
				<summary><strong><?php esc_html_e( 'Microsoft', 'techskype-social-login' ); ?></strong></summary>
				<ol>
					<li><?php printf( wp_kses_post( __( 'Open <a href="%s" target="_blank" rel="noopener noreferrer">Microsoft Entra app registrations</a> and select New registration.', 'techskype-social-login' ) ), esc_url( 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade' ) ); ?></li>
					<li><?php esc_html_e( 'For customer login, select accounts in any organizational directory and personal Microsoft accounts.', 'techskype-social-login' ); ?></li>
					<li><?php esc_html_e( 'Add a Web platform redirect URI:', 'techskype-social-login' ); ?> <code><?php echo esc_html( $this->oauth->callback_url( 'microsoft' ) ); ?></code></li>
					<li><?php esc_html_e( 'Delegated permissions/scopes used:', 'techskype-social-login' ); ?> <code>openid profile email User.Read</code></li>
					<li><?php esc_html_e( 'Create a Client Secret under Certificates & secrets. Copy its Value immediately; do not use the Secret ID.', 'techskype-social-login' ); ?></li>
				</ol>
			</details>

			<details>
				<summary><strong><?php esc_html_e( 'Apple', 'techskype-social-login' ); ?></strong></summary>
				<ol>
					<li><?php printf( wp_kses_post( __( 'In the <a href="%s" target="_blank" rel="noopener noreferrer">Apple Developer portal</a>, enable Sign in with Apple for an App ID.', 'techskype-social-login' ) ), esc_url( 'https://developer.apple.com/account/resources/identifiers/list' ) ); ?></li>
					<li><?php esc_html_e( 'Create and configure a Services ID; use it as the Client/Services ID.', 'techskype-social-login' ); ?></li>
					<li><?php esc_html_e( 'Primary App ID domain:', 'techskype-social-login' ); ?> <code><?php echo esc_html( $base_domain ); ?></code></li>
					<li><?php esc_html_e( 'Return URL:', 'techskype-social-login' ); ?> <code><?php echo esc_html( $this->oauth->callback_url( 'apple' ) ); ?></code></li>
					<li><?php esc_html_e( 'Create a Sign in with Apple key and download its .p8 file. Enter the Team ID, Key ID, and complete private-key contents.', 'techskype-social-login' ); ?></li>
					<li><?php esc_html_e( 'Example Services ID:', 'techskype-social-login' ); ?> <code>com.example.website.login</code></li>
				</ol>
			</details>
			<?php
		}

	/**
	 * Register personal-data exporter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public function register_privacy_exporter( $exporters ) {
		$exporters['techskype-social-login'] = array(
			'exporter_friendly_name' => __( 'TechSkype Social Login', 'techskype-social-login' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Export plugin user metadata.
	 *
	 * @param string $email Email.
	 * @return array<string, mixed>
	 */
	public function export_personal_data( $email ) {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user ) {
			$provider_data = array();
			foreach ( array( 'google_sub' => 'Google', 'facebook_id' => 'Facebook', 'linkedin_id' => 'LinkedIn', 'microsoft_id' => 'Microsoft', 'apple_id' => 'Apple' ) as $meta_suffix => $provider_label ) {
				$value = get_user_meta( $user->ID, 'techskype_' . $meta_suffix, true );
				if ( $value ) {
					$provider_data[] = array(
						'name'  => sprintf( __( '%s account identifier', 'techskype-social-login' ), $provider_label ),
						'value' => $value,
					);
				}
			}
			$data[] = array(
				'group_id'    => 'techskype-social-login',
				'group_label' => __( 'Social Login', 'techskype-social-login' ),
				'item_id'     => 'techskype-social-login-' . $user->ID,
				'data'        => array_merge(
					$provider_data,
					array(
					array(
						'name'  => __( 'Google profile image', 'techskype-social-login' ),
						'value' => get_user_meta( $user->ID, 'techskype_google_picture', true ),
					),
					)
				),
			);
		}
		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * Register eraser.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public function register_privacy_eraser( $erasers ) {
		$erasers['techskype-social-login'] = array(
			'eraser_friendly_name' => __( 'TechSkype Social Login', 'techskype-social-login' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Erase plugin user metadata.
	 *
	 * @param string $email Email.
	 * @return array<string, mixed>
	 */
	public function erase_personal_data( $email ) {
		$user    = get_user_by( 'email', $email );
		$removed = false;
		if ( $user ) {
			foreach ( array( 'google_sub', 'google_picture', 'facebook_id', 'facebook_picture', 'linkedin_id', 'linkedin_picture', 'microsoft_id', 'microsoft_picture', 'apple_id', 'apple_picture' ) as $meta_suffix ) {
				$removed = delete_user_meta( $user->ID, 'techskype_' . $meta_suffix ) || $removed;
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
