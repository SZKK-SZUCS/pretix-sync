<?php
defined( 'ABSPATH' ) || exit;

/**
 * Attendee emails, sent with the WooCommerce email template (header/footer/colors from WooCommerce → Settings → Emails).
 *
 * Subject, heading and body of every email type are editable under WooCommerce → Settings → Pretix Sync; an empty
 * field falls back to the default below (English, Hungarian translation shipped in /languages). Edited texts are
 * Polylang strings, so each language can have its own version. Texts can also be overridden in code with the filters
 * "pretix_sync_email_subject" and "pretix_sync_email_body".
 *
 * The email goes out in the language the order was placed in (see Pretix_Sync_I18n).
 *
 * Placeholders (the same set in all fields; subject and heading use the plain-text values):
 *   {name} {event_name} {buyer_note} {register_url} {pwreset_url} {account_note} {registration_note}
 */
class Pretix_Sync_Mailer {

	/**
	 * Default texts in the current locale. The "ticket" email says nothing about sessions when the sessions module is
	 * off ({registration_note} is empty then).
	 *
	 * @return array<string, array{label: string, subject: string, heading: string, body: string}>
	 */
	public static function defaults() {
		return array(
			'ticket'  => array(
				'label'   => __( 'Ticket confirmed (session registration not open yet, or sessions module off)', 'pretix-sync' ),
				'subject' => __( 'Your {event_name} ticket', 'pretix-sync' ),
				'heading' => __( 'Your ticket is confirmed', 'pretix-sync' ),
				'body'    => __( "<p>Hello {name},</p>\n<p>You have a ticket for <strong>{event_name}</strong>{buyer_note}.</p>\n{registration_note}\n<p>Your personal <strong>event pass</strong> with your QR code will be handed out at the venue. Wear it at all times – it is your entry to the event.</p>\n<p>See you at {event_name}!</p>", 'pretix-sync' ),
			),
			'open'    => array(
				'label'   => __( 'Session registration opened (sent to everyone who already has a ticket)', 'pretix-sync' ),
				'subject' => __( 'Session registration is now open – {event_name}', 'pretix-sync' ),
				'heading' => __( 'Session registration is open', 'pretix-sync' ),
				'body'    => __( "<p>Hello {name},</p>\n<p>Registration for the sessions of <strong>{event_name}</strong> is now open. Seats are limited, so register early:</p>\n<p><a href=\"{register_url}\" style=\"font-weight:bold\">Register for sessions</a></p>\n{account_note}\n<p>Your personal <strong>event pass</strong> with your QR code will be handed out at the venue. Wear it at all times – it is your entry to the event.</p>\n<p>See you at {event_name}!</p>", 'pretix-sync' ),
			),
			'welcome' => array(
				'label'   => __( 'Ticket bought while session registration is already open', 'pretix-sync' ),
				'subject' => __( 'Your {event_name} ticket – register for sessions', 'pretix-sync' ),
				'heading' => __( 'Your ticket is confirmed', 'pretix-sync' ),
				'body'    => __( "<p>Hello {name},</p>\n<p>You have a ticket for <strong>{event_name}</strong>{buyer_note}.</p>\n<p>Seats at the sessions are limited, so please register for the sessions you want to attend:</p>\n<p><a href=\"{register_url}\" style=\"font-weight:bold\">Register for sessions</a></p>\n{account_note}\n<p>Your personal <strong>event pass</strong> with your QR code will be handed out at the venue. Wear it at all times – it is your entry to the event.</p>\n<p>See you at {event_name}!</p>", 'pretix-sync' ),
			),
		);
	}

	/**
	 * One template field: the admin's text (in $lang, via Polylang) or the default.
	 *
	 * @param array|null $defaults  defaults() computed in the right locale; null = current locale
	 */
	public static function template( $type, $field, $lang = '', $defaults = null ) {
		$stored = (string) get_option( 'pretix_sync_mail_' . $type . '_' . $field, '' );
		if ( '' !== trim( $stored ) ) {
			return Pretix_Sync_I18n::translate( $stored, $lang );
		}
		$defaults = null === $defaults ? self::defaults() : $defaults;
		return $defaults[ $type ][ $field ];
	}

	/** WooCommerce settings fields for the email templates. */
	public static function settings_fields() {
		$fields = array(
			array(
				'title' => 'Emails',
				'type'  => 'title',
				'desc'  => 'The fields hold the default texts (in the language of your admin screen) – edit them freely. A field left unchanged keeps following the language of each order (English / Hungarian); clear a field and save to restore the default. A changed text is sent as it is for every language' . ( Pretix_Sync_I18n::polylang_active() ? '' : ' (use Polylang to translate it)' ) . '. Placeholders: <code>{name}</code> <code>{event_name}</code> <code>{buyer_note}</code> <code>{register_url}</code> <code>{pwreset_url}</code> <code>{account_note}</code> <code>{registration_note}</code>. HTML is allowed in the body.'
					. ( Pretix_Sync_I18n::polylang_active() ? ' <strong>Texts you enter here are translated under Languages → Translations (group “Pretix Sync”).</strong>' : '' ),
				'id'    => 'pretix_sync_emails',
			),
		);
		foreach ( self::defaults() as $type => $d ) {
			$fields[] = array( 'title' => $d['label'] . ': subject', 'id' => 'pretix_sync_mail_' . $type . '_subject', 'type' => 'text', 'default' => $d['subject'], 'css' => 'min-width:350px' );
			$fields[] = array( 'title' => 'Heading', 'id' => 'pretix_sync_mail_' . $type . '_heading', 'type' => 'text', 'default' => $d['heading'], 'css' => 'min-width:350px' );
			$fields[] = array( 'title' => 'Body', 'id' => 'pretix_sync_mail_' . $type . '_body', 'type' => 'textarea', 'default' => $d['body'], 'css' => 'min-width:350px;height:160px;font-family:monospace' );
		}
		$fields[] = array( 'type' => 'sectionend', 'id' => 'pretix_sync_emails' );
		return $fields;
	}

	/** Normalised for comparing a posted text with a default (textareas post CRLF). */
	private static function norm( $s ) {
		return trim( str_replace( "
", "
", (string) $s ) );
	}

	/**
	 * Settings screen: a field that was never edited (stored empty) is shown with the default text as its real,
	 * editable value, not as a placeholder.
	 */
	public static function show_defaults() {
		foreach ( self::defaults() as $type => $d ) {
			foreach ( array( 'subject', 'heading', 'body' ) as $field ) {
				$default = $d[ $field ];
				add_filter( 'option_pretix_sync_mail_' . $type . '_' . $field, function ( $v ) use ( $default ) {
					return '' === trim( (string) $v ) ? $default : $v;
				} );
			}
		}
	}

	/**
	 * Saving: a text still equal to the default is stored as empty, so it keeps following the order's language
	 * instead of being frozen in the admin's language.
	 */
	public static function store_defaults_as_empty() {
		foreach ( self::defaults() as $type => $d ) {
			foreach ( array( 'subject', 'heading', 'body' ) as $field ) {
				$default = self::norm( $d[ $field ] );
				add_filter( 'woocommerce_admin_settings_sanitize_option_pretix_sync_mail_' . $type . '_' . $field, function ( $v ) use ( $default ) {
					return self::norm( $v ) === $default ? '' : $v;
				} );
			}
		}
	}

	/**
	 * @param string $type  'ticket'   – ticket confirmed, session registration not open yet (or sessions module off)
	 *                      'open'     – session registration is open (sent when registration opens)
	 *                      'welcome'  – ticket bought while registration is already open
	 */
	public static function send( $type, $attendee, $order ) {
		if ( ! in_array( $type, array( 'ticket', 'open' ), true ) ) {
			$type = 'welcome';
		}
		// Everything below (default texts, notes, the event name) is produced in the order's language.
		$switched = Pretix_Sync_I18n::switch_to( $order );
		$ctx      = Pretix_Sync_I18n::order_language( $order );
		try {
			$sent = self::send_in_language( $type, $attendee, $order, $ctx['lang'] );
		} finally {
			Pretix_Sync_I18n::restore( $switched );
		}
		return $sent;
	}

	private static function send_in_language( $type, $attendee, $order, $lang ) {
		$event_raw = Pretix_Sync_I18n::translate( Pretix_Sync_Settings::get( 'event_name' ), $lang );
		if ( '' === $event_raw ) {
			$event_raw = get_bloginfo( 'name' );
		}
		$name_raw = trim( ( isset( $attendee['title'] ) ? $attendee['title'] . ' ' : '' ) . $attendee['given_name'] . ' ' . $attendee['family_name'] );
		$buyer    = esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );
		if ( 'pretix-sync-complimentary' === $order->get_created_via() ) {
			$buyer = ''; // complimentary ticket: nobody "booked" it
		}
		$sessions = esc_url( Pretix_Sync_I18n::pretix_link( Pretix_Sync_Settings::sessions_url(), $order ) );
		$pwreset  = esc_url( Pretix_Sync_I18n::pretix_link( Pretix_Sync_Settings::password_reset_url(), $order ) );

		$account_note = ! empty( $attendee['customer_new'] )
			/* translators: %s: password reset URL */
			? sprintf( __( '<p>We have created an account for you with this email address. You will receive a separate email with a link to <strong>set your password</strong> (the link is valid for one day). If it has expired, you can set your password here at any time: <a href="%1$s">%1$s</a></p>', 'pretix-sync' ), $pwreset )
			/* translators: %s: password reset URL */
			: sprintf( __( '<p>Log in with your existing account for this email address. Forgot your password? Reset it here: <a href="%1$s">%1$s</a></p>', 'pretix-sync' ), $pwreset );

		$registration_note = Pretix_Sync_Settings::sessions_enabled()
			? __( '<p>Seats at the sessions are limited, so you need to register for the sessions you want to attend. <strong>Session registration is not open yet</strong> – we will send you an email as soon as it opens.</p>', 'pretix-sync' )
			: '';

		$html  = array(
			'{name}'              => esc_html( $name_raw ),
			'{event_name}'        => esc_html( $event_raw ),
			/* translators: %s: name of the person who bought the ticket */
			'{buyer_note}'        => $buyer ? sprintf( __( ' (booked by %s)', 'pretix-sync' ), $buyer ) : '',
			'{register_url}'      => $sessions,
			'{pwreset_url}'       => $pwreset,
			'{account_note}'      => $account_note,
			'{registration_note}' => $registration_note,
		);
		$plain = array(
			'{name}'       => $name_raw,
			'{event_name}' => $event_raw,
		);

		$defaults = self::defaults();
		$subject  = strtr( self::template( $type, 'subject', $lang, $defaults ), $plain );
		$heading  = strtr( self::template( $type, 'heading', $lang, $defaults ), $plain );
		$body     = strtr( self::template( $type, 'body', $lang, $defaults ), $html );
		// Templates are written as plain <p>; the WooCommerce email wrapper needs the spacing inline.
		$body = str_replace( '<p>', '<p style="margin:0 0 16px">', $body );

		$subject = apply_filters( 'pretix_sync_email_subject', $subject, $type, $attendee, $order );
		$body    = apply_filters( 'pretix_sync_email_body', $body, $type, $attendee, $order );

		$mailer  = WC()->mailer();
		$message = $mailer->wrap_message( $heading, $body );
		$email   = new WC_Email();
		$message = $email->style_inline( $message );

		$sent = $mailer->send( $attendee['email'], $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
		pretix_sync_log( sprintf( 'Email "%s" (%s) to %s (order #%d): %s', $type, $lang ? $lang : get_locale(), $attendee['email'], $order->get_id(), $sent ? 'sent' : 'FAILED' ), $sent ? 'info' : 'error' );
		return $sent;
	}
}
