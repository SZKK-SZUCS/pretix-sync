<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → pretix synchronisation.
 *
 * Phase 1 (always, right after payment): one reusable medium (the pass QR) per attendee + "ticket" email.
 * Phase 2 (once lecture registration is open): pretix customer account + membership, medium linked to the
 *          customer, "open"/"welcome" email.
 * Every step stores its result immediately, so a re-run only does what is still missing.
 */
class Pretix_Sync_Sync {

	const MAX_ATTEMPTS = 5;

	public static function init() {
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'enqueue_order' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'enqueue_order' ) );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'enqueue_revoke_order' ) );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'enqueue_revoke_order' ) );

		add_action( 'pretix_sync_sync_order', array( __CLASS__, 'sync_order' ), 10, 2 );
		add_action( 'pretix_sync_revoke_order', array( __CLASS__, 'revoke_order' ), 10, 2 );
	}

	/* ---------- queueing ---------- */

	public static function enqueue_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' !== $order->get_meta( Pretix_Sync_Attendees::ORDER_FLAG ) ) {
			return;
		}
		pretix_sync_enqueue( 'pretix_sync_sync_order', array( (int) $order_id, 0 ) );
	}

	public static function enqueue_revoke_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'yes' !== $order->get_meta( Pretix_Sync_Attendees::ORDER_FLAG ) ) {
			return;
		}
		pretix_sync_enqueue( 'pretix_sync_revoke_order', array( (int) $order_id, 0 ) );
	}

	private static function retry( $hook, $order_id, $attempt ) {
		if ( $attempt + 1 >= self::MAX_ATTEMPTS ) {
			return false;
		}
		$delays = array( 60, 300, 900, 3600, 10800 );
		as_schedule_single_action( time() + $delays[ $attempt ], $hook, array( (int) $order_id, $attempt + 1 ), PRETIX_SYNC_AS_GROUP );
		return true;
	}

	/* ---------- sync ---------- */

	public static function sync_order( $order_id, $attempt = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! in_array( $order->get_status(), array( 'processing', 'completed', Pretix_Sync_Comp::STATUS ), true ) ) {
			return;
		}
		$open      = Pretix_Sync_Settings::registration_open();
		$transient = false;
		$errors    = array();
		$done      = array();

		foreach ( Pretix_Sync_Attendees::for_order( $order ) as $row ) {
			$a = $row['data'];
			if ( isset( $a['status'] ) && 'revoked' === $a['status'] ) {
				continue;
			}
			$err = self::sync_attendee( $order, $row['item'], $row['index'], $a, $open );
			if ( $err ) {
				if ( Pretix_Sync_API::is_transient( $err ) ) {
					$transient = true;
				} else {
					$errors[] = Pretix_Sync_Attendees::full_name( $a ) . ': ' . $err->get_error_message();
				}
			} else {
				$done[] = Pretix_Sync_Attendees::full_name( $a );
			}
		}

		if ( $done ) {
			$what = Pretix_Sync_Attendees::is_pass_only( $order ) ? 'pass only' : ( $open ? 'pass + lecture registration' : 'pass' );
			$order->add_order_note( sprintf( 'pretix: %s synced (%s): %s', $what, count( $done ), implode( ', ', $done ) ) );
		}
		if ( $errors ) {
			$order->add_order_note( "pretix sync ERROR:\n" . implode( "\n", $errors ) );
			self::notify_admin( $order, $errors );
		}
		if ( $transient ) {
			if ( self::retry( 'pretix_sync_sync_order', $order_id, $attempt ) ) {
				$order->add_order_note( 'pretix temporarily unreachable – sync will be retried automatically.' );
			} else {
				$order->add_order_note( 'pretix sync failed after several attempts. Use "Re-run pretix sync" on this order.' );
				self::notify_admin( $order, array( 'pretix unreachable after ' . self::MAX_ATTEMPTS . ' attempts' ) );
			}
		}
	}

	/**
	 * @return WP_Error|null
	 */
	public static function sync_attendee( $order, $item, $index, &$a, $open ) {
		$save = function () use ( $item, $index, &$a ) {
			Pretix_Sync_Attendees::save( $item, $index, $a );
		};
		$fail = function ( $err ) use ( &$a, $save ) {
			$a['error'] = $err->get_error_message();
			if ( ! Pretix_Sync_API::is_transient( $err ) ) {
				$a['status'] = 'error';
			}
			$save();
			return $err;
		};
		unset( $a['error'] );

		// Phase 1: the pass.
		if ( empty( $a['medium_id'] ) ) {
			$m = Pretix_Sync_API::create_medium( Pretix_Sync_Attendees::full_name( $a ) . ' (#' . $order->get_order_number() . ')' );
			if ( is_wp_error( $m ) ) {
				return $fail( $m );
			}
			$a['medium_id']         = (int) $m['id'];
			$a['medium_identifier'] = $m['identifier'];
			$a['status']            = 'pass';
			$save();
		}

		// Phase 1b: pass ticket in the passes event (for badge printing), linked to the pass.
		$err = self::sync_pass_ticket( $order, $item, $a, $save );
		if ( $err ) {
			return $fail( $err );
		}

		// Phase 1c: free all-access ticket in the lectures event (staff), linked to the pass.
		$err = self::sync_all_access_ticket( $order, $item, $a, $save );
		if ( $err ) {
			return $fail( $err );
		}

		// Pass-only attendees (e.g. staff who get their pass handed over at the venue) stop here on purpose: no
		// email, no pretix account, no membership, no lecture registration - also after registration opens.
		if ( Pretix_Sync_Attendees::is_pass_only( $order ) ) {
			$a['status'] = 'passonly';
			$save();
			return null;
		}

		if ( ! $open ) {
			if ( empty( $a['mail_ticket'] ) ) {
				Pretix_Sync_Mailer::send( 'ticket', $a, $order );
				$a['mail_ticket'] = time();
			}
			$a['status'] = 'pass';
			$save();
			return null;
		}

		// Phase 2: account + membership.
		if ( empty( $a['customer_id'] ) ) {
			$c = Pretix_Sync_API::find_customer_by_email( $a['email'] );
			if ( is_wp_error( $c ) ) {
				return $fail( $c );
			}
			if ( $c ) {
				$a['customer_new'] = false;
			} else {
				$c = Pretix_Sync_API::create_customer( $a['email'], Pretix_Sync_Attendees::name_parts( $a ), true );
				if ( is_wp_error( $c ) ) {
					return $fail( $c );
				}
				$a['customer_new'] = true;
			}
			$a['customer_id'] = $c['identifier'];
			$save();
		}

		if ( empty( $a['membership_id'] ) ) {
			$ms = Pretix_Sync_API::create_membership( $a['customer_id'], Pretix_Sync_Attendees::name_parts( $a ) );
			if ( is_wp_error( $ms ) ) {
				return $fail( $ms );
			}
			$a['membership_id'] = (int) $ms['id'];
			$save();
		}

		if ( empty( $a['medium_linked'] ) ) {
			$r = Pretix_Sync_API::update_medium( $a['medium_id'], array( 'customer' => $a['customer_id'] ) );
			if ( is_wp_error( $r ) ) {
				return $fail( $r );
			}
			$a['medium_linked'] = 1;
			$save();
		}

		if ( empty( $a['mail_open'] ) ) {
			Pretix_Sync_Mailer::send( empty( $a['mail_ticket'] ) ? 'welcome' : 'open', $a, $order );
			$a['mail_open'] = time();
		}
		$a['status'] = 'registered';
		$save();
		return null;
	}

	/**
	 * Creates the attendee's pass ticket (0 € order in the passes event) and links it to their pass, so the
	 * pretix badge exporter can print the badge with the pass QR ("Reusable Medium ID"). Does nothing when no
	 * passes event is configured or the WooCommerce product has no pass item.
	 *
	 * @return WP_Error|null
	 */
	private static function sync_pass_ticket( $order, $item, &$a, $save ) {
		if ( ! Pretix_Sync_Settings::passes_enabled() || empty( $a['medium_id'] ) ) {
			return null;
		}
		$pass_item = Pretix_Sync_Settings::pass_item_for( $item->get_product() );
		if ( ! $pass_item ) {
			return null;
		}

		$team = Pretix_Sync_Attendees::team_name( $order );

		if ( empty( $a['pass_order'] ) ) {
			$o = Pretix_Sync_API::create_pass_order(
				isset( $a['email'] ) ? $a['email'] : '',
				$pass_item,
				Pretix_Sync_Attendees::name_parts( $a ),
				'WooCommerce #' . $order->get_order_number() . ' – ' . $item->get_name(),
				$team
			);
			if ( is_wp_error( $o ) ) {
				return $o;
			}
			$a['pass_order']    = $o['code'];
			$a['pass_position'] = (int) $o['positions'][0]['id'];
			$a['pass_company']  = $team;
			$save();
		}

		if ( empty( $a['pass_linked'] ) ) {
			$r = Pretix_Sync_Webhook::update_links( $a['medium_id'], array(), array( $a['pass_position'] ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$a['pass_linked'] = 1;
			$save();
		}

		// Runs for new and existing passes alike, so "Re-run sync for all orders" fills in the team name on passes
		// created before this field was sent, and picks up later edits of the order's team name.
		return self::sync_pass_company( $a, $team, $save );
	}

	/**
	 * Staff: a free all-access ticket in the lectures event, linked straight to the attendee's pass. The lecture
	 * check-in lists then accept the pass at every lecture. Only for products with an all-access item ID set.
	 * It needs no pretix account: the link is made here, not by the webhook (which works through the account).
	 *
	 * @return WP_Error|null
	 */
	private static function sync_all_access_ticket( $order, $item, &$a, $save ) {
		if ( empty( $a['medium_id'] ) ) {
			return null;
		}
		$all_access = Pretix_Sync_Settings::all_access_item_for( $item->get_product() );
		if ( ! $all_access ) {
			return null;
		}

		if ( empty( $a['all_access_order'] ) ) {
			$o = Pretix_Sync_API::create_all_access_order(
				isset( $a['email'] ) ? $a['email'] : '',
				$all_access,
				Pretix_Sync_Attendees::name_parts( $a ),
				'WooCommerce #' . $order->get_order_number() . ' – ' . $item->get_name() . ' (all access)'
			);
			if ( is_wp_error( $o ) ) {
				return $o;
			}
			$a['all_access_order']    = $o['code'];
			$a['all_access_position'] = (int) $o['positions'][0]['id'];
			$save();
		}

		if ( empty( $a['all_access_linked'] ) ) {
			$r = Pretix_Sync_Webhook::update_links( $a['medium_id'], array(), array( $a['all_access_position'] ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$a['all_access_linked'] = 1;
			$save();
		}
		return null;
	}

	/**
	 * Writes the team name to the badge ticket's "company" field when it differs from what was written last.
	 *
	 * Only transient errors are returned (the queue retries them). Any other failure is already in the log and is
	 * deliberately not fatal: the team name is cosmetic and must not block the lecture registration. The stored
	 * value stays unchanged, so the next sync tries again.
	 *
	 * @return WP_Error|null
	 */
	private static function sync_pass_company( &$a, $team, $save ) {
		$written = isset( $a['pass_company'] ) ? $a['pass_company'] : '';
		if ( $written === $team || ! empty( $a['pass_canceled'] ) ) {
			return null;
		}
		$r = Pretix_Sync_API::update_pass_position( $a['pass_position'], array( 'company' => $team ) );
		if ( is_wp_error( $r ) ) {
			return Pretix_Sync_API::is_transient( $r ) ? $r : null;
		}
		$a['pass_company'] = $team;
		$save();
		return null;
	}

	/* ---------- revoke ---------- */

	public static function revoke_order( $order_id, $attempt = 0 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$transient = false;
		$errors    = array();
		$done      = array();
		foreach ( Pretix_Sync_Attendees::for_order( $order ) as $row ) {
			$a = $row['data'];
			if ( isset( $a['status'] ) && 'revoked' === $a['status'] ) {
				continue;
			}
			$err = self::revoke_attendee( $order, $row['item'], $row['index'], $a );
			if ( $err ) {
				if ( Pretix_Sync_API::is_transient( $err ) ) {
					$transient = true;
				} else {
					$errors[] = Pretix_Sync_Attendees::full_name( $a ) . ': ' . $err->get_error_message();
				}
			} else {
				$done[] = Pretix_Sync_Attendees::full_name( $a );
			}
		}
		if ( $done ) {
			$order->add_order_note( 'pretix: revoked pass / lecture registrations for ' . implode( ', ', $done ) );
		}
		if ( $errors ) {
			$order->add_order_note( "pretix revoke ERROR:\n" . implode( "\n", $errors ) );
			self::notify_admin( $order, $errors );
		}
		if ( $transient && ! self::retry( 'pretix_sync_revoke_order', $order_id, $attempt ) ) {
			$order->add_order_note( 'pretix revoke failed after several attempts – please check manually.' );
		}
	}

	/**
	 * Cancels the attendee's lecture registrations, invalidates the membership and deactivates the pass.
	 *
	 * @return WP_Error|null
	 */
	public static function revoke_attendee( $order, $item, $index, &$a ) {
		$save = function () use ( $item, $index, &$a ) {
			Pretix_Sync_Attendees::save( $item, $index, $a );
		};

		// 1. Lecture registrations – unless the same pretix account still holds another valid ticket.
		if ( ! empty( $a['customer_id'] ) && empty( $a['regs_canceled'] ) ) {
			$key = Pretix_Sync_Attendees::key( $order, $item, $index );
			if ( ! self::customer_has_other_ticket( $a['customer_id'], $key ) ) {
				$page = 1;
				do {
					$list = Pretix_Sync_API::list_orders( array( 'customer' => $a['customer_id'], 'page' => (string) $page ) );
					if ( is_wp_error( $list ) ) {
						$a['error'] = $list->get_error_message();
						$save();
						return $list;
					}
					foreach ( $list['results'] as $o ) {
						if ( in_array( $o['status'], array( 'p', 'n' ), true ) ) {
							$r = Pretix_Sync_API::cancel_order( $o['code'] );
							if ( is_wp_error( $r ) ) {
								$a['error'] = $r->get_error_message();
								$save();
								return $r;
							}
						}
					}
					$page++;
				} while ( ! empty( $list['next'] ) );
			}
			$a['regs_canceled'] = 1;
			$save();
		}

		// 2. Membership.
		if ( ! empty( $a['membership_id'] ) && empty( $a['membership_revoked'] ) ) {
			$r = Pretix_Sync_API::revoke_membership( $a['membership_id'] );
			if ( is_wp_error( $r ) ) {
				$a['error'] = $r->get_error_message();
				$save();
				return $r;
			}
			$a['membership_revoked'] = 1;
			$save();
		}

		// 3. Pass ticket (badge).
		if ( ! empty( $a['pass_order'] ) && empty( $a['pass_canceled'] ) ) {
			$r = Pretix_Sync_API::cancel_pass_order( $a['pass_order'] );
			if ( is_wp_error( $r ) ) {
				$d = $r->get_error_data();
				// 400 = already canceled; anything else is a real error.
				if ( ! isset( $d['status'] ) || 400 !== (int) $d['status'] ) {
					$a['error'] = $r->get_error_message();
					$save();
					return $r;
				}
			}
			$a['pass_canceled'] = 1;
			$save();
		}

		// 3b. All-access ticket in the lectures event (staff).
		if ( ! empty( $a['all_access_order'] ) && empty( $a['all_access_canceled'] ) ) {
			$r = Pretix_Sync_API::cancel_all_access_order( $a['all_access_order'] );
			if ( is_wp_error( $r ) ) {
				$d = $r->get_error_data();
				if ( ! isset( $d['status'] ) || 400 !== (int) $d['status'] ) { // 400 = already canceled
					$a['error'] = $r->get_error_message();
					$save();
					return $r;
				}
			}
			$a['all_access_canceled'] = 1;
			$save();
		}

		// 4. Pass.
		if ( ! empty( $a['medium_id'] ) && empty( $a['medium_revoked'] ) ) {
			$r = Pretix_Sync_API::update_medium( $a['medium_id'], array( 'active' => false, 'linked_orderpositions' => array() ) );
			if ( is_wp_error( $r ) ) {
				$a['error'] = $r->get_error_message();
				$save();
				return $r;
			}
			$a['medium_revoked'] = 1;
		}

		unset( $a['error'] );
		$a['status']     = 'revoked';
		$a['revoked_at'] = time();
		$save();
		return null;
	}

	/** Does another, non-revoked attendee record use the same pretix account? */
	private static function customer_has_other_ticket( $customer_id, $exclude_key ) {
		foreach ( Pretix_Sync_Attendees::order_ids() as $oid ) {
			$o = wc_get_order( $oid );
			if ( ! $o ) {
				continue;
			}
			foreach ( Pretix_Sync_Attendees::for_order( $o ) as $row ) {
				$d = $row['data'];
				if ( Pretix_Sync_Attendees::key( $o, $row['item'], $row['index'] ) === $exclude_key ) {
					continue;
				}
				if ( ! empty( $d['customer_id'] ) && $d['customer_id'] === $customer_id && ( ! isset( $d['status'] ) || 'revoked' !== $d['status'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/* ---------- admin edits ---------- */

	/**
	 * Name change: update membership + pass label in place.
	 * Email change: revoke the old record and create a fresh one (new pass!) for the new email.
	 *
	 * @return WP_Error|string  message
	 */
	public static function edit_attendee( $order, $item, $index, $new ) {
		$list = $item->get_meta( Pretix_Sync_Attendees::ITEM_META );
		$a    = $list[ $index ];

		$old_email = isset( $a['email'] ) ? $a['email'] : '';

		// Pass-only attendees have no account and may have no email at all: adding or changing it must not replace
		// the person (a new email would otherwise mean a new pass). Only the stored address is updated.
		if ( Pretix_Sync_Attendees::is_pass_only( $order ) && strtolower( $new['email'] ) !== strtolower( $old_email ) ) {
			$a['email'] = strtolower( $new['email'] );
			if ( '' !== $a['email'] && ! empty( $a['pass_position'] ) && empty( $a['pass_canceled'] ) ) {
				$r = Pretix_Sync_API::update_pass_position( $a['pass_position'], array( 'attendee_email' => $a['email'] ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
			$old_email = $a['email'];
		}

		if ( strtolower( $new['email'] ) !== strtolower( $old_email ) ) {
			if ( ! isset( $a['status'] ) || 'revoked' !== $a['status'] ) {
				$err = self::revoke_attendee( $order, $item, $index, $a );
				if ( $err ) {
					return $err;
				}
			}
			$history   = isset( $a['history'] ) ? $a['history'] : array();
			$old       = $a;
			unset( $old['history'] );
			$history[] = $old;
			$fresh     = array(
				'title'       => $new['title'],
				'given_name'  => $new['given_name'],
				'family_name' => $new['family_name'],
				'email'       => strtolower( $new['email'] ),
				'status'      => 'new',
				'history'     => $history,
			);
			Pretix_Sync_Attendees::save( $item, $index, $fresh );
			$err = self::sync_attendee( $order, $item, $index, $fresh, Pretix_Sync_Settings::registration_open() );
			if ( $err ) {
				return $err;
			}
			return 'Attendee replaced. The previous pass was deactivated – a NEW pass QR code has been issued (reprint the badge).';
		}

		$a['title']       = $new['title'];
		$a['given_name']  = $new['given_name'];
		$a['family_name'] = $new['family_name'];
		Pretix_Sync_Attendees::save( $item, $index, $a );

		if ( ! empty( $a['membership_id'] ) ) {
			$r = Pretix_Sync_API::update_membership( $a['membership_id'], array( 'attendee_name_parts' => Pretix_Sync_Attendees::name_parts( $a ) ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		if ( ! empty( $a['pass_position'] ) && empty( $a['pass_canceled'] ) ) {
			$r = Pretix_Sync_API::update_pass_position( $a['pass_position'], array( 'attendee_name_parts' => Pretix_Sync_Attendees::name_parts( $a ) ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		if ( ! empty( $a['all_access_position'] ) && empty( $a['all_access_canceled'] ) ) {
			$r = Pretix_Sync_API::update_all_access_position( $a['all_access_position'], array( 'attendee_name_parts' => Pretix_Sync_Attendees::name_parts( $a ) ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		if ( ! empty( $a['medium_id'] ) ) {
			$r = Pretix_Sync_API::update_medium( $a['medium_id'], array( 'label' => Pretix_Sync_Attendees::full_name( $a ) . ' (#' . $order->get_order_number() . ')' ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}
		return 'Attendee name updated (membership, pass label and badge ticket). Lecture registrations made before this change keep the old name in pretix.';
	}

	/* ---------- helpers ---------- */

	private static function notify_admin( $order, $errors ) {
		$to = get_option( 'admin_email' );
		wp_mail(
			$to,
			sprintf( '[Pretix Sync] Sync problem on order #%s', $order->get_order_number() ),
			"Order: " . $order->get_edit_order_url() . "\n\n" . implode( "\n", $errors )
		);
	}

	/** Open lecture registration: flag + queue every paid order. */
	public static function open_registration() {
		update_option( 'pretix_sync_registration_open', 'yes' );
		update_option( 'pretix_sync_registration_opened_at', time() );
		$ids = Pretix_Sync_Attendees::order_ids();
		foreach ( $ids as $id ) {
			pretix_sync_enqueue( 'pretix_sync_sync_order', array( (int) $id, 0 ) );
		}
		return count( $ids );
	}
}
