<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_stripe_donation\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\EmailValidator;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\simple_stripe_donation\Repository\DonationRepositoryInterface;
use Drupal\simple_stripe_donation\Service\DonationService;
use Drupal\simple_stripe_donation\Service\DonationServiceInterface;
use Drupal\simple_stripe_donation\Service\MoneyService;
use Drupal\simple_stripe_donation\Service\StripeServiceInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\simple_stripe_donation\Service\DonationService
 * @group simple_stripe_donation
 */
class DonationServiceTest extends UnitTestCase {

  /**
   * Default config values used unless a test overrides one.
   */
  protected function defaultConfigMap(): array {
    return [
      'stripe.currency' => 'usd',
      'donation.minimum_amount' => '1',
      'donation.maximum_amount' => '10000',
      'donation.allow_anonymous' => TRUE,
      'donation.allow_donor_name' => TRUE,
      'donation.allow_donor_message' => TRUE,
      'email.send_donor_receipt' => TRUE,
      'email.send_admin_notification' => TRUE,
      'email.admin_notification_email' => 'admin@example.com',
      'email.donor_subject' => 'Thanks - [donation:reference]',
      'email.admin_subject' => 'New donation - [donation:reference]',
    ];
  }

  /**
   * Builds a DonationService with mocked collaborators.
   *
   * @return array{0: DonationService, 1: DonationRepositoryInterface, 2: StripeServiceInterface, 3: MailManagerInterface}
   */
  protected function buildService(array $configOverrides = [], ?DonationRepositoryInterface $repository = NULL, ?StripeServiceInterface $stripeService = NULL): array {
    $repository = $repository ?? $this->createMock(DonationRepositoryInterface::class);
    $stripeService = $stripeService ?? $this->createMock(StripeServiceInterface::class);

    $configMap = $configOverrides + $this->defaultConfigMap();
    $immutableConfig = $this->createMock(ImmutableConfig::class);
    $immutableConfig->method('get')->willReturnCallback(function (string $key) use ($configMap) {
      return $configMap[$key] ?? NULL;
    });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($immutableConfig);

    $loggerChannel = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($loggerChannel);

    $uuid = $this->createMock(UuidInterface::class);
    $uuid->method('generate')->willReturn('11111111-1111-1111-1111-111111111111');

    $mailManager = $this->createMock(MailManagerInterface::class);
    $mailManager->method('mail')->willReturn(['result' => TRUE]);

    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getDefaultLanguage')->willReturn($language);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1700000000);

    $service = new DonationService(
      $repository,
      $stripeService,
      new MoneyService(),
      $configFactory,
      $loggerFactory,
      $uuid,
      $mailManager,
      $languageManager,
      new EmailValidator(),
      $time
    );

    return [$service, $repository, $stripeService, $mailManager];
  }

  /**
   * @covers ::createDonation
   */
  public function testCreateDonationRejectsInvalidAmount(): void {
    [$service, $repository] = $this->buildService();
    $repository->expects($this->never())->method('insert');

    $result = $service->createDonation([
      'amount' => 'not-a-number',
      'donor_email' => 'donor@example.com',
    ]);

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('valid donation amount', $result['error']);
  }

  /**
   * @covers ::createDonation
   */
  public function testCreateDonationRejectsBelowMinimum(): void {
    [$service, $repository] = $this->buildService();
    $repository->expects($this->never())->method('insert');

    $result = $service->createDonation([
      'amount' => '0.50',
      'donor_email' => 'donor@example.com',
    ]);

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('minimum donation amount', $result['error']);
  }

  /**
   * @covers ::createDonation
   */
  public function testCreateDonationRejectsAboveMaximum(): void {
    [$service, $repository] = $this->buildService();
    $repository->expects($this->never())->method('insert');

    $result = $service->createDonation([
      'amount' => '999999',
      'donor_email' => 'donor@example.com',
    ]);

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('maximum donation amount', $result['error']);
  }

  /**
   * @covers ::createDonation
   */
  public function testCreateDonationRejectsInvalidEmail(): void {
    [$service, $repository] = $this->buildService();
    $repository->expects($this->never())->method('insert');

    $result = $service->createDonation([
      'amount' => '25',
      'donor_email' => 'not-an-email',
    ]);

    $this->assertFalse($result['success']);
    $this->assertStringContainsString('valid email', $result['error']);
  }

  /**
   * @covers ::createDonation
   */
  public function testCreateDonationSucceeds(): void {
    $repository = $this->createMock(DonationRepositoryInterface::class);
    $repository->expects($this->once())
      ->method('insert')
      ->with($this->callback(function (array $fields) {
        return $fields['amount'] === 2500
          && $fields['currency'] === 'usd'
          && $fields['status'] === DonationServiceInterface::STATUS_PENDING
          && $fields['donor_email'] === 'donor@example.com';
      }))
      ->willReturn(42);

    $repository->expects($this->once())
      ->method('update')
      ->with(42, $this->callback(function (array $fields) {
        return $fields['stripe_checkout_session_id'] === 'cs_test_123';
      }));

    $session = (object) ['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/pay/cs_test_123'];
    $stripeService = $this->createMock(StripeServiceInterface::class);
    $stripeService->expects($this->once())
      ->method('createCheckoutSession')
      ->with($this->callback(function (array $params) {
        return $params['metadata']['donation_id'] === '42'
          && $params['payment_intent_data']['metadata']['donation_id'] === '42';
      }))
      ->willReturn($session);

    [$service] = $this->buildService([], $repository, $stripeService);

    $result = $service->createDonation([
      'amount' => '25',
      'donor_email' => 'donor@example.com',
      'donor_name' => 'Jane Donor',
    ]);

    $this->assertTrue($result['success']);
    $this->assertSame(42, $result['donation_id']);
    $this->assertSame('https://checkout.stripe.com/pay/cs_test_123', $result['checkout_url']);
  }

  /**
   * @covers ::markSucceeded
   */
  public function testMarkSucceededSendsNotificationsOnce(): void {
    $donation = (object) [
      'id' => 42,
      'amount' => 2500,
      'currency' => 'usd',
      'donor_email' => 'donor@example.com',
      'donor_name' => 'Jane Donor',
      'message' => NULL,
      'anonymous' => 0,
      'created' => 1700000000,
      'completed' => 1700000000,
    ];

    $repository = $this->createMock(DonationRepositoryInterface::class);
    $repository->expects($this->once())
      ->method('update')
      ->with(42, $this->anything(), ['status' => DonationServiceInterface::STATUS_PENDING])
      ->willReturn(TRUE);
    $repository->method('loadById')->willReturn($donation);

    [$service, , , $mailManager] = $this->buildService([], $repository);
    $mailManager->expects($this->exactly(2))->method('mail');

    $result = $service->markSucceeded(42, ['payment_intent_id' => 'pi_123']);

    $this->assertTrue($result);
  }

  /**
   * @covers ::markSucceeded
   */
  public function testMarkSucceededIsIdempotent(): void {
    $repository = $this->createMock(DonationRepositoryInterface::class);
    $repository->method('update')->willReturn(FALSE);
    $repository->expects($this->never())->method('loadById');

    [$service, , , $mailManager] = $this->buildService([], $repository);
    $mailManager->expects($this->never())->method('mail');

    $result = $service->markSucceeded(42, ['payment_intent_id' => 'pi_123']);

    $this->assertFalse($result);
  }

  /**
   * @covers ::markFailed
   * @covers ::markExpired
   * @covers ::markRefunded
   */
  public function testGuardedStateTransitions(): void {
    $repository = $this->createMock(DonationRepositoryInterface::class);
    $repository->expects($this->exactly(3))
      ->method('update')
      ->willReturnOnConsecutiveCalls(TRUE, TRUE, TRUE);

    [$service] = $this->buildService([], $repository);

    $this->assertTrue($service->markFailed(1));
    $this->assertTrue($service->markExpired(2));
    $this->assertTrue($service->markRefunded(3));
  }

  /**
   * @covers ::refundDonation
   */
  public function testRefundDonationRejectsNonSucceeded(): void {
    $donation = (object) ['id' => 5, 'status' => DonationServiceInterface::STATUS_PENDING, 'stripe_payment_intent_id' => 'pi_1'];
    $repository = $this->createMock(DonationRepositoryInterface::class);
    $repository->method('loadById')->willReturn($donation);

    $stripeService = $this->createMock(StripeServiceInterface::class);
    $stripeService->expects($this->never())->method('refundPayment');

    [$service] = $this->buildService([], $repository, $stripeService);

    $result = $service->refundDonation(5);

    $this->assertFalse($result['success']);
  }

  /**
   * @covers ::refundDonation
   */
  public function testRefundDonationSucceeds(): void {
    $donation = (object) ['id' => 6, 'status' => DonationServiceInterface::STATUS_SUCCEEDED, 'stripe_payment_intent_id' => 'pi_2'];
    $repository = $this->createMock(DonationRepositoryInterface::class);
    $repository->method('loadById')->willReturn($donation);
    $repository->expects($this->once())
      ->method('update')
      ->with(6, $this->anything(), ['status' => DonationServiceInterface::STATUS_SUCCEEDED])
      ->willReturn(TRUE);

    $refund = (object) ['id' => 're_1', 'status' => 'succeeded'];
    $stripeService = $this->createMock(StripeServiceInterface::class);
    $stripeService->expects($this->once())->method('refundPayment')->with('pi_2', NULL)->willReturn($refund);

    [$service] = $this->buildService([], $repository, $stripeService);

    $result = $service->refundDonation(6);

    $this->assertTrue($result['success']);
    $this->assertSame('re_1', $result['refund_id']);
  }

}
