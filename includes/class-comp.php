<?php
defined( 'ABSPATH' ) || exit;

/**
 * Complimentary (free) tickets – e.g. competition winners, guests, speakers, staff.
 *
 * Creates a WooCommerce order with the custom status "Complimentary" (wc-pretix-sync-comp). Invoicing plugins and
 * WooCommerce customer emails react to processing/completed, not to this status, so no (0 Ft) invoice is issued.
 * The attendees are synced to pretix exactly like paid ones (pass + lecture registration right) - unless the
 * "Pass only" option is ticked: then only the pass and badge ticket are created (no emails, no pretix account,
 * no lecture registration), e.g. for staff whose pass is handed over at the venue.
 *
 * Attendees are entered in the grid (up to 5) or imported from a CSV file (preview first, then confirm).
 */
class Pretix_Sync_Comp {

	const STATUS = 'pretix-sync-comp';

	/** Attendees per WooCommerce order when importing: keeps each background sync job short. */
	const CHUNK = 20;

	/** Hard limit of rows in one CSV import. */
	const MAX_ROWS = 300;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_status' ) );
		add_action( 'admin_post_pretix_sync_comp', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_pretix_sync_comp_csv', array( __CLASS__, 'handle_csv_preview' ) );
		add_action( 'admin_post_pretix_sync_comp_csv_sample', array( __CLASS__, 'handle_csv_sample' ) );
		add_action( 'admin_post_pretix_sync_comp_csv_confirm', array( __CLASS__, 'handle_csv_confirm' ) );
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

	/* ---------- admin forms (rendered on the tools page) ---------- */

	private static function ticket_products() {
		return array_filter(
			wc_get_products( array( 'limit' => -1, 'status' => array( 'publish', 'private' ) ) ),
			array( 'Pretix_Sync_Settings', 'is_ticket_product' )
		);
	}

	private static function product_select( $products ) {
		echo '<select name="product_id" required>';
		foreach ( $products as $p ) {
			echo '<option value="' . (int) $p->get_id() . '">' . esc_html( $p->get_name() ) . '</option>';
		}
		echo '</select>';
	}

	private static function pass_only_checkbox() {
		return '<label><input type="checkbox" name="pass_only" value="1"> <strong>Pass only</strong> – no emails, no pretix account, no lecture registration (e.g. staff whose pass is handed out at the venue). Email is optional.</label>';
	}

	public static function render_form() {
		$products = self::ticket_products();

		self::render_csv_preview();

		echo '<h2>Complimentary tickets</h2>';
		echo '<p>For winners, guests, speakers, staff etc. Creates an order with status <strong>Complimentary</strong> – no payment, no invoice, no WooCommerce customer email. Attendees get the same pass and emails as paying attendees, unless <em>Pass only</em> is ticked.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pretix_sync_comp' );
		echo '<input type="hidden" name="action" value="pretix_sync_comp">';
		echo '<table class="form-table" style="max-width:900px"><tr><th>Ticket type</th><td>';
		self::product_select( $products );
		echo '</td></tr>';
		echo '<tr><th>Reason / note</th><td><input type="text" name="note" style="width:100%" placeholder="e.g. Competition winner – LinkedIn giveaway" required></td></tr>';
		echo '<tr><th>Team name</th><td><input type="text" name="team_name" style="width:100%" placeholder="Printed on the badge (optional)"></td></tr>';
		echo '<tr><th>Pass only</th><td>' . self::pass_only_checkbox() . '</td></tr></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>#</th><th>Title</th><th>Given name *</th><th>Family name *</th><th>Email *</th></tr></thead><tbody>';
		for ( $i = 0; $i < 5; $i++ ) {
			echo '<tr><td>' . ( $i + 1 ) . '</td>';
			echo '<td><input type="text" name="att[' . $i . '][title]" style="width:70px"></td>';
			echo '<td><input type="text" name="att[' . $i . '][given_name]"></td>';
			echo '<td><input type="text" name="att[' . $i . '][family_name]"></td>';
			echo '<td><input type="email" name="att[' . $i . '][email]" style="width:100%"></td></tr>';
		}
		echo '</tbody></table><p class="description">Leave unused rows empty. Each attendee needs their own email address (except with <em>Pass only</em>).</p>';
		echo '<p><button class="button button-primary" onclick="return confirm(\'Create complimentary tickets? Unless Pass only is ticked, the attendees are emailed.\')">Create complimentary tickets</button></p></form>';

		echo '<h3>Import from CSV</h3>';
		echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pretix_sync_comp_csv_sample' ), 'pretix_sync_comp_csv_sample' ) ) . '">Download sample CSV</a> <span class="description">Open it in Excel, replace the example rows with your people, save as CSV.</span></p>';
		echo '<p>For many attendees at once. The file needs a header row with the columns <code>given_name</code>, <code>family_name</code>, <code>email</code>; optional: <code>title</code>, <code>team</code> (team name for the badge; overrides the field below). Separator <code>;</code> or <code>,</code>, UTF-8 or Windows-1250 (Excel). Nothing is created before you confirm the preview. People who are already in the system are skipped.</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pretix_sync_comp_csv' );
		echo '<input type="hidden" name="action" value="pretix_sync_comp_csv">';
		echo '<table class="form-table" style="max-width:900px"><tr><th>Ticket type</th><td>';
		self::product_select( $products );
		echo '</td></tr>';
		echo '<tr><th>Reason / note</th><td><input type="text" name="note" style="width:100%" placeholder="e.g. Staff" required></td></tr>';
		echo '<tr><th>Team name</th><td><input type="text" name="team_name" style="width:100%" placeholder="Default for rows without a team column (optional)"></td></tr>';
		echo '<tr><th>Pass only</th><td>' . self::pass_only_checkbox() . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<tr><th>CSV file</th><td><input type="file" name="csv_file" accept=".csv,.txt,text/csv"></td></tr>';
		echo '<tr><th>…or paste</th><td><textarea name="csv_text" rows="6" style="width:100%;font-family:monospace" placeholder="given_name;family_name;email&#10;Anna;Kovács;"></textarea></td></tr></table>';
		echo '<p><button class="button">Preview import</button></p></form>';
	}

	/* ---------- shared helpers ---------- */

	private static function name_key( $a ) {
		return strtolower( remove_accents( trim( $a['given_name'] . ' ' . $a['family_name'] ) ) );
	}

	/**
	 * Identity of everybody already in the system (not revoked): emails and names, mapped to the order number.
	 * Used to skip duplicates on import.
	 */
	private static function existing_index() {
		$idx = array( 'email' => array(), 'name' => array() );
		foreach ( Pretix_Sync_Attendees::order_ids() as $oid ) {
			$o = wc_get_order( $oid );
			if ( ! $o ) {
				continue;
			}
			foreach ( Pretix_Sync_Attendees::for_order( $o ) as $row ) {
				$d = $row['data'];
				if ( isset( $d['status'] ) && 'revoked' === $d['status'] ) {
					continue;
				}
				if ( ! empty( $d['email'] ) ) {
					$idx['email'][ strtolower( $d['email'] ) ] = $o->get_order_number();
				}
				$idx['name'][ self::name_key( $d ) ] = $o->get_order_number();
			}
		}
		return $idx;
	}

	/**
	 * Creates one complimentary order and queues its sync.
	 *
	 * @param array $attendees  list of attendee arrays (title, given_name, family_name, email, status)
	 */
	private static function create_order( $product, $attendees, $note, $team, $pass_only ) {
		$order   = wc_create_order( array( 'created_via' => 'pretix-sync-complimentary' ) );
		$item_id = $order->add_product( $product, count( $attendees ), array( 'subtotal' => 0, 'total' => 0 ) );
		$item    = $order->get_item( $item_id );
		$item->add_meta_data( Pretix_Sync_Attendees::ITEM_META, array_values( $attendees ), true );
		$item->save();

		$first = $attendees[0];
		$order->set_billing_first_name( $first['given_name'] );
		$order->set_billing_last_name( $first['family_name'] );
		if ( '' !== $first['email'] ) {
			$order->set_billing_email( $first['email'] );
		}
		$order->update_meta_data( Pretix_Sync_Attendees::ORDER_FLAG, 'yes' );
		$order->update_meta_data( '_pretix_sync_complimentary', $note );
		if ( '' !== $team ) {
			// Same key the checkout field uses, so the badge logic does not need to know the order's origin.
			$order->update_meta_data( 'team_name', $team );
		}
		if ( $pass_only ) {
			$order->update_meta_data( Pretix_Sync_Attendees::PASS_ONLY_META, 'yes' );
		}
		$order->calculate_totals( false );
		$order->set_status( self::STATUS, 'Complimentary ticket – ' . $note . ( $pass_only ? ' (pass only)' : '' ) . ' (created by ' . wp_get_current_user()->user_login . ').' );
		$order->save();

		Pretix_Sync_Sync::enqueue_order( $order->get_id() );
		return $order;
	}

	private static function back_to_tools( $msg ) {
		wp_safe_redirect( add_query_arg( 'pretix_sync_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=pretix-sync' ) ) );
		exit;
	}

	/* ---------- grid ---------- */

	public static function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pretix_sync_comp' );

		$product   = wc_get_product( isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0 );
		$note      = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$team      = isset( $_POST['team_name'] ) ? sanitize_text_field( wp_unslash( $_POST['team_name'] ) ) : '';
		$pass_only = ! empty( $_POST['pass_only'] );
		$rows      = isset( $_POST['att'] ) && is_array( $_POST['att'] ) ? wp_unslash( $_POST['att'] ) : array(); // phpcs:ignore

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
			$email_ok = $pass_only ? ( '' === $a['email'] || is_email( $a['email'] ) ) : is_email( $a['email'] );
			if ( ! $a['given_name'] || ! $a['family_name'] || ! $email_ok ) {
				$errors[] = $pass_only
					? "Row $n: given name and family name are required; the email, if given, must be valid."
					: "Row $n: given name, family name and a valid email are required.";
				continue;
			}
			$key = '' !== $a['email'] ? $a['email'] : self::name_key( $a );
			if ( isset( $seen[ $key ] ) ) {
				$errors[] = "Row $n: " . ( '' !== $a['email'] ? 'email' : 'name' ) . " already used in row {$seen[$key]}.";
				continue;
			}
			$seen[ $key ] = $n;
			$attendees[]  = $a;
		}
		if ( ! $product || ! Pretix_Sync_Settings::is_ticket_product( $product ) ) {
			$errors[] = 'Select a pretix ticket product.';
		}
		if ( ! $attendees ) {
			$errors[] = 'Enter at least one attendee.';
		}
		if ( $errors ) {
			self::back_to_tools( 'Nothing created. ' . implode( ' ', $errors ) );
		}

		$order = self::create_order( $product, $attendees, $note, $team, $pass_only );
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/* ---------- CSV import ---------- */

	/** Example rows of the downloadable CSV template (fictional people; one without email, one with a title). */
	public static function sample_rows() {
		return array(
			array( 'given_name', 'family_name', 'email', 'title', 'team' ),
			array( 'Anna', 'Minta', 'anna.minta@example.com', '', 'STAFF' ),
			array( 'Béla', 'Példa', '', '', 'STAFF' ),
			array( 'Csilla', 'Teszt', 'csilla.teszt@example.com', 'Dr.', 'Organizers' ),
		);
	}

	/**
	 * Download: the template for the CSV import. ";" separated with a UTF-8 BOM, which is what Excel in a Hungarian
	 * locale opens correctly and saves back without breaking the accents. The importer also reads "," and tab.
	 */
	public static function handle_csv_sample() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pretix_sync_comp_csv_sample' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=pretix-sync-attendees-sample.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( self::sample_rows() as $row ) {
			fputcsv( $out, $row, ';' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** Column header → canonical field name, tolerant to case, accents and common Hungarian/English variants. */
	private static function canonical_column( $header ) {
		$h = trim( strtolower( remove_accents( (string) $header ) ) );
		$h = trim( preg_replace( '/[^a-z0-9]+/', '_', $h ), '_' );
		$map = array(
			'title'       => 'title',
			'titulus'     => 'title',
			'given_name'  => 'given_name',
			'given'       => 'given_name',
			'first_name'  => 'given_name',
			'firstname'   => 'given_name',
			'utonev'      => 'given_name',
			'keresztnev'  => 'given_name',
			'family_name' => 'family_name',
			'family'      => 'family_name',
			'last_name'   => 'family_name',
			'lastname'    => 'family_name',
			'surname'     => 'family_name',
			'vezeteknev'  => 'family_name',
			'csaladnev'   => 'family_name',
			'email'       => 'email',
			'e_mail'      => 'email',
			'team'        => 'team',
			'team_name'   => 'team',
			'csapat'      => 'team',
			'csapatnev'   => 'team',
		);
		return isset( $map[ $h ] ) ? $map[ $h ] : '';
	}

	/**
	 * Parses CSV text into rows. Handles a UTF-8 BOM, Windows-1250 (Excel) text and ";" / "," / tab separators.
	 *
	 * @return array{rows: array, error: string}  rows: list of array(line, title, given_name, family_name, email, team)
	 */
	public static function parse_csv( $text ) {
		$text = (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text );
		if ( '' === trim( $text ) ) {
			return array( 'rows' => array(), 'error' => 'The CSV is empty.' );
		}
		if ( ! preg_match( '//u', $text ) ) { // not valid UTF-8: Excel in a Hungarian locale saves Windows-1250
			// iconv knows Windows-1250; mbstring does not (it throws on that name). ISO-8859-2 is the fallback: it
			// differs from Windows-1250 only in characters Hungarian does not use.
			$conv = function_exists( 'iconv' ) ? @iconv( 'Windows-1250', 'UTF-8//IGNORE', $text ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $conv || '' === $conv ) {
				$conv = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'UTF-8', 'ISO-8859-2' ) : $text;
			}
			$text = $conv;
		}

		$first = strtok( ltrim( $text ), "\r\n" );
		$delim = ';';
		$best  = -1;
		foreach ( array( ';', ',', "\t" ) as $d ) {
			$c = substr_count( (string) $first, $d );
			if ( $c > $best ) {
				$best  = $c;
				$delim = $d;
			}
		}

		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $fh, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rewind( $fh );

		$cols = null;
		$rows = array();
		$line = 0;
		while ( false !== ( $r = fgetcsv( $fh, 0, $delim ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$line++;
			if ( 1 === count( $r ) && ( null === $r[0] || '' === trim( $r[0] ) ) ) {
				continue; // blank line
			}
			if ( null === $cols ) {
				$cols = array();
				foreach ( $r as $i => $h ) {
					$c = self::canonical_column( $h );
					if ( $c && ! in_array( $c, $cols, true ) ) {
						$cols[ $i ] = $c;
					}
				}
				$have = array_values( $cols );
				if ( ! in_array( 'given_name', $have, true ) || ! in_array( 'family_name', $have, true ) ) {
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					return array( 'rows' => array(), 'error' => 'The first row must be a header with at least the columns given_name and family_name (found: ' . trim( (string) $first ) . ').' );
				}
				continue;
			}
			$row = array( 'line' => $line, 'title' => '', 'given_name' => '', 'family_name' => '', 'email' => '', 'team' => '' );
			foreach ( $cols as $i => $c ) {
				$row[ $c ] = isset( $r[ $i ] ) ? trim( (string) $r[ $i ] ) : '';
			}
			$rows[] = $row;
			if ( count( $rows ) > self::MAX_ROWS ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return array( 'rows' => array(), 'error' => 'Too many rows (max ' . self::MAX_ROWS . ' per import) – split the file.' );
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array( 'rows' => $rows, 'error' => $rows ? '' : 'No data rows found below the header.' );
	}

	/**
	 * Marks every parsed row: ok | error | dup_file | dup_existing, with a message. Only "ok" rows are created.
	 *
	 * @param array $existing  result of existing_index()
	 * @return array rows with added keys status, message (and the sanitized values)
	 */
	public static function validate_rows( $rows, $pass_only, $default_team, $existing ) {
		$seen = array();
		$out  = array();
		foreach ( $rows as $r ) {
			$r['title']       = sanitize_text_field( $r['title'] );
			$r['given_name']  = sanitize_text_field( $r['given_name'] );
			$r['family_name'] = sanitize_text_field( $r['family_name'] );
			$raw_email        = strtolower( trim( (string) $r['email'] ) );
			$r['team']        = sanitize_text_field( '' !== $r['team'] ? $r['team'] : $default_team );
			$r['status']      = 'ok';
			$r['message']     = '';

			// Checked before sanitizing: sanitize_email() turns an invalid address into '' and would hide the typo.
			$email_ok   = $pass_only ? ( '' === $raw_email || is_email( $raw_email ) ) : is_email( $raw_email );
			$r['email'] = $email_ok ? sanitize_email( $raw_email ) : $raw_email;
			if ( ! $r['given_name'] || ! $r['family_name'] ) {
				$r['status']  = 'error';
				$r['message'] = 'given_name and family_name are required';
			} elseif ( ! $email_ok ) {
				$r['status']  = 'error';
				$r['message'] = $pass_only ? 'the email is not valid' : 'a valid email is required (tick "Pass only" if these people need none)';
			} else {
				$key = '' !== $r['email'] ? $r['email'] : self::name_key( $r );
				if ( isset( $seen[ $key ] ) ) {
					$r['status']  = 'dup_file';
					$r['message'] = 'duplicate of row ' . $seen[ $key ];
				} elseif ( '' !== $r['email'] && isset( $existing['email'][ $r['email'] ] ) ) {
					$r['status']  = 'dup_existing';
					$r['message'] = 'already in the system (order #' . $existing['email'][ $r['email'] ] . ')';
				} elseif ( '' === $r['email'] && isset( $existing['name'][ self::name_key( $r ) ] ) ) {
					$r['status']  = 'dup_existing';
					$r['message'] = 'a person with this name is already in the system (order #' . $existing['name'][ self::name_key( $r ) ] . ')';
				}
				$seen[ $key ] = $r['line'];
			}
			$out[] = $r;
		}
		return $out;
	}

	/** Splits the importable rows into orders: grouped by team, at most CHUNK attendees per order. */
	public static function plan_orders( $rows ) {
		$by_team = array();
		foreach ( $rows as $r ) {
			if ( 'ok' !== $r['status'] ) {
				continue;
			}
			$by_team[ $r['team'] ][] = array(
				'title'       => $r['title'],
				'given_name'  => $r['given_name'],
				'family_name' => $r['family_name'],
				'email'       => $r['email'],
				'status'      => 'new',
			);
		}
		$orders = array();
		foreach ( $by_team as $team => $list ) {
			foreach ( array_chunk( $list, self::CHUNK ) as $chunk ) {
				$orders[] = array( 'team' => (string) $team, 'attendees' => $chunk );
			}
		}
		return $orders;
	}

	/** Step 1: parse + validate, keep the result server-side and show the preview. */
	public static function handle_csv_preview() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pretix_sync_comp_csv' );

		$product   = wc_get_product( isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0 );
		$note      = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$team      = isset( $_POST['team_name'] ) ? sanitize_text_field( wp_unslash( $_POST['team_name'] ) ) : '';
		$pass_only = ! empty( $_POST['pass_only'] );
		if ( ! $product || ! Pretix_Sync_Settings::is_ticket_product( $product ) ) {
			self::back_to_tools( 'Import not started. Select a pretix ticket product.' );
		}
		if ( '' === $note ) {
			self::back_to_tools( 'Import not started. Enter a reason / note.' );
		}

		$text = '';
		if ( ! empty( $_FILES['csv_file']['tmp_name'] ) && UPLOAD_ERR_OK === (int) $_FILES['csv_file']['error'] && is_uploaded_file( $_FILES['csv_file']['tmp_name'] ) ) { // phpcs:ignore
			if ( (int) $_FILES['csv_file']['size'] > 512 * 1024 ) { // phpcs:ignore
				self::back_to_tools( 'Import not started. The file is larger than 512 KB.' );
			}
			$text = (string) file_get_contents( $_FILES['csv_file']['tmp_name'] ); // phpcs:ignore
		} elseif ( ! empty( $_POST['csv_text'] ) ) {
			$text = (string) wp_unslash( $_POST['csv_text'] ); // phpcs:ignore
		}
		$parsed = self::parse_csv( $text );
		if ( $parsed['error'] ) {
			self::back_to_tools( 'Import not started. ' . $parsed['error'] );
		}

		$rows  = self::validate_rows( $parsed['rows'], $pass_only, $team, self::existing_index() );
		// Kisbetűs, mert a betöltés sanitize_key()-t használ, az pedig kisbetűsít: vegyes kis/nagybetűs tokennél a keresett
		// transient kulcs sosem egyezne a mentettel ("preview expired").
		$token = strtolower( wp_generate_password( 20, false ) );
		set_transient( 'pretix_sync_csv_' . $token, array(
			'user'       => get_current_user_id(),
			'product_id' => $product->get_id(),
			'note'       => $note,
			'pass_only'  => $pass_only,
			'rows'       => $rows,
		), HOUR_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'pretix_sync_csv', $token, admin_url( 'admin.php?page=pretix-sync' ) ) );
		exit;
	}

	private static function load_preview( $token ) {
		$data = get_transient( 'pretix_sync_csv_' . sanitize_key( $token ) );
		if ( ! is_array( $data ) || (int) $data['user'] !== get_current_user_id() ) {
			return null;
		}
		return $data;
	}

	/** Preview table above the forms (tools page). */
	public static function render_csv_preview() {
		if ( empty( $_GET['pretix_sync_csv'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$token = sanitize_key( wp_unslash( $_GET['pretix_sync_csv'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$data  = self::load_preview( $token );
		if ( ! $data ) {
			echo '<div class="notice notice-warning inline"><p>The import preview has expired or belongs to another user. Upload the CSV again.</p></div>';
			return;
		}
		$product = wc_get_product( $data['product_id'] );
		$plan    = self::plan_orders( $data['rows'] );
		$n_ok    = 0;
		$counts  = array( 'ok' => 0, 'error' => 0, 'dup_file' => 0, 'dup_existing' => 0 );
		foreach ( $data['rows'] as $r ) {
			$counts[ $r['status'] ]++;
		}
		foreach ( $plan as $o ) {
			$n_ok += count( $o['attendees'] );
		}
		$colors = array( 'ok' => '#008a20', 'error' => '#d63638', 'dup_file' => '#996800', 'dup_existing' => '#996800' );
		$labels = array( 'ok' => 'will be created', 'error' => 'error', 'dup_file' => 'skipped', 'dup_existing' => 'skipped' );

		echo '<div style="border:2px solid #2271b1;padding:0 16px 8px;margin:16px 0;max-width:1000px;background:#fff">';
		echo '<h2>CSV import – preview</h2>';
		echo '<p><strong>' . esc_html( $product ? $product->get_name() : '?' ) . '</strong>' . ( $data['pass_only'] ? ' · <strong>pass only</strong> (no emails)' : ' · attendees <strong>will be emailed</strong>' ) . ' · ' . esc_html( $data['note'] ) . '</p>';
		$dups = (int) $counts['dup_file'] + (int) $counts['dup_existing'];
		echo '<p>' . (int) $counts['ok'] . ' to create, ' . $dups . ' skipped as duplicates, ' . (int) $counts['error'] . ' with errors. ' . ( $plan ? 'This makes ' . count( $plan ) . ' order(s).' : '' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table class="widefat striped"><thead><tr><th>Row</th><th>Title</th><th>Given name</th><th>Family name</th><th>Email</th><th>Team</th><th>Result</th></tr></thead><tbody>';
		foreach ( $data['rows'] as $r ) {
			echo '<tr><td>' . (int) $r['line'] . '</td><td>' . esc_html( $r['title'] ) . '</td><td>' . esc_html( $r['given_name'] ) . '</td><td>' . esc_html( $r['family_name'] ) . '</td><td>' . esc_html( $r['email'] ) . '</td><td>' . esc_html( $r['team'] ) . '</td>';
			echo '<td><strong style="color:' . esc_attr( $colors[ $r['status'] ] ) . '">' . esc_html( $labels[ $r['status'] ] ) . '</strong>' . ( $r['message'] ? ' – ' . esc_html( $r['message'] ) : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( $n_ok ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
			wp_nonce_field( 'pretix_sync_comp_csv_confirm' );
			echo '<input type="hidden" name="action" value="pretix_sync_comp_csv_confirm"><input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
			$confirm = sprintf( 'Create %d attendee(s)? %s', $n_ok, $data['pass_only'] ? 'No emails are sent.' : 'They are emailed.' );
			echo '<button class="button button-primary" onclick="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')">Create ' . (int) $n_ok . ' attendee(s)</button> ';
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=pretix-sync' ) ) . '">Cancel</a></form>';
		} else {
			echo '<p><strong>Nothing to import.</strong> Fix the file and upload it again.</p>';
		}
		echo '</div>';
	}

	/** Step 2: create the orders from the stored (validated) preview. */
	public static function handle_csv_confirm() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden' );
		}
		check_admin_referer( 'pretix_sync_comp_csv_confirm' );

		$token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		$data  = self::load_preview( $token );
		if ( ! $data ) {
			self::back_to_tools( 'Nothing created: the import preview has expired. Upload the CSV again.' );
		}
		delete_transient( 'pretix_sync_csv_' . $token ); // one confirm per preview: a reload cannot create everything twice

		$product = wc_get_product( $data['product_id'] );
		if ( ! $product || ! Pretix_Sync_Settings::is_ticket_product( $product ) ) {
			self::back_to_tools( 'Nothing created: select a pretix ticket product.' );
		}
		$plan   = self::plan_orders( $data['rows'] );
		$people = 0;
		foreach ( $plan as $o ) {
			self::create_order( $product, $o['attendees'], $data['note'], $o['team'], (bool) $data['pass_only'] );
			$people += count( $o['attendees'] );
		}
		self::back_to_tools( sprintf( 'Created %d order(s) for %d attendee(s)%s. They are synced to pretix in the background (see the status counters below).', count( $plan ), $people, $data['pass_only'] ? ' – pass only, no emails' : '' ) );
	}
}
