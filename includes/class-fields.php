<?php
defined( 'ABSPATH' ) || exit;

/**
 * Custom checkout fields, defined by the admin (Settings → Pretix Sync → Checkout fields) instead of being
 * hard-coded for one event.
 *
 * A field applies either to the whole order (asked once) or to every attendee (asked per ticket). One text field can
 * be marked "Print on badge": its value is written to the pretix badge ticket ("Attendee company" element).
 * Values: order-level in order meta "_pretix_sync_field_<key>", attendee-level in the attendee record under "custom".
 */
class Pretix_Sync_Fields {

	const OPTION     = 'pretix_sync_custom_fields';
	const META       = '_pretix_sync_field_';
	const BADGE_META = '_pretix_sync_badge_text'; // Order-level badge text (checkout field or complimentary form).
	const MAX_FIELDS = 20;

	/** Keys that are already used in the attendee record. */
	const RESERVED = array( 'title', 'given_name', 'family_name', 'email', 'status', 'error', 'custom' );

	public static function types() {
		return array(
			'text'     => __( 'Text', 'pretix-sync' ),
			'textarea' => __( 'Long text', 'pretix-sync' ),
			'select'   => __( 'Choice list', 'pretix-sync' ),
			'checkbox' => __( 'Checkbox', 'pretix-sync' ),
		);
	}

	public static function scopes() {
		return array(
			'order'    => __( 'Whole order (asked once)', 'pretix-sync' ),
			'attendee' => __( 'Each attendee', 'pretix-sync' ),
		);
	}

	/** @return array<int, array{key: string, label: string, type: string, scope: string, required: bool, badge: bool, options: string[]}> */
	public static function all() {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $f ) {
			if ( ! is_array( $f ) || empty( $f['key'] ) || empty( $f['label'] ) ) {
				continue;
			}
			$out[] = array(
				'key'      => (string) $f['key'],
				'label'    => (string) $f['label'],
				'type'     => isset( $f['type'] ) && array_key_exists( $f['type'], self::types() ) ? $f['type'] : 'text',
				'scope'    => isset( $f['scope'] ) && 'attendee' === $f['scope'] ? 'attendee' : 'order',
				'required' => ! empty( $f['required'] ),
				'badge'    => ! empty( $f['badge'] ),
				'options'  => isset( $f['options'] ) ? array_values( (array) $f['options'] ) : array(),
			);
		}
		return $out;
	}

	public static function of_scope( $scope ) {
		return array_values( array_filter( self::all(), function ( $f ) use ( $scope ) {
			return $f['scope'] === $scope;
		} ) );
	}

	public static function badge_field() {
		foreach ( self::all() as $f ) {
			if ( $f['badge'] ) {
				return $f;
			}
		}
		return null;
	}

	/** Label / option text in the visitor's language (Polylang string translation; otherwise as typed). */
	public static function tr( $text, $lang = null ) {
		return Pretix_Sync_I18n::translate( $text, null === $lang ? Pretix_Sync_I18n::current_lang() : $lang );
	}

	/** Texts that Polylang should offer for translation. */
	public static function register_strings() {
		foreach ( self::all() as $f ) {
			pll_register_string( 'Checkout field: ' . $f['key'], $f['label'], Pretix_Sync_I18n::GROUP );
			foreach ( $f['options'] as $i => $o ) {
				pll_register_string( 'Checkout field ' . $f['key'] . ' option ' . ( $i + 1 ), $o, Pretix_Sync_I18n::GROUP );
			}
		}
	}

	/* ---------- checkout ---------- */

	private static function post_value( $path ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verifies the checkout nonce.
		$node = isset( $_POST['pretix_sync_fields'] ) ? wp_unslash( $_POST['pretix_sync_fields'] ) : null; // phpcs:ignore
		foreach ( $path as $p ) {
			if ( ! is_array( $node ) || ! isset( $node[ $p ] ) ) {
				return '';
			}
			$node = $node[ $p ];
		}
		return is_array( $node ) ? '' : $node;
	}

	/** Cleaned posted value of a field. $path: array( 'o', key ) for order fields, array( 'a', cart key, index, key ) otherwise. */
	public static function posted( $f, $path ) {
		$v = self::post_value( $path );
		switch ( $f['type'] ) {
			case 'checkbox':
				return '' !== (string) $v && '0' !== (string) $v ? 'yes' : '';
			case 'textarea':
				return sanitize_textarea_field( $v );
			case 'select':
				$v = sanitize_text_field( $v );
				return in_array( $v, $f['options'], true ) ? $v : '';
			default:
				return sanitize_text_field( $v );
		}
	}

	/** Prints one input; $name is the full field name. */
	public static function render_input( $f, $name, $value, $class = 'form-row-wide' ) {
		$args = array(
			'type'     => $f['type'],
			'label'    => self::tr( $f['label'] ),
			'required' => $f['required'],
			'class'    => array( $class ),
		);
		if ( 'select' === $f['type'] ) {
			$args['options'] = array( '' => __( 'Please choose…', 'pretix-sync' ) );
			foreach ( $f['options'] as $o ) {
				$args['options'][ $o ] = self::tr( $o );
			}
		}
		if ( 'checkbox' === $f['type'] ) {
			$value = 'yes' === $value ? '1' : '';
		}
		woocommerce_form_field( $name, $args, $value );
	}

	public static function render_order_fields() {
		$fields = self::of_scope( 'order' );
		if ( ! $fields ) {
			return;
		}
		echo '<div class="pretix-sync-order-fields" style="margin:0 0 1.2em">';
		foreach ( $fields as $f ) {
			self::render_input( $f, 'pretix_sync_fields[o][' . $f['key'] . ']', self::posted( $f, array( 'o', $f['key'] ) ) );
		}
		echo '</div>';
	}

	public static function render_attendee_fields( $cart_key, $i ) {
		foreach ( self::of_scope( 'attendee' ) as $f ) {
			self::render_input( $f, 'pretix_sync_fields[a][' . $cart_key . '][' . $i . '][' . $f['key'] . ']', self::posted( $f, array( 'a', $cart_key, $i, $f['key'] ) ) );
		}
	}

	private static function missing( $f, $value ) {
		return $f['required'] && '' === trim( (string) $value );
	}

	public static function validate_order_fields( $errors ) {
		foreach ( self::of_scope( 'order' ) as $f ) {
			if ( self::missing( $f, self::posted( $f, array( 'o', $f['key'] ) ) ) ) {
				/* translators: %s: field label */
				$errors->add( 'pretix_sync_field', sprintf( __( '%s is required.', 'pretix-sync' ), self::tr( $f['label'] ) ) );
			}
		}
	}

	public static function validate_attendee_fields( $errors, $attendee_label, $cart_key, $i ) {
		foreach ( self::of_scope( 'attendee' ) as $f ) {
			if ( self::missing( $f, self::posted( $f, array( 'a', $cart_key, $i, $f['key'] ) ) ) ) {
				/* translators: 1: attendee label, 2: field label */
				$errors->add( 'pretix_sync_field', sprintf( __( '%1$s: %2$s is required.', 'pretix-sync' ), $attendee_label, self::tr( $f['label'] ) ) );
			}
		}
	}

	/** Attendee-level values of one posted attendee, for the attendee record. */
	public static function attendee_values( $cart_key, $i ) {
		$out = array();
		foreach ( self::of_scope( 'attendee' ) as $f ) {
			$out[ $f['key'] ] = self::posted( $f, array( 'a', $cart_key, $i, $f['key'] ) );
		}
		return $out;
	}

	/** Stores the order-level values on the order (and the badge text, when the badge field is order-level). */
	public static function save_order_fields( $order ) {
		$badge = self::badge_field();
		foreach ( self::of_scope( 'order' ) as $f ) {
			$v = self::posted( $f, array( 'o', $f['key'] ) );
			$order->update_meta_data( self::META . $f['key'], $v );
			if ( $badge && $badge['key'] === $f['key'] ) {
				$order->update_meta_data( self::BADGE_META, $v );
			}
		}
	}

	/* ---------- reading (admin, export, badge) ---------- */

	/** Text for the badge: the attendee's own value when the badge field is per attendee, else the order's. */
	public static function badge_text( $order, $attendee = array() ) {
		$badge = self::badge_field();
		if ( $badge && 'attendee' === $badge['scope'] && isset( $attendee['custom'][ $badge['key'] ] ) ) {
			return trim( (string) $attendee['custom'][ $badge['key'] ] );
		}
		return trim( (string) $order->get_meta( self::BADGE_META ) );
	}

	/** Human readable value (translated choice, Yes for a ticked checkbox). */
	public static function display_value( $f, $v, $lang = null ) {
		if ( 'checkbox' === $f['type'] ) {
			return 'yes' === $v ? __( 'Yes', 'pretix-sync' ) : '';
		}
		return 'select' === $f['type'] ? self::tr( (string) $v, $lang ) : (string) $v;
	}

	public static function order_value( $order, $f ) {
		return (string) $order->get_meta( self::META . $f['key'] );
	}

	/** Value of any field for one attendee row (attendee-level from the record, order-level from the order). */
	public static function value_for( $order, $attendee, $f ) {
		if ( 'attendee' === $f['scope'] ) {
			return isset( $attendee['custom'][ $f['key'] ] ) ? (string) $attendee['custom'][ $f['key'] ] : '';
		}
		return self::order_value( $order, $f );
	}

	/* ---------- admin editor ---------- */

	private static function row( $idx, $f ) {
		$n = 'pretix_sync_cf[' . $idx . ']';
		echo '<tr class="pretix-sync-cf-row">';
		echo '<td><input type="text" name="' . esc_attr( $n ) . '[label]" value="' . esc_attr( $f['label'] ) . '" style="width:100%">';
		echo '<input type="hidden" name="' . esc_attr( $n ) . '[key]" value="' . esc_attr( $f['key'] ) . '">';
		echo '<small style="color:#646970">' . ( '' !== $f['key'] ? esc_html( $f['key'] ) : '' ) . '</small></td>';
		echo '<td><select name="' . esc_attr( $n ) . '[type]">';
		foreach ( self::types() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['type'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td><td><select name="' . esc_attr( $n ) . '[scope]">';
		foreach ( self::scopes() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['scope'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td>';
		echo '<td style="text-align:center"><input type="checkbox" name="' . esc_attr( $n ) . '[required]" value="1"' . checked( $f['required'], true, false ) . '></td>';
		echo '<td style="text-align:center"><input type="radio" name="pretix_sync_cf_badge" value="' . esc_attr( (string) $idx ) . '"' . checked( $f['badge'], true, false ) . '></td>';
		echo '<td><textarea name="' . esc_attr( $n ) . '[options]" rows="2" style="width:100%" placeholder="' . esc_attr__( 'One choice per line', 'pretix-sync' ) . '">' . esc_textarea( implode( "\n", $f['options'] ) ) . '</textarea></td>';
		echo '</tr>';
	}

	public static function render_editor() {
		$blank = array( 'key' => '', 'label' => '', 'type' => 'text', 'scope' => 'order', 'required' => false, 'badge' => false, 'options' => array() );
		$rows  = self::all();
		echo '<h2>' . esc_html__( 'Checkout fields', 'pretix-sync' ) . '</h2>';
		echo '<p>' . esc_html__( 'Extra questions at the checkout, besides the attendee title, name and email. A field applies to the whole order (asked once) or to each attendee. The field chosen under “Print on badge” is written to the badge ticket in the passes event (pretix “Attendee company” element). Clear the label of a field and save to remove it.', 'pretix-sync' ) . '</p>';
		echo '<table class="widefat" style="max-width:1000px" id="pretix-sync-cf"><thead><tr><th>' . esc_html__( 'Label', 'pretix-sync' ) . '</th><th>' . esc_html__( 'Type', 'pretix-sync' ) . '</th><th>' . esc_html__( 'Applies to', 'pretix-sync' ) . '</th><th>' . esc_html__( 'Required', 'pretix-sync' ) . '</th><th>' . esc_html__( 'Print on badge', 'pretix-sync' ) . '</th><th>' . esc_html__( 'Choices', 'pretix-sync' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $i => $f ) {
			self::row( $i, $f );
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="pretix-sync-cf-add">' . esc_html__( 'Add field', 'pretix-sync' ) . '</button> <label style="margin-left:1em"><input type="radio" name="pretix_sync_cf_badge" value="-1"' . checked( null === self::badge_field(), true, false ) . '> ' . esc_html__( 'Nothing on the badge', 'pretix-sync' ) . '</label></p>';
		echo '<table style="display:none"><tbody id="pretix-sync-cf-tpl">';
		self::row( '__i__', $blank );
		echo '</tbody></table>';
		// Adds a blank row; indexes only need to be unique within the form.
		echo '<script>(function(){var b=document.getElementById("pretix-sync-cf-add");if(!b){return;}var n=' . (int) count( $rows ) . '+100;b.addEventListener("click",function(){var t=document.getElementById("pretix-sync-cf-tpl").innerHTML.replace(/__i__/g,String(n++));document.querySelector("#pretix-sync-cf tbody").insertAdjacentHTML("beforeend",t);});})();</script>';
	}

	private static function make_key( $label, $taken ) {
		$base = str_replace( '-', '_', sanitize_title( $label ) );
		$base = '' === $base ? 'field' : substr( $base, 0, 40 );
		$key  = $base;
		$n    = 2;
		while ( in_array( $key, $taken, true ) || in_array( $key, self::RESERVED, true ) ) {
			$key = $base . '_' . $n++;
		}
		return $key;
	}

	/** Saves the editor rows. Called after WooCommerce has verified the settings nonce. */
	public static function save_from_post() {
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( ! isset( $_POST['pretix_sync_cf'] ) || ! is_array( $_POST['pretix_sync_cf'] ) ) {
			return;
		}
		$rows      = wp_unslash( $_POST['pretix_sync_cf'] );
		$badge_idx = isset( $_POST['pretix_sync_cf_badge'] ) ? (string) wp_unslash( $_POST['pretix_sync_cf_badge'] ) : '-1';
		// phpcs:enable
		$out   = array();
		$taken = array();
		foreach ( $rows as $idx => $r ) {
			$label = isset( $r['label'] ) ? sanitize_text_field( $r['label'] ) : '';
			if ( '' === $label || count( $out ) >= self::MAX_FIELDS ) {
				continue;
			}
			$type = isset( $r['type'] ) && array_key_exists( $r['type'], self::types() ) ? $r['type'] : 'text';
			$key  = isset( $r['key'] ) ? sanitize_key( $r['key'] ) : '';
			if ( '' === $key || in_array( $key, $taken, true ) || in_array( $key, self::RESERVED, true ) ) {
				$key = self::make_key( $label, $taken );
			}
			$taken[]  = $key;
			$options  = array();
			if ( 'select' === $type && isset( $r['options'] ) ) {
				foreach ( preg_split( '/\r\n|\r|\n/', (string) $r['options'] ) as $line ) {
					$line = sanitize_text_field( $line );
					if ( '' !== $line && ! in_array( $line, $options, true ) ) {
						$options[] = $line;
					}
				}
			}
			$out[] = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'scope'    => isset( $r['scope'] ) && 'attendee' === $r['scope'] ? 'attendee' : 'order',
				'required' => ! empty( $r['required'] ),
				// Only a free text value makes sense on a badge.
				'badge'    => (string) $idx === $badge_idx && in_array( $type, array( 'text', 'select' ), true ),
				'options'  => $options,
			);
		}
		update_option( self::OPTION, $out );
	}
}
