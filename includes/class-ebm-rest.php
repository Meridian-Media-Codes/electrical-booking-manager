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
			'/month-availability',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'month_availability' ),
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

	private static function normalise_uk_postcode( $postcode ) {
		$postcode = strtoupper( sanitize_text_field( wp_unslash( $postcode ) ) );
		$postcode = preg_replace( '/\s+/', '', $postcode );

		if ( strlen( $postcode ) < 5 || strlen( $postcode ) > 7 ) {
			return '';
		}

		return substr( $postcode, 0, -3 ) . ' ' . substr( $postcode, -3 );
	}

	private static function postcode_allowed( $postcode ) {
		$postcode = self::normalise_uk_postcode( $postcode );

		if ( '' === $postcode ) {
			return false;
		}

		$compact = preg_replace( '/\s+/', '', strtoupper( $postcode ) );

		$prefixes = method_exists( 'EBM_Settings', 'allowed_postcode_prefixes' )
			? EBM_Settings::allowed_postcode_prefixes()
			: array( 'FY' );

		foreach ( $prefixes as $prefix ) {
			$prefix = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $prefix ) );

			if ( '' !== $prefix && 0 === strpos( $compact, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	private static function parse_timestamp( $value ) {
		$timestamp = strtotime( (string) $value );

		return $timestamp ? $timestamp : 0;
	}

	private static function intervals_overlap( $start_a, $end_a, $start_b, $end_b ) {
		return $start_a < $end_b && $end_a > $start_b;
	}

	private static function google_event_interval( $event ) {
		$timezone = wp_timezone();

		$start = (string) ( $event['start'] ?? '' );
		$end   = (string) ( $event['end'] ?? '' );

		if ( '' === $start ) {
			return null;
		}

		try {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
				$start_dt = new DateTimeImmutable( $start . ' 00:00:00', $timezone );
				$end_dt   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end )
					? new DateTimeImmutable( $end . ' 00:00:00', $timezone )
					: $start_dt->modify( '+1 day' );

				return array(
					'start' => $start_dt->getTimestamp(),
					'end'   => $end_dt->getTimestamp(),
				);
			}

			$start_dt = new DateTimeImmutable( $start, $timezone );
			$end_dt   = '' !== $end ? new DateTimeImmutable( $end, $timezone ) : $start_dt;

			return array(
				'start' => $start_dt->getTimestamp(),
				'end'   => max( $end_dt->getTimestamp(), $start_dt->getTimestamp() ),
			);
		} catch ( Exception $e ) {
			return null;
		}
	}

	private static function preload_plugin_blocks( $range_start, $range_end ) {
		global $wpdb;

		$days_table     = EBM_Helpers::table( 'booking_days' );
		$bookings_table = EBM_Helpers::table( 'bookings' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.start_at, d.end_at
				FROM $days_table d
				INNER JOIN $bookings_table b ON b.id = d.booking_id
				WHERE b.status IN ('pending_payment','confirmed')
				AND d.start_at < %s
				AND d.end_at > %s",
				$range_end,
				$range_start
			)
		);

		$blocks = array();

		foreach ( (array) $rows as $row ) {
			$start = self::parse_timestamp( $row->start_at );
			$end   = self::parse_timestamp( $row->end_at );

			if ( $start && $end && $end > $start ) {
				$blocks[] = array(
					'start' => $start,
					'end'   => $end,
				);
			}
		}

		return $blocks;
	}

	private static function preload_google_blocks( $range_start, $range_end ) {
		if ( ! class_exists( 'EBM_Google' ) || ! EBM_Google::connected() || ! method_exists( 'EBM_Google', 'events' ) ) {
			return array();
		}

		$events = EBM_Google::events( $range_start, $range_end );

		if ( is_wp_error( $events ) ) {
			/*
			 * Fail closed. If Google cannot be checked, avoid selling dates that may be busy.
			 */
			return new WP_Error( 'ebm_google_month_check', $events->get_error_message() );
		}

		$blocks = array();

		foreach ( (array) $events as $event ) {
			$interval = self::google_event_interval( $event );

			if ( $interval && $interval['end'] > $interval['start'] ) {
				$blocks[] = $interval;
			}
		}

		return $blocks;
	}

	private static function candidate_available_from_blocks( $segments, $plugin_blocks, $google_blocks, $max_bookings, $buffer_minutes ) {
		foreach ( (array) $segments as $segment ) {
			$segment_start = self::parse_timestamp( $segment['start_at'] ?? '' );
			$segment_end   = self::parse_timestamp( $segment['end_at'] ?? '' );

			if ( ! $segment_start || ! $segment_end || $segment_end <= $segment_start ) {
				return false;
			}

			$check_start = $segment_start - ( $buffer_minutes * MINUTE_IN_SECONDS );
			$check_end   = $segment_end + ( $buffer_minutes * MINUTE_IN_SECONDS );

			$plugin_overlap_count = 0;

			foreach ( $plugin_blocks as $block ) {
				if ( self::intervals_overlap( $check_start, $check_end, $block['start'], $block['end'] ) ) {
					$plugin_overlap_count++;

					if ( $plugin_overlap_count >= $max_bookings ) {
						return false;
					}
				}
			}

			foreach ( $google_blocks as $block ) {
				if ( self::intervals_overlap( $check_start, $check_end, $block['start'], $block['end'] ) ) {
					return false;
				}
			}
		}

		return true;
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
				array( 'message' => __( 'Invalid job.', 'electrical-booking-manager' ) ),
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
				array( 'message' => __( 'Invalid date.', 'electrical-booking-manager' ) ),
				400
			);
		}

		return array(
			'slots' => EBM_Scheduler::slots(
				absint( $request->get_param( 'job_id' ) ),
				$date,
				EBM_Helpers::clean_addons( $request->get_param( 'addons' ) )
			),
		);
	}

	public static function month_availability( WP_REST_Request $request ) {
		$month = sanitize_text_field( $request->get_param( 'month' ) );

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Invalid month.', 'electrical-booking-manager' ) ),
				400
			);
		}

		$job_id = absint( $request->get_param( 'job_id' ) );

		if ( ! $job_id ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Please choose a job first.', 'electrical-booking-manager' ) ),
				400
			);
		}

		$addons   = EBM_Helpers::clean_addons( $request->get_param( 'addons' ) );
		$duration = EBM_Scheduler::duration( $job_id, $addons );

		if ( ! $duration ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Invalid job duration.', 'electrical-booking-manager' ) ),
				400
			);
		}

		$cache_key = 'ebm_fast_month_' . md5(
			wp_json_encode(
				array(
					'month'    => $month,
					'job_id'   => $job_id,
					'addons'   => $addons,
					'duration' => $duration,
					'start'    => EBM_Settings::get( 'work_start', '09:00' ),
					'end'      => EBM_Settings::get( 'work_end', '17:00' ),
					'buffer'   => EBM_Settings::get( 'buffer_minutes', 15 ),
					'days'     => EBM_Settings::get( 'business_days', array( '1', '2', '3', '4', '5' ) ),
					'holidays' => EBM_Settings::get( 'holidays', '' ),
				)
			)
		);

		$cached = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$timezone = wp_timezone();

		try {
			$first_day = new DateTimeImmutable( $month . '-01 00:00:00', $timezone );
		} catch ( Exception $e ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Invalid month.', 'electrical-booking-manager' ) ),
				400
			);
		}

		$weekday_offset = (int) $first_day->format( 'N' ) - 1;
		$calendar_start = $first_day->modify( '-' . $weekday_offset . ' days' );
		$calendar_end   = $calendar_start->modify( '+41 days' )->setTime( 23, 59, 59 );

		$work_start = EBM_Settings::get( 'work_start', '09:00' );
		$work_end   = EBM_Settings::get( 'work_end', '17:00' );

		$work_start_dt = DateTimeImmutable::createFromFormat( 'H:i', $work_start, $timezone );
		$work_end_dt   = DateTimeImmutable::createFromFormat( 'H:i', $work_end, $timezone );

		$daily_minutes = 480;

		if ( $work_start_dt && $work_end_dt ) {
			$daily_minutes = max( 30, (int) ( ( $work_end_dt->getTimestamp() - $work_start_dt->getTimestamp() ) / 60 ) );
		}

		$extra_days = min( 90, max( 14, (int) ceil( $duration / $daily_minutes ) + 14 ) );
		$query_end  = $calendar_end->modify( '+' . $extra_days . ' days' );

		$range_start = $calendar_start->format( 'Y-m-d 00:00:00' );
		$range_end   = $query_end->format( 'Y-m-d 23:59:59' );

		$plugin_blocks = self::preload_plugin_blocks( $range_start, $range_end );
		$google_blocks = self::preload_google_blocks( $range_start, $range_end );

		if ( is_wp_error( $google_blocks ) ) {
			return new WP_REST_Response(
				array( 'message' => $google_blocks->get_error_message() ),
				500
			);
		}

		$max_bookings   = max( 1, absint( EBM_Settings::get( 'max_bookings_per_slot', 1 ) ) );
		$buffer_minutes = absint( EBM_Settings::get( 'buffer_minutes', 15 ) );
		$today          = new DateTimeImmutable( 'today', $timezone );

		$available_dates   = array();
		$unavailable_dates = array();

		for ( $i = 0; $i < 42; $i++ ) {
			$date_object = $calendar_start->modify( '+' . $i . ' days' );
			$date        = $date_object->format( 'Y-m-d' );

			if ( $date_object < $today ) {
				$unavailable_dates[] = $date;
				continue;
			}

			$mutable_date = new DateTime( $date, $timezone );

			if ( ! EBM_Scheduler::is_business_day( $mutable_date ) ) {
				$unavailable_dates[] = $date;
				continue;
			}

			$day_has_slot = false;
			$cursor       = new DateTimeImmutable( $date . ' ' . $work_start, $timezone );
			$limit        = new DateTimeImmutable( $date . ' ' . $work_end, $timezone );

			while ( $cursor < $limit ) {
				$time     = $cursor->format( 'H:i' );
				$segments = EBM_Scheduler::segments( $date, $time, $duration );

				if (
					$segments
					&& self::candidate_available_from_blocks(
						$segments,
						$plugin_blocks,
						$google_blocks,
						$max_bookings,
						$buffer_minutes
					)
				) {
					$day_has_slot = true;
					break;
				}

				$cursor = $cursor->modify( '+30 minutes' );
			}

			if ( $day_has_slot ) {
				$available_dates[] = $date;
			} else {
				$unavailable_dates[] = $date;
			}
		}

		$response = array(
			'month'             => $month,
			'available_dates'   => $available_dates,
			'unavailable_dates' => $unavailable_dates,
			'generated_at'      => current_time( 'mysql' ),
			'strategy'          => 'preloaded_range',
		);

		set_transient( $cache_key, $response, 60 );

		return $response;
	}

	public static function quote( WP_REST_Request $request ) {
		global $wpdb;

		$job_id = absint( $request->get_param( 'job_id' ) );

		$job = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . EBM_Helpers::table( 'jobs' ) . ' WHERE id = %d AND is_active = 1',
				$job_id
			)
		);

		if ( ! $job ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Invalid job.', 'electrical-booking-manager' ) ),
				400
			);
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
			return new WP_REST_Response(
				array( 'message' => __( 'Too many attempts. Please try again later.', 'electrical-booking-manager' ) ),
				429
			);
		}

		global $wpdb;

		$job_id = absint( $request->get_param( 'job_id' ) );

		$job = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . EBM_Helpers::table( 'jobs' ) . ' WHERE id = %d AND is_active = 1',
				$job_id
			)
		);

		if ( ! $job ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Invalid job.', 'electrical-booking-manager' ) ),
				400
			);
		}

		$customer = (array) $request->get_param( 'customer' );

		$name     = sanitize_text_field( $customer['name'] ?? '' );
		$email    = sanitize_email( $customer['email'] ?? '' );
		$phone    = sanitize_text_field( $customer['phone'] ?? '' );
		$postcode = self::normalise_uk_postcode( $customer['postcode'] ?? '' );

		$line_1  = sanitize_text_field( $customer['line_1'] ?? '' );
		$line_2  = sanitize_text_field( $customer['line_2'] ?? '' );
		$town    = sanitize_text_field( $customer['town'] ?? '' );
		$county  = sanitize_text_field( $customer['county'] ?? '' );
		$address = sanitize_textarea_field( $customer['address'] ?? '' );

		if ( '' === $address ) {
			$address = implode(
				"\n",
				array_filter(
					array(
						$line_1,
						$line_2,
						$town,
						$county,
						$postcode,
					)
				)
			);
		}

		$privacy = isset( $customer['privacy'] ) ? (bool) $customer['privacy'] : (bool) $request->get_param( 'privacy' );

		if ( ! $privacy || ! $name || ! is_email( $email ) || ! $phone || ! $address ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Please complete your details and accept the privacy notice.', 'electrical-booking-manager' ) ),
				400
			);
		}

		if ( $postcode && ! self::postcode_allowed( $postcode ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'Sorry, bookings are only available for FY postcodes.', 'electrical-booking-manager' ) ),
				403
			);
		}

		$addons   = EBM_Helpers::clean_addons( $request->get_param( 'addons' ) );
		$date     = sanitize_text_field( $request->get_param( 'date' ) );
		$time     = sanitize_text_field( $request->get_param( 'time' ) );
		$duration = EBM_Scheduler::duration( $job_id, $addons );
		$segments = EBM_Scheduler::segments( $date, $time, $duration );

		if ( ! $segments || ! EBM_Scheduler::available( $segments ) ) {
			return new WP_REST_Response(
				array( 'message' => __( 'That slot is no longer available.', 'electrical-booking-manager' ) ),
				409
			);
		}

		$total   = EBM_Scheduler::price( $job_id, $addons );
		$deposit = EBM_Scheduler::deposit( $job, $total );
		$now     = current_time( 'mysql' );

		$wpdb->insert(
			EBM_Helpers::table( 'customers' ),
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

		$status = $deposit > 0 ? 'pending_payment' : 'confirmed';

		$wpdb->insert(
			EBM_Helpers::table( 'bookings' ),
			array(
				'public_token'       => EBM_Helpers::token(),
				'job_id'             => $job_id,
				'customer_id'        => $customer_id,
				'status'             => $status,
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

		if ( $deposit <= 0 ) {
			if ( class_exists( 'EBM_Google' ) && EBM_Google::connected() ) {
				EBM_Google::create_event( $booking_id );
			}

			return array(
				'booking_id'       => $booking_id,
				'checkout_url'     => '',
				'payment_required' => false,
				'message'          => __( 'Booking confirmed. No payment is due.', 'electrical-booking-manager' ),
				'total'            => round( $total, 2 ),
				'deposit'          => round( $deposit, 2 ),
				'balance'          => round( $total - $deposit, 2 ),
			);
		}

		$session = EBM_Stripe::checkout( $booking_id, $deposit, 'deposit' );

		if ( is_wp_error( $session ) ) {
			return new WP_REST_Response(
				array( 'message' => $session->get_error_message() ),
				500
			);
		}

		return array(
			'booking_id'       => $booking_id,
			'checkout_url'     => esc_url_raw( $session['url'] ?? '' ),
			'payment_required' => true,
		);
	}
}