<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_SSH_Auth {

	const LOCKOUT_THRESHOLD = 5;
	const LOCKOUT_SECONDS   = 900; // 15 minutes

	/**
	 * Validate an incoming request. Returns true, or a WP_Error explaining why not.
	 */
	public static function validate( WP_REST_Request $request ) {
		$settings = get_option( WP_SSH_OPTION, array() );

		if ( empty( $settings['enabled'] ) ) {
			return new WP_Error( 'wp_ssh_disabled', 'WP SSH bridge is disabled on this site. Enable it from Tools > WP SSH.', array( 'status' => 403 ) );
		}

		$ip = self::get_client_ip();

		if ( self::is_locked_out( $ip ) ) {
			return new WP_Error( 'wp_ssh_locked_out', 'Too many failed attempts. Try again later.', array( 'status' => 429 ) );
		}

		if ( ! empty( $settings['ip_allowlist'] ) ) {
			$allowed = array_filter( array_map( 'trim', explode( "\n", $settings['ip_allowlist'] ) ) );
			if ( ! empty( $allowed ) && ! in_array( $ip, $allowed, true ) ) {
				self::register_failure( $ip );
				return new WP_Error( 'wp_ssh_ip_blocked', 'This IP is not on the allowlist.', array( 'status' => 403 ) );
			}
		}

		$provided_key = $request->get_header( 'x-wpssh-key' );

		if ( empty( $provided_key ) || empty( $settings['secret_key'] ) || ! hash_equals( $settings['secret_key'], $provided_key ) ) {
			self::register_failure( $ip );
			return new WP_Error( 'wp_ssh_bad_key', 'Invalid or missing secret key.', array( 'status' => 401 ) );
		}

		self::clear_failures( $ip );

		return true;
	}

	public static function get_client_ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
	}

	protected static function transient_key( $ip ) {
		return 'wp_ssh_fail_' . md5( $ip );
	}

	public static function is_locked_out( $ip ) {
		$count = (int) get_transient( self::transient_key( $ip ) );
		return $count >= self::LOCKOUT_THRESHOLD;
	}

	public static function register_failure( $ip ) {
		$key   = self::transient_key( $ip );
		$count = (int) get_transient( $key );
		$count++;
		set_transient( $key, $count, self::LOCKOUT_SECONDS );
	}

	public static function clear_failures( $ip ) {
		delete_transient( self::transient_key( $ip ) );
	}

	/**
	 * Append an entry to the (capped) action log stored in options.
	 */
	public static function log_action( $action, $summary ) {
		$settings = get_option( WP_SSH_OPTION, array() );
		$log      = isset( $settings['log'] ) && is_array( $settings['log'] ) ? $settings['log'] : array();

		array_unshift( $log, array(
			'time'    => current_time( 'mysql' ),
			'ip'      => self::get_client_ip(),
			'action'  => $action,
			'summary' => $summary,
		) );

		$log = array_slice( $log, 0, 100 );

		$settings['log'] = $log;
		update_option( WP_SSH_OPTION, $settings );
	}
}
