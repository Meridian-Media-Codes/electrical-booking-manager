<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Google {
	public static function init() {
		add_action( 'admin_post_ebm_google_connect', array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_ebm_google_callback', array( __CLASS__, 'callback' ) );
		add_action( 'admin_post_ebm_google_disconnect', array( __CLASS__, 'disconnect' ) );
	}

	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=ebm_google_callback' );
	}

	public static function connected() {
		return '' !== EBM_Settings::get( 'google_refresh_token', '' );
	}

	private static function google_datetime( $datetime ) {
		try {
			$date = new DateTimeImmutable( (string) $datetime, wp_timezone() );
		} catch ( Exception $e ) {
			return '';
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	private static function google_local_datetime( $datetime ) {
		try {
			$date = new DateTimeImmutable( (string) $datetime, wp_timezone() );
		} catch ( Exception $e ) {
			return '';
		}

		return $date->format( 'Y-m-d\TH:i:s' );
	}

	private static function google_timezone() {
		$timezone = wp_timezone_string();

		if ( ! $timezone ) {
			$timezone = 'Europe/London';
		}

		return $timezone;
	}

	private static function clear_google_event_cache() {
		global $wpdb;

		$wpdb->query(
			"DELETE FROM $wpdb->options
			WHERE option_name LIKE '_transient_ebm_google_events_%'
			OR option_name LIKE '_transient_timeout_ebm_google_events_%'"
		);
	}

	public static function connect() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ebm_google_connect' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'electrical-booking-manager' ) );
		}

		$client_id = EBM_Settings::get( 'google_client_id', '' );

		if ( '' === $client_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ebm-settings&google=missing_client_id' ) );
			exit;
		}

		$state = wp_create_nonce( 'ebm_google_state' );

		$url = add_query_arg(
			array(
				'client_id'              => $client_id,
				'redirect_uri'           => self::redirect_uri(),
				'response_type'          => 'code',
				'scope'                  => 'https://www.googleapis.com/auth/calendar.readonly https://www.googleapis.com/auth/calendar.events',
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
			),
			'https://accounts.google.com/o/oauth2/v2/auth'
		);

		wp_safe_redirect( $url );
		exit;
	}

	public static function callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'electrical-booking-manager' ) );
		}

		$error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );

		if ( '' !== $error ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ebm-settings&google=failed&google_error=' . rawurlencode( $error ) ) );
			exit;
		}

		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );

		if ( ! wp_verify_nonce( $state, 'ebm_google_state' ) ) {
			wp_die( esc_html__( 'Invalid OAuth state.', 'electrical-booking-manager' ) );
		}

		$code = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );

		if ( '' === $code ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ebm-settings&google=missing_code' ) );
			exit;
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => $code,
					'client_id'     => EBM_Settings::get( 'google_client_id', '' ),
					'client_secret' => EBM_Settings::secret( 'google_client_secret' ),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ebm-settings&google=failed&google_error=' . rawurlencode( $response->get_error_message() ) ) );
			exit;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( wp_remote_retrieve_response_code( $response ) >= 400 || empty( $body['refresh_token'] ) ) {
			$message = $body['error_description'] ?? ( $body['error'] ?? __( 'Google did not return a refresh token.', 'electrical-booking-manager' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=ebm-settings&google=failed&google_error=' . rawurlencode( $message ) ) );
			exit;
		}

		$settings = EBM_Settings::all();
		$settings['google_refresh_token'] = EBM_Helpers::encrypt( sanitize_text_field( $body['refresh_token'] ) );

		update_option( EBM_Settings::OPTION, $settings, false );
		delete_transient( 'ebm_google_access_token' );
		self::clear_google_event_cache();

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-settings&google=connected' ) );
		exit;
	}

	public static function disconnect() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ebm_google_disconnect' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'electrical-booking-manager' ) );
		}

		$settings = EBM_Settings::all();
		$settings['google_refresh_token'] = '';

		update_option( EBM_Settings::OPTION, $settings, false );
		delete_transient( 'ebm_google_access_token' );
		self::clear_google_event_cache();

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-settings&google=disconnected' ) );
		exit;
	}

	private static function google_error_message( $response, $fallback ) {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error']['message'] ) ) {
			return sanitize_text_field( $body['error']['message'] );
		}

		if ( isset( $body['error_description'] ) ) {
			return sanitize_text_field( $body['error_description'] );
		}

		if ( isset( $body['error'] ) && is_string( $body['error'] ) ) {
			return sanitize_text_field( $body['error'] );
		}

		return $fallback;
	}

	private static function token() {
		$cached = get_transient( 'ebm_google_access_token' );

		if ( $cached ) {
			return $cached;
		}

		$refresh_token = EBM_Helpers::decrypt( EBM_Settings::get( 'google_refresh_token', '' ) );

		if ( '' === $refresh_token ) {
			return '';
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => EBM_Settings::get( 'google_client_id', '' ),
					'client_secret' => EBM_Settings::secret( 'google_client_secret' ),
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( wp_remote_retrieve_response_code( $response ) >= 400 || empty( $body['access_token'] ) ) {
			return '';
		}

		$access_token = sanitize_text_field( $body['access_token'] );
		$expires_in   = max( 300, absint( $body['expires_in'] ?? 3600 ) - 120 );

		set_transient( 'ebm_google_access_token', $access_token, $expires_in );

		return $access_token;
	}

	public static function conflicts( $segments ) {
		if ( ! self::connected() ) {
			return false;
		}

		$events = self::events_for_segments( $segments );

		if ( is_wp_error( $events ) ) {
			return true;
		}

		foreach ( $segments as $segment ) {
			$segment_start = strtotime( $segment['start_at'] ?? '' );
			$segment_end   = strtotime( $segment['end_at'] ?? '' );

			if ( ! $segment_start || ! $segment_end ) {
				return true;
			}

			foreach ( $events as $event ) {
				$event_start = strtotime( $event['start'] ?? '' );
				$event_end   = strtotime( $event['end'] ?? '' );

				if ( ! $event_start ) {
					continue;
				}

				if ( ! $event_end ) {
					$event_end = $event_start;
				}

				if ( $segment_start < $event_end && $segment_end > $event_start ) {
					return true;
				}
			}
		}

		return false;
	}

	private static function events_for_segments( $segments ) {
		$min = null;
		$max = null;

		foreach ( (array) $segments as $segment ) {
			$start = strtotime( $segment['start_at'] ?? '' );
			$end   = strtotime( $segment['end_at'] ?? '' );

			if ( ! $start || ! $end ) {
				continue;
			}

			$min = null === $min ? $start : min( $min, $start );
			$max = null === $max ? $end : max( $max, $end );
		}

		if ( null === $min || null === $max ) {
			return array();
		}

		$time_min = wp_date( 'Y-m-d 00:00:00', $min, wp_timezone() );
		$time_max = wp_date( 'Y-m-d 23:59:59', $max, wp_timezone() );

		return self::events( $time_min, $time_max );
	}

	public static function create_event( $booking_id ) {
		global $wpdb;

		if ( ! self::connected() ) {
			return '';
		}

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT b.*, j.title job_title, c.name, c.email, c.phone, c.address
				FROM ' . EBM_Helpers::table( 'bookings' ) . ' b
				INNER JOIN ' . EBM_Helpers::table( 'jobs' ) . ' j ON j.id = b.job_id
				INNER JOIN ' . EBM_Helpers::table( 'customers' ) . ' c ON c.id = b.customer_id
				WHERE b.id = %d',
				$booking_id
			)
		);

		if ( ! $booking ) {
			return '';
		}

		$token = self::token();

		if ( ! $token ) {
			return '';
		}

		$start = self::google_local_datetime( $booking->start_at );
		$end   = self::google_local_datetime( $booking->end_at );

		if ( '' === $start || '' === $end ) {
			return '';
		}

		$event = array(
			'summary'     => 'Booking: ' . $booking->job_title,
			'description' => "Customer: {$booking->name}\nEmail: {$booking->email}\nPhone: {$booking->phone}\nAddress: {$booking->address}",
			'start'       => array(
				'dateTime' => $start,
				'timeZone' => self::google_timezone(),
			),
			'end'         => array(
				'dateTime' => $end,
				'timeZone' => self::google_timezone(),
			),
		);

		$response = wp_remote_post(
			'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( EBM_Settings::get( 'google_calendar_id', 'primary' ) ) . '/events',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $event ),
			)
		);

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['id'] ) ) {
			$wpdb->update(
				EBM_Helpers::table( 'bookings' ),
				array( 'google_event_id' => sanitize_text_field( $body['id'] ) ),
				array( 'id' => $booking_id ),
				array( '%s' ),
				array( '%d' )
			);

			self::clear_google_event_cache();

			return sanitize_text_field( $body['id'] );
		}

		return '';
	}

	public static function events( $time_min, $time_max ) {
		if ( ! self::connected() ) {
			return array();
		}

		$formatted_min = self::google_datetime( $time_min );
		$formatted_max = self::google_datetime( $time_max );

		if ( '' === $formatted_min || '' === $formatted_max ) {
			return new WP_Error(
				'ebm_google_bad_time',
				__( 'The calendar date range could not be formatted for Google.', 'electrical-booking-manager' )
			);
		}

		$calendar_id = EBM_Settings::get( 'google_calendar_id', 'primary' );
		$cache_key   = 'ebm_google_events_' . md5( $calendar_id . '|' . $formatted_min . '|' . $formatted_max );
		$cached      = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$token = self::token();

		if ( ! $token ) {
			return new WP_Error(
				'ebm_google_token',
				__( 'Could not refresh the Google Calendar access token. Clear the saved token, save settings, then connect again.', 'electrical-booking-manager' )
			);
		}

		$url = add_query_arg(
			array(
				'timeMin'      => $formatted_min,
				'timeMax'      => $formatted_max,
				'singleEvents' => 'true',
				'orderBy'      => 'startTime',
			),
			'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar_id ) . '/events'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new WP_Error(
				'ebm_google_events',
				self::google_error_message(
					$response,
					__( 'Google Calendar events could not be loaded.', 'electrical-booking-manager' )
				)
			);
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$events = array();

		foreach ( (array) ( $body['items'] ?? array() ) as $item ) {
			if ( 'cancelled' === ( $item['status'] ?? '' ) ) {
				continue;
			}

			$start = $item['start']['dateTime'] ?? ( $item['start']['date'] ?? '' );
			$end   = $item['end']['dateTime'] ?? ( $item['end']['date'] ?? '' );

			if ( ! $start ) {
				continue;
			}

			$events[] = array(
				'id'      => sanitize_text_field( $item['id'] ?? '' ),
				'summary' => sanitize_text_field( $item['summary'] ?? __( 'Google event', 'electrical-booking-manager' ) ),
				'start'   => sanitize_text_field( $start ),
				'end'     => sanitize_text_field( $end ),
			);
		}

		set_transient( $cache_key, $events, 60 );

		return $events;
	}

		public static function delete_event( $event_id ) {
		if ( ! self::connected() ) {
			return false;
		}

		$event_id = sanitize_text_field( $event_id );

		if ( '' === $event_id ) {
			return false;
		}

		$token = self::token();

		if ( ! $token ) {
			return false;
		}

		$calendar_id = rawurlencode( EBM_Settings::get( 'google_calendar_id', 'primary' ) );

		$response = wp_remote_request(
			'https://www.googleapis.com/calendar/v3/calendars/' . $calendar_id . '/events/' . rawurlencode( $event_id ),
			array(
				'method'  => 'DELETE',
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );

		self::clear_google_event_cache();

		return in_array( $code, array( 200, 204, 410, 404 ), true );
	}

	public static function recreate_event( $booking_id ) {
		global $wpdb;

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . EBM_Helpers::table( 'bookings' ) . ' WHERE id = %d',
				absint( $booking_id )
			)
		);

		if ( ! $booking ) {
			return '';
		}

		if ( ! empty( $booking->google_event_id ) ) {
			self::delete_event( $booking->google_event_id );

			$wpdb->update(
				EBM_Helpers::table( 'bookings' ),
				array( 'google_event_id' => '' ),
				array( 'id' => absint( $booking_id ) ),
				array( '%s' ),
				array( '%d' )
			);
		}

		return self::create_event( absint( $booking_id ) );
	}
}