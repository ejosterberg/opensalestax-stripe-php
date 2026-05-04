<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace OpenSalesTax\Stripe\Exceptions;

use OpenSalesTax\Exceptions\OpenSalesTaxException;

/**
 * Base exception for all opensalestax-stripe-php errors.
 *
 * Extends the SDK's base so callers can `catch (OpenSalesTaxException $e)`
 * once and handle both SDK and connector errors uniformly.
 */
class OpenSalesTaxStripeException extends OpenSalesTaxException
{
}
