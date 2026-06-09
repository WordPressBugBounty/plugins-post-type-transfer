<?php
/**
 * Post Visibility class file.
 *
 * @package Post_Type_Transfer
 */

if ( ! class_exists( 'PTT_Post_Visibility' ) ) {
	/**
	 * Handles per-post visibility restrictions.
	 */
	class PTT_Post_Visibility {

		const META_PREFIX = '_ptt_hide_';

		const OPTIONS = array(
			'redirect_404'   => 'Redirect 404 page',
			'front_page'     => 'Hide from Front Page',
			'category_pages' => 'Hide from Category Pages',
			'tag_pages'      => 'Hide from Tag Pages',
			'author_pages'   => 'Hide from Author Pages',
			'date_archive'   => 'Hide from Date Archive Pages',
			'search_pages'   => 'Hide from Search Pages',
			'feeds'          => 'Hide from Feeds',
			'recent_posts'   => 'Hide from Recent Post',
			'adjacent_links' => 'Hide from Next and previous rel link',
			'rest_api'       => 'Hide from REST API',
		);

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ), 10, 1 );
			add_action( 'save_post', array( $this, 'save_meta' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

			// Frontend visibility hooks.
			add_action( 'parse_query', array( $this, 'fix_queried_object' ) );
			add_action( 'template_redirect', array( $this, 'handle_redirect_404' ) );
			add_action( 'pre_get_posts', array( $this, 'filter_queries' ), 99 );
			add_filter( 'widget_posts_args', array( $this, 'filter_recent_posts_widget' ) );
			add_filter( 'query_loop_block_query_vars', array( $this, 'filter_query_loop_block' ), 10, 1 );
			add_filter( 'get_next_post_join', array( $this, 'filter_adjacent_post_join' ) );
			add_filter( 'get_previous_post_join', array( $this, 'filter_adjacent_post_join' ) );
			add_filter( 'get_next_post_where', array( $this, 'filter_adjacent_post_where' ) );
			add_filter( 'get_previous_post_where', array( $this, 'filter_adjacent_post_where' ) );
			add_action( 'rest_api_init', array( $this, 'register_rest_filters' ) );
		}

		/**
		 * Register the meta box, skipping excluded post types.
		 *
		 * @param string $post_type Current post type.
		 */
		public function register_meta_box( $post_type ) {
			$excluded = apply_filters( 'ptt_exclude_post_type', array( 'acf-field-group', 'attachment' ) );
			if ( in_array( $post_type, $excluded, true ) ) {
				return;
			}
			add_meta_box(
				'ptt-post-visibility',
				__( 'Post Visibility', 'post-type-transfer' ),
				array( $this, 'render_meta_box' ),
				null,
				'side',
				'default',
				array( '__block_editor_compatible_meta_box' => true )
			);
		}

		/**
		 * Render the meta box HTML.
		 *
		 * @param WP_Post $post Current post object.
		 */
		public function render_meta_box( $post ) {
			wp_nonce_field( 'ptt_post_visibility', 'ptt_post_visibility_nonce' );
			$active = $this->get_active_options( $post->ID );
			?>
			<div class="ptt-visibility-options">
				<label class="ptt-visibility-all">
					<input type="checkbox" id="ptt-check-all" />
					<?php esc_html_e( 'All Check/Uncheck', 'post-type-transfer' ); ?>
				</label>
				<?php foreach ( self::OPTIONS as $key => $label ) : ?>
					<label>
						<input
							type="checkbox"
							class="ptt-visibility-option"
							name="ptt_visibility[]"
							value="<?php echo esc_attr( $key ); ?>"
							<?php checked( in_array( $key, $active, true ) ); ?>
						/>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</div>
			<?php
		}

		/**
		 * Retrieve which visibility options are active for a post.
		 *
		 * @param int $post_id Post ID.
		 * @return string[]
		 */
		private function get_active_options( $post_id ) {
			$active = array();
			foreach ( array_keys( self::OPTIONS ) as $key ) {
				if ( '1' === get_post_meta( $post_id, self::META_PREFIX . $key, true ) ) {
					$active[] = $key;
				}
			}
			return $active;
		}

		/**
		 * Save visibility meta on post save.
		 *
		 * @param int $post_id Post ID.
		 */
		public function save_meta( $post_id ) {
			if ( ! isset( $_POST['ptt_post_visibility_nonce'] ) ) {
				return;
			}
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ptt_post_visibility_nonce'] ) ), 'ptt_post_visibility' ) ) {
				return;
			}
			if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
				return;
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			$submitted = isset( $_POST['ptt_visibility'] )
				? array_map( 'sanitize_key', wp_unslash( $_POST['ptt_visibility'] ) )
				: array();

			foreach ( array_keys( self::OPTIONS ) as $key ) {
				if ( in_array( $key, $submitted, true ) ) {
					update_post_meta( $post_id, self::META_PREFIX . $key, '1' );
				} else {
					delete_post_meta( $post_id, self::META_PREFIX . $key );
				}
			}
		}

		/**
		 * Enqueue assets on post editor screens.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public function enqueue_assets( $hook ) {
			if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
				return;
			}
			wp_enqueue_script(
				'ptt-post-visibility',
				plugin_dir_url( __FILE__ ) . 'assets/js/ptt-post-visibility.js',
				array(),
				POST_TYPE_TRANSFER_VERSION,
				true
			);
			wp_enqueue_style(
				'ptt-post-visibility',
				plugin_dir_url( __FILE__ ) . 'assets/css/ptt-post-visibility.css',
				array(),
				POST_TYPE_TRANSFER_VERSION
			);
		}

		/**
		 * Workaround for is_front_page() not working inside pre_get_posts when a
		 * static front page is set. Manually populates queried_object when page_id
		 * is present but queried_object hasn't been set yet.
		 *
		 * @see https://core.trac.wordpress.org/ticket/27015
		 *
		 * @param WP_Query $query Current query object.
		 */
		public function fix_queried_object( $query ) {
			if ( is_null( $query->queried_object ) && $query->get( 'page_id' ) ) {
				$query->queried_object    = get_post( $query->get( 'page_id' ) );
				$query->queried_object_id = (int) $query->get( 'page_id' );
			}
		}

		/**
		 * Exclude hidden posts from the Recent Posts widget.
		 *
		 * @param array $args WP_Query args passed to the widget.
		 * @return array
		 */
		public function filter_recent_posts_widget( $args ) {
			$meta_query         = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();
			$meta_query[]       = array(
				'key'     => self::META_PREFIX . 'recent_posts',
				'compare' => 'NOT EXISTS',
			);
			$args['meta_query'] = $meta_query;
			return $args;
		}

		/**
		 * Redirect to 404 if the post has that option enabled.
		 */
		public function handle_redirect_404() {
			if ( is_admin() || ! is_singular() ) {
				return;
			}
			$post_id = get_queried_object_id();
			if ( '1' !== get_post_meta( $post_id, self::META_PREFIX . 'redirect_404', true ) ) {
				return;
			}
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			$template = get_404_template();
			if ( $template ) {
				include $template; // phpcs:ignore WPThemeReview.CoreFunctionality.FileInclude.FileIncludeFound
			}
			exit;
		}

		/**
		 * Apply meta_query conditions to exclude hidden posts from relevant queries.
		 *
		 * @param WP_Query $query The query object.
		 */
		public function filter_queries( $query ) {
			if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}

			$meta_keys = array();

			if ( $query->is_main_query() ) {
				if ( $query->is_front_page() ) {
					$meta_keys[] = self::META_PREFIX . 'front_page';
				}
				if ( $query->is_category() ) {
					$meta_keys[] = self::META_PREFIX . 'category_pages';
				}
				if ( $query->is_tag() ) {
					$meta_keys[] = self::META_PREFIX . 'tag_pages';
				}
				if ( $query->is_author() ) {
					$meta_keys[] = self::META_PREFIX . 'author_pages';
				}
				if ( $query->is_date() ) {
					$meta_keys[] = self::META_PREFIX . 'date_archive';
				}
				if ( $query->is_search() ) {
					$meta_keys[] = self::META_PREFIX . 'search_pages';
				}
				if ( $query->is_feed() ) {
					$meta_keys[] = self::META_PREFIX . 'feeds';
				}
			} else {
				// Secondary queries (Query Loop block, custom loops, etc.):
				// global conditionals use $wp_query which is already fully set up.
				$meta_keys[] = self::META_PREFIX . 'recent_posts';

				if ( is_front_page() ) {
					$meta_keys[] = self::META_PREFIX . 'front_page';
				}
				if ( is_category() ) {
					$meta_keys[] = self::META_PREFIX . 'category_pages';
				}
				if ( is_tag() ) {
					$meta_keys[] = self::META_PREFIX . 'tag_pages';
				}
				if ( is_author() ) {
					$meta_keys[] = self::META_PREFIX . 'author_pages';
				}
				if ( is_date() ) {
					$meta_keys[] = self::META_PREFIX . 'date_archive';
				}
				if ( is_search() ) {
					$meta_keys[] = self::META_PREFIX . 'search_pages';
				}
				if ( is_feed() ) {
					$meta_keys[] = self::META_PREFIX . 'feeds';
				}
			}

			if ( empty( $meta_keys ) ) {
				return;
			}

			$meta_query = (array) $query->get( 'meta_query' );
			foreach ( $meta_keys as $key ) {
				$meta_query[] = array(
					'key'     => $key,
					'compare' => 'NOT EXISTS',
				);
			}
			$query->set( 'meta_query', $meta_query );
		}

		/**
		 * Filter the Query Loop block's query vars.
		 *
		 * `query_loop_block_query_vars` fires during block rendering (after the `wp`
		 * action), so global template conditionals are fully reliable here.
		 *
		 * @param array $query WP_Query args for the block.
		 * @return array
		 */
		public function filter_query_loop_block( $query ) {
			$keys = array( self::META_PREFIX . 'recent_posts' );

			if ( is_front_page() ) {
				$keys[] = self::META_PREFIX . 'front_page';
			}
			if ( is_category() ) {
				$keys[] = self::META_PREFIX . 'category_pages';
			}
			if ( is_tag() ) {
				$keys[] = self::META_PREFIX . 'tag_pages';
			}
			if ( is_author() ) {
				$keys[] = self::META_PREFIX . 'author_pages';
			}
			if ( is_date() ) {
				$keys[] = self::META_PREFIX . 'date_archive';
			}
			if ( is_search() ) {
				$keys[] = self::META_PREFIX . 'search_pages';
			}
			if ( is_feed() ) {
				$keys[] = self::META_PREFIX . 'feeds';
			}

			$meta_query = isset( $query['meta_query'] ) ? (array) $query['meta_query'] : array();
			foreach ( $keys as $key ) {
				$meta_query[] = array(
					'key'     => $key,
					'compare' => 'NOT EXISTS',
				);
			}
			$query['meta_query'] = $meta_query;

			return $query;
		}

		/**
		 * Add a LEFT JOIN for adjacent post meta visibility.
		 *
		 * @param string $join JOIN clause.
		 * @return string
		 */
		public function filter_adjacent_post_join( $join ) {
			global $wpdb;
			$join .= " LEFT JOIN {$wpdb->postmeta} AS ptt_adj_meta ON p.ID = ptt_adj_meta.post_id AND ptt_adj_meta.meta_key = '_ptt_hide_adjacent_links' ";
			return $join;
		}

		/**
		 * Exclude posts hidden from adjacent navigation.
		 *
		 * @param string $where WHERE clause.
		 * @return string
		 */
		public function filter_adjacent_post_where( $where ) {
			$where .= " AND (ptt_adj_meta.meta_id IS NULL OR ptt_adj_meta.meta_value != '1') ";
			return $where;
		}

		/**
		 * Register REST API query filters for all REST-enabled post types.
		 */
		public function register_rest_filters() {
			$post_types = get_post_types( array( 'show_in_rest' => true ) );
			foreach ( $post_types as $post_type ) {
				add_filter(
					"rest_{$post_type}_query",
					array( $this, 'filter_rest_query' ),
					10,
					2
				);
			}
		}

		/**
		 * Exclude REST-API-hidden posts from REST collection responses.
		 *
		 * @param array           $args    Query arguments.
		 * @param WP_REST_Request $request REST request.
		 * @return array
		 */
		public function filter_rest_query( $args, $request ) {
			// Allow editors to see all posts in the edit context.
			if ( 'edit' === $request->get_param( 'context' ) ) {
				return $args;
			}
			$meta_query         = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();
			$meta_query[]       = array(
				'key'     => self::META_PREFIX . 'rest_api',
				'compare' => 'NOT EXISTS',
			);
			$args['meta_query'] = $meta_query;
			return $args;
		}
	}
}
