<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\Stripe\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\Stripe\Exceptions\NonUSDException;
use OpenSalesTax\Stripe\InvoiceCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Stripe\Invoice;
use Stripe\Util\Util;

final class InvoiceCalculatorTest extends TestCase
{
    public function testCalculatesForMnMixedCart(): void
    {
        $invoice = $this->loadInvoice('invoice-mn-mixed.json');

        // Engine response â€” 2 line items in (after nontaxable was filtered out).
        // SaaS line $100 @ ~7% + general line $50 @ ~7% with MN jurisdiction stack.
        $cannedResponse = [
            'subtotal' => '150.00',
            'tax_total' => '12.0375',
            'lines' => [
                [
                    'amount' => '100.00',
                    'category' => 'digital_goods',
                    'tax' => '8.0250',
                    'rate_pct' => '8.025',
                    'jurisdictions' => [
                        ['name' => 'Minnesota', 'type' => 'state', 'rate_pct' => '6.875', 'tax' => '6.8750'],
                        ['name' => 'Hennepin County', 'type' => 'county', 'rate_pct' => '0.15', 'tax' => '0.1500'],
                        ['name' => 'Minneapolis', 'type' => 'city', 'rate_pct' => '0.5', 'tax' => '0.5000'],
                        ['name' => 'Hennepin County Transit', 'type' => 'district', 'rate_pct' => '0.5', 'tax' => '0.5000'],
                    ],
                ],
                [
                    'amount' => '50.00',
                    'category' => 'general',
                    'tax' => '4.0125',
                    'rate_pct' => '8.025',
                    'jurisdictions' => [
                        ['name' => 'Minnesota', 'type' => 'state', 'rate_pct' => '6.875', 'tax' => '3.4375'],
                        ['name' => 'Hennepin County', 'type' => 'county', 'rate_pct' => '0.15', 'tax' => '0.0750'],
                        ['name' => 'Minneapolis', 'type' => 'city', 'rate_pct' => '0.5', 'tax' => '0.2500'],
                        ['name' => 'Hennepin County Transit', 'type' => 'district', 'rate_pct' => '0.5', 'tax' => '0.2500'],
                    ],
                ],
            ],
            'disclaimer' => 'Calculation only; not legal or tax advice. Verify against your state Department of Revenue before remitting.',
        ];

        $ostClient = $this->buildOstClient($cannedResponse, 200);

        $breakdown = InvoiceCalculator::calculateForInvoice($invoice, $ostClient);

        self::assertSame('150.00', $breakdown->subtotal);
        self::assertSame('12.0375', $breakdown->taxTotal);
        self::assertSame('8.02500', $breakdown->combinedRatePct);

        // 2 lines (the txcd_00000000 nontaxable line was excluded)
        self::assertCount(2, $breakdown->lines);
        self::assertSame('il_1aaa', $breakdown->lines[0]['stripe_line_id']);
        self::assertSame('il_2bbb', $breakdown->lines[1]['stripe_line_id']);

        // Skipped non-taxable bookkeeping
        self::assertSame(['il_3ccc'], $breakdown->skippedNontaxableLineIds);

        // Aggregated jurisdictions: 4 unique authorities, summed across lines
        self::assertCount(4, $breakdown->jurisdictions);
        $minnesotaState = array_values(array_filter(
            $breakdown->jurisdictions,
            static fn ($j) => $j['name'] === 'Minnesota',
        ))[0];
        self::assertSame('10.3125', $minnesotaState['tax']); // 6.875 + 3.4375 = 10.3125
    }

    public function testNonUsdCurrencyThrows(): void
    {
        $invoice = Util::convertToStripeObject([
            'id' => 'in_eur',
            'object' => 'invoice',
            'currency' => 'eur',
            'customer_address' => ['postal_code' => '55401'],
            'lines' => [
                'object' => 'list',
                'data' => [],
            ],
        ], []);
        self::assertInstanceOf(Invoice::class, $invoice);

        // Build a client that should never get called (currency check is upstream)
        $ostClient = $this->buildOstClient(['unused' => true], 200);

        $this->expectException(NonUSDException::class);
        $this->expectExceptionMessageMatches('/eur.*in_eur/');
        InvoiceCalculator::calculateForInvoice($invoice, $ostClient);
    }

    public function testAllNontaxableLinesShortCircuitsEngine(): void
    {
        // Invoice where every line is the nontaxable code â€” should NOT call the engine
        $invoice = Util::convertToStripeObject([
            'id' => 'in_all_nt',
            'object' => 'invoice',
            'currency' => 'usd',
            'customer_address' => ['postal_code' => '55401'],
            'lines' => [
                'object' => 'list',
                'data' => [
                    [
                        'id' => 'il_x',
                        'object' => 'line_item',
                        'amount' => 1000,
                        'currency' => 'usd',
                        'price' => ['object' => 'price', 'tax_code' => 'txcd_00000000'],
                    ],
                ],
            ],
        ], []);
        self::assertInstanceOf(Invoice::class, $invoice);

        // HTTP client mock that asserts it was NEVER called â€” if it is, the test fails
        $mockHttp = $this->createMock(ClientInterface::class);
        $mockHttp->expects(self::never())->method('sendRequest');
        $ostClient = new OpenSalesTaxClient(baseUrl: 'http://test', httpClient: $mockHttp);

        $breakdown = InvoiceCalculator::calculateForInvoice($invoice, $ostClient);

        self::assertSame('0.00', $breakdown->subtotal);
        self::assertSame('0', $breakdown->taxTotal);
        self::assertSame('0', $breakdown->combinedRatePct);
        self::assertCount(0, $breakdown->lines);
        self::assertSame(['il_x'], $breakdown->skippedNontaxableLineIds);
    }

    /**
     * @param array<string, mixed> $cannedResponseBody
     */
    private function buildOstClient(array $cannedResponseBody, int $status): OpenSalesTaxClient
    {
        $http = $this->createMock(ClientInterface::class);
        $http->method('sendRequest')->willReturnCallback(
            static fn (RequestInterface $req) => new Response(
                $status,
                ['Content-Type' => 'application/json'],
                json_encode($cannedResponseBody, JSON_THROW_ON_ERROR),
            ),
        );
        return new OpenSalesTaxClient(baseUrl: 'http://mock', httpClient: $http);
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
