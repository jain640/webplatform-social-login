=== TechSkype Social Login ===
Contributors: techskype
Tags: social login, google login, facebook login, linkedin login, woocommerce login
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure Google, Facebook, LinkedIn, Microsoft and Apple login for WordPress and WooCommerce.

== Description ==

TechSkype Social Login adds secure social sign-in buttons to WordPress.

Features:

* Verifies Google ID token signatures using Google's published public keys.
* Supports Facebook OAuth, LinkedIn OpenID Connect and Microsoft OpenID Connect.
* Supports Sign in with Apple, including dynamic ES256 client assertions.
* Validates token audience, issuer, expiry, email verification and nonce.
* Protects login requests with short-lived, HttpOnly state cookies.
* Encrypts provider secrets and Apple private keys at rest using WordPress authentication salts.
* Connects existing users by verified email.
* Optionally creates new WordPress users.
* Displays on WooCommerce login and registration forms.
* Includes `[techskype_social_login]` for custom pages.
* Supports local post-login redirects and optional email-domain restrictions.
* Integrates with WordPress personal-data export and erasure tools.

No Google Client Secret is stored or required. Other providers require their standard application credentials.

== External services ==

This plugin connects to external identity services only when the site owner enables them and a visitor chooses their login button.

* The browser downloads the Google Identity Services library from `https://accounts.google.com/gsi/client` when a login button is displayed. The site's OAuth Client ID and standard browser request information are sent to Google.
* After the visitor chooses a Google account, Google returns a signed identity token to the website. The token contains the account identifier and basic profile fields approved by the visitor.
* The server periodically downloads public signing keys from `https://www.googleapis.com/oauth2/v3/certs` to verify tokens. No visitor identity data is sent when public keys are downloaded.

This service is provided by Google under the [Google APIs Terms of Service](https://developers.google.com/terms/) and [Google Privacy Policy](https://policies.google.com/privacy).

For enabled OAuth providers, the visitor is sent to the provider's authorization page. The plugin sends the application Client ID, callback URL, requested basic-profile/email scopes, and a random state value. The provider sends an authorization code back to the website. The server exchanges that code and requests the visitor's identifier, name, verified email and optional profile image.

* Facebook endpoints use `facebook.com` and `graph.facebook.com`. [Meta Platform Terms](https://developers.facebook.com/terms/) and [Meta Privacy Policy](https://www.facebook.com/privacy/policy/).
* LinkedIn endpoints use `linkedin.com` and `api.linkedin.com`. [LinkedIn API Terms](https://www.linkedin.com/legal/l/api-terms-of-use) and [LinkedIn Privacy Policy](https://www.linkedin.com/legal/privacy-policy).
* Microsoft endpoints use `login.microsoftonline.com` and `graph.microsoft.com`. [Microsoft APIs Terms](https://learn.microsoft.com/legal/microsoft-apis/terms-of-use) and [Microsoft Privacy Statement](https://privacy.microsoft.com/privacystatement).
* Apple endpoints use `appleid.apple.com`. The plugin also downloads Apple's public signing keys to validate ID tokens. [Sign in with Apple terms](https://developer.apple.com/sign-in-with-apple/) and [Apple Privacy Policy](https://www.apple.com/legal/privacy/).

== Installation ==

1. Upload the `techskype-social-login` folder to `/wp-content/plugins/`.
2. Activate TechSkype Social Login.
3. In Google Cloud, create an OAuth 2.0 Client ID of type Web application.
4. Add your HTTPS website origin under Authorized JavaScript origins.
5. Open Settings > TechSkype Social Login and enter the Client ID.
6. Add `[techskype_social_login]` to any custom login page, or enable WooCommerce placement.

For another provider, create its web application, copy the exact callback URL displayed in the plugin settings, enter the credentials, and enable the provider.

The settings screen includes step-by-step examples for every provider, including the current site's exact origin, domain, callback URLs, required products and requested scopes.

== Frequently Asked Questions ==

= Is a Google Client Secret required? =

No. This plugin uses the Google Identity Services ID-token flow and validates signed tokens on the server.

= Does the plugin create users automatically? =

Only when account creation is enabled in the plugin and the provider email is verified.

= Can login be limited to an organization? =

Yes. Enter one or more comma-separated email domains in the settings.

== Privacy ==

When a visitor uses a social provider, that provider returns an account identifier and basic profile fields such as name, verified email address and profile image. The plugin stores the provider account identifier and profile image URL in WordPress user metadata. WordPress core stores the user's name and email as part of the user account.

The browser loads Google's Identity Services JavaScript from `accounts.google.com` when Google login is configured. Other providers are contacted only when their button is selected. Site owners should disclose enabled external services in their privacy policy and obtain any consent required in their jurisdiction.

Plugin metadata is available through WordPress personal-data export and erasure tools.

== Changelog ==

= 1.2.0 =

* Added site-specific configuration examples for every provider.
* Added provider console links, required products, scopes, domains and callback instructions.

= 1.1.0 =

* Added Facebook OAuth login.
* Added LinkedIn OpenID Connect login.
* Added Microsoft OpenID Connect login.
* Added Sign in with Apple.
* Added encrypted credential storage and provider-specific callback settings.

= 1.0.0 =

* Initial release.
