<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_REST {
	public static function routes() {
		register_rest_route(
			'ebm/v1',
			'/jobs',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'jobs' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'ebm/v1',
			'/addons/(?P<job_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'addons' ),
				'permission_callback' => '__return_true',
			)
		);

		foreach ( array( 'slots', 'quote', 'book' ) as $route ) {
			register_rest_route(
				'ebm/v1',
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $route ),
					'permission_callback' => array( __CLASS__, 'nonce' ),
				)
			);
		}
	}

	public static function nonce( WP_REST_Request $request ) {
		return (bool) wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
	}

	public static function jobs() {
		global $wpdb;

		return array(
			'jobs' => $wpdb->get_results( 'SELECT id,title,description,duration_minutes,custom_fields FROM ' . EBM_Helpers::table( 'jobs' ) . ' WHERE is_active=1 ORDER BY title ASC' ),
		);
	}

	public static function addons( WP_REST_Request $request ) {
		global $wpdb;

		return array(
			'addons' => $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id,title,description,min_qty,max_qty,extra_duration_minutes FROM ' . EBM_Helpers::table( 'addons' ) . ' WHERE job_id=%d AND is_active=1 ORDER BY title ASC',
					absint( $request['job_id'] )
				)
			),
		);
	}

	public static function slots( WP_REST_Request $request ) {
		$date = sanitize_text_field( $request->get_param( 'date' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Invalid date.', 'electrical-booking-manager' ) ), 400 );
		}

		return array(
			'slots' => EBM_Scheduler::slots(
				absint( $request->get_param( 'job_id' ) ),
				$date,
				EBM_Helpers::clean_addons( $request->get_param( 'addons' ) )
			),
		);
	}

	public static function quote( WP_REST_Request $request ) {
		global $wpdb;

		$job_id = absint( $request->get_param( 'job_id' ) );
		$job    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . EBM_Helpers::table( 'jobs' ) . ' WHERE id=%d AND is_active=1', $job_id ) );

		if ( ! $job ) {
			return new WP_REST_Response( array( 'message' => __( 'Invalid job.', 'electrical-booking-manager' ) ), 400 );
		}

		$addons  = EBM_Helpers::clean_addons( $request->get_param( 'addons' ) );
		$total   = EBM_Scheduler::price( $job_id, $addons );
		$deposit = EBM_Scheduler::deposit( $job, $total );

		return array(
			'total'   => $total,
			'deposit' => $deposit,
			'balance' => round( $total - $deposit, 2 ),
		);
	}

	public static function book( WP_REST_Request $request ) {
		if ( EBM_DB::rate_limited( 'book' ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Too many attempts. Please try again later.', 'electrical-booking-manager' ) ), 429 );
		}

		global $wpdb;

		$job_id = absint( $request->get_param( 'job_id' ) );
		$job    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . EBM_Helpers::table( 'jobs' ) . ' WHERE id=%d AND is_active=1', $job_id ) );

		if ( ! $job ) {
			return new WP_REST_Response( array( 'message' => __( 'Invalid job.', 'electrical-booking-manager' ) ), 400 );
		}

		$customer = (array) $request->get_param( 'customer' );
		$name     = sanitize_text_field( $customer['name'] ?? '' );
		$email    = sanitize_email( $customer['email'] ?? '' );
		$phone    = sanitize_text_field( $customer['phone'] ?? '' );
		$address  = sanitize_textarea_field( $customer['address'] ?? '' );

		if ( ! $request->get_param( 'privacy' ) || ! $name || ! is_email( $email ) || ! $phone || ! $address ) {
			return new WP_REST_Response( array( 'message' => __( 'Please complete your details and accept the privacy notice.', 'electrical-booking-manager' ) ), 400 );
		}

		$addons   = EBM_Helpers::clean_addons( $request->get_param( 'addons' ) );
		$date     = sanitize_text_field( $request->get_param( 'date' ) );
		$time     = sanitize_text_field( $request->get_param( 'time' ) );
		$duration = EBM_Scheduler::duration( $job_id, $addons );
		$segments = EBM_Scheduler::segments( $date, $time, $duration );

		if ( ! $segments || ! EBM_Scheduler::available( $segments ) ) {
			return new WP_REST_Response( array( 'message' => __( 'That slot is no longer available.', 'electrical-booking-manager' ) ), 409 );
		}

		$total   = EBM_Scheduler::price( $job_id, $addons );
		$deposit = EBM_Scheduler::deposit( $job, $total );
		$now     = current_time( 'mysql' );

		$customers_table = EBM_Helpers::table( 'customers' );
		$existing_customer_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM $customers_table WHERE email = %s ORDER BY id DESC LIMIT 1", $email )
		);

		if ( $existing_customer_id ) {
			$wpdb->update(
				$customers_table,
				array(
					'name'                => $name,
					'phone'               => $phone,
					'address'             => $address,
					'privacy_accepted_at' => $now,
					'updated_at'          => $now,
				),
				array( 'id' => $existing_customer_id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			$customer_id = $existing_customer_id;
		} else {
			$wpdb->insert(
				$customers_table,
				array(
					'name'                => $name,
					'email'               => $email,
					'phone'               => $phone,
					'address'             => $address,
					'privacy_accepted_at' => $now,
					'created_at'          => $now,
					'updated_at'          => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$customer_id = (int) $wpdb->insert_id;
		}

		$wpdb->insert(
			EBM_Helpers::table( 'bookings' ),
			array(
				'public_token'       => EBM_Helpers::token(),
				'job_id'             => $job_id,
				'customer_id'        => $customer_id,
				'status'             => 'pending_payment',
				'start_at'           => $segments[0]['start_at'],
				'end_at'             => end( $segments )['end_at'],
				'total_minutes'      => $duration,
				'total_amount'       => $total,
				'deposit_amount'     => $deposit,
				'balance_amount'     => round( $total - $deposit, 2 ),
				'addons_json'        => wp_json_encode( $addons ),
				'custom_fields_json' => wp_json_encode( array_map( 'sanitize_text_field', (array) $request->get_param( 'custom_fields' ) ) ),
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%f', '%f', '%s', '%s', '%s', '%s' )
		);

		$booking_id = (int) $wpdb->insert_id;

		foreach ( $segments as $seg ) {
			$wpdb->insert(
				EBM_Helpers::table( 'booking_days' ),
				array(
					'booking_id' => $booking_id,
					'work_date'  => $seg['date'],
					'start_at'   => $seg['start_at'],
					'end_at'     => $seg['end_at'],
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}

		$session = EBM_Stripe::checkout( $booking_id, $deposit, 'deposit' );

		if ( is_wp_error( $session ) ) {
			return new WP_REST_Response( array( 'message' => $session->get_error_message() ), 500 );
		}

		return array(
			'booking_id'   => $booking_id,
			'checkout_url' => esc_url_raw( $session['url'] ?? '' ),
		);
	}
}
