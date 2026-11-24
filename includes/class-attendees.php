<?php
defined( 'ABSPATH' ) || exit;

/**
 * Attendee storage.
 *
 * Attendees live on the order line item as meta "_pretix_sync_attendees": a list of arrays with
 *   title, given_name, family_name, email            – entered at checkout
 *   medium_id, medium_identifier                      – phase 1 (pass QR)
 *   custom                                            – values of the attendee-level custom checkout fields (key => value)
 *   pass_order, pass_position, pass_linked, pass_company – badge ticket in the passes event; pass_company is the
 *                                                       badge text last written to it (to detect changes)
 *   customer_id, customer_new, membership_id          – phase 2 (session registration right)
 *   all_access_order, all_access_position, all_access_linked, all_access_canceled
 *                                                     – free all-access ticket in the sessions event (staff), linked to the pass
 *   status: new | pass | passonly | registered | revoked | error   (passonly = finished pass-only attendee)
 *   error                                             – last error message
 */
class Pretix_Sync_Attendees {

	const ITEM_META  = '_pretix_sync_attendees';
	const ORDER_FLAG = '_pretix_sync_has_attendees';

	/** Order flag: "pass only" – pass and badge ticket only; no emails, no pretix account, no session registration. */
	const PASS_ONLY_META = '_pretix_sync_pass_only';

	public static function is_pass_only( $order ) {
		return 'yes' === $order->get_meta( self::PASS_ONLY_META );
	}

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

	/** Text printed on the badge: the checkout field marked "Print on badge", or the text of the complimentary form. */
	public static function badge_text( $order, $a = array() ) {
		return Pretix_Sync_Fields::badge_text( $order, $a );
	}

	public static function full_name( $a ) {
		return trim( implode( ' ', array_filter( array( isset( $a['title'] ) ? $a['title'] : '', $a['given_name'], $a['family_name'] ) ) ) );
	}

	public static function ticket_label( $item ) {
		return $item->get_name();
	}

	/** All order IDs that carry attendees. */
	public static function order_ids( $statuses = array( 'processing', 'completed', 'pretix-sync-comp' ) ) {
		return wc_get_orders( array(
			'limit'      => -1,
			'status'     => $statuses,
			'meta_key'   => self::ORDER_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery
			'return'     => 'ids',
		) );
	}
}
