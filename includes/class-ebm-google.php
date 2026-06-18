<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Google {
	const ACCESS_TOKEN_TRANSIENT = 'ebm_google_access_token';
	const LAST_ERROR_OPTION      = 'ebm_google_last_error';

	public static function init() {
		add_action( 'admin_post_ebm_google_connect', array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_ebm_google_callback', array( __CLASS__, 'callback' ) );
		add_action( 'admin_post_ebm_google_disconnect', array( __CLASS__, 'disconnect' ) );
	}

	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=ebm_google_callback' );
	}

	public static function auth_mode() {
		$mode = EBM_Settings::get( 'google_auth_mode', 'service_account' );

		return in_array( $mode, array( 'service_account', 'oauth' ), true ) ? $mode : 'service_account';
	}

	public static function connected() {
		if ( 'service_account' === self::auth_mode() ) {
			$credentials = self::service_account_credentials();

			return ! empty( $credentials['client_email'] ) && ! empty( $credentials['private_key'] );
		}

		return '' !== EBM_Settings::get( 'google_refresh_token', '' );
	}

	private static function set_last_error( $message ) {
		$message = sanitize_text_field( (string) $message );

		if ( '' === $message ) {
			return;
		}

		update_option(
			self::LAST_ERROR_OPTION,
			array(
				'message' => $message,
				'time'    => current_time( 'mysql' ),
			),
			false
		);
	}

	private static function clear_last_error() {
		delete_option( self::LAST_ERROR_OPTION );
	}

	public static function last_error() {
		$error = get_option( self::LAST_ERROR_OPTION, array() );

		return is_array( $error ) ? $error : array();
	}

	private static function clear_cache() {
		global $wpdb;

		delete_transient( self::ACCESS_TOKEN_TRANSIENT );
		delete_transient( self::ACCESS_TOKEN_TRANSIENT . '_oauth' );
		delete_transient( self::ACCESS_TOKEN_TRANSIENT . '_service_account' );

		$wpdb->query(
			"DELETE FROM $wpdb->options
			WHERE option_name LIKE '_transient_ebm_google_events_%'
			OR option_name LIKE '_transient_timeout_ebm_google_events_%'
			OR option_name LIKE '_transient_ebm_google_access_token_%'
			OR option_name LIKE '_transient_timeout_ebm_google_access_token_%'"
		);
	}

	private static function google_timezone() {
		$timezone = wp_timezone_string();

		return $timezone ? $timezone : 'Europe/London';
	}

	private static function google_local_datetime( $datetime ) {
		try {
			$date = new DateTimeImmutable( (string) $datetime, wp_timezone() );
		} catch ( Exception $e ) {
			return '';
		}

		return $date->format( 'Y-m-d\TH:i:s' );
	}

	private static function google_utc_datetime( $datetime ) {
		try {
			$date = new DateTimeImmutable( (string) $datetime, wp_timezone() );
		} catch ( Exception $e ) {
			return '';
		}

		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	private static function plain_text( $value ) {
		return trim( wp_strip_all_tags( (string) $value ) );
	}

	private static function money( $amount ) {
		if ( class_exists( 'EBM_Helpers' ) && method_exists( 'EBM_Helpers', 'money' ) ) {
			return EBM_Helpers::money( $amount );
		}

		return '£' . number_format_i18n( (float) $amount, 2 );
	}

	private static function status_label( $status ) {
		return ucwords( str_replace( '_', ' ', sanitize_text_field( $status ) ) );
	}

	private static function schedule_line( $start_at, $end_at ) {
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

	public static function service_account_credentials() {
		$encrypted_json = EBM_Settings::get( 'google_service_account_json', '' );

		if ( '' === $encrypted_json ) {
			return array();
		}

		$json = EBM_Helpers::decrypt( $encrypted_json );

		if ( '' === $json ) {
			self::set_last_error( __( 'The saved Google service account JSON could not be decrypted.', 'electrical-booking-manager' ) );
			return array();
		}

		$credentials = json_decode( $json, true );

		if ( ! is_array( $credentials ) ) {
			self::set_last_error( __( 'The saved Google service account JSON is invalid.', 'electrical-booking-manager' ) );
			return array();
		}

		return $credentials;
	}

	public static function service_account_email() {
		$credentials = self::service_account_credentials();

		return sanitize_email( $credentials['client_email'] ?? '' );
	}

	private static function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private static function access_token_transient_key() {
		if ( 'service_account' === self::auth_mode() ) {
			$email = self::service_account_email();

			return self::ACCESS_TOKEN_TRANSIENT . '_service_account_' . md5( $email );
		}

		return self::ACCESS_TOKEN_TRANSIENT . '_oauth';
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
				'scope'                  => 'https://www.googleapis.com/auth/calendar.events',
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
			self::set_last_error( $response->get_error_message() );
			wp_safe_redirect( admin_url( 'admin.php?page=ebm-settings&google=failed&google_error=' . rawurlencode( $response->get_error_message() ) ) );
			exit;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( wp_remote_retrieve_response_code( $response ) >= 400 || empty( $body['refresh_token'] ) ) {
			$message = $body['error_description'] ?? ( $body['error'] ?? __( 'Google did not return a refresh token.', 'electrical-booking-manager' ) );

			self::set_last_error( $message );

			wp_safe_redirect( admin_url( 'admin.php?page=ebm-settings&google=failed&google_error=' . rawurlencode( $message ) ) );
			exit;
		}

		$settings = EBM_Settings::all();
		$settings['google_refresh_token'] = EBM_Helpers::encrypt( sanitize_text_field( $body['refresh_token'] ) );
		$settings['google_auth_mode']     = 'oauth';

		update_option( EBM_Settings::OPTION, $settings, false );

		self::clear_cache();
		self::clear_last_error();

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-settings&google=connected' ) );
		exit;
	}

	public static function disconnect() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ebm_google_disconnect' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'electrical-booking-manager' ) );
		}

		$settings = EBM_Settings::all();

		if ( 'service_account' === self::auth_mode() ) {
			$settings['google_service_account_json'] = '';
		} else {
			$settings['google_refresh_token'] = '';
		}

		update_option( EBM_Settings::OPTION, $settings, false );

		self::clear_cache();
		self::clear_last_error();

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-settings&google=disconnected' ) );
		exit;
	}

	private static function token( $force_refresh = false ) {
		if ( 'service_account' === self::auth_mode() ) {
			return self::service_account_token( $force_refresh );
		}

		return self::oauth_token( $force_refresh );
	}

	private static function service_account_token( $force_refresh = false ) {
		$cache_key = self::access_token_transient_key();

		if ( $force_refresh ) {
			delete_transient( $cache_key );
		}

		$cached = get_transient( $cache_key );

		if ( ! $force_refresh && $cached ) {
			return $cached;
		}

		$credentials = self::service_account_credentials();

		if ( empty( $credentials['client_email'] ) || empty( $credentials['private_key'] ) ) {
			self::set_last_error( __( 'Google service account JSON is missing the client email or private key.', 'electrical-booking-manager' ) );
			return '';
		}

		if ( ! function_exists( 'openssl_sign' ) ) {
			self::set_last_error( __( 'The PHP OpenSSL extension is required for Google service account authentication.', 'electrical-booking-manager' ) );
			return '';
		}

		$now = time();

		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$claim = array(
			'iss'   => $credentials['client_email'],
			'scope' => 'https://www.googleapis.com/auth/calendar',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'iat'   => $now,
			'exp'   => $now + 3600,
		);

		$jwt_header = self::base64url_encode( wp_json_encode( $header ) );
		$jwt_claim  = self::base64url_encode( wp_json_encode( $claim ) );

		$signing_input = $jwt_header . '.' . $jwt_claim;
		$signature     = '';

		$signed = openssl_sign(
			$signing_input,
			$signature,
			$credentials['private_key'],
			OPENSSL_ALGO_SHA256
		);

		if ( ! $signed ) {
			self::set_last_error( __( 'The Google service account token could not be signed. Check the private key in the JSON file.', 'electrical-booking-manager' ) );
			return '';
		}

		$assertion = $signing_input . '.' . self::base64url_encode( $signature );

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 20,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::set_last_error( $response->get_error_message() );
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( wp_remote_retrieve_response_code( $response ) >= 400 || empty( $body['access_token'] ) ) {
			$message = self::error_message(
				$response,
				__( 'Could not create a Google Calendar access token from the service account.', 'electrical-booking-manager' )
			);

			self::set_last_error( $message );

			return '';
		}

		$access_token = sanitize_text_field( $body['access_token'] );
		$expires_in   = max( 300, absint( $body['expires_in'] ?? 3600 ) - 120 );

		set_transient( $cache_key, $access_token, $expires_in );
		self::clear_last_error();

		return $access_token;
	}

	private static function oauth_token( $force_refresh = false ) {
		$cache_key = self::access_token_transient_key();

		if ( $force_refresh ) {
			delete_transient( $cache_key );
		}

		$cached = get_transient( $cache_key );

		if ( ! $force_refresh && $cached ) {
			return $cached;
		}

		$refresh_token = EBM_Helpers::decrypt( EBM_Settings::get( 'google_refresh_token', '' ) );

		if ( '' === $refresh_token ) {
			self::set_last_error( __( 'Google refresh token is missing or could not be decrypted. Reconnect Google Calendar.', 'electrical-booking-manager' ) );
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
			self::set_last_error( $response->get_error_message() );
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( wp_remote_retrieve_response_code( $response ) >= 400 || empty( $body['access_token'] ) ) {
			$message = self::error_message(
				$response,
				__( 'Could not refresh the Google Calendar access token. Reconnect Google Calendar.', 'electrical-booking-manager' )
			);

			self::set_last_error( $message );

			return '';
		}

		$access_token = sanitize_text_field( $body['access_token'] );
		$expires_in   = max( 300, absint( $body['expires_in'] ?? 3600 ) - 120 );

		set_transient( $cache_key, $access_token, $expires_in );
		self::clear_last_error();

		return $access_token;
	}

	private static function error_message( $response, $fallback ) {
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

	private static function google_request( $method, $url, $body = null, $timeout = 20 ) {
		$token = self::token();

		if ( ! $token ) {
			return new WP_Error(
				'ebm_google_token',
				__( 'Could not get a Google Calendar access token. Check the Google Calendar connection settings.', 'electrical-booking-manager' )
			);
		}

		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			self::set_last_error( $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( in_array( $code, array( 401, 403 ), true ) ) {
			$fresh_token = self::token( true );

			if ( ! $fresh_token ) {
				return new WP_Error(
					'ebm_google_token',
					__( 'Google Calendar rejected the saved connection. Check the Google Calendar settings.', 'electrical-booking-manager' )
				);
			}

			$args['headers']['Authorization'] = 'Bearer ' . $fresh_token;
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				self::set_last_error( $response->get_error_message() );
				return $response;
			}
		}

		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			self::set_last_error(
				self::error_message(
					$response,
					__( 'Google Calendar request failed.', 'electrical-booking-manager' )
				)
			);
		} else {
			self::clear_last_error();
		}

		return $response;
	}

	public static function shared_calendars() {
		if ( ! self::connected() ) {
			return new WP_Error(
				'ebm_google_not_connected',
				__( 'Google Calendar is not connected.', 'electrical-booking-manager' )
			);
		}

		$url = add_query_arg(
			array(
				'minAccessRole' => 'reader',
				'showHidden'    => 'true',
			),
			'https://www.googleapis.com/calendar/v3/users/me/calendarList'
		);

		$response = self::google_request( 'GET', $url, null, 20 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new WP_Error(
				'ebm_google_calendar_list',
				self::error_message(
					$response,
					__( 'Google shared calendars could not be loaded.', 'electrical-booking-manager' )
				)
			);
		}

		$body      = json_decode( wp_remote_retrieve_body( $response ), true );
		$calendars = array();

		foreach ( (array) ( $body['items'] ?? array() ) as $item ) {
			$id          = sanitize_text_field( $item['id'] ?? '' );
			$summary     = sanitize_text_field( $item['summary'] ?? '' );
			$access_role = sanitize_text_field( $item['accessRole'] ?? '' );

			if ( '' === $id ) {
				continue;
			}

			$calendars[] = array(
				'id'          => $id,
				'summary'     => $summary ?: $id,
				'access_role' => $access_role,
				'primary'     => ! empty( $item['primary'] ),
				'can_write'   => in_array( $access_role, array( 'writer', 'owner' ), true ),
			);
		}

		return $calendars;
	}

	public static function test_saved_calendar() {
		if ( ! self::connected() ) {
			return new WP_Error(
				'ebm_google_not_connected',
				__( 'Google Calendar is not connected.', 'electrical-booking-manager' )
			);
		}

		$calendar_id = EBM_Settings::get( 'google_calendar_id', '' );

		if ( '' === $calendar_id || 'primary' === $calendar_id ) {
			return new WP_Error(
				'ebm_google_missing_calendar_id',
				__( 'Select a shared Google Calendar before testing.', 'electrical-booking-manager' )
			);
		}

		$url = add_query_arg(
			array(
				'maxResults'   => 1,
				'singleEvents' => 'true',
				'orderBy'      => 'startTime',
				'timeMin'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			),
			'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $calendar_id ) . '/events'
		);

		$response = self::google_request( 'GET', $url, null, 20 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new WP_Error(
				'ebm_google_calendar_test',
				self::error_message(
					$response,
					__( 'The selected Google Calendar could not be tested.', 'electrical-booking-manager' )
				)
			);
		}

		return true;
	}

	public static function events( $time_min, $time_max ) {
		if ( ! self::connected() ) {
			return array();
		}

		$formatted_min = self::google_utc_datetime( $time_min );
		$formatted_max = self::google_utc_datetime( $time_max );

		if ( '' === $formatted_min || '' === $formatted_max ) {
			return new WP_Error(
				'ebm_google_bad_range',
				__( 'The calendar date range could not be formatted for Google.', 'electrical-booking-manager' )
			);
		}

		$calendar_id = EBM_Settings::get( 'google_calendar_id', 'primary' );
		$cache_key   = 'ebm_google_events_' . md5( $calendar_id . '|' . $formatted_min . '|' . $formatted_max );
		$cached      = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
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

		$response = self::google_request( 'GET', $url, null, 12 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new WP_Error(
				'ebm_google_events',
				self::error_message(
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

			if ( '' === $start ) {
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

	public static function conflicts( $segments ) {
		if ( ! self::connected() ) {
			return false;
		}

		$events = self::events_for_segments( $segments );

		if ( is_wp_error( $events ) ) {
			return true;
		}

		foreach ( (array) $segments as $segment ) {
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

	public static function get_event( $event_id ) {
		if ( ! self::connected() ) {
			return new WP_Error(
				'ebm_google_not_connected',
				__( 'Google Calendar is not connected.', 'electrical-booking-manager' )
			);
		}

		$event_id = sanitize_text_field( $event_id );

		if ( '' === $event_id ) {
			return new WP_Error(
				'ebm_google_missing_event_id',
				__( 'Google event ID is missing.', 'electrical-booking-manager' )
			);
		}

		$url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( EBM_Settings::get( 'google_calendar_id', 'primary' ) ) . '/events/' . rawurlencode( $event_id );

		$response = self::google_request( 'GET', $url, null, 20 );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 404 === $code || 410 === $code ) {
			return new WP_Error(
				'ebm_google_event_missing',
				__( 'The saved Google event no longer exists on this calendar.', 'electrical-booking-manager' )
			);
		}

		if ( $code >= 400 ) {
			return new WP_Error(
				'ebm_google_event_check',
				self::error_message(
					$response,
					__( 'The saved Google event could not be checked.', 'electrical-booking-manager' )
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['id'] ) ) {
			return new WP_Error(
				'ebm_google_event_bad_response',
				__( 'Google returned an invalid event response.', 'electrical-booking-manager' )
			);
		}

		return $body;
	}

	public static function event_exists( $event_id ) {
		return ! is_wp_error( self::get_event( $event_id ) );
	}

	private static function booking_addons_text( $booking ) {
		global $wpdb;

		if ( empty( $booking->addons_json ) ) {
			return 'None';
		}

		$selected_addons = json_decode( (string) $booking->addons_json, true );

		if ( ! is_array( $selected_addons ) || empty( $selected_addons ) ) {
			return 'None';
		}

		$clean_addons = array();

		foreach ( $selected_addons as $addon_id => $qty ) {
			$addon_id = absint( $addon_id );
			$qty      = absint( $qty );

			if ( $addon_id && $qty > 0 ) {
				$clean_addons[ $addon_id ] = $qty;
			}
		}

		if ( empty( $clean_addons ) ) {
			return 'None';
		}

		$addon_ids    = array_keys( $clean_addons );
		$placeholders = implode( ',', array_fill( 0, count( $addon_ids ), '%d' ) );
		$addons_table = EBM_Helpers::table( 'addons' );

		$addons = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, price, extra_duration_minutes, category
				FROM $addons_table
				WHERE id IN ($placeholders)
				ORDER BY category ASC, sort_order ASC, title ASC",
				$addon_ids
			)
		);

		if ( empty( $addons ) ) {
			return 'None';
		}

		$lines = array();

		foreach ( $addons as $addon ) {
			$qty = absint( $clean_addons[ (int) $addon->id ] ?? 0 );

			if ( $qty < 1 ) {
				continue;
			}

			$line = $qty . ' x ' . self::plain_text( $addon->title );

			if ( isset( $addon->price ) ) {
				$line .= ' - ' . self::money( $addon->price ) . ' each';
				$line .= ' - line total ' . self::money( (float) $addon->price * $qty );
			}

			if ( ! empty( $addon->category ) ) {
				$line .= ' - ' . self::plain_text( $addon->category );
			}

			$lines[] = $line;
		}

		if ( empty( $lines ) ) {
			return 'None';
		}

		return implode( "\n", $lines );
	}

	private static function booking_description( $booking, $booking_id ) {
		$description = array();

		$description[] = 'Created by Electrical Booking Manager';
		$description[] = 'Booking ID: #' . absint( $booking_id );
		$description[] = '';

		$description[] = 'Customer';
		$description[] = 'Customer: ' . self::plain_text( $booking->name ?? '' );
		$description[] = 'Email: ' . self::plain_text( $booking->email ?? '' );
		$description[] = 'Phone: ' . self::plain_text( $booking->phone ?? '' );
		$description[] = '';

		$description[] = 'Booking';
		$description[] = 'Service: ' . self::plain_text( $booking->job_title ?? '' );
		$description[] = 'Schedule: ' . self::schedule_line( $booking->start_at ?? '', $booking->end_at ?? '' );
		$description[] = 'Status: ' . self::status_label( $booking->status ?? '' );
		$description[] = '';

		$description[] = 'Payment';
		$description[] = 'Total: ' . self::money( $booking->total_amount ?? 0 );
		$description[] = 'Deposit paid: ' . self::money( $booking->deposit_amount ?? 0 );
		$description[] = 'Balance: ' . self::money( $booking->balance_amount ?? 0 );
		$description[] = '';

		$description[] = 'Add-ons';
		$description[] = self::booking_addons_text( $booking );

		if ( ! empty( $booking->address ) ) {
			$description[] = '';
			$description[] = 'Service address';
			$description[] = self::plain_text( $booking->address );
		}

		return implode( "\n", $description );
	}

	public static function create_event( $booking_id ) {
		global $wpdb;

		$booking_id = absint( $booking_id );

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

		$start = self::google_local_datetime( $booking->start_at );
		$end   = self::google_local_datetime( $booking->end_at );

		if ( '' === $start || '' === $end ) {
			self::set_last_error( __( 'Booking date could not be formatted for Google Calendar.', 'electrical-booking-manager' ) );
			return '';
		}

		$summary = 'Booking: ' . self::plain_text( $booking->job_title );

		if ( ! empty( $booking->deposit_amount ) ) {
			$summary .= ' - Deposit ' . self::money( $booking->deposit_amount );
		}

		$event = array(
			'summary'     => $summary,
			'description' => self::booking_description( $booking, $booking_id ),
			'location'    => self::plain_text( $booking->address ?? '' ),
			'start'       => array(
				'dateTime' => $start,
				'timeZone' => self::google_timezone(),
			),
			'end'         => array(
				'dateTime' => $end,
				'timeZone' => self::google_timezone(),
			),
			'extendedProperties' => array(
				'private' => array(
					'ebm_booking_id' => (string) $booking_id,
					'ebm_source'     => 'electrical_booking_manager',
				),
			),
		);

		$url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( EBM_Settings::get( 'google_calendar_id', 'primary' ) ) . '/events';

		$response = self::google_request( 'POST', $url, $event, 20 );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			self::set_last_error(
				self::error_message(
					$response,
					__( 'Google Calendar event could not be created.', 'electrical-booking-manager' )
				)
			);

			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['id'] ) ) {
			$wpdb->update(
				EBM_Helpers::table( 'bookings' ),
				array(
					'google_event_id' => sanitize_text_field( $body['id'] ),
					'updated_at'      => current_time( 'mysql' ),
				),
				array( 'id' => $booking_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			self::clear_cache();
			self::clear_last_error();

			return sanitize_text_field( $body['id'] );
		}

		self::set_last_error( __( 'Google Calendar did not return an event ID.', 'electrical-booking-manager' ) );

		return '';
	}

	public static function delete_event( $event_id ) {
		if ( ! self::connected() ) {
			return false;
		}

		$event_id = sanitize_text_field( $event_id );

		if ( '' === $event_id ) {
			return false;
		}

		$url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( EBM_Settings::get( 'google_calendar_id', 'primary' ) ) . '/events/' . rawurlencode( $event_id );

		$response = self::google_request( 'DELETE', $url, null, 20 );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		self::clear_cache();

		return in_array( wp_remote_retrieve_response_code( $response ), array( 200, 204, 404, 410 ), true );
	}

	public static function recreate_event( $booking_id ) {
		global $wpdb;

		$booking_id = absint( $booking_id );

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . EBM_Helpers::table( 'bookings' ) . ' WHERE id = %d',
				$booking_id
			)
		);

		if ( ! $booking ) {
			return '';
		}

		if ( ! empty( $booking->google_event_id ) ) {
			self::delete_event( $booking->google_event_id );
		}

		$wpdb->update(
			EBM_Helpers::table( 'bookings' ),
			array(
				'google_event_id' => '',
				'updated_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $booking_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return self::create_event( $booking_id );
	}
}