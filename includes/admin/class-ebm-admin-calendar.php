<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Admin_Calendar {
	public static function init() {}

	private static function event_fingerprint( $title, $start ) {
		$title = strtolower( trim( wp_strip_all_tags( (string) $title ) ) );
		$time  = strtotime( (string) $start );

		if ( ! $title || ! $time ) {
			return '';
		}

		return md5( $title . '|' . gmdate( 'Y-m-d H:i', $time ) );
	}

	public static function render() {
		EBM_Admin::cap();

		global $wpdb;

		$month = sanitize_text_field( wp_unslash( $_GET['month'] ?? gmdate( 'Y-m' ) ) );

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			$month = gmdate( 'Y-m' );
		}

		$timezone  = wp_timezone();
		$first_day = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $month . '-01 00:00:00', $timezone );

		if ( ! $first_day ) {
			$first_day = new DateTimeImmutable( 'first day of this month 00:00:00', $timezone );
		}

		$start_of_calendar = $first_day->modify( '-' . (int) $first_day->format( 'w' ) . ' days' );
		$last_day          = $first_day->modify( 'last day of this month' );
		$end_of_calendar   = $last_day->modify( '+' . ( 6 - (int) $last_day->format( 'w' ) ) . ' days' )->setTime( 23, 59, 59 );

		$db_start = $start_of_calendar->format( 'Y-m-d H:i:s' );
		$db_end   = $end_of_calendar->format( 'Y-m-d H:i:s' );

		$bookings_table  = EBM_Helpers::table( 'bookings' );
		$jobs_table      = EBM_Helpers::table( 'jobs' );
		$customers_table = EBM_Helpers::table( 'customers' );

		$bookings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.*, j.title AS job_title, c.name AS customer_name
				FROM $bookings_table b
				INNER JOIN $jobs_table j ON j.id = b.job_id
				INNER JOIN $customers_table c ON c.id = b.customer_id
				WHERE b.start_at <= %s
				AND b.end_at >= %s
				ORDER BY b.start_at ASC",
				$db_end,
				$db_start
			)
		);

		$events_by_day       = array();
		$plugin_google_ids   = array();
		$plugin_fingerprints = array();

		foreach ( $bookings as $booking ) {
			$day_key = mysql2date( 'Y-m-d', $booking->start_at, false );
			$title   = $booking->job_title;

			if ( ! empty( $booking->google_event_id ) ) {
				$plugin_google_ids[] = (string) $booking->google_event_id;
			}

			$plugin_fingerprints[] = self::event_fingerprint( 'Booking: ' . $booking->job_title, $booking->start_at );

			$events_by_day[ $day_key ][] = array(
				'type'     => 'booking',
				'status'   => sanitize_html_class( $booking->status ),
				'time'     => mysql2date( 'H:i', $booking->start_at, false ),
				'title'    => $title,
				'customer' => $booking->customer_name,
				'url'      => admin_url( 'admin.php?page=ebm-bookings&booking_id=' . absint( $booking->id ) ),
			);
		}

		$plugin_google_ids   = array_filter( array_unique( $plugin_google_ids ) );
		$plugin_fingerprints = array_filter( array_unique( $plugin_fingerprints ) );

		$google_error = '';

		if ( EBM_Google::connected() && method_exists( 'EBM_Google', 'events' ) ) {
			$google_response = EBM_Google::events( $db_start, $db_end );

			if ( is_wp_error( $google_response ) ) {
				$google_error = $google_response->get_error_message();
			} elseif ( is_array( $google_response ) ) {
				foreach ( $google_response as $event ) {
					$google_id = (string) ( $event['id'] ?? '' );
					$start     = $event['start'] ?? '';
					$title     = $event['summary'] ?? __( 'Google event', 'electrical-booking-manager' );

					if ( empty( $start ) ) {
						continue;
					}

					if ( $google_id && in_array( $google_id, $plugin_google_ids, true ) ) {
						continue;
					}

					if ( in_array( self::event_fingerprint( $title, $start ), $plugin_fingerprints, true ) ) {
						continue;
					}

					$timestamp = strtotime( $start );

					if ( ! $timestamp ) {
						continue;
					}

					$day_key = wp_date( 'Y-m-d', $timestamp, $timezone );

					$events_by_day[ $day_key ][] = array(
						'type'     => 'google',
						'status'   => 'google',
						'time'     => wp_date( 'H:i', $timestamp, $timezone ),
						'title'    => $title,
						'customer' => __( 'Google Calendar', 'electrical-booking-manager' ),
						'url'      => '',
					);
				}
			}
		}

		foreach ( $events_by_day as $day_key => $events ) {
			usort(
				$events,
				function( $a, $b ) {
					return strcmp( $a['time'], $b['time'] );
				}
			);

			$events_by_day[ $day_key ] = $events;
		}

		$prev_month = $first_day->modify( '-1 month' )->format( 'Y-m' );
		$next_month = $first_day->modify( '+1 month' )->format( 'Y-m' );
		$today      = wp_date( 'Y-m', null, $timezone );
		$today_day  = wp_date( 'Y-m-d', null, $timezone );

		$weekdays = array(
			__( 'Sun', 'electrical-booking-manager' ),
			__( 'Mon', 'electrical-booking-manager' ),
			__( 'Tue', 'electrical-booking-manager' ),
			__( 'Wed', 'electrical-booking-manager' ),
			__( 'Thu', 'electrical-booking-manager' ),
			__( 'Fri', 'electrical-booking-manager' ),
			__( 'Sat', 'electrical-booking-manager' ),
		);
		?>
		<div class="wrap ebm-admin-shell">
			<?php EBM_Admin_Notices::render(); ?>

			<div class="ebm-calendar-card">
				<div class="ebm-calendar-header">
					<h1><?php echo esc_html( $first_day->format( 'F Y' ) ); ?></h1>

					<div class="ebm-calendar-nav">
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ebm-calendar&month=' . $prev_month ) ); ?>"><?php esc_html_e( 'Previous', 'electrical-booking-manager' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ebm-calendar&month=' . $today ) ); ?>"><?php esc_html_e( 'Today', 'electrical-booking-manager' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ebm-calendar&month=' . $next_month ) ); ?>"><?php esc_html_e( 'Next', 'electrical-booking-manager' ); ?></a>
					</div>
				</div>

				<div class="ebm-calendar-legend">
					<span class="ebm-legend-item"><span class="ebm-legend-dot pending"></span><?php esc_html_e( 'Pending payment', 'electrical-booking-manager' ); ?></span>
					<span class="ebm-legend-item"><span class="ebm-legend-dot confirmed"></span><?php esc_html_e( 'Confirmed', 'electrical-booking-manager' ); ?></span>
					<span class="ebm-legend-item"><span class="ebm-legend-dot completed"></span><?php esc_html_e( 'Completed', 'electrical-booking-manager' ); ?></span>
					<span class="ebm-legend-item"><span class="ebm-legend-dot cancelled"></span><?php esc_html_e( 'Cancelled', 'electrical-booking-manager' ); ?></span>
					<span class="ebm-legend-item"><span class="ebm-legend-dot google"></span><?php esc_html_e( 'Google event', 'electrical-booking-manager' ); ?></span>
				</div>

				<?php if ( ! EBM_Google::connected() ) : ?>
					<div class="notice notice-info inline" style="margin:16px 24px;">
						<p>
							<?php esc_html_e( 'Google Calendar is not connected yet. Plugin bookings will still show here.', 'electrical-booking-manager' ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=ebm-settings' ) ); ?>"><?php esc_html_e( 'Connect it in settings.', 'electrical-booking-manager' ); ?></a>
						</p>
					</div>
				<?php endif; ?>

				<?php if ( $google_error ) : ?>
					<div class="notice notice-warning inline" style="margin:16px 24px;">
						<p><?php echo esc_html( $google_error ); ?></p>
					</div>
				<?php endif; ?>

				<div class="ebm-calendar-grid">
					<?php foreach ( $weekdays as $weekday ) : ?>
						<div class="ebm-calendar-weekday"><?php echo esc_html( $weekday ); ?></div>
					<?php endforeach; ?>

					<?php
					$cursor = $start_of_calendar;

					while ( $cursor <= $end_of_calendar ) :
						$day_key    = $cursor->format( 'Y-m-d' );
						$is_muted   = $cursor->format( 'Y-m' ) !== $first_day->format( 'Y-m' );
						$is_today   = $day_key === $today_day;
						$day_events = $events_by_day[ $day_key ] ?? array();
						?>
						<div class="ebm-calendar-day <?php echo $is_muted ? 'is-muted' : ''; ?> <?php echo $is_today ? 'is-today' : ''; ?>">
							<div class="ebm-calendar-date"><?php echo esc_html( $cursor->format( 'j' ) ); ?></div>

							<?php foreach ( $day_events as $event ) : ?>
								<?php $class = 'google' === $event['type'] ? 'google' : $event['status']; ?>

								<?php if ( ! empty( $event['url'] ) ) : ?>
									<a class="ebm-calendar-event <?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( $event['url'] ); ?>">
										<span class="ebm-event-time"><?php echo esc_html( $event['time'] . ' · ' . $event['title'] ); ?></span>
										<span class="ebm-event-meta"><?php echo esc_html( $event['customer'] ); ?></span>
									</a>
								<?php else : ?>
									<div class="ebm-calendar-event <?php echo esc_attr( $class ); ?>">
										<span class="ebm-event-time"><?php echo esc_html( $event['time'] . ' · ' . $event['title'] ); ?></span>
										<span class="ebm-event-meta"><?php echo esc_html( $event['customer'] ); ?></span>
									</div>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
						<?php
						$cursor = $cursor->modify( '+1 day' );
					endwhile;
					?>
				</div>
			</div>
		</div>
		<?php
	}
}