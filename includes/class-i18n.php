<?php
defined( 'ABSPATH' ) || exit;

/**
 * Two languages (English / Hungarian) with or without Polylang.
 *
 * - Fixed texts (checkout, default email texts) use the plugin text domain; a Hungarian .mo ships in /languages.
 * - Texts an admin edits (email templates, event name) are registered as Polylang strings, so they can be translated
 *   under Languages → Translations (group "Pretix Sync"). Without Polylang the stored text is used as it is.
 * - Every order remembers the language it was placed in, so emails and the pretix account use that language even
 *   though they are sent from a background job (which runs in the site language).
 */
class Pretix_Sync_I18n {

	const ORDER_LANG   = '_pretix_sync_lang';   // Polylang language slug ('' without Polylang).
	const ORDER_LOCALE = '_pretix_sync_locale'; // WordPress locale, e.g. hu_HU.
	const GROUP        = 'Pretix Sync';

	public static function init() {
		self::load_textdomain();
		add_action( 'init', array( __CLASS__, 'register_strings' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'pretix-sync', false, dirname( plugin_basename( PRETIX_SYNC_FILE ) ) . '/languages' );
	}

	/* ---------- Polylang ---------- */

	public static function polylang_active() {
		return function_exists( 'pll_register_string' ) && function_exists( 'pll_current_language' ) && function_exists( 'pll_languages_list' );
	}

	/** Polylang for WooCommerce is what makes products, orders and emails language-aware. */
	public static function wc_addon_active() {
		return defined( 'PLLWC_VERSION' ) || function_exists( 'PLLWC' );
	}

	/** @return array<string, array{name: string, locale: string}> slug => name, locale (empty without Polylang) */
	public static function languages() {
		if ( ! self::polylang_active() ) {
			return array();
		}
		$slugs   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
		$names   = (array) pll_languages_list( array( 'fields' => 'name' ) );
		$locales = (array) pll_languages_list( array( 'fields' => 'locale' ) );
		$out     = array();
		foreach ( array_values( $slugs ) as $i => $slug ) {
			$out[ $slug ] = array(
				'name'   => isset( $names[ $i ] ) ? $names[ $i ] : $slug,
				'locale' => isset( $locales[ $i ] ) ? $locales[ $i ] : '',
			);
		}
		return $out;
	}

	public static function default_lang() {
		return self::polylang_active() && function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';
	}

	public static function current_lang() {
		if ( ! self::polylang_active() ) {
			return '';
		}
		$slug = pll_current_language( 'slug' );
		return $slug ? (string) $slug : self::default_lang();
	}

	/** Is this post a translation of a product in the default language (not the source itself)? */
	public static function is_translation( $post_id ) {
		if ( ! self::polylang_active() || ! function_exists( 'pll_get_post_language' ) ) {
			return false;
		}
		$lang = pll_get_post_language( $post_id );
		return $lang && self::default_lang() && $lang !== self::default_lang();
	}

	/** The default-language twin of a post (the post itself without Polylang or when none exists). */
	public static function source_post_id( $post_id ) {
		if ( self::polylang_active() && function_exists( 'pll_get_post' ) && self::default_lang() ) {
			$source = pll_get_post( $post_id, self::default_lang() );
			if ( $source ) {
				return (int) $source;
			}
		}
		return (int) $post_id;
	}

	/* ---------- order language ---------- */

	/** Remembers the language of an order. $lang = Polylang slug, empty = the current front-end language. */
	public static function stamp_order( $order, $lang = '' ) {
		$lang      = '' !== $lang ? $lang : self::current_lang();
		$languages = self::languages();
		$locale    = isset( $languages[ $lang ] ) && $languages[ $lang ]['locale'] ? $languages[ $lang ]['locale'] : determine_locale();
		$order->update_meta_data( self::ORDER_LANG, $lang );
		$order->update_meta_data( self::ORDER_LOCALE, $locale );
	}

	/** @return array{lang: string, locale: string} language the order's emails go out in. */
	public static function order_language( $order ) {
		$lang   = (string) $order->get_meta( self::ORDER_LANG );
		$locale = (string) $order->get_meta( self::ORDER_LOCALE );
		if ( '' === $locale ) {
			$lang   = self::default_lang();
			$all    = self::languages();
			$locale = isset( $all[ $lang ] ) && $all[ $lang ]['locale'] ? $all[ $lang ]['locale'] : get_locale();
		}
		return array( 'lang' => $lang, 'locale' => $locale );
	}

	/**
	 * Switches WordPress to the order's language (reloading the plugin's translation file).
	 *
	 * @return bool true if restore() must be called afterwards
	 */
	public static function switch_to( $order ) {
		$ctx = self::order_language( $order );
		if ( ! $ctx['locale'] || ! function_exists( 'switch_to_locale' ) ) {
			return false;
		}
		$switched = switch_to_locale( $ctx['locale'] );
		if ( $switched ) {
			self::load_textdomain();
		}
		return (bool) $switched;
	}

	public static function restore( $switched ) {
		if ( $switched && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
			self::load_textdomain();
		}
	}

	/** Translation of an admin-edited text (a Polylang string) into $lang; unchanged without Polylang. */
	public static function translate( $text, $lang ) {
		if ( self::polylang_active() && '' !== $lang && function_exists( 'pll_translate_string' ) ) {
			return pll_translate_string( $text, $lang );
		}
		return $text;
	}

	/* ---------- pretix ---------- */

	/** pretix customer-account language for an order: its own language when pretix supports it, else the setting. */
	public static function pretix_locale( $order ) {
		$ctx     = self::order_language( $order );
		$code    = strtolower( substr( $ctx['locale'], 0, 2 ) );
		$allowed = apply_filters( 'pretix_sync_pretix_locales', array( 'en', 'hu' ) );
		return in_array( $code, $allowed, true ) ? $code : Pretix_Sync_Settings::get( 'locale' );
	}

	/**
	 * Link into the pretix shop that first switches the shop to the order's language (pretix' own /locale/set).
	 * Filter "pretix_sync_link_locale" returns false to link straight to the page.
	 */
	public static function pretix_link( $url, $order ) {
		if ( ! apply_filters( 'pretix_sync_link_locale', true ) ) {
			return $url;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			return $url;
		}
		return add_query_arg(
			array(
				'locale' => self::pretix_locale( $order ),
				'next'   => $path,
			),
			Pretix_Sync_Settings::base_url() . '/locale/set'
		);
	}

	/* ---------- Polylang strings ---------- */

	/** Registers the admin-edited texts so they show up under Languages → Translations. */
	public static function register_strings() {
		if ( ! self::polylang_active() ) {
			return;
		}
		foreach ( array_keys( Pretix_Sync_Mailer::defaults() ) as $type ) {
			foreach ( array( 'subject', 'heading', 'body' ) as $field ) {
				$value = (string) get_option( 'pretix_sync_mail_' . $type . '_' . $field, '' );
				if ( '' !== trim( $value ) ) {
					pll_register_string( 'Email ' . $type . ': ' . $field, $value, self::GROUP, 'body' === $field );
				}
			}
		}
		$event = (string) get_option( 'pretix_sync_event_name', '' );
		if ( '' !== $event ) {
			pll_register_string( 'Event name', $event, self::GROUP );
		}
	}

	/* ---------- admin notice ---------- */

	public static function notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::polylang_active() || self::wc_addon_active() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Pretix Sync:</strong> Polylang is active, but <strong>Polylang for WooCommerce</strong> is not. For a bilingual shop install it: it translates products, orders and WooCommerce emails. Without it only the plugin\'s own texts (checkout fields, ticket emails) are bilingual, and the ticket flag must be set on the default-language product.</p></div>';
	}
}
