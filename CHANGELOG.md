# Changelog

All notable changes documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning: [SemVer](https://semver.org).

## [Unreleased]

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
- GitHub Actions CI on PHP 8.1 / 8.2 / 8.3
