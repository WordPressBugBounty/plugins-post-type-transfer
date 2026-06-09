<?php
/**
 * CSV Admin UI — Export button, Import modal, and result notices.
 *
 * @package Post_Type_Transfer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PTT_CSV_Admin' ) ) {

	/**
	 * Renders the Export CSV button and an Import CSV modal dialog on every
	 * post-type list table, and shows a dismissible admin notice after import.
	 */
	class PTT_CSV_Admin {

		/**
		 * Post type captured from restrict_manage_posts; used by admin_footer
		 * to render the modal for the correct type.
		 *
		 * @var string
		 */
		private $active_post_type = '';

		/**
		 * Register hooks.
		 */
		public function __construct() {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_action( 'restrict_manage_posts', array( $this, 'render_csv_controls' ), 10, 2 );
			add_action( 'admin_footer', array( $this, 'render_import_modal' ) );
			add_action( 'admin_notices', array( $this, 'render_import_notice' ) );
		}

		/**
		 * Enqueue the modal stylesheet and script on list-table screens only.
		 *
		 * @param string $hook_suffix Current admin page hook.
		 */
		public function enqueue_assets( $hook_suffix ) {
			// Only needed on edit.php (post-type list tables).
			if ( 'edit.php' !== $hook_suffix ) {
				return;
			}

			$post_type = isset( $_GET['post_type'] ) // phpcs:ignore WordPress.Security.NonceVerification
				? sanitize_key( wp_unslash( $_GET['post_type'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
				: 'post';

			if ( $this->is_excluded_post_type( $post_type ) || ! $this->user_can( $post_type ) ) {
				return;
			}

			$base_url = plugin_dir_url( __FILE__ );

			wp_enqueue_style(
				'ptt-csv-modal',
				$base_url . 'assets/css/ptt-csv-modal.css',
				array( 'dashicons' ),
				POST_TYPE_TRANSFER_VERSION
			);

			wp_enqueue_script(
				'ptt-csv-modal',
				$base_url . 'assets/js/ptt-csv-modal.js',
				array(),
				POST_TYPE_TRANSFER_VERSION,
				true
			);

			wp_localize_script(
				'ptt-csv-modal',
				'pttCsvModal',
				array(
					'invalidFile'   => __( 'Please select a .csv file.', 'post-type-transfer' ),
					'importing'     => __( 'Importing…', 'post-type-transfer' ),
					'useFilePicker' => __( 'Drag and drop is not supported in your browser. Please use the file picker.', 'post-type-transfer' ),
				)
			);
		}

		/**
		 * Post types whose CSV import/export is handled by their own plugin
		 * (e.g. WooCommerce) and should therefore be excluded here.
		 *
		 * Developers can extend or trim the list via the filter.
		 *
		 * @param string $post_type Post type slug.
		 * @return bool
		 */
		private function is_excluded_post_type( $post_type ) {
			$excluded = apply_filters(
				'ptt_csv_excluded_post_types',
				array( 'product', 'product_variation', 'shop_order', 'shop_coupon', 'shop_webhook' )
			);
			return in_array( $post_type, (array) $excluded, true );
		}

		/**
		 * Whether the current user can edit posts of the given post type.
		 *
		 * @param string $post_type Post type slug.
		 * @return bool
		 */
		private function user_can( $post_type ) {
			$obj = get_post_type_object( $post_type );
			if ( ! $obj ) {
				return false;
			}
			return current_user_can( $obj->cap->edit_posts );
		}

		/**
		 * Render the Export button and Import trigger above the list table (top only).
		 *
		 * The Import button opens the modal rendered later by render_import_modal().
		 *
		 * @param string $post_type Current post type.
		 * @param string $which     Table position ('top' or 'bottom').
		 */
		public function render_csv_controls( $post_type, $which ) {
			if ( 'top' !== $which ) {
				return;
			}

			if ( $this->is_excluded_post_type( $post_type ) || ! $this->user_can( $post_type ) ) {
				return;
			}

			// Capture for the footer modal.
			$this->active_post_type = $post_type;

			$export_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=ptt_export_csv&post_type=' . rawurlencode( $post_type ) ),
				'ptt_export_csv_' . $post_type,
				'ptt_nonce'
			);
			?>
			<div class="ptt-csv-controls" style="display:inline-flex;align-items:center;gap:6px;margin-left:4px;vertical-align:middle;">
				<a
					href="<?php echo esc_url( $export_url ); ?>"
					class="button"
					title="<?php esc_attr_e( 'Download all posts as a CSV file', 'post-type-transfer' ); ?>"
				>
					<?php esc_html_e( 'Export CSV', 'post-type-transfer' ); ?>
				</a>
				<button
					type="button"
					class="button"
					id="ptt-import-open"
					title="<?php esc_attr_e( 'Upload a CSV file to create or update posts', 'post-type-transfer' ); ?>"
				>
					<?php esc_html_e( 'Import CSV', 'post-type-transfer' ); ?>
				</button>
			</div>
			<?php
		}

		/**
		 * Output the import modal markup in the page footer.
		 * CSS and JS are handled by enqueue_assets(); only HTML is emitted here.
		 */
		public function render_import_modal() {
			$post_type = $this->active_post_type;
			if ( ! $post_type || ! $this->user_can( $post_type ) ) {
				return;
			}

			$obj        = get_post_type_object( $post_type );
			$type_label = $obj ? $obj->labels->singular_name : $post_type;
			?>
			<div id="ptt-import-modal" role="dialog" aria-modal="true" aria-labelledby="ptt-modal-title" aria-hidden="true">
				<div id="ptt-modal-overlay" class="ptt-modal-overlay"></div>
				<div class="ptt-modal-wrap">
					<div class="ptt-modal-header">
						<h2 id="ptt-modal-title">
							<?php esc_html_e( 'Import CSV', 'post-type-transfer' ); ?>
							<span class="ptt-modal-subtitle">— <?php echo esc_html( $type_label ); ?></span>
						</h2>
						<button
							type="button"
							class="ptt-modal-x"
							aria-label="<?php esc_attr_e( 'Close', 'post-type-transfer' ); ?>"
						>
							<span aria-hidden="true">&#x2715;</span>
						</button>
					</div>
					<div class="ptt-modal-body">
						<form
							id="ptt-import-form"
							method="post"
							enctype="multipart/form-data"
							action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						>
							<?php wp_nonce_field( 'ptt_import_csv_' . $post_type, 'ptt_nonce' ); ?>
							<input type="hidden" name="action"    value="ptt_import_csv">
							<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">
							<div
								id="ptt-drop-zone"
								class="ptt-drop-zone"
								tabindex="0"
								role="button"
								aria-label="<?php esc_attr_e( 'Click or drag a CSV file here', 'post-type-transfer' ); ?>"
							>
								<input
									type="file"
									id="ptt-csv-file-input"
									name="ptt_csv_file"
									accept=".csv,text/csv"
									required
									aria-label="<?php esc_attr_e( 'Choose CSV file', 'post-type-transfer' ); ?>"
								>
								<span class="dashicons dashicons-upload ptt-drop-icon" aria-hidden="true"></span>
								<p class="ptt-drop-primary">
									<strong><?php esc_html_e( 'Click to choose a file', 'post-type-transfer' ); ?></strong>
									<?php esc_html_e( 'or drag &amp; drop', 'post-type-transfer' ); ?>
								</p>
								<p class="ptt-drop-hint"><?php esc_html_e( 'CSV files only (.csv)', 'post-type-transfer' ); ?></p>
							</div>
							<div id="ptt-file-badge" class="ptt-file-badge" hidden>
								<span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span>
								<span id="ptt-file-name"></span>
								<button
									type="button"
									id="ptt-file-clear"
									aria-label="<?php esc_attr_e( 'Remove selected file', 'post-type-transfer' ); ?>"
								>&#x2715;</button>
							</div>
							<p class="ptt-modal-hint description">
								<?php
								printf(
									/* translators: 1: post_title column  2: post_name column */
									esc_html__( 'Required columns: %1$s and %2$s. Existing posts are matched by slug and updated; unmatched rows create new posts.', 'post-type-transfer' ),
									'<code>post_title</code>',
									'<code>post_name</code>'
								);
								?>
							</p>
						</form>
					</div>
					<div class="ptt-modal-footer">
						<button type="button" class="button ptt-modal-cancel">
							<?php esc_html_e( 'Cancel', 'post-type-transfer' ); ?>
						</button>
						<button
							type="submit"
							form="ptt-import-form"
							id="ptt-import-submit"
							class="button button-primary"
							disabled
						>
							<?php esc_html_e( 'Import', 'post-type-transfer' ); ?>
						</button>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Display the import result stored in a user-scoped transient.
		 */
		public function render_import_notice() {
			$user_id = get_current_user_id();
			$result  = get_transient( 'ptt_import_result_' . $user_id );

			if ( false === $result ) {
				return;
			}

			delete_transient( 'ptt_import_result_' . $user_id );

			$has_errors = ! empty( $result['errors'] );
			$class      = $has_errors ? 'notice-warning' : 'notice-success';
			$created    = isset( $result['created'] ) ? (int) $result['created'] : 0;
			$updated    = isset( $result['updated'] ) ? (int) $result['updated'] : 0;
			$skipped    = isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
			?>
			<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: created count  2: updated count  3: skipped count */
							__( 'CSV Import complete. %1$d created, %2$d updated, %3$d skipped.', 'post-type-transfer' ),
							$created,
							$updated,
							$skipped
						)
					);
					?>
				</p>
				<?php if ( $has_errors ) : ?>
					<ul style="list-style:disc;padding-left:20px;margin-top:4px;">
						<?php foreach ( $result['errors'] as $err ) : ?>
							<li><?php echo esc_html( $err ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
			<?php
		}
	}
}
