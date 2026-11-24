<?php
defined( 'ABSPATH' ) || exit;

/**
 * Classic checkout: one attendee block (title, given name, family name, email) per pretix ticket in the cart.
 */
class Pretix_Sync_Checkout {

	public static function init() {
		add_action( 'woocommerce_checkout_after_customer_details', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'save_item' ), 10, 4 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'flag_order' ), 10, 2 );

		// Show attendees on thank-you page, My account and WooCommerce emails.
		add_action( 'woocommerce_order_item_meta_end', array( __CLASS__, 'display_item' ), 10, 4 );
	}

	/** Cart items that are pretix tickets: cart_item_key => [product name, qty]. */
	private static function ticket_cart_items() {
		$out = array();
		if ( ! WC()->cart ) {
			return $out;
		}
		foreach ( WC()->cart->get_cart() as $key => $ci ) {
			if ( Pretix_Sync_Settings::is_ticket_product( $ci['data'] ) ) {
				$out[ $key ] = array(
					'name' => $ci['data']->get_name(),
					'qty'  => (int) $ci['quantity'],
				);
			}
		}
		return $out;
	}

	private static function posted( $key, $i, $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verifies the checkout nonce.
		if ( isset( $_POST['pretix_sync_attendee'][ $key ][ $i ][ $field ] ) ) {
			$v = wp_unslash( $_POST['pretix_sync_attendee'][ $key ][ $i ][ $field ] ); // phpcs:ignore
			return 'email' === $field ? sanitize_email( $v ) : sanitize_text_field( $v );
		}
		return '';
	}

	public static function render() {
		$items = self::ticket_cart_items();
		if ( ! $items ) {
			return;
		}
		echo '<div id="pretix-sync-attendees" class="pretix-sync-attendees" style="margin:2em 0">';
		echo '<h3>' . esc_html__( 'Attendee details', 'pretix-sync' ) . '</h3>';
		echo '<p>' . ( Pretix_Sync_Settings::sessions_enabled()
			? esc_html__( 'Please enter the details of each attendee. Every attendee receives their own email with their pass and the session registration link.', 'pretix-sync' )
			: esc_html__( 'Please enter the details of each attendee. Every attendee receives their own email with their pass.', 'pretix-sync' )
		) . '</p>';

		$n = 0;
		foreach ( $items as $key => $info ) {
			for ( $i = 0; $i < $info['qty']; $i++ ) {
				$n++;
				echo '<fieldset style="border:1px solid #ddd;padding:1em 1.2em;margin:0 0 1em">';
				/* translators: 1: attendee number, 2: ticket type */
				echo '<legend style="padding:0 .4em;font-weight:600">' . esc_html( sprintf( __( 'Attendee %1$d – %2$s', 'pretix-sync' ), $n, $info['name'] ) ) . '</legend>';
				$base = 'pretix_sync_attendee[' . $key . '][' . $i . ']';
				woocommerce_form_field( $base . '[title]', array(
					'type'  => 'text',
					'label' => __( 'Title', 'pretix-sync' ),
					'class' => array( 'form-row-first' ),
					'placeholder' => __( 'e.g. Dr.', 'pretix-sync' ),
				), self::posted( $key, $i, 'title' ) );
				echo '<div class="clear"></div>';
				woocommerce_form_field( $base . '[given_name]', array(
					'type'     => 'text',
					'label'    => __( 'Given name', 'pretix-sync' ),
					'required' => true,
					'class'    => array( 'form-row-first' ),
				), self::posted( $key, $i, 'given_name' ) );
				woocommerce_form_field( $base . '[family_name]', array(
					'type'     => 'text',
					'label'    => __( 'Family name', 'pretix-sync' ),
					'required' => true,
					'class'    => array( 'form-row-last' ),
				), self::posted( $key, $i, 'family_name' ) );
				woocommerce_form_field( $base . '[email]', array(
					'type'     => 'email',
					'label'    => __( 'Email address', 'pretix-sync' ),
					'required' => true,
					'class'    => array( 'form-row-wide' ),
				), self::posted( $key, $i, 'email' ) );
				echo '</fieldset>';
			}
		}
		echo '</div>';
	}

	public static function validate( $data, $errors ) {
		$items = self::ticket_cart_items();
		if ( ! $items ) {
			return;
		}
		$seen = array();
		$n    = 0;
		foreach ( $items as $key => $info ) {
			for ( $i = 0; $i < $info['qty']; $i++ ) {
				$n++;
				$given  = self::posted( $key, $i, 'given_name' );
				$family = self::posted( $key, $i, 'family_name' );
				$email  = strtolower( self::posted( $key, $i, 'email' ) );
				/* translators: %d: attendee number */
				$label = sprintf( __( 'Attendee %d', 'pretix-sync' ), $n );

				if ( '' === $given || '' === $family ) {
					/* translators: %s: attendee label */
					$errors->add( 'pretix_sync_attendee', sprintf( __( '%s: please enter the given name and family name.', 'pretix-sync' ), $label ) );
				}
				if ( ! is_email( $email ) ) {
					/* translators: %s: attendee label */
					$errors->add( 'pretix_sync_attendee', sprintf( __( '%s: please enter a valid email address.', 'pretix-sync' ), $label ) );
				} elseif ( isset( $seen[ $email ] ) ) {
					/* translators: 1: attendee label, 2: other attendee label */
					$errors->add( 'pretix_sync_attendee', sprintf( __( '%1$s: this email address is already used for %2$s. Every attendee needs their own email address.', 'pretix-sync' ), $label, $seen[ $email ] ) );
				} else {
					$seen[ $email ] = $label;
				}
			}
		}
	}

	public static function save_item( $item, $cart_item_key, $values, $order ) {
		if ( ! Pretix_Sync_Settings::is_ticket_product( $values['data'] ) ) {
			return;
		}
		$list = array();
		for ( $i = 0; $i < (int) $values['quantity']; $i++ ) {
			$list[ $i ] = array(
				'title'       => self::posted( $cart_item_key, $i, 'title' ),
				'given_name'  => self::posted( $cart_item_key, $i, 'given_name' ),
				'family_name' => self::posted( $cart_item_key, $i, 'family_name' ),
				'email'       => strtolower( self::posted( $cart_item_key, $i, 'email' ) ),
				'status'      => 'new',
			);
		}
		$item->add_meta_data( Pretix_Sync_Attendees::ITEM_META, $list, true );
	}

	public static function flag_order( $order, $data ) {
		foreach ( self::ticket_cart_items() as $info ) {
			$order->update_meta_data( Pretix_Sync_Attendees::ORDER_FLAG, 'yes' );
			Pretix_Sync_I18n::stamp_order( $order );
			return;
		}
	}

	public static function display_item( $item_id, $item, $order, $plain_text = false ) {
		$list = $item->get_meta( Pretix_Sync_Attendees::ITEM_META );
		if ( ! is_array( $list ) || ! $list ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Attendees:', 'pretix-sync' ) . "\n";
			foreach ( $list as $a ) {
				echo '- ' . esc_html( Pretix_Sync_Attendees::full_name( $a ) . ' <' . $a['email'] . '>' ) . "\n";
			}
			return;
		}
		echo '<div class="pretix-sync-attendee-list" style="margin-top:.5em"><strong>' . esc_html__( 'Attendees:', 'pretix-sync' ) . '</strong><ul style="margin:.2em 0 0 1.2em">';
		foreach ( $list as $a ) {
			echo '<li>' . esc_html( Pretix_Sync_Attendees::full_name( $a ) ) . ' – ' . esc_html( $a['email'] ) . '</li>';
		}
		echo '</ul></div>';
	}
}
