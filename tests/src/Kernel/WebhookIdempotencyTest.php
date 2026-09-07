<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_stripe_donation\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_stripe_donation\Service\DonationServiceInterface;
use Drupal\simple_stripe_donation\Service\StripeServiceInterface;
use Drupal\simple_stripe_donation\Service\WebhookService;
use Stripe\Event;

/**
 * Verifies webhook idempotency is enforced at the database level, not just
 * in application code.
 *
 * @coversDefaultClass \Drupal\simple_stripe_donation\Service\WebhookService
 * @group simple_stripe_donation
 */
class WebhookIdempotencyTest extends KernelTestBase {

  protected static $modules = ['simple_stripe_donation'];

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('simple_stripe_donation', [
      'simple_stripe_donation',
      'simple_stripe_donation_event',
    ]);
  }

  protected function buildEvent(string $id, string $type = 'payment_intent.succeeded'): Event {
    return Event::constructFrom([
      'id' => $id,
      'type' => $type,
      'data' => ['object' => ['id' => 'pi_1']],
    ]);
  }

  protected function buildWebhookService(StripeServiceInterface $stripeService, DonationServiceInterface $donationService): WebhookService {
    return new WebhookService(
      \Drupal::database(),
      $stripeService,
      $donationService,
      \Drupal::service('logger.factory'),
      \Drupal::service('datetime.time')
    );
  }

  /**
   * @covers ::storeEvent
   */
  public function testStoreEventIsIdempotentAtDatabaseLevel(): void {
    $webhookService = $this->buildWebhookService(
      $this->createMock(StripeServiceInterface::class),
      $this->createMock(DonationServiceInterface::class)
    );

    $event = $this->buildEvent('evt_dup_1');

    $this->assertTrue($webhookService->storeEvent($event));
    // Second delivery of the same event ID: UNIQUE(stripe_event_id)
    // rejects the insert; storeEvent() reports it as not-new.
    $this->assertFalse($webhookService->storeEvent($event));
  }

  /**
   * @covers ::isProcessed
   * @covers ::markProcessed
   */
  public function testIsProcessedAndMarkProcessed(): void {
    $webhookService = $this->buildWebhookService(
      $this->createMock(StripeServiceInterface::class),
      $this->createMock(DonationServiceInterface::class)
    );

    $event = $this->buildEvent('evt_dup_2');
    $webhookService->storeEvent($event);

    $this->assertFalse($webhookService->isProcessed('evt_dup_2'));
    $webhookService->markProcessed('evt_dup_2');
    $this->assertTrue($webhookService->isProcessed('evt_dup_2'));
  }

  /**
   * @covers ::handleWebhook
   */
  public function testHandleWebhookIgnoresDuplicateOfProcessedEvent(): void {
    $donationService = $this->createMock(DonationServiceInterface::class);
    $donationService->expects($this->once())->method('processPaymentIntentSucceeded');

    $event = $this->buildEvent('evt_dup_3');
    $stripeService = $this->createMock(StripeServiceInterface::class);
    $stripeService->method('constructWebhookEvent')->willReturn($event);

    $webhookService = $this->buildWebhookService($stripeService, $donationService);

    $first = $webhookService->handleWebhook('payload', 'sig');
    $this->assertTrue($first['success']);
    $this->assertSame(200, $first['status_code']);

    // Retry of the exact same event: already processed, so DonationService
    // must not run again (assertion above: exactly once), but Stripe still
    // gets a clean 200 so it stops retrying.
    $second = $webhookService->handleWebhook('payload', 'sig');
    $this->assertTrue($second['success']);
    $this->assertSame(200, $second['status_code']);
  }

  /**
   * @covers ::handleWebhook
   */
  public function testHandleWebhookRetriesEventThatFailedProcessing(): void {
    $donationService = $this->createMock(DonationServiceInterface::class);
    $donationService->expects($this->exactly(2))
      ->method('processPaymentIntentSucceeded')
      ->willThrowException(new \RuntimeException('simulated failure'));

    $event = $this->buildEvent('evt_retry_1');
    $stripeService = $this->createMock(StripeServiceInterface::class);
    $stripeService->method('constructWebhookEvent')->willReturn($event);

    $webhookService = $this->buildWebhookService($stripeService, $donationService);

    $first = $webhookService->handleWebhook('payload', 'sig');
    $this->assertFalse($first['success']);
    $this->assertSame(500, $first['status_code']);
    $this->assertFalse($webhookService->isProcessed('evt_retry_1'));

    // Stripe retries because it got a 500. Since the event was never
    // marked processed, WebhookService must attempt processing again
    // rather than silently dropping it.
    $second = $webhookService->handleWebhook('payload', 'sig');
    $this->assertFalse($second['success']);
    $this->assertSame(500, $second['status_code']);
  }

}
