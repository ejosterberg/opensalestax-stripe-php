# Changelog

All notable changes documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [SemVer](https://semver.org).

## [Unreleased]

## [0.2.0] — 2026-05-19

### Added

- **CP-9 first-class shipping support on Checkout Sessions.**
  `SessionCalculator::calculateForCheckoutSession()` now extracts
  the session's `shipping_cost.amount_subtotal` (in minor units) and
  sends it to the OpenSalesTax engine as a top-level `shipping`
  field via `ejosterberg/opensalestax` v0.3.0. The engine applies
  per-state shipping-taxability rules internally — MN's
  "tax-if-items-taxable", MO/VA's "separately-stated", MD's
  "shipping-vs-handling" distinction — so callers don't have to
  reinvent them.
- `TaxBreakdown::$shipping` (`CalculatedShipping|null`) surfaces the
  engine's per-state shipping tax to the consumer. Null when the
  session has no `shipping_cost` OR the engine returned no shipping
  result. 2 new unit tests covering the with-shipping and
  without-shipping paths (asserts both the outgoing wire body AND
  the breakdown surface).
- Invoice path unchanged — Stripe Invoice doesn't have a separate
  pre-tax shipping field, so the existing line-item path is the
  right shape. A future minor release can add per-line shipping
  detection (`tax_code='txcd_99999999'` or
  `description LIKE 'Shipping%'`) if merchant feedback indicates
  demand.

### Changed

- **Bumps `ejosterberg/opensalestax` constraint from `^0.2.0` to
  `^0.3.0`.** Picks up the new third arg on `Client::calculate(addr,
  lines, shipping?)` plus `CalculateResponse::$shipping` and
  `$coverageWarning`. Backward compatible — Invoice and shipping-
  less Session paths behave identically to v0.1.3.

### Notes

- Engine v0.59.0+ required for shipping to be honored. Older
  engines silently ignore the field; the breakdown's shipping is
  null and tax is item-only.

## [0.1.3] — 2026-05-19

### Changed

- **CP-8 Phase 5D: bumped `ejosterberg/opensalestax` constraint to `^0.2.0`.**
  Picks up the new `OpenSalesTax\Client::capabilities()` /
  `OpenSalesTax\Client::capabilitiesCached()` helpers for engine v0.59.0's
  `/v1/capabilities` endpoint. No merchant-visible behavior change in
  this release — the helper is available to connector code but not yet
  wired into any feature path. Constraint bump only; Test Connection
  surface enrichment deferred to v-next.

## [0.1.2] — 2026-05-17

### Changed

- **Dual-licensed Apache-2.0 OR GPL-2.0-or-later.** Adds GPL-2.0-or-later as
  an alternative license alongside the existing Apache-2.0 grant, enabling
  downstream redistribution in GPL-only ecosystems without giving up Apache
  compatibility. License files reorganized: `LICENSE-APACHE.txt` (existing
  Apache text, moved from `LICENSE`), `LICENSE-GPL.txt` (new, GNU GPL v2
  text), `LICENSE` (new dual-declaration). SPDX headers updated across
  source files. `composer.json` `license` field switched from string to
  array form. Brings this connector in line with the rest of the
  OpenSalesTax portfolio's dual-licensing standard.
- **SDK requirement tightened to `^0.1.1`** (was `^0.1`) — pin the published
  stable instead of accepting the bootstrap `0.1.0`. `composer.lock`
  refreshed; no API-surface changes.

### Added

- **`.github/dependabot.yml`** — weekly checks for composer + GitHub Actions
  dependencies, with grouped dev-dep PRs. Brings this repo in line with
  the rest of the OpenSalesTax connector portfolio's supply-chain hygiene
  standard.

## [0.1.1] — 2026-05-15

### Fixed
- **composer.json now installable from Packagist.** v0.1.0's `require` block contained `"ejosterberg/opensalestax": "dev-main as 0.1.0"` plus a path-repo `repositories` block pointing at `../opensalestax-php`. That setup only resolved when the SDK was checked out alongside this repo on disk. Now that `ejosterberg/opensalestax` is published on Packagist:
  - Removed the `repositories` path-repo block.
  - Changed the SDK requirement to `"ejosterberg/opensalestax": "^0.1"` — clean SemVer constraint resolving from Packagist directly.
  - `composer require ejosterberg/opensalestax-stripe-php:^0.1` now works for any consumer, not just Eric's local development tree.
- CI workflow dropped the cross-repo "Checkout PHP SDK alongside" step that paired with the path-repo. Composer resolves the SDK from Packagist normally now.

### Added
- SECURITY.md (vulnerability reporting + threat model + 90-day disclosure window).

### Changed
- CHANGELOG line claiming "GitHub Actions CI on PHP 8.1 / 8.2 / 8.3" corrected to "8.2 / 8.3 / 8.4" (composer.json has always required PHP >=8.2).

### Notes
- No source-code or public-API changes. The wire contract with Stripe + the OpenSalesTax engine is identical.
- v0.1.0 was effectively un-installable from Packagist; v0.1.1 is the first version that any merchant can actually `composer require`.

## [0.1.0] — 2026-05-04

### Added
- Initial v0.1 alpha against engine v0.22.0 + `ejosterberg/opensalestax` v0.1.0
- `OpenSalesTax\Stripe\InvoiceCalculator::calculateForInvoice($invoice, $client)` — server-side library for Stripe invoices
- `OpenSalesTax\Stripe\SessionCalculator::calculateForCheckoutSession($session, $client)` — same shape for Checkout Sessions
- `OpenSalesTax\Stripe\TaxBreakdown` — readonly result DTO with subtotal, tax total, combined rate, per-line breakdown, per-jurisdiction breakdown, engine disclaimer
- `OpenSalesTax\Stripe\TaxCodeMap` — Stripe Tax Code → OST category translation; default 12-code table; injectable for custom mappings
- `OpenSalesTax\Stripe\AddressExtractor` — billing-address cascade across `customer_address`, `customer_shipping`, hydrated customer
- `OpenSalesTax\Stripe\LineExtractor` — pulls line amount + tax code per Stripe invoice line / session line item
- Exceptions: `OpenSalesTaxStripeException` (base), `NonUSDException`, `MissingAddressException`, `UnsupportedSourceException`
- PHPUnit test suite — fixture-based unit tests + gated live integration test
- GitHub Actions CI on PHP 8.2 / 8.3 / 8.4
