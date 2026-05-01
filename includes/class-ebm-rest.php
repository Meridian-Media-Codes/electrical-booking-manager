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
			'/addons',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'addons' ),
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

		register_rest_route(
			'ebm/v1',
			'/slots',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'slots' ),
				'permission_callback' => array( __CLASS__, 'nonce' ),
			)
		);

		register_rest_route(
			'ebm/v1',
			'/quote',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'quote' ),
				'permission_callback' => array( __CLASS__, 'nonce' ),
			)
		);

		register_rest_route(
			'ebm/v1',
			'/book',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'book' ),
				'permission_callback' => array( __CLASS__, 'nonce' ),
			)
		);

		register_rest_route(
			'ebm/v1',
			'/bookings',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'book' ),
				'permission_callback' => array( __CLASS__, 'nonce' ),
			)
		);
	}

	public static function nonce( WP_REST_Request $request ) {
		return (bool) wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' );
	}

	public static function jobs() {
		global $wpdb;

		$jobs_table = EBM_Helpers::table( 'jobs' );

		return array(
			'jobs' => $wpdb->get_results(
				"SELECT id, title, description, duration_minutes, custom_fields
				FROM $jobs_table
				WHERE is_active = 1
				ORDER BY title ASC"
			),
		);
	}

	public static function addons( WP_REST_Request $request ) {
		global $wpdb;

		$job_id = absint( $request->get_param( 'job_id' ) );

		if ( ! $job_id && isset( $request['job_id'] ) ) {
			$job_id = absint( $request['job_id'] );
		}

		if ( ! $job_id ) {
			return new WP_REST_Response(
				array(
					'message' => __( 'Invalid job.', 'electrical-booking-manager' ),
				),
				400
			);
		}

		$addons_table = EBM_Helpers::table( 'addons' );

		return array(
			'addons' => $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title, description, min_qty, max_qty, extra_duration_minutes
					FROM $addons_table
					WHERE job_id = %d
					AND is_active = 1
					ORDER BY title ASC",
					$job_id
				)
			),
		);
	}

	public static function slots( WP_REST_Request $request ) {
		$date = sanitize_text_field( $request->get_param( 'date' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_REST_Response(
				array(
					'message' => __( 'Invalid date.', 'electrical-booking-manager' ),
				),
				400
			);
		}

		$job_id = absint( $request->get_param( 'job_id' ) );
		$addons = EBM_Helpers::clean_addons( $request->get_param( 'addons' ) );

		return array(
			'slots' => EBM_Scheduler::slots(
				$job_id,
				$date,
				$addons
			),
		);
	}

	public static function quote( WP_REST_Request $request ) {
		global $wpdb;

		$job_id = absint( $request->get_param( 'job_id' ) );
		$job    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . EBM_Helpers::table( 'jobs' ) . ' WHERE id = %d AND is_active = 1',
				$job_id
			)
		);

		if ( ! $job ) {
			return new WP_REST_Response(
				array(
					'message' => __( 'Invalid job.', 'electrical-booking-manager' ),
				),
				400
			);
		}

		$addons = EBM_Helpers::clean_addons( $request->get_param( 'addons' ) );

		$original_total = EBM_Scheduler::price( $job_id, $addons );
		$total          = $original_total;

		$voucher_code    = class_exists( 'EBM_Discounts' ) ? EBM_Discounts::normalise_code( $request->get_param( 'voucher_code' ) ?? '' ) : '';
		$discount_id     = 0;
		$discount_amount = 0;

		if ( '' !== $voucher_code && class_exists( 'EBM_Discounts' ) ) {
			$discount_result = EBM_Discounts::validate( $voucher_code, $job_id, $total );

			if ( is_wp_error( $discount_result ) ) {
				return new WP_REST_Response(
					array(
						'message' => $discount_result->get_error_message(),
					),
					400
				);
			}

			$discount_id     = absint( $discount_result['id'] );
			$discount_amount = (float) $discount_result['discount_amount'];
			$total           = max( 0, round( $total - $discount_amount, 2 ) );
		}

		$deposit = EBM_Scheduler::deposit( $job, $total );

		return array(
			'original_total'   => round( $original_total, 2 ),
			'total'            => round( $total, 2 ),
			'total_amount'     => round( $total, 2 ),
			'deposit'          => round( $deposit, 2 ),
			'deposit_amount'   => round( $deposit, 2 ),
			'balance'          => round( $total - $deposit, 2 ),
			'balance_amount'   => round( $total - $deposit, 2 ),
			'voucher_code'     => $voucher_code,
			'discount_id'      => $discount_id,
			'discount_amount'  => round( $discount_amount, 2 ),
		);
	}

	public static function book( WP_REST_Request $request ) {
		if ( EBM_DB::rate_limited( 'book' ) ) {
			return new WP_REST_Response(
				array(
					'message' => __( 'Too many attempts. Please try again later.', 'electrical-booking-manager' ),
				),
				429
			);
		}

		global $wpdb;

		$job_id = absint( $request->get_param( 'job_id' ) );
		$job    = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . EBM_Helpers::table( 'jobs' ) . ' WHERE id = %d AND is_active = 1',
				$job_id
			)
		);

		if ( ! $job ) {
			return new WP_REST_Response(
				array(
					'message' => __( 'Invalid job.', 'electrical-booking-manager' ),
				),
				400
			);
		}

		$customer = (array) $request->get_param( 'customer' );

		$name    = sanitize_text_field( $customer['name'] ?? '' );
		$email   = sanitize_email( $customer['email'] ?? '' );
		$phone   = sanitize_text_field( $customer['phone'] ?? '' );
		$address = sanitize_textarea_field( $customer['address'] ?? '' );

		$privacy = false;

		if ( isset( $customer['privacy'] ) ) {
			$privacy = (bool) $customer['privacy'];
		} elseif ( null !== $request->get_param( 'privacy' ) ) {
			$privacy = (bool) $request->get_param( 'privacy' );
		}

		if ( ! $privacy || ! $name || ! is_email( $email ) || ! $phone || ! $address ) {
			return new WP_REST_Response(
				array(
					'message' => __( 'Please complete your details and accept the privacy notice.', 'electrical-booking-manager' ),
				),
				400
			);
		}

		$addons   = EBM_Helpers::clean_addons( $request->get_param( 'addons' ) );
		$date     = sanitize_text_field( $request->get_param( 'date' ) );
		$time     = sanitize_text_field( $request->get_param( 'time' ) );
		$duration = EBM_Scheduler::duration( $job_id, $addons );
		$segments = EBM_Scheduler::segments( $date, $time, $duration );

		if ( ! $segments || ! EBM_Scheduler::available( $segments ) ) {
			return new WP_REST_Response(
				array(
					'message' => __( 'That slot is no longer available.', 'electrical-booking-manager' ),
				),
				409
			);
		}

		$original_total = EBM_Scheduler::price( $job_id, $addons );
		$total          = $original_total;

		$voucher_code    = class_exists( 'EBM_Discounts' ) ? EBM_Discounts::normalise_code( $request->get_param( 'voucher_code' ) ?? '' ) : '';
		$discount_id     = 0;
		$discount_amount = 0;

		if ( '' !== $voucher_code && class_exists( 'EBM_Discounts' ) ) {
			$discount_result = EBM_Discounts::validate( $voucher_code, $job_id, $total );

			if ( is_wp_error( $discount_result ) ) {
				return new WP_REST_Response(
					array(
						'message' => $discount_result->get_error_message(),
					),
					400
				);
			}

			$discount_id     = absint( $discount_result['id'] );
			$discount_amount = (float) $discount_result['discount_amount'];
			$total           = max( 0, round( $total - $discount_amount, 2 ) );
		}

		$deposit = EBM_Scheduler::deposit( $job, $total );
		$now     = current_time( 'mysql' );

		$customers_table      = EBM_Helpers::table( 'customers' );
		$existing_customer_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $customers_table WHERE email = %s ORDER BY id DESC LIMIT 1",
				$email
			)
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

		$bookings_table = EBM_Helpers::table( 'bookings' );

		$booking_data = array(
			'public_token'       => EBM_Helpers::token(),
			'job_id'             => $job_id,
			'customer_id'        => $customer_id,
			'status'             => $deposit > 0 ? 'pending_payment' : 'confirmed',
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
		);

		$booking_formats = array(
			'%s',
			'%d',
			'%d',
			'%s',
			'%s',
			'%s',
			'%d',
			'%f',
			'%f',
			'%f',
			'%s',
			'%s',
			'%s',
			'%s',
		);

		/*
		 * Future schema upgrade:
		 * We should add discount_id, voucher_code, original_total_amount and discount_amount columns
		 * to the bookings table. For now, the discount is safely applied to totals before payment.
		 */

		$wpdb->insert(
			$bookings_table,
			$booking_data,
			$booking_formats
		);

		$booking_id = (int) $wpdb->insert_id;

		if ( ! $booking_id ) {
			return new WP_REST_Response(
				array(
					'message' => __( 'The booking could not be created.', 'electrical-booking-manager' ),
				),
				500
			);
		}

		foreach ( $segments as $segment ) {
			$wpdb->insert(
				EBM_Helpers::table( 'booking_days' ),
				array(
					'booking_id' => $booking_id,
					'work_date'  => $segment['date'],
					'start_at'   => $segment['start_at'],
					'end_at'     => $segment['end_at'],
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}

		if ( $discount_id && class_exists( 'EBM_Discounts' ) ) {
			EBM_Discounts::increment_usage( $discount_id );
		}

		if ( class_exists( 'EBM_Google' ) && EBM_Google::connected() ) {
			EBM_Google::create_event( $booking_id );
		}

if ( $deposit <= 0 ) {
	return array(
		'booking_id'       => $booking_id,
		'checkout_url'     => '',
		'payment_required' => false,
		'message'          => __( 'Booking confirmed. No payment is due.', 'electrical-booking-manager' ),
		'voucher_code'     => $voucher_code,
		'discount_id'      => $discount_id,
		'discount_amount'  => round( $discount_amount, 2 ),
		'original_total'   => round( $original_total, 2 ),
		'total'            => round( $total, 2 ),
		'deposit'          => round( $deposit, 2 ),
		'balance'          => round( $total - $deposit, 2 ),
	);
}

		$session = EBM_Stripe::checkout( $booking_id, $deposit, 'deposit' );

		if ( is_wp_error( $session ) ) {
			return new WP_REST_Response(
				array(
					'message' => $session->get_error_message(),
				),
				500
			);
		}

		return array(
			'booking_id'       => $booking_id,
			'checkout_url'     => esc_url_raw( $session['url'] ?? '' ),
			'payment_required' => true,
			'voucher_code'     => $voucher_code,
			'discount_id'      => $discount_id,
			'discount_amount'  => round( $discount_amount, 2 ),
			'original_total'   => round( $original_total, 2 ),
			'total'            => round( $total, 2 ),
			'deposit'          => round( $deposit, 2 ),
			'balance'          => round( $total - $deposit, 2 ),
		);
	}
}