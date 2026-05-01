<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EBM_Admin_Customers {
	public static function init() {
		add_action( 'admin_post_ebm_save_customer', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_ebm_delete_customer', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_ebm_bulk_customers', array( __CLASS__, 'bulk' ) );
	}

	public static function render() {
		EBM_Admin::cap();
		global $wpdb;

		$customers_table = EBM_Helpers::table( 'customers' );
		$bookings_table  = EBM_Helpers::table( 'bookings' );

		$search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page = absint( $_GET['per_page'] ?? 20 );

		if ( ! in_array( $per_page, array( 10, 20, 50, 100 ), true ) ) {
			$per_page = 20;
		}

		$offset     = ( $paged - 1 ) * $per_page;
		$where_sql  = '1=1';
		$where_args = array();

		if ( '' !== $search ) {
			$like       = '%' . $wpdb->esc_like( $search ) . '%';
			$where_sql  = '(c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR c.address LIKE %s)';
			$where_args = array( $like, $like, $like, $like );
		}

		$total_sql = "SELECT COUNT(*) FROM $customers_table c WHERE $where_sql";
		$total     = ! empty( $where_args ) ? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $where_args ) ) : (int) $wpdb->get_var( $total_sql );

		$query_sql = "
			SELECT c.*, COUNT(b.id) AS total_bookings, MAX(b.start_at) AS recent_booking
			FROM $customers_table c
			LEFT JOIN $bookings_table b ON b.customer_id = c.id
			WHERE $where_sql
			GROUP BY c.id
			ORDER BY c.updated_at DESC, c.id DESC
			LIMIT %d OFFSET %d
		";

		$customers = $wpdb->get_results( $wpdb->prepare( $query_sql, array_merge( $where_args, array( $per_page, $offset ) ) ) );

		$edit_customer_id = absint( $_GET['customer_id'] ?? 0 );
		$editing_customer = null;

		if ( $edit_customer_id ) {
			$editing_customer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $customers_table WHERE id = %d", $edit_customer_id ) );
		}

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$base_url    = admin_url( 'admin.php?page=ebm-customers' );
		?>
		<div class="wrap ebm-admin-shell">
			<?php EBM_Admin_Notices::render(); ?>

			<div class="ebm-customers-card">
				<div class="ebm-customers-header">
					<h1><?php esc_html_e( 'Manage Customers', 'electrical-booking-manager' ); ?></h1>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ebm-customers&customer_id=0#ebm-customer-editor' ) ); ?>"><?php esc_html_e( '+ Add New', 'electrical-booking-manager' ); ?></a>
				</div>

				<form class="ebm-customers-filter" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="ebm-customers">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search customer', 'electrical-booking-manager' ); ?>">
					<a class="button" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Reset', 'electrical-booking-manager' ); ?></a>
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply', 'electrical-booking-manager' ); ?></button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ebm_bulk_customers">
					<?php wp_nonce_field( 'ebm_bulk_customers' ); ?>

					<div class="ebm-bulk-bar">
						<select name="bulk_action">
							<option value=""><?php esc_html_e( 'Bulk actions', 'electrical-booking-manager' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete selected', 'electrical-booking-manager' ); ?></option>
						</select>
						<button class="button" type="submit" onclick="return confirm('<?php echo esc_js( __( 'Apply this bulk action? Customers with bookings will be skipped.', 'electrical-booking-manager' ) ); ?>');"><?php esc_html_e( 'Apply', 'electrical-booking-manager' ); ?></button>
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
								<tr><td colspan="6"><?php esc_html_e( 'No customers found.', 'electrical-booking-manager' ); ?></td></tr>
							<?php endif; ?>

							<?php foreach ( $customers as $customer ) : ?>
								<?php
								$initial    = strtoupper( mb_substr( $customer->name, 0, 1 ) );
								$edit_url   = admin_url( 'admin.php?page=ebm-customers&customer_id=' . absint( $customer->id ) . '#ebm-customer-editor' );
								$delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=ebm_delete_customer&customer_id=' . absint( $customer->id ) ), 'ebm_delete_customer_' . absint( $customer->id ) );
								?>
								<tr>
									<td><input type="checkbox" class="ebm-customer-check" name="customer_ids[]" value="<?php echo esc_attr( $customer->id ); ?>"></td>
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
									<td><?php echo ! empty( $customer->recent_booking ) ? esc_html( mysql2date( 'F j, Y g:i a', $customer->recent_booking ) ) : esc_html__( 'None', 'electrical-booking-manager' ); ?></td>
									<td><?php echo esc_html( absint( $customer->total_bookings ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</form>

				<div class="ebm-pagination">
					<div class="ebm-pagination-left">
						<span><?php printf( esc_html__( 'Showing %1$d out of %2$d', 'electrical-booking-manager' ), count( $customers ), $total ); ?></span>
						<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
							<input type="hidden" name="page" value="ebm-customers">
							<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>">
							<label><?php esc_html_e( 'Per Page', 'electrical-booking-manager' ); ?>
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

			<?php self::editor( $editing_customer ); ?>
		</div>
		<?php
	}

	private static function editor( $customer ) {
		$is_new        = ! $customer;
		$show_new_form = isset( $_GET['customer_id'] ) && '0' === (string) $_GET['customer_id'];

		if ( $is_new && ! $show_new_form ) {
			return;
		}

		$customer_id = $is_new ? 0 : (int) $customer->id;
		?>
		<div id="ebm-customer-editor" class="ebm-panel ebm-customer-editor">
			<div class="ebm-panel-header"><h2><?php echo $is_new ? esc_html__( 'Add customer', 'electrical-booking-manager' ) : esc_html__( 'Edit customer', 'electrical-booking-manager' ); ?></h2></div>
			<div class="ebm-panel-body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ebm_save_customer">
					<input type="hidden" name="id" value="<?php echo esc_attr( $customer_id ); ?>">
					<?php wp_nonce_field( 'ebm_save_customer' ); ?>
					<div class="ebm-form-grid">
						<div class="ebm-field"><label for="ebm-customer-name"><?php esc_html_e( 'Full name', 'electrical-booking-manager' ); ?></label><input id="ebm-customer-name" type="text" name="name" required value="<?php echo esc_attr( $customer->name ?? '' ); ?>"></div>
						<div class="ebm-field"><label for="ebm-customer-email"><?php esc_html_e( 'Email', 'electrical-booking-manager' ); ?></label><input id="ebm-customer-email" type="email" name="email" required value="<?php echo esc_attr( $customer->email ?? '' ); ?>"></div>
						<div class="ebm-field"><label for="ebm-customer-phone"><?php esc_html_e( 'Phone', 'electrical-booking-manager' ); ?></label><input id="ebm-customer-phone" type="tel" name="phone" value="<?php echo esc_attr( $customer->phone ?? '' ); ?>"></div>
						<div class="ebm-field ebm-full"><label for="ebm-customer-address"><?php esc_html_e( 'Service address', 'electrical-booking-manager' ); ?></label><textarea id="ebm-customer-address" name="address" rows="4"><?php echo esc_textarea( $customer->address ?? '' ); ?></textarea></div>
					</div>
					<div class="ebm-actions">
						<?php submit_button( $is_new ? __( 'Create customer', 'electrical-booking-manager' ) : __( 'Save customer', 'electrical-booking-manager' ), 'primary', 'submit', false ); ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ebm-customers' ) ); ?>"><?php esc_html_e( 'Cancel', 'electrical-booking-manager' ); ?></a>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	public static function save() {
		EBM_Admin::cap();
		check_admin_referer( 'ebm_save_customer' );

		global $wpdb;

		$id    = absint( $_POST['id'] ?? 0 );
		$now   = current_time( 'mysql' );
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
			$wpdb->update( EBM_Helpers::table( 'customers' ), $data, array( 'id' => $id ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
			$customer_id = $id;
		} else {
			$data['privacy_accepted_at'] = null;
			$data['created_at']          = $now;
			$wpdb->insert( EBM_Helpers::table( 'customers' ), $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
			$customer_id = (int) $wpdb->insert_id;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-customers&customer_id=' . absint( $customer_id ) . '&updated=1#ebm-customer-editor' ) );
		exit;
	}

	public static function delete() {
		EBM_Admin::cap();
		$customer_id = absint( $_GET['customer_id'] ?? 0 );
		check_admin_referer( 'ebm_delete_customer_' . $customer_id );

		if ( ! $customer_id ) {
			wp_die( esc_html__( 'Invalid customer.', 'electrical-booking-manager' ) );
		}

		global $wpdb;
		$bookings_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . EBM_Helpers::table( 'bookings' ) . ' WHERE customer_id = %d', $customer_id ) );

		if ( $bookings_count > 0 ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ebm-customers&not_deleted=1' ) );
			exit;
		}

		$wpdb->delete( EBM_Helpers::table( 'customers' ), array( 'id' => $customer_id ), array( '%d' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-customers&deleted=1' ) );
		exit;
	}

	public static function bulk() {
		EBM_Admin::cap();
		check_admin_referer( 'ebm_bulk_customers' );

		if ( 'delete' !== sanitize_key( wp_unslash( $_POST['bulk_action'] ?? '' ) ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ebm-customers' ) );
			exit;
		}

		$customer_ids = isset( $_POST['customer_ids'] ) && is_array( $_POST['customer_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['customer_ids'] ) ) : array();
		$customer_ids = array_filter( array_unique( $customer_ids ) );

		if ( empty( $customer_ids ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=ebm-customers' ) );
			exit;
		}

		global $wpdb;

		$deleted = 0;
		$skipped = 0;

		foreach ( $customer_ids as $customer_id ) {
			$bookings_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . EBM_Helpers::table( 'bookings' ) . ' WHERE customer_id = %d', $customer_id ) );

			if ( $bookings_count > 0 ) {
				$skipped++;
				continue;
			}

			$result = $wpdb->delete( EBM_Helpers::table( 'customers' ), array( 'id' => $customer_id ), array( '%d' ) );

			if ( false !== $result ) {
				$deleted++;
			}
		}

		$args = array( 'page' => 'ebm-customers' );
		if ( $deleted > 0 ) {
			$args['bulk_deleted'] = 1;
		}
		if ( $skipped > 0 ) {
			$args['bulk_skipped'] = 1;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
