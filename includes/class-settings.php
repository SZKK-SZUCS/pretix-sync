<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings: WooCommerce → Settings → FSS pretix, plus the per-product "FSS ticket" flag.
 */
class FSS_Pretix_Settings {

	const PRODUCT_META = '_fss_pretix_ticket';
	const PASS_ITEM_META = '_fss_pretix_pass_item';

	public static function init() {
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'add_tab' ), 60 );
		add_action( 'woocommerce_settings_tabs_fss_pretix', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_update_options_fss_pretix', array( __CLASS__, 'save' ) );

		// Product flag.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'product_save' ) );
	}

	/* ---------- getters ---------- */

	public static function get( $key, $default = '' ) {
		$defaults = array(
			'base_url'           => 'https://pretix.sze.hu',
			'organizer'          => 'fss',
			'talks_event'        => 'fss2026-talks',
			'membership_type'    => '1',
			'valid_from'         => '2026-11-20T00:00:00+01:00',
			'valid_until'        => '2026-11-22T23:59:59+01:00',
			'locale'             => 'en',
			'testmode'           => 'no',
			'event_name'         => 'Formula Student Symposium 2026',
			'passes_event'       => '',
		);
		$value = get_option( 'fss_pretix_' . $key, null );
		if ( null === $value || '' === $value ) {
			return isset( $defaults[ $key ] ) ? $defaults[ $key ] : $default;
		}
		return $value;
	}

	/**
	 * API token lookup order: wp-config constant → environment variable → Docker secret file → plugin setting.
	 */
	public static function token() {
		return self::token_source()[1];
	}

	/** @return array{0:string,1:string} [source label, token] */
	public static function token_source() {
		if ( defined( 'FSS_PRETIX_TOKEN' ) && FSS_PRETIX_TOKEN ) {
			return array( 'wp-config.php constant', trim( FSS_PRETIX_TOKEN ) );
		}
		$env = getenv( 'FSS_PRETIX_TOKEN' );
		if ( $env ) {
			return array( 'environment variable FSS_PRETIX_TOKEN', trim( $env ) );
		}
		foreach ( array( '/run/secrets/fss_pretix_token', '/run/secrets/FSS_PRETIX_TOKEN' ) as $f ) {
			if ( @is_readable( $f ) ) { // phpcs:ignore
				$v = trim( (string) file_get_contents( $f ) ); // phpcs:ignore
				if ( $v ) {
					return array( 'Docker secret ' . basename( $f ), $v );
				}
			}
		}
		$opt = get_option( 'fss_pretix_api_token', '' );
		if ( $opt ) {
			return array( 'plugin setting', trim( $opt ) );
		}
		return array( '', '' );
	}

	public static function is_testmode() {
		return 'yes' === self::get( 'testmode' );
	}

	public static function registration_open() {
		return 'yes' === get_option( 'fss_pretix_registration_open', 'no' );
	}

	public static function base_url() {
		return untrailingslashit( self::get( 'base_url' ) );
	}

	public static function talks_url() {
		return self::base_url() . '/' . self::get( 'organizer' ) . '/' . self::get( 'talks_event' ) . '/';
	}

	public static function password_reset_url() {
		return self::base_url() . '/' . self::get( 'organizer' ) . '/account/pwreset';
	}

	public static function webhook_url() {
		return add_query_arg( 'secret', get_option( 'fss_pretix_webhook_secret' ), rest_url( 'fss-pretix/v1/webhook' ) );
	}

	/** Pass tickets (badge printing) are created only when a passes event is configured. */
	public static function passes_enabled() {
		return '' !== trim( (string) self::get( 'passes_event' ) );
	}

	/** pretix item ID of the pass product that belongs to a WooCommerce ticket product, or 0. */
	public static function pass_item_for( $product ) {
		if ( ! $product ) {
			return 0;
		}
		$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		return (int) get_post_meta( $id, self::PASS_ITEM_META, true );
	}

	public static function is_ticket_product( $product ) {
		if ( ! $product ) {
			return false;
		}
		$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		return 'yes' === get_post_meta( $id, self::PRODUCT_META, true );
	}

	/* ---------- settings tab ---------- */

	public static function add_tab( $tabs ) {
		$tabs['fss_pretix'] = 'FSS pretix';
		return $tabs;
	}

	private static function fields() {
		return array(
			array( 'title' => 'pretix connection', 'type' => 'title', 'desc' => 'API token source (first match wins): <code>FSS_PRETIX_TOKEN</code> constant in wp-config.php, <code>FSS_PRETIX_TOKEN</code> environment variable, Docker secret <code>fss_pretix_token</code>, or the field below. ' . ( self::token_source()[0] ? '<strong style="color:#008a20">Token found (' . esc_html( self::token_source()[0] ) . ').</strong>' : '<strong style="color:#d63638">Token missing.</strong>' ), 'id' => 'fss_pretix_conn' ),
			array( 'title' => 'API token (fallback)', 'id' => 'fss_pretix_api_token', 'type' => 'password', 'default' => '', 'css' => 'min-width:350px', 'desc' => 'Only used if no constant / environment variable / Docker secret is set. Stored in the database.' ),
			array( 'title' => 'pretix base URL', 'id' => 'fss_pretix_base_url', 'type' => 'text', 'default' => 'https://pretix.sze.hu', 'css' => 'min-width:350px' ),
			array( 'title' => 'Organizer slug', 'id' => 'fss_pretix_organizer', 'type' => 'text', 'default' => 'fss' ),
			array( 'title' => 'Lectures event slug', 'id' => 'fss_pretix_talks_event', 'type' => 'text', 'default' => 'fss2026-talks' ),
			array( 'title' => 'Passes event slug', 'id' => 'fss_pretix_passes_event', 'type' => 'text', 'default' => '', 'desc' => 'Optional. pretix event holding one 0 € pass ticket per attendee, for badge printing (Badges → QR code: Reusable Medium ID). Leave empty to disable. The pass item is set per product (Product → General → pretix pass item ID).' ),
			array( 'title' => 'Membership type ID', 'id' => 'fss_pretix_membership_type', 'type' => 'number', 'default' => '1' ),
			array( 'title' => 'Membership valid from', 'id' => 'fss_pretix_valid_from', 'type' => 'text', 'default' => '2026-11-20T00:00:00+01:00', 'desc' => 'ISO 8601 with offset. Must be before the first lecture validity window starts.' ),
			array( 'title' => 'Membership valid until', 'id' => 'fss_pretix_valid_until', 'type' => 'text', 'default' => '2026-11-22T23:59:59+01:00' ),
			array( 'title' => 'Customer account language', 'id' => 'fss_pretix_locale', 'type' => 'text', 'default' => 'en' ),
			array( 'title' => 'Event name (emails)', 'id' => 'fss_pretix_event_name', 'type' => 'text', 'default' => 'Formula Student Symposium 2026', 'css' => 'min-width:350px' ),
			array( 'title' => 'Test mode', 'id' => 'fss_pretix_testmode', 'type' => 'checkbox', 'default' => 'no', 'desc' => 'Create test-mode memberships (only usable when the pretix event is in test mode).' ),
			array( 'type' => 'sectionend', 'id' => 'fss_pretix_conn' ),
		);
	}

	public static function render() {
		woocommerce_admin_fields( self::fields() );
		echo '<h2>pretix webhook</h2><p>Create a webhook in pretix (Organizer → Webhooks) with this target URL:</p>';
		echo '<p><input type="text" readonly style="width:100%;max-width:900px" value="' . esc_attr( self::webhook_url() ) . '" onclick="this.select()"></p>';
		echo '<p>Events: <em>Order placed, Order marked as paid, Order canceled, Order reactivated, Order changed (all), Order modified</em>. Limit it to the lectures event.</p>';
		echo '<p>Tools (open registration, pass export, connection test): <a href="' . esc_url( admin_url( 'admin.php?page=fss-pretix' ) ) . '">WooCommerce → FSS pretix</a></p>';
	}

	public static function save() {
		woocommerce_update_options( self::fields() );
	}

	/* ---------- product flag ---------- */

	public static function product_field() {
		echo '<div class="options_group">';
		woocommerce_wp_checkbox( array(
			'id'          => self::PRODUCT_META,
			'label'       => 'FSS ticket (pretix)',
			'description' => 'Ask for attendee details per ticket and create a pretix pass + lecture registration right for each attendee.',
		) );
		woocommerce_wp_text_input( array(
			'id'                => self::PASS_ITEM_META,
			'label'             => 'pretix pass item ID',
			'type'              => 'number',
			'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
			'desc_tip'          => true,
			'description'       => 'ID of the matching pass product in the pretix passes event (badge printing). Leave empty if this ticket needs no badge.',
		) );
		echo '</div>';
	}

	public static function product_save( $product ) {
		$product->update_meta_data( self::PRODUCT_META, isset( $_POST[ self::PRODUCT_META ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification
		$pass_item = isset( $_POST[ self::PASS_ITEM_META ] ) ? absint( $_POST[ self::PASS_ITEM_META ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$product->update_meta_data( self::PASS_ITEM_META, $pass_item ? (string) $pass_item : '' );
	}
}
