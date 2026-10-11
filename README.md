# MakePay for Invoice Ninja

**1.0.0-rc.1:** connection and local integration checks passed; funded settlement remains a rollout prerequisite. See [validation evidence](docs/TESTING.md).

A hosted crypto payment gateway for **self-hosted Invoice Ninja v5**, with native
merchant connection fields and a client portal checkout screen. Built against
Invoice Ninja's BTCPay, Custom and BaseDriver conventions. This is a source
extension for self-hosted installations; it cannot be installed into Invoice
Ninja's hosted SaaS without their cooperation.

## Supported behavior

- Invoice currencies such as EUR and USD, including Invoice Ninja's calculated gateway fees and partial
  payment amounts. Customers choose supported crypto assets on MakePay checkout.
- MakePay company/API key connection fields in **Settings → Payment Settings**.
- Regular and Livewire client checkout pages.
- Signed asynchronous payment confirmation, native payment records, invoice
  balance updates and receipts.
- Persistent link reuse, duplicate webhook protection and company/gateway binding.
- Optional MakePay buttons in invoice PDFs, invoice emails and reminder emails.

The invoice currency is sent as `fiatCurrency`; the company's configured crypto
settlement asset is separate. Currency precision comes from Invoice Ninja.
Callbacks verify the saved fiat amount and currency, rather than the token symbol.
Refunds are handled in MakePay and recorded manually after settlement. Saved
payment methods and automatic recurring debits are not supported.

## Install

Back up your application and database. Download this repository to a separate
folder on your Invoice Ninja host. PHP 8.2+ and an installed Invoice Ninja v5
application are required.

```bash
php scripts/install.php /absolute/path/to/invoiceninja
```

The installer copies the driver/views, adds provider-based guards to
`Gateway::getHelp()` and `Gateway::getMethods()`, saves the original model as
`Gateway.php.makepay-backup`, registers MakePay in the existing gateways table,
and clears Laravel caches. It never stores merchant secrets. It is idempotent.
Run the installer again after Invoice Ninja upgrades, then rerun integration
checks against that version. Review the small model patch before a major upgrade.

## Connect

1. In MakeCrypto create a company-specific API key with
   `makepay:payment-links:read` and `makepay:payment-links:write`.
2. Configure a supported stablecoin settlement route for that company.
3. Copy the company ID, API key ID/secret and company webhook secret.
4. In Invoice Ninja open **Settings → Payment Settings → New Gateway** and
   choose **MakePay**.
5. Enter `companyId`, `apiKeyId`, `apiKeySecret`, and `webhookSecret`. Invoice
   Ninja encrypts gateway configuration. Enable the Crypto payment method.
6. Use Invoice Ninja's native webhook URL for the company gateway:

```text
https://your-ninja-domain/payment_webhook/COMPANY_KEY/HASHED_COMPANY_GATEWAY_ID
```

Set that HTTPS URL under **Integrations → API Integrations → Callback URL configuration** in MakeCrypto. The ID
is the hashed **company gateway** ID, not the global gateway ID. Invoice Ninja
exposes the company key and hashed company gateway ID in its API responses.
A MakeCrypto company has one default callback destination: use separate company
credentials for separate stores or route callbacks through a reviewed dispatcher.

## Checkout and verification

Open an unpaid invoice in the client portal, choose Crypto/MakePay, and click
**Continue to MakePay**. The server obtains the payable amount from Invoice
Ninja's payment hash, creates a hosted link, and redirects the customer. The
invoice becomes paid only after a verified `session.status = complete` webhook.
Pending, underpaid, failed, expired, and cancelled sessions remain unpaid.

If checkout creation has an ambiguous timeout, the saved `makepay.state` remains
`creating` to prevent a duplicate charge. Inspect MakeCrypto payment links for
`orderId = ninja_PAYMENT_HASH` and reconcile the saved UID/URL before allowing
another attempt. Never delete a reservation without checking provider history.

## PDF and email buttons

Use the included [PDF and email templates](docs/PDF-AND-EMAIL.md) to add **Pay with
MakePay** buttons. They open the invoice portal, where the customer selects
Crypto / MakePay. Invoice Ninja supports reminders, PDF email attachments and
native payment receipts. The guide explains setup and direct-link behavior when
multiple gateways are configured.

## Tests

```bash
php tests/protocol.php
php scripts/install.php /path/to/disposable/invoiceninja
php tests/integration.php /path/to/disposable/invoiceninja
```

The upstream app must have its dependencies installed, a local/testing `.env`,
and a migrated/seeded disposable MySQL database. Integration tests create fake
companies, clients and invoices, mock the MakePay API, then exercise the actual
native payment service and invoice balance updates. No real crypto is transferred.
See [upstream reference](docs/UPSTREAM-REFERENCE.md) for reviewed gateway code.

## Tutorial

See the [screenshot and video walkthrough](docs/TUTORIAL.md) and [validation record](docs/TESTING.md).
