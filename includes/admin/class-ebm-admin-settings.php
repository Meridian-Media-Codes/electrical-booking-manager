<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Admin_Settings {
	const SHARED_CALENDARS_TRANSIENT = 'ebm_google_shared_calendars';

	public static function init() {
		add_action( 'admin_post_ebm_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_ebm_google_load_calendars', array( __CLASS__, 'load_google_calendars' ) );
		add_action( 'admin_post_ebm_google_test_calendar', array( __CLASS__, 'test_google_calendar' ) );
	}

	private static function redirect_with_google_state( $state, $message = '' ) {
		$url = admin_url( 'admin.php?page=ebm-settings&google=' . rawurlencode( $state ) );

		if ( '' !== $message ) {
			$url = add_query_arg(
				'google_error',
				rawurlencode( $message ),
				$url
			);
		}

		wp_safe_redirect( $url );
		exit;
	}

	public static function load_google_calendars() {
		EBM_Admin::cap();

		if ( ! check_admin_referer( 'ebm_google_load_calendars' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'electrical-booking-manager' ) );
		}

		delete_transient( self::SHARED_CALENDARS_TRANSIENT );

		$calendars = EBM_Google::shared_calendars();

		if ( is_wp_error( $calendars ) ) {
			self::redirect_with_google_state(
				'calendar_list_failed',
				$calendars->get_error_message()
			);
		}

		set_transient( self::SHARED_CALENDARS_TRANSIENT, $calendars, 30 * MINUTE_IN_SECONDS );

		self::redirect_with_google_state( 'calendar_list_loaded' );
	}

	public static function test_google_calendar() {
		EBM_Admin::cap();

		if ( ! check_admin_referer( 'ebm_google_test_calendar' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'electrical-booking-manager' ) );
		}

		$result = EBM_Google::test_saved_calendar();

		if ( is_wp_error( $result ) ) {
			self::redirect_with_google_state(
				'calendar_test_failed',
				$result->get_error_message()
			);
		}

		self::redirect_with_google_state( 'calendar_test_success' );
	}

	public static function render() {
		EBM_Admin::cap();

		$settings          = EBM_Settings::all();
		$redirect_uri      = EBM_Google::redirect_uri();
		$google_state      = sanitize_key( wp_unslash( $_GET['google'] ?? '' ) );
		$google_error      = sanitize_text_field( wp_unslash( $_GET['google_error'] ?? '' ) );
		$auth_mode         = EBM_Google::auth_mode();
		$service_email     = EBM_Google::service_account_email();
		$shared_calendars  = get_transient( self::SHARED_CALENDARS_TRANSIENT );
		$shared_calendars  = is_array( $shared_calendars ) ? $shared_calendars : array();
		$last_google_error = EBM_Google::last_error();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Electrical Booking Settings', 'electrical-booking-manager' ); ?></h1>

			<?php EBM_Admin_Notices::render(); ?>

			<?php if ( 'connected' === $google_state ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google Calendar connected.', 'electrical-booking-manager' ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'disconnected' === $google_state ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Google Calendar disconnected.', 'electrical-booking-manager' ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'missing_client_id' === $google_state ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Add your Google client ID before connecting Google Calendar.', 'electrical-booking-manager' ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'calendar_list_loaded' === $google_state ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Shared Google calendars loaded. Select the correct calendar below, then save settings.', 'electrical-booking-manager' ); ?></p></div>
			<?php endif; ?>

			<?php if ( 'calendar_test_success' === $google_state ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The saved Google Calendar was tested successfully.', 'electrical-booking-manager' ); ?></p></div>
			<?php endif; ?>

			<?php if ( in_array( $google_state, array( 'failed', 'missing_code', 'calendar_list_failed', 'calendar_test_failed' ), true ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'Google Calendar issue.', 'electrical-booking-manager' ); ?></strong></p>
					<?php if ( $google_error ) : ?>
						<p><?php echo esc_html( $google_error ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $last_google_error['message'] ) ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p>
						<strong><?php esc_html_e( 'Last Google Calendar error:', 'electrical-booking-manager' ); ?></strong>
						<?php echo esc_html( $last_google_error['message'] ); ?>
					</p>
					<?php if ( ! empty( $last_google_error['time'] ) ) : ?>
						<p><?php echo esc_html( $last_google_error['time'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ebm_save_settings">
				<?php wp_nonce_field( 'ebm_save_settings' ); ?>

				<h2><?php esc_html_e( 'Business settings', 'electrical-booking-manager' ); ?></h2>

				<table class="form-table">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Business name', 'electrical-booking-manager' ); ?></th>
							<td><input name="business_name" class="regular-text" value="<?php echo esc_attr( $settings['business_name'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Admin email', 'electrical-booking-manager' ); ?></th>
							<td><input name="admin_email" class="regular-text" value="<?php echo esc_attr( $settings['admin_email'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Business days', 'electrical-booking-manager' ); ?></th>
							<td>
								<?php foreach ( array( '0' => 'Sun', '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat' ) as $number => $label ) : ?>
									<label style="margin-right:12px;">
										<input type="checkbox" name="business_days[]" value="<?php echo esc_attr( $number ); ?>" <?php checked( in_array( $number, $settings['business_days'], true ) ); ?>>
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Hours', 'electrical-booking-manager' ); ?></th>
							<td>
								<input type="time" name="work_start" value="<?php echo esc_attr( $settings['work_start'] ); ?>">
								<?php esc_html_e( 'to', 'electrical-booking-manager' ); ?>
								<input type="time" name="work_end" value="<?php echo esc_attr( $settings['work_end'] ); ?>">
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Buffer minutes', 'electrical-booking-manager' ); ?></th>
							<td><input type="number" name="buffer_minutes" value="<?php echo esc_attr( $settings['buffer_minutes'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Max bookings per slot', 'electrical-booking-manager' ); ?></th>
							<td><input type="number" min="1" name="max_bookings_per_slot" value="<?php echo esc_attr( $settings['max_bookings_per_slot'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Holidays', 'electrical-booking-manager' ); ?></th>
							<td>
								<textarea name="holidays" rows="5" class="large-text"><?php echo esc_textarea( $settings['holidays'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One YYYY-MM-DD date per line.', 'electrical-booking-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Global deposit', 'electrical-booking-manager' ); ?></th>
							<td>
								<select name="global_deposit_type">
									<option value="percent" <?php selected( $settings['global_deposit_type'], 'percent' ); ?>><?php esc_html_e( 'Percent', 'electrical-booking-manager' ); ?></option>
									<option value="fixed" <?php selected( $settings['global_deposit_type'], 'fixed' ); ?>><?php esc_html_e( 'Fixed', 'electrical-booking-manager' ); ?></option>
								</select>
								<input type="number" step="0.01" name="global_deposit_value" value="<?php echo esc_attr( $settings['global_deposit_value'] ); ?>">
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Service area and address lookup', 'electrical-booking-manager' ); ?></h2>

				<table class="form-table">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Allowed postcode prefixes', 'electrical-booking-manager' ); ?></th>
							<td>
								<input name="allowed_postcode_prefixes" class="regular-text" value="<?php echo esc_attr( $settings['allowed_postcode_prefixes'] ?? 'FY' ); ?>">
								<p class="description">
									<?php esc_html_e( 'Comma separated. Example: FY. The booking form will block addresses outside these postcode prefixes.', 'electrical-booking-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google Places browser API key', 'electrical-booking-manager' ); ?></th>
							<td>
								<input name="google_places_api_key" class="regular-text" value="<?php echo esc_attr( $settings['google_places_api_key'] ?? '' ); ?>">
								<p class="description">
									<?php esc_html_e( 'Used on the front end for address autocomplete. Restrict this key to your website domain and to Maps JavaScript API / Places API only.', 'electrical-booking-manager' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Stripe', 'electrical-booking-manager' ); ?></h2>

				<table class="form-table">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Stripe publishable key', 'electrical-booking-manager' ); ?></th>
							<td><input name="stripe_publishable_key" class="regular-text" value="<?php echo esc_attr( $settings['stripe_publishable_key'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Stripe secret key', 'electrical-booking-manager' ); ?></th>
							<td><input name="stripe_secret_key" type="password" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'electrical-booking-manager' ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Stripe webhook secret', 'electrical-booking-manager' ); ?></th>
							<td><input name="stripe_webhook_secret" type="password" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'electrical-booking-manager' ); ?>"></td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Google Calendar', 'electrical-booking-manager' ); ?></h2>

				<table class="form-table">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Connection method', 'electrical-booking-manager' ); ?></th>
							<td>
								<select name="google_auth_mode">
									<option value="service_account" <?php selected( $auth_mode, 'service_account' ); ?>>
										<?php esc_html_e( 'Service account', 'electrical-booking-manager' ); ?>
									</option>
									<option value="oauth" <?php selected( $auth_mode, 'oauth' ); ?>>
										<?php esc_html_e( 'OAuth fallback', 'electrical-booking-manager' ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'Use service account for the stable booking calendar connection. OAuth is kept only as a fallback.', 'electrical-booking-manager' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Service account JSON', 'electrical-booking-manager' ); ?></th>
							<td>
								<textarea name="google_service_account_json" rows="8" class="large-text code" placeholder="<?php esc_attr_e( 'Paste the full Google service account JSON key here. Leave blank to keep the saved key.', 'electrical-booking-manager' ); ?>"></textarea>

								<?php if ( '' !== $service_email ) : ?>
									<p>
										<strong><?php esc_html_e( 'Saved service account email:', 'electrical-booking-manager' ); ?></strong><br>
										<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $service_email ); ?>" onclick="this.select();">
									</p>
									<p class="description">
										<?php esc_html_e( 'Send this email address to the client. They must share the booking calendar with it and set the permission to Make changes to events.', 'electrical-booking-manager' ); ?>
									</p>
								<?php else : ?>
									<p class="description">
										<?php esc_html_e( 'After you save the JSON key, the service account email will appear here.', 'electrical-booking-manager' ); ?>
									</p>
								<?php endif; ?>

								<label>
									<input type="checkbox" name="google_service_account_clear" value="1">
									<?php esc_html_e( 'Clear saved service account JSON', 'electrical-booking-manager' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Shared calendars', 'electrical-booking-manager' ); ?></th>
							<td>
								<?php if ( ! empty( $shared_calendars ) ) : ?>
									<select name="google_calendar_id" class="regular-text">
										<option value="">
											<?php esc_html_e( 'Select a shared calendar', 'electrical-booking-manager' ); ?>
										</option>

										<?php foreach ( $shared_calendars as $calendar ) : ?>
											<?php
											$calendar_id      = sanitize_text_field( $calendar['id'] ?? '' );
											$calendar_summary = sanitize_text_field( $calendar['summary'] ?? $calendar_id );
											$access_role      = sanitize_text_field( $calendar['access_role'] ?? '' );
											$can_write        = ! empty( $calendar['can_write'] );
											$label            = $calendar_summary . ' - ' . $calendar_id;

											if ( $access_role ) {
												$label .= ' - ' . $access_role;
											}

											if ( ! $can_write ) {
												$label .= ' - read only';
											}
											?>
											<option value="<?php echo esc_attr( $calendar_id ); ?>" <?php selected( $settings['google_calendar_id'], $calendar_id ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">
										<?php esc_html_e( 'After loading calendars, select the booking calendar and save settings.', 'electrical-booking-manager' ); ?>
									</p>
								<?php else : ?>
									<input name="google_calendar_id" class="regular-text" value="<?php echo esc_attr( $settings['google_calendar_id'] ); ?>">
									<p class="description">
										<?php esc_html_e( 'No shared calendars have been loaded yet. Save the service account JSON, ask the client to share their calendar, then click Load shared calendars.', 'electrical-booking-manager' ); ?>
									</p>
								<?php endif; ?>

								<p>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_google_load_calendars' ), 'ebm_google_load_calendars' ) ); ?>">
										<?php esc_html_e( 'Load shared calendars', 'electrical-booking-manager' ); ?>
									</a>

									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_google_test_calendar' ), 'ebm_google_test_calendar' ) ); ?>">
										<?php esc_html_e( 'Test saved calendar', 'electrical-booking-manager' ); ?>
									</a>
								</p>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google connection status', 'electrical-booking-manager' ); ?></th>
							<td>
								<?php if ( EBM_Google::connected() ) : ?>
									<strong style="color:#008a20;"><?php esc_html_e( 'Connected', 'electrical-booking-manager' ); ?></strong>
								<?php else : ?>
									<strong style="color:#b32d2e;"><?php esc_html_e( 'Not connected', 'electrical-booking-manager' ); ?></strong>
								<?php endif; ?>

								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_google_disconnect' ), 'ebm_google_disconnect' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Clear the saved Google Calendar connection from this site?', 'electrical-booking-manager' ) ); ?>');">
									<?php esc_html_e( 'Clear saved Google connection', 'electrical-booking-manager' ); ?>
								</a>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'OAuth fallback settings', 'electrical-booking-manager' ); ?></th>
							<td>
								<details>
									<summary><?php esc_html_e( 'Show OAuth fallback fields', 'electrical-booking-manager' ); ?></summary>

									<p>
										<label>
											<?php esc_html_e( 'Google client ID', 'electrical-booking-manager' ); ?><br>
											<input name="google_client_id" class="regular-text" value="<?php echo esc_attr( $settings['google_client_id'] ); ?>">
										</label>
									</p>

									<p>
										<label>
											<?php esc_html_e( 'Google client secret', 'electrical-booking-manager' ); ?><br>
											<input name="google_client_secret" type="password" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'electrical-booking-manager' ); ?>">
										</label>
									</p>

									<p>
										<label>
											<?php esc_html_e( 'Google redirect URI', 'electrical-booking-manager' ); ?><br>
											<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $redirect_uri ); ?>" onclick="this.select();">
										</label>
									</p>

									<p>
										<?php if ( 'oauth' === $auth_mode && EBM_Google::connected() ) : ?>
											<strong style="color:#008a20;"><?php esc_html_e( 'OAuth connected', 'electrical-booking-manager' ); ?></strong>
										<?php else : ?>
											<strong style="color:#b32d2e;"><?php esc_html_e( 'OAuth not connected', 'electrical-booking-manager' ); ?></strong>
										<?php endif; ?>

										<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_google_connect' ), 'ebm_google_connect' ) ); ?>">
											<?php esc_html_e( 'Connect OAuth Google Calendar', 'electrical-booking-manager' ); ?>
										</a>
									</p>
								</details>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Email and privacy', 'electrical-booking-manager' ); ?></h2>

				<table class="form-table">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Email from name', 'electrical-booking-manager' ); ?></th>
							<td><input name="email_from_name" class="regular-text" value="<?php echo esc_attr( $settings['email_from_name'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Email from address', 'electrical-booking-manager' ); ?></th>
							<td><input name="email_from_address" class="regular-text" value="<?php echo esc_attr( $settings['email_from_address'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Privacy page URL', 'electrical-booking-manager' ); ?></th>
							<td><input name="privacy_page_url" class="regular-text" value="<?php echo esc_attr( $settings['privacy_page_url'] ); ?>"></td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>

			<div class="ebm-panel" style="margin-top:20px; max-width:900px;">
				<div class="ebm-panel-header">
					<h2><?php esc_html_e( 'Google setup notes', 'electrical-booking-manager' ); ?></h2>
				</div>

				<div class="ebm-panel-body">
					<h3><?php esc_html_e( 'Service account calendar setup', 'electrical-booking-manager' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Paste the Google service account JSON key into the settings above and save.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Copy the service account email that appears after saving.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Ask the client to share their booking calendar with that email.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'They must set the permission to Make changes to events.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Click Load shared calendars.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Select the correct shared calendar and save settings.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Click Test saved calendar.', 'electrical-booking-manager' ); ?></li>
					</ol>

					<h3><?php esc_html_e( 'Google Places address lookup', 'electrical-booking-manager' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Enable Maps JavaScript API and Places API in Google Cloud.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Create or use a browser API key.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Restrict the key to this website domain.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Restrict the key to Maps JavaScript API and Places API only.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Paste the key into Google Places browser API key above.', 'electrical-booking-manager' ); ?></li>
					</ol>

					<h3><?php esc_html_e( 'OAuth fallback', 'electrical-booking-manager' ); ?></h3>
					<p><?php esc_html_e( 'OAuth is still available as a fallback, but the service account route should be used for stable website booking calendar access.', 'electrical-booking-manager' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	public static function save() {
		EBM_Admin::cap();
		check_admin_referer( 'ebm_save_settings' );

		EBM_Settings::save( wp_unslash( $_POST ) );

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-settings&updated=1' ) );
		exit;
	}
}