<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Service;

use Stripe\Event;

/**
 * Handles verification, idempotent storage and dispatch of Stripe webhook
 * events.
 *
 * The {simple_stripe_donation_event} table's UNIQUE(stripe_event_id)
 * constraint is the actual idempotency guarantee (a database-level
 * protection, not just an application-level check that could race); this
 * service's job is to use that guarantee correctly.
 */
interface WebhookServiceInterface {

  /**
   * Verifies a webhook signature and returns the constructed Stripe Event.
   *
   * @throws \UnexpectedValueException
   *   If the payload is malformed.
   * @throws \Stripe\Exception\SignatureVerificationException
   *   If the signature does not match.
   * @throws \Drupal\simple_stripe_donation\Exception\StripeConfigurationException
   *   If no webhook secret is configured.
   */
  public function validateEvent(string $payload, string $sigHeader): Event;

  /**
   * Checks whether an event has already been fully processed.
   */
  public function isProcessed(string $stripeEventId): bool;

  /**
   * Stores a verified event for idempotency tracking and auditing.
   *
   * @return bool
   *   TRUE if this was a new event row (first delivery seen). FALSE if a
   *   row for this stripe_event_id already existed (a retried delivery) —
   *   the unique index prevents inserting it twice.
   */
  public function storeEvent(Event $event): bool;

  /**
   * Dispatches a verified event to the appropriate DonationService method.
   *
   * Unsupported/unrecognized event types are safely ignored (logged, not
   * an error), so additional event types can be enabled later without
   * requiring Stripe dashboard changes to stop sending old ones.
   *
   * @throws \Throwable
   *   Any processing failure propagates so the caller can decide whether
   *   Stripe should retry.
   */
  public function processEvent(Event $event): void;

  /**
   * Marks a stored event as successfully processed.
   */
  public function markProcessed(string $stripeEventId): void;

  /**
   * End-to-end webhook handling: verify, deduplicate, process, mark.
   *
   * @return array
   *   ['success' => bool, 'status_code' => int, 'message' => string].
   *   The controller should return status_code as the HTTP response code.
   */
  public function handleWebhook(string $payload, string $sigHeader): array;

}
