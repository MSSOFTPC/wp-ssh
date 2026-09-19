<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whitelisted, scoped actions only. No arbitrary code execution.
 * Every method receives the request params array and returns array|WP_Error.
 */
class WP_SSH_Actions {

	/** Map of action name => method name. This IS the whitelist. */
	public static function map() {
		return array(
			'site_info'              => 'site_info',
			'diagnostics'            => 'diagnostics',
			'option_get'             => 'option_get',
			'option_update'          => 'option_update',
			'post_list'              => 'post_list',
			'post_get'               => 'post_get',
			'post_create'            => 'post_create',
			'post_update'            => 'post_update',
			'post_delete'            => 'post_delete',
			'term_list'              => 'term_list',
			'term_create'            => 'term_create',
			'media_upload'           => 'media_upload',
			'media_list'             => 'media_list',
			'plugin_list'            => 'plugin_list',
			'plugin_activate'        => 'plugin_activate',
			'plugin_deactivate'      => 'plugin_deactivate',
			'theme_list'             => 'theme_list',
			'file_read'              => 'file_read',
			'file_write'             => 'file_write',
			'file_list'              => 'file_list',
			'db_query'               => 'db_query',
			'cache_clear'            => 'cache_clear',
			'search_replace'         => 'search_replace',
			'product_attribute_set'  => 'product_attribute_set',
			'menu_item_add'          => 'menu_item_add',
			'menu_list'              => 'menu_list',
			'widget_add'             => 'widget_add',
			'elementor_data_get'     => 'elementor_data_get',
			'elementor_data_set'     => 'elementor_data_set',
			'order_list'             => 'order_list',
			'order_get'              => 'order_get',
			'order_update_status'    => 'order_update_status',
			'file_restore'           => 'file_restore',
			'template_export'        => 'template_export',
			'template_import'        => 'template_import',
			'cron_list'              => 'cron_list',
			'cron_run'               => 'cron_run',
			'health_check_status'    => 'health_check_status',
			'health_check_run'       => 'health_check_run',
		);
	}

	/** Actions that change state. Blocked entirely when read_only_mode is on. */
	protected static function write_actions() {
		return array(
			'option_update', 'post_create', 'post_update', 'post_delete', 'term_create',
			'media_upload', 'plugin_activate', 'plugin_deactivate', 'file_write', 'db_query',
			'cache_clear', 'search_replace', 'product_attribute_set', 'menu_item_add', 'widget_add',
			'elementor_data_set', 'order_update_status', 'file_restore', 'template_import',
			'cron_run', 'health_check_run',
		);
	}

	public static function dispatch( $action, $params ) {
		$map = self::map();

		if ( ! isset( $map[ $action ] ) ) {
			return new WP_Error( 'wp_ssh_unknown_action', 'Unknown or disallowed action: ' . $action, array( 'status' => 400 ) );
		}

		if ( in_array( $action, self::write_actions(), true ) ) {
			$settings = get_option( WP_SSH_OPTION, array() );
			if ( ! empty( $settings['read_only_mode'] ) ) {
				return new WP_Error( 'wp_ssh_read_only', 'This site is in read-only mode. Turn it off in Tools > WP SSH to allow write actions.', array( 'status' => 403 ) );
			}
		}

		$method = $map[ $action ];

		if ( ! method_exists( __CLASS__, $method ) ) {
			return new WP_Error( 'wp_ssh_not_implemented', 'Action not implemented.', array( 'status' => 500 ) );
		}

		return self::$method( $params );
	}

	/* ---------------------------------------------------------------- */

	public static function site_info( $params ) {
		$settings = get_option( WP_SSH_OPTION, array() );
		return array(
			'label'       => ! empty( $settings['site_label'] ) ? $settings['site_label'] : get_bloginfo( 'name' ),
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url(),
			'admin_email' => get_bloginfo( 'admin_email' ),
			'wp_version'  => get_bloginfo( 'version' ),
			'theme'       => wp_get_theme()->get( 'Name' ),
			'is_multisite' => is_multisite(),
			'woocommerce' => class_exists( 'WooCommerce' ) ? WC()->version : false,
		);
	}

	public static function diagnostics( $params ) {
		global $wpdb;

		$autoload = $wpdb->get_row( "SELECT COUNT(*) as cnt, SUM(LENGTH(option_value)) as bytes FROM {$wpdb->options} WHERE autoload='yes'" );
		$revisions = wp_count_posts( 'revision' );
		$transients = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%'" );

		$db_size = $wpdb->get_var( $wpdb->prepare(
			"SELECT ROUND(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = %s",
			DB_NAME
		) );

		$largest_tables = $wpdb->get_results( $wpdb->prepare(
			"SELECT table_name, ROUND((data_length + index_length), 0) as size_bytes, table_rows
			 FROM information_schema.tables WHERE table_schema = %s
			 ORDER BY (data_length + index_length) DESC LIMIT 10",
			DB_NAME
		), ARRAY_A );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );

		return array(
			'php_version'          => phpversion(),
			'memory_limit'         => ini_get( 'memory_limit' ),
			'max_execution_time'   => ini_get( 'max_execution_time' ),
			'wp_debug'             => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_memory_limit'      => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : null,
			'active_plugins_count' => count( $active_plugins ),
			'total_plugins_count'  => count( $all_plugins ),
			'active_plugins'       => $active_plugins,
			'autoload_options'     => array(
				'count' => (int) $autoload->cnt,
				'bytes' => (int) $autoload->bytes,
				'kb'    => round( $autoload->bytes / 1024, 1 ),
			),
			'transients_count'     => (int) $transients,
			'revisions_count'      => (int) array_sum( (array) $revisions ),
			'db_total_size_bytes'  => (int) $db_size,
			'db_largest_tables'    => $largest_tables,
			'object_cache_active'  => wp_using_ext_object_cache(),
			'page_cache_plugins'   => array(
				'litespeed'  => class_exists( 'LiteSpeed\Core' ) || function_exists( 'litespeed_purge_all' ),
				'wp_super_cache' => function_exists( 'wp_cache_clear_cache' ),
				'w3_total_cache' => function_exists( 'w3tc_flush_all' ),
				'wp_rocket'  => function_exists( 'rocket_clean_domain' ),
			),
		);
	}

	public static function option_get( $params ) {
		if ( empty( $params['name'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "name".', array( 'status' => 400 ) );
		}
		return array( 'value' => get_option( sanitize_key( $params['name'] ) ) );
	}

	public static function option_update( $params ) {
		if ( empty( $params['name'] ) || ! array_key_exists( 'value', $params ) ) {
			return new WP_Error( 'missing_param', 'Missing "name" or "value".', array( 'status' => 400 ) );
		}
		$name = sanitize_key( $params['name'] );
		update_option( $name, $params['value'] );
		WP_SSH_Auth::log_action( 'option_update', $name );
		return array( 'updated' => $name );
	}

	public static function post_list( $params ) {
		$args = array(
			'post_type'      => isset( $params['post_type'] ) ? sanitize_key( $params['post_type'] ) : 'post',
			'post_status'    => isset( $params['post_status'] ) ? sanitize_key( $params['post_status'] ) : 'any',
			'posts_per_page' => isset( $params['limit'] ) ? min( 100, absint( $params['limit'] ) ) : 20,
			's'              => isset( $params['search'] ) ? sanitize_text_field( $params['search'] ) : '',
		);
		$query = new WP_Query( $args );
		$out   = array();
		foreach ( $query->posts as $p ) {
			$out[] = array(
				'id'     => $p->ID,
				'title'  => $p->post_title,
				'status' => $p->post_status,
				'type'   => $p->post_type,
				'slug'   => $p->post_name,
			);
		}
		return array( 'posts' => $out, 'found' => $query->found_posts );
	}

	public static function post_get( $params ) {
		if ( empty( $params['id'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "id".', array( 'status' => 400 ) );
		}
		$post = get_post( absint( $params['id'] ) );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}
		return array(
			'id'      => $post->ID,
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'excerpt' => $post->post_excerpt,
			'status'  => $post->post_status,
			'type'    => $post->post_type,
			'meta'    => get_post_meta( $post->ID ),
		);
	}

	public static function post_create( $params ) {
		if ( empty( $params['title'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "title".', array( 'status' => 400 ) );
		}
		$postarr = array(
			'post_title'   => sanitize_text_field( $params['title'] ),
			'post_content' => isset( $params['content'] ) ? wp_kses_post( $params['content'] ) : '',
			'post_excerpt' => isset( $params['excerpt'] ) ? sanitize_text_field( $params['excerpt'] ) : '',
			'post_type'    => isset( $params['post_type'] ) ? sanitize_key( $params['post_type'] ) : 'post',
			'post_status'  => isset( $params['post_status'] ) ? sanitize_key( $params['post_status'] ) : 'draft',
		);
		if ( ! empty( $params['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $params['slug'] );
		}
		$id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
			foreach ( $params['meta'] as $k => $v ) {
				update_post_meta( $id, sanitize_key( $k ), $v );
			}
		}
		WP_SSH_Auth::log_action( 'post_create', "#$id " . $postarr['post_title'] );
		return array( 'id' => $id );
	}

	public static function post_update( $params ) {
		if ( empty( $params['id'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "id".', array( 'status' => 400 ) );
		}
		$postarr = array( 'ID' => absint( $params['id'] ) );
		foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status', 'slug' => 'post_name' ) as $in => $field ) {
			if ( isset( $params[ $in ] ) ) {
				$postarr[ $field ] = ( 'content' === $in ) ? wp_kses_post( $params[ $in ] ) : sanitize_text_field( $params[ $in ] );
			}
		}
		$result = wp_update_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! empty( $params['meta'] ) && is_array( $params['meta'] ) ) {
			foreach ( $params['meta'] as $k => $v ) {
				update_post_meta( $postarr['ID'], sanitize_key( $k ), $v );
			}
		}
		WP_SSH_Auth::log_action( 'post_update', '#' . $postarr['ID'] );
		return array( 'id' => $postarr['ID'] );
	}

	public static function post_delete( $params ) {
		if ( empty( $params['id'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "id".', array( 'status' => 400 ) );
		}
		$force = ! empty( $params['force'] );
		if ( $force && empty( $params['confirm'] ) ) {
			return new WP_Error( 'confirm_required', 'Permanent delete requires "confirm": true in params.', array( 'status' => 400 ) );
		}
		$result = wp_delete_post( absint( $params['id'] ), $force );
		WP_SSH_Auth::log_action( 'post_delete', '#' . $params['id'] . ( $force ? ' (forced)' : ' (trashed)' ) );
		return array( 'deleted' => (bool) $result );
	}

	public static function term_list( $params ) {
		$taxonomy = isset( $params['taxonomy'] ) ? sanitize_key( $params['taxonomy'] ) : 'category';
		$terms    = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$out = array();
		foreach ( $terms as $t ) {
			$out[] = array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count );
		}
		return array( 'terms' => $out );
	}

	public static function term_create( $params ) {
		if ( empty( $params['name'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "name".', array( 'status' => 400 ) );
		}
		$taxonomy = isset( $params['taxonomy'] ) ? sanitize_key( $params['taxonomy'] ) : 'category';
		$args     = array();
		if ( ! empty( $params['slug'] ) ) {
			$args['slug'] = sanitize_title( $params['slug'] );
		}
		$result = wp_insert_term( sanitize_text_field( $params['name'] ), $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		WP_SSH_Auth::log_action( 'term_create', $taxonomy . ':' . $params['name'] );
		return $result;
	}

	public static function media_upload( $params ) {
		if ( empty( $params['filename'] ) || empty( $params['content_base64'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "filename" or "content_base64".', array( 'status' => 400 ) );
		}

		$allowed_ext = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' );
		$filename    = sanitize_file_name( $params['filename'] );
		$ext         = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( ! in_array( $ext, $allowed_ext, true ) ) {
			return new WP_Error( 'bad_ext', 'File type not allowed.', array( 'status' => 400 ) );
		}

		$data = base64_decode( $params['content_base64'], true );
		if ( false === $data ) {
			return new WP_Error( 'bad_data', 'Invalid base64 content.', array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_upload_bits( $filename, null, $data );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_failed', $upload['error'], array( 'status' => 500 ) );
		}

		$filetype   = wp_check_filetype( $upload['file'], null );
		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : preg_replace( '/\.[^.]+$/', '', $filename ),
			'post_status'    => 'inherit',
		);
		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );
		$meta      = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $meta );

		WP_SSH_Auth::log_action( 'media_upload', $filename );

		return array( 'id' => $attach_id, 'url' => wp_get_attachment_url( $attach_id ) );
	}

	public static function media_list( $params ) {
		$args  = array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => isset( $params['limit'] ) ? min( 100, absint( $params['limit'] ) ) : 20 );
		$query = new WP_Query( $args );
		$out   = array();
		foreach ( $query->posts as $p ) {
			$out[] = array( 'id' => $p->ID, 'title' => $p->post_title, 'url' => wp_get_attachment_url( $p->ID ) );
		}
		return array( 'media' => $out );
	}

	public static function plugin_list( $params ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$active = get_option( 'active_plugins', array() );
		$out    = array();
		foreach ( $all as $file => $data ) {
			$out[] = array(
				'file'   => $file,
				'name'   => $data['Name'],
				'version' => $data['Version'],
				'active' => in_array( $file, $active, true ),
			);
		}
		return array( 'plugins' => $out );
	}

	public static function plugin_activate( $params ) {
		if ( empty( $params['file'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "file".', array( 'status' => 400 ) );
		}
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$result = activate_plugin( sanitize_text_field( $params['file'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		WP_SSH_Auth::log_action( 'plugin_activate', $params['file'] );
		return array( 'activated' => $params['file'] );
	}

	public static function plugin_deactivate( $params ) {
		if ( empty( $params['file'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "file".', array( 'status' => 400 ) );
		}
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( sanitize_text_field( $params['file'] ) );
		WP_SSH_Auth::log_action( 'plugin_deactivate', $params['file'] );
		return array( 'deactivated' => $params['file'] );
	}

	public static function theme_list( $params ) {
		$themes = wp_get_themes();
		$active = get_stylesheet();
		$out    = array();
		foreach ( $themes as $slug => $theme ) {
			$out[] = array( 'slug' => $slug, 'name' => $theme->get( 'Name' ), 'active' => ( $slug === $active ) );
		}
		return array( 'themes' => $out );
	}

	/* ---------------------------------------------------------------- */
	/* File access is strictly contained inside wp-content.             */

	protected static function resolve_scoped_path( $relative ) {
		$base = realpath( WP_CONTENT_DIR );
		$target = realpath( WP_CONTENT_DIR . '/' . ltrim( $relative, '/' ) );

		// For writes, the target may not exist yet — resolve its parent dir instead.
		if ( false === $target ) {
			$parent = realpath( dirname( WP_CONTENT_DIR . '/' . ltrim( $relative, '/' ) ) );
			if ( false === $parent || 0 !== strpos( $parent, $base ) ) {
				return false;
			}
			return WP_CONTENT_DIR . '/' . ltrim( $relative, '/' );
		}

		if ( 0 !== strpos( $target, $base ) ) {
			return false;
		}

		return $target;
	}

	public static function file_read( $params ) {
		if ( empty( $params['path'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "path" (relative to wp-content).', array( 'status' => 400 ) );
		}
		$path = self::resolve_scoped_path( $params['path'] );
		if ( ! $path || ! is_file( $path ) ) {
			return new WP_Error( 'not_found', 'File not found or outside wp-content.', array( 'status' => 404 ) );
		}
		return array( 'path' => $params['path'], 'content' => file_get_contents( $path ) );
	}

	public static function file_write( $params ) {
		if ( empty( $params['path'] ) || ! isset( $params['content'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "path" or "content" (relative to wp-content).', array( 'status' => 400 ) );
		}
		$path = self::resolve_scoped_path( $params['path'] );
		if ( ! $path ) {
			return new WP_Error( 'bad_path', 'Path must stay inside wp-content.', array( 'status' => 400 ) );
		}
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$backup_info = null;
		if ( is_file( $path ) && empty( $params['skip_backup'] ) ) {
			$backup_info = self::rotate_backup( $path );
		}

		$bytes = file_put_contents( $path, $params['content'] );
		if ( false === $bytes ) {
			return new WP_Error( 'write_failed', 'Could not write file.', array( 'status' => 500 ) );
		}
		WP_SSH_Auth::log_action( 'file_write', $params['path'] );
		return array( 'path' => $params['path'], 'bytes' => $bytes, 'backup' => $backup_info );
	}

	/**
	 * Keep up to 3 rotating .wpssh-bak-N sibling copies of a file before overwriting it.
	 */
	protected static function rotate_backup( $path ) {
		$max = 3;
		for ( $i = $max; $i >= 1; $i-- ) {
			$from = $path . '.wpssh-bak-' . $i;
			$to   = $path . '.wpssh-bak-' . ( $i + 1 );
			if ( is_file( $from ) ) {
				if ( $i + 1 > $max ) {
					unlink( $from );
				} else {
					rename( $from, $to );
				}
			}
		}
		$first = $path . '.wpssh-bak-1';
		copy( $path, $first );
		return basename( $first );
	}

	public static function file_list( $params ) {
		$relative = isset( $params['path'] ) ? $params['path'] : '';
		$path     = self::resolve_scoped_path( $relative );
		if ( ! $path || ! is_dir( $path ) ) {
			return new WP_Error( 'not_found', 'Directory not found or outside wp-content.', array( 'status' => 404 ) );
		}
		$items = array();
		foreach ( scandir( $path ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$items[] = array(
				'name' => $item,
				'type' => is_dir( $path . '/' . $item ) ? 'dir' : 'file',
			);
		}
		return array( 'path' => $relative, 'items' => $items );
	}

	/* ---------------------------------------------------------------- */

	public static function db_query( $params ) {
		global $wpdb;

		if ( empty( $params['sql'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "sql".', array( 'status' => 400 ) );
		}

		$sql       = $params['sql'];
		$is_select = (bool) preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql );

		if ( ! $is_select ) {
			$settings = get_option( WP_SSH_OPTION, array() );
			if ( empty( $settings['allow_write_queries'] ) ) {
				return new WP_Error( 'writes_disabled', 'Write queries are disabled. Enable "Allow write queries" in Tools > WP SSH to permit this.', array( 'status' => 403 ) );
			}
			if ( empty( $params['confirm'] ) ) {
				return new WP_Error( 'confirm_required', 'Write queries require "confirm": true in params.', array( 'status' => 400 ) );
			}
		}

		if ( $is_select ) {
			$results = $wpdb->get_results( $sql, ARRAY_A );
			if ( null === $results && $wpdb->last_error ) {
				return new WP_Error( 'db_error', $wpdb->last_error, array( 'status' => 500 ) );
			}
			return array( 'rows' => $results );
		}

		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			return new WP_Error( 'db_error', $wpdb->last_error, array( 'status' => 500 ) );
		}
		WP_SSH_Auth::log_action( 'db_query', substr( $sql, 0, 120 ) );
		return array( 'affected_rows' => $result );
	}

	/* ---------------------------------------------------------------- */
	/* Cache clearing                                                    */

	public static function cache_clear( $params ) {
		$cleared = array();

		// Elementor's per-post render + CSS cache (the exact thing that caused
		// stale colors/layout during this project's manual work).
		if ( ! empty( $params['post_id'] ) ) {
			$post_id = absint( $params['post_id'] );
			delete_post_meta( $post_id, '_elementor_css' );
			delete_post_meta( $post_id, '_elementor_element_cache' );
			$cleared[] = "elementor cache for post {$post_id}";
		} elseif ( ! empty( $params['all_elementor'] ) ) {
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_css','_elementor_element_cache')" );
			$cleared[] = 'elementor cache for all posts';
		}

		// Woodmart / xtemos theme compiled CSS cache, if present.
		delete_option( 'xts-theme_settings_default-css-data' );
		delete_option( 'xts-theme_settings_default-file-data' );
		$cleared[] = 'theme compiled CSS cache';

		// Expired + our own transients.
		if ( ! empty( $params['transients'] ) ) {
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_transient\\_timeout\\_%'" );
			$cleared[] = 'all transients';
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
			$cleared[] = 'object cache';
		}

		if ( function_exists( 'litespeed_purge_all' ) ) {
			litespeed_purge_all();
			$cleared[] = 'litespeed page cache';
		} elseif ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			$cleared[] = 'litespeed page cache';
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$cleared[] = 'wp super cache';
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$cleared[] = 'w3 total cache';
		}

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$cleared[] = 'wp rocket';
		}

		WP_SSH_Auth::log_action( 'cache_clear', implode( ', ', $cleared ) );

		return array( 'cleared' => $cleared );
	}

	/* ---------------------------------------------------------------- */
	/* Serialization-safe search & replace across the database.         */

	public static function search_replace( $params ) {
		if ( ! isset( $params['search'] ) || ! isset( $params['replace'] ) || '' === $params['search'] ) {
			return new WP_Error( 'missing_param', 'Missing "search" or "replace".', array( 'status' => 400 ) );
		}

		global $wpdb;
		$search  = $params['search'];
		$replace = $params['replace'];
		$dry_run = ! empty( $params['dry_run'] );

		$tables = ! empty( $params['tables'] ) && is_array( $params['tables'] )
			? $params['tables']
			: array( $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->comments, $wpdb->terms, $wpdb->termmeta );

		$report = array();

		foreach ( $tables as $table ) {
			$table = preg_replace( '/[^a-zA-Z0-9_]/', '', $table ); // whitelist-safe identifier only
			$cols  = $wpdb->get_col( "DESCRIBE {$table}", 0 );
			$pk    = self::guess_primary_key( $wpdb, $table );

			if ( ! $pk ) {
				continue;
			}

			$text_cols = array_values( array_filter( $cols, function ( $c ) use ( $wpdb, $table ) {
				return true; // DESCRIBE doesn't give type here cheaply; we try/catch per-cell instead.
			} ) );

			$changed_rows = 0;

			foreach ( $text_cols as $col ) {
				if ( $col === $pk ) {
					continue;
				}
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT {$pk} as pk_val, {$col} as val FROM {$table} WHERE {$col} LIKE %s",
					'%' . $wpdb->esc_like( $search ) . '%'
				), ARRAY_A );

				foreach ( $rows as $row ) {
					$new_val = self::serialization_safe_replace( $search, $replace, $row['val'] );
					if ( $new_val !== $row['val'] ) {
						$changed_rows++;
						if ( ! $dry_run ) {
							$wpdb->update( $table, array( $col => $new_val ), array( $pk => $row['pk_val'] ) );
						}
					}
				}
			}

			if ( $changed_rows > 0 ) {
				$report[ $table ] = $changed_rows;
			}
		}

		WP_SSH_Auth::log_action( 'search_replace', ( $dry_run ? 'DRY RUN ' : '' ) . $search . ' -> ' . $replace );

		return array( 'dry_run' => $dry_run, 'changed_per_table' => $report );
	}

	protected static function guess_primary_key( $wpdb, $table ) {
		$key = $wpdb->get_var( "SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'", 4 );
		return $key ? $key : null;
	}

	/** Recursively replaces a string inside a value, re-serializing PHP-serialized data correctly. */
	protected static function serialization_safe_replace( $search, $replace, $value ) {
		if ( is_serialized( $value ) ) {
			$data = @unserialize( $value ); // phpcs:ignore
			if ( false !== $data || 'b:0;' === $value ) {
				$data = self::deep_replace( $search, $replace, $data );
				return serialize( $data ); // phpcs:ignore
			}
		}
		if ( is_string( $value ) ) {
			return str_replace( $search, $replace, $value );
		}
		return $value;
	}

	protected static function deep_replace( $search, $replace, $data ) {
		if ( is_string( $data ) ) {
			return str_replace( $search, $replace, $data );
		}
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				$out[ self::deep_replace( $search, $replace, $k ) ] = self::deep_replace( $search, $replace, $v );
			}
			return $out;
		}
		if ( is_object( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data->$k = self::deep_replace( $search, $replace, $v );
			}
			return $data;
		}
		return $data;
	}

	/* ---------------------------------------------------------------- */
	/* WooCommerce product attributes                                   */

	public static function product_attribute_set( $params ) {
		if ( empty( $params['product_id'] ) || empty( $params['taxonomy'] ) || empty( $params['term_ids'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "product_id", "taxonomy", or "term_ids".', array( 'status' => 400 ) );
		}

		$product_id = absint( $params['product_id'] );
		$taxonomy   = sanitize_key( $params['taxonomy'] );
		$term_ids   = array_map( 'absint', (array) $params['term_ids'] );
		$for_variation = ! empty( $params['is_variation'] );

		$result = wp_set_object_terms( $product_id, $term_ids, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$attrs = get_post_meta( $product_id, '_product_attributes', true );
		if ( ! is_array( $attrs ) ) {
			$attrs = array();
		}
		$attrs[ $taxonomy ] = array(
			'name'         => $taxonomy,
			'value'        => '',
			'is_visible'   => 1,
			'is_variation' => $for_variation ? 1 : 0,
			'is_taxonomy'  => 1,
		);
		update_post_meta( $product_id, '_product_attributes', $attrs );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}

		WP_SSH_Auth::log_action( 'product_attribute_set', "#$product_id $taxonomy" );

		return array( 'product_id' => $product_id, 'taxonomy' => $taxonomy, 'term_ids' => $term_ids );
	}

	/* ---------------------------------------------------------------- */
	/* Nav menus                                                         */

	public static function menu_list( $params ) {
		$menus = wp_get_nav_menus();
		$out   = array();
		foreach ( $menus as $m ) {
			$out[] = array( 'id' => $m->term_id, 'name' => $m->name, 'slug' => $m->slug, 'count' => $m->count );
		}
		return array( 'menus' => $out );
	}

	public static function menu_item_add( $params ) {
		if ( empty( $params['menu'] ) || empty( $params['title'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "menu" or "title".', array( 'status' => 400 ) );
		}

		$menu = is_numeric( $params['menu'] ) ? absint( $params['menu'] ) : $params['menu'];
		$menu_obj = wp_get_nav_menu_object( $menu );
		if ( ! $menu_obj ) {
			return new WP_Error( 'not_found', 'Menu not found.', array( 'status' => 404 ) );
		}

		$args = array(
			'menu-item-title'  => sanitize_text_field( $params['title'] ),
			'menu-item-status' => 'publish',
		);

		if ( ! empty( $params['object_id'] ) ) {
			$args['menu-item-object-id'] = absint( $params['object_id'] );
			$args['menu-item-object']    = isset( $params['object_type'] ) ? sanitize_key( $params['object_type'] ) : 'page';
			$args['menu-item-type']      = isset( $params['is_taxonomy'] ) && $params['is_taxonomy'] ? 'taxonomy' : 'post_type';
		} else {
			$args['menu-item-url']  = isset( $params['url'] ) ? esc_url_raw( $params['url'] ) : '#';
			$args['menu-item-type'] = 'custom';
		}

		if ( ! empty( $params['position'] ) ) {
			$args['menu-item-position'] = absint( $params['position'] );
		}
		if ( ! empty( $params['parent_id'] ) ) {
			$args['menu-item-parent-id'] = absint( $params['parent_id'] );
		}

		$item_id = wp_update_nav_menu_item( $menu_obj->term_id, 0, $args );
		if ( is_wp_error( $item_id ) ) {
			return $item_id;
		}

		WP_SSH_Auth::log_action( 'menu_item_add', $menu_obj->name . ' <- ' . $params['title'] );

		return array( 'item_id' => $item_id, 'menu_id' => $menu_obj->term_id );
	}

	/* ---------------------------------------------------------------- */
	/* Widgets (classic sidebar widgets)                                 */

	public static function widget_add( $params ) {
		if ( empty( $params['sidebar_id'] ) || empty( $params['id_base'] ) || ! isset( $params['instance'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "sidebar_id", "id_base", or "instance".', array( 'status' => 400 ) );
		}

		$sidebar_id = sanitize_key( $params['sidebar_id'] );
		$id_base    = sanitize_key( $params['id_base'] );
		$instance   = $params['instance'];

		$option_name = 'widget_' . $id_base;
		$widget_opts = get_option( $option_name, array() );
		if ( ! is_array( $widget_opts ) ) {
			$widget_opts = array();
		}

		$next_num = 1;
		foreach ( array_keys( $widget_opts ) as $k ) {
			if ( is_numeric( $k ) && (int) $k >= $next_num ) {
				$next_num = (int) $k + 1;
			}
		}

		$widget_opts[ $next_num ] = $instance;
		update_option( $option_name, $widget_opts );

		$sidebars = get_option( 'sidebars_widgets', array() );
		if ( ! isset( $sidebars[ $sidebar_id ] ) || ! is_array( $sidebars[ $sidebar_id ] ) ) {
			$sidebars[ $sidebar_id ] = array();
		}
		$sidebars[ $sidebar_id ][] = $id_base . '-' . $next_num;
		update_option( 'sidebars_widgets', $sidebars );

		WP_SSH_Auth::log_action( 'widget_add', "{$id_base}-{$next_num} -> {$sidebar_id}" );

		return array( 'widget_id' => $id_base . '-' . $next_num, 'sidebar_id' => $sidebar_id );
	}

	/* ---------------------------------------------------------------- */
	/* Elementor page data — get/set with automatic cache invalidation. */

	public static function elementor_data_get( $params ) {
		if ( empty( $params['post_id'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "post_id".', array( 'status' => 400 ) );
		}
		$post_id = absint( $params['post_id'] );
		return array(
			'post_id' => $post_id,
			'data'    => get_post_meta( $post_id, '_elementor_data', true ),
		);
	}

	public static function elementor_data_set( $params ) {
		if ( empty( $params['post_id'] ) || ! isset( $params['data'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "post_id" or "data".', array( 'status' => 400 ) );
		}
		$post_id = absint( $params['post_id'] );
		$data    = is_array( $params['data'] ) ? wp_json_encode( $params['data'] ) : $params['data'];

		json_decode( $data );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'bad_json', 'data must be valid Elementor JSON.', array( 'status' => 400 ) );
		}

		update_post_meta( $post_id, '_elementor_data', $data );
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );

		WP_SSH_Auth::log_action( 'elementor_data_set', "#$post_id (" . strlen( $data ) . ' bytes)' );

		return array( 'post_id' => $post_id, 'bytes' => strlen( $data ) );
	}

	/* ---------------------------------------------------------------- */
	/* WooCommerce orders                                                */

	public static function order_list( $params ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'no_woocommerce', 'WooCommerce is not active.', array( 'status' => 400 ) );
		}
		$args = array(
			'limit'   => isset( $params['limit'] ) ? min( 100, absint( $params['limit'] ) ) : 20,
			'status'  => isset( $params['status'] ) ? sanitize_key( $params['status'] ) : array_keys( wc_get_order_statuses() ),
			'orderby' => 'date',
			'order'   => 'DESC',
		);
		if ( ! empty( $params['search'] ) ) {
			$args['s'] = sanitize_text_field( $params['search'] );
		}
		$orders = wc_get_orders( $args );
		$out    = array();
		foreach ( $orders as $order ) {
			$out[] = array(
				'id'       => $order->get_id(),
				'status'   => $order->get_status(),
				'total'    => $order->get_total(),
				'currency' => $order->get_currency(),
				'customer' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email'    => $order->get_billing_email(),
				'date'     => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
			);
		}
		return array( 'orders' => $out );
	}

	public static function order_get( $params ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'no_woocommerce', 'WooCommerce is not active.', array( 'status' => 400 ) );
		}
		if ( empty( $params['id'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "id".', array( 'status' => 400 ) );
		}
		$order = wc_get_order( absint( $params['id'] ) );
		if ( ! $order ) {
			return new WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'       => $item->get_name(),
				'quantity'   => $item->get_quantity(),
				'total'      => $item->get_total(),
				'product_id' => $item->get_product_id(),
			);
		}
		return array(
			'id'               => $order->get_id(),
			'status'           => $order->get_status(),
			'total'            => $order->get_total(),
			'currency'         => $order->get_currency(),
			'customer'         => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email'            => $order->get_billing_email(),
			'phone'            => $order->get_billing_phone(),
			'address'          => $order->get_formatted_billing_address(),
			'shipping_address' => $order->get_formatted_shipping_address(),
			'items'            => $items,
			'note'             => $order->get_customer_note(),
			'payment_method'   => $order->get_payment_method_title(),
			'date'             => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
		);
	}

	public static function order_update_status( $params ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'no_woocommerce', 'WooCommerce is not active.', array( 'status' => 400 ) );
		}
		if ( empty( $params['id'] ) || empty( $params['status'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "id" or "status".', array( 'status' => 400 ) );
		}
		$order = wc_get_order( absint( $params['id'] ) );
		if ( ! $order ) {
			return new WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$status     = sanitize_key( $params['status'] );
		$status_key = ( 'wc-' === substr( $status, 0, 3 ) ) ? $status : 'wc-' . $status;
		if ( ! in_array( $status_key, array_keys( wc_get_order_statuses() ), true ) ) {
			return new WP_Error( 'bad_status', 'Invalid order status.', array( 'status' => 400 ) );
		}
		$order->update_status( str_replace( 'wc-', '', $status_key ), isset( $params['note'] ) ? sanitize_text_field( $params['note'] ) : '' );
		WP_SSH_Auth::log_action( 'order_update_status', '#' . $order->get_id() . ' -> ' . $status_key );
		return array( 'id' => $order->get_id(), 'status' => $order->get_status() );
	}

	/* ---------------------------------------------------------------- */
	/* Restore a file from its rotating .wpssh-bak-N backup.             */

	public static function file_restore( $params ) {
		if ( empty( $params['path'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "path" (relative to wp-content).', array( 'status' => 400 ) );
		}
		$path = self::resolve_scoped_path( $params['path'] );
		if ( ! $path ) {
			return new WP_Error( 'bad_path', 'Path must stay inside wp-content.', array( 'status' => 400 ) );
		}
		$slot        = isset( $params['backup'] ) ? max( 1, absint( $params['backup'] ) ) : 1;
		$backup_path = $path . '.wpssh-bak-' . $slot;
		if ( ! is_file( $backup_path ) ) {
			return new WP_Error( 'not_found', 'Backup not found: ' . basename( $backup_path ), array( 'status' => 404 ) );
		}
		if ( empty( $params['confirm'] ) ) {
			return new WP_Error( 'confirm_required', 'Restoring will overwrite the current file. Pass "confirm": true.', array( 'status' => 400 ) );
		}

		$backup_content = file_get_contents( $backup_path );
		if ( false === $backup_content ) {
			return new WP_Error( 'read_failed', 'Could not read backup file.', array( 'status' => 500 ) );
		}

		// Keep the pre-restore state instead of discarding it.
		if ( is_file( $path ) ) {
			self::rotate_backup( $path );
		}

		file_put_contents( $path, $backup_content );
		WP_SSH_Auth::log_action( 'file_restore', $params['path'] . ' <- ' . basename( $backup_path ) );
		return array( 'path' => $params['path'], 'restored_from' => basename( $backup_path ) );
	}

	/* ---------------------------------------------------------------- */
	/* Cross-site template clone (export from one site, import on another). */
	/* The plugin never talks to other sites directly — the caller moves    */
	/* the exported payload from one site's response into the other site's  */
	/* template_import call.                                                */

	public static function template_export( $params ) {
		if ( empty( $params['post_id'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "post_id".', array( 'status' => 400 ) );
		}
		$post_id = absint( $params['post_id'] );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}
		return array(
			'title'                    => $post->post_title,
			'post_type'                => $post->post_type,
			'elementor_data'           => get_post_meta( $post_id, '_elementor_data', true ),
			'elementor_page_settings'  => get_post_meta( $post_id, '_elementor_page_settings', true ),
			'elementor_template_type'  => get_post_meta( $post_id, '_elementor_template_type', true ),
			'elementor_version'        => get_post_meta( $post_id, '_elementor_version', true ),
			'page_template'            => get_post_meta( $post_id, '_wp_page_template', true ),
		);
	}

	public static function template_import( $params ) {
		if ( empty( $params['title'] ) || ! isset( $params['elementor_data'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "title" or "elementor_data".', array( 'status' => 400 ) );
		}

		$data = is_array( $params['elementor_data'] ) ? wp_json_encode( $params['elementor_data'] ) : $params['elementor_data'];
		json_decode( $data );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'bad_json', 'elementor_data must be valid Elementor JSON.', array( 'status' => 400 ) );
		}

		$post_id = ! empty( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;

		if ( $post_id ) {
			$result = wp_update_post( array( 'ID' => $post_id, 'post_title' => sanitize_text_field( $params['title'] ) ), true );
		} else {
			$result = wp_insert_post( array(
				'post_title'  => sanitize_text_field( $params['title'] ),
				'post_type'   => isset( $params['post_type'] ) ? sanitize_key( $params['post_type'] ) : 'page',
				'post_status' => isset( $params['post_status'] ) ? sanitize_key( $params['post_status'] ) : 'draft',
			), true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$post_id = $result;

		update_post_meta( $post_id, '_elementor_data', $data );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		if ( ! empty( $params['elementor_page_settings'] ) ) {
			update_post_meta( $post_id, '_elementor_page_settings', $params['elementor_page_settings'] );
		}
		if ( ! empty( $params['elementor_template_type'] ) ) {
			update_post_meta( $post_id, '_elementor_template_type', sanitize_key( $params['elementor_template_type'] ) );
		}
		update_post_meta( $post_id, '_elementor_version', ! empty( $params['elementor_version'] ) ? sanitize_text_field( $params['elementor_version'] ) : '3.0.0' );
		if ( ! empty( $params['page_template'] ) ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $params['page_template'] ) );
		}
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );

		WP_SSH_Auth::log_action( 'template_import', "#$post_id " . $params['title'] );

		return array( 'post_id' => $post_id );
	}

	/* ---------------------------------------------------------------- */
	/* WP-Cron inspector                                                 */

	public static function cron_list( $params ) {
		$crons = _get_cron_array();
		$out   = array();
		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $events ) {
					foreach ( $events as $event ) {
						$out[] = array(
							'hook'                => $hook,
							'next_run'            => gmdate( 'Y-m-d H:i:s', $timestamp ),
							'next_run_in_seconds' => $timestamp - time(),
							'schedule'            => isset( $event['schedule'] ) ? $event['schedule'] : 'single',
							'args'                => $event['args'],
						);
					}
				}
			}
		}
		usort( $out, function ( $a, $b ) {
			return $a['next_run_in_seconds'] <=> $b['next_run_in_seconds'];
		} );
		return array( 'events' => $out );
	}

	public static function cron_run( $params ) {
		if ( empty( $params['hook'] ) ) {
			return new WP_Error( 'missing_param', 'Missing "hook".', array( 'status' => 400 ) );
		}
		$hook = sanitize_text_field( $params['hook'] );
		$args = isset( $params['args'] ) && is_array( $params['args'] ) ? $params['args'] : array();
		do_action_ref_array( $hook, $args );
		WP_SSH_Auth::log_action( 'cron_run', $hook );
		return array( 'ran' => $hook );
	}

	/* ---------------------------------------------------------------- */
	/* Self health-check (runs hourly via wp_ssh_health_check cron hook  */
	/* registered in wp-ssh.php, plus available on-demand via the API). */

	public static function run_health_check() {
		$settings = get_option( WP_SSH_OPTION, array() );
		if ( empty( $settings['health_check_enabled'] ) ) {
			return;
		}

		$result             = self::perform_health_check();
		$settings           = get_option( WP_SSH_OPTION, array() );
		$settings['last_health_check'] = $result;
		update_option( WP_SSH_OPTION, $settings );

		if ( ! empty( $result['issues'] ) ) {
			$to      = ! empty( $settings['health_check_email'] ) ? $settings['health_check_email'] : get_bloginfo( 'admin_email' );
			$subject = '[WP SSH] Health check issues on ' . get_bloginfo( 'name' );
			$body    = "The following issues were found:\n\n" . implode( "\n", $result['issues'] );
			wp_mail( $to, $subject, $body );
		}
	}

	protected static function perform_health_check() {
		$issues   = array();
		$settings = get_option( WP_SSH_OPTION, array() );

		if ( empty( $settings['enabled'] ) ) {
			$issues[] = 'WP SSH bridge is currently disabled.';
		}

		global $wpdb;
		if ( ! $wpdb->check_connection( false ) ) {
			$issues[] = 'Database connection failed.';
		}

		$debug_log = WP_CONTENT_DIR . '/debug.log';
		if ( is_file( $debug_log ) ) {
			$tail          = self::tail_file( $debug_log, 20000 );
			$recent_fatals = preg_match_all( '/PHP Fatal error/i', $tail );
			if ( $recent_fatals > 0 ) {
				$issues[] = "Found {$recent_fatals} PHP Fatal error(s) in the tail of debug.log.";
			}
		}

		if ( function_exists( 'disk_free_space' ) ) {
			$free = @disk_free_space( ABSPATH ); // phpcs:ignore
			if ( false !== $free && $free < 100 * 1024 * 1024 ) {
				$issues[] = 'Low disk space: ' . round( $free / 1024 / 1024, 1 ) . ' MB free.';
			}
		}

		return array(
			'checked_at' => current_time( 'mysql' ),
			'issues'     => $issues,
			'ok'         => empty( $issues ),
		);
	}

	protected static function tail_file( $path, $bytes ) {
		$size = filesize( $path );
		$fh   = fopen( $path, 'r' ); // phpcs:ignore
		if ( ! $fh ) {
			return '';
		}
		fseek( $fh, max( 0, $size - $bytes ) );
		$data = fread( $fh, $bytes );
		fclose( $fh ); // phpcs:ignore
		return $data;
	}

	public static function health_check_status( $params ) {
		$settings = get_option( WP_SSH_OPTION, array() );
		return array(
			'enabled'    => ! empty( $settings['health_check_enabled'] ),
			'last_check' => isset( $settings['last_health_check'] ) ? $settings['last_health_check'] : null,
		);
	}

	public static function health_check_run( $params ) {
		$result             = self::perform_health_check();
		$settings           = get_option( WP_SSH_OPTION, array() );
		$settings['last_health_check'] = $result;
		update_option( WP_SSH_OPTION, $settings );
		WP_SSH_Auth::log_action( 'health_check_run', $result['ok'] ? 'OK' : count( $result['issues'] ) . ' issue(s)' );
		return $result;
	}
}
