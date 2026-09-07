<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Service;

use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Refund;

/**
 * Encapsulates every Stripe API call made by this module.
 *
 * No other class in the module should talk to the Stripe SDK directly.
 */
interface StripeServiceInterface {

  /**
   * Gets the active Stripe mode ('test' or 'live') from configuration.
   */
  public function getMode(): string;

  /**
   * Gets the publishable key for the active mode.
   *
   * Publishable keys are safe to expose publicly (they are, by Stripe's own
   * design, embedded in client-side pages), so this may be called from
   * render arrays without restriction.
   */
  public function getPublishableKey(): string;

  /**
   * Creates a Stripe Checkout Session.
   *
   * @param array $params
   *   Parameters passed through to Stripe\Checkout\Session::create(), e.g.
   *   line_items, mode, success_url, cancel_url, customer_email, metadata.
   *
   * @throws \Stripe\Exception\ApiErrorException
   * @throws \Drupal\simple_stripe_donation\Exception\StripeConfigurationException
   */
  public function createCheckoutSession(array $params): Session;

  /**
   * Retrieves a Stripe Checkout Session by ID.
   *
   * @throws \Stripe\Exception\ApiErrorException
   * @throws \Drupal\simple_stripe_donation\Exception\StripeConfigurationException
   */
  public function retrieveCheckoutSession(string $sessionId): Session;

  /**
   * Retrieves a Stripe PaymentIntent by ID.
   *
   * @throws \Stripe\Exception\ApiErrorException
   * @throws \Drupal\simple_stripe_donation\Exception\StripeConfigurationException
   */
  public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent;

  /**
   * Refunds a payment, in full or in part.
   *
   * @param string $paymentIntentId
   *   The Stripe PaymentIntent ID to refund.
   * @param int|null $amount
   *   The amount to refund, in the smallest currency unit. NULL refunds the
   *   full remaining amount.
   *
   * @throws \Stripe\Exception\ApiErrorException
   * @throws \Drupal\simple_stripe_donation\Exception\StripeConfigurationException
   */
  public function refundPayment(string $paymentIntentId, ?int $amount = NULL): Refund;

  /**
   * Verifies a webhook signature and constructs the corresponding Event.
   *
   * @throws \UnexpectedValueException
   *   If the payload is malformed.
   * @throws \Stripe\Exception\SignatureVerificationException
   *   If the signature does not match.
   * @throws \Drupal\simple_stripe_donation\Exception\StripeConfigurationException
   */
  public function constructWebhookEvent(string $payload, string $sigHeader): Event;

  /**
   * Tests connectivity using a candidate secret key, e.g. one just typed
   * into the settings form but not yet saved. Never logs the key value.
   *
   * @return bool
   *   TRUE if Stripe accepted the key and responded successfully.
   */
  public function testConnection(string $secretKeyCandidate): bool;

}
