<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\Stripe;

use OpenSalesTax\Address;
use OpenSalesTax\Stripe\Exceptions\MissingAddressException;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Invoice;

/**
 * Pulls a usable shipping/billing ZIP from a Stripe Invoice or
 * CheckoutSession. Tolerant of where Stripe stores the address in
 * different flows.
 *
 * Cascade order:
 *   1. The object's own `customer_address` / `customer_details.address`
 *   2. The object's shipping address (`customer_shipping` /
 *      `shipping_details`)
 *   3. The hydrated Customer's stored address (only if
 *      `$apiKey` is provided so we can hit the Stripe API)
 *
 * Throws `MissingAddressException` if nothing in that cascade has
 * a ZIP. The message carries the Stripe object ID for debugging.
 */
final class AddressExtractor
{
    public static function fromInvoice(Invoice $invoice, ?string $stripeApiKey = null): Address
    {
        $stripeId = (string) ($invoice->id ?? '(unknown invoice)');

        // 1. invoice.customer_address
        if (is_object($invoice->customer_address)) {
            $zip = self::pickZip($invoice->customer_address);
            if ($zip !== null) {
                return self::buildAddress($zip);
            }
        }

        // 2. invoice.customer_shipping.address
        // Use ?? null (isset semantics) so Stripe's __get magic doesn't emit notices
        // when the property is absent. is_object() guards make access safe at runtime.
        $shippingObj = $invoice->customer_shipping ?? null;
        if (is_object($shippingObj)) {
            $shippingAddr = $shippingObj->address ?? null;
            if (is_object($shippingAddr)) {
                $zip = self::pickZip($shippingAddr);
                if ($zip !== null) {
                    return self::buildAddress($zip);
                }
            }
        }

        // 3. Hydrate the customer
        if ($stripeApiKey !== null && is_string($invoice->customer) && $invoice->customer !== '') {
            $address = self::fromHydratedCustomer($invoice->customer, $stripeApiKey);
            if ($address !== null) {
                return $address;
            }
        }

        throw new MissingAddressException(
            $stripeId,
            'tried invoice.customer_address, invoice.customer_shipping.address, and hydrated Stripe\\Customer.address',
        );
    }

    public static function fromCheckoutSession(Session $session, ?string $stripeApiKey = null): Address
    {
        $stripeId = (string) ($session->id ?? '(unknown session)');

        // 1. session.customer_details.address
        if (is_object($session->customer_details) && is_object($session->customer_details->address)) {
            $zip = self::pickZip($session->customer_details->address);
            if ($zip !== null) {
                return self::buildAddress($zip);
            }
        }

        // 2. session.shipping_details.address
        $shippingObj = $session->shipping_details ?? null;
        if (is_object($shippingObj)) {
            $shippingAddr = $shippingObj->address ?? null;
            if (is_object($shippingAddr)) {
                $zip = self::pickZip($shippingAddr);
                if ($zip !== null) {
                    return self::buildAddress($zip);
                }
            }
        }

        // 3. Hydrate the customer
        if ($stripeApiKey !== null && is_string($session->customer) && $session->customer !== '') {
            $address = self::fromHydratedCustomer($session->customer, $stripeApiKey);
            if ($address !== null) {
                return $address;
            }
        }

        throw new MissingAddressException(
            $stripeId,
            'tried session.customer_details.address, session.shipping_details.address, and hydrated Stripe\\Customer.address',
        );
    }

    /**
     * Hydrate a Stripe Customer and pick a ZIP from its stored address.
     */
    private static function fromHydratedCustomer(string $customerId, string $stripeApiKey): ?Address
    {
        $customer = Customer::retrieve(['id' => $customerId], ['api_key' => $stripeApiKey]);
        if (is_object($customer->address)) {
            $zip = self::pickZip($customer->address);
            if ($zip !== null) {
                return self::buildAddress($zip);
            }
        }
        return null;
    }

    /**
     * Pull a postal code off a Stripe address object. Stripe's address
     * objects expose `postal_code` as a public property.
     */
    private static function pickZip(object $stripeAddressObject): ?string
    {
        $raw = $stripeAddressObject->postal_code ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return $raw;
    }

    /**
     * Build an OpenSalesTax `Address` from a Stripe-formatted postal_code.
     * Splits ZIP+4 (e.g. "55401-1234") into `zip5` + `zip4` if present.
     */
    private static function buildAddress(string $rawPostalCode): Address
    {
        $rawPostalCode = trim($rawPostalCode);

        if (preg_match('/^(\d{5})-(\d{4})$/', $rawPostalCode, $m) === 1) {
            return new Address(zip5: $m[1], zip4: $m[2]);
        }
        if (preg_match('/^(\d{5})$/', $rawPostalCode) === 1) {
            return new Address(zip5: $rawPostalCode);
        }

        // Some Stripe rows carry 9-digit ZIPs without the dash
        if (preg_match('/^(\d{5})(\d{4})$/', $rawPostalCode, $m) === 1) {
            return new Address(zip5: $m[1], zip4: $m[2]);
        }

        // Stripe stored something we can't parse cleanly. Fall through
        // to Address's own ZIP regex; it will throw OpenSalesTaxValidationException
        // with a clear message, which propagates to the caller.
        return new Address(zip5: $rawPostalCode);
    }
}
