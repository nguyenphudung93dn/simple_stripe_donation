<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_stripe_donation\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\simple_stripe_donation\Exception\StripeConfigurationException;
use Drupal\simple_stripe_donation\Service\DonationServiceInterface;
use Drupal\simple_stripe_donation\Service\StripeServiceInterface;
use Drupal\simple_stripe_donation\Service\WebhookService;
use Drupal\Tests\UnitTestCase;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;

/**
 * @coversDefaultClass \Drupal\simple_stripe_donation\Service\WebhookService
 * @group simple_stripe_donation
 */
class WebhookServiceTest extends UnitTestCase {

  protected Connection $connection;
  protected StripeServiceInterface $stripeService;
  protected DonationServiceInterface $donationService;
  protected WebhookService $webhookService;

  protected function setUp(): void {
    parent::setUp();

    $this->connection = $this->createMock(Connection::class);
    $this->stripeService = $this->createMock(StripeServiceInterface::class);
    $this->donationService = $this->createMock(DonationServiceInterface::class);

    $loggerChannel = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($loggerChannel);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);

    $this->webhookService = new WebhookService(
      $this->connection,
      $this->stripeService,
      $this->donationService,
      $loggerFactory,
      $time
    );
  }

  protected function buildEvent(string $type, array $objectData): Event {
    return Event::constructFrom([
      'id' => 'evt_' . substr(md5($type . serialize($objectData)), 0, 12),
      'type' => $type,
      'data' => ['object' => $objectData],
    ]);
  }

  /**
   * @covers ::processEvent
   */
  public function testProcessEventDispatchesCheckoutSessionCompleted(): void {
    $event = $this->buildEvent('checkout.session.completed', ['id' => 'cs_1', 'payment_status' => 'paid']);
    $this->donationService->expects($this->once())->method('processSuccessfulCheckout');
    $this->webhookService->processEvent($event);
  }

  /**
   * @covers ::processEvent
   */
  public function testProcessEventDispatchesCheckoutSessionExpired(): void {
    $event = $this->buildEvent('checkout.session.expired', ['id' => 'cs_2']);
    $this->donationService->expects($this->once())->method('processCheckoutSessionExpired');
    $this->webhookService->processEvent($event);
  }

  /**
   * @covers ::processEvent
   */
  public function testProcessEventDispatchesPaymentIntentSucceeded(): void {
    $event = $this->buildEvent('payment_intent.succeeded', ['id' => 'pi_1']);
    $this->donationService->expects($this->once())->method('processPaymentIntentSucceeded');
    $this->webhookService->processEvent($event);
  }

  /**
   * @covers ::processEvent
   */
  public function testProcessEventDispatchesPaymentIntentFailed(): void {
    $event = $this->buildEvent('payment_intent.payment_failed', ['id' => 'pi_2']);
    $this->donationService->expects($this->once())->method('processPaymentIntentFailed');
    $this->webhookService->processEvent($event);
  }

  /**
   * @covers ::processEvent
   */
  public function testProcessEventIgnoresUnsupportedType(): void {
    $event = $this->buildEvent('charge.dispute.created', ['id' => 'dp_1']);
    $this->donationService->expects($this->never())->method('processSuccessfulCheckout');
    $this->donationService->expects($this->never())->method('processCheckoutSessionExpired');
    $this->donationService->expects($this->never())->method('processPaymentIntentSucceeded');
    $this->donationService->expects($this->never())->method('processPaymentIntentFailed');
    $this->webhookService->processEvent($event);
  }

  /**
   * @covers ::validateEvent
   */
  public function testValidateEventDelegatesToStripeService(): void {
    $event = $this->buildEvent('payment_intent.succeeded', ['id' => 'pi_3']);
    $this->stripeService->expects($this->once())
      ->method('constructWebhookEvent')
      ->with('raw-payload', 'sig-header')
      ->willReturn($event);

    $this->assertSame($event, $this->webhookService->validateEvent('raw-payload', 'sig-header'));
  }

  /**
   * @covers ::handleWebhook
   */
  public function testHandleWebhookRejectsMalformedPayload(): void {
    $this->stripeService->method('constructWebhookEvent')->willThrowException(new \UnexpectedValueException('bad json'));
    $this->connection->expects($this->never())->method('insert');

    $result = $this->webhookService->handleWebhook('not-json', 'sig');

    $this->assertFalse($result['success']);
    $this->assertSame(400, $result['status_code']);
  }

  /**
   * @covers ::handleWebhook
   */
  public function testHandleWebhookRejectsInvalidSignature(): void {
    $this->stripeService->method('constructWebhookEvent')
      ->willThrowException(SignatureVerificationException::factory('bad signature'));
    $this->connection->expects($this->never())->method('insert');

    $result = $this->webhookService->handleWebhook('{}', 'bad-sig');

    $this->assertFalse($result['success']);
    $this->assertSame(400, $result['status_code']);
  }

  /**
   * @covers ::handleWebhook
   */
  public function testHandleWebhookReportsMissingConfiguration(): void {
    $this->stripeService->method('constructWebhookEvent')
      ->willThrowException(new StripeConfigurationException('Stripe webhook signing secret is not configured for the active mode.'));
    $this->connection->expects($this->never())->method('insert');

    $result = $this->webhookService->handleWebhook('{}', 'sig');

    $this->assertFalse($result['success']);
    $this->assertSame(500, $result['status_code']);
  }

}
