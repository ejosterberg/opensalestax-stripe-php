<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\Stripe;

use OpenSalesTax\Responses\CalculateResponse;
use OpenSalesTax\Responses\CalculatedShipping;

/**
 * Result of a Stripe-source tax calculation.
 *
 * Mirrors OpenSalesTax's `CalculateResponse` (subtotal / taxTotal /
 * disclaimer / per-jurisdiction breakdown) and adds Stripe-side
 * context: which Stripe line IDs each calculated line corresponds to,
 * plus a `combinedRatePct` aggregate suitable for creating a single
 * `Stripe\TaxRate` to apply via `default_tax_rates`.
 */
final readonly class TaxBreakdown
{
    /**
     * @param list<array{stripe_line_id: string, amount: string, tax: string, category: string, rate_pct: string, note: ?string}> $lines
     * @param list<array{name: string, type: string, rate_pct: string, tax: ?string}> $jurisdictions
     * @param list<string> $skippedNontaxableLineIds
     * @param CalculatedShipping|null $shipping Engine's calculated shipping segment (CP-9 / SDK v0.3.0). Null when the request omitted shipping OR the engine returned no shipping result (older engine, free shipping, non-taxable destination, etc.).
     */
    public function __construct(
        public string $subtotal,
        public string $taxTotal,
        public string $combinedRatePct,
        public array $lines,
        public array $jurisdictions,
        public array $skippedNontaxableLineIds,
        public string $disclaimer,
        public ?CalculatedShipping $shipping = null,
    ) {
    }

    /**
     * Build a TaxBreakdown from the OpenSalesTax engine response plus
     * Stripe-side context (line ID mapping + skipped non-taxable lines).
     *
     * @param list<string> $stripeLineIds   Parallel to $engineResponse->lines, in the same order
     * @param list<string> $skippedNontaxIds Stripe line IDs that were excluded because their tax_code is nontaxable
     */
    public static function fromEngineResponse(
        CalculateResponse $engineResponse,
        array $stripeLineIds,
        array $skippedNontaxIds,
    ): self {
        $combined = self::computeCombinedRate($engineResponse->subtotal, $engineResponse->taxTotal);

        $lines = [];
        $jurisdictionsAggregated = self::aggregateJurisdictions($engineResponse);

        foreach ($engineResponse->lines as $i => $calculatedLine) {
            $lines[] = [
                'stripe_line_id' => $stripeLineIds[$i] ?? '',
                'amount' => $calculatedLine->amount,
                'tax' => $calculatedLine->tax,
                'category' => $calculatedLine->category,
                'rate_pct' => $calculatedLine->ratePct,
                'note' => $calculatedLine->note,
            ];
        }

        return new self(
            subtotal: $engineResponse->subtotal,
            taxTotal: $engineResponse->taxTotal,
            combinedRatePct: $combined,
            lines: $lines,
            jurisdictions: $jurisdictionsAggregated,
            skippedNontaxableLineIds: $skippedNontaxIds,
            disclaimer: $engineResponse->disclaimer,
            shipping: $engineResponse->shipping,
        );
    }

    /**
     * Compute the effective combined-rate percentage for a single
     * `Stripe\TaxRate` to apply across the whole invoice. This is
     * `taxTotal / subtotal * 100` rounded to engine-style 5 decimals.
     */
    private static function computeCombinedRate(string $subtotal, string $taxTotal): string
    {
        $sub = (float) $subtotal;
        $tax = (float) $taxTotal;
        if ($sub <= 0.0) {
            return '0';
        }
        return number_format(($tax / $sub) * 100, 5, '.', '');
    }

    /**
     * Aggregate per-line jurisdictions into invoice-level totals, summing
     * their `tax` contribution.
     *
     * @return list<array{name: string, type: string, rate_pct: string, tax: ?string}>
     */
    private static function aggregateJurisdictions(CalculateResponse $engineResponse): array
    {
        /** @var array<string, array{name: string, type: string, rate_pct: string, tax_sum: float}> $byKey */
        $byKey = [];

        foreach ($engineResponse->lines as $calculatedLine) {
            foreach ($calculatedLine->jurisdictions as $j) {
                $key = $j->type . '|' . $j->name;
                if (!isset($byKey[$key])) {
                    $byKey[$key] = [
                        'name' => $j->name,
                        'type' => $j->type,
                        'rate_pct' => $j->ratePct,
                        'tax_sum' => 0.0,
                    ];
                }
                if ($j->tax !== null) {
                    $byKey[$key]['tax_sum'] += (float) $j->tax;
                }
            }
        }

        $out = [];
        foreach ($byKey as $entry) {
            $out[] = [
                'name' => $entry['name'],
                'type' => $entry['type'],
                'rate_pct' => $entry['rate_pct'],
                'tax' => number_format($entry['tax_sum'], 4, '.', ''),
            ];
        }

        return $out;
    }
}
