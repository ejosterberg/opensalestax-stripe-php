<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\Stripe\Exceptions;

/**
 * Thrown when the connector receives a Stripe object shape it doesn't
 * support — e.g. an Invoice with no `lines.data` array, or a
 * CheckoutSession in a status from which line items can't be read.
 */
final class UnsupportedSourceException extends OpenSalesTaxStripeException
{
}
