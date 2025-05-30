<?php
/**
 * Post type transfer quick edit class file.
 *
 * @package WordPress
 */

// If check class exists or not.
if ( ! class_exists( 'PTT_Quick_Edit' ) ) {
	/**
	 * Post type transfer quick edit class.
	 */
	class PTT_Quick_Edit extends Post_Type_Transfer {

		/**
		 * Calling class construct.
		 */
		public function __construct() {
			// Add column for quick-edit support.
			add_action( 'manage_posts_columns', array( $this, 'ptt_add_column' ) );
			add_action( 'manage_pages_columns', array( $this, 'ptt_add_column' ) );
			add_action( 'manage_posts_custom_column', array( $this, 'ptt_manage_column' ), 10, 2 );
			add_action( 'manage_pages_custom_column', array( $this, 'ptt_manage_column' ), 10, 2 );
			// Quick Edit.
			add_action( 'quick_edit_custom_box', array( $this, 'ptt_quick_edit' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'ptt_quick_edit_script' ) );
		}

		/**
		 * Adds quickedit button for bulk-editing post types.
		 *
		 * @param string $column_name Name of the column to edit.
		 */
		public function ptt_quick_edit( $column_name = '' ) {

			// Prevent multiple dropdowns in each column.
			if ( 'post_type' !== $column_name ) {
				return;
			}
			?>
			<fieldset class="inline-edit-col-ptt">
				<div id="pts_quick_edit" class="inline-edit-group wp-clearfix">
					<label class="alignleft">
						<span class="title"><?php esc_html_e( 'Post Type', 'post-type-transfer' ); ?></span>
						<?php
							wp_nonce_field( 'transfer-post-type', 'ptt-post-types' );
							$this->ptt_select_box();
						?>
					</label>
				</div>
			</fieldset>
			<?php
		}

		/**
		 * Adds the post type column.
		 *
		 * @param array $columns Existing columns.
		 */
		public function ptt_add_column( $columns ) {
			return array_merge( $columns, array( 'post_type' => esc_html__( 'Type', 'post-type-transfer' ) ) );
		}

		/**
		 * Manages the post type column.
		 *
		 * @param string $column The name of the column to display.
		 * @param int    $post_id The current post ID.
		 */
		public function ptt_manage_column( $column, $post_id ) {
			switch ( $column ) {
				case 'post_type':
					$post_type = get_post_type_object( get_post_type( $post_id ) );
					?>
				<span data-post-type="<?php echo esc_attr( $post_type->name ); ?>"><?php echo esc_html( $post_type->labels->singular_name ); ?></span>
					<?php
					break;
			}
		}

		/**
		 * Adds quickedit script for getting values into quickedit box.
		 *
		 * @param string $hook The current admin page.
		 */
		public function ptt_quick_edit_script( $hook = '' ) {
			// if not edit.php admin page.
			if ( 'edit.php' !== $hook ) {
				return;
			}
			// Enqueue quick edit JS.
			wp_enqueue_script( 'ptt-quick-edit', plugin_dir_url( __FILE__ ) . 'assets/js/post-type-transfer-quickedit.js', array( 'jquery' ), POST_TYPE_TRANSFER_VERSION, true );
			wp_enqueue_style( 'ptt-quick-edit', plugin_dir_url( __FILE__ ) . 'assets/css/post-type-transfer-quickedit.css', array(), POST_TYPE_TRANSFER_VERSION );
		}

		/**
		 * Output a post-type dropdown.
		 *
		 * @param boolean $bulk Check for bulk.
		 */
		public function ptt_select_box( $bulk = false ) {
			$selected = '';
			// Get current post type.
			$post_type = get_post_type();
			// Get all post type objects.
			$get_all_post_types = $this->ptt_get_all_post_types();
			// Exclude post data.
			// @phpstan-ignore-next-line.
			$exclude_post_data = $this->ptt_exclude_post_type( $get_all_post_types );
			// Start an output buffer.
			// Output.
			ob_start();
			?>
			<select name="post_type_transfer_types" id="post_type_transfer_types">
				<?php
				// Maybe include "No Change" option for bulk.
				if ( true === $bulk ) :
					?>
					<option value="-1"><?php esc_html_e( '&mdash; No Change &mdash;', 'post-type-transfer' ); ?></option>
					<?php
				endif;

				// Loop through post types.
				foreach ( $exclude_post_data as $post_type_key => $post_types ) :
					// Skip if user cannot publish this type of post.
					if ( ! current_user_can( $post_types->cap->publish_posts ) ) :
						continue;
					endif;
					// Only select if not bulk.
					if ( false === $bulk ) :
						// @phpstan-ignore-next-line.
						$selected = selected( $post_type, $post_type_key );
					endif;
					// Output option.
					?>
					<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<option value="<?php echo esc_attr( $post_types->name ); ?>" <?php echo $selected; // Do not escape. ?>>	<?php echo esc_html( $post_types->labels->singular_name ); ?>
					</option>
					<?php
						endforeach;
				?>
				</select>
				<?php
				$allowed_html = array(
					'select' => array(
						'name' => true,
						'id'   => true,
					),
					'option' => array(
						'value'    => true,
						'selected' => true,
					),
				);
				// Output the current buffer.
				echo wp_kses( ob_get_clean(), $allowed_html );
		}
	}
}
