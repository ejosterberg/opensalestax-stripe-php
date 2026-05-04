<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\Stripe;

use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\Stripe\Exceptions\NonUSDException;
use Stripe\Checkout\Session;

/**
 * Calculates US sales tax for a Stripe CheckoutSession using OpenSalesTax.
 *
 * Public entrypoint. Stateless. Delegate is `calculateForCheckoutSession()`.
 *
 * IMPORTANT: When retrieving the session, expand `line_items` so the
 * connector can read them — Stripe doesn't include them in the basic
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

        $engineResponse = $ostClient->calculate(
            address: $address,
            lineItems: $extracted['line_items'],
        );

        return TaxBreakdown::fromEngineResponse(
            $engineResponse,
            $extracted['stripe_line_ids'],
            $extracted['skipped_nontaxable_ids'],
        );
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
