<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Discounts {
	public static function table() {
		return EBM_Helpers::table( 'discounts' );
	}

	public static function maybe_create_table() {
		global $wpdb;

		$table_name      = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(80) NOT NULL,
			label VARCHAR(190) NOT NULL DEFAULT '',
			type VARCHAR(20) NOT NULL DEFAULT 'percent',
			amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			applies_to_job_id BIGINT UNSIGNED NULL DEFAULT NULL,
			usage_limit INT UNSIGNED NULL DEFAULT NULL,
			used_count INT UNSIGNED NOT NULL DEFAULT 0,
			starts_at DATETIME NULL DEFAULT NULL,
			ends_at DATETIME NULL DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY is_active (is_active),
			KEY applies_to_job_id (applies_to_job_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	public static function normalise_code( $code ) {
		$code = sanitize_text_field( wp_unslash( $code ) );
		$code = strtoupper( trim( $code ) );
		$code = preg_replace( '/[^A-Z0-9_-]/', '', $code );

		return $code;
	}

	public static function get_by_code( $code ) {
		global $wpdb;

		$code = self::normalise_code( $code );

		if ( '' === $code ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE code = %s LIMIT 1',
				$code
			)
		);
	}

	public static function validate( $code, $job_id, $total ) {
		$code  = self::normalise_code( $code );
		$total = max( 0, (float) $total );

		if ( '' === $code ) {
			return new WP_Error(
				'ebm_discount_empty',
				__( 'Enter a voucher code.', 'electrical-booking-manager' )
			);
		}

		$discount = self::get_by_code( $code );

		if ( ! $discount ) {
			return new WP_Error(
				'ebm_discount_not_found',
				__( 'This voucher code was not found.', 'electrical-booking-manager' )
			);
		}

		if ( ! (int) $discount->is_active ) {
			return new WP_Error(
				'ebm_discount_inactive',
				__( 'This voucher code is not active.', 'electrical-booking-manager' )
			);
		}

		$now = current_time( 'timestamp' );

		if ( ! empty( $discount->starts_at ) && strtotime( $discount->starts_at ) > $now ) {
			return new WP_Error(
				'ebm_discount_not_started',
				__( 'This voucher code is not active yet.', 'electrical-booking-manager' )
			);
		}

		if ( ! empty( $discount->ends_at ) && strtotime( $discount->ends_at ) < $now ) {
			return new WP_Error(
				'ebm_discount_expired',
				__( 'This voucher code has expired.', 'electrical-booking-manager' )
			);
		}

		if ( ! empty( $discount->usage_limit ) && (int) $discount->used_count >= (int) $discount->usage_limit ) {
			return new WP_Error(
				'ebm_discount_used',
				__( 'This voucher code has reached its usage limit.', 'electrical-booking-manager' )
			);
		}

		if ( ! empty( $discount->applies_to_job_id ) && (int) $discount->applies_to_job_id !== absint( $job_id ) ) {
			return new WP_Error(
				'ebm_discount_wrong_job',
				__( 'This voucher code cannot be used for this job.', 'electrical-booking-manager' )
			);
		}

		$discount_amount = self::calculate_amount( $discount, $total );

		if ( $discount_amount <= 0 ) {
			return new WP_Error(
				'ebm_discount_zero',
				__( 'This voucher code does not reduce the booking total.', 'electrical-booking-manager' )
			);
		}

		return array(
			'id'              => absint( $discount->id ),
			'code'            => $discount->code,
			'label'           => $discount->label,
			'type'            => $discount->type,
			'amount'          => (float) $discount->amount,
			'discount_amount' => $discount_amount,
		);
	}

	public static function calculate_amount( $discount, $total ) {
		$total = max( 0, (float) $total );

		if ( ! $discount || $total <= 0 ) {
			return 0;
		}

		$type   = sanitize_key( $discount->type );
		$amount = max( 0, (float) $discount->amount );

		if ( 'fixed' === $type ) {
			return round( min( $amount, $total ), 2 );
		}

		return round( min( $total * ( $amount / 100 ), $total ), 2 );
	}

	public static function increment_usage( $discount_id ) {
		global $wpdb;

		$discount_id = absint( $discount_id );

		if ( ! $discount_id ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET used_count = used_count + 1, updated_at = %s WHERE id = %d',
				current_time( 'mysql' ),
				$discount_id
			)
		);
	}
}