=== TechSkype Social Login ===
Contributors: techskype
Tags: social login, google login, woocommerce login, oauth, sign in with google
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure Sign in with Google integration for WordPress and WooCommerce.

== Description ==

TechSkype Social Login adds the current Google Identity Services login button to WordPress.

Features:

* Verifies Google ID token signatures using Google's published public keys.
* Validates token audience, issuer, expiry, email verification and nonce.
* Protects login requests with a short-lived, HttpOnly cookie.
* Connects existing users by verified email.
* Optionally creates new WordPress users.
* Displays on WooCommerce login and registration forms.
* Includes `[techskype_social_login]` for custom pages.
* Supports local post-login redirects and optional email-domain restrictions.
* Integrates with WordPress personal-data export and erasure tools.

No Google Client Secret is stored or required.

== External services ==

This plugin connects to Google Identity Services to authenticate users.

* The browser downloads the Google Identity Services library from `https://accounts.google.com/gsi/client` when a login button is displayed. The site's OAuth Client ID and standard browser request information are sent to Google.
* After the visitor chooses a Google account, Google returns a signed identity token to the website. The token contains the account identifier and basic profile fields approved by the visitor.
* The server periodically downloads public signing keys from `https://www.googleapis.com/oauth2/v3/certs` to verify tokens. No visitor identity data is sent when public keys are downloaded.

This service is provided by Google under the [Google APIs Terms of Service](https://developers.google.com/terms/) and [Google Privacy Policy](https://policies.google.com/privacy).

== Installation ==

1. Upload the `techskype-social-login` folder to `/wp-content/plugins/`.
2. Activate TechSkype Social Login.
3. In Google Cloud, create an OAuth 2.0 Client ID of type Web application.
4. Add your HTTPS website origin under Authorized JavaScript origins.
5. Open Settings > TechSkype Social Login and enter the Client ID.
6. Add `[techskype_social_login]` to any custom login page, or enable WooCommerce placement.

== Frequently Asked Questions ==

= Is a Google Client Secret required? =

No. This plugin uses the Google Identity Services ID-token flow and validates signed tokens on the server.

= Does the plugin create users automatically? =

Only when account creation is enabled in the plugin and the Google email is verified.

= Can login be limited to an organization? =

Yes. Enter one or more comma-separated email domains in the settings.

== Privacy ==

When a visitor uses Sign in with Google, Google provides a signed identity token containing the Google account identifier and basic profile fields such as name, verified email address and profile image. The plugin stores the Google account identifier and profile image URL in WordPress user metadata. WordPress core stores the user's name and email as part of the user account.

The browser loads Google's Identity Services JavaScript from `accounts.google.com`, subject to Google's privacy policy. Site owners should disclose this external service in their privacy policy and obtain any consent required in their jurisdiction.

Plugin metadata is available through WordPress personal-data export and erasure tools.

== Changelog ==

= 1.0.0 =

* Initial release.
