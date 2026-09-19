<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_SSH_API {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route( 'wp-ssh/v1', '/execute', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_execute' ),
			'permission_callback' => '__return_true', // Auth is handled inside via the secret key, not WP cookies.
		) );

		register_rest_route( 'wp-ssh/v1', '/ping', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_ping' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function handle_ping( WP_REST_Request $request ) {
		$settings = get_option( WP_SSH_OPTION, array() );
		return array(
			'plugin'  => 'wp-ssh',
			'version' => WP_SSH_VERSION,
			'site'    => get_bloginfo( 'name' ),
			'label'   => ! empty( $settings['site_label'] ) ? $settings['site_label'] : get_bloginfo( 'name' ),
		);
	}

	public static function handle_execute( WP_REST_Request $request ) {
		$auth = WP_SSH_Auth::validate( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$body   = $request->get_json_params();
		$action = isset( $body['action'] ) ? sanitize_key( $body['action'] ) : '';
		$params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();

		if ( empty( $action ) ) {
			return new WP_Error( 'missing_action', 'Missing "action".', array( 'status' => 400 ) );
		}

		$result = WP_SSH_Actions::dispatch( $action, $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true, 'data' => $result );
	}
}

WP_SSH_API::init();
