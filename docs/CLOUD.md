# Invoice Ninja Cloud

Hosted Invoice Ninja Pro and Enterprise can connect to MakePay through the Invoice
Ninja API. No server access or PHP installation is needed. The connector is managed
in MakeCrypto's existing **Bookkeeping → Integrations** page.

Full walkthrough: [Invoice Ninja (cloud)](https://www.makecrypto.io/documentation/makepay/apps/invoice-ninja-cloud).

## Connect

1. Configure settlement for your merchant in MakeCrypto.
2. In the correct Invoice Ninja company, open **Settings → Account Management →
   Integrations → API Tokens**, create an administrator token and copy it securely.
3. Open your MakeCrypto merchant's **Bookkeeping → Integrations → Invoice Ninja Cloud**.
4. Paste the token and select an unused invoice custom field (1–4).
5. Click **Connect Invoice Ninja**, verify the company name, then **Sync invoices**.

The token is encrypted on MakeCrypto's server. It allows invoice reads, payment-URL
updates and payment recording in the selected company. Setup labels the selected
field and creates invoice-change webhook subscriptions. It preserves unrelated
fields and your existing MakePay callback configuration.

MakePay does not appear in Invoice Ninja Cloud's built-in gateway selector. Customers
use a MakePay link in the invoice PDF or email. The separate PHP extension in this
repository provides a native gateway for self-hosted Invoice Ninja.

## Invoice currency and checkout

Create and issue a test invoice. Its client currency overrides the group and company
defaults, as in Invoice Ninja. USD and EUR are supported; the invoice currency is
independent of the merchant's crypto settlement asset.

Bookkeeping shows Invoice Ninja invoices alongside native invoices. Open the payment
link to verify its invoice number, currency and outstanding balance. Drafts have a
stable URL for template preparation but cannot collect payment. Checkout fetches the
current invoice balance before creating a payment link. Paid, cancelled, archived and
deleted invoices cannot collect another payment.

## PDF button and visible fallback

In the connected MakeCrypto panel, click **Copy PDF snippet**. In Invoice Ninja,
copy a design under **Settings → Invoice Design → Custom Designs**, enable **HTML
Mode**, and paste the snippet in **Body** after the totals. Select that saved design
for your invoice and regenerate the PDF after syncing it.

The snippet uses the selected field, such as `$invoice.custom1`. It adds a **Pay with
MakePay** button and a visibly underlined payment URL with space below the button.
Test the actual downloaded PDF in your viewer. Older downloads retain their old
contents. If the URL is missing, sync and regenerate before sending it.

## Invoice emails and reminders

Use **Copy email snippet**, then paste its HTML into the invoice email and reminder
templates under **Settings → Templates & Reminders**. Retain Invoice Ninja's native
`$view_button` as a fallback. Enable PDF attachments if required.

The email snippet uses the recipient's `$view_url` invitation. It resolves the correct
MakePay invoice link even when the first email of a recurring invoice precedes its
first background sync. Do not replace it with the PDF custom-field variable. Recurring
invoices can request payment, but this connection does not automatically debit crypto.

Complete any hosted phone/sender verification. Send an invoice and reminder to a QA
inbox and check their received buttons and attached PDF. Invoice Ninja controls its
email sending, reminders and payment receipts.

## Payment recording and recovery

A redirect alone does not mark the invoice paid. Background reconciliation checks an
authoritative MakePay settlement against the merchant, checkout, amount and invoice
currency. It then creates a Cryptocurrency payment in Invoice Ninja and allocates it
to that invoice. Duplicate processing and retries reuse the same payment reference.

If the invoice client, currency or balance changed after checkout, review the payment
before requesting another one. Disconnect pauses open checkouts and deactivates links;
completed payments awaiting recording must be resolved first. Reconnecting the same
company restores its stable links. Handle refunds separately and record them manually
after confirming settlement.

## Validation scope

The Cloud implementation has 18 focused connector tests, including USD/EUR currency
resolution, exact amounts, duplicate prevention, lost-response recovery, webhook
authentication, disconnect protection and closed-invoice guards. Migration checks
cover row-level security, rate limits and concurrency leases. Real hosted QA invoices
and downloaded PDFs verify the vendor API and PDF link annotations.

These checks include unfunded test checkouts and simulated settlement workflows.
They do not constitute a completed funded crypto transaction. Perform a controlled
funded payment and verify Invoice Ninja's recorded balance before merchant rollout.
