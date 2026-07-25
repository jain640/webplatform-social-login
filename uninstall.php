<?php
/**
 * Uninstall cleanup.
 *
 * User connection metadata is intentionally retained so reinstalling does not
 * silently disconnect accounts. It can be erased with WordPress privacy tools.
 *
 * @package TechSkypeSocialLogin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'techskype_social_login_settings' );
delete_option( 'techskype_social_login_storage_version' );
delete_transient( 'techskype_google_signing_keys' );
