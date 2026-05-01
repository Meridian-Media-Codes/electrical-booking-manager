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
		$s = EBM_Settings::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Electrical Booking Settings', 'electrical-booking-manager' ); ?></h1>
			<?php EBM_Admin_Notices::render(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ebm_save_settings">
				<?php wp_nonce_field( 'ebm_save_settings' ); ?>

				<table class="form-table">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Business name', 'electrical-booking-manager' ); ?></th>
							<td><input name="business_name" class="regular-text" value="<?php echo esc_attr( $s['business_name'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Admin email', 'electrical-booking-manager' ); ?></th>
							<td><input name="admin_email" class="regular-text" value="<?php echo esc_attr( $s['admin_email'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Business days', 'electrical-booking-manager' ); ?></th>
							<td>
								<?php foreach ( array( '0' => 'Sun', '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat' ) as $n => $l ) : ?>
									<label>
										<input type="checkbox" name="business_days[]" value="<?php echo esc_attr( $n ); ?>" <?php checked( in_array( $n, $s['business_days'], true ) ); ?>>
										<?php echo esc_html( $l ); ?>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Hours', 'electrical-booking-manager' ); ?></th>
							<td>
								<input type="time" name="work_start" value="<?php echo esc_attr( $s['work_start'] ); ?>">
								<?php esc_html_e( 'to', 'electrical-booking-manager' ); ?>
								<input type="time" name="work_end" value="<?php echo esc_attr( $s['work_end'] ); ?>">
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Buffer minutes', 'electrical-booking-manager' ); ?></th>
							<td><input type="number" name="buffer_minutes" value="<?php echo esc_attr( $s['buffer_minutes'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Max bookings per slot', 'electrical-booking-manager' ); ?></th>
							<td><input type="number" min="1" name="max_bookings_per_slot" value="<?php echo esc_attr( $s['max_bookings_per_slot'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Holidays', 'electrical-booking-manager' ); ?></th>
							<td>
								<textarea name="holidays" rows="5" class="large-text"><?php echo esc_textarea( $s['holidays'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One YYYY-MM-DD date per line.', 'electrical-booking-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Global deposit', 'electrical-booking-manager' ); ?></th>
							<td>
								<select name="global_deposit_type">
									<option value="percent" <?php selected( $s['global_deposit_type'], 'percent' ); ?>><?php esc_html_e( 'Percent', 'electrical-booking-manager' ); ?></option>
									<option value="fixed" <?php selected( $s['global_deposit_type'], 'fixed' ); ?>><?php esc_html_e( 'Fixed', 'electrical-booking-manager' ); ?></option>
								</select>
								<input type="number" step="0.01" name="global_deposit_value" value="<?php echo esc_attr( $s['global_deposit_value'] ); ?>">
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Stripe publishable key', 'electrical-booking-manager' ); ?></th>
							<td><input name="stripe_publishable_key" class="regular-text" value="<?php echo esc_attr( $s['stripe_publishable_key'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Stripe secret key', 'electrical-booking-manager' ); ?></th>
							<td><input name="stripe_secret_key" type="password" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'electrical-booking-manager' ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Stripe webhook secret', 'electrical-booking-manager' ); ?></th>
							<td><input name="stripe_webhook_secret" type="password" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'electrical-booking-manager' ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google client ID', 'electrical-booking-manager' ); ?></th>
							<td><input name="google_client_id" class="regular-text" value="<?php echo esc_attr( $s['google_client_id'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google client secret', 'electrical-booking-manager' ); ?></th>
							<td><input name="google_client_secret" type="password" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'electrical-booking-manager' ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google calendar ID', 'electrical-booking-manager' ); ?></th>
							<td><input name="google_calendar_id" class="regular-text" value="<?php echo esc_attr( $s['google_calendar_id'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google OAuth', 'electrical-booking-manager' ); ?></th>
							<td>
								<?php if ( EBM_Google::connected() ) : ?>
									<strong><?php esc_html_e( 'Connected', 'electrical-booking-manager' ); ?></strong>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_google_disconnect' ), 'ebm_google_disconnect' ) ); ?>">
										<?php esc_html_e( 'Disconnect', 'electrical-booking-manager' ); ?>
									</a>
								<?php else : ?>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_google_connect' ), 'ebm_google_connect' ) ); ?>">
										<?php esc_html_e( 'Connect Google Calendar', 'electrical-booking-manager' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
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
