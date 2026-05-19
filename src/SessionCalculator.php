<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\Stripe;

use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\Shipping;
use OpenSalesTax\Stripe\Exceptions\NonUSDException;
use Stripe\Checkout\Session;

/**
 * Calculates US sales tax for a Stripe CheckoutSession using OpenSalesTax.
 *
 * Public entrypoint. Stateless. Delegate is `calculateForCheckoutSession()`.
 *
 * IMPORTANT: When retrieving the session, expand `line_items` so the
 * connector can read them â€” Stripe doesn't include them in the basic
 * Session payload by default:
 *
 *   $session = \Stripe\Checkout\Session::retrieve(
 *       ['id' => $sessionId, 'expand' => ['line_items', 'line_items.data.price']]
 *   );
 */
final class SessionCalculator
{
    /**
     * @throws NonUSDException                                              When session currency is not USD
     * @throws \OpenSalesTax\Stripe\Exceptions\MissingAddressException      When no ZIP is reachable
     * @throws \OpenSalesTax\Stripe\Exceptions\UnsupportedSourceException   When the session has no expanded line_items
     * @throws \OpenSalesTax\Exceptions\OpenSalesTaxException               On engine / network failure
     */
    public static function calculateForCheckoutSession(
        Session $session,
        OpenSalesTaxClient $ostClient,
        ?TaxCodeMap $taxCodeMap = null,
        ?string $stripeApiKey = null,
    ): TaxBreakdown {
        self::assertUSD($session);

        $taxCodeMap ??= new TaxCodeMap();

        $address = AddressExtractor::fromCheckoutSession($session, $stripeApiKey);
        $extracted = LineExtractor::fromCheckoutSession($session, $taxCodeMap);

        if ($extracted['line_items'] === []) {
            return new TaxBreakdown(
                subtotal: '0.00',
                taxTotal: '0',
                combinedRatePct: '0',
                lines: [],
                jurisdictions: [],
                skippedNontaxableLineIds: $extracted['skipped_nontaxable_ids'],
                disclaimer: 'Calculation only; not legal or tax advice. Verify against your state Department of Revenue before remitting.',
            );
        }

        $shipping = self::extractShipping($session);

        $engineResponse = $ostClient->calculate(
            address: $address,
            lineItems: $extracted['line_items'],
            shipping: $shipping,
        );

        return TaxBreakdown::fromEngineResponse(
            $engineResponse,
            $extracted['stripe_line_ids'],
            $extracted['skipped_nontaxable_ids'],
        );
    }

    /**
     * Extract a typed Shipping value object from a Stripe CheckoutSession's
     * `shipping_cost->amount_subtotal` (pre-tax shipping amount in the
     * session's smallest currency unit — cents for USD). Returns null when
     * no shipping cost is set or the amount is zero/negative.
     *
     * CP-9 / SDK v0.3.0: lets the engine apply per-state shipping-taxability
     * rules (MN "tax-if-items-taxable", MO/VA "separately-stated", MD
     * "shipping-vs-handling") instead of forcing callers to invent rates.
     */
    private static function extractShipping(Session $session): ?Shipping
    {
        $shippingCost = $session->shipping_cost ?? null;
        if ($shippingCost === null) {
            return null;
        }
        $cents = $shippingCost->amount_subtotal ?? null;
        if (!is_int($cents) || $cents <= 0) {
            return null;
        }
        $dollars = number_format($cents / 100, 2, '.', '');
        try {
            return new Shipping(
                amount: $dollars,
                separatelyStated: true,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private static function assertUSD(Session $session): void
    {
        $currency = strtolower((string) ($session->currency ?? ''));
        if ($currency !== 'usd') {
            throw new NonUSDException(
                currency: $currency === '' ? '(empty)' : $currency,
                stripeObjectId: (string) ($session->id ?? '(unknown session)'),
            );
        }
    }
}
