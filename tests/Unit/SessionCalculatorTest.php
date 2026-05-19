<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\Stripe\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\Stripe\Exceptions\NonUSDException;
use OpenSalesTax\Stripe\SessionCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Stripe\Checkout\Session;
use Stripe\Util\Util;

final class SessionCalculatorTest extends TestCase
{
    public function testCalculatesForCheckoutSession(): void
    {
        $session = $this->loadSession('checkout-session-mn.json');

        $cannedResponse = [
            'subtotal' => '75.00',
            'tax_total' => '6.0188',
            'lines' => [
                [
                    'amount' => '75.00',
                    'category' => 'digital_goods',
                    'tax' => '6.0188',
                    'rate_pct' => '8.025',
                    'jurisdictions' => [
                        ['name' => 'Minnesota', 'type' => 'state', 'rate_pct' => '6.875', 'tax' => '5.1563'],
                        ['name' => 'Ramsey County', 'type' => 'county', 'rate_pct' => '0.5', 'tax' => '0.3750'],
                        ['name' => 'St. Paul', 'type' => 'city', 'rate_pct' => '1.5', 'tax' => '1.1250'],
                    ],
                ],
            ],
            'disclaimer' => 'Calculation only; not legal or tax advice. Verify against your state Department of Revenue before remitting.',
        ];

        $ostClient = $this->buildOstClient($cannedResponse, 200);

        $breakdown = SessionCalculator::calculateForCheckoutSession($session, $ostClient);

        self::assertSame('75.00', $breakdown->subtotal);
        self::assertSame('6.0188', $breakdown->taxTotal);
        self::assertSame('8.02507', $breakdown->combinedRatePct); // 6.0188 / 75 * 100 = 8.02507%
        self::assertCount(1, $breakdown->lines);
        self::assertSame('li_1xxx', $breakdown->lines[0]['stripe_line_id']);
        self::assertCount(3, $breakdown->jurisdictions);
    }

    public function testNonUsdSessionThrows(): void
    {
        $session = Util::convertToStripeObject([
            'id' => 'cs_gbp',
            'object' => 'checkout.session',
            'currency' => 'gbp',
            'customer_details' => ['address' => ['postal_code' => '55401']],
            'line_items' => ['object' => 'list', 'data' => []],
        ], []);
        self::assertInstanceOf(Session::class, $session);

        $ostClient = $this->buildOstClient([], 200);

        $this->expectException(NonUSDException::class);
        $this->expectExceptionMessageMatches('/gbp.*cs_gbp/');
        SessionCalculator::calculateForCheckoutSession($session, $ostClient);
    }

    // CP-9 / SDK v0.3.0: first-class shipping support.
    public function testSessionShippingCostPassedToEngineAndSurfacedInBreakdown(): void
    {
        // Build a session WITH shipping_cost.amount_subtotal = 1250 (cents = $12.50).
        $sessionData = json_decode(
            (string) file_get_contents(__DIR__ . '/fixtures/checkout-session-mn.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $sessionData['shipping_cost'] = ['amount_subtotal' => 1250];
        $session = Util::convertToStripeObject($sessionData, []);
        self::assertInstanceOf(Session::class, $session);

        $cannedResponse = [
            'subtotal' => '75.00',
            'tax_total' => '6.0188',
            'lines' => [
                [
                    'amount' => '75.00', 'category' => 'digital_goods',
                    'tax' => '6.0188', 'rate_pct' => '8.025', 'jurisdictions' => [],
                ],
            ],
            'disclaimer' => '',
            'shipping' => [
                'amount' => '12.50',
                'tax_amount' => '1.0031',
                'rate_pct' => '8.025',
                'taxable_reason' => 'MN taxes shipping when items are taxable.',
            ],
        ];

        // Capture the outgoing request body so we can assert the shipping
        // field made it into the wire payload.
        $captured = ['body' => null];
        $http = $this->createMock(ClientInterface::class);
        $http->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $req) use ($cannedResponse, &$captured) {
                $captured['body'] = (string) $req->getBody();
                return new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode($cannedResponse, JSON_THROW_ON_ERROR),
                );
            },
        );
        $ostClient = new OpenSalesTaxClient(baseUrl: 'http://mock', httpClient: $http);

        $breakdown = SessionCalculator::calculateForCheckoutSession($session, $ostClient);

        // Shipping in wire body
        self::assertNotNull($captured['body']);
        $sent = json_decode((string) $captured['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($sent);
        self::assertArrayHasKey('shipping', $sent);
        self::assertSame('12.50', $sent['shipping']['amount']);

        // Engine-returned shipping surfaced on the breakdown
        self::assertNotNull($breakdown->shipping);
        self::assertSame('1.0031', $breakdown->shipping->taxAmount);
        self::assertSame('12.50', $breakdown->shipping->amount);
    }

    public function testSessionWithoutShippingOmitsFieldFromRequest(): void
    {
        $session = $this->loadSession('checkout-session-mn.json');
        // No shipping_cost on this fixture.

        $cannedResponse = [
            'subtotal' => '75.00', 'tax_total' => '6.0188',
            'lines' => [['amount' => '75.00', 'category' => 'digital_goods', 'tax' => '6.0188', 'rate_pct' => '8.025', 'jurisdictions' => []]],
            'disclaimer' => '',
        ];

        $captured = ['body' => null];
        $http = $this->createMock(ClientInterface::class);
        $http->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $req) use ($cannedResponse, &$captured) {
                $captured['body'] = (string) $req->getBody();
                return new Response(200, [], json_encode($cannedResponse, JSON_THROW_ON_ERROR));
            },
        );
        $ostClient = new OpenSalesTaxClient(baseUrl: 'http://mock', httpClient: $http);

        $breakdown = SessionCalculator::calculateForCheckoutSession($session, $ostClient);

        self::assertNotNull($captured['body']);
        $sent = json_decode((string) $captured['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($sent);
        self::assertArrayNotHasKey('shipping', $sent);
        self::assertNull($breakdown->shipping);
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

    private function loadSession(string $name): Session
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertNotFalse($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $session = Util::convertToStripeObject($decoded, []);
        self::assertInstanceOf(Session::class, $session);
        return $session;
    }
}
