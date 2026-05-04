<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\Stripe;

use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\Stripe\Exceptions\NonUSDException;
use Stripe\Invoice;

/**
 * Calculates US sales tax for a Stripe Invoice using OpenSalesTax.
 *
 * Public entrypoint. Stateless. Delegate is `calculateForInvoice()`.
 */
final class InvoiceCalculator
{
    /**
     * @param Invoice            $invoice  A retrieved Stripe Invoice (preferably in draft status, before finalization)
     * @param OpenSalesTaxClient $ostClient The OpenSalesTax SDK client
     * @param TaxCodeMap|null    $taxCodeMap Optional override of the default Stripe Tax Code → OST category mapping
     * @param string|null        $stripeApiKey If passed, the AddressExtractor may hydrate the Customer to find a ZIP. Optional.
     *
     * @throws NonUSDException                                                When invoice currency is not USD
     * @throws \OpenSalesTax\Stripe\Exceptions\MissingAddressException        When no ZIP is reachable
     * @throws \OpenSalesTax\Stripe\Exceptions\UnsupportedSourceException     When the invoice has no lines
     * @throws \OpenSalesTax\Exceptions\OpenSalesTaxException                 On engine / network failure
     */
    public static function calculateForInvoice(
        Invoice $invoice,
        OpenSalesTaxClient $ostClient,
        ?TaxCodeMap $taxCodeMap = null,
        ?string $stripeApiKey = null,
    ): TaxBreakdown {
        self::assertUSD($invoice);

        $taxCodeMap ??= new TaxCodeMap();

        $address = AddressExtractor::fromInvoice($invoice, $stripeApiKey);
        $extracted = LineExtractor::fromInvoice($invoice, $taxCodeMap);

        // If every line was nontaxable, return a zero breakdown without
        // hitting the engine.
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

    private static function assertUSD(Invoice $invoice): void
    {
        $currency = strtolower((string) ($invoice->currency ?? ''));
        if ($currency !== 'usd') {
            throw new NonUSDException(
                currency: $currency === '' ? '(empty)' : $currency,
                stripeObjectId: (string) ($invoice->id ?? '(unknown invoice)'),
            );
        }
    }
}
