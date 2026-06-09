<?php
/**
 * Review Notice Handler
 *
 * @package Post_Type_Transfer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PTT_Review_Notice
 *
 * Displays a dismissible admin notice asking users to rate the plugin
 * after 7 days of activation.
 */
class PTT_Review_Notice {

	/**
	 * Option key that stores the plugin activation timestamp.
	 *
	 * @var string
	 */
	const ACTIVATION_OPTION = 'ptt_activation_date';

	/**
	 * Option key that flags whether the notice has been dismissed.
	 *
	 * @var string
	 */
	const DISMISSED_OPTION = 'ptt_review_notice_dismissed';

	/**
	 * Number of days after activation before the notice appears.
	 *
	 * @var int
	 */
	const DAYS_BEFORE_NOTICE = 7;

	/**
	 * Constructor — registers admin notice and AJAX dismiss handler.
	 */
	public function __construct() {
		self::set_activation_date();
		add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
		add_action( 'wp_ajax_ptt_dismiss_review_notice', array( $this, 'dismiss_notice' ) );
	}

	/**
	 * Records the plugin activation timestamp.
	 *
	 * Should be called inside the plugin's activation hook. Uses add_option
	 * so an existing timestamp is never overwritten on reactivation.
	 */
	public static function activate() {
		self::set_activation_date();
	}

	/**
	 * Sets the activation date option if it does not exist yet.
	 *
	 * Called both on activation and on every load so that users who update
	 * the plugin (without a fresh activate) also get a timestamp recorded.
	 */
	private static function set_activation_date() {
		if ( ! get_option( self::ACTIVATION_OPTION ) ) {
			add_option( self::ACTIVATION_OPTION, time() );
		}
	}

	/**
	 * Shows the review notice when all conditions are met:
	 *   - Not already dismissed.
	 *   - Activation timestamp is present.
	 *   - At least DAYS_BEFORE_NOTICE days have elapsed.
	 *   - Current user can manage options.
	 */
	public function maybe_show_notice() {
		if ( get_option( self::DISMISSED_OPTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$activation_date = (int) get_option( self::ACTIVATION_OPTION );
		if ( ! $activation_date ) {
			return;
		}

		if ( ( time() - $activation_date ) < ( self::DAYS_BEFORE_NOTICE * DAY_IN_SECONDS ) ) {
			return;
		}

		$this->render_notice();
	}

	/**
	 * Persists the notice dismissal via AJAX.
	 */
	public function dismiss_notice() {
		check_ajax_referer( 'ptt_dismiss_review_notice', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}

		update_option( self::DISMISSED_OPTION, true );
		wp_die();
	}

	/**
	 * Renders the notice markup and the inline dismiss script.
	 */
	private function render_notice() {
		$review_url = 'https://wordpress.org/support/plugin/post-type-transfer/reviews/#new-post';
		$nonce      = wp_create_nonce( 'ptt_dismiss_review_notice' );

		$message = sprintf(
			/* translators: %1$s: opening anchor tag, %2$s: closing anchor tag */
			__( 'Love using Post Type Transfer? Share your experience with a review on %1$sWordPress.org%2$s. Your feedback helps us improve the plugin and continue delivering new features and enhancements.', 'post-type-transfer' ),
			'<a href="' . esc_url( $review_url ) . '" target="_blank" rel="noopener noreferrer">',
			'</a>'
		);
		?>
		<div class="notice notice-info is-dismissible ptt-review-notice" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p><?php echo wp_kses_post( $message ); ?></p>
		</div>
		<script>
		( function( $ ) {
			$( document ).on( 'click', '.ptt-review-notice .notice-dismiss', function() {
				$.post( ajaxurl, {
					action: 'ptt_dismiss_review_notice',
					nonce: $( '.ptt-review-notice' ).data( 'nonce' )
				} );
			} );
		} )( jQuery );
		</script>
		<?php
	}
}
