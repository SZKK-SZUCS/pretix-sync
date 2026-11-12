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
- Team name: the order's custom field "team_name" (checkout field / complimentary form) is written to the badge
  ticket's "Company" field. In the badge layout add a text element with content "Attendee company". A changed team
  name is picked up by "Re-run sync for all orders".
- Name edits update the badge ticket; revoke/refund cancels it.

Staff / pass only
- Tools page → Complimentary tickets: tick "Pass only" → the pass (QR) and badge ticket are created, but NO emails are
  sent, NO pretix account/membership is created and there is no lecture registration (also not after registration
  opens). The email is optional. The pass is handed out at the venue after printing.
- All access at the lectures: set Product → General → "pretix all-access item ID" (staff product, e.g. the
  "All access (staff)" item of the lectures event). Each attendee then gets a free ticket of that item in the lectures
  event, linked straight to their pass. The item must be on the lecture check-in lists, otherwise the pass is not
  accepted at the rooms (pretix → lectures event → Check-in → every lecture list → Products).
- CSV import (same page; "Download sample CSV" gives a template): header row with given_name, family_name, email (optional: title, team), separator ; or ,
  UTF-8 or Windows-1250. A preview shows every row (ok / duplicate / error) and nothing is created before you confirm.
  People already in the system are skipped (by email, or by name when there is no email). Max 300 rows, 20 per order.
- Revoke cancels the pass ticket and the all-access ticket.

Filters: fss_pretix_email_subject, fss_pretix_email_body ($value, $type, $attendee, $order)
Logs: WooCommerce → Status → Logs, source "fss-pretix". Queue: Tools → Scheduled Actions, group "fss-pretix".
