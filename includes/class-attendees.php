<?php
defined( 'ABSPATH' ) || exit;

/**
 * Attendee storage.
 *
 * Attendees live on the order line item as meta "_fss_attendees": a list of arrays with
 *   title, given_name, family_name, email            – entered at checkout
 *   medium_id, medium_identifier                      – phase 1 (pass QR)
 *   pass_order, pass_position, pass_linked, pass_company – badge ticket in the passes event; pass_company is the
 *                                                       team name last written to it (to detect changes)
 *   customer_id, customer_new, membership_id          – phase 2 (lecture registration right)
 *   status: new | pass | registered | revoked | error
 *   error                                             – last error message
 */
class FSS_Pretix_Attendees {

	const ITEM_META  = '_fss_attendees';
	const ORDER_FLAG = '_fss_pretix_has_attendees';

	/** @return array<int, array{item: WC_Order_Item_Product, index: int, data: array}> */
	public static function for_order( $order ) {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			$list = $item->get_meta( self::ITEM_META );
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $i => $a ) {
				$out[] = array( 'item' => $item, 'index' => (int) $i, 'data' => $a );
			}
		}
		return $out;
	}

	public static function key( $order, $item, $index ) {
		return $order->get_id() . ':' . $item->get_id() . ':' . $index;
	}

	public static function find( $order, $key ) {
		foreach ( self::for_order( $order ) as $row ) {
			if ( self::key( $order, $row['item'], $row['index'] ) === $key ) {
				return $row;
			}
		}
		return null;
	}

	public static function save( $item, $index, $data ) {
		$list = $item->get_meta( self::ITEM_META );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		$list[ $index ] = $data;
		$item->update_meta_data( self::ITEM_META, $list );
		$item->save();
	}

	public static function name_parts( $a ) {
		return array(
			'_scheme'     => 'title_given_family',
			'title'       => isset( $a['title'] ) ? $a['title'] : '',
			'given_name'  => $a['given_name'],
			'family_name' => $a['family_name'],
		);
	}

	/**
	 * Team name of the order, printed on the badge. It is an order-level custom field ("team_name", added by the
	 * Checkout Form plugin at checkout; the complimentary form sets the same key), not part of the attendee data.
	 */
	public static function team_name( $order ) {
		return trim( (string) $order->get_meta( 'team_name' ) );
	}

	public static function full_name( $a ) {
		return trim( implode( ' ', array_filter( array( isset( $a['title'] ) ? $a['title'] : '', $a['given_name'], $a['family_name'] ) ) ) );
	}

	public static function ticket_label( $item ) {
		return $item->get_name();
	}

	/** All order IDs that carry attendees. */
	public static function order_ids( $statuses = array( 'processing', 'completed', 'fss-comp' ) ) {
		return wc_get_orders( array(
			'limit'      => -1,
			'status'     => $statuses,
			'meta_key'   => self::ORDER_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
			'return'     => 'ids',
		) );
	}
}
