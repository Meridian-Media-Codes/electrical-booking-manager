<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EBM_Admin_Bookings {
	public static function init() {
		add_action( 'admin_post_ebm_update_booking', array( __CLASS__, 'update' ) );
	}

	public static function render() {
		EBM_Admin::cap();
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT b.*, j.title job_title, c.name, c.email, c.phone
			FROM ' . EBM_Helpers::table( 'bookings' ) . ' b
			INNER JOIN ' . EBM_Helpers::table( 'jobs' ) . ' j ON j.id = b.job_id
			INNER JOIN ' . EBM_Helpers::table( 'customers' ) . ' c ON c.id = b.customer_id
			ORDER BY b.start_at DESC
			LIMIT 200'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bookings', 'electrical-booking-manager' ); ?></h1>
			<?php EBM_Admin_Notices::render(); ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Date', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Job', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Total', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Action', 'electrical-booking-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No bookings yet.', 'electrical-booking-manager' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $b ) : ?>
						<tr>
							<td><?php echo esc_html( $b->id ); ?></td>
							<td><?php echo esc_html( mysql2date( 'd M Y H:i', $b->start_at ) ); ?></td>
							<td><?php echo esc_html( $b->name ); ?><br><small><?php echo esc_html( $b->email ); ?></small></td>
							<td><?php echo esc_html( $b->job_title ); ?></td>
							<td><?php echo esc_html( $b->status ); ?></td>
							<td><?php echo esc_html( EBM_Helpers::money( $b->total_amount ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="ebm_update_booking">
									<input type="hidden" name="booking_id" value="<?php echo esc_attr( $b->id ); ?>">
									<?php wp_nonce_field( 'ebm_update_booking_' . $b->id ); ?>
									<select name="status">
										<option value="confirmed" <?php selected( $b->status, 'confirmed' ); ?>><?php esc_html_e( 'Confirmed', 'electrical-booking-manager' ); ?></option>
										<option value="completed" <?php selected( $b->status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'electrical-booking-manager' ); ?></option>
										<option value="cancelled" <?php selected( $b->status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'electrical-booking-manager' ); ?></option>
									</select>
									<button class="button"><?php esc_html_e( 'Update', 'electrical-booking-manager' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function update() {
		EBM_Admin::cap();
		$id = absint( $_POST['booking_id'] ?? 0 );
		check_admin_referer( 'ebm_update_booking_' . $id );
		$status = sanitize_key( $_POST['status'] ?? '' );

		if ( ! in_array( $status, array( 'confirmed', 'completed', 'cancelled' ), true ) ) {
			wp_die( esc_html__( 'Invalid status.', 'electrical-booking-manager' ) );
		}

		global $wpdb;
		$wpdb->update(
			EBM_Helpers::table( 'bookings' ),
			array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( 'completed' === $status ) {
			$b = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . EBM_Helpers::table( 'bookings' ) . ' WHERE id = %d', $id ) );
			if ( $b && (float) $b->balance_amount > 0 ) {
				$session = EBM_Stripe::checkout( $id, (float) $b->balance_amount, 'balance' );
				if ( ! is_wp_error( $session ) && ! empty( $session['url'] ) ) {
					EBM_Emails::balance( $id, $session['url'] );
				}
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-bookings&updated=1' ) );
		exit;
	}
}
