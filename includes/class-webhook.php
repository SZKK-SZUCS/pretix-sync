<?php
defined( 'ABSPATH' ) || exit;

/**
 * pretix webhook → keeps each attendee's pass (reusable medium) linked to their current lecture registrations.
 *
 * pretix does not attach web-shop registrations to an existing medium by itself, so on every change of an order
 * in the lectures event we recompute: medium.linked_orderpositions =
 *     (current links – all positions of this order) ∪ (active positions of this order).
 */
class FSS_Pretix_Webhook {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'fss_pretix_webhook_order', array( __CLASS__, 'process_order' ), 10, 3 );
		add_action( 'fss_pretix_reconcile_page', array( __CLASS__, 'reconcile_page' ), 10, 1 );
	}

	public static function routes() {
		register_rest_route( 'fss-pretix/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'receive' ),
			'permission_callback' => function ( WP_REST_Request $r ) {
				$secret = (string) get_option( 'fss_pretix_webhook_secret' );
				return $secret && hash_equals( $secret, (string) $r->get_param( 'secret' ) );
			},
		) );
	}

	public static function receive( WP_REST_Request $r ) {
		$p = $r->get_json_params();
		if ( empty( $p['event'] ) || empty( $p['code'] ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => 'no order' ), 200 );
		}
		if ( $p['event'] !== FSS_Pretix_Settings::get( 'talks_event' ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => 'other event' ), 200 );
		}
		// Payload only carries identifiers; the real state is fetched from the API when processing.
		as_enqueue_async_action( 'fss_pretix_webhook_order', array( sanitize_key( $p['event'] ), sanitize_text_field( $p['code'] ), 0 ), FSS_PRETIX_AS_GROUP ); // no dedupe: always process the latest state
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	public static function process_order( $event, $code, $attempt = 0 ) {
		$err = self::link_order( $event, $code );
		if ( is_wp_error( $err ) ) {
			if ( FSS_Pretix_API::is_transient( $err ) && $attempt < 4 ) {
				as_schedule_single_action( time() + 60 * ( $attempt + 1 ) * ( $attempt + 1 ), 'fss_pretix_webhook_order', array( $event, $code, $attempt + 1 ), FSS_PRETIX_AS_GROUP );
				return;
			}
			fss_pretix_log( "Linking registration $code to pass failed: " . $err->get_error_message(), 'error' );
			wp_mail( get_option( 'admin_email' ), '[FSS pretix] Could not link lecture registration ' . $code, $err->get_error_message() );
		}
	}

	/**
	 * @param array|null $order  already fetched order (reconcile) or null
	 * @return true|WP_Error
	 */
	public static function link_order( $event, $code, $order = null ) {
		if ( null === $order ) {
			$order = FSS_Pretix_API::get_order( $event, $code );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
		}
		if ( empty( $order['customer'] ) ) {
			fss_pretix_log( "Registration $code has no customer account – nothing to link.", 'warning' );
			return true;
		}

		$all    = array();
		$active = array();
		$order_active = in_array( $order['status'], array( 'p', 'n' ), true );
		foreach ( $order['positions'] as $pos ) {
			$all[] = (int) $pos['id'];
			if ( $order_active && empty( $pos['canceled'] ) ) {
				$active[] = (int) $pos['id'];
			}
		}

		$media = FSS_Pretix_API::find_media_by_customer( $order['customer'] );
		if ( is_wp_error( $media ) ) {
			return $media;
		}
		if ( ! $media ) {
			if ( $active ) {
				return new WP_Error( 'fss_no_pass', "Registration $code: customer {$order['customer']} has no active pass (reusable medium)." );
			}
			return true;
		}
		// One pass per person. If a person holds two tickets, the oldest active pass is used.
		usort( $media, function ( $x, $y ) {
			return (int) $x['id'] - (int) $y['id'];
		} );
		$medium_id = (int) $media[0]['id'];

		$r = self::update_links( $medium_id, $all, $active );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( $r ) {
			fss_pretix_log( sprintf( 'Pass %d: registration %s linked (%d active positions)', $medium_id, $code, count( $active ) ) );
		}
		return true;
	}

	/**
	 * Changes the positions linked to a pass: removes $remove, then adds $add. Serialized per medium with a
	 * MySQL named lock, because webhooks and syncs for the same person may run in parallel.
	 *
	 * @return bool|WP_Error  true if something changed
	 */
	public static function update_links( $medium_id, $remove, $add ) {
		global $wpdb;
		$lock = 'fss_pretix_medium_' . (int) $medium_id;
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 20)', $lock ) );
		try {
			$medium = FSS_Pretix_API::get_medium( $medium_id );
			if ( is_wp_error( $medium ) ) {
				return $medium;
			}
			$current = array();
			foreach ( (array) $medium['linked_orderpositions'] as $op ) {
				$current[] = (int) ( is_array( $op ) ? $op['id'] : $op );
			}
			$remove = array_map( 'intval', (array) $remove );
			$add    = array_map( 'intval', (array) $add );
			$target = array_values( array_unique( array_merge( array_diff( $current, $remove ), $add ) ) );
			sort( $target );
			$cmp = $current;
			sort( $cmp );
			if ( $target === $cmp ) {
				return false;
			}
			$r = FSS_Pretix_API::update_medium( $medium_id, array( 'linked_orderpositions' => $target ) );
			return is_wp_error( $r ) ? $r : true;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/* ---------- safety net: re-link every registration ---------- */

	public static function start_reconcile() {
		fss_pretix_enqueue( 'fss_pretix_reconcile_page', array( 1 ) );
	}

	public static function reconcile_page( $page ) {
		$list = FSS_Pretix_API::list_orders( array( 'page' => (string) (int) $page, 'include_canceled_positions' => 'true' ) );
		if ( is_wp_error( $list ) ) {
			fss_pretix_log( 'Reconcile page ' . $page . ' failed: ' . $list->get_error_message(), 'error' );
			return;
		}
		$event = FSS_Pretix_Settings::get( 'talks_event' );
		foreach ( $list['results'] as $o ) {
			$r = self::link_order( $event, $o['code'], $o );
			if ( is_wp_error( $r ) ) {
				fss_pretix_log( 'Reconcile ' . $o['code'] . ': ' . $r->get_error_message(), 'error' );
			}
		}
		if ( ! empty( $list['next'] ) ) {
			fss_pretix_enqueue( 'fss_pretix_reconcile_page', array( (int) $page + 1 ) );
		} else {
			update_option( 'fss_pretix_last_reconcile', time() );
		}
	}
}
