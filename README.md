# opensalestax-stripe-php

> A Stripe Tax replacement for PHP SaaS — calculate US sales tax via [OpenSalesTax](https://github.com/ejosterberg/open-sales-tax) and apply it to your Stripe invoices and checkout sessions.

**Status:** v0.1 alpha. Not yet on Packagist.

## Why

Stripe Tax charges 0.5% per transaction. At $1M ARR a SaaS pays ~$5K/yr for sales-tax calculation that an OSS engine can do for $0. This connector is the bridge between your Stripe SaaS and a self-hosted OpenSalesTax instance.

## Install

```bash
composer require ejosterberg/opensalestax-stripe-php
```

(Once published. While the repo is private, install via the Git remote — see CONTRIBUTING.md for the dev install path.)

This connector requires:

- PHP 8.1+
- `stripe/stripe-php` ^20.0 (you almost certainly have this already)
- `ejosterberg/opensalestax` (the OpenSalesTax PHP SDK; auto-installed)
- A reachable OpenSalesTax engine (self-host: see the engine's [README](https://github.com/ejosterberg/open-sales-tax))

## Quickstart

```php
use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\Stripe\InvoiceCalculator;

$ost = new OpenSalesTaxClient(baseUrl: 'http://your-engine:8080');

// Inside your invoice.created webhook handler:
$invoice = \Stripe\Invoice::retrieve($event->data->object->id);

$breakdown = InvoiceCalculator::calculateForInvoice($invoice, $ost);

echo $breakdown->subtotal;          // "100.00"
echo $breakdown->taxTotal;          // "8.025"
echo $breakdown->combinedRatePct;   // "8.025"
foreach ($breakdown->jurisdictions as $j) {
    echo "{$j['type']:9} {$j['name']:50} {$j['rate_pct']}% \${$j['tax']}\n";
}

// Apply to the invoice (one combined TaxRate per state for v0.1; per-jurisdiction breakdown is in $breakdown for accounting)
$taxRate = \Stripe\TaxRate::create([
    'display_name' => 'Sales Tax',
    'percentage'   => (float) $breakdown->combinedRatePct,
    'inclusive'    => false,
    'jurisdiction' => $invoice->customer_address->state ?? 'US',
]);

\Stripe\Invoice::update($invoice->id, [
    'default_tax_rates' => [$taxRate->id],
]);
```

## Tax Code mapping

Each Stripe line item can carry a `tax_code` on its `Price` (e.g. `txcd_10103001` for SaaS). The connector translates Stripe's catalog into OpenSalesTax's 6 categories. Built-in mapping:

| Stripe code | OST category |
|---|---|
| `txcd_99999999` General Tangible Goods | `general` |
| `txcd_20030000` General Services | `general` |
| `txcd_10103001` SaaS Business / `txcd_10103000` SaaS Personal | `digital_goods` |
| `txcd_10101000` IaaS / `txcd_10102000` PaaS / `txcd_10105002` AIaaS | `digital_goods` |
| `txcd_10302000` Digital Books / `txcd_10402100` Digital Video / `txcd_10401100` Digital Audio / `txcd_10701100` Website Hosting | `digital_goods` |
| `txcd_00000000` Nontaxable | line is **excluded** from the OST calc; tax = 0 |
| anything else | falls through to `general` (a warning is emitted you can subscribe to) |

To override the mapping for your own catalog:

```php
use OpenSalesTax\Stripe\TaxCodeMap;

$customMap = new TaxCodeMap([
    'txcd_30060006' => 'clothing',           // your specific Stripe codes
    'txcd_40030003' => 'prepared_food',
]);

$breakdown = InvoiceCalculator::calculateForInvoice($invoice, $ost, $customMap);
```

## What's NOT in v0.1

- **Webhook handler / signature verification** — you write the webhook plumbing; this library is the calculator. (v0.2 will add a self-contained webhook handler.)
- **Stripe Tax compatibility shim** — exposing the same response shape Stripe's `/v1/tax/calculations` returns, so SaaS already integrating Stripe Tax can switch with one config line. (v0.3 marketing pitch.)
- **Non-USD invoices** — the engine is USD-only; non-USD invoices throw `NonUSDException`.
- **Stripe Connect / multi-account** — out of scope.
- **Subscription proration mid-cycle** — calculates at invoice-creation point only.

## Engine compatibility

Tested against **OpenSalesTax v0.22.0**. Pin both the connector and the engine in production:

```
ejosterberg/opensalestax-stripe-php: ^0.1
opensalestax engine:                 v0.22.x
```

## Disclaimer

> Tax calculations are provided as-is for convenience. The merchant is solely responsible for tax-collection accuracy and remittance to the appropriate jurisdictions. Verify against your state Department of Revenue before remitting.

## License

[Apache 2.0](LICENSE).
