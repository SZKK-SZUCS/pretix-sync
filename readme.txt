=== Pretix Sync ===

WooCommerce ticket sales → pretix session registration with one QR pass per attendee.

Setup
1. wp-config.php:  define( 'PRETIX_SYNC_TOKEN', '<pretix API token>' );
2. Activate the plugin. Flag every ticket product (Product → General → "Pretix ticket").
3. WooCommerce → Settings → Pretix Sync: check URL, organizer, sessions event slug, membership type ID, validity.
4. WooCommerce → Pretix Sync → "Test pretix connection".
5. pretix → Organizer → Webhooks: add the webhook URL shown on the tools page.
   Events: order placed, paid, canceled, reactivated, changed (all), modified. Limit to the sessions event.
6. The checkout page must use the classic checkout ([woocommerce_checkout] or the Elementor Checkout widget).

Modules (WooCommerce → Settings → Pretix Sync → Modules)
- Always on: attendee details at checkout, one pass (QR) per attendee, ticket email.
- Session registration: pretix account + membership per attendee, sessions event, webhook, "open registration" tool.
  Off = ticket and pass only (no pretix account, no webhook, no registration emails).
- Complimentary / staff tickets: complimentary orders, pass-only mode, CSV import (tools page).
- Badge printing: on as soon as a Passes event slug is set.

Emails
- Subject, heading and body of the three emails (ticket / registration open / welcome) are editable in the settings
  (Emails section). Empty field = default text. Placeholders: {name} {event_name} {buyer_note} {register_url}
  {pwreset_url} {account_note} {registration_note}.

Languages (English / Hungarian, Polylang optional)
- Checkout fields and the default email texts are translated (Hungarian .mo in /languages). Change/extend:
  edit languages/pretix-sync-hu_HU.po, then run `python tools/build-i18n.py` (rebuilds the .pot and the .mo files and
  fails if a string is missing).
- Every order remembers the language it was placed in; its emails, the pretix account and the links into the pretix shop
  (via pretix' /locale/set) use that language, also when the job runs in the background.
- With Polylang: email texts and the event name you edit in the settings appear under Languages → Translations
  (group "Pretix Sync") and are sent in the order's language. For a bilingual shop install Polylang for WooCommerce
  too (the plugin warns if it is missing). The ticket flag / pretix item IDs are set once, on the default-language product.
- Complimentary orders: pick the email language on the form (with Polylang).

Flow
- Paid order → per attendee: pretix reusable medium (pass QR) + "ticket" email.
- "Open session registration" (tools page) → per attendee: pretix customer (activation email), membership,
  medium linked to customer, "registration open" email. Later buyers get everything at once.
- pretix webhook → session registrations are linked to the attendee's pass, cancellations unlinked.
- Refunded / cancelled order → registrations canceled, membership invalidated, pass deactivated.

Badge printing (optional, v1.2)
- pretix: a separate "Passes" event with one 0 € pass product per ticket type (admission, personalized).
- Plugin settings: "Passes event slug". Product → General: "pretix pass item ID" for each ticket product.
- Per attendee the plugin creates a 0 € pass order there (no email) and links it to the attendee's pass.
  Badges → layout with a QR code whose content is "Reusable Medium ID"; Export → Badges prints them all.
- Existing attendees: tools page → "Re-run sync for all orders" creates the missing badge tickets.
- Team name: the order's custom field "team_name" (checkout field / complimentary form) is written to the badge
  ticket's "Company" field. In the badge layout add a text element with content "Attendee company". A changed team
  name is picked up by "Re-run sync for all orders".
- Name edits update the badge ticket; revoke/refund cancels it.

Staff / pass only
- Tools page → Complimentary tickets: tick "Pass only" → the pass (QR) and badge ticket are created, but NO emails are
  sent, NO pretix account/membership is created and there is no session registration (also not after registration
  opens). The email is optional. The pass is handed out at the venue after printing.
- All access at the sessions: set Product → General → "pretix all-access item ID" (staff product, e.g. the
  "All access (staff)" item of the sessions event). Each attendee then gets a free ticket of that item in the sessions
  event, linked straight to their pass. The item must be on the session check-in lists, otherwise the pass is not
  accepted at the rooms (pretix → sessions event → Check-in → every session list → Products).
- CSV import (same page; "Download sample CSV" gives a template): header row with given_name, family_name, email (optional: title, team), separator ; or ,
  UTF-8 or Windows-1250. A preview shows every row (ok / duplicate / error) and nothing is created before you confirm.
  People already in the system are skipped (by email, or by name when there is no email). Max 300 rows, 20 per order.
- Revoke cancels the pass ticket and the all-access ticket.

Filters: pretix_sync_email_subject, pretix_sync_email_body ($value, $type, $attendee, $order)
Logs: WooCommerce → Status → Logs, source "pretix-sync". Queue: Tools → Scheduled Actions, group "pretix-sync".
