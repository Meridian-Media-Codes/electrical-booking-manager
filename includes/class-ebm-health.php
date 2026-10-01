<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Calendar health monitor.
 *
 * - Checks the Google connection every hour (WP-Cron).
 * - Re-checks 5 minutes after any Google error is recorded by the plugin.
 * - Flags paid, upcoming bookings that never made it into Google Calendar.
 * - Emails the admin when it breaks, a daily reminder while it stays broken,
 *   and an "all clear" email when it recovers.
 */
final class EBM_Health {
	const CRON_HOOK      = 'ebm_google_health_check';
	const RECHECK_HOOK   = 'ebm_google_health_recheck';
	const STATE_OPTION   = 'ebm_google_health_state';
	const REMIND_EVERY   = DAY_IN_SECONDS;
	const RECHECK_DELAY  = 5 * MINUTE_IN_SECONDS;
	const MISSING_GRACE  = 15 * MINUTE_IN_SECONDS;

	private static $running = false;

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( self::RECHECK_HOOK, array( __CLASS__, 'run' ) );

		add_action( 'add_option_ebm_google_last_error', array( __CLASS__, 'queue_recheck' ) );
		add_action( 'update_option_ebm_google_last_error', array( __CLASS__, 'queue_recheck' ) );

		add_action( 'admin_post_ebm_google_health_check', array( __CLASS__, 'manual_check' ) );

		register_deactivation_hook( EBM_FILE, array( __CLASS__, 'unschedule' ) );
	}

	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::RECHECK_HOOK );
	}

	public static function queue_recheck() {
		if ( self::$running ) {
			return;
		}

		if ( ! wp_next_scheduled( self::RECHECK_HOOK ) ) {
			wp_schedule_single_event( time() + self::RECHECK_DELAY, self::RECHECK_HOOK );
		}
	}

	/**
	 * Runs every check and sends alerts on state changes.
	 *
	 * @return array List of problems found (empty when healthy).
	 */
	public static function run() {
		if ( self::$running ) {
			return array();
		}

		self::$running = true;
		$problems      = self::problems();
		self::$running = false;

		$state = self::state();
		$now   = time();

		$state['last_check'] = $now;

		if ( empty( $problems ) ) {
			if ( 'failing' === $state['status'] ) {
				self::send_recovery( $state );
			}

			$state['status']     = 'ok';
			$state['since']      = 0;
			$state['last_alert'] = 0;
			$state['problems']   = array();

			self::save_state( $state );

			return array();
		}

		$is_new       = 'failing' !== $state['status'];
		$changed      = $problems !== $state['problems'];
		$reminder_due = ( $now - (int) $state['last_alert'] ) >= self::REMIND_EVERY;

		if ( $is_new ) {
			$state['since'] = $now;
		}

		if ( $is_new || $changed || $reminder_due ) {
			self::send_alert( $problems, $state['since'], ! $is_new );
			$state['last_alert'] = $now;
		}

		$state['status']   = 'failing';
		$state['problems'] = $problems;

		self::save_state( $state );

		return $problems;
	}

	private static function problems() {
		$problems = array();

		if ( ! class_exists( 'EBM_Google' ) ) {
			return array( __( 'The Google Calendar module is not loaded.', 'electrical-booking-manager' ) );
		}

		if ( ! EBM_Google::connected() ) {
			return array( __( 'No Google connection is saved. The service account JSON key is missing from the plugin settings.', 'electrical-booking-manager' ) );
		}

		$mode        = EBM_Google::auth_mode();
		$calendar_id = trim( (string) EBM_Settings::get( 'google_calendar_id', '' ) );

		if ( 'service_account' === $mode && ( '' === $calendar_id || 'primary' === $calendar_id ) ) {
			return array( __( 'No calendar is selected. Enter the client\'s Calendar ID in the Shared calendars box (it cannot be "primary" with a service account).', 'electrical-booking-manager' ) );
		}

		if ( 'oauth' === $mode && ( '' === $calendar_id || 'primary' === $calendar_id ) ) {
			$start  = wp_date( 'Y-m-d H:i:00', time(), wp_timezone() );
			$end    = wp_date( 'Y-m-d H:i:00', time() + HOUR_IN_SECONDS, wp_timezone() );
			$result = EBM_Google::events( $start, $end );
		} else {
			$result = EBM_Google::test_saved_calendar();
		}

		if ( is_wp_error( $result ) ) {
			$problems[] = sprintf(
				/* translators: %s: Google error message */
				__( 'Google Calendar could not be reached: %s. While this continues, the booking form will not show available slots.', 'electrical-booking-manager' ),
				$result->get_error_message()
			);
		}

		$missing = self::bookings_missing_events();

		if ( ! empty( $missing ) ) {
			$problems[] = sprintf(
				/* translators: %s: comma separated booking references */
				__( 'These paid, upcoming bookings are not in Google Calendar: %s. Open each booking in the plugin and re-save it to push it to the calendar.', 'electrical-booking-manager' ),
				implode( ', ', $missing )
			);
		}

		return $problems;
	}

	private static function bookings_missing_events() {
		global $wpdb;

		$table  = EBM_Helpers::table( 'bookings' );
		$now    = current_time( 'mysql' );
		$cutoff = wp_date( 'Y-m-d H:i:s', time() - self::MISSING_GRACE, wp_timezone() );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM $table
				WHERE status IN ('deposit_paid', 'confirmed', 'completed')
				AND end_at > %s
				AND updated_at < %s
				AND ( google_event_id IS NULL OR google_event_id = '' )
				ORDER BY start_at ASC
				LIMIT 20",
				$now,
				$cutoff
			)
		);

		return array_map(
			function( $id ) {
				return '#' . absint( $id );
			},
			(array) $ids
		);
	}

	private static function state() {
		$defaults = array(
			'status'     => 'ok',
			'since'      => 0,
			'last_check' => 0,
			'last_alert' => 0,
			'problems'   => array(),
		);

		$state = get_option( self::STATE_OPTION, array() );

		return wp_parse_args( is_array( $state ) ? $state : array(), $defaults );
	}

	private static function save_state( $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	private static function recipients() {
		$emails = array();

		$plugin_admin = sanitize_email( EBM_Settings::get( 'admin_email', '' ) );
		$site_admin   = sanitize_email( get_option( 'admin_email' ) );

		foreach ( array( $plugin_admin, $site_admin ) as $email ) {
			if ( is_email( $email ) ) {
				$emails[] = strtolower( $email );
			}
		}

		$emails = apply_filters( 'ebm_google_health_recipients', array_values( array_unique( $emails ) ) );

		return array_filter( (array) $emails, 'is_email' );
	}

	private static function headers() {
		$name  = sanitize_text_field( EBM_Settings::get( 'email_from_name', get_bloginfo( 'name' ) ) );
		$email = sanitize_email( EBM_Settings::get( 'email_from_address', get_option( 'admin_email' ) ) );

		if ( ! is_email( $email ) ) {
			$email = get_option( 'admin_email' );
		}

		return array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $name . ' <' . $email . '>',
		);
	}

	private static function site_label() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' (' . wp_parse_url( home_url(), PHP_URL_HOST ) . ')';
	}

	private static function settings_url() {
		return admin_url( 'admin.php?page=ebm-settings' );
	}

	private static function wrap( $heading, $body_html, $colour ) {
		$button = '<p style="margin:24px 0 0;"><a href="' . esc_url( self::settings_url() ) . '" style="background:#1d4ed8;color:#ffffff;padding:10px 18px;border-radius:4px;text-decoration:none;display:inline-block;">' . esc_html__( 'Open booking settings', 'electrical-booking-manager' ) . '</a></p>';

		return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1f2937;max-width:600px;">'
			. '<h2 style="margin:0 0 12px;color:' . esc_attr( $colour ) . ';">' . esc_html( $heading ) . '</h2>'
			. $body_html
			. $button
			. '<p style="margin:24px 0 0;font-size:12px;color:#6b7280;">' . esc_html__( 'Sent automatically by Electrical Booking Manager. The connection is checked every hour.', 'electrical-booking-manager' ) . '</p>'
			. '</div>';
	}

	private static function send_alert( $problems, $since, $is_reminder ) {
		$recipients = self::recipients();

		if ( empty( $recipients ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: reminder prefix, 2: site name */
			__( '%1$sGoogle Calendar connection problem – %2$s', 'electrical-booking-manager' ),
			$is_reminder ? __( 'Still failing: ', 'electrical-booking-manager' ) : '',
			self::site_label()
		);

		$items = '';

		foreach ( $problems as $problem ) {
			$items .= '<li style="margin:0 0 8px;">' . esc_html( $problem ) . '</li>';
		}

		$body  = '<p>' . esc_html__( 'The booking system has a problem with its Google Calendar connection.', 'electrical-booking-manager' ) . '</p>';
		$body .= '<ul style="padding-left:20px;">' . $items . '</ul>';

		if ( $since ) {
			$body .= '<p><strong>' . esc_html__( 'First detected:', 'electrical-booking-manager' ) . '</strong> ' . esc_html( wp_date( 'l j F Y, H:i', $since ) ) . '</p>';
		}

		$body .= '<p><strong>' . esc_html__( 'Things to check:', 'electrical-booking-manager' ) . '</strong></p>';
		$body .= '<ul style="padding-left:20px;">'
			. '<li>' . esc_html__( 'The calendar is still shared with the service account email, with "Make changes" permission.', 'electrical-booking-manager' ) . '</li>'
			. '<li>' . esc_html__( 'The service account key has not been deleted or disabled in Google Cloud Console.', 'electrical-booking-manager' ) . '</li>'
			. '<li>' . esc_html__( 'The Calendar ID in the plugin settings matches the shared calendar.', 'electrical-booking-manager' ) . '</li>'
			. '</ul>';

		wp_mail( $recipients, $subject, self::wrap( __( 'Google Calendar connection problem', 'electrical-booking-manager' ), $body, '#b91c1c' ), self::headers() );
	}

	private static function send_recovery( $state ) {
		$recipients = self::recipients();

		if ( empty( $recipients ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Resolved: Google Calendar connection restored – %s', 'electrical-booking-manager' ),
			self::site_label()
		);

		$body = '<p>' . esc_html__( 'The Google Calendar connection is working again and the booking form is showing available slots.', 'electrical-booking-manager' ) . '</p>';

		if ( ! empty( $state['since'] ) ) {
			$body .= '<p><strong>' . esc_html__( 'Problem started:', 'electrical-booking-manager' ) . '</strong> ' . esc_html( wp_date( 'l j F Y, H:i', (int) $state['since'] ) ) . '<br><strong>' . esc_html__( 'Resolved:', 'electrical-booking-manager' ) . '</strong> ' . esc_html( wp_date( 'l j F Y, H:i' ) ) . '</p>';
		}

		$body .= '<p>' . esc_html__( 'Check that any bookings taken while it was down appear in Google Calendar.', 'electrical-booking-manager' ) . '</p>';

		wp_mail( $recipients, $subject, self::wrap( __( 'Google Calendar connection restored', 'electrical-booking-manager' ), $body, '#15803d' ), self::headers() );
	}

	/**
	 * Manual run for testing:
	 * /wp-admin/admin-post.php?action=ebm_google_health_check
	 * /wp-admin/admin-post.php?action=ebm_google_health_check&test_email=1
	 */
	public static function manual_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'electrical-booking-manager' ) );
		}

		if ( ! empty( $_GET['test_email'] ) ) {
			self::send_alert(
				array( __( 'This is a test alert. No action is needed – it confirms that connection alerts reach this inbox.', 'electrical-booking-manager' ) ),
				time(),
				false
			);

			wp_die(
				esc_html( sprintf(
					/* translators: %s: email addresses */
					__( 'Test alert sent to: %s', 'electrical-booking-manager' ),
					implode( ', ', self::recipients() )
				) ),
				esc_html__( 'Google health check', 'electrical-booking-manager' ),
				array( 'back_link' => true )
			);
		}

		$problems = self::run();

		if ( empty( $problems ) ) {
			$message = __( 'Google Calendar connection is healthy. No problems found.', 'electrical-booking-manager' );
		} else {
			$message = __( 'Problems found (alert email sent):', 'electrical-booking-manager' ) . ' ' . implode( ' | ', $problems );
		}

		wp_die(
			esc_html( $message ),
			esc_html__( 'Google health check', 'electrical-booking-manager' ),
			array( 'back_link' => true )
		);
	}
}

EBM_Health::boot();