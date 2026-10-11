# Invoice Ninja gateway implementation references

This integration follows the structure of Invoice Ninja's maintained gateways.
The MakePay implementation is original code; upstream classes remain in Invoice
Ninja and are not redistributed in this package.

Reviewed upstream: `invoiceninja/invoiceninja`, `v5-stable`, commit
`f1ffa5f9989afbed4e7dc80b5c0cc18944335ffc` (11 October 2026).

- `app/PaymentDrivers/BTCPayPaymentDriver.php`: gateway discovery, credential
  validation, crypto method registration and webhook entry point.
- `app/PaymentDrivers/BTCPay/BTCPay.php`: hosted redirect, client currency and
  invoice number, native portal data, regular and Livewire views.
- `app/PaymentDrivers/CustomPaymentDriver.php`: provider configuration and
  portal layout conventions.
- `app/PaymentDrivers/BaseDriver.php`: `createPayment()` performs gateway fee
  confirmation, attaches invoices, updates balances and creates native receipts.
- `app/Models/CompanyGateway.php`: encrypted configuration and provider-based
  class discovery.
- `app/Http/Requests/Payments/PaymentWebhookRequest.php`: existing company-scoped
  webhook route.

Differences necessary for MakePay: do not trust browser amount/currency; reserve
creation in the database; verify timestamped raw-body HMAC before accessing a
payment hash; match UID, fiat amount/currency and merchant order reference; only a session
whose status is exactly `complete` can complete payment; serialize callbacks
with a row lock; prohibit cross-gateway/company callbacks. A redirect never
creates a payment. Refunds and automatic recurring debits are unsupported.

Native integration tests exercise the installed driver against the upstream
Laravel app and MySQL, including the actual balance update through `BaseDriver`.
