<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class EBM_Admin_Jobs {
	public static function init() {
		add_action( 'admin_post_ebm_save_job', array( __CLASS__, 'save_job' ) );
		add_action( 'admin_post_ebm_delete_job', array( __CLASS__, 'hide_job' ) );
		add_action( 'admin_post_ebm_hard_delete_job', array( __CLASS__, 'delete_job' ) );
		add_action( 'admin_post_ebm_save_addon', array( __CLASS__, 'save_addon' ) );
		add_action( 'admin_post_ebm_delete_addon', array( __CLASS__, 'hide_addon' ) );
		add_action( 'admin_post_ebm_hard_delete_addon', array( __CLASS__, 'delete_addon' ) );
	}

	public static function render() {
		EBM_Admin::cap();
		global $wpdb;

		$jobs_table   = EBM_Helpers::table( 'jobs' );
		$addons_table = EBM_Helpers::table( 'addons' );

		$jobs = $wpdb->get_results( "SELECT * FROM $jobs_table ORDER BY is_active DESC, title ASC" );

		$is_new_request  = isset( $_GET['job_id'] ) && '0' === (string) $_GET['job_id'];
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
			$addons = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $addons_table WHERE job_id = %d ORDER BY is_active DESC, title ASC", $selected_job_id ) );
		}

		$new_job_url = admin_url( 'admin.php?page=ebm-jobs&job_id=0' );
		?>
		<div class="wrap ebm-admin-shell">
			<h1><?php esc_html_e( 'Jobs & Extras', 'electrical-booking-manager' ); ?></h1>
			<?php EBM_Admin_Notices::render(); ?>

			<div class="ebm-admin-layout">
				<div class="ebm-panel">
					<div class="ebm-panel-header"><h2><?php esc_html_e( 'Services', 'electrical-booking-manager' ); ?></h2></div>
					<div class="ebm-panel-body">
						<p><a class="button button-primary" href="<?php echo esc_url( $new_job_url ); ?>"><?php esc_html_e( 'Add new job', 'electrical-booking-manager' ); ?></a></p>
						<div class="ebm-job-list">
							<?php if ( empty( $jobs ) ) : ?>
								<div class="ebm-muted-box"><?php esc_html_e( 'No jobs have been created yet.', 'electrical-booking-manager' ); ?></div>
							<?php endif; ?>

							<?php foreach ( $jobs as $job ) : ?>
								<?php
								$card_url    = admin_url( 'admin.php?page=ebm-jobs&job_id=' . absint( $job->id ) );
								$is_selected = ! $is_new_request && ( (int) $job->id === $selected_job_id );
								$addon_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $addons_table WHERE job_id = %d AND is_active = 1", (int) $job->id ) );
								?>
								<a class="ebm-job-card <?php echo $is_selected ? 'is-selected' : ''; ?>" href="<?php echo esc_url( $card_url ); ?>">
									<span class="ebm-job-card-title">
										<span><?php echo esc_html( $job->title ); ?></span>
										<span class="ebm-badge <?php echo (int) $job->is_active ? 'green' : 'grey'; ?>"><?php echo (int) $job->is_active ? esc_html__( 'Active', 'electrical-booking-manager' ) : esc_html__( 'Hidden', 'electrical-booking-manager' ); ?></span>
									</span>
									<span class="ebm-job-card-meta">
										<span><?php echo esc_html( EBM_Helpers::money( $job->price ) ); ?></span>
										<span><?php echo esc_html( EBM_Admin::format_duration( $job->duration_minutes ) ); ?></span>
										<span><?php echo esc_html( sprintf( _n( '%d extra', '%d extras', $addon_count, 'electrical-booking-manager' ), $addon_count ) ); ?></span>
									</span>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div>
					<?php self::job_editor( $selected_job ); ?>
					<?php self::addons_panel( $selected_job, $addons ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	private static function job_editor( $job ) {
		$is_new  = ! $job;
		$job_id  = $is_new ? 0 : (int) $job->id;
		$duration = EBM_Admin::split_minutes_to_best_unit( $job->duration_minutes ?? 60 );
		?>
		<div class="ebm-panel" style="margin-bottom:18px;">
			<div class="ebm-panel-header"><h2><?php echo $is_new ? esc_html__( 'Add new job', 'electrical-booking-manager' ) : esc_html__( 'Edit job', 'electrical-booking-manager' ); ?></h2></div>
			<div class="ebm-panel-body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ebm_save_job">
					<input type="hidden" name="id" value="<?php echo esc_attr( $job_id ); ?>">
					<?php wp_nonce_field( 'ebm_save_job' ); ?>

					<div class="ebm-form-grid">
						<div class="ebm-field ebm-full"><label for="ebm-job-title"><?php esc_html_e( 'Job name', 'electrical-booking-manager' ); ?></label><input id="ebm-job-title" type="text" name="title" required value="<?php echo esc_attr( $job->title ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Install new light fitting', 'electrical-booking-manager' ); ?>"></div>
						<div class="ebm-field ebm-full"><label for="ebm-job-description"><?php esc_html_e( 'Customer description', 'electrical-booking-manager' ); ?></label><textarea id="ebm-job-description" name="description" rows="5"><?php echo esc_textarea( $job->description ?? '' ); ?></textarea></div>
						<div class="ebm-field"><label for="ebm-job-price"><?php esc_html_e( 'Base price', 'electrical-booking-manager' ); ?></label><input id="ebm-job-price" type="number" step="0.01" min="0" name="price" value="<?php echo esc_attr( $job->price ?? '0.00' ); ?>"></div>
						<div class="ebm-field">
							<label for="ebm-job-duration-value"><?php esc_html_e( 'Duration', 'electrical-booking-manager' ); ?></label>
							<div class="ebm-inline-duration">
								<input id="ebm-job-duration-value" type="number" step="0.01" min="0" name="duration_value" value="<?php echo esc_attr( $duration['value'] ); ?>">
								<?php EBM_Admin::duration_unit_select( 'duration_unit', $duration['unit'] ); ?>
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
						<div class="ebm-field"><label for="ebm-job-deposit-value"><?php esc_html_e( 'Deposit value', 'electrical-booking-manager' ); ?></label><input id="ebm-job-deposit-value" type="number" step="0.01" min="0" name="deposit_value" value="<?php echo esc_attr( $job->deposit_value ?? '' ); ?>"></div>
						<div class="ebm-field"><label><input type="checkbox" name="allow_split_days" value="1" <?php checked( (int) ( $job->allow_split_days ?? 1 ), 1 ); ?>> <?php esc_html_e( 'Allow split days', 'electrical-booking-manager' ); ?></label></div>
						<div class="ebm-field"><label><input type="checkbox" name="is_active" value="1" <?php checked( (int) ( $job->is_active ?? 1 ), 1 ); ?>> <?php esc_html_e( 'Active on booking form', 'electrical-booking-manager' ); ?></label></div>
					</div>

					<div class="ebm-actions">
						<?php submit_button( $is_new ? __( 'Create job', 'electrical-booking-manager' ) : __( 'Save job', 'electrical-booking-manager' ), 'primary', 'submit', false ); ?>
						<?php if ( ! $is_new ) : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ebm-jobs&job_id=0' ) ); ?>"><?php esc_html_e( 'Add another job', 'electrical-booking-manager' ); ?></a>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_delete_job&job_id=' . $job_id ), 'ebm_delete_job_' . $job_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Hide this job from the booking form? Existing bookings will stay safe.', 'electrical-booking-manager' ) ); ?>');"><?php esc_html_e( 'Hide job', 'electrical-booking-manager' ); ?></a>
							<a class="ebm-danger-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_hard_delete_job&job_id=' . $job_id ), 'ebm_hard_delete_job_' . $job_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this job, its extras, and linked test bookings? This cannot be undone.', 'electrical-booking-manager' ) ); ?>');"><?php esc_html_e( 'Delete permanently', 'electrical-booking-manager' ); ?></a>
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
				<div class="ebm-panel-header"><h2><?php esc_html_e( 'Extras', 'electrical-booking-manager' ); ?></h2></div>
				<div class="ebm-panel-body"><div class="ebm-muted-box"><?php esc_html_e( 'Save the job first, then you can add extras to it.', 'electrical-booking-manager' ); ?></div></div>
			</div>
			<?php
			return;
		}

		$job_id = (int) $job->id;
		?>
		<div class="ebm-panel">
			<div class="ebm-panel-header"><h2><?php esc_html_e( 'Extras for this job', 'electrical-booking-manager' ); ?></h2></div>
			<div class="ebm-panel-body">
				<div class="ebm-extra-list">
					<?php if ( empty( $addons ) ) : ?>
						<div class="ebm-muted-box"><?php esc_html_e( 'No extras yet. Add the first one below.', 'electrical-booking-manager' ); ?></div>
					<?php endif; ?>

					<?php foreach ( $addons as $addon ) : ?>
						<?php self::addon_form( $job_id, $addon ); ?>
					<?php endforeach; ?>
				</div>

				<hr>
				<h3><?php esc_html_e( 'Add new extra', 'electrical-booking-manager' ); ?></h3>
				<?php self::addon_form( $job_id, null ); ?>
			</div>
		</div>
		<?php
	}

	private static function addon_form( $job_id, $addon ) {
		$is_new = ! $addon;
		$addon_id = $is_new ? 0 : (int) $addon->id;
		$addon_duration = EBM_Admin::split_minutes_to_best_unit( $addon->extra_duration_minutes ?? 0 );
		?>
		<form class="ebm-extra-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ebm_save_addon">
			<input type="hidden" name="id" value="<?php echo esc_attr( $addon_id ); ?>">
			<input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>">
			<?php wp_nonce_field( 'ebm_save_addon_' . $addon_id ); ?>

			<?php if ( ! $is_new ) : ?>
				<div class="ebm-extra-top">
					<div>
						<strong><?php echo esc_html( $addon->title ); ?></strong>
						<div class="ebm-extra-meta"><?php echo esc_html( EBM_Helpers::money( $addon->price ) ); ?> · <?php echo esc_html( EBM_Admin::format_duration( $addon->extra_duration_minutes ) ); ?> <?php esc_html_e( 'per unit', 'electrical-booking-manager' ); ?></div>
					</div>
					<span class="ebm-badge <?php echo (int) $addon->is_active ? 'green' : 'grey'; ?>"><?php echo (int) $addon->is_active ? esc_html__( 'Active', 'electrical-booking-manager' ) : esc_html__( 'Hidden', 'electrical-booking-manager' ); ?></span>
				</div>
			<?php endif; ?>

			<div class="ebm-extra-edit">
				<div class="wide"><label><?php esc_html_e( 'Extra name', 'electrical-booking-manager' ); ?><input type="text" name="title" required value="<?php echo esc_attr( $addon->title ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Smart switch upgrade', 'electrical-booking-manager' ); ?>"></label></div>
				<div class="wide"><label><?php esc_html_e( 'Description', 'electrical-booking-manager' ); ?><textarea name="description" rows="3"><?php echo esc_textarea( $addon->description ?? '' ); ?></textarea></label></div>
				<div><label><?php esc_html_e( 'Price', 'electrical-booking-manager' ); ?><input type="number" step="0.01" min="0" name="price" value="<?php echo esc_attr( $addon->price ?? '0.00' ); ?>"></label></div>
				<div><label><?php esc_html_e( 'Min qty', 'electrical-booking-manager' ); ?><input type="number" min="0" name="min_qty" value="<?php echo esc_attr( $addon->min_qty ?? 0 ); ?>"></label></div>
				<div><label><?php esc_html_e( 'Max qty', 'electrical-booking-manager' ); ?><input type="number" min="0" name="max_qty" value="<?php echo esc_attr( $addon->max_qty ?? 1 ); ?>"></label></div>
				<div>
					<label><?php esc_html_e( 'Extra duration', 'electrical-booking-manager' ); ?>
						<div class="ebm-inline-duration">
							<input type="number" step="0.01" min="0" name="extra_duration_value" value="<?php echo esc_attr( $addon_duration['value'] ); ?>">
							<?php EBM_Admin::duration_unit_select( 'extra_duration_unit', $addon_duration['unit'] ); ?>
						</div>
					</label>
				</div>
				<div class="wide"><label><input type="checkbox" name="is_active" value="1" <?php checked( (int) ( $addon->is_active ?? 1 ), 1 ); ?>> <?php esc_html_e( 'Active on booking form', 'electrical-booking-manager' ); ?></label></div>
			</div>

			<div class="ebm-actions">
				<?php submit_button( $is_new ? __( 'Add extra', 'electrical-booking-manager' ) : __( 'Save extra', 'electrical-booking-manager' ), $is_new ? 'primary' : 'secondary', 'submit', false ); ?>
				<?php if ( ! $is_new ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_delete_addon&addon_id=' . $addon_id . '&job_id=' . $job_id ), 'ebm_delete_addon_' . $addon_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Hide this extra from the booking form?', 'electrical-booking-manager' ) ); ?>');"><?php esc_html_e( 'Hide extra', 'electrical-booking-manager' ); ?></a>
					<a class="ebm-danger-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_hard_delete_addon&addon_id=' . $addon_id . '&job_id=' . $job_id ), 'ebm_hard_delete_addon_' . $addon_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this extra? This cannot be undone.', 'electrical-booking-manager' ) ); ?>');"><?php esc_html_e( 'Delete permanently', 'electrical-booking-manager' ); ?></a>
				<?php endif; ?>
			</div>
		</form>
		<?php
	}

	public static function save_job() {
		EBM_Admin::cap();
		check_admin_referer( 'ebm_save_job' );

		global $wpdb;
		$now = current_time( 'mysql' );
		$id  = absint( $_POST['id'] ?? 0 );
		$deposit_type = sanitize_key( wp_unslash( $_POST['deposit_type'] ?? 'global' ) );

		if ( ! in_array( $deposit_type, array( 'global', 'percent', 'fixed' ), true ) ) {
			$deposit_type = 'global';
		}

		$duration_minutes = EBM_Admin::duration_to_minutes( wp_unslash( $_POST['duration_value'] ?? 60 ), wp_unslash( $_POST['duration_unit'] ?? 'minutes' ) );
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
			$wpdb->update( EBM_Helpers::table( 'jobs' ), $data, array( 'id' => $id ), array( '%s', '%s', '%f', '%d', '%s', '%f', '%d', '%d', '%s' ), array( '%d' ) );
			$job_id = $id;
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( EBM_Helpers::table( 'jobs' ), $data, array( '%s', '%s', '%f', '%d', '%s', '%f', '%d', '%d', '%s', '%s' ) );
			$job_id = (int) $wpdb->insert_id;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-jobs&job_id=' . absint( $job_id ) . '&updated=1' ) );
		exit;
	}

	public static function hide_job() {
		EBM_Admin::cap();
		$job_id = absint( $_GET['job_id'] ?? 0 );
		check_admin_referer( 'ebm_delete_job_' . $job_id );
		global $wpdb;
		$wpdb->update( EBM_Helpers::table( 'jobs' ), array( 'is_active' => 0, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $job_id ), array( '%d', '%s' ), array( '%d' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=ebm-jobs&job_id=' . absint( $job_id ) . '&hidden=1' ) );
		exit;
	}

	public static function delete_job() {
		EBM_Admin::cap();
		$job_id = absint( $_GET['job_id'] ?? 0 );
		check_admin_referer( 'ebm_hard_delete_job_' . $job_id );

		if ( ! $job_id ) {
			wp_die( esc_html__( 'Invalid job.', 'electrical-booking-manager' ) );
		}

		global $wpdb;
		$bookings_table     = EBM_Helpers::table( 'bookings' );
		$booking_days_table = EBM_Helpers::table( 'booking_days' );
		$transactions_table = EBM_Helpers::table( 'transactions' );

		$booking_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $bookings_table WHERE job_id = %d", $job_id ) );

		if ( ! empty( $booking_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $booking_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $booking_days_table WHERE booking_id IN ($placeholders)", $booking_ids ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $transactions_table WHERE booking_id IN ($placeholders)", $booking_ids ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $bookings_table WHERE id IN ($placeholders)", $booking_ids ) );
		}

		$wpdb->delete( EBM_Helpers::table( 'addons' ), array( 'job_id' => $job_id ), array( '%d' ) );
		$wpdb->delete( EBM_Helpers::table( 'jobs' ), array( 'id' => $job_id ), array( '%d' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-jobs&deleted=1' ) );
		exit;
	}

	public static function save_addon() {
		EBM_Admin::cap();
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

		$extra_duration_minutes = EBM_Admin::duration_to_minutes( wp_unslash( $_POST['extra_duration_value'] ?? 0 ), wp_unslash( $_POST['extra_duration_unit'] ?? 'minutes' ) );

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
			$wpdb->update( EBM_Helpers::table( 'addons' ), $data, array( 'id' => $id ), array( '%d', '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%s' ), array( '%d' ) );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( EBM_Helpers::table( 'addons' ), $data, array( '%d', '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%s', '%s' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-jobs&job_id=' . absint( $job_id ) . '&updated=1' ) );
		exit;
	}

	public static function hide_addon() {
		EBM_Admin::cap();
		$addon_id = absint( $_GET['addon_id'] ?? 0 );
		$job_id   = absint( $_GET['job_id'] ?? 0 );
		check_admin_referer( 'ebm_delete_addon_' . $addon_id );
		global $wpdb;
		$wpdb->update( EBM_Helpers::table( 'addons' ), array( 'is_active' => 0, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $addon_id ), array( '%d', '%s' ), array( '%d' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=ebm-jobs&job_id=' . absint( $job_id ) . '&hidden=1' ) );
		exit;
	}

	public static function delete_addon() {
		EBM_Admin::cap();
		$addon_id = absint( $_GET['addon_id'] ?? 0 );
		$job_id   = absint( $_GET['job_id'] ?? 0 );
		check_admin_referer( 'ebm_hard_delete_addon_' . $addon_id );

		if ( ! $addon_id ) {
			wp_die( esc_html__( 'Invalid extra.', 'electrical-booking-manager' ) );
		}

		global $wpdb;
		$wpdb->delete( EBM_Helpers::table( 'addons' ), array( 'id' => $addon_id ), array( '%d' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=ebm-jobs&job_id=' . absint( $job_id ) . '&deleted=1' ) );
		exit;
	}
}
