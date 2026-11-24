<?php
/**
 * Plugin Name:       Pretix Sync
 * Description:       Connects WooCommerce ticket sales with pretix: per-attendee checkout fields, pretix customer accounts, memberships and one reusable QR pass per attendee for session check-in.
 * Version:           2.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * Author:            Széchenyi István University
 * Text Domain:       pretix-sync
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'PRETIX_SYNC_VERSION', '2.0.0' );
define( 'PRETIX_SYNC_FILE', __FILE__ );
define( 'PRETIX_SYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRETIX_SYNC_AS_GROUP', 'pretix-sync' );

// Composer autoloader (Plugin Update Checker). The release zip ships it; a source checkout needs `composer install`.
if ( file_exists( PRETIX_SYNC_DIR . 'vendor/autoload.php' ) ) {
	require_once PRETIX_SYNC_DIR . 'vendor/autoload.php';
}

// HPOS + cart/checkout blocks compatibility declaration.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		// Attendee fields are rendered on the classic (shortcode) checkout only.
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
	}
} );

require_once PRETIX_SYNC_DIR . 'includes/class-i18n.php';
require_once PRETIX_SYNC_DIR . 'includes/class-settings.php';
require_once PRETIX_SYNC_DIR . 'includes/class-api.php';
require_once PRETIX_SYNC_DIR . 'includes/class-attendees.php';
require_once PRETIX_SYNC_DIR . 'includes/class-checkout.php';
require_once PRETIX_SYNC_DIR . 'includes/class-mailer.php';
require_once PRETIX_SYNC_DIR . 'includes/class-sync.php';
require_once PRETIX_SYNC_DIR . 'includes/class-webhook.php';
require_once PRETIX_SYNC_DIR . 'includes/class-admin.php';
require_once PRETIX_SYNC_DIR . 'includes/class-comp.php';

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>Pretix Sync requires WooCommerce.</p></div>';
		} );
		return;
	}
	Pretix_Sync_I18n::init();
	Pretix_Sync_Settings::init();
	Pretix_Sync_Checkout::init();
	Pretix_Sync_Sync::init();
	Pretix_Sync_Webhook::init();
	Pretix_Sync_Admin::init();
	Pretix_Sync_Comp::init();
} );

register_activation_hook( __FILE__, function () {
	if ( ! get_option( 'pretix_sync_webhook_secret' ) ) {
		update_option( 'pretix_sync_webhook_secret', wp_generate_password( 40, false, false ) );
	}
} );

/**
 * Log helper (WooCommerce → Status → Logs → source "pretix-sync").
 */
function pretix_sync_log( $message, $level = 'info', $context = array() ) {
	if ( function_exists( 'wc_get_logger' ) ) {
		wc_get_logger()->log( $level, is_string( $message ) ? $message : wp_json_encode( $message ), array_merge( array( 'source' => 'pretix-sync' ), $context ) );
	}
}

/**
 * Queue an Action Scheduler job unless the same hook+args is already pending.
 * (Action Scheduler's own "unique" flag ignores the arguments, so it can't be used per order.)
 */
function pretix_sync_enqueue( $hook, $args ) {
	if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, PRETIX_SYNC_AS_GROUP ) ) {
		return;
	}
	as_enqueue_async_action( $hook, $args, PRETIX_SYNC_AS_GROUP );
}

// Self-update from the GitHub releases of this plugin (the release zip asset).
if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
	$pretix_sync_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/SZKK-SZUCS/pretix-sync',
		__FILE__,
		'pretix-sync'
	);
	$pretix_sync_update_checker->setBranch( 'main' );
	$pretix_sync_vcs_api = $pretix_sync_update_checker->getVcsApi();
	if ( method_exists( $pretix_sync_vcs_api, 'enableReleaseAssets' ) ) { // GitHub only; picks the release zip asset
		$pretix_sync_vcs_api->enableReleaseAssets( '/\.zip$/i' );
	}
}
