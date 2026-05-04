<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\Stripe;

use OpenSalesTax\LineItem;
use OpenSalesTax\Stripe\Exceptions\UnsupportedSourceException;
use Stripe\Checkout\Session;
use Stripe\Invoice;

/**
 * Extracts line items from a Stripe Invoice or CheckoutSession into
 * the shape OpenSalesTax expects (decimal-string `amount` + a tax
 * category resolved through `TaxCodeMap`).
 *
 * The `extractFromInvoice()` method also returns a side-channel array
 * keyed by the Stripe line ID so callers can map calculation results
 * back to specific Stripe line items if they need to.
 */
final class LineExtractor
{
    /**
     * @return array{
     *     line_items: list<LineItem>,
     *     stripe_line_ids: list<string>,
     *     skipped_nontaxable_ids: list<string>
     * }
     */
    public static function fromInvoice(Invoice $invoice, TaxCodeMap $taxCodeMap): array
    {
        // Use ?? null (isset semantics) to avoid Stripe's StripeObject::__get notice
        // when the property is absent. Once null, is_object/is_array guards do the rest.
        $linesObj = $invoice->lines ?? null;
        if (!is_object($linesObj) || !is_array($linesObj->data ?? null)) {
            throw new UnsupportedSourceException(
                "Stripe invoice '" . (string) ($invoice->id ?? 'unknown') . "' has no lines.data array",
            );
        }

        $lineItems = [];
        $stripeIds = [];
        $skipped = [];

        foreach ($linesObj->data as $line) {
            $stripeId = (string) ($line->id ?? '');
            $taxCode = self::pickTaxCode($line);

            if ($taxCodeMap->isNontaxable($taxCode)) {
                $skipped[] = $stripeId;
                continue;
            }

            $amountCents = (int) ($line->amount ?? 0);
            if ($amountCents === 0) {
                continue; // zero-value lines (proration credits, etc.) — skip
            }

            $lineItems[] = new LineItem(
                amount: self::centsToDecimalString($amountCents),
                category: $taxCodeMap->resolve($taxCode),
            );
            $stripeIds[] = $stripeId;
        }

        return [
            'line_items' => $lineItems,
            'stripe_line_ids' => $stripeIds,
            'skipped_nontaxable_ids' => $skipped,
        ];
    }

    /**
     * @return array{
     *     line_items: list<LineItem>,
     *     stripe_line_ids: list<string>,
     *     skipped_nontaxable_ids: list<string>
     * }
     */
    public static function fromCheckoutSession(Session $session, TaxCodeMap $taxCodeMap): array
    {
        $lineItemsObj = $session->line_items ?? null;
        if (!is_object($lineItemsObj) || !is_array($lineItemsObj->data ?? null)) {
            throw new UnsupportedSourceException(
                "Stripe checkout session '" . (string) ($session->id ?? 'unknown')
                    . "' has no line_items.data — make sure to expand 'line_items' when retrieving the session",
            );
        }

        $lineItems = [];
        $stripeIds = [];
        $skipped = [];

        foreach ($lineItemsObj->data as $line) {
            $stripeId = (string) ($line->id ?? '');
            $taxCode = self::pickTaxCode($line);

            if ($taxCodeMap->isNontaxable($taxCode)) {
                $skipped[] = $stripeId;
                continue;
            }

            $amountCents = (int) ($line->amount_subtotal ?? $line->amount_total ?? 0);
            if ($amountCents === 0) {
                continue;
            }

            $lineItems[] = new LineItem(
                amount: self::centsToDecimalString($amountCents),
                category: $taxCodeMap->resolve($taxCode),
            );
            $stripeIds[] = $stripeId;
        }

        return [
            'line_items' => $lineItems,
            'stripe_line_ids' => $stripeIds,
            'skipped_nontaxable_ids' => $skipped,
        ];
    }

    /**
     * Pull the Stripe Tax Code off a line. Lines can carry it via
     * `price.tax_code` (Stripe's preferred location) or a flattened
     * `tax_code` field on legacy / manual invoice lines. Empty string
     * if neither set.
     */
    private static function pickTaxCode(object $line): string
    {
        // Direct on-line (legacy / manual invoice items)
        if (isset($line->tax_code) && is_string($line->tax_code) && $line->tax_code !== '') {
            return $line->tax_code;
        }

        // On the price object
        if (is_object($line->price ?? null)) {
            /** @var object $price */
            $price = $line->price;
            if (isset($price->tax_code) && is_string($price->tax_code) && $price->tax_code !== '') {
                return $price->tax_code;
            }

            // Some price objects expand product
            if (is_object($price->product ?? null)) {
                /** @var object $product */
                $product = $price->product;
                if (isset($product->tax_code) && is_string($product->tax_code) && $product->tax_code !== '') {
                    return $product->tax_code;
                }
            }
        }

        return '';
    }

    /**
     * Convert Stripe's integer cents to OpenSalesTax's decimal string.
     */
    private static function centsToDecimalString(int $amountCents): string
    {
        return number_format($amountCents / 100, 2, '.', '');
    }
}
