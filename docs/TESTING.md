# Validation record

Recorded 2026-10-11. Candidate release: 1.0.0-rc.1.

## Upstream and environment

Invoice Ninja `v5-stable`, commit `f1ffa5f9989afbed4e7dc80b5c0cc18944335ffc`; PHP 8.5.5 locally, MySQL 8.4, Laravel's native client portal at localhost. The installer ran twice successfully without duplicate gateway registrations. CI uses PHP 8.4 and the same pinned upstream commit.

## Automated checks

`php tests/protocol.php`: 22 checks for HMAC verification, timestamp window, exact amounts and payment reference matching.

`php -d memory_limit=512M tests/integration.php /path/to/disposable/invoiceninja`: 29 native checks, including driver/settings discovery, actual client payment-method discovery, credential validation, server totals, persistent link reuse, status filtering, stale signatures, wrong UID/amount/currency, company gateway binding, native payment allocation, duplicate delivery, full HTTP routing, native fee quote, fee-inclusive partial payment and one-time fee recording. Requests to MakePay are mocked; the upstream models, MySQL transactions, fee calculations and payment service are real.

The HTTP endpoint was also exercised through the running Laravel server: an invalid signature returned 401 and a duplicate complete callback returned 200 `already_completed`. The native route is `/payment_webhook/{company_key}/{hashed_company_gateway_id}`; it is outside the `/api/v1` route group.

## Browser and real API checks

The real native portal offered Crypto, displayed the MakePay gateway screen, and redirected through the production MakePay API to a hosted Demo Shop 2 checkout displaying **10 USD**. A dedicated approved merchant QA key and signing secret were stored in encrypted gateway configuration. No secrets are committed or included in tutorial images.

The Paid screenshot is from a local signed-callback simulation. The hosted-checkout screenshot is from the real API connection. No crypto was transferred. Localhost cannot receive Internet callbacks without a public HTTPS route; a funded payment and automatic provider delivery remain required before customer rollout.

## Reproduce

Use a disposable local/testing Invoice Ninja application with its dependencies installed and database migrated/seeded. See `tests/ci.env` and the GitHub Actions workflow. Run the installer, protocol and integration checks. To create an unpaid browser fixture, pass `--portal-fixture` to the integration script; it prints an invitation URL. `output-fixture.json` is ignored and contains test references only.

The native automated suite now creates and completes an EUR invoice, preserving `fiatCurrency` independently of USDC settlement. It also rejects mismatched fiat amounts/currencies. Protocol checks cover zero and three decimal price precision.
