<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Admin_Notices {
	public static function render() {
		$messages = array(
			'updated'      => array( 'success', __( 'Saved.', 'electrical-booking-manager' ) ),
			'hidden'       => array( 'success', __( 'Hidden.', 'electrical-booking-manager' ) ),
			'deleted'      => array( 'success', __( 'Deleted.', 'electrical-booking-manager' ) ),
			'not_deleted'  => array( 'warning', __( 'This customer has bookings, so they were not deleted.', 'electrical-booking-manager' ) ),
			'bulk_deleted' => array( 'success', __( 'Selected customers were deleted.', 'electrical-booking-manager' ) ),
			'bulk_skipped' => array( 'warning', __( 'Some customers were skipped because they have bookings.', 'electrical-booking-manager' ) ),
		);

		foreach ( $messages as $key => $message ) {
			if ( empty( $_GET[ $key ] ) ) {
				continue;
			}

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $message[0] ),
				esc_html( $message[1] )
			);
		}
	}
}
