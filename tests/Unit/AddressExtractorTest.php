<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\Stripe\Tests\Unit;

use OpenSalesTax\Stripe\AddressExtractor;
use OpenSalesTax\Stripe\Exceptions\MissingAddressException;
use PHPUnit\Framework\TestCase;
use Stripe\Checkout\Session;
use Stripe\Invoice;
use Stripe\Util\Util;

final class AddressExtractorTest extends TestCase
{
    public function testExtractsZip5FromInvoiceCustomerAddress(): void
    {
        $invoice = $this->loadInvoiceFixture('invoice-mn-mixed.json');
        $address = AddressExtractor::fromInvoice($invoice);

        self::assertSame('55401', $address->zip5);
        self::assertNull($address->zip4);
    }

    public function testFallsBackToShippingAddress(): void
    {
        // Construct an invoice with no customer_address but with customer_shipping
        $invoice = $this->buildInvoice([
            'id' => 'in_test',
            'object' => 'invoice',
            'currency' => 'usd',
            'customer' => 'cus_test',
            'customer_address' => null,
            'customer_shipping' => [
                'name' => 'Test',
                'address' => [
                    'postal_code' => '75201',
                    'state' => 'TX',
                    'country' => 'US',
                ],
            ],
        ]);

        $address = AddressExtractor::fromInvoice($invoice);
        self::assertSame('75201', $address->zip5);
    }

    public function testThrowsMissingAddressWhenNoZipReachable(): void
    {
        $invoice = $this->buildInvoice([
            'id' => 'in_no_addr',
            'object' => 'invoice',
            'currency' => 'usd',
            'customer' => 'cus_test',
            'customer_address' => null,
            'customer_shipping' => null,
        ]);

        $this->expectException(MissingAddressException::class);
        $this->expectExceptionMessageMatches('/in_no_addr/');
        AddressExtractor::fromInvoice($invoice);
    }

    public function testParsesZipPlus4WithDash(): void
    {
        $invoice = $this->buildInvoice([
            'id' => 'in_zip4',
            'object' => 'invoice',
            'currency' => 'usd',
            'customer' => 'cus_test',
            'customer_address' => [
                'postal_code' => '55401-1234',
                'state' => 'MN',
                'country' => 'US',
            ],
        ]);

        $address = AddressExtractor::fromInvoice($invoice);
        self::assertSame('55401', $address->zip5);
        self::assertSame('1234', $address->zip4);
    }

    public function testParsesZipPlus4WithoutDash(): void
    {
        $invoice = $this->buildInvoice([
            'id' => 'in_zip4nd',
            'object' => 'invoice',
            'currency' => 'usd',
            'customer' => 'cus_test',
            'customer_address' => [
                'postal_code' => '554011234',
                'state' => 'MN',
                'country' => 'US',
            ],
        ]);

        $address = AddressExtractor::fromInvoice($invoice);
        self::assertSame('55401', $address->zip5);
        self::assertSame('1234', $address->zip4);
    }

    public function testExtractsFromCheckoutSession(): void
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/checkout-session-mn.json');
        self::assertNotFalse($raw);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $session = Util::convertToStripeObject($decoded, []);
        self::assertInstanceOf(Session::class, $session);

        $address = AddressExtractor::fromCheckoutSession($session);
        self::assertSame('55101', $address->zip5);
    }

    public function testCheckoutSessionThrowsMissingAddress(): void
    {
        $session = Util::convertToStripeObject([
            'id' => 'cs_no_addr',
            'object' => 'checkout.session',
            'currency' => 'usd',
            'customer' => 'cus_test',
            'customer_details' => null,
            'shipping_details' => null,
        ], []);
        self::assertInstanceOf(Session::class, $session);

        $this->expectException(MissingAddressException::class);
        $this->expectExceptionMessageMatches('/cs_no_addr/');
        AddressExtractor::fromCheckoutSession($session);
    }

    private function loadInvoiceFixture(string $name): Invoice
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertNotFalse($raw, "Fixture missing: {$name}");
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $obj = Util::convertToStripeObject($decoded, []);
        self::assertInstanceOf(Invoice::class, $obj);
        return $obj;
    }

    /**
     * @param array<string, mixed> $shape
     */
    private function buildInvoice(array $shape): Invoice
    {
        $obj = Util::convertToStripeObject($shape, []);
        self::assertInstanceOf(Invoice::class, $obj);
        return $obj;
    }
}
