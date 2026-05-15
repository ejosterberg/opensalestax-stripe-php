# Security Policy

## Reporting a vulnerability

Email **ejosterberg@gmail.com** with subject line starting
`[opensalestax-stripe-php] security:`. Include affected
version, reproduction steps, and impact. Do not open a public
GitHub issue for security reports.

Acknowledgement target: 7 days. Critical issues
(tax-correctness errors, leakage of customer billing data,
or Stripe-credential exposure paths): mark `[critical]` in
subject, expect faster turnaround.

Disclosure window: 90 days from acknowledgement, or sooner
once a fix ships.

## Supported versions

Latest minor on `main`. Older releases are not back-patched.

## Threat model

This is a server-side library used by a PHP SaaS application
(e.g., a subscription product) to apply destination-based US
sales tax to Stripe Invoices and Checkout Sessions, computed
by a self-hosted OpenSalesTax engine.

The library runs in the SaaS's PHP process. It does NOT add
inbound HTTP endpoints. It calls outbound to:

- The OST engine (URL configured by the SaaS operator) — via
  the `opensalestax` SDK
- The Stripe API (using the SaaS's own Stripe key, which the
  SaaS code passes in) — via `stripe/stripe-php`

What the library reads from the Stripe Invoice / Checkout
Session: customer billing address (ZIP gates the calc), line
items (amounts + currency), Stripe Tax Code metadata (mapped
to OST category strings). It does NOT read: payment method
details, card numbers, customer email/phone, internal Stripe
account state.

What the library returns: a `TaxBreakdown` DTO carrying
per-jurisdiction rate + amount data. The SaaS decides how to
surface that to the customer (invoice line, receipt, etc.).

## Out of scope

- The OST engine itself — report at
  https://github.com/ejosterberg/open-sales-tax/issues
- `stripe/stripe-php` — report upstream
- Misuse by the consuming SaaS (e.g., logging the
  `TaxBreakdown` with customer email) — the consumer is
  responsible for their own logging hygiene

## Disclaimer

> Tax calculations are provided as-is for convenience. The
> merchant is solely responsible for tax-collection accuracy
> and remittance to the appropriate jurisdictions. Verify
> against your state Department of Revenue before remitting.
