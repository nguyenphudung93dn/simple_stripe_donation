<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\simple_stripe_donation\Exception\StripeConfigurationException;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;

/**
 * Default WebhookServiceInterface implementation.
 */
class WebhookService implements WebhookServiceInterface {

  const TABLE = 'simple_stripe_donation_event';

  protected Connection $connection;
  protected StripeServiceInterface $stripeService;
  protected DonationServiceInterface $donationService;
  protected $logger;
  protected TimeInterface $time;

  public function __construct(
    Connection $connection,
    StripeServiceInterface $stripeService,
    DonationServiceInterface $donationService,
    LoggerChannelFactoryInterface $loggerFactory,
    TimeInterface $time
  ) {
    $this->connection = $connection;
    $this->stripeService = $stripeService;
    $this->donationService = $donationService;
    $this->logger = $loggerFactory->get('simple_stripe_donation');
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public function validateEvent(string $payload, string $sigHeader): Event {
    return $this->stripeService->constructWebhookEvent($payload, $sigHeader);
  }

  /**
   * {@inheritdoc}
   */
  public function isProcessed(string $stripeEventId): bool {
    $processed = $this->connection->select(self::TABLE, 'e')
      ->fields('e', ['processed'])
      ->condition('stripe_event_id', $stripeEventId)
      ->execute()
      ->fetchField();

    return $processed !== FALSE && (bool) $processed;
  }

  /**
   * {@inheritdoc}
   */
  public function storeEvent(Event $event): bool {
    try {
      $this->connection->insert(self::TABLE)
        ->fields([
          'stripe_event_id' => $event->id,
          'event_type' => $event->type,
          'payload' => json_encode($event->toArray(), JSON_UNESCAPED_SLASHES),
          'processed' => 0,
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();

      return TRUE;
    }
    catch (IntegrityConstraintViolationException $e) {
      // UNIQUE(stripe_event_id) rejected a row that already exists: this
      // is a retried delivery, database-guaranteed, not an app-level guess.
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function markProcessed(string $stripeEventId): void {
    $this->connection->update(self::TABLE)
      ->fields([
        'processed' => 1,
        'processed_at' => $this->time->getRequestTime(),
      ])
      ->condition('stripe_event_id', $stripeEventId)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function processEvent(Event $event): void {
    switch ($event->type) {
      case 'checkout.session.completed':
        $this->donationService->processSuccessfulCheckout($event->data['object']);
        break;

      case 'checkout.session.expired':
        $this->donationService->processCheckoutSessionExpired($event->data['object']);
        break;

      case 'payment_intent.succeeded':
        $this->donationService->processPaymentIntentSucceeded($event->data['object']);
        break;

      case 'payment_intent.payment_failed':
        $this->donationService->processPaymentIntentFailed($event->data['object']);
        break;

      default:
        // Forward compatible: new event types can be enabled in the Stripe
        // dashboard without this handler erroring out.
        $this->logger->info('Ignoring unsupported webhook event type @type.', ['@type' => $event->type]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function handleWebhook(string $payload, string $sigHeader): array {
    try {
      $event = $this->validateEvent($payload, $sigHeader);
    }
    catch (\UnexpectedValueException $e) {
      $this->logger->warning('Stripe webhook: malformed payload (@length bytes).', ['@length' => strlen($payload)]);
      return ['success' => FALSE, 'status_code' => 400, 'message' => 'Invalid payload'];
    }
    catch (SignatureVerificationException $e) {
      $this->logger->warning('Stripe webhook: invalid signature.');
      return ['success' => FALSE, 'status_code' => 400, 'message' => 'Invalid signature'];
    }
    catch (StripeConfigurationException $e) {
      // Never include $e->getMessage() key/secret values; this exception's
      // message is always safe (see StripeConfigurationException).
      $this->logger->error('Stripe webhook: @message', ['@message' => $e->getMessage()]);
      return ['success' => FALSE, 'status_code' => 500, 'message' => 'Webhook not configured'];
    }

    $isNewEvent = $this->storeEvent($event);

    if (!$isNewEvent) {
      if ($this->isProcessed($event->id)) {
        $this->logger->info('Duplicate webhook event @id (@type) ignored; already processed.', [
          '@id' => $event->id,
          '@type' => $event->type,
        ]);
        return ['success' => TRUE, 'status_code' => 200, 'message' => 'Duplicate event ignored'];
      }

      // Previously stored but never finished processing (e.g. the process
      // crashed after storeEvent() but before markProcessed()). Re-attempt
      // rather than silently drop it; DonationService's guarded state
      // transitions make re-processing safe.
      $this->logger->info('Retrying previously unprocessed webhook event @id (@type).', [
        '@id' => $event->id,
        '@type' => $event->type,
      ]);
    }
    else {
      $this->logger->info('Webhook received: @type (@id).', ['@id' => $event->id, '@type' => $event->type]);
    }

    try {
      $this->processEvent($event);
    }
    catch (\Throwable $e) {
      $this->logger->error('Error processing webhook event @id (@type): @message', [
        '@id' => $event->id,
        '@type' => $event->type,
        '@message' => $e->getMessage(),
      ]);
      // Non-2xx so Stripe retries; the event row stays processed=0.
      return ['success' => FALSE, 'status_code' => 500, 'message' => 'Processing failed'];
    }

    $this->markProcessed($event->id);

    return ['success' => TRUE, 'status_code' => 200, 'message' => 'Processed'];
  }

}
