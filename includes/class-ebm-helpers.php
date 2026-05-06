<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Helpers {
	public static function encrypt( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		$key = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
		$iv  = random_bytes( 16 );

		$cipher = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return '';
		}

		$mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );

		return base64_encode( $iv . $mac . $cipher );
	}

	public static function decrypt( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		$key = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
		$raw = base64_decode( $value, true );

		if ( false === $raw || strlen( $raw ) < 49 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 16 );
		$mac    = substr( $raw, 16, 32 );
		$cipher = substr( $raw, 48 );

		if ( ! hash_equals( $mac, hash_hmac( 'sha256', $iv . $cipher, $key, true ) ) ) {
			return '';
		}

		$plain = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

		return false === $plain ? '' : $plain;
	}

	public static function money( $amount ) {
		return '£' . number_format_i18n( (float) $amount, 2 );
	}

	public static function token() {
		return wp_generate_password( 48, false, false );
	}

	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'ebm_' . sanitize_key( $name );
	}

	public static function clean_addons( $raw ) {
		$out = array();

		if ( is_array( $raw ) ) {
			foreach ( $raw as $id => $qty ) {
				$id  = absint( $id );
				$qty = absint( $qty );

				if ( $id && $qty > 0 ) {
					$out[ $id ] = $qty;
				}
			}
		}

		return $out;
	}

	public static function booking_extras( $booking ) {
		global $wpdb;

		$addons_json = '';

		if ( is_object( $booking ) && isset( $booking->addons_json ) ) {
			$addons_json = $booking->addons_json;
		} elseif ( is_array( $booking ) && isset( $booking['addons_json'] ) ) {
			$addons_json = $booking['addons_json'];
		}

		$addons = json_decode( (string) $addons_json, true );

		if ( ! is_array( $addons ) || empty( $addons ) ) {
			return array();
		}

		$ids = array_filter( array_map( 'absint', array_keys( $addons ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, title, price, extra_duration_minutes
				FROM ' . self::table( 'addons' ) . "
				WHERE id IN ($placeholders)",
				$ids
			)
		);

		$rows_by_id = array();

		foreach ( (array) $rows as $row ) {
			$rows_by_id[ (int) $row->id ] = $row;
		}

		$extras = array();

		foreach ( $addons as $addon_id => $qty ) {
			$addon_id = absint( $addon_id );
			$qty      = absint( $qty );

			if ( ! $addon_id || ! $qty || empty( $rows_by_id[ $addon_id ] ) ) {
				continue;
			}

			$row = $rows_by_id[ $addon_id ];

			$extras[] = array(
				'id'       => $addon_id,
				'title'    => sanitize_text_field( $row->title ),
				'qty'      => $qty,
				'price'    => (float) $row->price,
				'subtotal' => (float) $row->price * $qty,
				'duration' => (int) $row->extra_duration_minutes,
			);
		}

		return $extras;
	}

	public static function booking_extras_text( $booking ) {
		$extras = self::booking_extras( $booking );

		if ( empty( $extras ) ) {
			return __( 'No extras selected', 'electrical-booking-manager' );
		}

		$lines = array();

		foreach ( $extras as $extra ) {
			$line = sprintf(
				'%s × %d',
				$extra['title'],
				$extra['qty']
			);

			if ( $extra['subtotal'] > 0 ) {
				$line .= ' - ' . self::money( $extra['subtotal'] );
			}

			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	public static function booking_extras_html( $booking ) {
		$extras = self::booking_extras( $booking );

		if ( empty( $extras ) ) {
			return esc_html__( 'No extras selected', 'electrical-booking-manager' );
		}

		$html = '<ul style="margin:0; padding-left:18px;">';

		foreach ( $extras as $extra ) {
			$text = sprintf(
				'%s × %d',
				$extra['title'],
				$extra['qty']
			);

			if ( $extra['subtotal'] > 0 ) {
				$text .= ' - ' . self::money( $extra['subtotal'] );
			}

			$html .= '<li>' . esc_html( $text ) . '</li>';
		}

		$html .= '</ul>';

		return $html;
	}
}