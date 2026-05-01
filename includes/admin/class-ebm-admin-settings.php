<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Admin_Settings {
	public static function init() {
		add_action( 'admin_post_ebm_save_settings', array( __CLASS__, 'save' ) );
	}

	public static function render() {
		EBM_Admin::cap();

		$settings     = EBM_Settings::all();
		$redirect_uri = EBM_Google::redirect_uri();
		$google_state = sanitize_key( wp_unslash( $_GET['google'] ?? '' ) );
		$google_error = sanitize_text_field( wp_unslash( $_GET['google_error'] ?? '' ) );
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

			<?php if ( 'failed' === $google_state || 'missing_code' === $google_state ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><strong><?php esc_html_e( 'Google Calendar could not connect.', 'electrical-booking-manager' ); ?></strong></p>
					<?php if ( $google_error ) : ?>
						<p><?php echo esc_html( $google_error ); ?></p>
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
							<th><?php esc_html_e( 'Google client ID', 'electrical-booking-manager' ); ?></th>
							<td><input name="google_client_id" class="regular-text" value="<?php echo esc_attr( $settings['google_client_id'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google client secret', 'electrical-booking-manager' ); ?></th>
							<td><input name="google_client_secret" type="password" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'electrical-booking-manager' ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google calendar ID', 'electrical-booking-manager' ); ?></th>
							<td>
								<input name="google_calendar_id" class="regular-text" value="<?php echo esc_attr( $settings['google_calendar_id'] ); ?>">
								<p class="description"><?php esc_html_e( 'Use primary for the main calendar, or paste a specific Google Calendar ID.', 'electrical-booking-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google redirect URI', 'electrical-booking-manager' ); ?></th>
							<td>
								<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $redirect_uri ); ?>" onclick="this.select();">
								<p class="description"><?php esc_html_e( 'Copy this exact URL into Google Cloud Console under Authorized redirect URIs. It must match character for character.', 'electrical-booking-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google OAuth', 'electrical-booking-manager' ); ?></th>
							<td>
								<?php if ( EBM_Google::connected() ) : ?>
									<strong style="color:#008a20;"><?php esc_html_e( 'Connected', 'electrical-booking-manager' ); ?></strong>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_google_disconnect' ), 'ebm_google_disconnect' ) ); ?>">
										<?php esc_html_e( 'Disconnect Calendar', 'electrical-booking-manager' ); ?>
									</a>
								<?php else : ?>
									<strong style="color:#b32d2e;"><?php esc_html_e( 'Not connected', 'electrical-booking-manager' ); ?></strong>
									<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_google_connect' ), 'ebm_google_connect' ) ); ?>">
										<?php esc_html_e( 'Connect Google Calendar', 'electrical-booking-manager' ); ?>
									</a>
								<?php endif; ?>

								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_google_disconnect' ), 'ebm_google_disconnect' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Clear the saved Google Calendar token from this site?', 'electrical-booking-manager' ) ); ?>');">
									<?php esc_html_e( 'Clear saved Google token', 'electrical-booking-manager' ); ?>
								</a>
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
					<h3><?php esc_html_e( 'Google Calendar OAuth', 'electrical-booking-manager' ); ?></h3>
					<p><?php esc_html_e( 'The Google error redirect_uri_mismatch means the redirect URI saved in Google Cloud Console does not match this site.', 'electrical-booking-manager' ); ?></p>

					<ol>
						<li><?php esc_html_e( 'Open Google Cloud Console.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Go to APIs & Services, then Credentials.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Open your OAuth 2.0 Client ID.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Under Authorized redirect URIs, add the exact URL shown above.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Save, wait a minute, then try Connect Google Calendar again.', 'electrical-booking-manager' ); ?></li>
					</ol>

					<h3><?php esc_html_e( 'Google Places address lookup', 'electrical-booking-manager' ); ?></h3>
					<ol>
						<li><?php esc_html_e( 'Enable Maps JavaScript API and Places API in Google Cloud.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Create or use a browser API key.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Restrict the key to this website domain.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Restrict the key to Maps JavaScript API and Places API only.', 'electrical-booking-manager' ); ?></li>
						<li><?php esc_html_e( 'Paste the key into Google Places browser API key above.', 'electrical-booking-manager' ); ?></li>
					</ol>
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