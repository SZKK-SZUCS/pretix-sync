<?php
defined( 'ABSPATH' ) || exit;

/**
 * Attendee emails, sent with the WooCommerce email template (header/footer/colors from WooCommerce → Settings → Emails).
 *
 * Subject, heading and body of every email type are editable under WooCommerce → Settings → Pretix Sync; an empty
 * field falls back to the default below. Texts can also be overridden in code with the filters
 * "pretix_sync_email_subject" and "pretix_sync_email_body".
 *
 * Placeholders (the same set in all fields; subject and heading use the plain-text values):
 *   {name} {event_name} {buyer_note} {register_url} {pwreset_url} {account_note} {registration_note}
 */
class Pretix_Sync_Mailer {

	/**
	 * Default texts. The "ticket" email says nothing about sessions when the sessions module is off
	 * ({registration_note} is empty then).
	 *
	 * @return array<string, array{label: string, subject: string, heading: string, body: string}>
	 */
	public static function defaults() {
		$pass = '<p>Your personal <strong>event pass</strong> with your QR code will be handed out at the venue. Wear it at all times – it is your entry to the event.</p>';
		$bye  = '<p>See you at {event_name}!</p>';
		return array(
			'ticket'  => array(
				'label'   => 'Ticket confirmed (session registration not open yet, or sessions module off)',
				'subject' => 'Your {event_name} ticket',
				'heading' => 'Your ticket is confirmed',
				'body'    => "<p>Hello {name},</p>\n<p>You have a ticket for <strong>{event_name}</strong>{buyer_note}.</p>\n{registration_note}\n" . $pass . "\n" . $bye,
			),
			'open'    => array(
				'label'   => 'Session registration opened (sent to everyone who already has a ticket)',
				'subject' => 'Session registration is now open – {event_name}',
				'heading' => 'Session registration is open',
				'body'    => "<p>Hello {name},</p>\n<p>Registration for the sessions of <strong>{event_name}</strong> is now open. Seats are limited, so register early:</p>\n<p><a href=\"{register_url}\" style=\"font-weight:bold\">Register for sessions</a></p>\n{account_note}\n" . $pass . "\n" . $bye,
			),
			'welcome' => array(
				'label'   => 'Ticket bought while session registration is already open',
				'subject' => 'Your {event_name} ticket – register for sessions',
				'heading' => 'Your ticket is confirmed',
				'body'    => "<p>Hello {name},</p>\n<p>You have a ticket for <strong>{event_name}</strong>{buyer_note}.</p>\n<p>Seats at the sessions are limited, so please register for the sessions you want to attend:</p>\n<p><a href=\"{register_url}\" style=\"font-weight:bold\">Register for sessions</a></p>\n{account_note}\n" . $pass . "\n" . $bye,
			),
		);
	}

	/** One stored template field, falling back to the default when empty. */
	public static function template( $type, $field ) {
		$defaults = self::defaults();
		$stored   = (string) get_option( 'pretix_sync_mail_' . $type . '_' . $field, '' );
		return '' !== trim( $stored ) ? $stored : $defaults[ $type ][ $field ];
	}

	/** WooCommerce settings fields for the email templates. */
	public static function settings_fields() {
		$fields = array(
			array(
				'title' => 'Emails',
				'type'  => 'title',
				'desc'  => 'Leave a field empty to use the default text (shown greyed out). Placeholders: <code>{name}</code> <code>{event_name}</code> <code>{buyer_note}</code> <code>{register_url}</code> <code>{pwreset_url}</code> <code>{account_note}</code> <code>{registration_note}</code>. HTML is allowed in the body.',
				'id'    => 'pretix_sync_emails',
			),
		);
		foreach ( self::defaults() as $type => $d ) {
			$fields[] = array( 'title' => $d['label'] . ': subject', 'id' => 'pretix_sync_mail_' . $type . '_subject', 'type' => 'text', 'default' => '', 'placeholder' => $d['subject'], 'css' => 'min-width:350px' );
			$fields[] = array( 'title' => 'Heading', 'id' => 'pretix_sync_mail_' . $type . '_heading', 'type' => 'text', 'default' => '', 'placeholder' => $d['heading'], 'css' => 'min-width:350px' );
			$fields[] = array( 'title' => 'Body', 'id' => 'pretix_sync_mail_' . $type . '_body', 'type' => 'textarea', 'default' => '', 'placeholder' => $d['body'], 'css' => 'min-width:350px;height:160px;font-family:monospace' );
		}
		$fields[] = array( 'type' => 'sectionend', 'id' => 'pretix_sync_emails' );
		return $fields;
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
		$event_raw = Pretix_Sync_Settings::get( 'event_name' );
		if ( '' === $event_raw ) {
			$event_raw = get_bloginfo( 'name' );
		}
		$name_raw = trim( ( isset( $attendee['title'] ) ? $attendee['title'] . ' ' : '' ) . $attendee['given_name'] . ' ' . $attendee['family_name'] );
		$buyer    = esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );
		if ( 'pretix-sync-complimentary' === $order->get_created_via() ) {
			$buyer = ''; // complimentary ticket: nobody "booked" it
		}
		$sessions = esc_url( Pretix_Sync_Settings::sessions_url() );
		$pwreset  = esc_url( Pretix_Sync_Settings::password_reset_url() );

		$account_note = ! empty( $attendee['customer_new'] )
			? "<p>We have created an account for you with this email address. You will receive a separate email with a link to <strong>set your password</strong> (the link is valid for one day). If it has expired, you can set your password here at any time: <a href=\"$pwreset\">$pwreset</a></p>"
			: "<p>Log in with your existing account for this email address. Forgot your password? Reset it here: <a href=\"$pwreset\">$pwreset</a></p>";

		$registration_note = Pretix_Sync_Settings::sessions_enabled()
			? '<p>Seats at the sessions are limited, so you need to register for the sessions you want to attend. <strong>Session registration is not open yet</strong> – we will send you an email as soon as it opens.</p>'
			: '';

		$html  = array(
			'{name}'              => esc_html( $name_raw ),
			'{event_name}'        => esc_html( $event_raw ),
			'{buyer_note}'        => $buyer ? " (booked by $buyer)" : '',
			'{register_url}'      => $sessions,
			'{pwreset_url}'       => $pwreset,
			'{account_note}'      => $account_note,
			'{registration_note}' => $registration_note,
		);
		$plain = array(
			'{name}'       => $name_raw,
			'{event_name}' => $event_raw,
		);

		$subject = strtr( self::template( $type, 'subject' ), $plain );
		$heading = strtr( self::template( $type, 'heading' ), $plain );
		$body    = strtr( self::template( $type, 'body' ), $html );
		// Templates are written as plain <p>; the WooCommerce email wrapper needs the spacing inline.
		$body = str_replace( '<p>', '<p style="margin:0 0 16px">', $body );

		$subject = apply_filters( 'pretix_sync_email_subject', $subject, $type, $attendee, $order );
		$body    = apply_filters( 'pretix_sync_email_body', $body, $type, $attendee, $order );

		$mailer  = WC()->mailer();
		$message = $mailer->wrap_message( $heading, $body );
		$email   = new WC_Email();
		$message = $email->style_inline( $message );

		$sent = $mailer->send( $attendee['email'], $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
		pretix_sync_log( sprintf( 'Email "%s" to %s (order #%d): %s', $type, $attendee['email'], $order->get_id(), $sent ? 'sent' : 'FAILED' ), $sent ? 'info' : 'error' );
		return $sent;
	}
}
