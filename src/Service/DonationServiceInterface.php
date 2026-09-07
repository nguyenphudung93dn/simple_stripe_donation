<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Service;

/**
 * Owns donation business rules: validation, state transitions, notification.
 *
 * State model:
 *
 *   pending
 *     ├── succeeded   (checkout.session.completed with payment_status=paid,
 *     │                or payment_intent.succeeded)
 *     ├── failed      (payment_intent.payment_failed)
 *     └── expired     (checkout.session.expired)
 *
 *   succeeded
 *     └── refunded    (admin-initiated refund, confirmed by Stripe)
 *
 * All transitions out of 'pending' and out of 'succeeded' are guarded at
 * the database layer (conditional UPDATE ... WHERE status = <expected>),
 * so repeated/duplicate/racing webhook deliveries can never apply a
 * transition twice or move a donation through an invalid path.
 */
interface DonationServiceInterface {

  const STATUS_PENDING = 'pending';
  const STATUS_SUCCEEDED = 'succeeded';
  const STATUS_FAILED = 'failed';
  const STATUS_EXPIRED = 'expired';
  const STATUS_REFUNDED = 'refunded';

  /**
   * Validates donor input and creates a pending donation with a Stripe
   * Checkout Session.
   *
   * @param array $input
   *   Keys: amount (decimal string), currency (optional, ISO 4217),
   *   donor_name, donor_email, donor_phone, donor_address, message,
   *   anonymous (bool), uid (optional int).
   *
   * @return array
   *   On success: ['success' => TRUE, 'donation_id' => int, 'checkout_url' => string].
   *   On failure: ['success' => FALSE, 'error' => string] with a message
   *   safe to display to the donor.
   */
  public function createDonation(array $input): array;

  /**
   * Loads a donation by its public UUID.
   */
  public function getDonation(string $uuid): ?object;

  /**
   * Loads a donation by its internal ID.
   */
  public function getDonationById(int $donationId): ?object;

  /**
   * Loads a donation by its Stripe Checkout Session ID, for the /donate/success page.
   */
  public function getDonationByCheckoutSessionId(string $sessionId): ?object;

  /**
   * Transitions a donation from pending to succeeded. Idempotent: a no-op
   * (returns FALSE) if the donation is not currently pending.
   *
   * @param array $stripeContext
   *   Optional keys: payment_intent_id, customer_id.
   */
  public function markSucceeded(int $donationId, array $stripeContext = []): bool;

  /**
   * Transitions a donation from pending to failed. Idempotent.
   */
  public function markFailed(int $donationId): bool;

  /**
   * Transitions a donation from pending to expired. Idempotent.
   */
  public function markExpired(int $donationId): bool;

  /**
   * Transitions a donation from succeeded to refunded. Idempotent.
   */
  public function markRefunded(int $donationId, ?int $refundedAmount = NULL): bool;

  /**
   * Processes a verified checkout.session.completed event: resolves the
   * donation, confirms actual payment status, and marks it succeeded.
   *
   * Does NOT trust the event alone: if Stripe reports the session's
   * payment_status as anything other than 'paid' (e.g. an async payment
   * method still settling), the donation is left pending.
   */
  public function processSuccessfulCheckout(object $session): array;

  /**
   * Processes a verified checkout.session.expired event.
   */
  public function processCheckoutSessionExpired(object $session): array;

  /**
   * Processes a verified payment_intent.succeeded event.
   */
  public function processPaymentIntentSucceeded(object $paymentIntent): array;

  /**
   * Processes a verified payment_intent.payment_failed event.
   */
  public function processPaymentIntentFailed(object $paymentIntent): array;

  /**
   * Refunds a succeeded donation via Stripe, in full or in part.
   *
   * @param int $donationId
   *   The donation's internal ID.
   * @param int|null $amount
   *   Amount to refund in the smallest currency unit, or NULL for a full
   *   refund.
   *
   * @return array
   *   ['success' => bool, 'error'?: string, 'refund_id'?: string, 'pending'?: bool].
   */
  public function refundDonation(int $donationId, ?int $amount = NULL): array;

  /**
   * Formats a short human-facing reference for a donation ID (e.g.
   * DON-000123 by default; prefix and digit padding are configurable via
   * 'donation.reference_prefix' and 'donation.reference_digits').
   */
  public function formatReference(int $donationId): string;

}
