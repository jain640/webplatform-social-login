<?php
/**
 * OAuth and OpenID Connect provider integrations.
 *
 * @package TechSkypeSocialLogin
 */

defined( 'ABSPATH' ) || exit;

final class TechSkype_OAuth_Providers {
	const STATE_COOKIE = 'techskype_social_oauth_state';

	/**
	 * Main plugin.
	 *
	 * @var TechSkype_Social_Login
	 */
	private $plugin;

	/**
	 * Provider definitions.
	 *
	 * @var array<string, array<string, string>>
	 */
	private $providers;

	/**
	 * Constructor.
	 *
	 * @param TechSkype_Social_Login $plugin Main plugin.
	 */
	public function __construct( $plugin ) {
		$this->plugin    = $plugin;
		$this->providers = array(
			'facebook'  => array(
				'label'         => 'Facebook',
				'authorize_url' => 'https://www.facebook.com/dialog/oauth',
				'token_url'     => 'https://graph.facebook.com/oauth/access_token',
				'scope'         => 'email,public_profile',
			),
			'linkedin'  => array(
				'label'         => 'LinkedIn',
				'authorize_url' => 'https://www.linkedin.com/oauth/v2/authorization',
				'token_url'     => 'https://www.linkedin.com/oauth/v2/accessToken',
				'scope'         => 'openid profile email',
			),
			'microsoft' => array(
				'label'         => 'Microsoft',
				'authorize_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
				'token_url'     => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
				'scope'         => 'openid profile email User.Read',
			),
			'apple'     => array(
				'label'         => 'Apple',
				'authorize_url' => 'https://appleid.apple.com/auth/authorize',
				'token_url'     => 'https://appleid.apple.com/auth/token',
				'scope'         => 'name email',
			),
		);
	}

	/**
	 * Provider setting defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'facebook_enabled'  => 0,
			'facebook_id'       => '',
			'facebook_secret'   => '',
			'linkedin_enabled'  => 0,
			'linkedin_id'       => '',
			'linkedin_secret'   => '',
			'microsoft_enabled' => 0,
			'microsoft_id'      => '',
			'microsoft_secret'  => '',
			'apple_enabled'     => 0,
			'apple_id'          => '',
			'apple_team_id'     => '',
			'apple_key_id'      => '',
			'apple_private_key' => '',
		);
	}

	/**
	 * Register provider routes.
	 */
	public function register_routes() {
		register_rest_route(
			TechSkype_Social_Login::REST_NS,
			'/authorize/(?P<provider>facebook|linkedin|microsoft|apple)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'authorize' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			TechSkype_Social_Login::REST_NS,
			'/callback/(?P<provider>facebook|linkedin|microsoft|apple)',
			array(
				'methods'             => WP_REST_Server::ALLMETHODS,
				'callback'            => array( $this, 'callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Render enabled provider buttons.
	 *
	 * @param string $redirect Redirect URL.
	 * @return string
	 */
	public function buttons_html( $redirect ) {
		$settings = $this->plugin->get_settings();
		$html     = '';
		foreach ( $this->providers as $provider => $definition ) {
			if ( empty( $settings[ $provider . '_enabled' ] ) || empty( $settings[ $provider . '_id' ] ) ) {
				continue;
			}
			if ( 'apple' === $provider && ( empty( $settings['apple_team_id'] ) || empty( $settings['apple_key_id'] ) || empty( $settings['apple_private_key'] ) ) ) {
				continue;
			}
			if ( 'apple' !== $provider && empty( $settings[ $provider . '_secret' ] ) ) {
				continue;
			}

			$url = add_query_arg(
				array( 'redirect' => $redirect ),
				rest_url( TechSkype_Social_Login::REST_NS . '/authorize/' . $provider )
			);
			$html .= sprintf(
				'<a class="techskype-provider-button techskype-provider-%1$s" href="%2$s"><span aria-hidden="true">%3$s</span>%4$s</a>',
				esc_attr( $provider ),
				esc_url( $url ),
				esc_html( strtoupper( substr( $definition['label'], 0, 1 ) ) ),
				esc_html( sprintf( __( 'Continue with %s', 'techskype-social-login' ), $definition['label'] ) )
			);
		}
		return $html;
	}

	/**
	 * Start authorization.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function authorize( WP_REST_Request $request ) {
		if ( is_user_logged_in() ) {
			return new WP_Error( 'already_logged_in', __( 'You are already signed in.', 'techskype-social-login' ), array( 'status' => 400 ) );
		}

		$provider = sanitize_key( $request['provider'] );
		$settings = $this->plugin->get_settings();
		if ( ! $this->provider_ready( $provider, $settings ) ) {
			return new WP_Error( 'provider_unavailable', __( 'This login provider is not configured.', 'techskype-social-login' ), array( 'status' => 400 ) );
		}

		$state    = wp_generate_password( 48, false, false );
		$redirect = $this->plugin->safe_local_redirect( (string) $request->get_param( 'redirect' ) );
		set_transient(
			'techskype_oauth_' . hash( 'sha256', $state ),
			array(
				'provider' => $provider,
				'redirect' => $redirect ?: home_url( '/' ),
			),
			10 * MINUTE_IN_SECONDS
		);
		$this->set_state_cookie( $state, time() + 10 * MINUTE_IN_SECONDS );

		$args = array(
			'client_id'     => $settings[ $provider . '_id' ],
			'redirect_uri'  => $this->callback_url( $provider ),
			'response_type' => 'code',
			'scope'         => $this->providers[ $provider ]['scope'],
			'state'         => $state,
		);
		if ( 'apple' === $provider ) {
			$args['response_mode'] = 'form_post';
		}

		return new WP_REST_Response( null, 302, array( 'Location' => add_query_arg( $args, $this->providers[ $provider ]['authorize_url'] ) ) );
	}

	/**
	 * Complete an OAuth callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function callback( WP_REST_Request $request ) {
		$provider = sanitize_key( $request['provider'] );
		$state    = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$cookie   = isset( $_COOKIE[ self::STATE_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::STATE_COOKIE ] ) ) : '';
		$stored   = $state ? get_transient( 'techskype_oauth_' . hash( 'sha256', $state ) ) : false;

		if ( ! $state || ! $cookie || ! hash_equals( $cookie, $state ) || ! is_array( $stored ) || $provider !== ( $stored['provider'] ?? '' ) ) {
			return new WP_Error( 'invalid_oauth_state', __( 'The social login request expired or was invalid.', 'techskype-social-login' ), array( 'status' => 403 ) );
		}
		delete_transient( 'techskype_oauth_' . hash( 'sha256', $state ) );
		$this->set_state_cookie( '', time() - HOUR_IN_SECONDS );

		if ( $request->get_param( 'error' ) ) {
			return new WP_Error( 'provider_denied', sanitize_text_field( (string) $request->get_param( 'error_description' ) ) ?: __( 'Login was cancelled.', 'techskype-social-login' ), array( 'status' => 400 ) );
		}
		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		if ( ! $code ) {
			return new WP_Error( 'missing_code', __( 'The provider did not return an authorization code.', 'techskype-social-login' ), array( 'status' => 400 ) );
		}

		$identity = $this->fetch_identity( $provider, $code, $request );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}

		$result = $this->plugin->complete_social_login( $provider, $identity, $stored['redirect'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( null, 302, array( 'Location' => $result ) );
	}

	/**
	 * Fetch a verified provider identity.
	 *
	 * @param string          $provider Provider.
	 * @param string          $code Authorization code.
	 * @param WP_REST_Request $request Callback request.
	 * @return array<string, mixed>|WP_Error
	 */
	private function fetch_identity( $provider, $code, WP_REST_Request $request ) {
		$settings      = $this->plugin->get_settings();
		$client_secret = 'apple' === $provider ? $this->apple_client_secret( $settings ) : $settings[ $provider . '_secret' ];
		if ( is_wp_error( $client_secret ) ) {
			return $client_secret;
		}

		$token_response = wp_safe_remote_post(
			$this->providers[ $provider ]['token_url'],
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => $this->callback_url( $provider ),
					'client_id'     => $settings[ $provider . '_id' ],
					'client_secret' => $client_secret,
				),
			)
		);
		$tokens = $this->json_response( $token_response );
		if ( is_wp_error( $tokens ) || empty( $tokens['access_token'] ) && empty( $tokens['id_token'] ) ) {
			return new WP_Error( 'token_exchange_failed', __( 'The provider could not complete login.', 'techskype-social-login' ), array( 'status' => 502 ) );
		}

		if ( 'facebook' === $provider ) {
			return $this->facebook_identity( $tokens['access_token'], $settings );
		}
		if ( 'apple' === $provider ) {
			return $this->apple_identity( $tokens['id_token'], $settings, $request );
		}

		$userinfo_url = 'linkedin' === $provider ? 'https://api.linkedin.com/v2/userinfo' : 'https://graph.microsoft.com/oidc/userinfo';
		$response     = wp_safe_remote_get(
			$userinfo_url,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $tokens['access_token'] ),
			)
		);
		$profile = $this->json_response( $response );
		if ( is_wp_error( $profile ) || empty( $profile['sub'] ) || empty( $profile['email'] ) ) {
			return new WP_Error( 'profile_unavailable', __( 'The provider did not return a usable email address.', 'techskype-social-login' ), array( 'status' => 403 ) );
		}

		return array(
			'id'             => sanitize_text_field( $profile['sub'] ),
			'email'          => sanitize_email( $profile['email'] ),
			'email_verified' => ! empty( $profile['email_verified'] ) || 'microsoft' === $provider,
			'name'           => sanitize_text_field( $profile['name'] ?? '' ),
			'first_name'     => sanitize_text_field( $profile['given_name'] ?? '' ),
			'last_name'      => sanitize_text_field( $profile['family_name'] ?? '' ),
			'picture'        => esc_url_raw( $profile['picture'] ?? '' ),
		);
	}

	/**
	 * Validate Facebook access token and profile.
	 *
	 * @param string               $access_token Access token.
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>|WP_Error
	 */
	private function facebook_identity( $access_token, $settings ) {
		$app_token = rawurlencode( $settings['facebook_id'] . '|' . $settings['facebook_secret'] );
		$debug     = $this->json_response(
			wp_safe_remote_get(
				'https://graph.facebook.com/debug_token?input_token=' . rawurlencode( $access_token ) . '&access_token=' . $app_token,
				array( 'timeout' => 15 )
			)
		);
		if ( is_wp_error( $debug ) || empty( $debug['data']['is_valid'] ) || (string) $debug['data']['app_id'] !== (string) $settings['facebook_id'] ) {
			return new WP_Error( 'invalid_facebook_token', __( 'Facebook could not verify this login.', 'techskype-social-login' ), array( 'status' => 403 ) );
		}

		$proof   = hash_hmac( 'sha256', $access_token, $settings['facebook_secret'] );
		$profile = $this->json_response(
			wp_safe_remote_get(
				'https://graph.facebook.com/me?fields=id,name,first_name,last_name,email,picture.type(large)&access_token=' . rawurlencode( $access_token ) . '&appsecret_proof=' . $proof,
				array( 'timeout' => 15 )
			)
		);
		if ( is_wp_error( $profile ) || empty( $profile['id'] ) || empty( $profile['email'] ) ) {
			return new WP_Error( 'facebook_email_missing', __( 'Facebook did not provide an email address.', 'techskype-social-login' ), array( 'status' => 403 ) );
		}
		return array(
			'id'             => sanitize_text_field( $profile['id'] ),
			'email'          => sanitize_email( $profile['email'] ),
			'email_verified' => true,
			'name'           => sanitize_text_field( $profile['name'] ?? '' ),
			'first_name'     => sanitize_text_field( $profile['first_name'] ?? '' ),
			'last_name'      => sanitize_text_field( $profile['last_name'] ?? '' ),
			'picture'        => esc_url_raw( $profile['picture']['data']['url'] ?? '' ),
		);
	}

	/**
	 * Validate Apple ID token and extract identity.
	 *
	 * @param string               $id_token ID token.
	 * @param array<string, mixed> $settings Settings.
	 * @param WP_REST_Request      $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	private function apple_identity( $id_token, $settings, WP_REST_Request $request ) {
		$claims = $this->verify_rs256_jwt( $id_token, 'https://appleid.apple.com/auth/keys', 'https://appleid.apple.com', $settings['apple_id'] );
		if ( is_wp_error( $claims ) || empty( $claims['sub'] ) || empty( $claims['email'] ) ) {
			return new WP_Error( 'invalid_apple_token', __( 'Apple could not verify this login.', 'techskype-social-login' ), array( 'status' => 403 ) );
		}

		$user_data = json_decode( (string) $request->get_param( 'user' ), true );
		$first     = sanitize_text_field( $user_data['name']['firstName'] ?? '' );
		$last      = sanitize_text_field( $user_data['name']['lastName'] ?? '' );
		return array(
			'id'             => sanitize_text_field( $claims['sub'] ),
			'email'          => sanitize_email( $claims['email'] ),
			'email_verified' => in_array( $claims['email_verified'] ?? false, array( true, 'true', 1, '1' ), true ),
			'name'           => trim( $first . ' ' . $last ),
			'first_name'     => $first,
			'last_name'      => $last,
			'picture'        => '',
		);
	}

	/**
	 * Generate Apple's ES256 client assertion.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return string|WP_Error
	 */
	private function apple_client_secret( $settings ) {
		$header  = array( 'alg' => 'ES256', 'kid' => $settings['apple_key_id'] );
		$now     = time();
		$payload = array(
			'iss' => $settings['apple_team_id'],
			'iat' => $now,
			'exp' => $now + 5 * MINUTE_IN_SECONDS,
			'aud' => 'https://appleid.apple.com',
			'sub' => $settings['apple_id'],
		);
		$input = $this->base64url_encode( wp_json_encode( $header ) ) . '.' . $this->base64url_encode( wp_json_encode( $payload ) );
		$key   = openssl_pkey_get_private( $settings['apple_private_key'] );
		if ( ! $key || ! openssl_sign( $input, $der_signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'invalid_apple_key', __( 'The configured Apple private key is invalid.', 'techskype-social-login' ), array( 'status' => 500 ) );
		}
		$raw_signature = $this->ecdsa_der_to_raw( $der_signature, 32 );
		if ( false === $raw_signature ) {
			return new WP_Error( 'invalid_apple_signature', __( 'Apple client authentication could not be generated.', 'techskype-social-login' ), array( 'status' => 500 ) );
		}
		return $input . '.' . $this->base64url_encode( $raw_signature );
	}

	/**
	 * Verify a remote-key RS256 JWT.
	 *
	 * @param string $token Token.
	 * @param string $jwks_url JWKS URL.
	 * @param string $issuer Expected issuer.
	 * @param string $audience Expected audience.
	 * @return array<string, mixed>|WP_Error
	 */
	private function verify_rs256_jwt( $token, $jwks_url, $issuer, $audience ) {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) || ! function_exists( 'openssl_verify' ) ) {
			return new WP_Error( 'invalid_jwt' );
		}
		$header = json_decode( $this->base64url_decode( $parts[0] ), true );
		$claims = json_decode( $this->base64url_decode( $parts[1] ), true );
		if ( ! is_array( $header ) || ! is_array( $claims ) || 'RS256' !== ( $header['alg'] ?? '' ) || empty( $header['kid'] ) ) {
			return new WP_Error( 'invalid_jwt' );
		}
		$keys = $this->remote_jwks( $jwks_url );
		if ( is_wp_error( $keys ) || empty( $keys[ $header['kid'] ] ) ) {
			return new WP_Error( 'invalid_jwt_key' );
		}
		$signature = $this->base64url_decode( $parts[2] );
		$audiences = is_array( $claims['aud'] ?? '' ) ? $claims['aud'] : array( $claims['aud'] ?? '' );
		if (
			false === $signature ||
			1 !== openssl_verify( $parts[0] . '.' . $parts[1], $signature, $keys[ $header['kid'] ], OPENSSL_ALGO_SHA256 ) ||
			$issuer !== ( $claims['iss'] ?? '' ) ||
			! in_array( $audience, $audiences, true ) ||
			empty( $claims['exp'] ) ||
			(int) $claims['exp'] < time() - 60
		) {
			return new WP_Error( 'invalid_jwt' );
		}
		return $claims;
	}

	/**
	 * Retrieve remote JWKs as PEM keys.
	 *
	 * @param string $url URL.
	 * @return array<string, string>|WP_Error
	 */
	private function remote_jwks( $url ) {
		$cache_key = 'techskype_jwks_' . md5( $url );
		$keys      = get_transient( $cache_key );
		if ( is_array( $keys ) && $keys ) {
			return $keys;
		}
		$response = wp_safe_remote_get( $url, array( 'timeout' => 10, 'redirection' => 2 ) );
		$data     = $this->json_response( $response );
		if ( is_wp_error( $data ) || empty( $data['keys'] ) ) {
			return new WP_Error( 'jwks_unavailable' );
		}
		$keys = array();
		foreach ( $data['keys'] as $jwk ) {
			if ( empty( $jwk['kid'] ) || empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
				continue;
			}
			$pem = $this->jwk_to_pem( $jwk );
			if ( $pem ) {
				$keys[ $jwk['kid'] ] = $pem;
			}
		}
		set_transient( $cache_key, $keys, HOUR_IN_SECONDS );
		return $keys ?: new WP_Error( 'jwks_unavailable' );
	}

	/**
	 * Parse a successful JSON HTTP response.
	 *
	 * @param array<string, mixed>|WP_Error $response Response.
	 * @return array<string, mixed>|WP_Error
	 */
	private function json_response( $response ) {
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 ) {
			return new WP_Error( 'remote_request_failed' );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) ? $data : new WP_Error( 'invalid_remote_response' );
	}

	/**
	 * Check configuration.
	 *
	 * @param string               $provider Provider.
	 * @param array<string, mixed> $settings Settings.
	 * @return bool
	 */
	private function provider_ready( $provider, $settings ) {
		if ( empty( $this->providers[ $provider ] ) || empty( $settings[ $provider . '_enabled' ] ) || empty( $settings[ $provider . '_id' ] ) ) {
			return false;
		}
		if ( 'apple' === $provider ) {
			return ! empty( $settings['apple_team_id'] ) && ! empty( $settings['apple_key_id'] ) && ! empty( $settings['apple_private_key'] );
		}
		return ! empty( $settings[ $provider . '_secret' ] );
	}

	/**
	 * Callback URL.
	 *
	 * @param string $provider Provider.
	 * @return string
	 */
	public function callback_url( $provider ) {
		return rest_url( TechSkype_Social_Login::REST_NS . '/callback/' . $provider );
	}

	/**
	 * Set state cookie.
	 *
	 * @param string $value Value.
	 * @param int    $expires Expiry.
	 */
	private function set_state_cookie( $value, $expires ) {
		setcookie(
			self::STATE_COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => is_ssl() ? 'None' : 'Lax',
			)
		);
	}

	/**
	 * Base64url encode.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $value Value.
	 * @return string|false
	 */
	private function base64url_decode( $value ) {
		$value .= str_repeat( '=', ( 4 - strlen( $value ) % 4 ) % 4 );
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}

	/**
	 * Convert ECDSA DER signature to JWT raw signature.
	 *
	 * @param string $der DER signature.
	 * @param int    $part_length R/S length.
	 * @return string|false
	 */
	private function ecdsa_der_to_raw( $der, $part_length ) {
		$offset = 0;
		if ( "\x30" !== ( $der[ $offset++ ] ?? '' ) ) {
			return false;
		}
		$this->read_der_length( $der, $offset );
		if ( "\x02" !== ( $der[ $offset++ ] ?? '' ) ) {
			return false;
		}
		$r_length = $this->read_der_length( $der, $offset );
		$r        = substr( $der, $offset, $r_length );
		$offset  += $r_length;
		if ( "\x02" !== ( $der[ $offset++ ] ?? '' ) ) {
			return false;
		}
		$s_length = $this->read_der_length( $der, $offset );
		$s        = substr( $der, $offset, $s_length );
		$r        = str_pad( ltrim( $r, "\x00" ), $part_length, "\x00", STR_PAD_LEFT );
		$s        = str_pad( ltrim( $s, "\x00" ), $part_length, "\x00", STR_PAD_LEFT );
		return strlen( $r ) === $part_length && strlen( $s ) === $part_length ? $r . $s : false;
	}

	/**
	 * Read DER length.
	 *
	 * @param string $der DER data.
	 * @param int    $offset Offset passed by reference.
	 * @return int
	 */
	private function read_der_length( $der, &$offset ) {
		$length = ord( $der[ $offset++ ] );
		if ( $length < 0x80 ) {
			return $length;
		}
		$bytes  = $length & 0x7f;
		$length = 0;
		while ( $bytes-- > 0 ) {
			$length = ( $length << 8 ) | ord( $der[ $offset++ ] );
		}
		return $length;
	}

	/**
	 * Convert RSA JWK to PEM.
	 *
	 * @param array<string, string> $jwk JWK.
	 * @return string|false
	 */
	private function jwk_to_pem( $jwk ) {
		$n = $this->base64url_decode( $jwk['n'] );
		$e = $this->base64url_decode( $jwk['e'] );
		if ( false === $n || false === $e ) {
			return false;
		}
		$rsa = $this->asn1_sequence( $this->asn1_integer( $n ) . $this->asn1_integer( $e ) );
		$oid = hex2bin( '300d06092a864886f70d0101010500' );
		$key = $this->asn1_sequence( $oid . "\x03" . $this->asn1_length( strlen( $rsa ) + 1 ) . "\x00" . $rsa );
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $key ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/**
	 * ASN.1 integer.
	 *
	 * @param string $value Binary.
	 * @return string
	 */
	private function asn1_integer( $value ) {
		$value = ltrim( $value, "\x00" ) ?: "\x00";
		$value = ord( $value[0] ) > 0x7f ? "\x00" . $value : $value;
		return "\x02" . $this->asn1_length( strlen( $value ) ) . $value;
	}

	/**
	 * ASN.1 sequence.
	 *
	 * @param string $value Binary.
	 * @return string
	 */
	private function asn1_sequence( $value ) {
		return "\x30" . $this->asn1_length( strlen( $value ) ) . $value;
	}

	/**
	 * ASN.1 length.
	 *
	 * @param int $length Length.
	 * @return string
	 */
	private function asn1_length( $length ) {
		if ( $length < 128 ) {
			return chr( $length );
		}
		$value = '';
		while ( $length ) {
			$value  = chr( $length & 0xff ) . $value;
			$length = $length >> 8;
		}
		return chr( 0x80 | strlen( $value ) ) . $value;
	}
}
