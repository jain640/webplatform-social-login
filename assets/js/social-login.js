( function () {
	'use strict';

	function postJson( url, body ) {
		return fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw new Error( data.message || webPlatformSocialLogin.genericError );
				}
				return data;
			} );
		} );
	}

	function showError( containers, message ) {
		containers.forEach( function ( container ) {
			container.classList.remove( 'is-loading' );
			container.querySelector( '.webplatform-social-login-status' ).textContent = message;
		} );
	}

	function initialize() {
		var containers = Array.from( document.querySelectorAll( '[data-webplatform-google-login]' ) );
		if ( ! containers.length && ! webPlatformSocialLogin.oneTap ) {
			return;
		}

		postJson( webPlatformSocialLogin.nonceUrl, {} ).then( function ( nonceResponse ) {
			google.accounts.id.initialize( {
				client_id: webPlatformSocialLogin.clientId,
				nonce: nonceResponse.nonce,
				context: 'signin',
				itp_support: true,
				callback: function ( googleResponse ) {
					containers.forEach( function ( container ) {
						container.classList.add( 'is-loading' );
						container.querySelector( '.webplatform-social-login-status' ).textContent = '';
					} );
					postJson( webPlatformSocialLogin.loginUrl, {
						credential: googleResponse.credential,
						nonce: nonceResponse.nonce,
						redirect: webPlatformSocialLogin.redirectUrl
					} ).then( function ( loginResponse ) {
						window.location.assign( loginResponse.redirect );
					} ).catch( function ( error ) {
						showError( containers, error.message || webPlatformSocialLogin.genericError );
					} );
				}
			} );

			containers.forEach( function ( container ) {
				google.accounts.id.renderButton( container.querySelector( '.webplatform-google-button' ), {
				type: 'standard',
				theme: webPlatformSocialLogin.buttonTheme,
				size: webPlatformSocialLogin.buttonSize,
				text: webPlatformSocialLogin.buttonText,
				shape: 'rectangular',
				logo_alignment: 'left',
				width: Math.min( 360, Math.max( 240, container.clientWidth || 240 ) )
				} );
			} );

			if ( webPlatformSocialLogin.oneTap ) {
				google.accounts.id.prompt();
			}
		} ).catch( function () {
			showError( containers, webPlatformSocialLogin.networkError );
		} );
	}

	function boot() {
		if ( ! window.google || ! google.accounts || ! google.accounts.id ) {
			window.setTimeout( boot, 100 );
			return;
		}
		initialize();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
