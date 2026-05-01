<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Stripe {
	public static function routes() {
		register_rest_route(
			'ebm/v1',
			'/stripe-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	private static function request( $endpoint, $body ) {
		$key = EBM_Settings::secret( 'stripe_secret_key' );

		if ( ! $key ) {
			return new WP_Error(
				'ebm_no_stripe',
				__( 'Stripe secret key is missing.', 'electrical-booking-manager' )
			);
		}

		$response = wp_remote_post(
			'https://api.stripe.com/v1/' . ltrim( $endpoint, '/' ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new WP_Error(
				'ebm_stripe_error',
				$data['error']['message'] ?? __( 'Stripe request failed.', 'electrical-booking-manager' )
			);
		}

		return $data;
	}

	public static function checkout( $booking_id, $amount, $type = 'deposit' ) {
		global $wpdb;

		$amount = (float) $amount;

		if ( $amount <= 0 ) {
			return new WP_Error(
				'ebm_no_payment_due',
				__( 'No payment is due for this booking.', 'electrical-booking-manager' )
			);
		}

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT b.*, c.email
				FROM ' . EBM_Helpers::table( 'bookings' ) . ' b
				INNER JOIN ' . EBM_Helpers::table( 'customers' ) . ' c ON c.id = b.customer_id
				WHERE b.id = %d',
				$booking_id
			)
		);

		if ( ! $booking ) {
			return new WP_Error(
				'ebm_missing_booking',
				__( 'Booking not found.', 'electrical-booking-manager' )
			);
		}

		$success_url = add_query_arg(
			array(
				'ebm_booking' => $booking->public_token,
				'payment'     => 'success',
			),
			home_url( '/' )
		);

		$cancel_url = add_query_arg(
			array(
				'ebm_booking' => $booking->public_token,
				'payment'     => 'cancelled',
			),
			home_url( '/' )
		);

		$data = self::request(
			'checkout/sessions',
			array(
				'mode'                                      => 'payment',
				'success_url'                               => $success_url,
				'cancel_url'                                => $cancel_url,
				'customer_email'                            => $booking->email,
				'client_reference_id'                       => $booking_id,
				'metadata[booking_id]'                      => $booking_id,
				'metadata[payment_type]'                    => $type,
				'payment_intent_data[metadata][booking_id]' => $booking_id,
				'payment_intent_data[metadata][payment_type]' => $type,
				'line_items[0][price_data][currency]'       => 'gbp',
				'line_items[0][price_data][product_data][name]' => 'Booking ' . ucfirst( sanitize_key( $type ) ),
				'line_items[0][price_data][unit_amount]'    => max( 50, (int) round( $amount * 100 ) ),
				'line_items[0][quantity]'                   => 1,
			)
		);

		if ( ! is_wp_error( $data ) ) {
			$wpdb->insert(
				EBM_Helpers::table( 'transactions' ),
				array(
					'booking_id'  => $booking_id,
					'type'        => sanitize_key( $type ),
					'status'      => 'checkout_created',
					'amount'      => $amount,
					'provider'    => 'stripe',
					'provider_id' => sanitize_text_field( $data['id'] ?? '' ),
					'created_at'  => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%f', '%s', '%s', '%s' )
			);
		}

		return $data;
	}

	private static function verify_webhook_signature( $payload, $signature_header ) {
		$secret = EBM_Settings::secret( 'stripe_webhook_secret' );

		if ( ! $secret ) {
			return true;
		}

		if ( ! $signature_header ) {
			return false;
		}

		$timestamp = '';
		$signature = '';

		foreach ( explode( ',', $signature_header ) as $part ) {
			$pieces = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pieces ) ) {
				continue;
			}

			if ( 't' === $pieces[0] ) {
				$timestamp = $pieces[1];
			}

			if ( 'v1' === $pieces[0] ) {
				$signature = $pieces[1];
			}
		}

		if ( ! $timestamp || ! $signature ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > 300 ) {
			return false;
		}

		$signed_payload = $timestamp . '.' . $payload;
		$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

		return hash_equals( $expected, $signature );
	}

	public static function webhook( WP_REST_Request $request ) {
		$payload   = $request->get_body();
		$signature = $request->get_header( 'stripe-signature' );

		if ( ! self::verify_webhook_signature( $payload, $signature ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => __( 'Invalid Stripe webhook signature.', 'electrical-booking-manager' ),
				),
				400
			);
		}

		$event = json_decode( $payload, true );

		if ( empty( $event['type'] ) ) {
			return new WP_REST_Response(
				array(
					'ok' => false,
				),
				400
			);
		}

		if ( 'checkout.session.completed' === $event['type'] ) {
			self::handle_checkout_completed( $event['data']['object'] ?? array() );
		}

		return array(
			'ok' => true,
		);
	}

	private static function handle_checkout_completed( $session ) {
		global $wpdb;

		$booking_id = absint( $session['client_reference_id'] ?? 0 );

		if ( ! $booking_id && ! empty( $session['metadata']['booking_id'] ) ) {
			$booking_id = absint( $session['metadata']['booking_id'] );
		}

		if ( ! $booking_id ) {
			return;
		}

		$booking = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . EBM_Helpers::table( 'bookings' ) . ' WHERE id = %d',
				$booking_id
			)
		);

		if ( ! $booking ) {
			return;
		}

		$payment_type = sanitize_key( $session['metadata']['payment_type'] ?? 'deposit' );

		$wpdb->insert(
			EBM_Helpers::table( 'transactions' ),
			array(
				'booking_id'  => $booking_id,
				'type'        => $payment_type,
				'status'      => 'paid',
				'amount'      => 'balance' === $payment_type ? (float) $booking->balance_amount : (float) $booking->deposit_amount,
				'provider'    => 'stripe',
				'provider_id' => sanitize_text_field( $session['id'] ?? '' ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%f', '%s', '%s', '%s' )
		);

		if ( 'deposit' === $payment_type ) {
			$wpdb->update(
				EBM_Helpers::table( 'bookings' ),
				array(
					'status'            => 'confirmed',
					'stripe_session_id' => sanitize_text_field( $session['id'] ?? '' ),
					'updated_at'        => current_time( 'mysql' ),
				),
				array( 'id' => $booking_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( class_exists( 'EBM_Google' ) && EBM_Google::connected() && empty( $booking->google_event_id ) ) {
				EBM_Google::create_event( $booking_id );
			}

			if ( class_exists( 'EBM_Emails' ) ) {
				EBM_Emails::confirmation( $booking_id );
			}
		}
	}
}