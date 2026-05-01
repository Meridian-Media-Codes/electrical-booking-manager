<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Scheduler {
	public static function is_business_day( DateTime $date ) {
		$business_days = EBM_Settings::get( 'business_days', array( '1', '2', '3', '4', '5' ) );

		if ( ! in_array( $date->format( 'w' ), $business_days, true ) ) {
			return false;
		}

		$holidays = array_filter(
			array_map(
				'trim',
				explode( "\n", EBM_Settings::get( 'holidays', '' ) )
			)
		);

		return ! in_array( $date->format( 'Y-m-d' ), $holidays, true );
	}

	private static function timestamp( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return 0;
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			try {
				$date = new DateTimeImmutable( $value . ' 00:00:00', wp_timezone() );
				return $date->getTimestamp();
			} catch ( Exception $e ) {
				return 0;
			}
		}

		if ( false !== strpos( $value, 'T' ) || preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $value ) ) {
			$timestamp = strtotime( $value );
			return $timestamp ? $timestamp : 0;
		}

		try {
			$date = new DateTimeImmutable( $value, wp_timezone() );
			return $date->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	private static function local_mysql_from_timestamp( $timestamp ) {
		return wp_date( 'Y-m-d H:i:s', absint( $timestamp ), wp_timezone() );
	}

	private static function ranges_overlap( $start_a, $end_a, $start_b, $end_b ) {
		$start_a = self::timestamp( $start_a );
		$end_a   = self::timestamp( $end_a );
		$start_b = self::timestamp( $start_b );
		$end_b   = self::timestamp( $end_b );

		if ( ! $start_a || ! $end_a || ! $start_b || ! $end_b ) {
			return false;
		}

		return $start_a < $end_b && $end_a > $start_b;
	}

	public static function segments( $date, $time, $minutes ) {
		$output     = array();
		$remaining  = max( 1, absint( $minutes ) );
		$work_start = EBM_Settings::get( 'work_start', '09:00' );
		$work_end   = EBM_Settings::get( 'work_end', '17:00' );

		try {
			$day = new DateTime( sanitize_text_field( $date ), wp_timezone() );
		} catch ( Exception $e ) {
			return array();
		}

		$current_time = sanitize_text_field( $time );

		while ( $remaining > 0 && count( $output ) < 80 ) {
			if ( ! self::is_business_day( $day ) ) {
				$day->modify( '+1 day' );
				$current_time = $work_start;
				continue;
			}

			try {
				$start = new DateTime( $day->format( 'Y-m-d' ) . ' ' . $current_time, wp_timezone() );
				$end   = new DateTime( $day->format( 'Y-m-d' ) . ' ' . $work_end, wp_timezone() );
			} catch ( Exception $e ) {
				return array();
			}

			if ( $start >= $end ) {
				$day->modify( '+1 day' );
				$current_time = $work_start;
				continue;
			}

			$available_minutes = (int) ( ( $end->getTimestamp() - $start->getTimestamp() ) / 60 );
			$use_minutes       = min( $available_minutes, $remaining );

			$segment_end = clone $start;
			$segment_end->modify( '+' . $use_minutes . ' minutes' );

			$output[] = array(
				'date'     => $day->format( 'Y-m-d' ),
				'start_at' => $start->format( 'Y-m-d H:i:s' ),
				'end_at'   => $segment_end->format( 'Y-m-d H:i:s' ),
			);

			$remaining -= $use_minutes;
			$day->modify( '+1 day' );
			$current_time = $work_start;
		}

		return $output;
	}

	public static function duration( $job_id, $addons ) {
		global $wpdb;

		$job = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . EBM_Helpers::table( 'jobs' ) . ' WHERE id = %d AND is_active = 1',
				$job_id
			)
		);

		if ( ! $job ) {
			return 0;
		}

		$total = (int) $job->duration_minutes;

		foreach ( $addons as $addon_id => $quantity ) {
			$addon = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . EBM_Helpers::table( 'addons' ) . ' WHERE id = %d AND job_id = %d AND is_active = 1',
					$addon_id,
					$job_id
				)
			);

			if ( $addon ) {
				$quantity = min(
					max( absint( $quantity ), (int) $addon->min_qty ),
					(int) $addon->max_qty
				);

				$total += (int) $addon->extra_duration_minutes * $quantity;
			}
		}

		return $total;
	}

	public static function price( $job_id, $addons ) {
		global $wpdb;

		$job = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . EBM_Helpers::table( 'jobs' ) . ' WHERE id = %d AND is_active = 1',
				$job_id
			)
		);

		if ( ! $job ) {
			return 0;
		}

		$total = (float) $job->price;

		foreach ( $addons as $addon_id => $quantity ) {
			$addon = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . EBM_Helpers::table( 'addons' ) . ' WHERE id = %d AND job_id = %d AND is_active = 1',
					$addon_id,
					$job_id
				)
			);

			if ( $addon ) {
				$quantity = min(
					max( absint( $quantity ), (int) $addon->min_qty ),
					(int) $addon->max_qty
				);

				$total += (float) $addon->price * $quantity;
			}
		}

		return round( $total, 2 );
	}

	public static function deposit( $job, $total ) {
		$type = ( $job->deposit_type && 'global' !== $job->deposit_type )
			? $job->deposit_type
			: EBM_Settings::get( 'global_deposit_type', 'percent' );

		$value = ( null !== $job->deposit_value && '' !== $job->deposit_value )
			? (float) $job->deposit_value
			: (float) EBM_Settings::get( 'global_deposit_value', 25 );

		$deposit = ( 'fixed' === $type )
			? $value
			: ( $total * ( $value / 100 ) );

		return round( min( max( 0, $deposit ), $total ), 2 );
	}

	private static function google_events_for_segments( $segments ) {
		if ( ! class_exists( 'EBM_Google' ) || ! EBM_Google::connected() ) {
			return array();
		}

		$min = null;
		$max = null;

		foreach ( $segments as $segment ) {
			$start = self::timestamp( $segment['start_at'] ?? '' );
			$end   = self::timestamp( $segment['end_at'] ?? '' );

			if ( ! $start || ! $end ) {
				continue;
			}

			$min = null === $min ? $start : min( $min, $start );
			$max = null === $max ? $end : max( $max, $end );
		}

		if ( null === $min || null === $max ) {
			return array();
		}

		$range_start = wp_date( 'Y-m-d 00:00:00', $min, wp_timezone() );
		$range_end   = wp_date( 'Y-m-d 23:59:59', $max, wp_timezone() );

		$events = EBM_Google::events( $range_start, $range_end );

		if ( is_wp_error( $events ) ) {
			return $events;
		}

		return is_array( $events ) ? $events : array();
	}

	private static function google_events_for_candidate_slots( $candidate_slots ) {
		$all_segments = array();

		foreach ( $candidate_slots as $slot ) {
			foreach ( (array) ( $slot['segments'] ?? array() ) as $segment ) {
				$all_segments[] = $segment;
			}
		}

		return self::google_events_for_segments( $all_segments );
	}

	private static function google_conflicts( $segments, $google_events ) {
		if ( is_wp_error( $google_events ) ) {
			return true;
		}

		if ( empty( $google_events ) ) {
			return false;
		}

		foreach ( $segments as $segment ) {
			foreach ( $google_events as $event ) {
				$event_start = $event['start'] ?? '';
				$event_end   = $event['end'] ?? '';

				if ( '' === $event_start ) {
					continue;
				}

				if ( '' === $event_end ) {
					$event_end = $event_start;
				}

				if ( self::ranges_overlap( $segment['start_at'], $segment['end_at'], $event_start, $event_end ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public static function available( $segments, $exclude = 0, $google_events = null ) {
		global $wpdb;

		if ( empty( $segments ) ) {
			return false;
		}

		$booking_days_table = EBM_Helpers::table( 'booking_days' );
		$bookings_table     = EBM_Helpers::table( 'bookings' );
		$max_bookings       = (int) EBM_Settings::get( 'max_bookings_per_slot', 1 );
		$buffer_minutes     = (int) EBM_Settings::get( 'buffer_minutes', 15 );

		foreach ( $segments as $segment ) {
			$segment_start = self::timestamp( $segment['start_at'] ?? '' );
			$segment_end   = self::timestamp( $segment['end_at'] ?? '' );

			if ( ! $segment_start || ! $segment_end ) {
				return false;
			}

			$start_with_buffer = self::local_mysql_from_timestamp( $segment_start - ( $buffer_minutes * 60 ) );
			$end_with_buffer   = self::local_mysql_from_timestamp( $segment_end + ( $buffer_minutes * 60 ) );

			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM $booking_days_table d
					INNER JOIN $bookings_table b ON b.id = d.booking_id
					WHERE b.status IN ('pending_payment', 'confirmed')
					AND b.id != %d
					AND d.start_at < %s
					AND d.end_at > %s",
					absint( $exclude ),
					$end_with_buffer,
					$start_with_buffer
				)
			);

			if ( $count >= $max_bookings ) {
				return false;
			}
		}

		if ( null === $google_events ) {
			$google_events = self::google_events_for_segments( $segments );
		}

		if ( self::google_conflicts( $segments, $google_events ) ) {
			return false;
		}

		return true;
	}

	public static function slots( $job_id, $date, $addons ) {
		$duration = self::duration( $job_id, $addons );

		if ( $duration < 1 ) {
			return array();
		}

		$candidate_slots = array();

		try {
			$cursor = new DateTime( $date . ' ' . EBM_Settings::get( 'work_start', '09:00' ), wp_timezone() );
			$limit  = new DateTime( $date . ' ' . EBM_Settings::get( 'work_end', '17:00' ), wp_timezone() );
		} catch ( Exception $e ) {
			return array();
		}

		while ( $cursor < $limit ) {
			$time     = $cursor->format( 'H:i' );
			$segments = self::segments( $date, $time, $duration );

			if ( ! empty( $segments ) ) {
				$candidate_slots[] = array(
					'time'     => $time,
					'segments' => $segments,
				);
			}

			$cursor->modify( '+30 minutes' );
		}

		$google_events = self::google_events_for_candidate_slots( $candidate_slots );

		if ( is_wp_error( $google_events ) ) {
			return array();
		}

		$available_slots = array();

		foreach ( $candidate_slots as $slot ) {
			if ( self::available( $slot['segments'], 0, $google_events ) ) {
				$available_slots[] = $slot;
			}
		}

		return $available_slots;
	}
}