<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EBM_Admin_Jobs {
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_add_sort_order_columns' ) );

		add_action( 'admin_post_ebm_save_job', array( __CLASS__, 'save_job' ) );
		add_action( 'admin_post_ebm_delete_job', array( __CLASS__, 'hide_job' ) );
		add_action( 'admin_post_ebm_hard_delete_job', array( __CLASS__, 'delete_job' ) );
		add_action( 'admin_post_ebm_save_addon', array( __CLASS__, 'save_addon' ) );
		add_action( 'admin_post_ebm_delete_addon', array( __CLASS__, 'hide_addon' ) );
		add_action( 'admin_post_ebm_hard_delete_addon', array( __CLASS__, 'delete_addon' ) );

		add_action( 'wp_ajax_ebm_reorder_jobs', array( __CLASS__, 'reorder_jobs' ) );
		add_action( 'wp_ajax_ebm_reorder_addons', array( __CLASS__, 'reorder_addons' ) );
	}

	public static function render() {
		EBM_Admin::cap();

		global $wpdb;

		$jobs_table   = EBM_Helpers::table( 'jobs' );
		$addons_table = EBM_Helpers::table( 'addons' );

		$jobs = $wpdb->get_results( "SELECT * FROM $jobs_table ORDER BY sort_order ASC, is_active DESC, title ASC" );

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
			$addons = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $addons_table WHERE job_id = %d ORDER BY category ASC, sort_order ASC, is_active DESC, title ASC",
					$selected_job_id
				)
			);
		}

		$new_job_url = admin_url( 'admin.php?page=ebm-jobs&job_id=0' );
		?>
		<div class="wrap ebm-admin-shell">
			<h1><?php esc_html_e( 'Services', 'electrical-booking-manager' ); ?></h1>

			<?php EBM_Admin_Notices::render(); ?>

			<div class="ebm-admin-layout">
				<div class="ebm-panel">
					<div class="ebm-panel-header">
						<h2><?php esc_html_e( 'Services', 'electrical-booking-manager' ); ?></h2>
					</div>

					<div class="ebm-panel-body">
						<p>
							<a class="button button-primary" href="<?php echo esc_url( $new_job_url ); ?>">
								<?php esc_html_e( 'Add new service', 'electrical-booking-manager' ); ?>
							</a>
						</p>

						<div class="ebm-job-list ebm-sortable-services" data-ebm-sortable-services data-nonce="<?php echo esc_attr( wp_create_nonce( 'ebm_reorder_jobs' ) ); ?>">
							<?php if ( empty( $jobs ) ) : ?>
								<div class="ebm-muted-box">
									<?php esc_html_e( 'No services have been created yet.', 'electrical-booking-manager' ); ?>
								</div>
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

								<a class="ebm-job-card <?php echo $is_selected ? 'is-selected' : ''; ?>" href="<?php echo esc_url( $card_url ); ?>" data-job-id="<?php echo esc_attr( $job->id ); ?>" draggable="true">
									<span class="ebm-job-card-title">
										<span class="ebm-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'electrical-booking-manager' ); ?>">⋮⋮</span>

										<span class="ebm-job-card-name">
											<?php echo esc_html( $job->title ); ?>
										</span>

										<span class="ebm-badge <?php echo (int) $job->is_active ? 'green' : 'grey'; ?>">
											<?php echo (int) $job->is_active ? esc_html__( 'Active', 'electrical-booking-manager' ) : esc_html__( 'Hidden', 'electrical-booking-manager' ); ?>
										</span>
									</span>

									<span class="ebm-job-card-meta">
										<span><?php echo esc_html( EBM_Helpers::money( $job->price ) ); ?></span>
										<span><?php echo esc_html( EBM_Admin::format_duration( $job->duration_minutes ) ); ?></span>
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
					<?php self::job_editor( $selected_job ); ?>
					<?php self::addons_panel( $selected_job, $addons ); ?>
				</div>
			</div>

			<?php self::render_sorting_script(); ?>
		</div>
		<?php
	}

	private static function job_editor( $job ) {
		$is_new   = ! $job;
		$job_id   = $is_new ? 0 : (int) $job->id;
		$duration = EBM_Admin::split_minutes_to_best_unit( $job->duration_minutes ?? 60 );
		?>
		<div class="ebm-panel" style="margin-bottom:18px;">
			<div class="ebm-panel-header">
				<h2>
					<?php echo $is_new ? esc_html__( 'Add new service', 'electrical-booking-manager' ) : esc_html__( 'Edit service', 'electrical-booking-manager' ); ?>
				</h2>
			</div>

			<div class="ebm-panel-body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ebm_save_job">
					<input type="hidden" name="id" value="<?php echo esc_attr( $job_id ); ?>">

					<?php wp_nonce_field( 'ebm_save_job' ); ?>

					<div class="ebm-form-grid">
						<div class="ebm-field ebm-full">
							<label for="ebm-job-title"><?php esc_html_e( 'Service name', 'electrical-booking-manager' ); ?></label>
							<input id="ebm-job-title" type="text" name="title" required value="<?php echo esc_attr( $job->title ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Install new light fitting', 'electrical-booking-manager' ); ?>">
						</div>

						<div class="ebm-field ebm-full">
							<label for="ebm-job-description"><?php esc_html_e( 'Customer description', 'electrical-booking-manager' ); ?></label>
							<textarea id="ebm-job-description" name="description" rows="5"><?php echo esc_textarea( $job->description ?? '' ); ?></textarea>
						</div>

						<div class="ebm-field ebm-full">
							<label for="ebm-job-addons-intro"><?php esc_html_e( 'Extras page description', 'electrical-booking-manager' ); ?></label>
							<textarea id="ebm-job-addons-intro" name="addons_intro" rows="4" placeholder="<?php esc_attr_e( 'Tell the customer what this service includes and how to choose extras.', 'electrical-booking-manager' ); ?>"><?php echo esc_textarea( $job->addons_intro ?? '' ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'This appears under the service name on step 2 of the booking form. Leave it blank to use the default message.', 'electrical-booking-manager' ); ?>
							</p>
						</div>

						<div class="ebm-field">
							<label for="ebm-job-price"><?php esc_html_e( 'Base price', 'electrical-booking-manager' ); ?></label>
							<input id="ebm-job-price" type="number" step="0.01" min="0" name="price" value="<?php echo esc_attr( $job->price ?? '0.00' ); ?>">
						</div>

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

						<div class="ebm-field">
							<label for="ebm-job-deposit-value"><?php esc_html_e( 'Deposit value', 'electrical-booking-manager' ); ?></label>
							<input id="ebm-job-deposit-value" type="number" step="0.01" min="0" name="deposit_value" value="<?php echo esc_attr( $job->deposit_value ?? '' ); ?>">
						</div>

						<div class="ebm-field">
							<label class="ebm-checkbox-row">
								<input type="checkbox" name="allow_split_days" value="1" <?php checked( (int) ( $job->allow_split_days ?? 1 ), 1 ); ?>>
								<span><?php esc_html_e( 'Allow split days', 'electrical-booking-manager' ); ?></span>
							</label>
						</div>

						<div class="ebm-field">
							<label class="ebm-checkbox-row">
								<input type="checkbox" name="is_active" value="1" <?php checked( (int) ( $job->is_active ?? 1 ), 1 ); ?>>
								<span><?php esc_html_e( 'Active on booking form', 'electrical-booking-manager' ); ?></span>
							</label>
						</div>
					</div>

					<div class="ebm-actions">
						<?php submit_button( $is_new ? __( 'Create service', 'electrical-booking-manager' ) : __( 'Save service', 'electrical-booking-manager' ), 'primary', 'submit', false ); ?>

						<?php if ( ! $is_new ) : ?>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ebm-jobs&job_id=0' ) ); ?>">
								<?php esc_html_e( 'Add another service', 'electrical-booking-manager' ); ?>
							</a>

							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_delete_job&job_id=' . $job_id ), 'ebm_delete_job_' . $job_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Hide this service from the booking form? Existing bookings will stay safe.', 'electrical-booking-manager' ) ); ?>');">
								<?php esc_html_e( 'Hide service', 'electrical-booking-manager' ); ?>
							</a>

							<a class="ebm-danger-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_hard_delete_job&job_id=' . $job_id ), 'ebm_hard_delete_job_' . $job_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this service, its extras, and linked test bookings? This cannot be undone.', 'electrical-booking-manager' ) ); ?>');">
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
					<div class="ebm-muted-box">
						<?php esc_html_e( 'Save the service first, then you can add extras to it.', 'electrical-booking-manager' ); ?>
					</div>
				</div>
			</div>
			<?php
			return;
		}

		$job_id = (int) $job->id;
		?>
		<div class="ebm-panel">
			<div class="ebm-panel-header">
				<h2><?php esc_html_e( 'Extras for this service', 'electrical-booking-manager' ); ?></h2>
			</div>

			<div class="ebm-panel-body">
				<div class="ebm-extra-list ebm-sortable-extras" data-ebm-sortable-extras data-job-id="<?php echo esc_attr( $job_id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'ebm_reorder_addons_' . $job_id ) ); ?>">
					<?php if ( empty( $addons ) ) : ?>
						<div class="ebm-muted-box">
							<?php esc_html_e( 'No extras yet. Add the first one below.', 'electrical-booking-manager' ); ?>
						</div>
					<?php endif; ?>

					<?php foreach ( $addons as $addon ) : ?>
						<?php self::addon_form( $job_id, $addon ); ?>
					<?php endforeach; ?>
				</div>

				<div class="ebm-extra-builder">
					<h3><?php esc_html_e( 'Add new extra', 'electrical-booking-manager' ); ?></h3>
					<?php self::addon_form( $job_id, null ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	private static function addon_form( $job_id, $addon ) {
		$is_new                 = ! $addon;
		$addon_id               = $is_new ? 0 : (int) $addon->id;
		$extra_duration_minutes = $addon->extra_duration_minutes ?? 0;
		$addon_duration         = EBM_Admin::split_minutes_to_best_unit( $extra_duration_minutes );
		$form_class             = $is_new ? 'ebm-extra-card ebm-extra-card-new' : 'ebm-extra-card';
		$draggable              = $is_new ? '' : ' data-addon-id="' . esc_attr( $addon_id ) . '" draggable="true"';
		$category               = sanitize_text_field( $addon->category ?? '' );

		if ( '' === $category ) {
			$category = __( 'Other extras', 'electrical-booking-manager' );
		}
		?>
		<form class="<?php echo esc_attr( $form_class ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $draggable; ?>>
			<input type="hidden" name="action" value="ebm_save_addon">
			<input type="hidden" name="id" value="<?php echo esc_attr( $addon_id ); ?>">
			<input type="hidden" name="job_id" value="<?php echo esc_attr( $job_id ); ?>">

			<?php wp_nonce_field( 'ebm_save_addon_' . $addon_id ); ?>

			<?php if ( ! $is_new ) : ?>
				<details class="ebm-extra-details">
					<summary class="ebm-extra-summary">
						<div class="ebm-extra-summary-left">
							<span class="ebm-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'electrical-booking-manager' ); ?>">⋮⋮</span>

							<div class="ebm-extra-summary-copy">
								<strong class="ebm-extra-summary-title"><?php echo esc_html( $addon->title ); ?></strong>
								<span class="ebm-extra-summary-meta">
									<?php echo esc_html( $category ); ?>
									·
									<?php echo esc_html( EBM_Helpers::money( $addon->price ) ); ?>
									·
									<?php echo esc_html( EBM_Admin::format_duration( $addon->extra_duration_minutes ) ); ?>
									<?php esc_html_e( 'per unit', 'electrical-booking-manager' ); ?>
								</span>
							</div>
						</div>

						<div class="ebm-extra-summary-right">
							<span class="ebm-badge <?php echo (int) $addon->is_active ? 'green' : 'grey'; ?>">
								<?php echo (int) $addon->is_active ? esc_html__( 'Active', 'electrical-booking-manager' ) : esc_html__( 'Hidden', 'electrical-booking-manager' ); ?>
							</span>

							<span class="ebm-extra-toggle-indicator" aria-hidden="true">⌄</span>
						</div>
					</summary>

					<div class="ebm-extra-body">
			<?php else : ?>
				<div class="ebm-extra-body is-open">
			<?php endif; ?>

					<div class="ebm-extra-edit ebm-extra-form-grid">
						<div class="ebm-field">
							<label for="ebm-addon-title-<?php echo esc_attr( $addon_id ); ?>">
								<?php esc_html_e( 'Extra name', 'electrical-booking-manager' ); ?>
							</label>
							<input id="ebm-addon-title-<?php echo esc_attr( $addon_id ); ?>" type="text" name="title" required value="<?php echo esc_attr( $addon->title ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Smart switch upgrade', 'electrical-booking-manager' ); ?>">
						</div>

						<div class="ebm-field">
							<label for="ebm-addon-category-<?php echo esc_attr( $addon_id ); ?>">
								<?php esc_html_e( 'Category', 'electrical-booking-manager' ); ?>
							</label>
							<input id="ebm-addon-category-<?php echo esc_attr( $addon_id ); ?>" type="text" name="category" value="<?php echo esc_attr( $category ); ?>" placeholder="<?php esc_attr_e( 'Lighting', 'electrical-booking-manager' ); ?>" list="ebm-addon-category-options">

							<datalist id="ebm-addon-category-options">
								<option value="Lighting">
								<option value="Sockets">
								<option value="Cookers and ovens">
								<option value="External power">
								<option value="Smoke, alarms and safety">
								<option value="Other extras">
							</datalist>
						</div>

						<div class="ebm-field ebm-field-description">
							<label for="ebm-addon-description-<?php echo esc_attr( $addon_id ); ?>">
								<?php esc_html_e( 'Description', 'electrical-booking-manager' ); ?>
							</label>
							<textarea id="ebm-addon-description-<?php echo esc_attr( $addon_id ); ?>" name="description" rows="4"><?php echo esc_textarea( $addon->description ?? '' ); ?></textarea>
						</div>

						<div class="ebm-field">
							<label for="ebm-addon-price-<?php echo esc_attr( $addon_id ); ?>">
								<?php esc_html_e( 'Price', 'electrical-booking-manager' ); ?>
							</label>
							<input id="ebm-addon-price-<?php echo esc_attr( $addon_id ); ?>" type="number" step="0.01" min="0" name="price" value="<?php echo esc_attr( $addon->price ?? '0.00' ); ?>">
						</div>

						<div class="ebm-field">
							<label for="ebm-addon-min-<?php echo esc_attr( $addon_id ); ?>">
								<?php esc_html_e( 'Min qty', 'electrical-booking-manager' ); ?>
							</label>
							<input id="ebm-addon-min-<?php echo esc_attr( $addon_id ); ?>" type="number" min="0" name="min_qty" value="<?php echo esc_attr( $addon->min_qty ?? 0 ); ?>">
						</div>

						<div class="ebm-field">
							<label for="ebm-addon-max-<?php echo esc_attr( $addon_id ); ?>">
								<?php esc_html_e( 'Max qty', 'electrical-booking-manager' ); ?>
							</label>
							<input id="ebm-addon-max-<?php echo esc_attr( $addon_id ); ?>" type="number" min="0" name="max_qty" value="<?php echo esc_attr( $addon->max_qty ?? 1 ); ?>">
						</div>

						<div class="ebm-field ebm-field-duration">
							<label for="ebm-addon-duration-<?php echo esc_attr( $addon_id ); ?>">
								<?php esc_html_e( 'Extra duration', 'electrical-booking-manager' ); ?>
							</label>

							<div class="ebm-duration-group ebm-inline-duration">
								<input id="ebm-addon-duration-<?php echo esc_attr( $addon_id ); ?>" type="number" step="0.01" min="0" name="extra_duration_value" value="<?php echo esc_attr( $addon_duration['value'] ); ?>">
								<?php EBM_Admin::duration_unit_select( 'extra_duration_unit', $addon_duration['unit'] ); ?>
							</div>
						</div>

						<div class="ebm-field ebm-field-full">
							<label class="ebm-checkbox-row">
								<input type="checkbox" name="is_active" value="1" <?php checked( (int) ( $addon->is_active ?? 1 ), 1 ); ?>>
								<span><?php esc_html_e( 'Active on booking form', 'electrical-booking-manager' ); ?></span>
							</label>
						</div>
					</div>

					<div class="ebm-actions ebm-extra-actions">
						<?php submit_button( $is_new ? __( 'Add extra', 'electrical-booking-manager' ) : __( 'Save extra', 'electrical-booking-manager' ), $is_new ? 'primary' : 'secondary', 'submit', false ); ?>

						<?php if ( ! $is_new ) : ?>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_delete_addon&addon_id=' . $addon_id . '&job_id=' . $job_id ), 'ebm_delete_addon_' . $addon_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Hide this extra from the booking form?', 'electrical-booking-manager' ) ); ?>');">
								<?php esc_html_e( 'Hide extra', 'electrical-booking-manager' ); ?>
							</a>

							<a class="ebm-danger-link" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ebm_hard_delete_addon&addon_id=' . $addon_id . '&job_id=' . $job_id ), 'ebm_hard_delete_addon_' . $addon_id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this extra? This cannot be undone.', 'electrical-booking-manager' ) ); ?>');">
								<?php esc_html_e( 'Delete permanently', 'electrical-booking-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>

				</div>

			<?php if ( ! $is_new ) : ?>
				</details>
			<?php endif; ?>
		</form>
		<?php
	}

	public static function maybe_add_sort_order_columns() {
		self::maybe_add_sort_order_column(
			EBM_Helpers::table( 'jobs' ),
			"SELECT id FROM " . EBM_Helpers::table( 'jobs' ) . " ORDER BY title ASC"
		);

		self::maybe_add_sort_order_column(
			EBM_Helpers::table( 'addons' ),
			"SELECT id FROM " . EBM_Helpers::table( 'addons' ) . " ORDER BY job_id ASC, title ASC"
		);

		self::maybe_add_job_addons_intro_column();
		self::maybe_add_addon_category_column();
	}

	private static function maybe_add_sort_order_column( $table, $select_sql ) {
		global $wpdb;

		$column = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM $table LIKE %s",
				'sort_order'
			)
		);

		if ( $column ) {
			return;
		}

		$wpdb->query( "ALTER TABLE $table ADD sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER id" );

		$rows  = $wpdb->get_results( $select_sql );
		$order = 10;

		foreach ( (array) $rows as $row ) {
			$wpdb->update(
				$table,
				array( 'sort_order' => $order ),
				array( 'id' => absint( $row->id ) ),
				array( '%d' ),
				array( '%d' )
			);

			$order += 10;
		}
	}

	private static function maybe_add_job_addons_intro_column() {
		global $wpdb;

		$table  = EBM_Helpers::table( 'jobs' );
		$column = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM $table LIKE %s",
				'addons_intro'
			)
		);

		if ( $column ) {
			return;
		}

		$wpdb->query( "ALTER TABLE $table ADD addons_intro LONGTEXT NULL AFTER description" );
	}

	private static function maybe_add_addon_category_column() {
		global $wpdb;

		$table  = EBM_Helpers::table( 'addons' );
		$column = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM $table LIKE %s",
				'category'
			)
		);

		if ( $column ) {
			return;
		}

		$wpdb->query( "ALTER TABLE $table ADD category VARCHAR(120) NOT NULL DEFAULT 'Other extras' AFTER extra_duration_minutes" );
	}

	public static function reorder_jobs() {
		EBM_Admin::cap();

		check_ajax_referer( 'ebm_reorder_jobs', 'nonce' );

		$order = isset( $_POST['order'] ) && is_array( $_POST['order'] )
			? array_map( 'absint', wp_unslash( $_POST['order'] ) )
			: array();

		if ( empty( $order ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No services were sent to reorder.', 'electrical-booking-manager' ) ),
				400
			);
		}

		self::save_sort_order( EBM_Helpers::table( 'jobs' ), $order );

		wp_send_json_success(
			array( 'message' => __( 'Service order saved.', 'electrical-booking-manager' ) )
		);
	}

	public static function reorder_addons() {
		EBM_Admin::cap();

		$job_id = absint( $_POST['job_id'] ?? 0 );

		check_ajax_referer( 'ebm_reorder_addons_' . $job_id, 'nonce' );

		$order = isset( $_POST['order'] ) && is_array( $_POST['order'] )
			? array_map( 'absint', wp_unslash( $_POST['order'] ) )
			: array();

		if ( ! $job_id || empty( $order ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No extras were sent to reorder.', 'electrical-booking-manager' ) ),
				400
			);
		}

		global $wpdb;

		$table    = EBM_Helpers::table( 'addons' );
		$position = 10;

		foreach ( $order as $addon_id ) {
			if ( ! $addon_id ) {
				continue;
			}

			$wpdb->update(
				$table,
				array(
					'sort_order' => $position,
					'updated_at'  => current_time( 'mysql' ),
				),
				array(
					'id'     => $addon_id,
					'job_id' => $job_id,
				),
				array( '%d', '%s' ),
				array( '%d', '%d' )
			);

			$position += 10;
		}

		wp_send_json_success(
			array( 'message' => __( 'Extra order saved.', 'electrical-booking-manager' ) )
		);
	}

	private static function save_sort_order( $table, $order ) {
		global $wpdb;

		$position = 10;

		foreach ( $order as $item_id ) {
			if ( ! $item_id ) {
				continue;
			}

			$wpdb->update(
				$table,
				array(
					'sort_order' => $position,
					'updated_at'  => current_time( 'mysql' ),
				),
				array( 'id' => $item_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);

			$position += 10;
		}
	}

	private static function next_sort_order( $table, $where = '' ) {
		global $wpdb;

		$sql = "SELECT MAX(sort_order) FROM $table";

		if ( $where ) {
			$sql .= ' WHERE ' . $where;
		}

		$max = (int) $wpdb->get_var( $sql );

		return $max + 10;
	}

	private static function render_sorting_script() {
		?>
		<script>
		(function () {
			function postOrder(action, nonce, order, jobId) {
				const data = new FormData();

				data.append('action', action);
				data.append('nonce', nonce || '');

				if (jobId) {
					data.append('job_id', jobId);
				}

				order.forEach(function (id) {
					data.append('order[]', id);
				});

				return fetch(ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					body: data
				}).then(function (response) {
					return response.json();
				});
			}

			function getDragAfterElement(container, y, selector) {
				const items = Array.from(container.querySelectorAll(selector + ':not(.is-dragging)'));

				return items.reduce(function (closest, child) {
					const box = child.getBoundingClientRect();
					const offset = y - box.top - box.height / 2;

					if (offset < 0 && offset > closest.offset) {
						return {
							offset: offset,
							element: child
						};
					}

					return closest;
				}, {
					offset: Number.NEGATIVE_INFINITY,
					element: null
				}).element;
			}

			function makeSortable(config) {
				const list = document.querySelector(config.listSelector);

				if (!list) {
					return;
				}

				let dragged = null;
				let didDrag = false;

				function saveOrder() {
					const order = Array.from(list.querySelectorAll(config.itemSelector)).map(function (item) {
						return item.getAttribute(config.idAttribute);
					});

					list.classList.add('is-saving-order');
					list.classList.remove('is-order-saved', 'is-order-error');

					postOrder(
						config.action,
						list.getAttribute('data-nonce') || '',
						order,
						list.getAttribute('data-job-id') || ''
					)
						.then(function (response) {
							if (!response || !response.success) {
								throw new Error(response && response.data && response.data.message ? response.data.message : 'Order could not be saved.');
							}

							list.classList.remove('is-saving-order');
							list.classList.add('is-order-saved');

							setTimeout(function () {
								list.classList.remove('is-order-saved');
							}, 1200);
						})
						.catch(function () {
							list.classList.remove('is-saving-order');
							list.classList.add('is-order-error');

							setTimeout(function () {
								list.classList.remove('is-order-error');
							}, 1600);
						});
				}

				list.addEventListener('dragstart', function (event) {
					const item = event.target.closest(config.itemSelector);

					if (!item) {
						return;
					}

					if (
						event.target.matches('input, textarea, select, button, option') ||
						event.target.closest('input, textarea, select, button')
					) {
						event.preventDefault();
						return;
					}

					dragged = item;
					didDrag = false;

					item.classList.add('is-dragging');

					if (event.dataTransfer) {
						event.dataTransfer.effectAllowed = 'move';
						event.dataTransfer.setData('text/plain', item.getAttribute(config.idAttribute));
					}
				});

				list.addEventListener('dragover', function (event) {
					event.preventDefault();

					if (!dragged) {
						return;
					}

					didDrag = true;

					const afterElement = getDragAfterElement(list, event.clientY, config.itemSelector);

					if (afterElement == null) {
						list.appendChild(dragged);
					} else {
						list.insertBefore(dragged, afterElement);
					}
				});

				list.addEventListener('dragend', function () {
					if (!dragged) {
						return;
					}

					dragged.classList.remove('is-dragging');

					if (didDrag) {
						saveOrder();
					}

					setTimeout(function () {
						dragged = null;
						didDrag = false;
					}, 50);
				});

				list.addEventListener('click', function (event) {
					if (didDrag) {
						event.preventDefault();
						event.stopPropagation();
					}
				}, true);
			}

			makeSortable({
				listSelector: '[data-ebm-sortable-services]',
				itemSelector: '[data-job-id]',
				idAttribute: 'data-job-id',
				action: 'ebm_reorder_jobs'
			});

			makeSortable({
				listSelector: '[data-ebm-sortable-extras]',
				itemSelector: '[data-addon-id]',
				idAttribute: 'data-addon-id',
				action: 'ebm_reorder_addons'
			});
		})();
		</script>
		<?php
	}

	public static function save_job() {
		EBM_Admin::cap();
		check_admin_referer( 'ebm_save_job' );

		global $wpdb;

		$now          = current_time( 'mysql' );
		$id           = absint( $_POST['id'] ?? 0 );
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
			'addons_intro'     => sanitize_textarea_field( wp_unslash( $_POST['addons_intro'] ?? '' ) ),
			'price'            => (float) ( $_POST['price'] ?? 0 ),
			'duration_minutes' => $duration_minutes,
			'deposit_type'     => $deposit_type,
			'deposit_value'    => $deposit_value,
			'allow_split_days' => isset( $_POST['allow_split_days'] ) ? 1 : 0,
			'is_active'        => isset( $_POST['is_active'] ) ? 1 : 0,
			'updated_at'       => $now,
		);

		if ( '' === $data['title'] ) {
			wp_die( esc_html__( 'Service title is required.', 'electrical-booking-manager' ) );
		}

		if ( $id ) {
			$wpdb->update(
				EBM_Helpers::table( 'jobs' ),
				$data,
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%f', '%d', '%s', '%f', '%d', '%d', '%s' ),
				array( '%d' )
			);

			$job_id = $id;
		} else {
			$data['sort_order'] = self::next_sort_order( EBM_Helpers::table( 'jobs' ) );
			$data['created_at'] = $now;

			$wpdb->insert(
				EBM_Helpers::table( 'jobs' ),
				$data,
				array( '%s', '%s', '%s', '%f', '%d', '%s', '%f', '%d', '%d', '%s', '%d', '%s' )
			);

			$job_id = (int) $wpdb->insert_id;
		}

		delete_transient( 'ebm_frontend_jobs_' . md5( get_locale() ) );

		wp_safe_redirect( admin_url( 'admin.php?page=ebm-jobs&job_id=' . absint( $job_id ) . '&updated=1' ) );
		exit;
	}

	public static function hide_job() {
		EBM_Admin::cap();

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

	public static function delete_job() {
		EBM_Admin::cap();

		$job_id = absint( $_GET['job_id'] ?? 0 );

		check_admin_referer( 'ebm_hard_delete_job_' . $job_id );

		if ( ! $job_id ) {
			wp_die( esc_html__( 'Invalid service.', 'electrical-booking-manager' ) );
		}

		global $wpdb;

		$bookings_table     = EBM_Helpers::table( 'bookings' );
		$booking_days_table = EBM_Helpers::table( 'booking_days' );
		$transactions_table = EBM_Helpers::table( 'transactions' );

		$booking_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM $bookings_table WHERE job_id = %d",
				$job_id
			)
		);

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

		$now      = current_time( 'mysql' );
		$job_id   = absint( $_POST['job_id'] ?? 0 );
		$title    = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$category = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );

		if ( '' === $category ) {
			$category = __( 'Other extras', 'electrical-booking-manager' );
		}

		if ( ! $job_id || '' === $title ) {
			wp_die( esc_html__( 'A service and extra name are required.', 'electrical-booking-manager' ) );
		}

		$min_qty = absint( $_POST['min_qty'] ?? 0 );
		$max_qty = absint( $_POST['max_qty'] ?? 1 );

		if ( $max_qty < $min_qty ) {
			$max_qty = $min_qty;
		}

		$extra_duration_minutes = EBM_Admin::duration_to_minutes(
			wp_unslash( $_POST['extra_duration_value'] ?? 0 ),
			wp_unslash( $_POST['extra_duration_unit'] ?? 'minutes' )
		);

		$data = array(
			'job_id'                 => $job_id,
			'title'                  => $title,
			'category'               => $category,
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
				array( '%d', '%s', '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%s' ),
				array( '%d' )
			);
		} else {
			$data['sort_order'] = self::next_sort_order(
				EBM_Helpers::table( 'addons' ),
				$wpdb->prepare( 'job_id = %d', $job_id )
			);
			$data['created_at'] = $now;

			$wpdb->insert(
				EBM_Helpers::table( 'addons' ),
				$data,
				array( '%d', '%s', '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%s', '%d', '%s' )
			);
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