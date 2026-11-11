<?php
defined( 'ABSPATH' ) || exit;

/**
 * Complimentary (free) tickets – e.g. competition winners.
 *
 * Creates a WooCommerce order with the custom status "Complimentary" (wc-fss-comp). Invoicing plugins and
 * WooCommerce customer emails react to processing/completed, not to this status, so no (0 Ft) invoice is issued.
 * The attendees are synced to pretix exactly like paid ones (pass + lecture registration right).
 */
class FSS_Pretix_Comp {

	const STATUS = 'fss-comp';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_status' ) );
		add_action( 'admin_post_fss_pretix_comp', array( __CLASS__, 'handle' ) );
	}

	public static function register_status() {
		register_post_status( 'wc-' . self::STATUS, array(
			'label'                     => 'Complimentary',
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop( 'Complimentary <span class="count">(%s)</span>', 'Complimentary <span class="count">(%s)</span>' ),
		) );
	}

	public static function add_status( $statuses ) {
		$statuses[ 'wc-' . self::STATUS ] = 'Complimentary';
		return $statuses;
	}

	/* ---------- admin form (rendered on the tools page) ---------- */

	public static function render_form() {
		$products = array_filter(
			wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'private' ) ) ),
			array( 'FSS_Pretix_Settings', 'is_ticket_product' )
		);
		echo '<h2>Complimentary tickets</h2>';
		echo '<p>For winners, guests, speakers etc. Creates an order with status <strong>Complimentary</strong> – no payment, no invoice, no WooCommerce customer email. Attendees get the same pass and emails as paying attendees.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'fss_pretix_comp' );
		echo '<input type="hidden" name="action" value="fss_pretix_comp">';
		echo '<table class="form-table" style="max-width:900px"><tr><th>Ticket type</th><td><select name="product_id" required>';
		foreach ( $products as $p ) {
			echo '<option value="' . (int) $p->get_id() . '">' . esc_html( $p->get_name() ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th>Reason / note</th><td><input type="text" name="note" style="width:100%" placeholder="e.g. Competition winner – LinkedIn giveaway" required></td></tr></table>';
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>#</th><th>Title</th><th>Given name *</th><th>Family name *</th><th>Email *</th></tr></thead><tbody>';
		for ( $i = 0; $i < 5; $i++ ) {
			echo '<tr><td>' . ( $i + 1 ) . '</td>';
			echo '<td><input type="text" name="att[' . $i . '][title]" style="width:70px"></td>';
			echo '<td><input type="text" name="att[' . $i . '][given_name]"></td>';
			echo '<td><input type="text" name="att[' . $i . '][family_name]"></td>';
			echo '<td><input type="email" name="att[' . $i . '][email]" style="width:100%"></td></tr>';
		}
		echo '</tbody></table><p class="description">Leave unused rows empty. Each attendee needs their own email address.</p>';
		echo '<p><button class="button button-primary" onclick="return confirm(\'Create complimentary tickets and email the attendees?\')">Create complimentary tickets</button></p></form>';
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'fss_pretix_comp' );

		$product = wc_get_product( isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0 );
		$note    = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$rows    = isset( $_POST['att'] ) && is_array( $_POST['att'] ) ? wp_unslash( $_POST['att'] ) : array(); // phpcs:ignore

		$attendees = array();
		$seen      = array();
		$errors    = array();
		foreach ( $rows as $i => $r ) {
			$a = array(
				'title'       => sanitize_text_field( isset( $r['title'] ) ? $r['title'] : '' ),
				'given_name'  => sanitize_text_field( isset( $r['given_name'] ) ? $r['given_name'] : '' ),
				'family_name' => sanitize_text_field( isset( $r['family_name'] ) ? $r['family_name'] : '' ),
				'email'       => strtolower( sanitize_email( isset( $r['email'] ) ? $r['email'] : '' ) ),
				'status'      => 'new',
			);
			if ( ! $a['given_name'] && ! $a['family_name'] && ! $a['email'] ) {
				continue; // empty row
			}
			$n = (int) $i + 1;
			if ( ! $a['given_name'] || ! $a['family_name'] || ! is_email( $a['email'] ) ) {
				$errors[] = "Row $n: given name, family name and a valid email are required.";
				continue;
			}
			if ( isset( $seen[ $a['email'] ] ) ) {
				$errors[] = "Row $n: email already used in row {$seen[$a['email']]}.";
				continue;
			}
			$seen[ $a['email'] ] = $n;
			$attendees[]         = $a;
		}
		if ( ! $product || ! FSS_Pretix_Settings::is_ticket_product( $product ) ) {
			$errors[] = 'Select an FSS ticket product.';
		}
		if ( ! $attendees ) {
			$errors[] = 'Enter at least one attendee.';
		}
		if ( $errors ) {
			wp_safe_redirect( add_query_arg( 'fss_msg', rawurlencode( 'Nothing created. ' . implode( ' ', $errors ) ), admin_url( 'admin.php?page=fss-pretix' ) ) );
			exit;
		}

		$order = wc_create_order( array( 'created_via' => 'fss-complimentary' ) );
		$item_id = $order->add_product( $product, count( $attendees ), array( 'subtotal' => 0, 'total' => 0 ) );
		$item    = $order->get_item( $item_id );
		$item->add_meta_data( FSS_Pretix_Attendees::ITEM_META, array_values( $attendees ), true );
		$item->save();

		$first = $attendees[0];
		$order->set_billing_first_name( $first['given_name'] );
		$order->set_billing_last_name( $first['family_name'] );
		$order->set_billing_email( $first['email'] );
		$order->update_meta_data( FSS_Pretix_Attendees::ORDER_FLAG, 'yes' );
		$order->update_meta_data( '_fss_complimentary', $note );
		$order->calculate_totals( false );
		$order->set_status( self::STATUS, 'Complimentary ticket – ' . $note . ' (created by ' . wp_get_current_user()->user_login . ').' );
		$order->save();

		FSS_Pretix_Sync::enqueue_order( $order->get_id() );

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}
}
