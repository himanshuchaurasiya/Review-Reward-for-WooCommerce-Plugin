<?php
/**
 * Plugin Name:       Review Reward for WooCommerce
 * Description:       Sends review reminder emails and issues a single-use reward coupon after a verified customer review is approved.
 * Version:           1.1.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            The HC
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       review-reward-for-woocommerce
 * WC requires at least: 6.0
 * WC tested up to:   10.0
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

final class Revrew_Review_Reward {

	const OPT                  = 'revrew_options';
	const CRON                 = 'revrew_daily';
	const MAX_REMINDER_AGE_DAYS = 90; // Used when the review window is 0 (no limit).

	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( __FILE__, array( __CLASS__, 'deactivate' ) );
		add_action( 'plugins_loaded', array( new self(), 'boot' ) );
	}

	public static function activate() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
	}

	public function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		// Make sure the cron event exists (e.g. after a manual file update).
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
		add_action( 'comment_post', array( $this, 'on_comment_post' ), 20, 3 );
		add_action( 'transition_comment_status', array( $this, 'on_status_change' ), 20, 3 );
		add_action( self::CRON, array( $this, 'send_reminders' ) );
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate_max_value' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public static function defaults() {
		return array(
			'enabled'       => 1,
			'reward_type'   => 'free_order',
			'fixed_amount'  => 500,
			'max_value'     => 2000,
			'expiry_days'   => 30,
			'free_shipping' => 1,
			'min_rating'    => 1,
			'min_length'    => 20,
			'window_days'   => 30,
			'remind_days'   => 7,
			'allow_chain'   => 0,
		);
	}

	public static function opt( $key ) {
		$options = wp_parse_args( get_option( self::OPT, array() ), self::defaults() );
		return $options[ $key ] ?? null;
	}

	private function fields() {
		return array(
			'enabled'       => array( __( 'Enable rewards', 'review-reward-for-woocommerce' ), 'checkbox', '' ),
			'reward_type'   => array(
				__( 'Reward type', 'review-reward-for-woocommerce' ),
				'select',
				__( 'Free order means 100% off eligible cart items, subject to the maximum order value.', 'review-reward-for-woocommerce' ),
				array(
					'free_order' => __( 'Free order (100% off)', 'review-reward-for-woocommerce' ),
					'fixed'      => __( 'Fixed amount off', 'review-reward-for-woocommerce' ),
				),
			),
			'fixed_amount'  => array( __( 'Fixed amount', 'review-reward-for-woocommerce' ), 'number', __( 'Used only for Fixed amount off.', 'review-reward-for-woocommerce' ) ),
			'max_value'     => array( __( 'Max order value for free order', 'review-reward-for-woocommerce' ), 'number', __( 'Cart subtotal must be at or below this amount. 0 means no limit.', 'review-reward-for-woocommerce' ) ),
			'expiry_days'   => array( __( 'Coupon validity (days)', 'review-reward-for-woocommerce' ), 'number', __( '0 means the coupon never expires.', 'review-reward-for-woocommerce' ) ),
			'free_shipping' => array( __( 'Include free shipping', 'review-reward-for-woocommerce' ), 'checkbox', '' ),
			'min_rating'    => array( __( 'Minimum star rating', 'review-reward-for-woocommerce' ), 'number', __( 'Choose 1-5. Set 1 to reward any rating.', 'review-reward-for-woocommerce' ) ),
			'min_length'    => array( __( 'Minimum review length (characters)', 'review-reward-for-woocommerce' ), 'number', __( 'Reviews shorter than this will not earn a reward.', 'review-reward-for-woocommerce' ) ),
			'window_days'   => array(
				__( 'Review window (days)', 'review-reward-for-woocommerce' ),
				'number',
				sprintf(
					/* translators: %d: number of days */
					__( 'Review must be submitted within this many days after order completion. 0 means no limit for rewards; reminders then only go to orders completed in the last %d days.', 'review-reward-for-woocommerce' ),
					self::MAX_REMINDER_AGE_DAYS
				),
			),
			'remind_days'   => array( __( 'Reminder email after (days)', 'review-reward-for-woocommerce' ), 'number', __( 'Send one review reminder this many days after order completion. Set 0 to disable reminders.', 'review-reward-for-woocommerce' ) ),
			'allow_chain'   => array( __( 'Allow rewards on reward orders', 'review-reward-for-woocommerce' ), 'checkbox', __( 'If disabled, orders using a review reward coupon cannot earn another reward.', 'review-reward-for-woocommerce' ) ),
		);
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Review Rewards', 'review-reward-for-woocommerce' ),
			__( 'Review Rewards', 'review-reward-for-woocommerce' ),
			'manage_woocommerce',
			'revrew-settings',
			array( $this, 'page' )
		);
	}

	public function register_settings() {
		register_setting(
			'revrew_group',
			self::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();
		foreach ( $this->fields() as $key => $field ) {
			if ( 'checkbox' === $field[1] ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			} elseif ( 'select' === $field[1] ) {
				$choices     = $field[3];
				$value       = isset( $input[ $key ] ) ? sanitize_key( wp_unslash( $input[ $key ] ) ) : 'free_order';
				$out[ $key ] = isset( $choices[ $value ] ) ? $value : 'free_order';
			} else {
				$out[ $key ] = max( 0, (float) ( $input[ $key ] ?? 0 ) );
			}
		}
		$out['min_rating']  = min( 5, max( 1, (int) $out['min_rating'] ) );
		$out['min_length']  = (int) $out['min_length'];
		$out['window_days'] = (int) $out['window_days'];
		$out['remind_days'] = (int) $out['remind_days'];
		$out['expiry_days'] = (int) $out['expiry_days'];
		return $out;
	}

	public function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Review Rewards', 'review-reward-for-woocommerce' ) . '</h1>';
		echo '<p>' . esc_html__( 'Configure review reminder emails and reward coupons. Reminders are sent by a daily scheduled task.', 'review-reward-for-woocommerce' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'revrew_group' );
		echo '<table class="form-table">';
		foreach ( $this->fields() as $key => $field ) {
			$name  = self::OPT . '[' . $key . ']';
			$value = self::opt( $key );
			echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label></th><td>';
			if ( 'checkbox' === $field[1] ) {
				printf(
					'<input id="%s" type="checkbox" name="%s" value="1" %s>',
					esc_attr( $key ),
					esc_attr( $name ),
					checked( $value, 1, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
			} elseif ( 'select' === $field[1] ) {
				echo '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $name ) . '">';
				foreach ( $field[3] as $option_value => $label ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( $option_value ),
						selected( $value, $option_value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						esc_html( $label )
					);
				}
				echo '</select>';
			} else {
				printf(
					'<input id="%s" type="number" step="any" min="0" name="%s" value="%s" class="small-text">',
					esc_attr( $key ),
					esc_attr( $name ),
					esc_attr( $value )
				);
			}
			if ( ! empty( $field[2] ) ) {
				echo '<p class="description">' . esc_html( $field[2] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		submit_button( __( 'Save changes', 'review-reward-for-woocommerce' ) );
		echo '</form></div>';
	}

	public function on_comment_post( $comment_id, $approved, $commentdata = array() ) {
		if ( 1 === (int) $approved ) {
			$this->process_review( $comment_id );
		}
	}

	public function on_status_change( $new_status, $old_status, $comment ) {
		if ( 'approved' === $new_status && 'approved' !== $old_status && $comment instanceof WP_Comment ) {
			$this->process_review( $comment->comment_ID );
		}
	}

	private function process_review( $comment_id ) {
		if ( ! self::opt( 'enabled' ) ) {
			return;
		}
		$comment = get_comment( $comment_id );
		if ( ! $comment || (int) $comment->comment_parent !== 0 ) {
			return;
		}
		$product_id = (int) $comment->comment_post_ID;
		if ( 'product' !== get_post_type( $product_id ) ) {
			return;
		}
		// WooCommerce reviews are stored as comment_type "review"; allow an empty type for compatible setups.
		if ( ! in_array( $comment->comment_type, array( 'review', '' ), true ) ) {
			return;
		}
		if ( get_comment_meta( $comment_id, '_revrew_rewarded', true ) ) {
			return;
		}

		$rating = (int) get_comment_meta( $comment_id, 'rating', true );
		if ( $rating < (int) self::opt( 'min_rating' ) ) {
			return;
		}
		$review_text = trim( wp_strip_all_tags( $comment->comment_content ) );
		$text_length = function_exists( 'mb_strlen' ) ? mb_strlen( $review_text ) : strlen( $review_text );
		if ( $text_length < (int) self::opt( 'min_length' ) ) {
			return;
		}

		$email   = sanitize_email( $comment->comment_author_email );
		$user_id = (int) $comment->user_id;
		if ( ! $email && ! $user_id ) {
			return;
		}

		// BUG FIX 1: one reward per customer per product.
		// Always filter by user_id or email, so an empty email can never make
		// this query match every rewarded review on the product.
		$dup_args = array(
			'post_id'  => $product_id,
			'type'     => 'review',
			'meta_key' => '_revrew_rewarded', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'count'    => true,
		);
		if ( $user_id > 0 ) {
			$dup_args['user_id'] = $user_id;
		} else {
			$dup_args['author_email'] = $email;
		}
		if ( get_comments( $dup_args ) ) {
			return;
		}

		$order = $this->find_eligible_order( $user_id, $email, $product_id );
		if ( ! $order ) {
			return;
		}

		$recipient = $email ? $email : sanitize_email( $order->get_billing_email() );
		if ( ! $recipient ) {
			return;
		}
		$code = $this->create_coupon( $order, $recipient, $comment_id );
		if ( ! $code ) {
			return;
		}

		$order->update_meta_data( '_revrew_rewarded', $code );
		$order->add_order_note(
			sprintf(
				/* translators: 1: coupon code, 2: review (comment) ID */
				__( 'Review reward coupon %1$s issued for approved review #%2$d.', 'review-reward-for-woocommerce' ),
				$code,
				$comment_id
			)
		);
		$order->save();
		update_comment_meta( $comment_id, '_revrew_rewarded', $code );
		$this->email_reward( $order, $code, $recipient );
	}

	/**
	 * Pick the first order from a list that contains the product and is still eligible.
	 */
	private function pick_order( $orders, $product_id, $cutoff ) {
		foreach ( $orders as $order ) {
			if ( $order->get_meta( '_revrew_rewarded' ) ) {
				continue;
			}
			$completed = $order->get_date_completed();
			if ( $cutoff && $completed && $completed->getTimestamp() < $cutoff ) {
				continue;
			}
			if ( ! self::opt( 'allow_chain' ) && $this->used_reward_coupon( $order ) ) {
				continue;
			}
			foreach ( $order->get_items() as $item ) {
				if ( (int) $item->get_product_id() === (int) $product_id || (int) $item->get_variation_id() === (int) $product_id ) {
					return $order;
				}
			}
		}
		return null;
	}

	private function find_eligible_order( $user_id, $email, $product_id ) {
		$base = array(
			'status'  => array( 'wc-completed', 'completed' ),
			'limit'   => 100,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		);

		$window = (int) self::opt( 'window_days' );
		$cutoff = $window > 0 ? time() - ( $window * DAY_IN_SECONDS ) : 0;

		if ( $user_id > 0 ) {
			$order = $this->pick_order( wc_get_orders( $base + array( 'customer_id' => $user_id ) ), $product_id, $cutoff );
			if ( $order ) {
				return $order;
			}
		}

		// A guest review can come from a logged-in account or vice versa; fall back to billing email.
		if ( $email ) {
			return $this->pick_order( wc_get_orders( $base + array( 'billing_email' => $email ) ), $product_id, $cutoff );
		}
		return null;
	}

	private function used_reward_coupon( $order ) {
		foreach ( $order->get_coupon_codes() as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( $coupon->get_id() && $coupon->get_meta( '_revrew_reward' ) ) {
				return true;
			}
		}
		return false;
	}

	private function create_coupon( $order, $email, $comment_id ) {
		$code = '';
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$candidate = 'THANKS-' . strtoupper( wp_generate_password( 8, false, false ) );
			if ( ! wc_get_coupon_id_by_code( $candidate ) ) {
				$code = $candidate;
				break;
			}
		}
		if ( '' === $code ) {
			return false;
		}

		try {
			$is_fixed = 'fixed' === self::opt( 'reward_type' );
			$coupon   = new WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( $is_fixed ? 'fixed_cart' : 'percent' );
			$coupon->set_amount( $is_fixed ? (float) self::opt( 'fixed_amount' ) : 100 );
			$coupon->set_individual_use( true );
			$coupon->set_usage_limit( 1 );
			$coupon->set_usage_limit_per_user( 1 );
			$coupon->set_email_restrictions( array( $email ) );
			$coupon->set_free_shipping( (bool) self::opt( 'free_shipping' ) );
			/* translators: %s: order number */
			$coupon->set_description( sprintf( __( 'Review reward for order #%s', 'review-reward-for-woocommerce' ), $order->get_order_number() ) );
			if ( ! $is_fixed ) {
				$coupon->add_meta_data( '_revrew_max_value', (float) self::opt( 'max_value' ), true );
			}
			$days = (int) self::opt( 'expiry_days' );
			if ( $days > 0 ) {
				$coupon->set_date_expires( time() + ( $days * DAY_IN_SECONDS ) );
			}
			$coupon->add_meta_data( '_revrew_reward', 1, true );
			$coupon->add_meta_data( '_revrew_order_id', $order->get_id(), true );
			$coupon->add_meta_data( '_revrew_comment_id', $comment_id, true );
			$coupon_id = $coupon->save();
			return $coupon_id ? $code : false;
		} catch ( Throwable $error ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Review reward coupon creation failed: ' . $error->getMessage(), array( 'source' => 'review-reward-for-woocommerce' ) );
			}
			return false;
		}
	}

	public function validate_max_value( $valid, $coupon ) {
		if ( ! $valid || ! $coupon instanceof WC_Coupon || ! WC()->cart ) {
			return $valid;
		}
		$max = (float) $coupon->get_meta( '_revrew_max_value' );
		if ( $max > 0 && (float) WC()->cart->get_subtotal() > $max ) {
			throw new Exception(
				sprintf(
					/* translators: %s: maximum order value */
					esc_html__( 'This reward coupon is valid on orders up to %s. Please reduce your cart value.', 'review-reward-for-woocommerce' ),
					esc_html( wp_strip_all_tags( wc_price( $max ) ) )
				),
				100
			);
		}
		return $valid;
	}

	private function email_reward( $order, $code, $recipient ) {
		$coupon      = new WC_Coupon( $code );
		$expiry_date = $coupon->get_date_expires();
		$expiry      = $expiry_date ? wc_format_datetime( $expiry_date ) : __( 'never', 'review-reward-for-woocommerce' );
		$max         = (float) $coupon->get_meta( '_revrew_max_value' );

		if ( 'fixed_cart' === $coupon->get_discount_type() ) {
			/* translators: %s: discount amount */
			$what = sprintf( __( '%s off', 'review-reward-for-woocommerce' ), wp_strip_all_tags( wc_price( $coupon->get_amount() ) ) );
		} elseif ( $max > 0 ) {
			/* translators: %s: maximum order value */
			$what = sprintf( __( '100%% off eligible cart items (up to %s)', 'review-reward-for-woocommerce' ), wp_strip_all_tags( wc_price( $max ) ) );
		} else {
			$what = __( '100% off eligible cart items', 'review-reward-for-woocommerce' );
		}

		/* translators: %s: customer first name */
		$body  = '<p>' . sprintf( esc_html__( 'Hi %s,', 'review-reward-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) ) . '</p>';
		/* translators: %s: reward description */
		$body .= '<p>' . sprintf( esc_html__( 'Thank you for your approved product review! Your reward is %s on an eligible order.', 'review-reward-for-woocommerce' ), '<strong>' . esc_html( $what ) . '</strong>' ) . '</p>';
		$body .= '<p style="font-size:20px"><strong>' . esc_html( $code ) . '</strong></p>';
		/* translators: %s: expiry date */
		$body .= '<p>' . sprintf( esc_html__( 'Expires: %s. Single use and valid for this email address only.', 'review-reward-for-woocommerce' ), esc_html( $expiry ) ) . '</p>';
		$body .= '<p><a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'Shop now', 'review-reward-for-woocommerce' ) . '</a></p>';

		$this->send( $recipient, __( 'Your review reward coupon is here', 'review-reward-for-woocommerce' ), __( 'Thanks for your review!', 'review-reward-for-woocommerce' ), $body );
	}

	public function send_reminders() {
		if ( ! self::opt( 'enabled' ) ) {
			return;
		}
		$days = (int) self::opt( 'remind_days' );
		if ( $days <= 0 ) {
			return;
		}

		$window = (int) self::opt( 'window_days' );
		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		// BUG FIX 2: never fall back to "all time". With no review window,
		// only orders from the last MAX_REMINDER_AGE_DAYS days are reminded.
		$age_days = $window > 0 ? $window : self::MAX_REMINDER_AGE_DAYS;
		$oldest   = time() - ( $age_days * DAY_IN_SECONDS );
		if ( $oldest >= $cutoff ) {
			return; // Window is shorter than the reminder delay; nothing can qualify.
		}

		// BUG FIX 3: filter at query level so already-handled orders never
		// fill the 100-order batch and block newer orders.
		$orders = wc_get_orders(
			array(
				'status'         => array( 'wc-completed', 'completed' ),
				'limit'          => 100,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'return'         => 'objects',
				'date_completed' => $oldest . '...' . $cutoff,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_revrew_reminder_sent',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_revrew_rewarded',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $orders as $order ) {
			// Orders that cannot be reminded are marked as handled so they
			// do not stay in the queue forever.
			if ( ! self::opt( 'allow_chain' ) && $this->used_reward_coupon( $order ) ) {
				$this->mark_reminded( $order );
				continue;
			}
			$email = sanitize_email( $order->get_billing_email() );
			if ( ! $email ) {
				$this->mark_reminded( $order );
				continue;
			}
			$items = array();
			foreach ( $order->get_items() as $item ) {
				$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				$url        = get_permalink( $product_id );
				if ( $url ) {
					$items[] = '<li><a href="' . esc_url( $url . '#reviews' ) . '">' . esc_html( $item->get_name() ) . '</a></li>';
				}
			}
			if ( empty( $items ) ) {
				$this->mark_reminded( $order );
				continue;
			}

			/* translators: %s: customer first name */
			$body  = '<p>' . sprintf( esc_html__( 'Hi %s,', 'review-reward-for-woocommerce' ), esc_html( $order->get_billing_first_name() ) ) . '</p>';
			/* translators: %s: order number */
			$body .= '<p>' . sprintf( esc_html__( 'Enjoying your purchase? Leave a review for an item from order #%s to earn a reward coupon for a future order:', 'review-reward-for-woocommerce' ), esc_html( $order->get_order_number() ) ) . '</p>';
			$body .= '<ul>' . implode( '', $items ) . '</ul>';

			$this->send( $email, __( 'Reminder: review your purchase and earn a reward', 'review-reward-for-woocommerce' ), __( 'Leave a review, earn a reward', 'review-reward-for-woocommerce' ), $body );
			$this->mark_reminded( $order );
		}
	}

	private function mark_reminded( $order ) {
		$order->update_meta_data( '_revrew_reminder_sent', time() );
		$order->save();
	}

	private function send( $to, $subject, $heading, $body ) {
		if ( ! $to || ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return;
		}
		$mailer = WC()->mailer();
		$mailer->send( $to, $subject, $mailer->wrap_message( $heading, $body ) );
	}
}

Revrew_Review_Reward::init();
