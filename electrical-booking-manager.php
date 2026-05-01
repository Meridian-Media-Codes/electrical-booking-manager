<?php
/**
 * Plugin Name: Electrical Booking Manager
 * Description: Secure electrical services booking system with add-ons, Google Calendar blocking, Stripe deposits, reminders, discounts, and multi-day scheduling.
 * Version: 1.1.1
 * Author: Meridian Media
 * Text Domain: electrical-booking-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EBM_VERSION', '1.1.1' );
define( 'EBM_FILE', __FILE__ );
define( 'EBM_DIR', plugin_dir_path( __FILE__ ) );
define( 'EBM_URL', plugin_dir_url( __FILE__ ) );

$ebm_core_files = array(
	'helpers',
	'db',
	'settings',
	'scheduler',
	'google',
	'stripe',
	'emails',
	'discounts',
	'rest',
	'shortcodes',
	'admin',
);

foreach ( $ebm_core_files as $file ) {
	$file_path = EBM_DIR . 'includes/class-ebm-' . $file . '.php';

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
}

$ebm_admin_files = array(
	'notices',
	'settings',
	'bookings',
	'calendar',
	'customers',
	'jobs',
	'discounts',
);

foreach ( $ebm_admin_files as $file ) {
	$file_path = EBM_DIR . 'includes/admin/class-ebm-admin-' . $file . '.php';

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
}

register_activation_hook( __FILE__, array( 'EBM_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EBM_DB', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function() {
		load_plugin_textdomain(
			'electrical-booking-manager',
			false,
			dirname( plugin_basename( EBM_FILE ) ) . '/languages'
		);
	}
);

add_action( 'init', array( 'EBM_Admin', 'init' ) );
add_action( 'init', array( 'EBM_Shortcodes', 'init' ) );
add_action( 'init', array( 'EBM_Google', 'init' ) );

add_action( 'rest_api_init', array( 'EBM_REST', 'routes' ) );
add_action( 'rest_api_init', array( 'EBM_Stripe', 'routes' ) );

add_action( 'ebm_send_reminders', array( 'EBM_Emails', 'send_due_reminders' ) );