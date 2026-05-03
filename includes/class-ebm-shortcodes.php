<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Shortcodes {
	public static function init() {
		add_shortcode( 'ebm_booking_form', array( __CLASS__, 'booking_form' ) );
		add_shortcode( 'ebm_my_bookings', array( __CLASS__, 'my_bookings' ) );
	}

	private static function frontend_asset_version( $relative_path ) {
		$path = EBM_DIR . ltrim( $relative_path, '/' );

		if ( file_exists( $path ) ) {
			return (string) filemtime( $path );
		}

		return defined( 'EBM_VERSION' ) ? EBM_VERSION : '1.0.0';
	}

	private static function preload_jobs() {
		global $wpdb;

		$cache_key = 'ebm_frontend_jobs_' . md5( get_locale() );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$jobs_table = EBM_Helpers::table( 'jobs' );

		$rows = $wpdb->get_results(
			"SELECT id, title, description, duration_minutes
			FROM $jobs_table
			WHERE is_active = 1
			ORDER BY title ASC"
		);

		$jobs = array();

		foreach ( (array) $rows as $row ) {
			$jobs[] = array(
				'id'               => absint( $row->id ),
				'title'            => sanitize_text_field( $row->title ),
				'description'      => sanitize_textarea_field( $row->description ),
				'duration_minutes' => absint( $row->duration_minutes ),
			);
		}

		set_transient( $cache_key, $jobs, 10 * MINUTE_IN_SECONDS );

		return $jobs;
	}

	private static function enqueue_frontend_assets() {
		$settings         = EBM_Settings::all();
		$places_api_key   = sanitize_text_field( $settings['google_places_api_key'] ?? '' );
		$allowed_prefixes = class_exists( 'EBM_Settings' ) && method_exists( 'EBM_Settings', 'allowed_postcode_prefixes' )
			? EBM_Settings::allowed_postcode_prefixes()
			: array( 'FY' );

		wp_enqueue_style(
			'ebm-frontend',
			EBM_URL . 'assets/css/frontend.css',
			array(),
			self::frontend_asset_version( 'assets/css/frontend.css' )
		);

		wp_enqueue_script(
			'ebm-frontend',
			EBM_URL . 'assets/js/frontend.js',
			array(),
			self::frontend_asset_version( 'assets/js/frontend.js' ),
			true
		);

		wp_localize_script(
			'ebm-frontend',
			'ebmBooking',
			array(
				'restUrl'                 => esc_url_raw( rest_url( 'ebm/v1/' ) ),
				'nonce'                   => wp_create_nonce( 'wp_rest' ),
				'preloadedJobs'           => self::preload_jobs(),
				'cacheVersion'            => self::frontend_asset_version( 'assets/js/frontend.js' ),
				'googlePlacesApiKey'      => $places_api_key,
				'allowedPostcodePrefixes' => $allowed_prefixes,
			)
		);

		if ( '' !== $places_api_key ) {
			wp_enqueue_script(
				'ebm-google-places',
				add_query_arg(
					array(
						'key'       => $places_api_key,
						'libraries' => 'places',
						'callback'  => 'ebmGooglePlacesLoaded',
						'loading'   => 'async',
						'v'         => 'weekly',
					),
					'https://maps.googleapis.com/maps/api/js'
				),
				array( 'ebm-frontend' ),
				null,
				true
			);
		}
	}

	public static function booking_form() {
		self::enqueue_frontend_assets();

		ob_start();
		?>
		<div id="ebm-booking-app" class="ebm-booking-form" data-ebm-booking-app>
			<div class="ebm-booking-shell">
				<div class="ebm-loading">
					<?php esc_html_e( 'Loading booking form...', 'electrical-booking-manager' ); ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function my_bookings() {
		self::enqueue_frontend_assets();

		ob_start();
		?>
		<div class="ebm-my-bookings">
			<div class="ebm-booking-shell">
				<h2><?php esc_html_e( 'My bookings', 'electrical-booking-manager' ); ?></h2>
				<p><?php esc_html_e( 'Customer booking lookup will be added in the next build.', 'electrical-booking-manager' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}