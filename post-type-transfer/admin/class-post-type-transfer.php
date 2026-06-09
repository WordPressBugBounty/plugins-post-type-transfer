<?php
/**
 * Post type transfer class file.
 *
 * @package Post_Type_Transfer
 */

// If check class exists or not.
if ( ! class_exists( 'Post_Type_Transfer' ) ) {
	/**
	 * Post type transfer.
	 */
	class Post_Type_Transfer {
		/**
		 * Calling class construct.
		 */
		public function __construct() {
			if ( ! $this->ptt_allowed_pages() ) {
				return;
			}
			// Action & filters.
			// If check is gutenberg.
			if ( function_exists( 'register_block_type' ) ) {
				new PTT_Gutenberg_Metabox();
			} else {
				add_action( 'post_submitbox_misc_actions', array( $this, 'ptt_post_metabox' ) );
			}
			// Transfer post type.
			add_filter( 'wp_insert_post_data', array( $this, 'ptt_post_type_transfer' ), 10, 2 );
			$this->ptt_quick_edit_section();
		}

		/**
		 * Function for Quick and bulk edit.
		 *
		 * @private function for Quick and bulk edit.
		 */
		public function ptt_quick_edit_section() {
			new PTT_Quick_Edit();
		}

		/**
		 * Add post metabox.
		 */
		public static function ptt_post_metabox() {
			// Get current post type.
			$post_type = get_post_type();
			if ( ! $post_type ) {
				return;
			}
			// Get all post type objects.
			$get_all_post_types = self::ptt_get_all_post_types();
			// Get current post object.
			$capability = get_post_type_object( $post_type );
			if ( ! $capability ) {
				return;
			}

			if ( ! in_array( $capability, $get_all_post_types, true ) ) {
				$get_all_post_types[ $post_type ] = $capability;
			}
			// Get exclude post data.
			$exclude_post_data = self::ptt_exclude_post_type( $get_all_post_types );
			?>
			<div class="misc-pub-section misc-pub-section-last post-type-transfer">
			<label for="post_type_transfer_types"><?php esc_html_e( 'Post Type:', 'post-type-transfer' ); ?></label>
			<span id="post-type-display"><b><?php echo esc_html( $capability->labels->singular_name ); ?></b></span>

			<?php if ( current_user_can( $capability->cap->publish_posts ) ) : ?>
				<div id="post-type-select">
					<select name="post_type_transfer_types" id="post_type_transfer_types">
					<?php
					foreach ( $exclude_post_data as $post_type_keys => $post_type_values ) {
						if ( ! current_user_can( $post_type_values->cap->publish_posts ) ) :
							continue;
							endif;
						?>
							<option value="<?php echo esc_attr( $post_type_values->name ); ?>" <?php selected( $post_type, $post_type_keys ); ?>><?php echo esc_html( $post_type_values->labels->singular_name ); ?>
							</option>
					<?php } ?>
					</select>
				</div>
				<?php
				wp_nonce_field( 'transfer-post-type', 'ptt-post-types' );
			endif;
			?>
			<?php if ( post_type_exists( 'acf-field-group' ) ) : ?>
				<p class="ptt-acf-note">
					<?php
					printf(
						/* translators: %s: link to ACF field groups */
						esc_html__( 'Ensure the target post type supports the same ACF meta fields to avoid data loss. %s', 'post-type-transfer' ),
						'<a href="' . esc_url( admin_url( 'edit.php?post_type=acf-field-group' ) ) . '" target="_blank">' . esc_html__( 'Manage Field Groups', 'post-type-transfer' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Switch post type using wp_insert_post_date.
		 *
		 * @param array $data     The data.
		 * @param array $postarr  The postarr.
		 *
		 * @return     array  ( switch post data )
		 */
		public function ptt_post_type_transfer( $data = array(), $postarr = array() ) {
			// Check postdata.
			if ( empty( $_POST['post_type_transfer_types'] ) || empty( $_POST['ptt-post-types'] ) ) {
				return $data;
			}
			// Get post type object.
			$select_post_type = sanitize_key( $_POST['post_type_transfer_types'] );
			$get_post_object  = get_post_type_object( $select_post_type );

			// Check post object.
			if ( empty( $get_post_object ) ) {
				return $data;
			}
			// If user cannot 'edit_post'.
			if ( ! current_user_can( 'edit_post', $postarr['ID'] ) ) {
				return $data;
			}
			// If nonce is invalid.
			$nonce_raw = sanitize_text_field( wp_unslash( $_POST['ptt-post-types'] ) );
			if ( ! wp_verify_nonce( $nonce_raw, 'transfer-post-type' ) ) {
				return $data;
			}
			// If autosave.
			if ( wp_is_post_autosave( $postarr['ID'] ) ) {
				return $data;
			}
			// If revision.
			if ( wp_is_post_revision( $postarr['ID'] ) ) {
				return $data;
			}
			// If user cannot 'publish_posts' on the new type.
			if ( ! current_user_can( $get_post_object->cap->publish_posts ) ) {
				return $data;
			}
			// Return transfer post type.
			$data['post_type'] = $select_post_type;
			return $data;
		}

		/**
		 * Get all register post types.
		 *
		 * @return array<string, WP_Post_Type>
		 */
		public static function ptt_get_all_post_types() {
			return get_post_types(
				array(
					'public'  => true,
					'show_ui' => true,
				),
				// @phpstan-ignore-next-line.
				OBJECT
			);
		}

		/**
		 * Exclude post type keys.
		 *
		 * @param  array $post_types  The array.
		 *
		 * @return array ( post types array )
		 */
		public static function ptt_exclude_post_type( $post_types = array() ) {
			// If check array key exists or not.
			if ( isset( $post_types['attachment'] ) ) {
				unset( $post_types['attachment'] );
			}
			return (array) $post_types;
		}

		/**
		 * Allowed pages.
		 *
		 * @return bool
		 */
		public function ptt_allowed_pages() {
			global $pagenow;
			// Only for admin area.
			if ( ! is_admin() ) {
				return false;
			}
			// Allow pages array.
			$allow_pages = array( 'post.php', 'edit.php', 'admin-ajax.php' );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id              = isset( $_REQUEST['post'] ) ? (int) $_REQUEST['post'] : 0;
			$not_allow_post_types = apply_filters( 'ptt_exclude_post_type', array( 'acf-field-group', 'attachment' ) );
			if ( in_array( get_post_type( $post_id ), $not_allow_post_types, true ) ) {
				return false;
			}
			return (bool) in_array( $pagenow, $allow_pages, true );
		}
	}
}
