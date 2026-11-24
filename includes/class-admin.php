<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin UI: tools page (WooCommerce → Pretix Sync), order meta box, pass CSV export.
 */
class Pretix_Sync_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 70 );
		add_action( 'admin_post_pretix_sync_tool', array( __CLASS__, 'handle_tool' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'handle_order_actions' ), 50 );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', 'Pretix Sync', 'Pretix Sync', 'manage_woocommerce', 'pretix-sync', array( __CLASS__, 'tools_page' ) );
	}

	/* ---------- notices ---------- */

	public static function notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! Pretix_Sync_Settings::token() ) {
			echo '<div class="notice notice-warning"><p><strong>Pretix Sync:</strong> no pretix API token found. Enter it in WooCommerce → Settings → Pretix Sync, or define <code>PRETIX_SYNC_TOKEN</code> in wp-config.php / as environment variable.</p></div>';
		}
		if ( class_exists( 'WC_Blocks_Utils' ) && WC_Blocks_Utils::has_block_in_page( wc_get_page_id( 'checkout' ), 'woocommerce/checkout' ) ) {
			echo '<div class="notice notice-error"><p><strong>Pretix Sync:</strong> the checkout page uses the Checkout <em>block</em>. Attendee fields only work with the classic checkout – replace the block with the <code>[woocommerce_checkout]</code> shortcode (or the Elementor Checkout widget).</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['pretix_sync_msg'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( wp_unslash( $_GET['pretix_sync_msg'] ) ) . '</p></div>'; // phpcs:ignore
		}
	}

	/* ---------- tools page ---------- */

	private static function stats() {
		$s = array( 'orders' => 0, 'attendees' => 0, 'new' => 0, 'pass' => 0, 'passonly' => 0, 'registered' => 0, 'revoked' => 0, 'error' => 0 );
		foreach ( Pretix_Sync_Attendees::order_ids() as $oid ) {
			$o = wc_get_order( $oid );
			if ( ! $o ) {
				continue;
			}
			$s['orders']++;
			foreach ( Pretix_Sync_Attendees::for_order( $o ) as $row ) {
				$st = isset( $row['data']['status'] ) ? $row['data']['status'] : 'new';
				$s['attendees']++;
				$s[ isset( $s[ $st ] ) ? $st : 'new' ]++;
			}
		}
		return $s;
	}

	private static function tool_button( $tool, $label, $class = 'button', $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 .5em .5em 0"' . ( $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>';
		wp_nonce_field( 'pretix_sync_tool' );
		echo '<input type="hidden" name="action" value="pretix_sync_tool"><input type="hidden" name="tool" value="' . esc_attr( $tool ) . '">';
		echo '<button class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function tools_page() {
		$s    = self::stats();
		$open = Pretix_Sync_Settings::registration_open();
		echo '<div class="wrap"><h1>Pretix Sync</h1>';

		echo '<h2>Status</h2><table class="widefat striped" style="max-width:600px"><tbody>';
		if ( Pretix_Sync_Settings::sessions_enabled() ) {
			echo '<tr><td>Session registration</td><td>' . ( $open ? '<strong style="color:#008a20">OPEN</strong> since ' . esc_html( wp_date( 'Y-m-d H:i', (int) get_option( 'pretix_sync_registration_opened_at' ) ) ) : '<strong>not open yet</strong>' ) . '</td></tr>';
		}
		echo '<tr><td>Orders with attendees</td><td>' . (int) $s['orders'] . '</td></tr>';
		echo '<tr><td>Attendees</td><td>' . (int) $s['attendees'] . '</td></tr>';
		echo '<tr><td>… pass created (waiting for registration opening)</td><td>' . (int) $s['pass'] . '</td></tr>';
		if ( Pretix_Sync_Settings::sessions_enabled() ) {
			echo '<tr><td>… pass + session registration right</td><td>' . (int) $s['registered'] . '</td></tr>';
		}
		echo '<tr><td>… pass only (no emails, no account – e.g. staff)</td><td>' . (int) $s['passonly'] . '</td></tr>';
		echo '<tr><td>… not synced yet</td><td>' . (int) $s['new'] . '</td></tr>';
		echo '<tr><td>… error</td><td>' . ( $s['error'] ? '<strong style="color:#d63638">' . (int) $s['error'] . '</strong>' : '0' ) . '</td></tr>';
		echo '<tr><td>… revoked</td><td>' . (int) $s['revoked'] . '</td></tr>';
		echo '<tr><td>Test mode</td><td>' . ( Pretix_Sync_Settings::is_testmode() ? 'ON' : 'off' ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h2>Actions</h2>';
		self::tool_button( 'test', 'Test pretix connection' );
		self::tool_button( 'export', 'Download pass list (CSV)' );
		self::tool_button( 'resync_all', 'Re-run sync for all orders (also creates missing badge tickets and updates team names)' );
		if ( Pretix_Sync_Settings::sessions_enabled() ) {
			self::tool_button( 'reconcile', 'Re-link all session registrations to passes' );
		}

		if ( Pretix_Sync_Settings::sessions_enabled() ) {
		echo '<h2>Session registration</h2>';
		if ( ! $open ) {
			echo '<p>Opening creates a pretix account and membership for every attendee (new accounts get an activation email) and sends everyone the “Session registration is now open” email. Make sure all sessions are set up in pretix and the pretix shop is <strong>live</strong> first.</p>';
			self::tool_button( 'open', 'Open session registration now', 'button button-primary', 'Open session registration and email all ' . $s['attendees'] . ' attendees?' );
		} else {
			echo '<p>Registration is open. New ticket buyers get their account and the registration email immediately.</p>';
			self::tool_button( 'close', 'Mark as not open (no emails are sent)', 'button', 'Only new purchases are affected. Continue?' );
		}
		}

		if ( Pretix_Sync_Settings::staff_enabled() ) {
			Pretix_Sync_Comp::render_form();
		}

		if ( Pretix_Sync_Settings::sessions_enabled() ) {
			echo '<h2>Webhook</h2><p>Target URL for the pretix webhook (Organizer → Webhooks):</p><p><input type="text" readonly style="width:100%;max-width:900px" value="' . esc_attr( Pretix_Sync_Settings::webhook_url() ) . '" onclick="this.select()"></p>';
		}
		Pretix_Sync_Help::render();

		echo '<p>Settings: <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=pretix_sync' ) ) . '">WooCommerce → Settings → Pretix Sync</a> · Logs: WooCommerce → Status → Logs (source <code>pretix-sync</code>) · Queue: Tools → Scheduled Actions (group <code>pretix-sync</code>)</p>';
		echo '</div>';
	}

	public static function handle_tool() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pretix_sync_tool' );
		$tool = isset( $_POST['tool'] ) ? sanitize_key( $_POST['tool'] ) : '';
		$msg  = '';
		switch ( $tool ) {
			case 'test':
				$r   = Pretix_Sync_API::test();
				$msg = is_wp_error( $r ) ? 'Connection FAILED: ' . $r->get_error_message() : 'Connection OK (customers, memberships, reusable media, orders). Event: ' . ( isset( $r['event'] ) ? self::i18n( $r['event']['name'] ) . ( ! empty( $r['event']['live'] ) ? ' (live)' : ' (not live)' ) . ( ! empty( $r['event']['testmode'] ) ? ' (test mode)' : '' ) : 'n/a (no event configured)' );
				break;
			case 'export':
				self::export_csv();
				exit;
			case 'resync_all':
				$n = 0;
				foreach ( Pretix_Sync_Attendees::order_ids() as $id ) {
					pretix_sync_enqueue( 'pretix_sync_sync_order', array( (int) $id, 0 ) );
					$n++;
				}
				$msg = "$n orders queued for sync.";
				break;
			case 'reconcile':
				Pretix_Sync_Webhook::start_reconcile();
				$msg = 'Re-linking of all session registrations queued.';
				break;
			case 'open':
				$n   = Pretix_Sync_Sync::open_registration();
				$msg = "Session registration opened. $n orders queued – emails go out in the background over the next minutes.";
				break;
			case 'close':
				update_option( 'pretix_sync_registration_open', 'no' );
				$msg = 'Session registration marked as not open.';
				break;
		}
		wp_safe_redirect( add_query_arg( 'pretix_sync_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=pretix-sync' ) ) );
		exit;
	}

	private static function i18n( $v ) {
		if ( is_array( $v ) ) {
			return isset( $v['en'] ) ? $v['en'] : reset( $v );
		}
		return (string) $v;
	}

	/* ---------- CSV export (for pass printing) ---------- */

	public static function export_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden' );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=pretix-sync-passes-' . gmdate( 'Ymd-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel UTF-8 BOM.
		fputcsv( $out, array_merge( array( 'Order', 'Order date', 'Ticket type', 'Title', 'Given name', 'Family name', 'Email', 'Pass QR code', 'Badge ticket (pretix)', 'Status', 'Buyer', 'Buyer email', 'Company' ), self::custom_headers() ) );
		foreach ( Pretix_Sync_Attendees::order_ids() as $oid ) {
			$o = wc_get_order( $oid );
			if ( ! $o ) {
				continue;
			}
			foreach ( Pretix_Sync_Attendees::for_order( $o ) as $row ) {
				$a = $row['data'];
				fputcsv( $out, array_merge( array(
					$o->get_order_number(),
					$o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : '',
					$row['item']->get_name(),
					isset( $a['title'] ) ? $a['title'] : '',
					$a['given_name'],
					$a['family_name'],
					isset( $a['email'] ) ? $a['email'] : '',
					isset( $a['medium_identifier'] ) && ( ! isset( $a['status'] ) || 'revoked' !== $a['status'] ) ? $a['medium_identifier'] : '',
					isset( $a['pass_order'] ) && empty( $a['pass_canceled'] ) ? $a['pass_order'] : '',
					isset( $a['status'] ) ? $a['status'] : 'new',
					trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
					$o->get_billing_email(),
					$o->get_billing_company(),
				), self::custom_cells( $o, $a ) ) );
			}
		}
		fclose( $out );
		exit;
	}

	/** CSV columns for the custom checkout fields. */
	private static function custom_headers() {
		$h = array();
		foreach ( Pretix_Sync_Fields::all() as $f ) {
			$h[] = $f['label'];
		}
		return $h;
	}

	private static function custom_cells( $order, $a ) {
		$c = array();
		foreach ( Pretix_Sync_Fields::all() as $f ) {
			$c[] = Pretix_Sync_Fields::display_value( $f, Pretix_Sync_Fields::value_for( $order, $a, $f ), '' );
		}
		return $c;
	}

	/* ---------- order meta box ---------- */

	public static function meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'pretix-sync', 'Pretix attendees', array( __CLASS__, 'render_box' ), $screen, 'normal', 'default' );
	}

	public static function render_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$rows = Pretix_Sync_Attendees::for_order( $order );
		if ( ! $rows ) {
			echo '<p>No pretix attendees on this order.</p>';
			return;
		}
		foreach ( Pretix_Sync_Fields::of_scope( 'order' ) as $cf ) {
			$cv = Pretix_Sync_Fields::order_value( $order, $cf );
			if ( '' !== $cv ) {
				echo '<p><strong>' . esc_html( $cf['label'] ) . ':</strong> ' . esc_html( Pretix_Sync_Fields::display_value( $cf, $cv, '' ) ) . '</p>';
			}
		}
		$colors = array( 'new' => '#996800', 'pass' => '#2271b1', 'passonly' => '#50575e', 'registered' => '#008a20', 'revoked' => '#8c8f94', 'error' => '#d63638' );
		echo '<table class="widefat striped"><thead><tr><th>Ticket</th><th>Title</th><th>Given name</th><th>Family name</th><th>Email</th><th>Pass QR</th><th>Status</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$a   = $row['data'];
			$key = Pretix_Sync_Attendees::key( $order, $row['item'], $row['index'] );
			$st  = isset( $a['status'] ) ? $a['status'] : 'new';
			$f   = 'pretix_sync_edit[' . esc_attr( $key ) . ']';
			$dis = 'revoked' === $st ? ' disabled' : '';
			echo '<tr>';
			$extra = array();
			foreach ( Pretix_Sync_Fields::of_scope( 'attendee' ) as $cf ) {
				$cv = Pretix_Sync_Fields::display_value( $cf, Pretix_Sync_Fields::value_for( $order, $a, $cf ), '' );
				if ( '' !== $cv ) {
					$extra[] = $cf['label'] . ': ' . $cv;
				}
			}
			echo '<td>' . esc_html( $row['item']->get_name() ) . ( $extra ? '<br><small>' . esc_html( implode( ', ', $extra ) ) . '</small>' : '' ) . '</td>';
			echo '<td><input type="text" name="' . $f . '[title]" value="' . esc_attr( isset( $a['title'] ) ? $a['title'] : '' ) . '" style="width:60px"' . $dis . '></td>';
			echo '<td><input type="text" name="' . $f . '[given_name]" value="' . esc_attr( $a['given_name'] ) . '"' . $dis . '></td>';
			echo '<td><input type="text" name="' . $f . '[family_name]" value="' . esc_attr( $a['family_name'] ) . '"' . $dis . '></td>';
			echo '<td><input type="email" name="' . $f . '[email]" value="' . esc_attr( isset( $a['email'] ) ? $a['email'] : '' ) . '"' . $dis . '></td>';
			echo '<td><code>' . esc_html( isset( $a['medium_identifier'] ) ? $a['medium_identifier'] : '–' ) . '</code>';
			if ( ! empty( $a['pass_order'] ) ) {
				echo '<br><small>badge ticket ' . esc_html( $a['pass_order'] ) . ( ! empty( $a['pass_canceled'] ) ? ' (canceled)' : '' ) . '</small>';
			}
			echo '</td>';
			echo '<td><strong style="color:' . esc_attr( isset( $colors[ $st ] ) ? $colors[ $st ] : '#000' ) . '">' . esc_html( $st ) . '</strong>';
			if ( ! empty( $a['customer_id'] ) ) {
				echo '<br><small>account ' . esc_html( $a['customer_id'] ) . ( ! empty( $a['customer_new'] ) ? ' (new)' : ' (existing)' ) . '</small>';
			}
			if ( ! empty( $a['error'] ) ) {
				echo '<br><small style="color:#d63638">' . esc_html( $a['error'] ) . '</small>';
			}
			echo '</td><td style="white-space:nowrap">';
			if ( 'revoked' !== $st ) {
				echo '<button type="submit" class="button" name="pretix_sync_action" value="save|' . esc_attr( $key ) . '">Save</button> ';
				echo '<button type="submit" class="button" name="pretix_sync_action" value="revoke|' . esc_attr( $key ) . '" onclick="return confirm(\'Revoke this attendee? Their pass is deactivated and all session registrations are canceled.\')">Revoke</button>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		wp_nonce_field( 'pretix_sync_order', 'pretix_sync_nonce' );
		echo '<p><button type="submit" class="button" name="pretix_sync_action" value="resync">Re-run pretix sync</button> ';
		echo '<span class="description">Changing an email replaces the attendee (new pass QR, old one deactivated). Changing only the name updates it in place.</span></p>';
	}

	public static function handle_order_actions( $order_id ) {
		if ( empty( $_POST['pretix_sync_action'] ) || empty( $_POST['pretix_sync_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['pretix_sync_nonce'] ), 'pretix_sync_order' ) ) { // phpcs:ignore
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$order  = wc_get_order( $order_id );
		$action = sanitize_text_field( wp_unslash( $_POST['pretix_sync_action'] ) );
		list( $verb, $key ) = array_pad( explode( '|', $action, 2 ), 2, '' );

		if ( 'resync' === $verb ) {
			Pretix_Sync_Sync::sync_order( $order_id );
			return;
		}

		$row = Pretix_Sync_Attendees::find( $order, $key );
		if ( ! $row ) {
			return;
		}
		$a    = $row['data'];
		$name = Pretix_Sync_Attendees::full_name( $a );

		if ( 'revoke' === $verb ) {
			$err = Pretix_Sync_Sync::revoke_attendee( $order, $row['item'], $row['index'], $a );
			$order->add_order_note( $err ? "pretix revoke failed for $name: " . $err->get_error_message() : "pretix: attendee $name revoked (pass deactivated, session registrations canceled)." );
			return;
		}

		if ( 'save' === $verb ) {
			$in  = isset( $_POST['pretix_sync_edit'][ $key ] ) ? wp_unslash( $_POST['pretix_sync_edit'][ $key ] ) : array(); // phpcs:ignore
			$new = array(
				'title'       => sanitize_text_field( isset( $in['title'] ) ? $in['title'] : '' ),
				'given_name'  => sanitize_text_field( isset( $in['given_name'] ) ? $in['given_name'] : '' ),
				'family_name' => sanitize_text_field( isset( $in['family_name'] ) ? $in['family_name'] : '' ),
				'email'       => sanitize_email( isset( $in['email'] ) ? $in['email'] : '' ),
			);
			// Pass-only attendees (staff) may have no email; every other attendee needs one.
			$email_ok = Pretix_Sync_Attendees::is_pass_only( $order ) ? ( '' === $new['email'] || is_email( $new['email'] ) ) : is_email( $new['email'] );
			if ( ! $new['given_name'] || ! $new['family_name'] || ! $email_ok ) {
				$order->add_order_note( "pretix: attendee $name NOT changed – name and a valid email are required." );
				return;
			}
			$r = Pretix_Sync_Sync::edit_attendee( $order, $row['item'], $row['index'], $new );
			$order->add_order_note( is_wp_error( $r ) ? "pretix: changing attendee $name failed: " . $r->get_error_message() : "pretix: $name → " . Pretix_Sync_Attendees::full_name( $new ) . ' <' . $new['email'] . '>. ' . $r );
		}
	}
}
