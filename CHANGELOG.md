# Changelog

All notable changes documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [SemVer](https://semver.org).

## [Unreleased]

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
