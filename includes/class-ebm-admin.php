<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Admin {
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );

		add_action( 'admin_post_ebm_save_settings', array( __CLASS__, 'save_settings' ) );

		add_action( 'admin_post_ebm_save_job', array( __CLASS__, 'save_job' ) );
		add_action( 'admin_post_ebm_delete_job', array( __CLASS__, 'delete_job' ) );
		add_action( 'admin_post_ebm_hard_delete_job', array( __CLASS__, 'hard_delete_job' ) );

		add_action( 'admin_post_ebm_save_addon', array( __CLASS__, 'save_addon' ) );
		add_action( 'admin_post_ebm_delete_addon', array( __CLASS__, 'delete_addon' ) );
		add_action( 'admin_post_ebm_hard_delete_addon', array( __CLASS__, 'hard_delete_addon' ) );

		add_action( 'admin_post_ebm_save_customer', array( __CLASS__, 'save_customer' ) );
		add_action( 'admin_post_ebm_delete_customer', array( __CLASS__, 'delete_customer' ) );
		add_action( 'admin_post_ebm_bulk_customers', array( __CLASS__, 'bulk_customers' ) );

		add_action( 'admin_post_ebm_update_booking', array( __CLASS__, 'update_booking' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'Electrical Bookings', 'electrical-booking-manager' ),
			__( 'Electrical Bookings', 'electrical-booking-manager' ),
			'manage_options',
			'ebm-bookings',
			array( __CLASS__, 'bookings' ),
			'dashicons-calendar-alt',
			26
		);

		add_submenu_page(
			'ebm-bookings',
			__( 'Bookings', 'electrical-booking-manager' ),
			__( 'Bookings', 'electrical-booking-manager' ),
			'manage_options',
			'ebm-bookings',
			array( __CLASS__, 'bookings' )
		);

		add_submenu_page(
			'ebm-bookings',
			__( 'Customers', 'electrical-booking-manager' ),
			__( 'Customers', 'electrical-booking-manager' ),
			'manage_options',
			'ebm-customers',
			array( __CLASS__, 'customers' )
		);

		add_submenu_page(
			'ebm-bookings',
			__( 'Jobs & Add-ons', 'electrical-booking-manager' ),
			__( 'Jobs & Add-ons', 'electrical-booking-manager' ),
			'manage_options',
			'ebm-jobs',
			array( __CLASS__, 'jobs' )
		);

		add_submenu_page(
			'ebm-bookings',
			__( 'Settings', 'electrical-booking-manager' ),
			__( 'Settings', 'electrical-booking-manager' ),
			'manage_options',
			'ebm-settings',
			array( __CLASS__, 'settings' )
		);
	}

	private static function cap() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'electrical-booking-manager' ) );
		}
	}

	private static function admin_notice() {
		if ( ! empty( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'electrical-booking-manager' ) . '</p></div>';
		}

		if ( ! empty( $_GET['hidden'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Hidden.', 'electrical-booking-manager' ) . '</p></div>';
		}

		if ( ! empty( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Deleted.', 'electrical-booking-manager' ) . '</p></div>';
		}

		if ( ! empty( $_GET['not_deleted'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'This customer has bookings, so they were not deleted.', 'electrical-booking-manager' ) . '</p></div>';
		}

		if ( ! empty( $_GET['bulk_deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Selected customers were deleted.', 'electrical-booking-manager' ) . '</p></div>';
		}

		if ( ! empty( $_GET['bulk_skipped'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Some customers were skipped because they have bookings.', 'electrical-booking-manager' ) . '</p></div>';
		}
	}

	private static function duration_to_minutes( $value, $unit ) {
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

	private static function split_minutes_to_best_unit( $minutes ) {
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

	private static function duration_unit_select( $name, $selected ) {
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<option value="minutes" <?php selected( $selected, 'minutes' ); ?>><?php esc_html_e( 'Minutes', 'electrical-booking-manager' ); ?></option>
			<option value="hours" <?php selected( $selected, 'hours' ); ?>><?php esc_html_e( 'Hours', 'electrical-booking-manager' ); ?></option>
			<option value="days" <?php selected( $selected, 'days' ); ?>><?php esc_html_e( 'Days', 'electrical-booking-manager' ); ?></option>
		</select>
		<?php
	}

	private static function format_duration( $minutes ) {
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

	private static function jobs_admin_css() {
		?>
		<style>
			.ebm-admin-shell {
				margin-top: 18px;
			}

			.ebm-admin-layout {
				display: grid;
				grid-template-columns: 340px minmax(0, 1fr);
				gap: 18px;
				align-items: start;
			}

			.ebm-panel {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 10px;
				box-shadow: 0 1px 2px rgba(0,0,0,.04);
				overflow: hidden;
			}

			.ebm-panel-header {
				padding: 16px 18px;
				border-bottom: 1px solid #dcdcde;
				background: #f6f7f7;
			}

			.ebm-panel-header h2,
			.ebm-panel-header h3 {
				margin: 0;
				font-size: 16px;
			}

			.ebm-panel-body {
				padding: 18px;
			}

			.ebm-job-list {
				display: flex;
				flex-direction: column;
				gap: 10px;
			}

			.ebm-job-card {
				display: block;
				text-decoration: none;
				color: #1d2327;
				border: 1px solid #dcdcde;
				border-radius: 10px;
				padding: 13px 14px;
				background: #fff;
			}

			.ebm-job-card:hover,
			.ebm-job-card:focus {
				border-color: #2271b1;
				box-shadow: 0 0 0 1px #2271b1;
				outline: none;
			}

			.ebm-job-card.is-selected {
				border-color: #2271b1;
				box-shadow: 0 0 0 1px #2271b1;
				background: #f0f6fc;
			}

			.ebm-job-card-title {
				display: flex;
				justify-content: space-between;
				gap: 10px;
				font-weight: 700;
			}

			.ebm-job-card-meta {
				margin-top: 8px;
				color: #646970;
				font-size: 12px;
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
			}

			.ebm-badge {
				display: inline-flex;
				align-items: center;
				min-height: 20px;
				padding: 0 8px;
				border-radius: 999px;
				background: #f0f0f1;
				color: #50575e;
				font-size: 12px;
				font-weight: 600;
			}

			.ebm-badge.green {
				background: #edfaef;
				color: #008a20;
			}

			.ebm-badge.grey {
				background: #f0f0f1;
				color: #646970;
			}

			.ebm-form-grid {
				display: grid;
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 16px;
			}

			.ebm-field.ebm-full {
				grid-column: 1 / -1;
			}

			.ebm-field label {
				display: block;
				font-weight: 600;
				margin-bottom: 6px;
			}

			.ebm-field input[type="text"],
			.ebm-field input[type="email"],
			.ebm-field input[type="tel"],
			.ebm-field input[type="number"],
			.ebm-field select,
			.ebm-field textarea {
				width: 100%;
				max-width: 100%;
			}

			.ebm-inline-duration {
				display: grid;
				grid-template-columns: minmax(0, 1fr) 150px;
				gap: 8px;
			}

			.ebm-actions {
				display: flex;
				gap: 8px;
				align-items: center;
				flex-wrap: wrap;
				margin-top: 18px;
			}

			.ebm-extra-list {
				display: flex;
				flex-direction: column;
				gap: 12px;
			}

			.ebm-extra-card {
				border: 1px solid #dcdcde;
				border-radius: 10px;
				padding: 14px;
				background: #fff;
			}

			.ebm-extra-top {
				display: flex;
				justify-content: space-between;
				gap: 14px;
				margin-bottom: 12px;
			}

			.ebm-extra-top strong {
				font-size: 14px;
			}

			.ebm-extra-meta {
				color: #646970;
				font-size: 12px;
				margin-top: 4px;
			}

			.ebm-extra-edit {
				display: grid;
				grid-template-columns: repeat(4, minmax(0, 1fr));
				gap: 12px;
			}

			.ebm-extra-edit .wide {
				grid-column: 1 / -1;
			}

			.ebm-muted-box {
				border: 1px dashed #c3c4c7;
				border-radius: 10px;
				padding: 18px;
				color: #646970;
				background: #fcfcfd;
			}

			.ebm-danger-link {
				color: #b32d2e;
			}

			.ebm-danger-link:hover {
				color: #8a2424;
			}

			.ebm-customers-card {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 12px;
				box-shadow: 0 1px 2px rgba(0,0,0,.04);
				overflow: hidden;
				margin-top: 18px;
			}

			.ebm-customers-header {
				padding: 24px;
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 16px;
			}

			.ebm-customers-header h1 {
				margin: 0;
				font-size: 22px;
			}

			.ebm-customers-filter {
				display: grid;
				grid-template-columns: minmax(0, 1fr) auto auto;
				gap: 12px;
				padding: 0 24px 24px;
				border-bottom: 1px solid #dcdcde;
			}

			.ebm-customers-filter input,
			.ebm-customers-filter select {
				width: 100%;
				min-height: 38px;
			}

			.ebm-bulk-bar {
				padding: 16px 24px;
				display: flex;
				gap: 10px;
				align-items: center;
				border-bottom: 1px solid #dcdcde;
				background: #fff;
			}

			.ebm-customers-table {
				width: 100%;
				border-collapse: collapse;
			}

			.ebm-customers-table th,
			.ebm-customers-table td {
				padding: 15px 24px;
				border-bottom: 1px solid #f0f0f1;
				text-align: left;
				vertical-align: middle;
			}

			.ebm-customers-table th {
				font-weight: 700;
				color: #1d2327;
				background: #fff;
			}

			.ebm-customers-table tr:nth-child(even) td {
				background: #f6f7f7;
			}

			.ebm-customer-name {
				display: flex;
				align-items: center;
				gap: 12px;
				font-weight: 600;
			}

			.ebm-avatar {
				width: 34px;
				height: 34px;
				border-radius: 50%;
				background: #edf1fb;
				color: #6b7897;
				display: inline-flex;
				align-items: center;
				justify-content: center;
				font-weight: 700;
				flex: 0 0 34px;
			}

			.ebm-row-actions {
				margin-top: 4px;
				font-size: 12px;
				font-weight: 400;
			}

			.ebm-row-actions a {
				text-decoration: none;
			}

			.ebm-pagination {
				padding: 18px 24px;
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 16px;
			}

			.ebm-pagination-left,
			.ebm-pagination-right {
				display: flex;
				align-items: center;
				gap: 10px;
			}

			.ebm-page-link {
				display: inline-flex;
				min-width: 34px;
				min-height: 34px;
				align-items: center;
				justify-content: center;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				background: #fff;
				text-decoration: none;
			}

			.ebm-page-link.current {
				background: #2271b1;
				border-color: #2271b1;
				color: #fff;
			}

			.ebm-customer-editor {
				margin-top: 18px;
			}

			@media (max-width: 1100px) {
				.ebm-admin-layout,
				.ebm-form-grid,
				.ebm-extra-edit,
				.ebm-customers-filter {
					grid-template-columns: 1fr;
				}

				.ebm-inline-duration {
					grid-template-columns: 1fr;
				}

				.ebm-customers-table {
					display: block;
					overflow-x: auto;
				}

				.ebm-customers-header,
				.ebm-pagination {
					align-items: flex-start;
					flex-direction: column;
				}

				.ebm-bulk-bar {
					align-items: flex-start;
					flex-direction: column;
				}
			}
		</style>
		<?php
	}

	public static function settings() {
		self::cap();

		$s = EBM_Settings::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Electrical Booking Settings', 'electrical-booking-manager' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ebm_save_settings">
				<?php wp_nonce_field( 'ebm_save_settings' ); ?>

				<table class="form-table">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Business name', 'electrical-booking-manager' ); ?></th>
							<td><input name="business_name" class="regular-text" value="<?php echo esc_attr( $s['business_name'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Admin email', 'electrical-booking-manager' ); ?></th>
							<td><input name="admin_email" class="regular-text" value="<?php echo esc_attr( $s['admin_email'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Business days', 'electrical-booking-manager' ); ?></th>
							<td>
								<?php foreach ( array( '0' => 'Sun', '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat' ) as $n => $l ) : ?>
									<label>
										<input type="checkbox" name="business_days[]" value="<?php echo esc_attr( $n ); ?>" <?php checked( in_array( $n, $s['business_days'], true ) ); ?>>
										<?php echo esc_html( $l ); ?>
									</label>
								<?php endforeach; ?>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Hours', 'electrical-booking-manager' ); ?></th>
							<td>
								<input type="time" name="work_start" value="<?php echo esc_attr( $s['work_start'] ); ?>">
								<?php esc_html_e( 'to', 'electrical-booking-manager' ); ?>
								<input type="time" name="work_end" value="<?php echo esc_attr( $s['work_end'] ); ?>">
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Buffer minutes', 'electrical-booking-manager' ); ?></th>
							<td><input type="number" name="buffer_minutes" value="<?php echo esc_attr( $s['buffer_minutes'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Max bookings per slot', 'electrical-booking-manager' ); ?></th>
							<td><input type="number" min="1" name="max_bookings_per_slot" value="<?php echo esc_attr( $s['max_bookings_per_slot'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Holidays', 'electrical-booking-manager' ); ?></th>
							<td>
								<textarea name="holidays" rows="5" class="large-text"><?php echo esc_textarea( $s['holidays'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One YYYY-MM-DD date per line.', 'electrical-booking-manager' ); ?></p>
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Global deposit', 'electrical-booking-manager' ); ?></th>
							<td>
								<select name="global_deposit_type">
									<option value="percent" <?php selected( $s['global_deposit_type'], 'percent' ); ?>><?php esc_html_e( 'Percent', 'electrical-booking-manager' ); ?></option>
									<option value="fixed" <?php selected( $s['global_deposit_type'], 'fixed' ); ?>><?php esc_html_e( 'Fixed', 'electrical-booking-manager' ); ?></option>
								</select>
								<input type="number" step="0.01" name="global_deposit_value" value="<?php echo esc_attr( $s['global_deposit_value'] ); ?>">
							</td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Stripe publishable key', 'electrical-booking-manager' ); ?></th>
							<td><input name="stripe_publishable_key" class="regular-text" value="<?php echo esc_attr( $s['stripe_publishable_key'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Stripe secret key', 'electrical-booking-manager' ); ?></th>
							<td><input name="stripe_secret_key" type="password" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'electrical-booking-manager' ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Stripe webhook secret', 'electrical-booking-manager' ); ?></th>
							<td><input name="stripe_webhook_secret" type="password" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'electrical-booking-manager' ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google client ID', 'electrical-booking-manager' ); ?></th>
							<td><input name="google_client_id" class="regular-text" value="<?php echo esc_attr( $s['google_client_id'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google client secret', 'electrical-booking-manager' ); ?></th>
							<td><input name="google_client_secret" type="password" class="regular-text" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'electrical-booking-manager' ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google calendar ID', 'electrical-booking-manager' ); ?></th>
							<td><input name="google_calendar_id" class="regular-text" value="<?php echo esc_attr( $s['google_calendar_id'] ); ?>"></td>
						</tr>

						<tr>
							<th><?php esc_html_e( 'Google OAuth', 'electrical-booking-manager' ); ?></th>
							<td>
								<?php if ( EBM_Google::connected() ) : ?>
									<strong><?php esc_html_e( 'Connected', 'electrical-booking-manager' ); ?></strong>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_google_disconnect' ), 'ebm_google_disconnect' ) ); ?>">
										<?php esc_html_e( 'Disconnect', 'electrical-booking-manager' ); ?>
									</a>
								<?php else : ?>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_google_connect' ), 'ebm_google_connect' ) ); ?>">
										<?php esc_html_e( 'Connect Google Calendar', 'electrical-booking-manager' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function save_settings() {
		self::cap();
		check_admin_referer( 'ebm_save_settings' );

		EBM_Settings::save( wp_unslash( $_POST ) );

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-settings&updated=1' ) );
		exit;
	}

	public static function customers() {
		self::cap();
		self::jobs_admin_css();

		global $wpdb;

		$customers_table = EBM_Helpers::table( 'customers' );
		$bookings_table  = EBM_Helpers::table( 'bookings' );

		$search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = absint( $_GET['per_page'] ?? 20 );

		if ( ! in_array( $per_page, array( 10, 20, 50, 100 ), true ) ) {
			$per_page = 20;
		}

		$offset = ( $paged - 1 ) * $per_page;

		$where_sql  = '1=1';
		$where_args = array();

		if ( '' !== $search ) {
			$like       = '%' . $wpdb->esc_like( $search ) . '%';
			$where_sql  = '(c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR c.address LIKE %s)';
			$where_args = array( $like, $like, $like, $like );
		}

		$total_sql = "SELECT COUNT(*) FROM $customers_table c WHERE $where_sql";
		$total     = ! empty( $where_args )
			? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $where_args ) )
			: (int) $wpdb->get_var( $total_sql );

		$query_sql = "
			SELECT
				c.*,
				COUNT(b.id) AS total_bookings,
				MAX(b.start_at) AS recent_booking
			FROM $customers_table c
			LEFT JOIN $bookings_table b ON b.customer_id = c.id
			WHERE $where_sql
			GROUP BY c.id
			ORDER BY c.updated_at DESC, c.id DESC
			LIMIT %d OFFSET %d
		";

		$query_args = array_merge( $where_args, array( $per_page, $offset ) );

		$customers = $wpdb->get_results(
			$wpdb->prepare(
				$query_sql,
				$query_args
			)
		);

		$edit_customer_id = absint( $_GET['customer_id'] ?? 0 );
		$editing_customer = null;

		if ( $edit_customer_id ) {
			$editing_customer = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $customers_table WHERE id = %d",
					$edit_customer_id
				)
			);
		}

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$base_url    = admin_url( 'admin.php?page=ebm-customers' );
		?>
		<div class="wrap ebm-admin-shell">
			<?php self::admin_notice(); ?>

			<div class="ebm-customers-card">
				<div class="ebm-customers-header">
					<h1><?php esc_html_e( 'Manage Customers', 'electrical-booking-manager' ); ?></h1>

					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ebm-customers&customer_id=0#ebm-customer-editor' ) ); ?>">
						<?php esc_html_e( '+ Add New', 'electrical-booking-manager' ); ?>
					</a>
				</div>

				<form class="ebm-customers-filter" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="ebm-customers">

					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search customer', 'electrical-booking-manager' ); ?>">

					<a class="button" href="<?php echo esc_url( $base_url ); ?>">
						<?php esc_html_e( 'Reset', 'electrical-booking-manager' ); ?>
					</a>

					<button class="button button-primary" type="submit">
						<?php esc_html_e( 'Apply', 'electrical-booking-manager' ); ?>
					</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ebm_bulk_customers">
					<?php wp_nonce_field( 'ebm_bulk_customers' ); ?>

					<div class="ebm-bulk-bar">
						<select name="bulk_action">
							<option value=""><?php esc_html_e( 'Bulk actions', 'electrical-booking-manager' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete selected', 'electrical-booking-manager' ); ?></option>
						</select>

						<button class="button" type="submit" onclick="return confirm('<?php echo esc_js( __( 'Apply this bulk action? Customers with bookings will be skipped.', 'electrical-booking-manager' ) ); ?>');">
							<?php esc_html_e( 'Apply', 'electrical-booking-manager' ); ?>
						</button>
					</div>

					<table class="ebm-customers-table">
						<thead>
							<tr>
								<th style="width:40px;"><input type="checkbox" id="ebm-select-all-customers"></th>
								<th><?php esc_html_e( 'Full Name', 'electrical-booking-manager' ); ?></th>
								<th><?php esc_html_e( 'Email', 'electrical-booking-manager' ); ?></th>
								<th><?php esc_html_e( 'Phone', 'electrical-booking-manager' ); ?></th>
								<th><?php esc_html_e( 'Recent Booking', 'electrical-booking-manager' ); ?></th>
								<th><?php esc_html_e( 'Total Bookings', 'electrical-booking-manager' ); ?></th>
							</tr>
						</thead>

						<tbody>
							<?php if ( empty( $customers ) ) : ?>
								<tr>
									<td colspan="6"><?php esc_html_e( 'No customers found.', 'electrical-booking-manager' ); ?></td>
								</tr>
							<?php endif; ?>

							<?php foreach ( $customers as $customer ) : ?>
								<?php
								$initial    = strtoupper( mb_substr( $customer->name, 0, 1 ) );
								$edit_url   = admin_url( 'admin.php?page=ebm-customers&customer_id=' . absint( $customer->id ) . '#ebm-customer-editor' );
								$delete_url = wp_nonce_url(
									admin_url( 'admin-post.php?action=ebm_delete_customer&customer_id=' . absint( $customer->id ) ),
									'ebm_delete_customer_' . absint( $customer->id )
								);
								?>
								<tr>
									<td>
										<input type="checkbox" class="ebm-customer-check" name="customer_ids[]" value="<?php echo esc_attr( $customer->id ); ?>">
									</td>

									<td>
										<div class="ebm-customer-name">
											<span class="ebm-avatar"><?php echo esc_html( $initial ); ?></span>
											<span>
												<?php echo esc_html( $customer->name ); ?>
												<div class="ebm-row-actions">
													<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'electrical-booking-manager' ); ?></a>
													|
													<a class="ebm-danger-link" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this customer? Customers with bookings will not be deleted.', 'electrical-booking-manager' ) ); ?>');"><?php esc_html_e( 'Delete', 'electrical-booking-manager' ); ?></a>
												</div>
											</span>
										</div>
									</td>

									<td><?php echo esc_html( $customer->email ); ?></td>
									<td><?php echo esc_html( $customer->phone ); ?></td>

									<td>
										<?php
										if ( ! empty( $customer->recent_booking ) ) {
											echo esc_html( mysql2date( 'F j, Y g:i a', $customer->recent_booking ) );
										} else {
											echo esc_html__( 'None', 'electrical-booking-manager' );
										}
										?>
									</td>

									<td><?php echo esc_html( absint( $customer->total_bookings ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</form>

				<div class="ebm-pagination">
					<div class="ebm-pagination-left">
						<span>
							<?php
							printf(
								esc_html__( 'Showing %1$d out of %2$d', 'electrical-booking-manager' ),
								count( $customers ),
								$total
							);
							?>
						</span>

						<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
							<input type="hidden" name="page" value="ebm-customers">
							<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">

							<label>
								<?php esc_html_e( 'Per Page', 'electrical-booking-manager' ); ?>
								<select name="per_page" onchange="this.form.submit()">
									<option value="10" <?php selected( $per_page, 10 ); ?>>10</option>
									<option value="20" <?php selected( $per_page, 20 ); ?>>20</option>
									<option value="50" <?php selected( $per_page, 50 ); ?>>50</option>
									<option value="100" <?php selected( $per_page, 100 ); ?>>100</option>
								</select>
							</label>
						</form>
					</div>

					<div class="ebm-pagination-right">
						<?php if ( $paged > 1 ) : ?>
							<a class="ebm-page-link" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ebm-customers', 's' => $search, 'per_page' => $per_page, 'paged' => $paged - 1 ), admin_url( 'admin.php' ) ) ); ?>">‹</a>
						<?php endif; ?>

						<span class="ebm-page-link current"><?php echo esc_html( $paged ); ?></span>

						<?php if ( $paged < $total_pages ) : ?>
							<a class="ebm-page-link" href="<?php echo esc_url( add_query_arg( array( 'page' => 'ebm-customers', 's' => $search, 'per_page' => $per_page, 'paged' => $paged + 1 ), admin_url( 'admin.php' ) ) ); ?>">›</a>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<?php self::customer_editor_panel( $editing_customer ); ?>
		</div>

		<script>
			document.addEventListener('DOMContentLoaded', function () {
				const selectAll = document.getElementById('ebm-select-all-customers');

				if (!selectAll) {
					return;
				}

				selectAll.addEventListener('change', function () {
					document.querySelectorAll('.ebm-customer-check').forEach(function (checkbox) {
						checkbox.checked = selectAll.checked;
					});
				});
			});
		</script>
		<?php
	}

	private static function customer_editor_panel( $customer ) {
		$is_new        = ! $customer;
		$show_new_form = isset( $_GET['customer_id'] ) && '0' === (string) $_GET['customer_id'];

		if ( $is_new && ! $show_new_form ) {
			return;
		}

		$customer_id = $is_new ? 0 : (int) $customer->id;
		?>
		<div id="ebm-customer-editor" class="ebm-panel ebm-customer-editor">
			<div class="ebm-panel-header">
				<h2><?php echo $is_new ? esc_html__( 'Add customer', 'electrical-booking-manager' ) : esc_html__( 'Edit customer', 'electrical-booking-manager' ); ?></h2>
			</div>

			<div class="ebm-panel-body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ebm_save_customer">
					<input type="hidden" name="id" value="<?php echo esc_attr( $customer_id ); ?>">
					<?php wp_nonce_field( 'ebm_save_customer' ); ?>

					<div class="ebm-form-grid">
						<div class="ebm-field">
							<label for="ebm-customer-name"><?php esc_html_e( 'Full name', 'electrical-booking-manager' ); ?></label>
							<input id="ebm-customer-name" type="text" name="name" required value="<?php echo esc_attr( $customer->name ?? '' ); ?>">
						</div>

						<div class="ebm-field">
							<label for="ebm-customer-email"><?php esc_html_e( 'Email', 'electrical-booking-manager' ); ?></label>
							<input id="ebm-customer-email" type="email" name="email" required value="<?php echo esc_attr( $customer->email ?? '' ); ?>">
						</div>

						<div class="ebm-field">
							<label for="ebm-customer-phone"><?php esc_html_e( 'Phone', 'electrical-booking-manager' ); ?></label>
							<input id="ebm-customer-phone" type="tel" name="phone" value="<?php echo esc_attr( $customer->phone ?? '' ); ?>">
						</div>

						<div class="ebm-field ebm-full">
							<label for="ebm-customer-address"><?php esc_html_e( 'Service address', 'electrical-booking-manager' ); ?></label>
							<textarea id="ebm-customer-address" name="address" rows="4"><?php echo esc_textarea( $customer->address ?? '' ); ?></textarea>
						</div>
					</div>

					<div class="ebm-actions">
						<?php submit_button( $is_new ? __( 'Create customer', 'electrical-booking-manager' ) : __( 'Save customer', 'electrical-booking-manager' ), 'primary', 'submit', false ); ?>

						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ebm-customers' ) ); ?>">
							<?php esc_html_e( 'Cancel', 'electrical-booking-manager' ); ?>
						</a>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	public static function save_customer() {
		self::cap();
		check_admin_referer( 'ebm_save_customer' );

		global $wpdb;

		$id  = absint( $_POST['id'] ?? 0 );
		$now = current_time( 'mysql' );

		$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );

		if ( '' === $name || '' === $email || ! is_email( $email ) ) {
			wp_die( esc_html__( 'A valid name and email are required.', 'electrical-booking-manager' ) );
		}

		$data = array(
			'name'       => $name,
			'email'      => $email,
			'phone'      => $phone,
			'address'    => sanitize_textarea_field( wp_unslash( $_POST['address'] ?? '' ) ),
			'updated_at' => $now,
		);

		if ( $id ) {
			$wpdb->update(
				EBM_Helpers::table( 'customers' ),
				$data,
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			$customer_id = $id;
		} else {
			$data['privacy_accepted_at'] = null;
			$data['created_at']          = $now;

			$wpdb->insert(
				EBM_Helpers::table( 'customers' ),
				$data,
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			$customer_id = (int) $wpdb->insert_id;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-customers&customer_id=' . absint( $customer_id ) . '&updated=1#ebm-customer-editor' ) );
		exit;
	}

	public static function delete_customer() {
		self::cap();

		$customer_id = absint( $_GET['customer_id'] ?? 0 );

		check_admin_referer( 'ebm_delete_customer_' . $customer_id );

		if ( ! $customer_id ) {
			wp_die( esc_html__( 'Invalid customer.', 'electrical-booking-manager' ) );
		}

		global $wpdb;

		$bookings_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . EBM_Helpers::table( 'bookings' ) . ' WHERE customer_id = %d',
				$customer_id
			)
		);

		if ( $bookings_count > 0 ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ebm-customers&not_deleted=1' ) );
			exit;
		}

		$wpdb->delete(
			EBM_Helpers::table( 'customers' ),
			array( 'id' => $customer_id ),
			array( '%d' )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-customers&deleted=1' ) );
		exit;
	}

	public static function bulk_customers() {
		self::cap();
		check_admin_referer( 'ebm_bulk_customers' );

		$bulk_action = sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) );

		if ( 'delete' !== $bulk_action ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ebm-customers' ) );
			exit;
		}

		$customer_ids = isset( $_POST['customer_ids'] ) && is_array( $_POST['customer_ids'] )
			? array_map( 'absint', wp_unslash( $_POST['customer_ids'] ) )
			: array();

		$customer_ids = array_filter( array_unique( $customer_ids ) );

		if ( empty( $customer_ids ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ebm-customers' ) );
			exit;
		}

		global $wpdb;

		$customers_table = EBM_Helpers::table( 'customers' );
		$bookings_table  = EBM_Helpers::table( 'bookings' );

		$deleted = 0;
		$skipped = 0;

		foreach ( $customer_ids as $customer_id ) {
			$bookings_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $bookings_table WHERE customer_id = %d",
					$customer_id
				)
			);

			if ( $bookings_count > 0 ) {
				$skipped++;
				continue;
			}

			$result = $wpdb->delete(
				$customers_table,
				array( 'id' => $customer_id ),
				array( '%d' )
			);

			if ( false !== $result ) {
				$deleted++;
			}
		}

		$args = array(
			'page' => 'ebm-customers',
		);

		if ( $deleted > 0 ) {
			$args['bulk_deleted'] = 1;
		}

		if ( $skipped > 0 ) {
			$args['bulk_skipped'] = 1;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function jobs() {
		self::cap();
		self::jobs_admin_css();

		global $wpdb;

		$jobs_table   = EBM_Helpers::table( 'jobs' );
		$addons_table = EBM_Helpers::table( 'addons' );

		$jobs = $wpdb->get_results( "SELECT * FROM $jobs_table ORDER BY is_active DESC, title ASC" );

		$is_new_request = isset( $_GET['job_id'] ) && '0' === (string) $_GET['job_id'];
		$selected_job_id = isset( $_GET['job_id'] ) ? absint( $_GET['job_id'] ) : 0;

		if ( ! $is_new_request && ! $selected_job_id && ! empty( $jobs ) ) {
			$selected_job_id = (int) $jobs[0]->id;
		}

		$selected_job = null;

		if ( ! $is_new_request ) {
			foreach ( $jobs as $job ) {
				if ( (int) $job->id === $selected_job_id ) {
					$selected_job = $job;
					break;
				}
			}
		}

		$addons = array();

		if ( $selected_job ) {
			$addons = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $addons_table WHERE job_id = %d ORDER BY is_active DESC, title ASC",
					$selected_job_id
				)
			);
		}

		$new_job_url = admin_url( 'admin.php?page=ebm-jobs&job_id=0' );
		?>
		<div class="wrap ebm-admin-shell">
			<h1><?php esc_html_e( 'Jobs & Extras', 'electrical-booking-manager' ); ?></h1>

			<?php self::admin_notice(); ?>

			<div class="ebm-admin-layout">
				<div class="ebm-panel">
					<div class="ebm-panel-header">
						<h2><?php esc_html_e( 'Services', 'electrical-booking-manager' ); ?></h2>
					</div>

					<div class="ebm-panel-body">
						<p>
							<a class="button button-primary" href="<?php echo esc_url( $new_job_url ); ?>">
								<?php esc_html_e( 'Add new job', 'electrical-booking-manager' ); ?>
							</a>
						</p>

						<div class="ebm-job-list">
							<?php if ( empty( $jobs ) ) : ?>
								<div class="ebm-muted-box"><?php esc_html_e( 'No jobs have been created yet.', 'electrical-booking-manager' ); ?></div>
							<?php endif; ?>

							<?php foreach ( $jobs as $job ) : ?>
								<?php
								$card_url    = admin_url( 'admin.php?page=ebm-jobs&job_id=' . absint( $job->id ) );
								$is_selected = ! $is_new_request && ( (int) $job->id === $selected_job_id );
								$addon_count = (int) $wpdb->get_var(
									$wpdb->prepare(
										"SELECT COUNT(*) FROM $addons_table WHERE job_id = %d AND is_active = 1",
										(int) $job->id
									)
								);
								?>
								<a class="ebm-job-card <?php echo $is_selected ? 'is-selected' : ''; ?>" href="<?php echo esc_url( $card_url ); ?>">
									<span class="ebm-job-card-title">
										<span><?php echo esc_html( $job->title ); ?></span>
										<span class="ebm-badge <?php echo (int) $job->is_active ? 'green' : 'grey'; ?>">
											<?php echo (int) $job->is_active ? esc_html__( 'Active', 'electrical-booking-manager' ) : esc_html__( 'Hidden', 'electrical-booking-manager' ); ?>
										</span>
									</span>

									<span class="ebm-job-card-meta">
										<span><?php echo esc_html( EBM_Helpers::money( $job->price ) ); ?></span>
										<span><?php echo esc_html( self::format_duration( $job->duration_minutes ) ); ?></span>
										<span>
											<?php
											echo esc_html(
												sprintf(
													_n( '%d extra', '%d extras', $addon_count, 'electrical-booking-manager' ),
													$addon_count
												)
											);
											?>
										</span>
									</span>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div>
					<?php self::job_editor_panel( $selected_job ); ?>
					<?php self::addons_panel( $selected_job, $addons ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	private static function job_editor_panel( $job ) {
		$is_new = ! $job;
		$job_id = $is_new ? 0 : (int) $job->id;

		$duration = self::split_minutes_to_best_unit( $job->duration_minutes ?? 60 );
		?>
		<div class="ebm-panel" style="margin-bottom:18px;">
			<div class="ebm-panel-header">
				<h2>
					<?php echo $is_new ? esc_html__( 'Add new job', 'electrical-booking-manager' ) : esc_html__( 'Edit job', 'electrical-booking-manager' ); ?>
				</h2>
			</div>

			<div class="ebm-panel-body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ebm_save_job">
					<input type="hidden" name="id" value="<?php echo esc_attr( $job_id ); ?>">
					<?php wp_nonce_field( 'ebm_save_job' ); ?>

					<div class="ebm-form-grid">
						<div class="ebm-field ebm-full">
							<label for="ebm-job-title"><?php esc_html_e( 'Job name', 'electrical-booking-manager' ); ?></label>
							<input id="ebm-job-title" type="text" name="title" required value="<?php echo esc_attr( $job->title ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Install new light fitting', 'electrical-booking-manager' ); ?>">
						</div>

						<div class="ebm-field ebm-full">
							<label for="ebm-job-description"><?php esc_html_e( 'Customer description', 'electrical-booking-manager' ); ?></label>
							<textarea id="ebm-job-description" name="description" rows="5"><?php echo esc_textarea( $job->description ?? '' ); ?></textarea>
						</div>

						<div class="ebm-field">
							<label for="ebm-job-price"><?php esc_html_e( 'Base price', 'electrical-booking-manager' ); ?></label>
							<input id="ebm-job-price" type="number" step="0.01" min="0" name="price" value="<?php echo esc_attr( $job->price ?? '0.00' ); ?>">
						</div>

						<div class="ebm-field">
							<label for="ebm-job-duration-value"><?php esc_html_e( 'Duration', 'electrical-booking-manager' ); ?></label>
							<div class="ebm-inline-duration">
								<input id="ebm-job-duration-value" type="number" step="0.01" min="0" name="duration_value" value="<?php echo esc_attr( $duration['value'] ); ?>">
								<?php self::duration_unit_select( 'duration_unit', $duration['unit'] ); ?>
							</div>
						</div>

						<div class="ebm-field">
							<label for="ebm-job-deposit-type"><?php esc_html_e( 'Deposit rule', 'electrical-booking-manager' ); ?></label>
							<select id="ebm-job-deposit-type" name="deposit_type">
								<option value="global" <?php selected( $job->deposit_type ?? 'global', 'global' ); ?>><?php esc_html_e( 'Use global setting', 'electrical-booking-manager' ); ?></option>
								<option value="percent" <?php selected( $job->deposit_type ?? '', 'percent' ); ?>><?php esc_html_e( 'Percentage', 'electrical-booking-manager' ); ?></option>
								<option value="fixed" <?php selected( $job->deposit_type ?? '', 'fixed' ); ?>><?php esc_html_e( 'Fixed amount', 'electrical-booking-manager' ); ?></option>
							</select>
						</div>

						<div class="ebm-field">
							<label for="ebm-job-deposit-value"><?php esc_html_e( 'Deposit value', 'electrical-booking-manager' ); ?></label>
							<input id="ebm-job-deposit-value" type="number" step="0.01" min="0" name="deposit_value" value="<?php echo esc_attr( $job->deposit_value ?? '' ); ?>">
						</div>

						<div class="ebm-field">
							<label>
								<input type="checkbox" name="allow_split_days" value="1" <?php checked( (int) ( $job->allow_split_days ?? 1 ), 1 ); ?>>
								<?php esc_html_e( 'Allow split days', 'electrical-booking-manager' ); ?>
							</label>
						</div>

						<div class="ebm-field">
							<label>
								<input type="checkbox" name="is_active" value="1" <?php checked( (int) ( $job->is_active ?? 1 ), 1 ); ?>>
								<?php esc_html_e( 'Active on booking form', 'electrical-booking-manager' ); ?>
							</label>
						</div>
					</div>

					<div class="ebm-actions">
						<?php submit_button( $is_new ? __( 'Create job', 'electrical-booking-manager' ) : __( 'Save job', 'electrical-booking-manager' ), 'primary', 'submit', false ); ?>

						<?php if ( ! $is_new ) : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ebm-jobs&job_id=0' ) ); ?>">
								<?php esc_html_e( 'Add another job', 'electrical-booking-manager' ); ?>
							</a>

							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_delete_job&job_id=' . $job_id ), 'ebm_delete_job_' . $job_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Hide this job from the booking form? Existing bookings will stay safe.', 'electrical-booking-manager' ) ); ?>');">
								<?php esc_html_e( 'Hide job', 'electrical-booking-manager' ); ?>
							</a>

							<a class="ebm-danger-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_hard_delete_job&job_id=' . $job_id ), 'ebm_hard_delete_job_' . $job_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this job, its extras, and linked test bookings? This cannot be undone.', 'electrical-booking-manager' ) ); ?>');">
								<?php esc_html_e( 'Delete permanently', 'electrical-booking-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	private static function addons_panel( $job, $addons ) {
		if ( ! $job ) {
			?>
			<div class="ebm-panel">
				<div class="ebm-panel-header">
					<h2><?php esc_html_e( 'Extras', 'electrical-booking-manager' ); ?></h2>
				</div>

				<div class="ebm-panel-body">
					<div class="ebm-muted-box"><?php esc_html_e( 'Save the job first, then you can add extras to it.', 'electrical-booking-manager' ); ?></div>
				</div>
			</div>
			<?php
			return;
		}

		$job_id = (int) $job->id;
		?>
		<div class="ebm-panel">
			<div class="ebm-panel-header">
				<h2><?php esc_html_e( 'Extras for this job', 'electrical-booking-manager' ); ?></h2>
			</div>

			<div class="ebm-panel-body">
				<div class="ebm-extra-list">
					<?php if ( empty( $addons ) ) : ?>
						<div class="ebm-muted-box"><?php esc_html_e( 'No extras yet. Add the first one below.', 'electrical-booking-manager' ); ?></div>
					<?php endif; ?>

					<?php foreach ( $addons as $addon ) : ?>
						<?php $addon_duration = self::split_minutes_to_best_unit( $addon->extra_duration_minutes ); ?>

						<form class="ebm-extra-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="ebm_save_addon">
							<input type="hidden" name="id" value="<?php echo esc_attr( $addon->id ); ?>">
							<input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>">
							<?php wp_nonce_field( 'ebm_save_addon_' . (int) $addon->id ); ?>

							<div class="ebm-extra-top">
								<div>
									<strong><?php echo esc_html( $addon->title ); ?></strong>
									<div class="ebm-extra-meta">
										<?php echo esc_html( EBM_Helpers::money( $addon->price ) ); ?>
										·
										<?php echo esc_html( self::format_duration( $addon->extra_duration_minutes ) ); ?>
										<?php esc_html_e( 'per unit', 'electrical-booking-manager' ); ?>
									</div>
								</div>

								<span class="ebm-badge <?php echo (int) $addon->is_active ? 'green' : 'grey'; ?>">
									<?php echo (int) $addon->is_active ? esc_html__( 'Active', 'electrical-booking-manager' ) : esc_html__( 'Hidden', 'electrical-booking-manager' ); ?>
								</span>
							</div>

							<div class="ebm-extra-edit">
								<div class="wide">
									<label>
										<?php esc_html_e( 'Extra name', 'electrical-booking-manager' ); ?>
										<input type="text" name="title" required value="<?php echo esc_attr( $addon->title ); ?>">
									</label>
								</div>

								<div class="wide">
									<label>
										<?php esc_html_e( 'Description', 'electrical-booking-manager' ); ?>
										<textarea name="description" rows="3"><?php echo esc_textarea( $addon->description ); ?></textarea>
									</label>
								</div>

								<div>
									<label>
										<?php esc_html_e( 'Price', 'electrical-booking-manager' ); ?>
										<input type="number" step="0.01" min="0" name="price" value="<?php echo esc_attr( $addon->price ); ?>">
									</label>
								</div>

								<div>
									<label>
										<?php esc_html_e( 'Min qty', 'electrical-booking-manager' ); ?>
										<input type="number" min="0" name="min_qty" value="<?php echo esc_attr( $addon->min_qty ); ?>">
									</label>
								</div>

								<div>
									<label>
										<?php esc_html_e( 'Max qty', 'electrical-booking-manager' ); ?>
										<input type="number" min="0" name="max_qty" value="<?php echo esc_attr( $addon->max_qty ); ?>">
									</label>
								</div>

								<div>
									<label>
										<?php esc_html_e( 'Extra duration', 'electrical-booking-manager' ); ?>
										<div class="ebm-inline-duration">
											<input type="number" step="0.01" min="0" name="extra_duration_value" value="<?php echo esc_attr( $addon_duration['value'] ); ?>">
											<?php self::duration_unit_select( 'extra_duration_unit', $addon_duration['unit'] ); ?>
										</div>
									</label>
								</div>

								<div class="wide">
									<label>
										<input type="checkbox" name="is_active" value="1" <?php checked( (int) $addon->is_active, 1 ); ?>>
										<?php esc_html_e( 'Active on booking form', 'electrical-booking-manager' ); ?>
									</label>
								</div>
							</div>

							<div class="ebm-actions">
								<?php submit_button( __( 'Save extra', 'electrical-booking-manager' ), 'secondary', 'submit', false ); ?>

								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_delete_addon&addon_id=' . absint( $addon->id ) . '&job_id=' . $job_id ), 'ebm_delete_addon_' . absint( $addon->id ) ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Hide this extra from the booking form?', 'electrical-booking-manager' ) ); ?>');">
									<?php esc_html_e( 'Hide extra', 'electrical-booking-manager' ); ?>
								</a>

								<a class="ebm-danger-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_hard_delete_addon&addon_id=' . absint( $addon->id ) . '&job_id=' . $job_id ), 'ebm_hard_delete_addon_' . absint( $addon->id ) ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this extra? This cannot be undone.', 'electrical-booking-manager' ) ); ?>');">
									<?php esc_html_e( 'Delete permanently', 'electrical-booking-manager' ); ?>
								</a>
							</div>
						</form>
					<?php endforeach; ?>
				</div>

				<hr>

				<h3><?php esc_html_e( 'Add new extra', 'electrical-booking-manager' ); ?></h3>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ebm_save_addon">
					<input type="hidden" name="id" value="0">
					<input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>">
					<?php wp_nonce_field( 'ebm_save_addon_0' ); ?>

					<div class="ebm-extra-edit">
						<div class="wide">
							<label>
								<?php esc_html_e( 'Extra name', 'electrical-booking-manager' ); ?>
								<input type="text" name="title" required placeholder="<?php esc_attr_e( 'Smart switch upgrade', 'electrical-booking-manager' ); ?>">
							</label>
						</div>

						<div class="wide">
							<label>
								<?php esc_html_e( 'Description', 'electrical-booking-manager' ); ?>
								<textarea name="description" rows="3"></textarea>
							</label>
						</div>

						<div>
							<label>
								<?php esc_html_e( 'Price', 'electrical-booking-manager' ); ?>
								<input type="number" step="0.01" min="0" name="price" value="0.00">
							</label>
						</div>

						<div>
							<label>
								<?php esc_html_e( 'Min qty', 'electrical-booking-manager' ); ?>
								<input type="number" min="0" name="min_qty" value="0">
							</label>
						</div>

						<div>
							<label>
								<?php esc_html_e( 'Max qty', 'electrical-booking-manager' ); ?>
								<input type="number" min="0" name="max_qty" value="1">
							</label>
						</div>

						<div>
							<label>
								<?php esc_html_e( 'Extra duration', 'electrical-booking-manager' ); ?>
								<div class="ebm-inline-duration">
									<input type="number" step="0.01" min="0" name="extra_duration_value" value="0">
									<?php self::duration_unit_select( 'extra_duration_unit', 'minutes' ); ?>
								</div>
							</label>
						</div>

						<div class="wide">
							<label>
								<input type="checkbox" name="is_active" value="1" checked>
								<?php esc_html_e( 'Active on booking form', 'electrical-booking-manager' ); ?>
							</label>
						</div>
					</div>

					<div class="ebm-actions">
						<?php submit_button( __( 'Add extra', 'electrical-booking-manager' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	public static function save_job() {
		self::cap();
		check_admin_referer( 'ebm_save_job' );

		global $wpdb;

		$now = current_time( 'mysql' );
		$id  = absint( $_POST['id'] ?? 0 );

		$deposit_type = sanitize_key( wp_unslash( $_POST['deposit_type'] ?? 'global' ) );

		if ( ! in_array( $deposit_type, array( 'global', 'percent', 'fixed' ), true ) ) {
			$deposit_type = 'global';
		}

		$duration_minutes = self::duration_to_minutes(
			wp_unslash( $_POST['duration_value'] ?? 60 ),
			wp_unslash( $_POST['duration_unit'] ?? 'minutes' )
		);

		if ( $duration_minutes < 1 ) {
			$duration_minutes = 1;
		}

		$deposit_value = null;

		if ( isset( $_POST['deposit_value'] ) && '' !== (string) $_POST['deposit_value'] ) {
			$deposit_value = (float) $_POST['deposit_value'];
		}

		$data = array(
			'title'            => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
			'description'      => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'price'            => (float) ( $_POST['price'] ?? 0 ),
			'duration_minutes' => $duration_minutes,
			'deposit_type'     => $deposit_type,
			'deposit_value'    => $deposit_value,
			'allow_split_days' => isset( $_POST['allow_split_days'] ) ? 1 : 0,
			'is_active'        => isset( $_POST['is_active'] ) ? 1 : 0,
			'updated_at'       => $now,
		);

		if ( '' === $data['title'] ) {
			wp_die( esc_html__( 'Job title is required.', 'electrical-booking-manager' ) );
		}

		if ( $id ) {
			$wpdb->update(
				EBM_Helpers::table( 'jobs' ),
				$data,
				array( 'id' => $id ),
				array( '%s', '%s', '%f', '%d', '%s', '%f', '%d', '%d', '%s' ),
				array( '%d' )
			);

			$job_id = $id;
		} else {
			$data['created_at'] = $now;

			$wpdb->insert(
				EBM_Helpers::table( 'jobs' ),
				$data,
				array( '%s', '%s', '%f', '%d', '%s', '%f', '%d', '%d', '%s', '%s' )
			);

			$job_id = (int) $wpdb->insert_id;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-jobs&job_id=' . absint( $job_id ) . '&updated=1' ) );
		exit;
	}

	public static function delete_job() {
		self::cap();

		$job_id = absint( $_GET['job_id'] ?? 0 );

		check_admin_referer( 'ebm_delete_job_' . $job_id );

		global $wpdb;

		$wpdb->update(
			EBM_Helpers::table( 'jobs' ),
			array(
				'is_active'  => 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-jobs&job_id=' . absint( $job_id ) . '&hidden=1' ) );
		exit;
	}

	public static function hard_delete_job() {
		self::cap();

		$job_id = absint( $_GET['job_id'] ?? 0 );

		check_admin_referer( 'ebm_hard_delete_job_' . $job_id );

		if ( ! $job_id ) {
			wp_die( esc_html__( 'Invalid job.', 'electrical-booking-manager' ) );
		}

		global $wpdb;

		$bookings_table     = EBM_Helpers::table( 'bookings' );
		$booking_days_table = EBM_Helpers::table( 'booking_days' );
		$transactions_table = EBM_Helpers::table( 'transactions' );
		$addons_table       = EBM_Helpers::table( 'addons' );
		$jobs_table         = EBM_Helpers::table( 'jobs' );

		$booking_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM $bookings_table WHERE job_id = %d",
				$job_id
			)
		);

		if ( ! empty( $booking_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $booking_ids ), '%d' ) );

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $booking_days_table WHERE booking_id IN ($placeholders)",
					$booking_ids
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $transactions_table WHERE booking_id IN ($placeholders)",
					$booking_ids
				)
			);

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM $bookings_table WHERE id IN ($placeholders)",
					$booking_ids
				)
			);
		}

		$wpdb->delete(
			$addons_table,
			array( 'job_id' => $job_id ),
			array( '%d' )
		);

		$wpdb->delete(
			$jobs_table,
			array( 'id' => $job_id ),
			array( '%d' )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-jobs&deleted=1' ) );
		exit;
	}

	public static function save_addon() {
		self::cap();

		$id = absint( $_POST['id'] ?? 0 );

		check_admin_referer( 'ebm_save_addon_' . $id );

		global $wpdb;

		$now    = current_time( 'mysql' );
		$job_id = absint( $_POST['job_id'] ?? 0 );
		$title  = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );

		if ( ! $job_id || '' === $title ) {
			wp_die( esc_html__( 'A job and extra name are required.', 'electrical-booking-manager' ) );
		}

		$min_qty = absint( $_POST['min_qty'] ?? 0 );
		$max_qty = absint( $_POST['max_qty'] ?? 1 );

		if ( $max_qty < $min_qty ) {
			$max_qty = $min_qty;
		}

		$extra_duration_minutes = self::duration_to_minutes(
			wp_unslash( $_POST['extra_duration_value'] ?? 0 ),
			wp_unslash( $_POST['extra_duration_unit'] ?? 'minutes' )
		);

		$data = array(
			'job_id'                 => $job_id,
			'title'                  => $title,
			'description'            => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
			'price'                  => (float) ( $_POST['price'] ?? 0 ),
			'min_qty'                => $min_qty,
			'max_qty'                => $max_qty,
			'extra_duration_minutes' => max( 0, $extra_duration_minutes ),
			'is_active'              => isset( $_POST['is_active'] ) ? 1 : 0,
			'updated_at'             => $now,
		);

		if ( $id ) {
			$wpdb->update(
				EBM_Helpers::table( 'addons' ),
				$data,
				array( 'id' => $id ),
				array( '%d', '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$data['created_at'] = $now;

			$wpdb->insert(
				EBM_Helpers::table( 'addons' ),
				$data,
				array( '%d', '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%s', '%s' )
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-jobs&job_id=' . absint( $job_id ) . '&updated=1' ) );
		exit;
	}

	public static function delete_addon() {
		self::cap();

		$addon_id = absint( $_GET['addon_id'] ?? 0 );
		$job_id   = absint( $_GET['job_id'] ?? 0 );

		check_admin_referer( 'ebm_delete_addon_' . $addon_id );

		global $wpdb;

		$wpdb->update(
			EBM_Helpers::table( 'addons' ),
			array(
				'is_active'  => 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $addon_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-jobs&job_id=' . absint( $job_id ) . '&hidden=1' ) );
		exit;
	}

	public static function hard_delete_addon() {
		self::cap();

		$addon_id = absint( $_GET['addon_id'] ?? 0 );
		$job_id   = absint( $_GET['job_id'] ?? 0 );

		check_admin_referer( 'ebm_hard_delete_addon_' . $addon_id );

		if ( ! $addon_id ) {
			wp_die( esc_html__( 'Invalid extra.', 'electrical-booking-manager' ) );
		}

		global $wpdb;

		$wpdb->delete(
			EBM_Helpers::table( 'addons' ),
			array( 'id' => $addon_id ),
			array( '%d' )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-jobs&job_id=' . absint( $job_id ) . '&deleted=1' ) );
		exit;
	}

	public static function bookings() {
		self::cap();

		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT b.*, j.title job_title, c.name, c.email, c.phone
			FROM ' . EBM_Helpers::table( 'bookings' ) . ' b
			INNER JOIN ' . EBM_Helpers::table( 'jobs' ) . ' j ON j.id = b.job_id
			INNER JOIN ' . EBM_Helpers::table( 'customers' ) . ' c ON c.id = b.customer_id
			ORDER BY b.start_at DESC
			LIMIT 200'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bookings', 'electrical-booking-manager' ); ?></h1>

			<?php self::admin_notice(); ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Date', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Job', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Total', 'electrical-booking-manager' ); ?></th>
						<th><?php esc_html_e( 'Action', 'electrical-booking-manager' ); ?></th>
					</tr>
				</thead>

				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="7"><?php esc_html_e( 'No bookings yet.', 'electrical-booking-manager' ); ?></td>
						</tr>
					<?php endif; ?>

					<?php foreach ( $rows as $b ) : ?>
						<tr>
							<td><?php echo esc_html( $b->id ); ?></td>
							<td><?php echo esc_html( mysql2date( 'd M Y H:i', $b->start_at ) ); ?></td>
							<td>
								<?php echo esc_html( $b->name ); ?><br>
								<small><?php echo esc_html( $b->email ); ?></small>
							</td>
							<td><?php echo esc_html( $b->job_title ); ?></td>
							<td><?php echo esc_html( $b->status ); ?></td>
							<td><?php echo esc_html( EBM_Helpers::money( $b->total_amount ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="ebm_update_booking">
									<input type="hidden" name="booking_id" value="<?php echo esc_attr( $b->id ); ?>">
									<?php wp_nonce_field( 'ebm_update_booking_' . $b->id ); ?>

									<select name="status">
										<option value="confirmed" <?php selected( $b->status, 'confirmed' ); ?>><?php esc_html_e( 'Confirmed', 'electrical-booking-manager' ); ?></option>
										<option value="completed" <?php selected( $b->status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'electrical-booking-manager' ); ?></option>
										<option value="cancelled" <?php selected( $b->status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'electrical-booking-manager' ); ?></option>
									</select>

									<button class="button"><?php esc_html_e( 'Update', 'electrical-booking-manager' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function update_booking() {
		self::cap();

		$id = absint( $_POST['booking_id'] ?? 0 );

		check_admin_referer( 'ebm_update_booking_' . $id );

		$status = sanitize_key( $_POST['status'] ?? '' );

		if ( ! in_array( $status, array( 'confirmed', 'completed', 'cancelled' ), true ) ) {
			wp_die( esc_html__( 'Invalid status.', 'electrical-booking-manager' ) );
		}

		global $wpdb;

		$wpdb->update(
			EBM_Helpers::table( 'bookings' ),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( 'completed' === $status ) {
			$b = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . EBM_Helpers::table( 'bookings' ) . ' WHERE id = %d',
					$id
				)
			);

			if ( $b && (float) $b->balance_amount > 0 ) {
				$session = EBM_Stripe::checkout( $id, (float) $b->balance_amount, 'balance' );

				if ( ! is_wp_error( $session ) && ! empty( $session['url'] ) ) {
					EBM_Emails::balance( $id, $session['url'] );
				}
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-bookings&updated=1' ) );
		exit;
	}
}