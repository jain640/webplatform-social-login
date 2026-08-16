# WordPress 7.1 compatibility validation

WordPress.org requested plugin authors to validate compatibility with WordPress 7.1 before changing the `Tested up to` value in `readme.txt`.

## Test matrix

Run the checks on the latest WordPress 7.1 release candidate (and repeat on the final 7.1 release):

- PHP 8.0, 8.2, and 8.3 where available.
- WordPress 7.1 with the default theme.
- WordPress 7.1 with WooCommerce enabled for WooCommerce placement checks.

## Required checks

1. Install and activate WebPlatform Social Login on a clean WordPress site.
2. Confirm the settings page loads without PHP warnings, notices, or JavaScript errors.
3. Save settings and confirm provider secrets remain usable after reload.
4. Test Google login and nonce/state validation.
5. Test each configured OAuth provider: Facebook, LinkedIn, Microsoft, and Apple.
6. Confirm failed/expired/invalid OAuth callbacks fail safely.
7. Confirm standard WordPress login and registration placement renders once.
8. With WooCommerce enabled, confirm login and registration placement renders once.
9. Test `[webplatform_social_login]` on a normal page.
10. If Google One Tap is enabled, verify it appears only for logged-out users and does not duplicate the normal button.
11. Verify login redirects remain local and domain restrictions still work.
12. Run WordPress Plugin Check and resolve new errors before release.
13. Review the PHP error log and browser console after the smoke tests.

## Release gate

Only after the checks above pass:

1. Change `Tested up to: 7.0` to `Tested up to: 7.1` in `readme.txt`.
2. Do not change `Stable tag` or the plugin version for a metadata-only compatibility declaration unless code also changes.
3. Commit the readme update to the WordPress.org SVN `trunk` and the current stable tag as appropriate for the release workflow.
4. Verify the WordPress.org plugin page shows WordPress 7.1 compatibility.

Do not declare 7.1 compatibility solely because the plugin activates; complete the provider, callback, WordPress-login, and WooCommerce smoke tests first.
