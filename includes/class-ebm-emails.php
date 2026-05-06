<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Emails {
	const CUSTOMER_CONFIRMATION_SENT_OPTION_PREFIX = 'ebm_customer_confirmation_sent_';
	const ADMIN_NOTIFICATION_SENT_OPTION_PREFIX    = 'ebm_admin_notification_sent_';
	const BALANCE_EMAIL_SENT_OPTION_PREFIX         = 'ebm_balance_email_sent_';

	public static function init() {
		if ( ! wp_next_scheduled( 'ebm_send_reminders' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'ebm_send_reminders' );
		}
	}

	private static function active_statuses() {
		return array( 'deposit_paid', 'confirmed', 'completed' );
	}

	private static function option_key( $prefix, $booking_id ) {
		return $prefix . absint( $booking_id );
	}

	private static function was_sent( $prefix, $booking_id ) {
		return '' !== (string) get_option( self::option_key( $prefix, $booking_id ), '' );
	}

	private static function mark_sent( $prefix, $booking_id ) {
		update_option( self::option_key( $prefix, $booking_id ), current_time( 'mysql' ), false );
	}

	private static function business_name() {
		return sanitize_text_field( EBM_Settings::get( 'business_name', get_bloginfo( 'name' ) ) );
	}

	private static function admin_email() {
		$email = sanitize_email( EBM_Settings::get( 'admin_email', get_option( 'admin_email' ) ) );

		if ( ! is_email( $email ) ) {
			$email = get_option( 'admin_email' );
		}

		return $email;
	}

	private static function from_headers() {
		$from_name  = sanitize_text_field( EBM_Settings::get( 'email_from_name', self::business_name() ) );
		$from_email = sanitize_email( EBM_Settings::get( 'email_from_address', self::admin_email() ) );

		if ( ! is_email( $from_email ) ) {
			$from_email = self::admin_email();
		}

		return array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		);
	}

	private static function logo_url() {
		$custom_logo_id = get_theme_mod( 'custom_logo' );

		if ( $custom_logo_id ) {
			$url = wp_get_attachment_image_url( $custom_logo_id, 'medium' );

			if ( $url ) {
				return esc_url_raw( $url );
			}
		}

		return '';
	}

	private static function money( $amount ) {
		if ( class_exists( 'EBM_Helpers' ) && method_exists( 'EBM_Helpers', 'money' ) ) {
			return esc_html( EBM_Helpers::money( $amount ) );
		}

		return '&pound;' . esc_html( number_format( (float) $amount, 2 ) );
	}

	private static function booking( $booking_id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT
					b.*,
					j.title AS job_title,
					j.description AS job_description,
					c.name AS customer_name,
					c.email AS customer_email,
					c.phone AS customer_phone,
					c.address AS customer_address
				FROM ' . EBM_Helpers::table( 'bookings' ) . ' b
				INNER JOIN ' . EBM_Helpers::table( 'jobs' ) . ' j ON j.id = b.job_id
				INNER JOIN ' . EBM_Helpers::table( 'customers' ) . ' c ON c.id = b.customer_id
				WHERE b.id = %d',
				absint( $booking_id )
			)
		);
	}

	private static function booking_days( $booking_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT *
				FROM ' . EBM_Helpers::table( 'booking_days' ) . '
				WHERE booking_id = %d
				ORDER BY start_at ASC',
				absint( $booking_id )
			)
		);
	}

	private static function extras_html( $booking ) {
		if ( class_exists( 'EBM_Helpers' ) && method_exists( 'EBM_Helpers', 'booking_extras_html' ) ) {
			return EBM_Helpers::booking_extras_html( $booking );
		}

		return esc_html__( 'No extras selected', 'electrical-booking-manager' );
	}

	private static function date_line( $start_at, $end_at ) {
		$start_ts = strtotime( (string) $start_at );
		$end_ts   = strtotime( (string) $end_at );

		if ( ! $start_ts ) {
			return '';
		}

		$date = wp_date( 'l j F Y', $start_ts );
		$from = wp_date( 'H:i', $start_ts );
		$to   = $end_ts ? wp_date( 'H:i', $end_ts ) : '';

		if ( $to ) {
			return $date . ', ' . $from . ' to ' . $to;
		}

		return $date . ', ' . $from;
	}

	private static function schedule_html( $booking ) {
		$days = self::booking_days( $booking->id );

		if ( empty( $days ) ) {
			return esc_html( self::date_line( $booking->start_at, $booking->end_at ) );
		}

		if ( 1 === count( $days ) ) {
			return esc_html( self::date_line( $days[0]->start_at, $days[0]->end_at ) );
		}

		$html = '<ol style="margin:0;padding-left:20px;">';

		foreach ( $days as $index => $day ) {
			$html .= '<li style="margin:0 0 8px;">';
			$html .= esc_html(
				sprintf(
					/* translators: 1: day number, 2: schedule line */
					__( 'Day %1$d: %2$s', 'electrical-booking-manager' ),
					$index + 1,
					self::date_line( $day->start_at, $day->end_at )
				)
			);
			$html .= '</li>';
		}

		$html .= '</ol>';

		return $html;
	}

	private static function address_html( $address ) {
		$address = trim( (string) $address );

		if ( '' === $address ) {
			return '';
		}

		$lines = preg_split( '/\r\n|\r|\n/', $address );
		$lines = array_filter( array_map( 'trim', $lines ) );

		return implode( '<br>', array_map( 'esc_html', $lines ) );
	}

	private static function status_label( $status ) {
		return ucwords( str_replace( '_', ' ', sanitize_text_field( $status ) ) );
	}

	private static function shell( $heading, $intro, $content, $button_url = '', $button_text = '' ) {
		$business_name = self::business_name();
		$logo_url      = self::logo_url();

		if ( $logo_url ) {
			$brand_html = '
				<div style="margin:0 0 24px;">
					<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $business_name ) . '" style="display:block;max-width:180px;height:auto;">
				</div>';
		} else {
			$brand_html = '
				<div style="margin:0 0 24px;font-size:22px;line-height:1.2;font-weight:900;color:#172234;">
					' . esc_html( $business_name ) . '
				</div>';
		}

		$button_html = '';

		if ( $button_url && $button_text ) {
			$button_html = '
				<table role="presentation" cellspacing="0" cellpadding="0" style="margin:26px 0 0;">
					<tr>
						<td>
							<a href="' . esc_url( $button_url ) . '" style="display:inline-block;background:#e87c00;color:#ffffff;text-decoration:none;font-size:15px;font-weight:900;padding:14px 22px;border-radius:999px;">
								' . esc_html( $button_text ) . '
							</a>
						</td>
					</tr>
				</table>';
		}

		return '
<!doctype html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>' . esc_html( $heading ) . '</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;color:#172234;font-family:Arial,Helvetica,sans-serif;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;padding:28px 12px;background:#f5f7fb;">
		<tr>
			<td align="center">
				<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#ffffff;border:1px solid #d8e0ea;border-radius:24px;overflow:hidden;box-shadow:0 16px 38px rgba(18,32,51,0.08);">
					<tr>
						<td style="padding:32px;">
							' . $brand_html . '

							<h1 style="margin:0 0 12px;font-size:30px;line-height:1.15;color:#172234;font-weight:900;">
								' . esc_html( $heading ) . '
							</h1>

							<p style="margin:0 0 24px;font-size:16px;line-height:1.65;color:#5f6f82;">
								' . esc_html( $intro ) . '
							</p>

							' . $content . '

							' . $button_html . '

							<p style="margin:30px 0 0;font-size:13px;line-height:1.6;color:#7a8796;">
								' . esc_html__( 'This email was sent by the booking system. Please keep it for your records.', 'electrical-booking-manager' ) . '
							</p>
						</td>
					</tr>
				</table>

				<p style="margin:18px 0 0;font-size:12px;line-height:1.5;color:#8b98a8;">
					' . esc_html( $business_name ) . '
				</p>
			</td>
		</tr>
	</table>
</body>
</html>';
	}

	private static function details_table( $rows ) {
		$html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #d8e0ea;border-radius:18px;overflow:hidden;background:#f8fbfd;">';

		$filtered_rows = array();

		foreach ( $rows as $label => $value ) {
			if ( '' === trim( wp_strip_all_tags( (string) $value ) ) ) {
				continue;
			}

			$filtered_rows[ $label ] = $value;
		}

		$total_rows = count( $filtered_rows );
		$count      = 0;

		foreach ( $filtered_rows as $label => $value ) {
			$count++;

			$border = $count < $total_rows ? 'border-bottom:1px solid #e5edf4;' : '';

			$html .= '
				<tr>
					<td style="padding:14px 16px;' . $border . 'color:#5f6f82;font-size:14px;font-weight:800;width:38%;vertical-align:top;">
						' . esc_html( $label ) . '
					</td>
					<td style="padding:14px 16px;' . $border . 'color:#172234;font-size:14px;font-weight:900;vertical-align:top;">
						' . $value . '
					</td>
				</tr>';
		}

		$html .= '</table>';

		return $html;
	}

	public static function confirmation( $booking_id, $force = false ) {
		$booking_id = absint( $booking_id );
		$booking    = self::booking( $booking_id );

		if ( ! $booking ) {
			return false;
		}

		if ( ! in_array( $booking->status, self::active_statuses(), true ) ) {
			return false;
		}

		if ( ! $force && self::was_sent( self::CUSTOMER_CONFIRMATION_SENT_OPTION_PREFIX, $booking_id ) ) {
			self::admin_new_booking( $booking_id );
			return true;
		}

		$to = sanitize_email( $booking->customer_email );

		if ( ! is_email( $to ) ) {
			return false;
		}

		$heading = __( 'Your booking is confirmed', 'electrical-booking-manager' );

		$intro = sprintf(
			/* translators: %s: customer name */
			__( 'Hi %s, your booking has been received and confirmed. The details are below.', 'electrical-booking-manager' ),
			$booking->customer_name
		);

		$content = self::details_table(
			array(
				__( 'Job', 'electrical-booking-manager' )             => esc_html( $booking->job_title ),
				__( 'Extras', 'electrical-booking-manager' )          => self::extras_html( $booking ),
				__( 'Schedule', 'electrical-booking-manager' )        => self::schedule_html( $booking ),
				__( 'Service address', 'electrical-booking-manager' ) => self::address_html( $booking->customer_address ),
				__( 'Total', 'electrical-booking-manager' )           => self::money( $booking->total_amount ),
				__( 'Deposit paid', 'electrical-booking-manager' )    => self::money( $booking->deposit_amount ),
				__( 'Balance', 'electrical-booking-manager' )         => self::money( $booking->balance_amount ),
			)
		);

		$content .= '
			<p style="margin:22px 0 0;font-size:15px;line-height:1.65;color:#5f6f82;">
				' . esc_html__( 'Please check the job and extras above. If anything looks wrong, contact us as soon as possible.', 'electrical-booking-manager' ) . '
			</p>

			<p style="margin:12px 0 0;font-size:15px;line-height:1.65;color:#5f6f82;">
				' . esc_html__( 'Please make sure someone is available at the property at the booked time.', 'electrical-booking-manager' ) . '
			</p>';

		$subject = sprintf(
			/* translators: %s: business name */
			__( 'Your booking with %s is confirmed', 'electrical-booking-manager' ),
			self::business_name()
		);

		$sent = wp_mail(
			$to,
			$subject,
			self::shell( $heading, $intro, $content ),
			self::from_headers()
		);

		if ( $sent ) {
			self::mark_sent( self::CUSTOMER_CONFIRMATION_SENT_OPTION_PREFIX, $booking_id );
		}

		self::admin_new_booking( $booking_id );

		return $sent;
	}

	public static function admin_new_booking( $booking_id, $force = false ) {
		$booking_id = absint( $booking_id );
		$booking    = self::booking( $booking_id );

		if ( ! $booking ) {
			return false;
		}

		if ( ! $force && self::was_sent( self::ADMIN_NOTIFICATION_SENT_OPTION_PREFIX, $booking_id ) ) {
			return true;
		}

		$to = self::admin_email();

		if ( ! is_email( $to ) ) {
			return false;
		}

		$heading = __( 'New booking received', 'electrical-booking-manager' );
		$intro   = __( 'A customer has made a new booking. The full details are below.', 'electrical-booking-manager' );

		$content = self::details_table(
			array(
				__( 'Customer', 'electrical-booking-manager' )        => esc_html( $booking->customer_name ),
				__( 'Email', 'electrical-booking-manager' )           => '<a href="mailto:' . esc_attr( $booking->customer_email ) . '" style="color:#e87c00;text-decoration:none;">' . esc_html( $booking->customer_email ) . '</a>',
				__( 'Phone', 'electrical-booking-manager' )           => esc_html( $booking->customer_phone ),
				__( 'Job', 'electrical-booking-manager' )             => esc_html( $booking->job_title ),
				__( 'Extras', 'electrical-booking-manager' )          => self::extras_html( $booking ),
				__( 'Schedule', 'electrical-booking-manager' )        => self::schedule_html( $booking ),
				__( 'Service address', 'electrical-booking-manager' ) => self::address_html( $booking->customer_address ),
				__( 'Status', 'electrical-booking-manager' )          => esc_html( self::status_label( $booking->status ) ),
				__( 'Total', 'electrical-booking-manager' )           => self::money( $booking->total_amount ),
				__( 'Deposit', 'electrical-booking-manager' )         => self::money( $booking->deposit_amount ),
				__( 'Balance', 'electrical-booking-manager' )         => self::money( $booking->balance_amount ),
			)
		);

		$content .= '
			<p style="margin:22px 0 0;font-size:15px;line-height:1.65;color:#5f6f82;">
				' . esc_html__( 'Check the extras before attending so the engineer brings the correct parts and materials.', 'electrical-booking-manager' ) . '
			</p>';

		$subject = sprintf(
			/* translators: 1: job title, 2: date */
			__( 'New booking: %1$s on %2$s', 'electrical-booking-manager' ),
			$booking->job_title,
			wp_date( 'd M Y H:i', strtotime( $booking->start_at ) )
		);

		$sent = wp_mail(
			$to,
			$subject,
			self::shell(
				$heading,
				$intro,
				$content,
				admin_url( 'admin.php?page=ebm-bookings' ),
				__( 'View bookings', 'electrical-booking-manager' )
			),
			self::from_headers()
		);

		if ( $sent ) {
			self::mark_sent( self::ADMIN_NOTIFICATION_SENT_OPTION_PREFIX, $booking_id );
		}

		return $sent;
	}

	public static function reminder( $booking_id, $force = false ) {
		global $wpdb;

		$booking_id = absint( $booking_id );
		$booking    = self::booking( $booking_id );

		if ( ! $booking ) {
			return false;
		}

		if ( ! in_array( $booking->status, array( 'deposit_paid', 'confirmed' ), true ) ) {
			return false;
		}

		if ( ! $force && (int) $booking->reminder_sent ) {
			return true;
		}

		$to = sanitize_email( $booking->customer_email );

		if ( ! is_email( $to ) ) {
			return false;
		}

		$heading = __( 'Your booking is tomorrow', 'electrical-booking-manager' );

		$intro = sprintf(
			/* translators: %s: customer name */
			__( 'Hi %s, this is a reminder that your electrical booking is due tomorrow.', 'electrical-booking-manager' ),
			$booking->customer_name
		);

		$content = self::details_table(
			array(
				__( 'Job', 'electrical-booking-manager' )             => esc_html( $booking->job_title ),
				__( 'Extras', 'electrical-booking-manager' )          => self::extras_html( $booking ),
				__( 'Schedule', 'electrical-booking-manager' )        => self::schedule_html( $booking ),
				__( 'Service address', 'electrical-booking-manager' ) => self::address_html( $booking->customer_address ),
			)
		);

		$content .= '
			<p style="margin:22px 0 0;font-size:15px;line-height:1.65;color:#5f6f82;">
				' . esc_html__( 'Please make sure the area is accessible and that someone is available at the property. If there is anything we need to know before we arrive, please contact us.', 'electrical-booking-manager' ) . '
			</p>';

		$subject = __( 'Reminder: your electrical booking is tomorrow', 'electrical-booking-manager' );

		$sent = wp_mail(
			$to,
			$subject,
			self::shell( $heading, $intro, $content ),
			self::from_headers()
		);

		if ( $sent ) {
			$wpdb->update(
				EBM_Helpers::table( 'bookings' ),
				array(
					'reminder_sent' => 1,
					'updated_at'    => current_time( 'mysql' ),
				),
				array( 'id' => $booking_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}

		return $sent;
	}

	public static function send_due_reminders() {
		global $wpdb;

		$timezone = wp_timezone();

		$tomorrow_start = new DateTimeImmutable( 'tomorrow 00:00:00', $timezone );
		$tomorrow_end   = new DateTimeImmutable( 'tomorrow 23:59:59', $timezone );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id
				FROM " . EBM_Helpers::table( 'bookings' ) . "
				WHERE status IN ('deposit_paid', 'confirmed')
				AND reminder_sent = 0
				AND start_at >= %s
				AND start_at <= %s
				ORDER BY start_at ASC
				LIMIT 100",
				$tomorrow_start->format( 'Y-m-d H:i:s' ),
				$tomorrow_end->format( 'Y-m-d H:i:s' )
			)
		);

		foreach ( (array) $rows as $row ) {
			self::reminder( absint( $row->id ) );
		}
	}

	public static function balance( $booking_id, $payment_url, $force = false ) {
		$booking_id  = absint( $booking_id );
		$payment_url = esc_url_raw( $payment_url );
		$booking     = self::booking( $booking_id );

		if ( ! $booking || '' === $payment_url ) {
			return false;
		}

		if ( ! $force && self::was_sent( self::BALANCE_EMAIL_SENT_OPTION_PREFIX, $booking_id ) ) {
			return true;
		}

		$to = sanitize_email( $booking->customer_email );

		if ( ! is_email( $to ) ) {
			return false;
		}

		$heading = __( 'Your remaining balance is ready to pay', 'electrical-booking-manager' );

		$intro = sprintf(
			/* translators: %s: customer name */
			__( 'Hi %s, your job has been marked as completed. You can now pay the remaining balance using the secure payment link below.', 'electrical-booking-manager' ),
			$booking->customer_name
		);

		$content = self::details_table(
			array(
				__( 'Job', 'electrical-booking-manager' )          => esc_html( $booking->job_title ),
				__( 'Extras', 'electrical-booking-manager' )       => self::extras_html( $booking ),
				__( 'Total', 'electrical-booking-manager' )        => self::money( $booking->total_amount ),
				__( 'Deposit paid', 'electrical-booking-manager' ) => self::money( $booking->deposit_amount ),
				__( 'Balance due', 'electrical-booking-manager' )  => self::money( $booking->balance_amount ),
			)
		);

		$subject = __( 'Your remaining booking balance is ready to pay', 'electrical-booking-manager' );

		$sent = wp_mail(
			$to,
			$subject,
			self::shell(
				$heading,
				$intro,
				$content,
				$payment_url,
				__( 'Pay remaining balance', 'electrical-booking-manager' )
			),
			self::from_headers()
		);

		if ( $sent ) {
			self::mark_sent( self::BALANCE_EMAIL_SENT_OPTION_PREFIX, $booking_id );
		}

		return $sent;
	}
}