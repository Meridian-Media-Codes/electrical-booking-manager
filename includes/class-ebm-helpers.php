<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class EBM_Helpers {
	public static function encrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) { return ''; }
		$key = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
		$iv = random_bytes( 16 );
		$cipher = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) { return ''; }
		$mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );
		return base64_encode( $iv . $mac . $cipher );
	}
	public static function decrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) { return ''; }
		$key = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
		$raw = base64_decode( $value, true );
		if ( false === $raw || strlen( $raw ) < 49 ) { return ''; }
		$iv = substr( $raw, 0, 16 ); $mac = substr( $raw, 16, 32 ); $cipher = substr( $raw, 48 );
		if ( ! hash_equals( $mac, hash_hmac( 'sha256', $iv . $cipher, $key, true ) ) ) { return ''; }
		$plain = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}
	public static function money( $amount ) { return '£' . number_format_i18n( (float) $amount, 2 ); }
	public static function token() { return wp_generate_password( 48, false, false ); }
	public static function table( $name ) { global $wpdb; return $wpdb->prefix . 'ebm_' . sanitize_key( $name ); }
	public static function clean_addons( $raw ) { $out = array(); if ( is_array( $raw ) ) { foreach ( $raw as $id => $qty ) { $out[ absint( $id ) ] = absint( $qty ); } } return $out; }
}
