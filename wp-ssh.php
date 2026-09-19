<?php
/**
 * Plugin Name: WP SSH (Remote Bridge)
 * Description: A private, self-hosted remote management bridge for this site. Lets you (or a trusted assistant) manage options, content, media, plugins and theme files over an authenticated HTTPS endpoint instead of raw SSH. Not intended for public distribution — each install generates its own unique secret key and is scoped to safe, whitelisted actions only.
 * Version: 1.2.0
 * Author: MOHD SAMAR
 * License: GPLv2 or later
 * Text Domain: wp-ssh
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_SSH_VERSION', '1.2.0' );
define( 'WP_SSH_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_SSH_OPTION', 'wp_ssh_settings' );

require_once WP_SSH_DIR . 'includes/class-wp-ssh-auth.php';
require_once WP_SSH_DIR . 'includes/class-wp-ssh-actions.php';
require_once WP_SSH_DIR . 'includes/class-wp-ssh-api.php';
require_once WP_SSH_DIR . 'includes/class-wp-ssh-admin.php';

/**
 * Activation: generate a unique secret key for THIS install and set safe defaults.
 * Nothing here is shared across sites — every activation gets its own random key.
 */
function wp_ssh_activate() {
	$settings = get_option( WP_SSH_OPTION );

	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	if ( empty( $settings['secret_key'] ) ) {
		$settings['secret_key'] = wp_ssh_generate_key();
	}

	if ( ! isset( $settings['enabled'] ) ) {
		$settings['enabled'] = false; // Off by default. You must opt in from the admin screen.
	}

	if ( ! isset( $settings['allow_write_queries'] ) ) {
		$settings['allow_write_queries'] = false;
	}

	if ( ! isset( $settings['read_only_mode'] ) ) {
		$settings['read_only_mode'] = false;
	}

	if ( ! isset( $settings['ip_allowlist'] ) ) {
		$settings['ip_allowlist'] = '';
	}

	if ( ! isset( $settings['site_label'] ) ) {
		$settings['site_label'] = '';
	}

	if ( ! isset( $settings['health_check_enabled'] ) ) {
		$settings['health_check_enabled'] = false; // Off by default, opt in from the admin screen.
	}

	if ( ! isset( $settings['health_check_email'] ) ) {
		$settings['health_check_email'] = '';
	}

	if ( ! isset( $settings['log'] ) ) {
		$settings['log'] = array();
	}

	update_option( WP_SSH_OPTION, $settings );

	if ( ! wp_next_scheduled( 'wp_ssh_health_check' ) ) {
		wp_schedule_event( time(), 'hourly', 'wp_ssh_health_check' );
	}
}
register_activation_hook( __FILE__, 'wp_ssh_activate' );

function wp_ssh_deactivate() {
	wp_clear_scheduled_hook( 'wp_ssh_health_check' );
}
register_deactivation_hook( __FILE__, 'wp_ssh_deactivate' );

add_action( 'wp_ssh_health_check', array( 'WP_SSH_Actions', 'run_health_check' ) );

function wp_ssh_generate_key() {
	return wp_generate_password( 64, false, false );
}

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'wp-ssh', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );
