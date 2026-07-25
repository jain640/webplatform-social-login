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
					throw new Error( data.message || techSkypeSocialLogin.genericError );
				}
				return data;
			} );
		} );
	}

	function showError( containers, message ) {
		containers.forEach( function ( container ) {
			container.classList.remove( 'is-loading' );
			container.querySelector( '.techskype-social-login-status' ).textContent = message;
		} );
	}

	function initialize() {
		var containers = Array.from( document.querySelectorAll( '[data-techskype-google-login]' ) );
		if ( ! containers.length ) {
			return;
		}

		postJson( techSkypeSocialLogin.nonceUrl, {} ).then( function ( nonceResponse ) {
			google.accounts.id.initialize( {
				client_id: techSkypeSocialLogin.clientId,
				nonce: nonceResponse.nonce,
				callback: function ( googleResponse ) {
					containers.forEach( function ( container ) {
						container.classList.add( 'is-loading' );
						container.querySelector( '.techskype-social-login-status' ).textContent = '';
					} );
					postJson( techSkypeSocialLogin.loginUrl, {
						credential: googleResponse.credential,
						nonce: nonceResponse.nonce,
						redirect: techSkypeSocialLogin.redirectUrl
					} ).then( function ( loginResponse ) {
						window.location.assign( loginResponse.redirect );
					} ).catch( function ( error ) {
						showError( containers, error.message || techSkypeSocialLogin.genericError );
					} );
				}
			} );

			containers.forEach( function ( container ) {
				google.accounts.id.renderButton( container.querySelector( '.techskype-google-button' ), {
				type: 'standard',
				theme: techSkypeSocialLogin.buttonTheme,
				size: techSkypeSocialLogin.buttonSize,
				text: techSkypeSocialLogin.buttonText,
				shape: 'rectangular',
				logo_alignment: 'left',
				width: Math.min( 360, Math.max( 240, container.clientWidth || 240 ) )
				} );
			} );
		} ).catch( function () {
			showError( containers, techSkypeSocialLogin.networkError );
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
