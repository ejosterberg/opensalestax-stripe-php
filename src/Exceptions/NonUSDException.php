<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\Stripe\Exceptions;

/**
 * Thrown when a Stripe Invoice / CheckoutSession is denominated in a
 * currency other than USD. The OpenSalesTax engine is USD-only; mixing
 * currencies would produce silently wrong tax amounts.
 */
final class NonUSDException extends OpenSalesTaxStripeException
{
    public function __construct(
        public readonly string $currency,
        public readonly string $stripeObjectId,
    ) {
        parent::__construct(
            "OpenSalesTax v0.x supports USD only; received '{$currency}' on Stripe object '{$stripeObjectId}'",
        );
    }
}
