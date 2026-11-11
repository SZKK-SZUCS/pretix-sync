=== FSS pretix sync ===

WooCommerce ticket sales → pretix lecture registration with one QR pass per attendee.

Setup
1. wp-config.php:  define( 'FSS_PRETIX_TOKEN', '<pretix API token>' );
2. Activate the plugin. Products 1759, 1757 and 2707 are flagged as FSS tickets automatically
   (Product → General → "FSS ticket (pretix)").
3. WooCommerce → Settings → FSS pretix: check URL, organizer, lectures event slug, membership type ID, validity.
4. WooCommerce → FSS pretix → "Test pretix connection".
5. pretix → Organizer → Webhooks: add the webhook URL shown on the tools page.
   Events: order placed, paid, canceled, reactivated, changed (all), modified. Limit to the lectures event.
6. The checkout page must use the classic checkout ([woocommerce_checkout] or the Elementor Checkout widget).

Flow
- Paid order → per attendee: pretix reusable medium (pass QR) + "ticket" email.
- "Open lecture registration" (tools page) → per attendee: pretix customer (activation email), membership,
  medium linked to customer, "registration open" email. Later buyers get everything at once.
- pretix webhook → lecture registrations are linked to the attendee's pass, cancellations unlinked.
- Refunded / cancelled order → registrations canceled, membership invalidated, pass deactivated.

Badge printing (optional, v1.2)
- pretix: event "FSS 2026 – Passes" with one 0 € pass product per ticket type (admission, personalized).
- Plugin settings: "Passes event slug". Product → General: "pretix pass item ID" for each FSS ticket product.
- Per attendee the plugin creates a 0 € pass order there (no email) and links it to the attendee's pass.
  Badges → layout with a QR code whose content is "Reusable Medium ID"; Export → Badges prints them all.
- Existing attendees: tools page → "Re-run sync for all orders" creates the missing badge tickets.
- Name edits update the badge ticket; revoke/refund cancels it.

Filters: fss_pretix_email_subject, fss_pretix_email_body ($value, $type, $attendee, $order)
Logs: WooCommerce → Status → Logs, source "fss-pretix". Queue: Tools → Scheduled Actions, group "fss-pretix".
