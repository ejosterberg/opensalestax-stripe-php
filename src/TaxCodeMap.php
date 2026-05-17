<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\Stripe;

/**
 * Translates Stripe Tax Codes to OpenSalesTax categories.
 *
 * Default mapping covers the top SaaS / e-commerce codes per Stripe's
 * public catalog as of 2026-05-04. Anything unmapped falls through to
 * `general`. Two specials:
 * - `txcd_00000000` ("Nontaxable") signals the line should be EXCLUDED
 *   from the OpenSalesTax calculation entirely; check via `isNontaxable()`.
 * - Custom mappings can be injected via the constructor â€” pass
 *   `['txcd_xxxxxxxx' => 'category', ...]` to override or extend.
 *
 * OpenSalesTax engine v0.22 categories: general (default), clothing,
 * groceries, prescription_drugs, prepared_food, digital_goods.
 */
final class TaxCodeMap
{
    /** @var array<string, string> */
    private const DEFAULT_MAP = [
        // Catch-alls
        'txcd_99999999' => 'general',         // General â€” Tangible Goods
        'txcd_20030000' => 'general',         // General â€” Services

        // SaaS family â€” most common for B2B/B2C SaaS
        'txcd_10103001' => 'digital_goods',   // SaaS â€” Business
        'txcd_10103000' => 'digital_goods',   // SaaS â€” Personal

        // Cloud infrastructure family
        'txcd_10101000' => 'digital_goods',   // IaaS â€” Business
        'txcd_10102000' => 'digital_goods',   // PaaS â€” Business
        'txcd_10105002' => 'digital_goods',   // AIaaS â€” Business

        // Digital media
        'txcd_10302000' => 'digital_goods',   // Digital Books
        'txcd_10402100' => 'digital_goods',   // Digital Video
        'txcd_10401100' => 'digital_goods',   // Digital Audio
        'txcd_10701100' => 'digital_goods',   // Website Hosting
    ];

    public const NONTAXABLE_CODE = 'txcd_00000000';

    public const FALLBACK_CATEGORY = 'general';

    /** @var array<string, string> */
    private array $map;

    /** @var (callable(string): void)|null */
    private $warningHandler = null;

    /**
     * @param array<string, string> $overrides Custom Stripe code â†’ OST category overrides; merged into the default map.
     */
    public function __construct(array $overrides = [])
    {
        $this->map = array_merge(self::DEFAULT_MAP, $overrides);
    }

    /**
     * Resolve a Stripe Tax Code to an OpenSalesTax category.
     *
     * Returns the mapped category, or `general` if the code is unknown
     * (also fires the warning handler if one is registered).
     *
     * For `txcd_00000000` (Nontaxable), call `isNontaxable()` separately
     * BEFORE resolving â€” this method returns `general` for nontaxable
     * codes (callers should be skipping them, not mapping them).
     */
    public function resolve(string $stripeCode): string
    {
        if ($stripeCode === '' || $stripeCode === self::NONTAXABLE_CODE) {
            return self::FALLBACK_CATEGORY;
        }
        if (isset($this->map[$stripeCode])) {
            return $this->map[$stripeCode];
        }
        if ($this->warningHandler !== null) {
            ($this->warningHandler)($stripeCode);
        }
        return self::FALLBACK_CATEGORY;
    }

    /**
     * True if the line should be excluded from the OpenSalesTax request entirely.
     *
     * Stripe's `txcd_00000000` is a policy override meaning "no tax in any
     * jurisdiction" â€” sending this through OpenSalesTax would apply normal
     * tax, which is wrong.
     */
    public function isNontaxable(string $stripeCode): bool
    {
        return $stripeCode === self::NONTAXABLE_CODE;
    }

    /**
     * Register a callback fired when `resolve()` falls through to the default.
     *
     * Useful for logging "we encountered an unknown Stripe Tax Code in
     * production" so the caller knows when to expand their mapping.
     *
     * @param (callable(string): void)|null $handler
     */
    public function setWarningHandler(?callable $handler): void
    {
        $this->warningHandler = $handler;
    }
}
