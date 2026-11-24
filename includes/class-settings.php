<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings: WooCommerce → Settings → Pretix Sync, plus the per-product "pretix ticket" flag.
 */
class Pretix_Sync_Settings {

	const PRODUCT_META = '_pretix_sync_ticket';
	const PASS_ITEM_META = '_pretix_sync_pass_item';
	const ALL_ACCESS_ITEM_META = '_pretix_sync_all_access_item';

	public static function init() {
		add_filter( 'woocommerce_settings_tabs_array', array( __CLASS__, 'add_tab' ), 60 );
		add_action( 'woocommerce_settings_tabs_pretix_sync', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_update_options_pretix_sync', array( __CLASS__, 'save' ) );

		// Product flag.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'product_save' ) );
	}

	/* ---------- getters ---------- */

	public static function get( $key, $default = '' ) {
		$defaults = array(
			'base_url'           => '',
			'organizer'          => '',
			'sessions_event'     => '',
			'module_sessions'    => 'yes',
			'module_staff'       => 'yes',
			'membership_type'    => '',
			'valid_from'         => '',
			'valid_until'        => '',
			'locale'             => 'en',
			'testmode'           => 'no',
			'event_name'         => '',
			'passes_event'       => '',
		);
		$value = get_option( 'pretix_sync_' . $key, null );
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
		if ( defined( 'PRETIX_SYNC_TOKEN' ) && PRETIX_SYNC_TOKEN ) {
			return array( 'wp-config.php constant', trim( PRETIX_SYNC_TOKEN ) );
		}
		$env = getenv( 'PRETIX_SYNC_TOKEN' );
		if ( $env ) {
			return array( 'environment variable PRETIX_SYNC_TOKEN', trim( $env ) );
		}
		foreach ( array( '/run/secrets/pretix_sync_token', '/run/secrets/PRETIX_SYNC_TOKEN' ) as $f ) {
			if ( @is_readable( $f ) ) { // phpcs:ignore
				$v = trim( (string) file_get_contents( $f ) ); // phpcs:ignore
				if ( $v ) {
					return array( 'Docker secret ' . basename( $f ), $v );
				}
			}
		}
		$opt = get_option( 'pretix_sync_api_token', '' );
		if ( $opt ) {
			return array( 'plugin setting', trim( $opt ) );
		}
		return array( '', '' );
	}

	public static function is_testmode() {
		return 'yes' === self::get( 'testmode' );
	}

	/** Module: session registration (pretix customer account + membership + sessions event). Off = pass/ticket only. */
	public static function sessions_enabled() {
		return 'yes' === self::get( 'module_sessions' );
	}

	/** Module: complimentary / staff tickets and CSV import. */
	public static function staff_enabled() {
		return 'yes' === self::get( 'module_staff' );
	}

	public static function registration_open() {
		return self::sessions_enabled() && 'yes' === get_option( 'pretix_sync_registration_open', 'no' );
	}

	public static function base_url() {
		return untrailingslashit( self::get( 'base_url' ) );
	}

	public static function sessions_url() {
		return self::base_url() . '/' . self::get( 'organizer' ) . '/' . self::get( 'sessions_event' ) . '/';
	}

	public static function password_reset_url() {
		return self::base_url() . '/' . self::get( 'organizer' ) . '/account/pwreset';
	}

	public static function webhook_url() {
		return add_query_arg( 'secret', get_option( 'pretix_sync_webhook_secret' ), rest_url( 'pretix-sync/v1/webhook' ) );
	}

	/**
	 * Product whose meta holds the ticket settings. With Polylang the translations of a product share the settings of
	 * the default-language product, so they are entered once.
	 */
	private static function source_product_id( $product ) {
		$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		return Pretix_Sync_I18n::source_post_id( $id );
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
		$id = self::source_product_id( $product );
		return (int) get_post_meta( $id, self::PASS_ITEM_META, true );
	}

	/**
	 * pretix item ID of the all-access ticket in the SESSIONS event for a WooCommerce ticket product, or 0.
	 * Set only on products whose holders may enter every session (staff).
	 */
	public static function all_access_item_for( $product ) {
		if ( ! $product || ! self::sessions_enabled() ) {
			return 0;
		}
		$id = self::source_product_id( $product );
		return (int) get_post_meta( $id, self::ALL_ACCESS_ITEM_META, true );
	}

	public static function is_ticket_product( $product ) {
		if ( ! $product ) {
			return false;
		}
		$id = self::source_product_id( $product );
		return 'yes' === get_post_meta( $id, self::PRODUCT_META, true );
	}

	/* ---------- settings tab ---------- */

	public static function add_tab( $tabs ) {
		$tabs['pretix_sync'] = 'Pretix Sync';
		return $tabs;
	}

	private static function fields() {
		return array_merge( array(
			array( 'title' => 'pretix connection', 'type' => 'title', 'desc' => 'API token source (first match wins): <code>PRETIX_SYNC_TOKEN</code> constant in wp-config.php, <code>PRETIX_SYNC_TOKEN</code> environment variable, Docker secret <code>pretix_sync_token</code>, or the field below. ' . ( self::token_source()[0] ? '<strong style="color:#008a20">Token found (' . esc_html( self::token_source()[0] ) . ').</strong>' : '<strong style="color:#d63638">Token missing.</strong>' ), 'id' => 'pretix_sync_conn' ),
			array( 'title' => 'API token (fallback)', 'id' => 'pretix_sync_api_token', 'type' => 'password', 'default' => '', 'css' => 'min-width:350px', 'desc' => 'Only used if no constant / environment variable / Docker secret is set. Stored in the database.' ),
			array( 'title' => 'pretix base URL', 'id' => 'pretix_sync_base_url', 'type' => 'text', 'default' => '', 'placeholder' => 'https://pretix.example.com', 'css' => 'min-width:350px' ),
			array( 'title' => 'Organizer slug', 'id' => 'pretix_sync_organizer', 'type' => 'text', 'default' => '' ),
			array( 'title' => 'Sessions event slug', 'id' => 'pretix_sync_sessions_event', 'type' => 'text', 'default' => '' ),
			array( 'title' => 'Passes event slug', 'id' => 'pretix_sync_passes_event', 'type' => 'text', 'default' => '', 'desc' => 'Optional. pretix event holding one 0 € pass ticket per attendee, for badge printing (Badges → QR code: Reusable Medium ID). Leave empty to disable. The pass item is set per product (Product → General → pretix pass item ID).' ),
			array( 'title' => 'Membership type ID', 'id' => 'pretix_sync_membership_type', 'type' => 'number', 'default' => '' ),
			array( 'title' => 'Membership valid from', 'id' => 'pretix_sync_valid_from', 'type' => 'text', 'default' => '', 'placeholder' => '2027-01-01T00:00:00+01:00', 'desc' => 'ISO 8601 with offset. Must be before the first session validity window starts.' ),
			array( 'title' => 'Membership valid until', 'id' => 'pretix_sync_valid_until', 'type' => 'text', 'default' => '', 'placeholder' => '2027-01-03T23:59:59+01:00' ),
			array( 'title' => 'Customer account language (fallback)', 'id' => 'pretix_sync_locale', 'type' => 'text', 'default' => 'en', 'desc' => 'The pretix account gets the language of the order (English or Hungarian); this is used for any other language.' ),
			array( 'title' => 'Event name (emails)', 'id' => 'pretix_sync_event_name', 'type' => 'text', 'default' => '', 'css' => 'min-width:350px', 'desc' => 'Empty = the site name. With Polylang it is translated under Languages → Translations (group “Pretix Sync”).' ),
			array( 'title' => 'Test mode', 'id' => 'pretix_sync_testmode', 'type' => 'checkbox', 'default' => 'no', 'desc' => 'Create test-mode memberships (only usable when the pretix event is in test mode).' ),
			array( 'type' => 'sectionend', 'id' => 'pretix_sync_conn' ),

			array( 'title' => 'Modules', 'type' => 'title', 'desc' => 'The base is always on: attendee details at checkout, a pass (QR) per attendee and the ticket email. Switch on what this event needs.', 'id' => 'pretix_sync_modules' ),
			array( 'title' => 'Session registration', 'id' => 'pretix_sync_module_sessions', 'type' => 'checkbox', 'default' => 'yes', 'desc' => 'pretix customer account + membership per attendee and a sessions event they register in (with the webhook that links registrations to the pass).' ),
			array( 'title' => 'Complimentary / staff tickets', 'id' => 'pretix_sync_module_staff', 'type' => 'checkbox', 'default' => 'yes', 'desc' => 'Tools page: complimentary tickets, pass-only mode and CSV import. Badge printing is switched on by setting the Passes event slug above.' ),
			array( 'type' => 'sectionend', 'id' => 'pretix_sync_modules' ),
		), Pretix_Sync_Mailer::settings_fields() );
	}

	public static function render() {
		Pretix_Sync_Mailer::show_defaults();
		woocommerce_admin_fields( self::fields() );
		if ( self::sessions_enabled() ) {
		echo '<h2>pretix webhook</h2><p>Create a webhook in pretix (Organizer → Webhooks) with this target URL:</p>';
		echo '<p><input type="text" readonly style="width:100%;max-width:900px" value="' . esc_attr( self::webhook_url() ) . '" onclick="this.select()"></p>';
		echo '<p>Events: <em>Order placed, Order marked as paid, Order canceled, Order reactivated, Order changed (all), Order modified</em>. Limit it to the sessions event.</p>';
		}
		echo '<p>' . esc_html__( 'How every setting and the webhook work is explained under the Webhook section of the Pretix Sync page.', 'pretix-sync' ) . '</p>';
		echo '<p>Tools (open registration, pass export, connection test): <a href="' . esc_url( admin_url( 'admin.php?page=pretix-sync' ) ) . '">WooCommerce → Pretix Sync</a></p>';
	}

	public static function save() {
		Pretix_Sync_Mailer::store_defaults_as_empty();
		woocommerce_update_options( self::fields() );
	}

	/* ---------- product flag ---------- */

	public static function product_field() {
		global $post;
		if ( $post && Pretix_Sync_I18n::is_translation( $post->ID ) ) {
			echo '<div class="options_group"><p class="form-field"><strong>Pretix Sync:</strong> the ticket settings are kept on the default-language product.</p></div>';
			return;
		}
		echo '<div class="options_group">';
		woocommerce_wp_checkbox( array(
			'id'          => self::PRODUCT_META,
			'label'       => 'Pretix ticket',
			'description' => 'Ask for attendee details per ticket and create a pretix pass + session registration right for each attendee.',
		) );
		woocommerce_wp_text_input( array(
			'id'                => self::PASS_ITEM_META,
			'label'             => 'pretix pass item ID',
			'type'              => 'number',
			'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
			'desc_tip'          => true,
			'description'       => 'ID of the matching pass product in the pretix passes event (badge printing). Leave empty if this ticket needs no badge.',
		) );
		if ( self::sessions_enabled() ) {
		woocommerce_wp_text_input( array(
			'id'                => self::ALL_ACCESS_ITEM_META,
			'label'             => 'pretix all-access item ID',
			'type'              => 'number',
			'custom_attributes' => array( 'min' => '1', 'step' => '1' ),
			'desc_tip'          => true,
			'description'       => 'Staff only. ID of the all-access product in the pretix SESSIONS event. Each attendee gets a free ticket of it, linked to their pass, so the pass is accepted at every session (the product must be on the session check-in lists). Leave empty for normal tickets.',
		) );
		}
		echo '</div>';
	}

	public static function product_save( $product ) {
		if ( Pretix_Sync_I18n::is_translation( $product->get_id() ) ) {
			return; // settings live on the default-language product
		}
		$product->update_meta_data( self::PRODUCT_META, isset( $_POST[ self::PRODUCT_META ] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification
		$pass_item = isset( $_POST[ self::PASS_ITEM_META ] ) ? absint( $_POST[ self::PASS_ITEM_META ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$product->update_meta_data( self::PASS_ITEM_META, $pass_item ? (string) $pass_item : '' );
		if ( self::sessions_enabled() ) { // the field is only shown (and saved) with the sessions module
			$all_access = isset( $_POST[ self::ALL_ACCESS_ITEM_META ] ) ? absint( $_POST[ self::ALL_ACCESS_ITEM_META ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			$product->update_meta_data( self::ALL_ACCESS_ITEM_META, $all_access ? (string) $all_access : '' );
		}
	}
}
