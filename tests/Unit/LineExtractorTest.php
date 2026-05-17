<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\Stripe\Tests\Unit;

use OpenSalesTax\Stripe\Exceptions\UnsupportedSourceException;
use OpenSalesTax\Stripe\LineExtractor;
use OpenSalesTax\Stripe\TaxCodeMap;
use PHPUnit\Framework\TestCase;
use Stripe\Checkout\Session;
use Stripe\Invoice;
use Stripe\Util\Util;

final class LineExtractorTest extends TestCase
{
    public function testInvoiceMixedCart(): void
    {
        $invoice = $this->loadInvoice('invoice-mn-mixed.json');
        $extracted = LineExtractor::fromInvoice($invoice, new TaxCodeMap());

        // 3 lines in fixture: SaaS (taxable, $100), tangible goods (taxable, $50),
        // nontaxable credit ($25). Expect 2 OST line items + 1 skipped.
        self::assertCount(2, $extracted['line_items']);
        self::assertCount(2, $extracted['stripe_line_ids']);
        self::assertSame(['il_3ccc'], $extracted['skipped_nontaxable_ids']);

        self::assertSame('100.00', $extracted['line_items'][0]->amount);
        self::assertSame('digital_goods', $extracted['line_items'][0]->category);
        self::assertSame('il_1aaa', $extracted['stripe_line_ids'][0]);

        self::assertSame('50.00', $extracted['line_items'][1]->amount);
        self::assertSame('general', $extracted['line_items'][1]->category);
        self::assertSame('il_2bbb', $extracted['stripe_line_ids'][1]);
    }

    public function testCheckoutSessionSingleLine(): void
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/checkout-session-mn.json');
        self::assertNotFalse($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $session = Util::convertToStripeObject($decoded, []);
        self::assertInstanceOf(Session::class, $session);

        $extracted = LineExtractor::fromCheckoutSession($session, new TaxCodeMap());

        self::assertCount(1, $extracted['line_items']);
        self::assertSame('75.00', $extracted['line_items'][0]->amount);
        self::assertSame('digital_goods', $extracted['line_items'][0]->category);
        self::assertSame('li_1xxx', $extracted['stripe_line_ids'][0]);
    }

    public function testInvoiceWithoutLinesThrows(): void
    {
        $invoice = Util::convertToStripeObject([
            'id' => 'in_empty',
            'object' => 'invoice',
            'currency' => 'usd',
        ], []);
        self::assertInstanceOf(Invoice::class, $invoice);

        $this->expectException(UnsupportedSourceException::class);
        LineExtractor::fromInvoice($invoice, new TaxCodeMap());
    }

    public function testZeroAmountLinesAreSkipped(): void
    {
        $invoice = Util::convertToStripeObject([
            'id' => 'in_zero',
            'object' => 'invoice',
            'currency' => 'usd',
            'lines' => [
                'object' => 'list',
                'data' => [
                    [
                        'id' => 'il_zero',
                        'object' => 'line_item',
                        'amount' => 0,
                        'currency' => 'usd',
                        'price' => [
                            'id' => 'price_proration',
                            'object' => 'price',
                            'tax_code' => 'txcd_99999999',
                        ],
                    ],
                ],
            ],
        ], []);
        self::assertInstanceOf(Invoice::class, $invoice);

        $extracted = LineExtractor::fromInvoice($invoice, new TaxCodeMap());
        self::assertCount(0, $extracted['line_items']);
    }

    public function testCentsToDecimalConversion(): void
    {
        $invoice = Util::convertToStripeObject([
            'id' => 'in_cents',
            'object' => 'invoice',
            'currency' => 'usd',
            'lines' => [
                'object' => 'list',
                'data' => [
                    [
                        'id' => 'il_99c',
                        'object' => 'line_item',
                        'amount' => 9999,
                        'currency' => 'usd',
                        'price' => ['object' => 'price', 'tax_code' => 'txcd_99999999'],
                    ],
                ],
            ],
        ], []);
        self::assertInstanceOf(Invoice::class, $invoice);

        $extracted = LineExtractor::fromInvoice($invoice, new TaxCodeMap());
        self::assertSame('99.99', $extracted['line_items'][0]->amount);
    }

    public function testTaxCodeFallsBackThroughLayers(): void
    {
        // Line has no direct tax_code, no price.tax_code, but price.product.tax_code
        $invoice = Util::convertToStripeObject([
            'id' => 'in_nested',
            'object' => 'invoice',
            'currency' => 'usd',
            'lines' => [
                'object' => 'list',
                'data' => [
                    [
                        'id' => 'il_nested',
                        'object' => 'line_item',
                        'amount' => 1000,
                        'currency' => 'usd',
                        'price' => [
                            'id' => 'price_no_tax_code',
                            'object' => 'price',
                            'product' => [
                                'id' => 'prod_with_tax_code',
                                'object' => 'product',
                                'tax_code' => 'txcd_10103001',
                            ],
                        ],
                    ],
                ],
            ],
        ], []);
        self::assertInstanceOf(Invoice::class, $invoice);

        $extracted = LineExtractor::fromInvoice($invoice, new TaxCodeMap());
        self::assertCount(1, $extracted['line_items']);
        self::assertSame('digital_goods', $extracted['line_items'][0]->category);
    }

    private function loadInvoice(string $name): Invoice
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertNotFalse($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $invoice = Util::convertToStripeObject($decoded, []);
        self::assertInstanceOf(Invoice::class, $invoice);
        return $invoice;
    }
}
