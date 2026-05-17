<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\Stripe\Tests\Integration;

use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\Stripe\InvoiceCalculator;
use PHPUnit\Framework\TestCase;
use Stripe\Customer;
use Stripe\Invoice;
use Stripe\InvoiceItem;
use Stripe\Stripe;

/**
 * Integration test against Stripe test mode + a live OpenSalesTax engine.
 *
 * Skipped unless BOTH `STRIPE_TEST_KEY` and `OPENSALESTAX_BASE_URL` env
 * vars are set. Costs nothing in test mode but creates real test-mode
 * resources you may want to clean up periodically.
 *
 * Locally:
 *   STRIPE_TEST_KEY=sk_test_... \
 *   OPENSALESTAX_BASE_URL=http://10.32.161.126:8080 \
 *     vendor/bin/phpunit --testsuite=integration
 */
final class StripeMockedTest extends TestCase
{
    private OpenSalesTaxClient $ost;
    private string $stripeKey;

    protected function setUp(): void
    {
        $stripeKey = getenv('STRIPE_TEST_KEY');
        $ostBase = getenv('OPENSALESTAX_BASE_URL');

        if ($stripeKey === false || $stripeKey === '' || $ostBase === false || $ostBase === '') {
            self::markTestSkipped(
                'STRIPE_TEST_KEY and/or OPENSALESTAX_BASE_URL not set; skipping live integration test.',
            );
        }

        if (!str_starts_with($stripeKey, 'sk_test_')) {
            self::markTestSkipped('STRIPE_TEST_KEY does not look like a test-mode key (should start with sk_test_).');
        }

        $this->stripeKey = $stripeKey;
        $this->ost = new OpenSalesTaxClient(baseUrl: $ostBase);
        Stripe::setApiKey($this->stripeKey);
    }

    public function testCalculatesTaxOnRealStripeMnInvoice(): void
    {
        // 1. Create a test-mode Stripe customer with a Minneapolis MN address
        $customer = Customer::create([
            'email' => 'test-' . uniqid('', true) . '@example.com',
            'name' => 'OpenSalesTax Integration Test',
            'address' => [
                'line1' => '100 Test St',
                'city' => 'Minneapolis',
                'state' => 'MN',
                'postal_code' => '55401',
                'country' => 'US',
            ],
        ]);

        // 2. Add a SaaS-line invoice item ($100, txcd_10103001 SaaS Business)
        InvoiceItem::create([
            'customer' => $customer->id,
            'amount' => 10000,
            'currency' => 'usd',
            'description' => 'OST integration test â€” SaaS Pro plan',
            // tax_behavior:exclusive ensures Stripe doesn't try to figure out tax itself
            'tax_behavior' => 'exclusive',
        ]);

        // 3. Create a draft invoice for that customer
        $invoice = Invoice::create([
            'customer' => $customer->id,
            'collection_method' => 'send_invoice',
            'days_until_due' => 30,
            'auto_advance' => false,
        ]);

        // 4. Re-retrieve to get expanded lines
        $invoice = Invoice::retrieve($invoice->id);

        // 5. Run the calculator
        $breakdown = InvoiceCalculator::calculateForInvoice($invoice, $this->ost);

        // 6. Asserts: subtotal matches, tax is non-zero, MN appears in jurisdictions
        self::assertSame('100.00', $breakdown->subtotal);
        self::assertGreaterThan(0.0, (float) $breakdown->taxTotal, 'Expected non-zero tax for MN customer');

        $hasMnState = false;
        foreach ($breakdown->jurisdictions as $j) {
            if ($j['name'] === 'Minnesota' && $j['type'] === 'state') {
                $hasMnState = true;
                break;
            }
        }
        self::assertTrue($hasMnState, 'Expected Minnesota state to appear in the jurisdiction breakdown');

        // Cleanup: void the draft invoice + delete the test customer
        try {
            $invoice->voidInvoice();
        } catch (\Throwable) {
            // best-effort cleanup; don't fail the test on cleanup hiccup
        }
        try {
            $customer->delete();
        } catch (\Throwable) {
            // ditto
        }
    }
}
