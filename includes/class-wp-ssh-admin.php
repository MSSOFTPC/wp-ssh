<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_SSH_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wp_ssh_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wp_ssh_regenerate', array( __CLASS__, 'handle_regenerate' ) );
	}

	public static function menu() {
		add_management_page(
			'WP SSH',
			'WP SSH',
			'manage_options',
			'wp-ssh',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wp_ssh_save' );

		$settings                          = get_option( WP_SSH_OPTION, array() );
		$settings['enabled']               = ! empty( $_POST['enabled'] );
		$settings['read_only_mode']        = ! empty( $_POST['read_only_mode'] );
		$settings['allow_write_queries']   = ! empty( $_POST['allow_write_queries'] );
		$settings['ip_allowlist']          = isset( $_POST['ip_allowlist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ip_allowlist'] ) ) : '';
		$settings['site_label']            = isset( $_POST['site_label'] ) ? sanitize_text_field( wp_unslash( $_POST['site_label'] ) ) : '';
		$settings['health_check_enabled']  = ! empty( $_POST['health_check_enabled'] );
		$settings['health_check_email']    = isset( $_POST['health_check_email'] ) ? sanitize_email( wp_unslash( $_POST['health_check_email'] ) ) : '';

		update_option( WP_SSH_OPTION, $settings );

		wp_safe_redirect( add_query_arg( array( 'page' => 'wp-ssh', 'saved' => '1' ), admin_url( 'tools.php' ) ) );
		exit;
	}

	public static function handle_regenerate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wp_ssh_regenerate' );

		$settings               = get_option( WP_SSH_OPTION, array() );
		$settings['secret_key'] = wp_ssh_generate_key();
		update_option( WP_SSH_OPTION, $settings );

		wp_safe_redirect( add_query_arg( array( 'page' => 'wp-ssh', 'regenerated' => '1' ), admin_url( 'tools.php' ) ) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = get_option( WP_SSH_OPTION, array() );
		$enabled  = ! empty( $settings['enabled'] );
		$key      = isset( $settings['secret_key'] ) ? $settings['secret_key'] : '';
		$log      = isset( $settings['log'] ) && is_array( $settings['log'] ) ? $settings['log'] : array();
		$site_url = home_url();
		$endpoint = rest_url( 'wp-ssh/v1/execute' );
		?>
		<div class="wrap">
			<h1>WP SSH — Remote Management Bridge</h1>
			<p>Site detected automatically: <strong><?php echo esc_html( ! empty( $settings['site_label'] ) ? $settings['site_label'] : get_bloginfo( 'name' ) ); ?></strong> (<?php echo esc_html( $site_url ); ?>)</p>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['regenerated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Secret key regenerated. Update anywhere you were using the old key.</p></div>
			<?php endif; ?>

			<?php if ( ! $enabled ) : ?>
				<div class="notice notice-warning"><p>The bridge is currently <strong>disabled</strong>. No requests will be accepted until you enable it below.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wp_ssh_save' ); ?>
				<input type="hidden" name="action" value="wp_ssh_save"/>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Site label</th>
						<td>
							<input type="text" name="site_label" value="<?php echo esc_attr( isset( $settings['site_label'] ) ? $settings['site_label'] : '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"/>
							<p class="description">Optional friendly name shown in <code>ping</code>/<code>site_info</code> responses — useful when you manage several sites and want to tell them apart at a glance.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Enable bridge</th>
						<td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?> /> Accept authenticated requests on this site</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Endpoint URL</th>
						<td><code><?php echo esc_html( $endpoint ); ?></code></td>
					</tr>
					<tr>
						<th scope="row">Secret key</th>
						<td>
							<input type="text" readonly value="<?php echo esc_attr( $key ); ?>" class="regular-text" style="width:420px;font-family:monospace;" onclick="this.select();"/>
							<p class="description">Send this in the <code>X-WPSSH-Key</code> header on every request. Keep it private — anyone with this key can perform the actions below.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Read-only mode</th>
						<td>
							<label><input type="checkbox" name="read_only_mode" value="1" <?php checked( ! empty( $settings['read_only_mode'] ) ); ?> /> Block every write action (options, posts, files, cache, DB, etc.) — safe for pure browsing/diagnostics</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Allow write queries</th>
						<td>
							<label><input type="checkbox" name="allow_write_queries" value="1" <?php checked( ! empty( $settings['allow_write_queries'] ) ); ?> /> Allow INSERT/UPDATE/DELETE via the db_query action (off = read-only SQL)</label>
						</td>
					</tr>
					<tr>
						<th scope="row">IP allowlist (optional)</th>
						<td>
							<textarea name="ip_allowlist" rows="4" class="large-text code" placeholder="One IP per line. Leave empty to allow any IP (still requires the secret key)."><?php echo esc_textarea( isset( $settings['ip_allowlist'] ) ? $settings['ip_allowlist'] : '' ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row">Health check emails</th>
						<td>
							<label><input type="checkbox" name="health_check_enabled" value="1" <?php checked( ! empty( $settings['health_check_enabled'] ) ); ?> /> Run an hourly self-check (DB connection, PHP fatals in debug.log, low disk space, bridge disabled) and email me if something's wrong</label><br/>
							<input type="email" name="health_check_email" value="<?php echo esc_attr( isset( $settings['health_check_email'] ) ? $settings['health_check_email'] : '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'admin_email' ) ); ?>" style="margin-top:6px;"/>
							<p class="description">Leave blank to use the site's admin email.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Regenerate the secret key? Anything using the old key will stop working.');">
				<?php wp_nonce_field( 'wp_ssh_regenerate' ); ?>
				<input type="hidden" name="action" value="wp_ssh_regenerate"/>
				<?php submit_button( 'Regenerate Secret Key', 'delete' ); ?>
			</form>

			<?php if ( ! empty( $settings['last_health_check'] ) ) :
				$hc = $settings['last_health_check'];
				?>
				<h2>Last health check</h2>
				<?php if ( ! empty( $hc['ok'] ) ) : ?>
					<div class="notice notice-success inline"><p>All good as of <?php echo esc_html( $hc['checked_at'] ); ?>.</p></div>
				<?php else : ?>
					<div class="notice notice-error inline">
						<p><strong>Issues found</strong> as of <?php echo esc_html( $hc['checked_at'] ); ?>:</p>
						<ul style="list-style:disc;margin-left:20px;">
							<?php foreach ( (array) $hc['issues'] as $issue ) : ?>
								<li><?php echo esc_html( $issue ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<h2>Recent activity</h2>
			<?php if ( empty( $log ) ) : ?>
				<p>No actions logged yet.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr><th>Time</th><th>IP</th><th>Action</th><th>Detail</th></tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( $entry['time'] ); ?></td>
								<td><?php echo esc_html( $entry['ip'] ); ?></td>
								<td><?php echo esc_html( $entry['action'] ); ?></td>
								<td><?php echo esc_html( $entry['summary'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>Available actions</h2>
			<p>POST JSON to the endpoint above with <code>X-WPSSH-Key</code> header set, body <code>{"action": "...", "params": {...}}</code>.</p>
			<ul style="columns:2;">
				<?php foreach ( array_keys( WP_SSH_Actions::map() ) as $a ) : ?>
					<li><code><?php echo esc_html( $a ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}

WP_SSH_Admin::init();
