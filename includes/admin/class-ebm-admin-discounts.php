<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Admin_Discounts {
	public static function init() {
		add_action( 'admin_post_ebm_save_discount', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_ebm_delete_discount', array( __CLASS__, 'delete' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_table' ) );
	}

	public static function ensure_table() {
		if ( class_exists( 'EBM_Discounts' ) ) {
			EBM_Discounts::maybe_create_table();
		}
	}

	public static function render() {
		EBM_Admin::cap();
		EBM_Admin::enqueue_admin_assets();

		self::ensure_table();

		global $wpdb;

		$table      = EBM_Discounts::table();
		$jobs_table = EBM_Helpers::table( 'jobs' );

		$discounts = $wpdb->get_results(
			"SELECT d.*, j.title AS job_title
			FROM $table d
			LEFT JOIN $jobs_table j ON j.id = d.applies_to_job_id
			ORDER BY d.is_active DESC, d.updated_at DESC, d.id DESC"
		);

		$jobs = $wpdb->get_results(
			"SELECT id, title FROM $jobs_table WHERE is_active = 1 ORDER BY title ASC"
		);

		$edit_id  = absint( $_GET['discount_id'] ?? 0 );
		$editing  = null;

		if ( $edit_id ) {
			$editing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $table WHERE id = %d",
					$edit_id
				)
			);
		}

		$is_new = ! $editing;
		?>
		<div class="wrap ebm-admin-shell">
			<h1><?php esc_html_e( 'Discounts', 'electrical-booking-manager' ); ?></h1>

			<?php EBM_Admin_Notices::render(); ?>

			<div class="ebm-admin-layout">
				<div class="ebm-panel">
					<div class="ebm-panel-header">
						<h2><?php esc_html_e( 'Voucher codes', 'electrical-booking-manager' ); ?></h2>
					</div>

					<div class="ebm-panel-body">
						<p>
							<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ebm-discounts&discount_id=0' ) ); ?>">
								<?php esc_html_e( 'Add new voucher', 'electrical-booking-manager' ); ?>
							</a>
						</p>

						<?php if ( empty( $discounts ) ) : ?>
							<div class="ebm-muted-box">
								<?php esc_html_e( 'No voucher codes have been created yet.', 'electrical-booking-manager' ); ?>
							</div>
						<?php endif; ?>

						<div class="ebm-job-list">
							<?php foreach ( $discounts as $discount ) : ?>
								<?php
								$edit_url = admin_url( 'admin.php?page=ebm-discounts&discount_id=' . absint( $discount->id ) );
								?>
								<a class="ebm-job-card <?php echo ( $editing && (int) $editing->id === (int) $discount->id ) ? 'is-selected' : ''; ?>" href="<?php echo esc_url( $edit_url ); ?>">
									<span class="ebm-job-card-title">
										<span><?php echo esc_html( $discount->code ); ?></span>
										<span class="ebm-badge <?php echo (int) $discount->is_active ? 'green' : 'grey'; ?>">
											<?php echo (int) $discount->is_active ? esc_html__( 'Active', 'electrical-booking-manager' ) : esc_html__( 'Inactive', 'electrical-booking-manager' ); ?>
										</span>
									</span>

									<span class="ebm-job-card-meta">
										<span>
											<?php
											if ( 'fixed' === $discount->type ) {
												echo esc_html( EBM_Helpers::money( $discount->amount ) );
											} else {
												echo esc_html( rtrim( rtrim( number_format( (float) $discount->amount, 2 ), '0' ), '.' ) . '%' );
											}
											?>
										</span>

										<span>
											<?php
											if ( ! empty( $discount->job_title ) ) {
												echo esc_html( $discount->job_title );
											} else {
												esc_html_e( 'All jobs', 'electrical-booking-manager' );
											}
											?>
										</span>

										<span>
											<?php
											printf(
												esc_html__( 'Used %d times', 'electrical-booking-manager' ),
												absint( $discount->used_count )
											);
											?>
										</span>
									</span>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div class="ebm-panel">
					<div class="ebm-panel-header">
						<h2><?php echo $is_new ? esc_html__( 'Add voucher code', 'electrical-booking-manager' ) : esc_html__( 'Edit voucher code', 'electrical-booking-manager' ); ?></h2>
					</div>

					<div class="ebm-panel-body">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="ebm_save_discount">
							<input type="hidden" name="id" value="<?php echo esc_attr( $editing->id ?? 0 ); ?>">
							<?php wp_nonce_field( 'ebm_save_discount' ); ?>

							<div class="ebm-form-grid">
								<div class="ebm-field">
									<label for="ebm-discount-code"><?php esc_html_e( 'Voucher code', 'electrical-booking-manager' ); ?></label>
									<input id="ebm-discount-code" type="text" name="code" required value="<?php echo esc_attr( $editing->code ?? '' ); ?>" placeholder="<?php esc_attr_e( 'SPARKY10', 'electrical-booking-manager' ); ?>">
								</div>

								<div class="ebm-field">
									<label for="ebm-discount-label"><?php esc_html_e( 'Internal label', 'electrical-booking-manager' ); ?></label>
									<input id="ebm-discount-label" type="text" name="label" value="<?php echo esc_attr( $editing->label ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Summer campaign', 'electrical-booking-manager' ); ?>">
								</div>

								<div class="ebm-field">
									<label for="ebm-discount-type"><?php esc_html_e( 'Discount type', 'electrical-booking-manager' ); ?></label>
									<select id="ebm-discount-type" name="type">
										<option value="percent" <?php selected( $editing->type ?? 'percent', 'percent' ); ?>><?php esc_html_e( 'Percentage', 'electrical-booking-manager' ); ?></option>
										<option value="fixed" <?php selected( $editing->type ?? '', 'fixed' ); ?>><?php esc_html_e( 'Fixed amount', 'electrical-booking-manager' ); ?></option>
									</select>
								</div>

								<div class="ebm-field">
									<label for="ebm-discount-amount"><?php esc_html_e( 'Amount', 'electrical-booking-manager' ); ?></label>
									<input id="ebm-discount-amount" type="number" min="0" step="0.01" name="amount" required value="<?php echo esc_attr( $editing->amount ?? '0.00' ); ?>">
								</div>

								<div class="ebm-field">
									<label for="ebm-discount-job"><?php esc_html_e( 'Applies to', 'electrical-booking-manager' ); ?></label>
									<select id="ebm-discount-job" name="applies_to_job_id">
										<option value="0"><?php esc_html_e( 'All jobs', 'electrical-booking-manager' ); ?></option>
										<?php foreach ( $jobs as $job ) : ?>
											<option value="<?php echo esc_attr( $job->id ); ?>" <?php selected( absint( $editing->applies_to_job_id ?? 0 ), absint( $job->id ) ); ?>>
												<?php echo esc_html( $job->title ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</div>

								<div class="ebm-field">
									<label for="ebm-discount-usage-limit"><?php esc_html_e( 'Usage limit', 'electrical-booking-manager' ); ?></label>
									<input id="ebm-discount-usage-limit" type="number" min="0" name="usage_limit" value="<?php echo esc_attr( $editing->usage_limit ?? '' ); ?>" placeholder="<?php esc_attr_e( 'No limit', 'electrical-booking-manager' ); ?>">
								</div>

								<div class="ebm-field">
									<label for="ebm-discount-starts"><?php esc_html_e( 'Start date', 'electrical-booking-manager' ); ?></label>
									<input id="ebm-discount-starts" type="date" name="starts_at" value="<?php echo esc_attr( ! empty( $editing->starts_at ) ? mysql2date( 'Y-m-d', $editing->starts_at, false ) : '' ); ?>">
								</div>

								<div class="ebm-field">
									<label for="ebm-discount-ends"><?php esc_html_e( 'End date', 'electrical-booking-manager' ); ?></label>
									<input id="ebm-discount-ends" type="date" name="ends_at" value="<?php echo esc_attr( ! empty( $editing->ends_at ) ? mysql2date( 'Y-m-d', $editing->ends_at, false ) : '' ); ?>">
								</div>

								<div class="ebm-field ebm-full">
									<label>
										<input type="checkbox" name="is_active" value="1" <?php checked( absint( $editing->is_active ?? 1 ), 1 ); ?>>
										<?php esc_html_e( 'Active', 'electrical-booking-manager' ); ?>
									</label>
								</div>
							</div>

							<div class="ebm-actions">
								<?php submit_button( $is_new ? __( 'Create voucher', 'electrical-booking-manager' ) : __( 'Save voucher', 'electrical-booking-manager' ), 'primary', 'submit', false ); ?>

								<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ebm-discounts&discount_id=0' ) ); ?>">
									<?php esc_html_e( 'Add another', 'electrical-booking-manager' ); ?>
								</a>

								<?php if ( ! $is_new ) : ?>
									<a class="ebm-danger-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_delete_discount&discount_id=' . absint( $editing->id ) ), 'ebm_delete_discount_' . absint( $editing->id ) ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this voucher code?', 'electrical-booking-manager' ) ); ?>');">
										<?php esc_html_e( 'Delete voucher', 'electrical-booking-manager' ); ?>
									</a>
								<?php endif; ?>
							</div>
						</form>

						<?php if ( ! $is_new ) : ?>
							<hr>
							<p>
								<?php
								printf(
									esc_html__( 'Used %d times.', 'electrical-booking-manager' ),
									absint( $editing->used_count )
								);
								?>
							</p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public static function save() {
		EBM_Admin::cap();
		check_admin_referer( 'ebm_save_discount' );

		self::ensure_table();

		global $wpdb;

		$id   = absint( $_POST['id'] ?? 0 );
		$now  = current_time( 'mysql' );
		$code = EBM_Discounts::normalise_code( $_POST['code'] ?? '' );
		$type = sanitize_key( wp_unslash( $_POST['type'] ?? 'percent' ) );

		if ( '' === $code ) {
			wp_die( esc_html__( 'Voucher code is required.', 'electrical-booking-manager' ) );
		}

		if ( ! in_array( $type, array( 'percent', 'fixed' ), true ) ) {
			$type = 'percent';
		}

		$starts_at = sanitize_text_field( wp_unslash( $_POST['starts_at'] ?? '' ) );
		$ends_at   = sanitize_text_field( wp_unslash( $_POST['ends_at'] ?? '' ) );

		$data = array(
			'code'              => $code,
			'label'             => sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) ),
			'type'              => $type,
			'amount'            => max( 0, (float) ( $_POST['amount'] ?? 0 ) ),
			'applies_to_job_id' => absint( $_POST['applies_to_job_id'] ?? 0 ) ?: null,
			'usage_limit'       => absint( $_POST['usage_limit'] ?? 0 ) ?: null,
			'starts_at'         => $starts_at ? $starts_at . ' 00:00:00' : null,
			'ends_at'           => $ends_at ? $ends_at . ' 23:59:59' : null,
			'is_active'         => isset( $_POST['is_active'] ) ? 1 : 0,
			'updated_at'        => $now,
		);

		if ( $id ) {
			$wpdb->update(
				EBM_Discounts::table(),
				$data,
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%f', '%d', '%d', '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$data['created_at'] = $now;

			$wpdb->insert(
				EBM_Discounts::table(),
				$data,
				array( '%s', '%s', '%s', '%f', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
			);

			$id = (int) $wpdb->insert_id;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-discounts&discount_id=' . absint( $id ) . '&updated=1' ) );
		exit;
	}

	public static function delete() {
		EBM_Admin::cap();

		$discount_id = absint( $_GET['discount_id'] ?? 0 );

		check_admin_referer( 'ebm_delete_discount_' . $discount_id );

		if ( ! $discount_id ) {
			wp_die( esc_html__( 'Invalid voucher.', 'electrical-booking-manager' ) );
		}

		global $wpdb;

		$wpdb->delete(
			EBM_Discounts::table(),
			array( 'id' => $discount_id ),
			array( '%d' )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-discounts&deleted=1' ) );
		exit;
	}
}