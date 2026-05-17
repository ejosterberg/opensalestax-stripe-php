# opensalestax-stripe-php

> Replace Stripe Tax with self-hosted [OpenSalesTax](https://github.com/ejosterberg/open-sales-tax). Server-side US sales tax calculation library for PHP SaaS using Stripe.

[![License](https://img.shields.io/badge/license-Apache%202.0%20OR%20GPL%202.0--or--later-blue)](LICENSE) [![PHP](https://img.shields.io/badge/php-%E2%89%A58.2-777bb4)](composer.json)

**Status:** v0.1 alpha. Tested against `stripe/stripe-php` v20 + OpenSalesTax engine v0.24.

## What this saves you

Stripe Tax charges **0.5% per transaction** (or $0.50, whichever is greater). At scale, that's real money:

| Annual revenue | Stripe Tax annual cost | This connector + self-hosted engine |
|---|---:|---:|
| $50K MRR / $600K ARR | ~$3,000/yr | $0 software cost |
| $100K MRR / $1.2M ARR | ~$6,000/yr | $0 |
| $250K MRR / $3M ARR | ~$15,000/yr | $0 |
| $500K MRR / $6M ARR | ~$30,000/yr | $0 |

You still pay infrastructure: a small VM running the OpenSalesTax engine (~$20–50/mo) plus a few hours/year to deploy engine updates. Below ~$1M ARR, Stripe Tax is reasonable. Above that, the math flips fast.

## How it works

This connector reads a Stripe `Invoice` or `Checkout\Session`, calls your OpenSalesTax engine for the right ZIP-based tax, and returns a structured breakdown you apply back to the invoice via `Stripe\TaxRate`. Three-line core path:

```php
$breakdown = InvoiceCalculator::calculateForInvoice($invoice, $ostClient);
$taxRate   = \Stripe\TaxRate::create([...$breakdown->combinedRatePct... ]);
\Stripe\Invoice::update($invoice->id, ['default_tax_rates' => [$taxRate->id]]);
```

That's the entire integration shape. Full code below.

## Install

```bash
composer require ejosterberg/opensalestax-stripe-php
```

This pulls in:

- `ejosterberg/opensalestax` — the OST PHP SDK (calls your engine)
- `stripe/stripe-php` ^20.0 — the official Stripe SDK (you almost certainly have this already)

You'll also need:

- **PHP 8.2+** (uses class-level `readonly` syntax)
- **A reachable OpenSalesTax engine.** Self-host with the [engine's docker-compose](https://github.com/ejosterberg/open-sales-tax) — about 5 minutes if you have Docker.

## Production deployment guide

For an existing PHP SaaS already using Stripe for billing. **~30 minutes** if your codebase is tidy.

### Step 1 — Spin up an OpenSalesTax engine

```bash
git clone https://github.com/ejosterberg/open-sales-tax
cd open-sales-tax
docker compose up -d
curl http://localhost:8080/v1/health   # → {"status":"ok",...}
```

Production: deploy the same compose file to your own VM, point a hostname at it, lock down with a firewall or reverse proxy.

### Step 2 — Collect billing addresses on checkout

If your Stripe Checkout sessions don't already require a billing address, add one line to `Session::create()`:

```php
'billing_address_collection' => 'required',
```

Stripe persists the address on the Customer object; subsequent recurring invoices have it automatically — no further work needed.

### Step 3 — Add an invoice.created webhook handler

In your existing Stripe webhook router, add the case:

```php
case 'invoice.created':
    self::applyOpenSalesTax($event->data->object);
    break;
```

### Step 4 — Implement applyOpenSalesTax

```php
use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\Stripe\InvoiceCalculator;
use OpenSalesTax\Stripe\Exceptions\NonUSDException;
use OpenSalesTax\Stripe\Exceptions\MissingAddressException;

private static function applyOpenSalesTax(\Stripe\Invoice $invoice): void
{
    $client = new OpenSalesTaxClient(baseUrl: getenv('OPENSALESTAX_BASE_URL'));

    try {
        // Re-retrieve with expanded customer to ensure address is populated.
        $expanded = \Stripe\Invoice::retrieve([
            'id'     => $invoice->id,
            'expand' => ['customer'],
        ]);

        $breakdown = InvoiceCalculator::calculateForInvoice($expanded, $client);

        if ($breakdown->lines === [] || (float) $breakdown->taxTotal <= 0.0) {
            return;  // nothing to apply (zero-tax cart or all lines non-taxable)
        }

        $taxRate = \Stripe\TaxRate::create([
            'display_name' => 'Sales Tax',
            'percentage'   => (float) $breakdown->combinedRatePct,
            'inclusive'    => false,
            'jurisdiction' => 'US',
        ]);

        \Stripe\Invoice::update($invoice->id, [
            'default_tax_rates' => [$taxRate->id],
        ]);
    } catch (NonUSDException | MissingAddressException $e) {
        // Best-effort: log and let the invoice proceed without tax.
        // (Merchant handles compliance manually for these edge cases.)
        error_log('OST skip: ' . $e->getMessage());
    } catch (\Throwable $e) {
        error_log('OST error: ' . $e->getMessage());
    }
}
```

### Step 5 — Set OPENSALESTAX_BASE_URL on production

```
OPENSALESTAX_BASE_URL=http://your-engine:8080
OPENSALESTAX_API_KEY=          # optional; only set if your engine has API-key auth enabled
```

That's it. The next subscription invoice fired will be auto-taxed.

## Quickstart (just the calculator, no webhook integration)

If you just want to compute tax on demand:

```php
use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\Stripe\InvoiceCalculator;

$ost = new OpenSalesTaxClient(baseUrl: 'http://localhost:8080');
$invoice = \Stripe\Invoice::retrieve($invoiceId);

$breakdown = InvoiceCalculator::calculateForInvoice($invoice, $ost);

echo $breakdown->subtotal;          // "100.00"
echo $breakdown->taxTotal;          // "8.025"
echo $breakdown->combinedRatePct;   // "8.025"

foreach ($breakdown->lines as $line) {
    echo "  {$line['stripe_line_id']} ({$line['category']}): \${$line['tax']}\n";
    if ($line['note'] !== null) {
        echo "    → {$line['note']}\n";
    }
}

foreach ($breakdown->jurisdictions as $j) {
    echo "  {$j['type']:9} {$j['name']:50} {$j['rate_pct']}% \${$j['tax']}\n";
}
```

For Checkout Sessions (instead of invoices):

```php
use OpenSalesTax\Stripe\SessionCalculator;

$session = \Stripe\Checkout\Session::retrieve([
    'id'     => $sessionId,
    'expand' => ['line_items', 'line_items.data.price'],   // required: expand line_items
]);

$breakdown = SessionCalculator::calculateForCheckoutSession($session, $ost);
```

## Stripe Tax Code mapping

Each Stripe line item can carry a `tax_code` on its `Price` (e.g. `txcd_10103001` for SaaS Business). The connector translates Stripe's catalog into OpenSalesTax's 6 categories. Default mapping:

| Stripe code | OST category |
|---|---|
| `txcd_99999999` General Tangible Goods | `general` |
| `txcd_20030000` General Services | `general` |
| `txcd_10103001` SaaS Business / `txcd_10103000` SaaS Personal | `digital_goods` |
| `txcd_10101000` IaaS / `txcd_10102000` PaaS / `txcd_10105002` AIaaS | `digital_goods` |
| `txcd_10302000` Digital Books / `txcd_10402100` Digital Video / `txcd_10401100` Digital Audio / `txcd_10701100` Website Hosting | `digital_goods` |
| `txcd_00000000` Nontaxable | line is **excluded** from the OST calc; tax = 0 |
| anything else | falls through to `general` (a warning callback can capture these) |

Override the mapping for your own catalog:

```php
use OpenSalesTax\Stripe\TaxCodeMap;

$customMap = new TaxCodeMap([
    'txcd_30060006' => 'clothing',
    'txcd_40030003' => 'prepared_food',
]);

$breakdown = InvoiceCalculator::calculateForInvoice($invoice, $ost, $customMap);
```

The default table covers SaaS / digital products. For physical-goods merchants, expect to add specific Stripe codes for clothing / groceries / etc. depending on your catalog.

## How this compares

| | Cost | Self-hosted | Per-jurisdiction breakdown | Stripe-native flow |
|---|---|---|---|---|
| **Stripe Tax** | 0.5%/txn | ❌ | ✅ | ✅ |
| **TaxJar API** | from ~$0.50/txn | ❌ | ✅ | partial |
| **Avalara AvaTax** | enterprise pricing | ❌ | ✅ | partial |
| **OpenSalesTax + this connector** | $0 software | ✅ | ✅ | via `TaxRate.create` |

OpenSalesTax + this connector trades operational responsibility (run a small engine VM) for transactional cost (zero). For SaaS founders comfortable with Docker, that's an easy win above ~$50K MRR.

## What's NOT in v0.1

- **Webhook handler / signature verification** — you write the webhook plumbing; this library is the calculator. v0.2 will add a self-contained webhook handler.
- **Stripe Tax-compatible response shim** — exposing the same response shape Stripe's `/v1/tax/calculations` returns, so SaaS already integrating Stripe Tax can switch with one config line. v0.3 marketing pitch.
- **Non-USD invoices** — engine is USD-only; non-USD invoices throw `NonUSDException` (callers can log + skip).
- **Stripe Connect / multi-account** — out of scope; multi-account flows have meaningfully different tax-collection-responsibility semantics.
- **Subscription proration mid-cycle** — calculates at invoice-creation point; for partial-period invoices the tax is point-in-time correct but proration semantics aren't formally validated.

## Quality bar

- **PHPStan level=max** — zero suppressed errors
- **PHP-CS-Fixer** with PSR-12 + risky rules — zero violations
- **PHPUnit** — 29 unit tests, 100 assertions, all passing
- **GitHub Actions CI** matrix on PHP 8.2 / 8.3 / 8.4
- **DCO sign-off** required on every commit

## Engine compatibility

Tested against **OpenSalesTax v0.24**. Engine v0.22+ recommended for production (a state-bleed data bug was fixed in v0.22). Pin both:

```
ejosterberg/opensalestax-stripe-php: ^0.1
opensalestax engine:                 v0.22+
```

## Disclaimer

> Tax calculations are provided as-is for convenience. The merchant is solely responsible for tax-collection accuracy and remittance to the appropriate jurisdictions. Verify against your state Department of Revenue before remitting.

## Contributing

DCO sign-off (`git commit -s`) required on every commit. See [CONTRIBUTING.md](CONTRIBUTING.md). Dual-licensed Apache-2.0 OR GPL-2.0-or-later + SPDX header on every source file.

## License

Dual-licensed under your choice of [Apache-2.0](LICENSE-APACHE.txt) OR [GPL-2.0-or-later](LICENSE-GPL.txt). See [LICENSE](LICENSE).
