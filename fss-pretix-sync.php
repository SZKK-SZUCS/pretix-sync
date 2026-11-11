<?php
/**
 * Plugin Name:       FSS pretix sync
 * Description:       Connects WooCommerce ticket sales with pretix: per-attendee checkout fields, pretix customer accounts, memberships and one reusable QR pass per attendee for lecture check-in.
 * Version:           1.2.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * Author:            FSS
 * Text Domain:       fss-pretix-sync
 */

defined( 'ABSPATH' ) || exit;

define( 'FSS_PRETIX_VERSION', '1.2.0' );
define( 'FSS_PRETIX_FILE', __FILE__ );
define( 'FSS_PRETIX_DIR', plugin_dir_path( __FILE__ ) );
define( 'FSS_PRETIX_AS_GROUP', 'fss-pretix' );

// HPOS + cart/checkout blocks compatibility declaration.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		// Attendee fields are rendered on the classic (shortcode) checkout only.
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
	}
} );

require_once FSS_PRETIX_DIR . 'includes/class-settings.php';
require_once FSS_PRETIX_DIR . 'includes/class-api.php';
require_once FSS_PRETIX_DIR . 'includes/class-attendees.php';
require_once FSS_PRETIX_DIR . 'includes/class-checkout.php';
require_once FSS_PRETIX_DIR . 'includes/class-mailer.php';
require_once FSS_PRETIX_DIR . 'includes/class-sync.php';
require_once FSS_PRETIX_DIR . 'includes/class-webhook.php';
require_once FSS_PRETIX_DIR . 'includes/class-admin.php';
require_once FSS_PRETIX_DIR . 'includes/class-comp.php';

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>FSS pretix sync requires WooCommerce.</p></div>';
		} );
		return;
	}
	FSS_Pretix_Settings::init();
	FSS_Pretix_Checkout::init();
	FSS_Pretix_Sync::init();
	FSS_Pretix_Webhook::init();
	FSS_Pretix_Admin::init();
	FSS_Pretix_Comp::init();
} );

register_activation_hook( __FILE__, function () {
	if ( ! get_option( 'fss_pretix_webhook_secret' ) ) {
		update_option( 'fss_pretix_webhook_secret', wp_generate_password( 40, false, false ) );
	}
	// Pre-flag the known FSS ticket products (PROFESSIONAL, FS TEAM MEMBER, test ticket) once.
	if ( ! get_option( 'fss_pretix_products_flagged' ) ) {
		foreach ( array( 1759, 1757, 2707 ) as $product_id ) {
			if ( 'product' === get_post_type( $product_id ) ) {
				update_post_meta( $product_id, '_fss_pretix_ticket', 'yes' );
			}
		}
		update_option( 'fss_pretix_products_flagged', 'yes' );
	}
} );

/**
 * Log helper (WooCommerce → Status → Logs → source "fss-pretix").
 */
function fss_pretix_log( $message, $level = 'info', $context = array() ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, is_string( $message ) ? $message : wp_json_encode( $message ), array_merge( array( 'source' => 'fss-pretix' ), $context ) );
	}
}

/**
 * Queue an Action Scheduler job unless the same hook+args is already pending.
 * (Action Scheduler's own "unique" flag ignores the arguments, so it can't be used per order.)
 */
function fss_pretix_enqueue( $hook, $args ) {
	if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, FSS_PRETIX_AS_GROUP ) ) {
		return;
	}
	as_enqueue_async_action( $hook, $args, FSS_PRETIX_AS_GROUP );
}
