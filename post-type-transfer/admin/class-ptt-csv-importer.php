<?php
/**
 * CSV Importer — creates or updates posts from an uploaded CSV file.
 *
 * @package Post_Type_Transfer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PTT_CSV_Importer' ) ) {

	/**
	 * Handles the admin_post_ptt_import_csv action.
	 *
	 * For each CSV row the importer:
	 *   - Matches an existing post by post_name (slug) or creates a new one.
	 *   - Restores title, content, status, and date.
	 *   - Sets categories and tags (pipe-separated), creating them when absent.
	 *   - Downloads and attaches the featured image from its URL.
	 *   - Writes every non-structural column back as post meta, including all
	 *     ACF field values and ACF field-key references (_field = field_abc123).
	 *     Columns with a "__json__:" prefix are decoded back to arrays/objects
	 *     before storage so ACF gallery, relationship, and similar fields are
	 *     restored faithfully.
	 *
	 * Results are stored in a 60-second user-scoped transient so the Admin UI
	 * class can display a dismissible notice after the redirect.
	 *
	 * Developer filters (see inline apply_filters() calls for full signatures):
	 *   ptt_csv_import_skip_row     — programmatically skip a row
	 *   ptt_csv_import_post_data    — modify post data before insert/update
	 *   ptt_csv_import_meta_value   — transform a meta value before update_post_meta
	 *   ptt_csv_import_image_url    — rewrite the image URL before sideloading
	 */
	class PTT_CSV_Importer {

		/**
		 * Structural CSV columns that map directly to wp_insert_post / wp_update_post
		 * arguments or receive dedicated handling.  Every other column in the CSV is
		 * written back to post meta — this is how all ACF fields, custom meta, and
		 * the original _hide_post field are restored without special-casing each one.
		 *
		 * @var string[]
		 */
		private static $structural_columns = array(
			'ID',
			'post_title',
			'post_content',
			'post_name',
			'post_status',
			'post_date',
			'categories',
			'tags',
			'featured_image',
		);

		/**
		 * Counters and error list for the current import run.
		 *
		 * @var array{created: int, updated: int, skipped: int, errors: array<int, string>}
		 */
		private $result = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);

		/**
		 * Register hooks.
		 */
		public function __construct() {
			add_action( 'admin_post_ptt_import_csv', array( $this, 'handle_import' ) );
		}

		/**
		 * Entry point for the import action.
		 */
		public function handle_import() {
			$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
			if ( ! $post_type ) {
				$this->abort( __( 'Invalid post type.', 'post-type-transfer' ) );
			}

			if (
				! isset( $_POST['ptt_nonce'] ) ||
				! wp_verify_nonce(
					sanitize_text_field( wp_unslash( $_POST['ptt_nonce'] ) ),
					'ptt_import_csv_' . $post_type
				)
			) {
				$this->abort( __( 'Security check failed.', 'post-type-transfer' ) );
			}

			$obj = get_post_type_object( $post_type );
			if ( ! $obj || ! current_user_can( $obj->cap->edit_posts ) ) {
				$this->abort( __( 'You do not have permission to import posts.', 'post-type-transfer' ) );
			}

			$excluded = apply_filters(
				'ptt_csv_excluded_post_types',
				array( 'product', 'product_variation', 'shop_order', 'shop_coupon', 'shop_webhook' )
			);
			if ( in_array( $post_type, (array) $excluded, true ) ) {
				$this->abort( __( 'CSV import is not available for this post type.', 'post-type-transfer' ) );
			}

			$upload_error = isset( $_FILES['ptt_csv_file']['error'] )
				? (int) $_FILES['ptt_csv_file']['error'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				: UPLOAD_ERR_NO_FILE;

			if ( empty( $_FILES['ptt_csv_file'] ) || UPLOAD_ERR_OK !== $upload_error ) {
				$this->abort( __( 'File upload failed. Please try again.', 'post-type-transfer' ) );
			}

			$tmp_path = isset( $_FILES['ptt_csv_file']['tmp_name'] )
				? $_FILES['ptt_csv_file']['tmp_name'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				: '';

			if ( ! is_uploaded_file( $tmp_path ) ) {
				$this->abort( __( 'Invalid file upload.', 'post-type-transfer' ) );
			}

			$original_name = isset( $_FILES['ptt_csv_file']['name'] )
				? sanitize_file_name( wp_unslash( $_FILES['ptt_csv_file']['name'] ) )
				: '';

			if ( 'csv' !== strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) ) ) {
				$this->abort( __( 'Only .csv files are accepted.', 'post-type-transfer' ) );
			}

			$this->parse_and_import( $tmp_path, $post_type );
			set_transient( 'ptt_import_result_' . get_current_user_id(), $this->result, 60 );
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . $post_type ) );
			exit;
		}

		/**
		 * Open the CSV file, validate headers, and process every data row.
		 *
		 * @param string $file_path Absolute path to the uploaded temp file.
		 * @param string $post_type Target post type slug.
		 */
		private function parse_and_import( $file_path, $post_type ) {
			$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $handle ) {
				$this->result['errors'][] = __( 'Could not read the uploaded file.', 'post-type-transfer' );
				return;
			}

			// Strip UTF-8 BOM produced by Excel / our own exporter.
			$bom = fread( $handle, 3 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( "\xEF\xBB\xBF" !== $bom ) {
				rewind( $handle );
			}

			// Header row.
			$headers = fgetcsv( $handle );
			if ( ! $headers ) {
				$this->result['errors'][] = __( 'CSV file is empty or could not be parsed.', 'post-type-transfer' );
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return;
			}
			$headers = array_map( 'trim', $headers );

			// Require the two columns we depend on for matching.
			foreach ( array( 'post_title', 'post_name' ) as $required ) {
				if ( ! in_array( $required, $headers, true ) ) {
					$this->result['errors'][] = sprintf(
						/* translators: %s: CSV column name */
						__( 'Required column "%s" not found in the CSV.', 'post-type-transfer' ),
						$required
					);
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					return;
				}
			}

			$col_count = count( $headers );
			$row_num   = 1;

			$row = fgetcsv( $handle );
			while ( false !== $row ) {
				++$row_num;

				// fgetcsv() returns array( null ) for blank/empty lines skip silently.
				if ( 1 === count( $row ) && null === $row[0] ) {
					$row = fgetcsv( $handle );
					continue;
				}

				if ( count( $row ) !== $col_count ) {
					$this->result['errors'][] = sprintf(
						/* translators: %d: row number */
						__( 'Row %d: column count mismatch skipped.', 'post-type-transfer' ),
						$row_num
					);
					++$this->result['skipped'];
					$row = fgetcsv( $handle );
					continue;
				}

				$data = array_combine( $headers, $row );

				/**
				 * Filter whether to skip a CSV row before processing it.
				 *
				 * Return true to silently skip the row and increment the skipped counter.
				 * Useful for conditional imports (e.g. skip rows missing a custom field).
				 *
				 * @param bool                 $skip      Whether to skip this row. Default false.
				 * @param array<string,string> $data      Associative row data (column => value).
				 * @param string               $post_type Target post type slug.
				 * @param int                  $row_num   Current row number (1-indexed, header = 1).
				 */
				if ( (bool) apply_filters( 'ptt_csv_import_skip_row', false, $data, $post_type, $row_num ) ) {
					++$this->result['skipped'];
					$row = fgetcsv( $handle );
					continue;
				}

				$outcome = $this->process_row( $data, $post_type );

				if ( is_wp_error( $outcome ) ) {
					$this->result['errors'][] = sprintf(
						/* translators: 1: row number  2: error message */
						__( 'Row %1$d: %2$s', 'post-type-transfer' ),
						$row_num,
						$outcome->get_error_message()
					);
					++$this->result['skipped'];
				} elseif ( 'created' === $outcome || 'updated' === $outcome ) {
					++$this->result[ $outcome ];
				}

				$row = fgetcsv( $handle );
			}

			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		/**
		 * Create or update a single post from a CSV row.
		 *
		 * @param array<string,string> $data      Associative row (header => value).
		 * @param string               $post_type Target post type slug.
		 * @return string|WP_Error 'created', 'updated', or a WP_Error.
		 */
		private function process_row( $data, $post_type ) {
			$slug = isset( $data['post_name'] ) ? sanitize_title( trim( $data['post_name'] ) ) : '';
			if ( ! $slug ) {
				return new WP_Error(
					'empty_slug',
					__( 'post_name (slug) is empty cannot match or create post.', 'post-type-transfer' )
				);
			}

			$title = isset( $data['post_title'] ) ? sanitize_text_field( trim( $data['post_title'] ) ) : '';
			if ( ! $title ) {
				return new WP_Error(
					'empty_title',
					__( 'post_title is empty cannot match or create post.', 'post-type-transfer' )
				);
			}

			$status   = $this->sanitize_post_status( isset( $data['post_status'] ) ? $data['post_status'] : 'draft' );
			$raw_date = isset( $data['post_date'] ) ? trim( $data['post_date'] ) : '';

			// 'future' status requires a date that is actually in the future.
			if ( 'future' === $status ) {
				$timestamp = $raw_date ? strtotime( $raw_date ) : false;
				if ( false === $timestamp || $timestamp <= time() ) {
					$status = 'draft';
				}
			}

			$post_data = array(
				'post_type'    => $post_type,
				'post_title'   => $title,
				'post_content' => isset( $data['post_content'] ) ? wp_kses_post( $data['post_content'] ) : '',
				'post_name'    => $slug,
				'post_status'  => $status,
			);

			// Preserve original date only when supplied and valid.
			if ( $raw_date && false !== strtotime( $raw_date ) ) {
				$post_data['post_date']     = $raw_date;
				$post_data['post_date_gmt'] = get_gmt_from_date( $raw_date );
			}

			$existing = $this->get_post_by_slug( $slug, $post_type );

			if ( $existing ) {
				$post_data['ID'] = $existing->ID;
			}

			/**
			 * Filter the post data array before it is passed to wp_insert_post()
			 * or wp_update_post().
			 *
			 * Use this hook to set additional post fields (e.g. post_author,
			 * menu_order, post_excerpt) or to override sanitised values.
			 *
			 * @param array<string,mixed>  $post_data Prepared post data.
			 * @param array<string,string> $data      Raw CSV row (column => value).
			 * @param string               $post_type Post type slug.
			 * @param WP_Post|null         $existing  Existing post, or null when creating.
			 */
			$post_data = (array) apply_filters( 'ptt_csv_import_post_data', $post_data, $data, $post_type, $existing );

			if ( $existing ) {
				$post_id = wp_update_post( $post_data, true );
				$action  = 'updated';
			} else {
				$post_id = wp_insert_post( $post_data, true );
				$action  = 'created';
			}

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			// Taxonomies only attempt if the taxonomy is registered for this type.
			$this->set_terms( $post_id, $post_type, 'category', isset( $data['categories'] ) ? $data['categories'] : '' );
			$this->set_terms( $post_id, $post_type, 'post_tag', isset( $data['tags'] ) ? $data['tags'] : '' );

			// Featured image sideload from URL when provided.
			if ( ! empty( $data['featured_image'] ) ) {
				$this->set_featured_image( $post_id, $data['featured_image'] );
			}

			// Every non-structural column is a post meta field.
			foreach ( $data as $key => $raw_value ) {
				if ( in_array( $key, self::$structural_columns, true ) ) {
					continue;
				}

				// Only accept well-formed meta keys (alphanumeric, underscores, hyphens).
				if ( ! preg_match( '/^[a-zA-Z0-9_\-]+$/', $key ) ) {
					continue;
				}

				// Empty string: skip to avoid overwriting existing meta with nothing.
				if ( '' === $raw_value ) {
					continue;
				}

				$meta_value = $this->decode_meta_value( $raw_value );

				/**
				 * Filter a decoded meta value before it is written to the database.
				 *
				 * Return null to skip saving this particular meta key for this post.
				 *
				 * @param mixed  $meta_value Decoded value (scalar, array, or object).
				 * @param string $key        Meta key.
				 * @param int    $post_id    Post ID.
				 * @param string $post_type  Post type slug.
				 */
				$meta_value = apply_filters( 'ptt_csv_import_meta_value', $meta_value, $key, $post_id, $post_type );

				if ( null !== $meta_value ) {
					update_post_meta( $post_id, $key, $meta_value );
				}
			}

			return $action;
		}

		/**
		 * Return the first post matching the slug and post type (including trashed).
		 *
		 * @param string $slug      Post slug.
		 * @param string $post_type Post type slug.
		 * @return WP_Post|null
		 */
		private function get_post_by_slug( $slug, $post_type ) {
			$posts = get_posts(
				array(
					'name'           => $slug,
					'post_type'      => $post_type,
					'post_status'    => array( 'publish', 'draft', 'private', 'pending', 'future', 'trash' ),
					'posts_per_page' => 1,
					'no_found_rows'  => true,
				)
			);
			return ! empty( $posts ) ? $posts[0] : null;
		}

		/**
		 * Assign pipe-separated terms to a post, creating any that do not yet exist.
		 *
		 * Silently skips if the taxonomy is not registered for the post type.
		 *
		 * @param int    $post_id        Post ID.
		 * @param string $post_type      Post type slug (used to check object/taxonomy link).
		 * @param string $taxonomy       Taxonomy slug.
		 * @param string $term_names_str Pipe-separated term names (may be empty).
		 */
		private function set_terms( $post_id, $post_type, $taxonomy, $term_names_str ) {
			if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				return;
			}

			if ( '' === trim( $term_names_str ) ) {
				wp_set_post_terms( $post_id, array(), $taxonomy );
				return;
			}

			$names    = array_unique( array_filter( array_map( 'trim', explode( '|', $term_names_str ) ) ) );
			$term_ids = array();

			foreach ( $names as $name ) {
				$term = get_term_by( 'name', $name, $taxonomy );
				if ( $term ) {
					$term_ids[] = (int) $term->term_id;
				} else {
					$inserted = wp_insert_term( $name, $taxonomy );
					if ( ! is_wp_error( $inserted ) ) {
						$term_ids[] = (int) $inserted['term_id'];
					}
				}
			}

			if ( $term_ids ) {
				wp_set_post_terms( $post_id, $term_ids, $taxonomy );
			}
		}

		/**
		 * Sideload an image from a URL and set it as the post's featured image.
		 *
		 * Re-uses an already-attached image when its source URL matches, avoiding
		 * duplicate uploads.
		 *
		 * @param int    $post_id   Post ID.
		 * @param string $image_url Remote image URL.
		 */
		private function set_featured_image( $post_id, $image_url ) {
			$image_url = esc_url_raw( trim( $image_url ) );
			if ( ! $image_url ) {
				return;
			}

			/**
			 * Filter the featured image URL before it is sideloaded.
			 *
			 * Return an empty string to skip sideloading for this post.
			 * Useful for CDN URL rewrites, local-to-staging mappings, or
			 * routing images through a proxy.
			 *
			 * @param string $image_url Sanitised source URL.
			 * @param int    $post_id   Post ID.
			 */
			$image_url = (string) apply_filters( 'ptt_csv_import_image_url', $image_url, $post_id );
			if ( ! $image_url ) {
				return;
			}
			$existing_id = attachment_url_to_postid( $image_url );
			if ( $existing_id ) {
				set_post_thumbnail( $post_id, $existing_id );
				return;
			}

			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			// Raise memory limit before sideloading large images can exceed the
			// default PHP memory limit and cause a fatal error mid-import.
			wp_raise_memory_limit( 'image' );

			$attach_id = media_sideload_image( $image_url, $post_id, null, 'id' );

			if ( ! is_wp_error( $attach_id ) && is_int( $attach_id ) && $attach_id > 0 ) {
				set_post_thumbnail( $post_id, $attach_id );
			}
		}

		/**
		 * Normalize a post status value to one of the allowed statuses.
		 *
		 * @param string $raw Raw status string from CSV.
		 * @return string
		 */
		private function sanitize_post_status( $raw ) {
			$allowed = array( 'publish', 'draft', 'private', 'pending', 'future' );
			$clean   = sanitize_key( trim( $raw ) );
			return in_array( $clean, $allowed, true ) ? $clean : 'draft';
		}

		/**
		 * Decode a meta value that was encoded by PTT_CSV_Exporter::encode_meta_value().
		 *
		 * Scalar strings pass through unchanged.  Values prefixed with "__json__:"
		 * are JSON-decoded back to their original array/object form so WordPress
		 * can re-serialize them on storage this preserves ACF gallery, relationship,
		 * flexible-content, and any other field types that store PHP arrays.
		 *
		 * @param string $value Raw cell value from the CSV.
		 * @return mixed Decoded value ready for update_post_meta().
		 */
		private function decode_meta_value( $value ) {
			if ( 0 === strpos( $value, '__json__:' ) ) {
				$decoded = json_decode( substr( $value, 9 ), true );
				return ( null !== $decoded ) ? $decoded : $value;
			}
			// Fallback encoding used by encode_meta_value() when wp_json_encode() fails.
			if ( 0 === strpos( $value, '__serialized__:' ) ) {
				return maybe_unserialize( substr( $value, 15 ) );
			}
			return $value;
		}

		/**
		 * Store a single-error result in the transient and redirect.
		 *
		 * @param string $message Human-readable error message.
		 */
		private function abort( $message ) {
			set_transient(
				'ptt_import_result_' . get_current_user_id(),
				array(
					'created' => 0,
					'updated' => 0,
					'skipped' => 0,
					'errors'  => array( $message ),
				),
				60
			);
			$referer = wp_get_referer();
			wp_safe_redirect( $referer ? $referer : admin_url() );
			exit;
		}
	}
}
