<?php
defined( 'ABSPATH' ) || exit;

/**
 * The explanation block on the Pretix Sync admin page: what the pretix settings mean, how the sync and the
 * webhook work. Kept as plain translatable text, so it follows the admin language (English / Hungarian).
 */
class Pretix_Sync_Help {

	/** One collapsible section; a block is array( 'p', text ) or array( 'ul', array( text, ... ) ). */
	private static function section( $heading, $blocks, $open = false ) {
		echo '<details style="margin:0 0 .6em;max-width:900px;background:#fff;border:1px solid #c3c4c7;padding:.2em 1em"' . ( $open ? ' open' : '' ) . '>';
		echo '<summary style="cursor:pointer;font-weight:600;padding:.6em 0">' . esc_html( $heading ) . '</summary>';
		foreach ( $blocks as $b ) {
			if ( 'p' === $b[0] ) {
				echo '<p>' . esc_html( $b[1] ) . '</p>';
				continue;
			}
			echo '<ul style="list-style:disc;margin:.4em 0 1em 1.4em">';
			foreach ( $b[1] as $li ) {
				echo '<li>' . esc_html( $li ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</details>';
	}

	public static function render() {
		echo '<h2>' . esc_html__( 'How Pretix Sync works', 'pretix-sync' ) . '</h2>';
		self::section( __( 'How it works – overview', 'pretix-sync' ), array(
			array( 'p', __( 'Pretix Sync connects the WooCommerce shop to pretix. The buyer pays in WooCommerce; the plugin then creates everything in pretix through the pretix API, in the background, so the checkout never waits for pretix.', 'pretix-sync' ) ),
			array( 'ul', array(
				__( 'Every attendee gets a pass: a reusable medium in pretix. Its QR code is the attendee\'s entry to the event and can be printed on a badge.', 'pretix-sync' ),
				__( 'Attendee details (and any custom checkout fields) are collected per ticket at checkout, so every attendee has their own pass and email.', 'pretix-sync' ),
				__( 'All work runs in the Action Scheduler queue (group pretix-sync). A temporary pretix outage is retried automatically; real errors go to the order notes, to the log (WooCommerce → Status → Logs, source pretix-sync) and by email to the site admin address.', 'pretix-sync' ),
			) ),
		) );
		self::section( __( 'What to prepare in pretix (the settings explained)', 'pretix-sync' ), array(
			array( 'p', __( 'Each setting on the Pretix Sync settings tab maps to something in pretix. Use “Test pretix connection” below after filling them in.', 'pretix-sync' ) ),
			array( 'ul', array(
				__( 'API token: create a team in pretix (Organizer → Teams) with an API token that may manage customer accounts and reusable media for the organizer, and view and change orders in the events used. Put the token in the PRETIX_SYNC_TOKEN constant in wp-config.php, an environment variable or a Docker secret; the settings field is only the last fallback and is stored in the database.', 'pretix-sync' ),
				__( 'pretix base URL and Organizer slug: the first parts of your pretix addresses, https://pretix.example.com/<organizer>/<event>/.', 'pretix-sync' ),
				__( 'Sessions event slug: the pretix event where attendees register for sessions. Give its session products a fixed validity window and “require a valid membership”; that is how pretix refuses overlapping sessions.', 'pretix-sync' ),
				__( 'Membership type ID: the number of the membership type (Organizer → Customer accounts → Membership types). Every attendee gets one membership of this type.', 'pretix-sync' ),
				__( 'Membership valid from / until: ISO 8601 with a time zone offset. The window must start before the first session and end after the last one, otherwise pretix does not accept the membership for those sessions.', 'pretix-sync' ),
				__( 'Passes event slug (optional): a pretix event with one free pass ticket per attendee, used for badge printing (Badges → QR code: Reusable Medium ID). Set the matching pass item ID on each WooCommerce ticket product. Empty = no badge tickets.', 'pretix-sync' ),
				__( 'Customer account language and Event name: the pretix account gets the language of the order (English or Hungarian), the fallback is used for any other language; the event name is used in the emails.', 'pretix-sync' ),
				__( 'Test mode: memberships and orders are created in pretix test mode. Only use it while the pretix event is in test mode too.', 'pretix-sync' ),
			) ),
		) );
		self::section( __( 'From payment to registration', 'pretix-sync' ), array(
			array( 'ul', array(
				__( 'Order paid (status processing or completed): one pass is created per attendee, labelled with the name and the order number. With a Passes event and a pass item ID on the product, a free badge ticket is also created and linked to the pass. The badge text is the custom checkout field marked “Print on badge”.', 'pretix-sync' ),
				__( 'Before session registration is open the attendee only receives the “ticket” email. No pretix account exists yet.', 'pretix-sync' ),
				__( '“Open session registration now” (above): for every attendee the plugin finds or creates the pretix customer account (new accounts get pretix\'s activation email), creates the membership, links the pass to the account and sends the “registration open” email. Orders paid after that get everything at once, with the “welcome” email.', 'pretix-sync' ),
				__( 'Pass-only tickets (staff whose pass is handed over at the venue): pass and badge only; no emails, no account, not even after registration opens.', 'pretix-sync' ),
				__( 'Every step saves its result immediately, so a re-run only does what is still missing. “Re-run sync for all orders” is safe to click at any time.', 'pretix-sync' ),
			) ),
		) );
		if ( Pretix_Sync_Settings::sessions_enabled() ) {
			self::section( __( 'Webhook: how it works', 'pretix-sync' ), array(
				array( 'p', __( 'pretix does not link a session registration made in its web shop to the attendee\'s pass on its own. The webhook closes that gap: whenever an order in the sessions event changes, pretix calls the target URL above and the plugin updates the pass, so the pass is accepted at the check-in of exactly the sessions the attendee is registered for.', 'pretix-sync' ) ),
				array( 'ul', array(
					__( 'Set-up: in pretix open Organizer → Webhooks → Add, paste the target URL above, limit it to the sessions event and tick: Order placed, Order marked as paid, Order canceled, Order reactivated, Order changed (all) and Order modified.', 'pretix-sync' ),
					__( 'The secret parameter in the URL is the only authentication. Treat the URL like a password and do not share it.', 'pretix-sync' ),
					__( 'The call only carries the event and the order code. The plugin answers at once and does the work in the queue: it fetches the real state of the order from the pretix API, so repeated or out-of-order calls do no harm. Calls for other events are ignored.', 'pretix-sync' ),
					__( 'What it does: the links of the pass become the old links, minus every position of this order, plus the active positions of this order. A paid or pending order links its sessions; a canceled order or a canceled position removes them, which frees the session from the pass.', 'pretix-sync' ),
					__( 'Orders without a customer account (not placed through the attendee\'s account) cannot be linked; they are skipped and logged as a warning. If the customer has no active pass, the call ends in an error and the admin gets an email.', 'pretix-sync' ),
					__( 'A temporary error is retried four times, after 1, 4, 9 and 16 minutes. After that the error is logged and emailed to the site admin address.', 'pretix-sync' ),
					__( 'Safety net: “Re-link all session registrations to passes” re-reads every order of the sessions event and repairs the links. Use it after the webhook was down or was set up late.', 'pretix-sync' ),
					__( 'Check that it works: register a test attendee for a session, then look for “Pass …: registration … linked” in the log (WooCommerce → Status → Logs, source pretix-sync).', 'pretix-sync' ),
				) ),
			) );
		}
		self::section( __( 'Refunds and cancellations', 'pretix-sync' ), array(
			array( 'p', __( 'When a WooCommerce order is refunded or cancelled, the plugin undoes everything for its attendees, step by step: it cancels their session registrations (pretix emails the attendee about it) unless the same pretix account holds another valid ticket, invalidates the membership, cancels the badge and all-access tickets and deactivates the pass. A deactivated pass no longer opens any door. A failed step is retried and reported in the order notes.', 'pretix-sync' ) ),
		) );
		self::section( __( 'Where to look when something is wrong', 'pretix-sync' ), array(
			array( 'ul', array(
				__( 'The order notes show every sync and revoke result of that order. “Re-run pretix sync” on the order repeats only the missing steps.', 'pretix-sync' ),
				__( 'Logs: WooCommerce → Status → Logs, source pretix-sync. Queue: Tools → Scheduled Actions, group pretix-sync (failed and pending jobs, with retries).', 'pretix-sync' ),
				__( '“Test pretix connection” checks the token, the URL and access to customers, memberships, reusable media and orders.', 'pretix-sync' ),
			) ),
		) );
	}
}
