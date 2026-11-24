<?php
defined( 'ABSPATH' ) || exit;

/**
 * Attendee emails, sent with the WooCommerce email template (header/footer/colors from WooCommerce → Settings → Emails).
 * Texts can be overridden with the filters "pretix_sync_email_subject" and "pretix_sync_email_body".
 */
class Pretix_Sync_Mailer {

	/**
	 * @param string $type  'ticket'   – ticket confirmed, lecture registration not open yet
	 *                      'open'     – lecture registration is open (sent when registration opens)
	 *                      'welcome'  – ticket bought while registration is already open
	 */
	public static function send( $type, $attendee, $order ) {
		$event   = Pretix_Sync_Settings::get( 'event_name' );
		$name    = esc_html( trim( ( isset( $attendee['title'] ) ? $attendee['title'] . ' ' : '' ) . $attendee['given_name'] . ' ' . $attendee['family_name'] ) );
		$buyer   = esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );
		if ( 'pretix-sync-complimentary' === $order->get_created_via() ) {
			$buyer = ''; // complimentary ticket: nobody "booked" it
		}
		$talks   = esc_url( Pretix_Sync_Settings::talks_url() );
		$pwreset = esc_url( Pretix_Sync_Settings::password_reset_url() );
		$is_new  = ! empty( $attendee['customer_new'] );
		$p       = 'style="margin:0 0 16px"';

		$account_block = $is_new
			? "<p $p>We have created an account for you with this email address. You will receive a separate email with a link to <strong>set your password</strong> (the link is valid for one day). If it has expired, you can set your password here at any time: <a href=\"$pwreset\">$pwreset</a></p>"
			: "<p $p>Log in with your existing account for this email address. Forgot your password? Reset it here: <a href=\"$pwreset\">$pwreset</a></p>";

		$pass_block = "<p $p>Your personal <strong>event pass</strong> with your QR code will be handed out at the venue. Wear it at all times – it is your entry to every lecture you have registered for.</p>";

		switch ( $type ) {
			case 'ticket':
				$subject = sprintf( 'Your %s ticket', $event );
				$heading = 'Your ticket is confirmed';
				$body    = "<p $p>Hello $name,</p>"
					. "<p $p>You have a ticket for the <strong>$event</strong>" . ( $buyer ? " (booked by $buyer)" : '' ) . '.</p>'
					. "<p $p>Seats at the lectures are limited, so you need to register for the lectures you want to attend. <strong>Lecture registration is not open yet</strong> – we will send you an email as soon as it opens.</p>"
					. $pass_block
					. "<p $p>See you at the $event!</p>";
				break;

			case 'open':
				$subject = sprintf( 'Lecture registration is now open – %s', $event );
				$heading = 'Lecture registration is open';
				$body    = "<p $p>Hello $name,</p>"
					. "<p $p>Registration for the lectures of the <strong>$event</strong> is now open. Seats are limited, so register early:</p>"
					. "<p $p><a href=\"$talks\" style=\"font-weight:bold\">Register for lectures</a></p>"
					. $account_block
					. $pass_block
					. "<p $p>See you at the $event!</p>";
				break;

			default: // welcome
				$subject = sprintf( 'Your %s ticket – register for lectures', $event );
				$heading = 'Your ticket is confirmed';
				$body    = "<p $p>Hello $name,</p>"
					. "<p $p>You have a ticket for the <strong>$event</strong>" . ( $buyer ? " (booked by $buyer)" : '' ) . '.</p>'
					. "<p $p>Seats at the lectures are limited, so please register for the lectures you want to attend:</p>"
					. "<p $p><a href=\"$talks\" style=\"font-weight:bold\">Register for lectures</a></p>"
					. $account_block
					. $pass_block
					. "<p $p>See you at the $event!</p>";
		}

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
