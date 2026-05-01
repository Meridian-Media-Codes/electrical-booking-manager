<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Admin {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		if ( class_exists( 'EBM_Admin_Settings' ) ) {
			EBM_Admin_Settings::init();
		}

		if ( class_exists( 'EBM_Admin_Bookings' ) ) {
			EBM_Admin_Bookings::init();
		}

		if ( class_exists( 'EBM_Admin_Calendar' ) ) {
			EBM_Admin_Calendar::init();
		}

		if ( class_exists( 'EBM_Admin_Customers' ) ) {
			EBM_Admin_Customers::init();
		}

		if ( class_exists( 'EBM_Admin_Jobs' ) ) {
			EBM_Admin_Jobs::init();
		}

		if ( class_exists( 'EBM_Admin_Discounts' ) ) {
			EBM_Admin_Discounts::init();
		}
	}

	public static function menu() {
		add_menu_page(
			__( 'Electrical Bookings', 'electrical-booking-manager' ),
			__( 'Electrical Bookings', 'electrical-booking-manager' ),
			'manage_options',
			'ebm-bookings',
			array( 'EBM_Admin_Bookings', 'render' ),
			'dashicons-calendar-alt',
			26
		);

		add_submenu_page(
			'ebm-bookings',
			__( 'Bookings', 'electrical-booking-manager' ),
			__( 'Bookings', 'electrical-booking-manager' ),
			'manage_options',
			'ebm-bookings',
			array( 'EBM_Admin_Bookings', 'render' )
		);

		add_submenu_page(
			'ebm-bookings',
			__( 'Calendar', 'electrical-booking-manager' ),
			__( 'Calendar', 'electrical-booking-manager' ),
			'manage_options',
			'ebm-calendar',
			array( 'EBM_Admin_Calendar', 'render' )
		);

		add_submenu_page(
			'ebm-bookings',
			__( 'Customers', 'electrical-booking-manager' ),
			__( 'Customers', 'electrical-booking-manager' ),
			'manage_options',
			'ebm-customers',
			array( 'EBM_Admin_Customers', 'render' )
		);

		add_submenu_page(
			'ebm-bookings',
			__( 'Jobs & Add-ons', 'electrical-booking-manager' ),
			__( 'Jobs & Add-ons', 'electrical-booking-manager' ),
			'manage_options',
			'ebm-jobs',
			array( 'EBM_Admin_Jobs', 'render' )
		);

		add_submenu_page(
			'ebm-bookings',
			__( 'Discounts', 'electrical-booking-manager' ),
			__( 'Discounts', 'electrical-booking-manager' ),
			'manage_options',
			'ebm-discounts',
			array( 'EBM_Admin_Discounts', 'render' )
		);

		add_submenu_page(
			'ebm-bookings',
			__( 'Settings', 'electrical-booking-manager' ),
			__( 'Settings', 'electrical-booking-manager' ),
			'manage_options',
			'ebm-settings',
			array( 'EBM_Admin_Settings', 'render' )
		);
	}

	public static function assets( $hook ) {
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );

		if ( 0 !== strpos( $page, 'ebm-' ) ) {
			return;
		}

		wp_enqueue_style(
			'ebm-admin',
			EBM_URL . 'assets/css/admin.css',
			array(),
			self::asset_version( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'ebm-admin',
			EBM_URL . 'assets/js/admin.js',
			array(),
			self::asset_version( 'assets/js/admin.js' ),
			true
		);
	}

	public static function enqueue_admin_assets() {
		wp_enqueue_style(
			'ebm-admin',
			EBM_URL . 'assets/css/admin.css',
			array(),
			self::asset_version( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'ebm-admin',
			EBM_URL . 'assets/js/admin.js',
			array(),
			self::asset_version( 'assets/js/admin.js' ),
			true
		);
	}

	private static function asset_version( $relative_path ) {
		$path = EBM_DIR . ltrim( $relative_path, '/' );

		if ( file_exists( $path ) ) {
			return (string) filemtime( $path );
		}

		return defined( 'EBM_VERSION' ) ? EBM_VERSION : '1.0.0';
	}

	public static function cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'electrical-booking-manager' ) );
		}
	}

	public static function duration_to_minutes( $value, $unit ) {
		$value = max( 0, (float) $value );
		$unit  = sanitize_key( $unit );

		if ( 'days' === $unit ) {
			return (int) round( $value * 1440 );
		}

		if ( 'hours' === $unit ) {
			return (int) round( $value * 60 );
		}

		return (int) round( $value );
	}

	public static function split_minutes_to_best_unit( $minutes ) {
		$minutes = max( 0, absint( $minutes ) );

		if ( $minutes >= 1440 && 0 === $minutes % 1440 ) {
			return array(
				'value' => $minutes / 1440,
				'unit'  => 'days',
			);
		}

		if ( $minutes >= 60 && 0 === $minutes % 60 ) {
			return array(
				'value' => $minutes / 60,
				'unit'  => 'hours',
			);
		}

		return array(
			'value' => $minutes,
			'unit'  => 'minutes',
		);
	}

	public static function duration_unit_select( $name, $selected ) {
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<option value="minutes" <?php selected( $selected, 'minutes' ); ?>>
				<?php esc_html_e( 'Minutes', 'electrical-booking-manager' ); ?>
			</option>
			<option value="hours" <?php selected( $selected, 'hours' ); ?>>
				<?php esc_html_e( 'Hours', 'electrical-booking-manager' ); ?>
			</option>
			<option value="days" <?php selected( $selected, 'days' ); ?>>
				<?php esc_html_e( 'Days', 'electrical-booking-manager' ); ?>
			</option>
		</select>
		<?php
	}

	public static function format_duration( $minutes ) {
		$minutes = absint( $minutes );

		if ( $minutes >= 1440 && 0 === $minutes % 1440 ) {
			$days = $minutes / 1440;

			return sprintf(
				_n( '%d day', '%d days', $days, 'electrical-booking-manager' ),
				$days
			);
		}

		if ( $minutes >= 60 && 0 === $minutes % 60 ) {
			$hours = $minutes / 60;

			return sprintf(
				_n( '%d hour', '%d hours', $hours, 'electrical-booking-manager' ),
				$hours
			);
		}

		return sprintf(
			_n( '%d minute', '%d minutes', $minutes, 'electrical-booking-manager' ),
			$minutes
		);
	}
}