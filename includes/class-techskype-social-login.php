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
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'woocommerce_login_form_end', array( $this, 'render_woocommerce_login' ) );
		add_action( 'woocommerce_register_form_end', array( $this, 'render_woocommerce_login' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TECHSKYPE_SOCIAL_LOGIN_FILE ), array( $this, 'settings_link' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_privacy_eraser' ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults() {
		return array(
			'client_id'        => '',
			'button_text'      => 'continue_with',
			'button_theme'     => 'outline',
			'button_size'      => 'large',
			'woocommerce'      => 1,
			'create_users'     => 1,
			'default_role'     => get_role( 'customer' ) ? 'customer' : 'subscriber',
			'redirect_url'     => '',
			'allowed_domains'  => '',
		);
	}

	/**
	 * Read settings.
	 *
	 * @return array<string, mixed>
	 */
	private function settings() {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), $this->defaults() );
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
		if ( empty( $settings['client_id'] ) ) {
			return current_user_can( 'manage_options' )
				? '<p class="techskype-social-login-error">' . esc_html__( 'TechSkype Social Login requires a Google Client ID.', 'techskype-social-login' ) . '</p>'
				: '';
		}

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

		wp_enqueue_script( 'techskype-social-login' );
		wp_enqueue_style( 'techskype-social-login' );
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

		return '<div class="techskype-social-login" data-techskype-google-login><div class="techskype-google-button"></div><p class="techskype-social-login-status" role="alert" aria-live="polite"></p></div>';
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
	 * Register REST routes.
	 */
	public function register_rest_routes() {
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
		if ( ! $user ) {
			$settings = $this->settings();
			if ( empty( $settings['create_users'] ) ) {
				return new WP_Error( 'registration_disabled', __( 'No account exists for this email address.', 'techskype-social-login' ), array( 'status' => 403 ) );
			}

			$user = $this->create_user_from_claims( $claims );
			if ( is_wp_error( $user ) ) {
				return $user;
			}
		}

		$existing_google_sub = get_user_meta( $user->ID, 'techskype_google_sub', true );
		if ( $existing_google_sub && ! hash_equals( (string) $existing_google_sub, (string) $claims['sub'] ) ) {
			return new WP_Error( 'account_mismatch', __( 'This email is connected to another Google account.', 'techskype-social-login' ), array( 'status' => 403 ) );
		}
		if ( ! $existing_google_sub && ! $this->google_is_authoritative_for_email( $claims ) ) {
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
		$email      = sanitize_email( $claims['email'] );
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
				'display_name' => sanitize_text_field( $claims['name'] ?? $user_login ),
				'first_name'   => sanitize_text_field( $claims['given_name'] ?? '' ),
				'last_name'    => sanitize_text_field( $claims['family_name'] ?? '' ),
				'role'         => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, 'techskype_google_sub', sanitize_text_field( $claims['sub'] ) );
		update_user_meta( $user_id, 'techskype_google_picture', esc_url_raw( $claims['picture'] ?? '' ) );
		do_action( 'techskype_social_login_user_created', $user_id, $claims );
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
		$button_texts  = array( 'signin_with', 'signup_with', 'continue_with', 'signin' );
		$button_themes = array( 'outline', 'filled_blue', 'filled_black' );
		$button_sizes  = array( 'large', 'medium', 'small' );
		$roles         = wp_roles()->get_names();

		return array(
			'client_id'       => preg_match( '/^[0-9]+-[a-z0-9_-]+\.apps\.googleusercontent\.com$/i', $input['client_id'] ?? '' ) ? sanitize_text_field( $input['client_id'] ) : '',
			'button_text'     => in_array( $input['button_text'] ?? '', $button_texts, true ) ? $input['button_text'] : $defaults['button_text'],
			'button_theme'    => in_array( $input['button_theme'] ?? '', $button_themes, true ) ? $input['button_theme'] : $defaults['button_theme'],
			'button_size'     => in_array( $input['button_size'] ?? '', $button_sizes, true ) ? $input['button_size'] : $defaults['button_size'],
			'woocommerce'     => empty( $input['woocommerce'] ) ? 0 : 1,
			'create_users'    => empty( $input['create_users'] ) ? 0 : 1,
			'default_role'    => isset( $roles[ $input['default_role'] ?? '' ] ) ? sanitize_key( $input['default_role'] ) : $defaults['default_role'],
			'redirect_url'    => $this->safe_redirect_url( $input['redirect_url'] ?? '' ),
			'allowed_domains' => implode( ',', array_filter( array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', strtolower( $input['allowed_domains'] ?? '' ) ) ) ) ) ),
		);
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
			<p><?php esc_html_e( 'Configure secure Sign in with Google for WordPress and WooCommerce.', 'techskype-social-login' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'techskype_social_login' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="techskype-client-id"><?php esc_html_e( 'Google Client ID', 'techskype-social-login' ); ?></label></th>
						<td><input class="regular-text code" id="techskype-client-id" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[client_id]" value="<?php echo esc_attr( $settings['client_id'] ); ?>" required><p class="description"><?php esc_html_e( 'Use a Web application OAuth client. No Client Secret is required.', 'techskype-social-login' ); ?></p></td>
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
						<th scope="row"><label for="techskype-default-role"><?php esc_html_e( 'New user role', 'techskype-social-login' ); ?></label></th>
						<td><select id="techskype-default-role" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_role]"><?php foreach ( $roles as $role_key => $role_name ) : ?><option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $settings['default_role'], $role_key ); ?>><?php echo esc_html( translate_user_role( $role_name ) ); ?></option><?php endforeach; ?></select></td>
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
						<td><input class="regular-text" id="techskype-domains" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[allowed_domains]" value="<?php echo esc_attr( $settings['allowed_domains'] ); ?>"><p class="description"><?php esc_html_e( 'Optional comma-separated list. Leave empty to allow all verified Google accounts.', 'techskype-social-login' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<h2><?php esc_html_e( 'Shortcode', 'techskype-social-login' ); ?></h2>
			<p><code>[techskype_social_login]</code></p>
		</div>
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
			$data[] = array(
				'group_id'    => 'techskype-social-login',
				'group_label' => __( 'Social Login', 'techskype-social-login' ),
				'item_id'     => 'techskype-social-login-' . $user->ID,
				'data'        => array(
					array(
						'name'  => __( 'Google account identifier', 'techskype-social-login' ),
						'value' => get_user_meta( $user->ID, 'techskype_google_sub', true ),
					),
					array(
						'name'  => __( 'Google profile image', 'techskype-social-login' ),
						'value' => get_user_meta( $user->ID, 'techskype_google_picture', true ),
					),
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
			$removed = delete_user_meta( $user->ID, 'techskype_google_sub' );
			$removed = delete_user_meta( $user->ID, 'techskype_google_picture' ) || $removed;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
