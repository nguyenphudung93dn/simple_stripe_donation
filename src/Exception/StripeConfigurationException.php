<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Exception;

/**
 * Thrown when required Stripe configuration (keys/secrets) is missing.
 *
 * The message is always safe to log or display in the admin UI: it must
 * never contain the actual key/secret value.
 */
class StripeConfigurationException extends \RuntimeException {
}
