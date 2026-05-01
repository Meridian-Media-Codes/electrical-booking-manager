<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Settings {
	const OPTION = 'ebm_settings';

	public static function defaults() {
		return array(
			'admin_email'              => get_option( 'admin_email' ),
			'business_name'            => get_bloginfo( 'name' ),
			'business_days'            => array( '1', '2', '3', '4', '5' ),
			'work_start'               => '09:00',
			'work_end'                 => '17:00',
			'buffer_minutes'           => 15,
			'max_bookings_per_slot'    => 1,
			'holidays'                 => '',
			'global_deposit_type'      => 'percent',
			'global_deposit_value'     => 25,

			'stripe_publishable_key'   => '',
			'stripe_secret_key'        => '',
			'stripe_webhook_secret'    => '',

			'google_client_id'         => '',
			'google_client_secret'     => '',
			'google_calendar_id'       => 'primary',
			'google_refresh_token'     => '',

			'google_places_api_key'    => '',
			'allowed_postcode_prefixes'=> 'FY',

			'email_from_name'          => get_bloginfo( 'name' ),
			'email_from_address'       => get_option( 'admin_email' ),
			'privacy_page_url'         => get_privacy_policy_url(),
		);
	}

	public static function all() {
		$settings = get_option( self::OPTION, array() );

		return wp_parse_args(
			is_array( $settings ) ? $settings : array(),
			self::defaults()
		);
	}

	public static function get( $key, $default = null ) {
		$settings = self::all();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	public static function secret( $key ) {
		return EBM_Helpers::decrypt( self::get( $key, '' ) );
	}

	public static function allowed_postcode_prefixes() {
		$raw = (string) self::get( 'allowed_postcode_prefixes', 'FY' );

		$prefixes = array_filter(
			array_map(
				function( $prefix ) {
					$prefix = strtoupper( trim( $prefix ) );
					$prefix = preg_replace( '/[^A-Z0-9]/', '', $prefix );

					return $prefix;
				},
				explode( ',', $raw )
			)
		);

		return array_values( array_unique( $prefixes ) ) ?: array( 'FY' );
	}

	public static function save( $raw ) {
		$current = self::all();

		$current['admin_email']           = sanitize_email( $raw['admin_email'] ?? $current['admin_email'] );
		$current['business_name']         = sanitize_text_field( $raw['business_name'] ?? $current['business_name'] );
		$current['work_start']            = sanitize_text_field( $raw['work_start'] ?? '09:00' );
		$current['work_end']              = sanitize_text_field( $raw['work_end'] ?? '17:00' );
		$current['buffer_minutes']        = absint( $raw['buffer_minutes'] ?? 15 );
		$current['max_bookings_per_slot'] = max( 1, absint( $raw['max_bookings_per_slot'] ?? 1 ) );
		$current['holidays']              = sanitize_textarea_field( $raw['holidays'] ?? '' );

		$current['global_deposit_type'] = in_array( ( $raw['global_deposit_type'] ?? 'percent' ), array( 'percent', 'fixed' ), true )
			? sanitize_key( $raw['global_deposit_type'] )
			: 'percent';

		$current['global_deposit_value'] = (float) ( $raw['global_deposit_value'] ?? 25 );

		$current['stripe_publishable_key'] = sanitize_text_field( $raw['stripe_publishable_key'] ?? '' );

		$current['google_client_id']      = sanitize_text_field( $raw['google_client_id'] ?? '' );
		$current['google_calendar_id']    = sanitize_text_field( $raw['google_calendar_id'] ?? 'primary' );
		$current['google_places_api_key'] = sanitize_text_field( $raw['google_places_api_key'] ?? '' );

		$allowed_postcode_prefixes = strtoupper( sanitize_text_field( $raw['allowed_postcode_prefixes'] ?? 'FY' ) );
		$allowed_postcode_prefixes = preg_replace( '/[^A-Z0-9,\s]/', '', $allowed_postcode_prefixes );
		$allowed_postcode_prefixes = implode(
			',',
			array_filter(
				array_map(
					'trim',
					explode( ',', $allowed_postcode_prefixes )
				)
			)
		);

		$current['allowed_postcode_prefixes'] = $allowed_postcode_prefixes ?: 'FY';

		$current['email_from_name']    = sanitize_text_field( $raw['email_from_name'] ?? $current['email_from_name'] );
		$current['email_from_address'] = sanitize_email( $raw['email_from_address'] ?? $current['email_from_address'] );
		$current['privacy_page_url']   = esc_url_raw( $raw['privacy_page_url'] ?? '' );

		$days = is_array( $raw['business_days'] ?? null )
			? array_map( 'sanitize_key', $raw['business_days'] )
			: array();

		$current['business_days'] = array_values(
			array_intersect(
				$days,
				array( '0', '1', '2', '3', '4', '5', '6' )
			)
		) ?: array( '1', '2', '3', '4', '5' );

		foreach ( array( 'stripe_secret_key', 'stripe_webhook_secret', 'google_client_secret' ) as $key ) {
			if ( ! empty( $raw[ $key ] ) ) {
				$current[ $key ] = EBM_Helpers::encrypt(
					sanitize_text_field( $raw[ $key ] )
				);
			}
		}

		update_option( self::OPTION, $current, false );
	}
}