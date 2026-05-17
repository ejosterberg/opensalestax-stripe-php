<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\Stripe\Exceptions;

/**
 * Thrown when neither the Stripe Invoice / CheckoutSession nor the
 * underlying Customer carries a postal_code. OpenSalesTax requires
 * a destination ZIP for any meaningful calculation.
 *
 * Common cause: the Stripe Checkout flow didn't set
 * `billing_address_collection => required`, so the customer was
 * created without an address.
 */
final class MissingAddressException extends OpenSalesTaxStripeException
{
    public function __construct(
        public readonly string $stripeObjectId,
        string $detail = '',
    ) {
        $msg = "No billing-address ZIP found on Stripe object '{$stripeObjectId}'";
        if ($detail !== '') {
            $msg .= ' â€” ' . $detail;
        }
        parent::__construct($msg);
    }
}
