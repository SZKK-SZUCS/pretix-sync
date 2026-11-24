<?php
defined( 'ABSPATH' ) || exit;

/**
 * Minimal pretix REST API client.
 *
 * Every method returns the decoded JSON array on success or a WP_Error.
 * WP_Error codes: 'pretix_sync_http_<status>' (data: status, body) or 'pretix_sync_network'.
 */
class Pretix_Sync_API {

	private static function request( $method, $path, $body = null, $query = array() ) {
		$token = Pretix_Sync_Settings::token();
		if ( ! $token ) {
			return new WP_Error( 'pretix_sync_config', 'PRETIX_SYNC_TOKEN is not defined in wp-config.php' );
		}
		if ( '' === Pretix_Sync_Settings::base_url() || '' === (string) Pretix_Sync_Settings::get( 'organizer' ) ) {
			return new WP_Error( 'pretix_sync_config', 'pretix base URL and organizer slug are not set (WooCommerce → Settings → Pretix Sync).' );
		}
		$url = Pretix_Sync_Settings::base_url() . '/api/v1/' . ltrim( $path, '/' );
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Token ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pretix_sync_network', $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $data ) ? $data : array();
		}
		pretix_sync_log( sprintf( '%s %s → %d %s', $method, $path, $status, $raw ), 'warning' );
		return new WP_Error( 'pretix_sync_http_' . $status, sprintf( 'pretix API %s %s returned %d: %s', $method, $path, $status, wp_strip_all_tags( substr( $raw, 0, 500 ) ) ), array( 'status' => $status, 'body' => $data ) );
	}

	/** True if the error is worth retrying later (network, 429, 5xx). */
	public static function is_transient( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return false;
		}
		if ( 'pretix_sync_network' === $error->get_error_code() ) {
			return true;
		}
		$data = $error->get_error_data();
		$s    = isset( $data['status'] ) ? (int) $data['status'] : 0;
		return 429 === $s || $s >= 500;
	}

	private static function org() {
		return 'organizers/' . rawurlencode( Pretix_Sync_Settings::get( 'organizer' ) ) . '/';
	}

	/* ---------- connection test ---------- */

	public static function test() {
		// Each call checks one permission the plugin needs (the membership type itself is not readable
		// without organizer "Settings" access, so it is not queried).
		$checks = array(
			'Customers (organizer)'      => array( self::org() . 'customers/', array( 'email' => 'connection-test@invalid.invalid' ) ),
			'Memberships (organizer)'    => array( self::org() . 'memberships/', array( 'membership_type' => (string) (int) Pretix_Sync_Settings::get( 'membership_type' ) ) ),
			'Reusable media (organizer)' => array( self::org() . 'reusablemedia/', array( 'type' => 'barcode' ) ),
			'Sessions event'             => array( self::ev(), array() ),
			'Orders (sessions event)'    => array( self::ev() . 'orders/', array( 'code' => 'XXXXX' ) ),
		);
		if ( ! Pretix_Sync_Settings::sessions_enabled() ) {
			unset( $checks['Customers (organizer)'], $checks['Memberships (organizer)'], $checks['Sessions event'], $checks['Orders (sessions event)'] );
		}
		if ( Pretix_Sync_Settings::passes_enabled() ) {
			$checks['Passes event']            = array( self::pev(), array() );
			$checks['Orders (passes event)']   = array( self::pev() . 'orders/', array( 'code' => 'XXXXX' ) );
		}
		$result = array();
		foreach ( $checks as $label => $c ) {
			$r = self::request( 'GET', $c[0], null, $c[1] );
			if ( is_wp_error( $r ) ) {
				$hint = '';
				if ( 'pretix_sync_http_403' === $r->get_error_code() || 'pretix_sync_http_404' === $r->get_error_code() ) {
					// Which organizer does this token actually belong to?
					$orgs = self::request( 'GET', 'organizers/' );
					if ( ! is_wp_error( $orgs ) ) {
						$slugs = wp_list_pluck( isset( $orgs['results'] ) ? $orgs['results'] : array(), 'slug' );
						$hint  = ' | The token belongs to organizer(s): ' . ( $slugs ? implode( ', ', $slugs ) : 'none' ) . ' (configured: ' . Pretix_Sync_Settings::get( 'organizer' ) . ')';
					}
				}
				return new WP_Error( $r->get_error_code(), $label . ': ' . $r->get_error_message() . $hint, $r->get_error_data() );
			}
			$result[ $label ] = $r;
		}
		foreach ( array( 'Sessions event', 'Passes event' ) as $k ) {
			if ( isset( $result[ $k ] ) ) {
				$result['event'] = $result[ $k ];
				break;
			}
		}
		return $result;
	}

	/* ---------- customers ---------- */

	public static function find_customer_by_email( $email ) {
		$r = self::request( 'GET', self::org() . 'customers/', null, array( 'email' => $email ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return ! empty( $r['results'] ) ? $r['results'][0] : null;
	}

	public static function create_customer( $email, $name_parts, $send_email = true, $locale = '' ) {
		return self::request( 'POST', self::org() . 'customers/', array(
			'email'      => $email,
			'name_parts' => $name_parts,
			'locale'     => '' !== $locale ? $locale : Pretix_Sync_Settings::get( 'locale' ),
			'send_email' => (bool) $send_email,
		) );
	}

	/* ---------- memberships ---------- */

	public static function create_membership( $customer_id, $name_parts ) {
		return self::request( 'POST', self::org() . 'memberships/', array(
			'customer'            => $customer_id,
			'membership_type'     => (int) Pretix_Sync_Settings::get( 'membership_type' ),
			'date_start'          => Pretix_Sync_Settings::get( 'valid_from' ),
			'date_end'            => Pretix_Sync_Settings::get( 'valid_until' ),
			'attendee_name_parts' => $name_parts,
			'testmode'            => Pretix_Sync_Settings::is_testmode(),
		) );
	}

	public static function update_membership( $id, $data ) {
		return self::request( 'PATCH', self::org() . 'memberships/' . (int) $id . '/', $data );
	}

	/**
	 * Memberships cannot be deleted or canceled via the API ("You can change the date instead").
	 * Moving date_end before date_start makes it invalid for every session.
	 */
	public static function revoke_membership( $id ) {
		$start = new DateTime( Pretix_Sync_Settings::get( 'valid_from' ) );
		$start->modify( '-1 second' );
		return self::update_membership( $id, array( 'date_end' => $start->format( 'c' ) ) );
	}

	/* ---------- reusable media (the pass QR) ---------- */

	public static function generate_identifier() {
		// Same alphabet pretix uses for barcode media: no 0/O/1/I.
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$out   = '';
		for ( $i = 0; $i < 16; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $out;
	}

	public static function create_medium( $label ) {
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$r = self::request( 'POST', self::org() . 'reusablemedia/', array(
				'type'                   => 'barcode',
				'identifier'             => self::generate_identifier(),
				'active'                 => true,
				'label'                  => mb_substr( $label, 0, 200 ),
				'linked_orderpositions'  => array(),
			) );
			// Retry only on identifier collision.
			if ( is_wp_error( $r ) ) {
				$d = $r->get_error_data();
				if ( isset( $d['body']['identifier'] ) ) {
					continue;
				}
			}
			return $r;
		}
		return $r;
	}

	public static function get_medium( $id ) {
		return self::request( 'GET', self::org() . 'reusablemedia/' . (int) $id . '/' );
	}

	public static function update_medium( $id, $data ) {
		return self::request( 'PATCH', self::org() . 'reusablemedia/' . (int) $id . '/', $data );
	}

	public static function find_media_by_customer( $customer_id ) {
		$r = self::request( 'GET', self::org() . 'reusablemedia/', null, array(
			'customer' => $customer_id,
			'type'     => 'barcode',
			'active'   => 'true',
		) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return isset( $r['results'] ) ? $r['results'] : array();
	}

	/* ---------- session registrations (orders in the sessions event) ---------- */

	private static function ev() {
		return self::org() . 'events/' . rawurlencode( Pretix_Sync_Settings::get( 'sessions_event' ) ) . '/';
	}

	public static function get_order( $event_slug, $code ) {
		return self::request( 'GET', self::org() . 'events/' . rawurlencode( $event_slug ) . '/orders/' . rawurlencode( $code ) . '/', null, array( 'include_canceled_positions' => 'true' ) );
	}

	public static function list_orders( $query = array() ) {
		return self::request( 'GET', self::ev() . 'orders/', null, $query );
	}

	/* ---------- pass tickets (orders in the passes event, used for badge printing) ---------- */

	private static function pev() {
		return self::org() . 'events/' . rawurlencode( Pretix_Sync_Settings::get( 'passes_event' ) ) . '/';
	}

	/**
	 * Body of a free, paid, silent order with one position (pass ticket / all-access ticket).
	 *
	 * The email keys are left out when there is no address: pass-only attendees (e.g. staff) may have none, and
	 * pretix documents both as optional. send_email is always false - pretix must never mail these orders.
	 *
	 * @param string $company Team name; pretix prints it on the badge via the "Attendee company" element.
	 */
	private static function free_order_body( $email, $item_id, $name_parts, $comment, $company ) {
		$position = array(
			'item'                => (int) $item_id,
			'attendee_name_parts' => $name_parts,
		);
		$body     = array(
			'locale'     => Pretix_Sync_Settings::get( 'locale' ),
			'status'     => 'p',
			'testmode'   => Pretix_Sync_Settings::is_testmode(),
			'send_email' => false,
			'force'      => true,
			'comment'    => $comment,
		);
		if ( '' !== $email ) {
			$position['attendee_email'] = $email;
			$body['email']              = $email;
		}
		if ( '' !== $company ) {
			$position['company'] = $company;
		}
		$body['positions'] = array( $position );
		return $body;
	}

	public static function create_pass_order( $email, $item_id, $name_parts, $comment, $company = '' ) {
		return self::request( 'POST', self::pev() . 'orders/', self::free_order_body( $email, $item_id, $name_parts, $comment, $company ) );
	}

	/**
	 * All-access ticket in the SESSIONS event (e.g. staff). It is not bought in the shop: the item is voucher-only
	 * and has no sales channel, and the order API does not check either. It carries no customer account; the plugin
	 * links its position to the attendee's pass directly, so the pass is accepted on the session check-in lists.
	 */
	public static function create_all_access_order( $email, $item_id, $name_parts, $comment, $company = '' ) {
		return self::request( 'POST', self::ev() . 'orders/', self::free_order_body( $email, $item_id, $name_parts, $comment, $company ) );
	}

	public static function update_all_access_position( $position_id, $data ) {
		return self::request( 'PATCH', self::ev() . 'orderpositions/' . (int) $position_id . '/', $data );
	}

	public static function cancel_all_access_order( $code ) {
		return self::request( 'POST', self::ev() . 'orders/' . rawurlencode( $code ) . '/mark_canceled/', array( 'send_email' => false ) );
	}

	public static function update_pass_position( $position_id, $data ) {
		return self::request( 'PATCH', self::pev() . 'orderpositions/' . (int) $position_id . '/', $data );
	}

	public static function cancel_pass_order( $code ) {
		return self::request( 'POST', self::pev() . 'orders/' . rawurlencode( $code ) . '/mark_canceled/', array( 'send_email' => false ) );
	}

	public static function cancel_order( $code ) {
		return self::request( 'POST', self::ev() . 'orders/' . rawurlencode( $code ) . '/mark_canceled/', array( 'send_email' => true ) );
	}
}
