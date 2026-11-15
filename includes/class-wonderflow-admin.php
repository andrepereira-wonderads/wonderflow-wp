<?php
/**
 * Admin pages: settings, connection test and send log.
 *
 * @package Wonderflow
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings -> Wonderflow.
 */
class Wonderflow_Admin {

	const SLUG = 'wonderflow';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_wonderflow_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_wonderflow_send_now', array( __CLASS__, 'handle_send_now' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WONDERFLOW_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_options_page( 'Wonderflow', 'Wonderflow', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * "Settings" link on the plugins list.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'wonderflow' ) . '</a>' );
		return $links;
	}

	/**
	 * Registers the option.
	 */
	public static function register() {
		register_setting(
			'wonderflow',
			Wonderflow_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Wonderflow_Settings', 'sanitize' ),
				'default'           => Wonderflow_Settings::defaults(),
			)
		);
	}

	/**
	 * "Test connection" button.
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'wonderflow' ) );
		}
		check_admin_referer( 'wonderflow_test' );
		set_transient( 'wonderflow_test_result', Wonderflow_Client::test_connection(), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * "Send now" button: sends the waiting rows in this request.
	 */
	public static function handle_send_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'wonderflow' ) );
		}
		check_admin_referer( 'wonderflow_send_now' );
		delete_transient( 'wonderflow_queue_lock' );
		Wonderflow_Queue::process( true );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Message of a connection test.
	 *
	 * @param array $result Test result.
	 * @return string
	 */
	private static function test_message( array $result ) {
		$messages = array(
			'ok'                        => __( 'Connected: the endpoint answered and the key is valid.', 'wonderflow' ),
			'not_configured'            => __( 'Fill in the endpoint and the server key first.', 'wonderflow' ),
			'network_error'             => __( 'The endpoint did not answer. Check the URL.', 'wonderflow' ),
			'missing_key'               => __( 'The key was not accepted. Create a new server key in the CRM.', 'wonderflow' ),
			'invalid_key'               => __( 'The key is invalid or revoked, or the clinic is suspended.', 'wonderflow' ),
			'server_key_in_browser'     => __( 'The request was refused as coming from a browser.', 'wonderflow' ),
			'plan_without_form_capture' => __( 'The clinic plan does not include form capture.', 'wonderflow' ),
			'rate_limited'              => __( 'Too many requests. Try again in a minute.', 'wonderflow' ),
		);
		return isset( $messages[ $result['code'] ] )
			? $messages[ $result['code'] ]
			/* translators: %s: error code. */
			: sprintf( __( 'Unexpected answer (%s).', 'wonderflow' ), $result['code'] );
	}

	/**
	 * Page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Wonderflow_Settings::all();
		$option   = Wonderflow_Settings::OPTION;
		$result   = get_transient( 'wonderflow_test_result' );
		if ( is_array( $result ) ) {
			delete_transient( 'wonderflow_test_result' );
		}
		?>
		<div class="wrap">
			<h1>Wonderflow</h1>
			<?php if ( is_array( $result ) ) : ?>
				<div class="notice notice-<?php echo $result['ok'] ? 'success' : 'error'; ?>"><p><?php echo esc_html( self::test_message( $result ) ); ?></p></div>
			<?php endif; ?>
			<?php settings_errors( $option ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'wonderflow' ); ?>
				<h2><?php esc_html_e( 'Connection', 'wonderflow' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wf-endpoint"><?php esc_html_e( 'Endpoint URL', 'wonderflow' ); ?></label></th>
						<td><input id="wf-endpoint" class="regular-text code" type="url" name="<?php echo esc_attr( $option ); ?>[endpoint]" value="<?php echo esc_attr( $settings['endpoint'] ); ?>" placeholder="https://<project>.supabase.co/functions/v1/ingest" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wf-key"><?php esc_html_e( 'Server key', 'wonderflow' ); ?></label></th>
						<td>
							<input id="wf-key" class="regular-text code" type="password" autocomplete="off" name="<?php echo esc_attr( $option ); ?>[server_key]" value="" placeholder="<?php echo '' !== $settings['server_key'] ? esc_attr__( 'Saved (leave empty to keep it)', 'wonderflow' ) : 'whk_...'; ?>" />
							<?php if ( '' !== $settings['server_key'] ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[forget_key]" value="1" /> <?php esc_html_e( 'Remove the saved key', 'wonderflow' ); ?></label>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Created in the CRM under Settings -> Integrations (server key). It stays on this server and is never sent to visitors.', 'wonderflow' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wf-country"><?php esc_html_e( 'Default country', 'wonderflow' ); ?></label></th>
						<td><input id="wf-country" class="small-text" type="text" maxlength="2" name="<?php echo esc_attr( $option ); ?>[country]" value="<?php echo esc_attr( $settings['country'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Used for phone numbers without a country code.', 'wonderflow' ); ?></p></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Forms', 'wonderflow' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Send forms', 'wonderflow' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[forms_enabled]" value="1" <?php checked( $settings['forms_enabled'] ); ?> /> <?php esc_html_e( 'Send Elementor Pro, Contact Form 7, WPForms, Gravity Forms and Fluent Forms submissions to the CRM', 'wonderflow' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="wf-version"><?php esc_html_e( 'Consent text version', 'wonderflow' ); ?></label></th>
						<td><input id="wf-version" class="regular-text" type="text" name="<?php echo esc_attr( $option ); ?>[consent_version]" value="<?php echo esc_attr( $settings['consent_version'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Stored with each consent. Change it whenever the consent text on the forms changes.', 'wonderflow' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wf-map"><?php esc_html_e( 'Field mapping (optional)', 'wonderflow' ); ?></label></th>
						<td><textarea id="wf-map" class="large-text code" rows="6" name="<?php echo esc_attr( $option ); ?>[field_map]"><?php echo esc_textarea( $settings['field_map'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Fields are detected by type and name. To set them by hand, use JSON per form, e.g. {"cf7:123": {"name": "your-name", "phone": "tel-1", "email": "your-email", "utility": "lembretes", "marketing": "novidades", "data_processing": "rgpd"}, "cf7:456": false}. false skips a form. Keys: cf7:<id>, elementor:<form name>, wpforms:<id>, gravity:<id>, fluent:<id>. The free-text message is never sent.', 'wonderflow' ); ?></p></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'WhatsApp', 'wonderflow' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Track clicks', 'wonderflow' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[whatsapp_enabled]" value="1" <?php checked( $settings['whatsapp_enabled'] ); ?> /> <?php esc_html_e( 'Add a Ref code to WhatsApp links and buttons and send the click (anonymous) to the CRM', 'wonderflow' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="wf-number"><?php esc_html_e( 'Clinic WhatsApp number', 'wonderflow' ); ?></label></th>
						<td><input id="wf-number" class="regular-text" type="text" name="<?php echo esc_attr( $option ); ?>[whatsapp_number]" value="<?php echo esc_attr( $settings['whatsapp_number'] ); ?>" placeholder="351912345678" />
						<p class="description"><?php esc_html_e( 'International format, digits only. Only links to this number get a Ref (empty: every WhatsApp link). Also used by the [wonderflow_whatsapp] shortcode.', 'wonderflow' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="wf-message"><?php esc_html_e( 'Prefilled message', 'wonderflow' ); ?></label></th>
						<td><input id="wf-message" class="regular-text" type="text" name="<?php echo esc_attr( $option ); ?>[whatsapp_message]" value="<?php echo esc_attr( $settings['whatsapp_message'] ); ?>" />
						<p class="description"><?php esc_html_e( '{ref} is replaced by the code. When a link already has a message, " Ref XXXXX" is added at its end.', 'wonderflow' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wonderflow_test" />
				<?php wp_nonce_field( 'wonderflow_test' ); ?>
				<?php submit_button( __( 'Test connection', 'wonderflow' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Latest sends', 'wonderflow' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Status only, kept for 30 days. Personal data is deleted from this site as soon as a send is settled.', 'wonderflow' ); ?></p>
			<?php if ( Wonderflow_Queue::pending_count() > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wonderflow_send_now" />
					<?php wp_nonce_field( 'wonderflow_send_now' ); ?>
					<?php submit_button( __( 'Send now', 'wonderflow' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<?php self::render_log(); ?>
		</div>
		<?php
	}

	/**
	 * Send log table.
	 */
	private static function render_log() {
		$rows = Wonderflow_Queue::latest();
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Nothing sent yet.', 'wonderflow' ) . '</p>';
			return;
		}
		$labels = array(
			'pending' => __( 'Waiting', 'wonderflow' ),
			'sent'    => __( 'Sent', 'wonderflow' ),
			'failed'  => __( 'Failed', 'wonderflow' ),
		);
		$kinds  = array(
			'lead'  => __( 'Form', 'wonderflow' ),
			'event' => __( 'WhatsApp click', 'wonderflow' ),
		);
		?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Date (UTC)', 'wonderflow' ); ?></th>
				<th><?php esc_html_e( 'Type', 'wonderflow' ); ?></th>
				<th><?php esc_html_e( 'Form', 'wonderflow' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wonderflow' ); ?></th>
				<th><?php esc_html_e( 'Attempts', 'wonderflow' ); ?></th>
				<th><?php esc_html_e( 'Code', 'wonderflow' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row->created_at ); ?></td>
					<td><?php echo esc_html( isset( $kinds[ $row->kind ] ) ? $kinds[ $row->kind ] : $row->kind ); ?></td>
					<td><?php echo esc_html( $row->form_label ); ?></td>
					<td><?php echo esc_html( isset( $labels[ $row->status ] ) ? $labels[ $row->status ] : $row->status ); ?></td>
					<td><?php echo esc_html( (string) $row->attempts ); ?></td>
					<td><code><?php echo esc_html( $row->last_code ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
