<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Admin_Bookings {
	public static function init() {
		add_action( 'admin_post_ebm_update_booking', array( __CLASS__, 'update' ) );
		add_action( 'admin_post_ebm_delete_booking', array( __CLASS__, 'delete' ) );
	}

	private static function statuses() {
		return array(
			'pending_payment' => __( 'Pending payment', 'electrical-booking-manager' ),
			'confirmed'       => __( 'Confirmed', 'electrical-booking-manager' ),
			'completed'       => __( 'Completed', 'electrical-booking-manager' ),
			'cancelled'       => __( 'Cancelled', 'electrical-booking-manager' ),
		);
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

		$statuses = self::statuses();
		?>
		<div class="wrap ebm-admin-shell">
			<h1><?php esc_html_e( 'Bookings', 'electrical-booking-manager' ); ?></h1>

			<?php EBM_Admin_Notices::render(); ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Date and time', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Job', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Total', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Google', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Action', 'electrical-booking-manager' ); ?></th>
					</tr>
				</thead>

				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'No bookings yet.', 'electrical-booking-manager' ); ?></td>
						</tr>
					<?php endif; ?>

					<?php foreach ( $rows as $booking ) : ?>
						<?php
						$date_value = mysql2date( 'Y-m-d', $booking->start_at, false );
						$time_value = mysql2date( 'H:i', $booking->start_at, false );
						?>
						<tr>
							<td><?php echo esc_html( $booking->id ); ?></td>

							<td>
								<strong><?php echo esc_html( mysql2date( 'd M Y H:i', $booking->start_at ) ); ?></strong><br>
								<small>
									<?php
									printf(
										esc_html__( 'Duration: %s', 'electrical-booking-manager' ),
										esc_html( EBM_Admin::format_duration( $booking->total_minutes ) )
									);
									?>
								</small>
							</td>

							<td>
								<?php echo esc_html( $booking->name ); ?><br>
								<small><?php echo esc_html( $booking->email ); ?></small>
							</td>

							<td><?php echo esc_html( $booking->job_title ); ?></td>

							<td><?php echo esc_html( $statuses[ $booking->status ] ?? $booking->status ); ?></td>

							<td><?php echo esc_html( EBM_Helpers::money( $booking->total_amount ) ); ?></td>

							<td>
								<?php if ( ! empty( $booking->google_event_id ) ) : ?>
									<span style="color:#137333;"><?php esc_html_e( 'Linked', 'electrical-booking-manager' ); ?></span>
								<?php elseif ( 'pending_payment' === $booking->status ) : ?>
									<span style="color:#b54708;"><?php esc_html_e( 'Waiting for payment', 'electrical-booking-manager' ); ?></span>
								<?php else : ?>
									<span style="color:#646970;"><?php esc_html_e( 'Not linked', 'electrical-booking-manager' ); ?></span>
								<?php endif; ?>
							</td>

							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
									<input type="hidden" name="action" value="ebm_update_booking">
									<input type="hidden" name="booking_id" value="<?php echo esc_attr( $booking->id ); ?>">
									<?php wp_nonce_field( 'ebm_update_booking_' . $booking->id ); ?>

									<input type="date" name="booking_date" value="<?php echo esc_attr( $date_value ); ?>">
									<input type="time" name="booking_time" value="<?php echo esc_attr( $time_value ); ?>">

									<select name="status">
										<?php foreach ( $statuses as $status_key => $status_label ) : ?>
											<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $booking->status, $status_key ); ?>>
												<?php echo esc_html( $status_label ); ?>
											</option>
										<?php endforeach; ?>
									</select>

									<button class="button"><?php esc_html_e( 'Update', 'electrical-booking-manager' ); ?></button>

									<a
										class="button button-link-delete"
										href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_delete_booking&booking_id=' . absint( $booking->id ) ), 'ebm_delete_booking_' . absint( $booking->id ) ) ); ?>"
										onclick="return confirm('<?php echo esc_js( __( 'Delete this booking? This cannot be undone.', 'electrical-booking-manager' ) ); ?>');"
									>
										<?php esc_html_e( 'Delete', 'electrical-booking-manager' ); ?>
									</a>
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

		if ( ! array_key_exists( $status, self::statuses() ) ) {
			wp_die( esc_html__( 'Invalid status.', 'electrical-booking-manager' ) );
		}

		$date = sanitize_text_field( wp_unslash( $_POST['booking_date'] ?? '' ) );
		$time = sanitize_text_field( wp_unslash( $_POST['booking_time'] ?? '' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			wp_die( esc_html__( 'Invalid date or time.', 'electrical-booking-manager' ) );
		}

		global $wpdb;

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . EBM_Helpers::table( 'bookings' ) . ' WHERE id = %d',
				$id
			)
		);

		if ( ! $booking ) {
			wp_die( esc_html__( 'Booking not found.', 'electrical-booking-manager' ) );
		}

		$segments = EBM_Scheduler::segments( $date, $time, (int) $booking->total_minutes );

		if ( empty( $segments ) ) {
			wp_die( esc_html__( 'The new date and time could not be scheduled.', 'electrical-booking-manager' ) );
		}

		if ( 'cancelled' !== $status && ! EBM_Scheduler::available( $segments, $id ) ) {
			wp_die( esc_html__( 'The new date and time is not available.', 'electrical-booking-manager' ) );
		}

		$date_changed = $booking->start_at !== $segments[0]['start_at'] || $booking->end_at !== end( $segments )['end_at'];

		$wpdb->update(
			EBM_Helpers::table( 'bookings' ),
			array(
				'status'     => $status,
				'start_at'   => $segments[0]['start_at'],
				'end_at'     => end( $segments )['end_at'],
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$wpdb->delete(
			EBM_Helpers::table( 'booking_days' ),
			array( 'booking_id' => $id ),
			array( '%d' )
		);

		foreach ( $segments as $segment ) {
			$wpdb->insert(
				EBM_Helpers::table( 'booking_days' ),
				array(
					'booking_id' => $id,
					'work_date'  => $segment['date'],
					'start_at'   => $segment['start_at'],
					'end_at'     => $segment['end_at'],
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}

		if ( class_exists( 'EBM_Google' ) && EBM_Google::connected() ) {
			if ( 'cancelled' === $status ) {
				if ( ! empty( $booking->google_event_id ) && method_exists( 'EBM_Google', 'delete_event' ) ) {
					EBM_Google::delete_event( $booking->google_event_id );
				}

				$wpdb->update(
					EBM_Helpers::table( 'bookings' ),
					array(
						'google_event_id' => '',
						'updated_at'      => current_time( 'mysql' ),
					),
					array( 'id' => $id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			} elseif ( in_array( $status, array( 'confirmed', 'completed' ), true ) ) {
				if ( $date_changed && ! empty( $booking->google_event_id ) && method_exists( 'EBM_Google', 'delete_event' ) ) {
					EBM_Google::delete_event( $booking->google_event_id );

					$wpdb->update(
						EBM_Helpers::table( 'bookings' ),
						array( 'google_event_id' => '' ),
						array( 'id' => $id ),
						array( '%s' ),
						array( '%d' )
					);
				}

				$fresh_booking = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT * FROM ' . EBM_Helpers::table( 'bookings' ) . ' WHERE id = %d',
						$id
					)
				);

				if ( empty( $fresh_booking->google_event_id ) ) {
					EBM_Google::create_event( $id );
				}
			}
		}

		if ( 'completed' === $status ) {
			$fresh_booking = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . EBM_Helpers::table( 'bookings' ) . ' WHERE id = %d',
					$id
				)
			);

			if ( $fresh_booking && (float) $fresh_booking->balance_amount > 0 ) {
				$session = EBM_Stripe::checkout( $id, (float) $fresh_booking->balance_amount, 'balance' );

				if ( ! is_wp_error( $session ) && ! empty( $session['url'] ) ) {
					EBM_Emails::balance( $id, $session['url'] );
				}
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-bookings&updated=1' ) );
		exit;
	}

	public static function delete() {
		EBM_Admin::cap();

		$id = absint( $_GET['booking_id'] ?? 0 );

		check_admin_referer( 'ebm_delete_booking_' . $id );

		if ( ! $id ) {
			wp_die( esc_html__( 'Invalid booking.', 'electrical-booking-manager' ) );
		}

		global $wpdb;

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . EBM_Helpers::table( 'bookings' ) . ' WHERE id = %d',
				$id
			)
		);

		if ( ! $booking ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ebm-bookings&deleted=1' ) );
			exit;
		}

		if ( ! empty( $booking->google_event_id ) && class_exists( 'EBM_Google' ) && EBM_Google::connected() && method_exists( 'EBM_Google', 'delete_event' ) ) {
			EBM_Google::delete_event( $booking->google_event_id );
		}

		$wpdb->delete(
			EBM_Helpers::table( 'booking_days' ),
			array( 'booking_id' => $id ),
			array( '%d' )
		);

		$wpdb->delete(
			EBM_Helpers::table( 'transactions' ),
			array( 'booking_id' => $id ),
			array( '%d' )
		);

		$wpdb->delete(
			EBM_Helpers::table( 'bookings' ),
			array( 'id' => $id ),
			array( '%d' )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-bookings&deleted=1' ) );
		exit;
	}
}