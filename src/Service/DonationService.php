<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\simple_stripe_donation\Repository\DonationRepositoryInterface;

/**
 * Default DonationServiceInterface implementation.
 */
class DonationService implements DonationServiceInterface {

  const CONFIG_NAME = 'simple_stripe_donation.settings';

  protected DonationRepositoryInterface $repository;
  protected StripeServiceInterface $stripeService;
  protected MoneyService $moneyService;
  protected ConfigFactoryInterface $configFactory;
  protected $logger;
  protected UuidInterface $uuid;
  protected MailManagerInterface $mailManager;
  protected LanguageManagerInterface $languageManager;
  protected EmailValidatorInterface $emailValidator;
  protected TimeInterface $time;

  public function __construct(
    DonationRepositoryInterface $repository,
    StripeServiceInterface $stripeService,
    MoneyService $moneyService,
    ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
    UuidInterface $uuid,
    MailManagerInterface $mailManager,
    LanguageManagerInterface $languageManager,
    EmailValidatorInterface $emailValidator,
    TimeInterface $time
  ) {
    $this->repository = $repository;
    $this->stripeService = $stripeService;
    $this->moneyService = $moneyService;
    $this->configFactory = $configFactory;
    $this->logger = $loggerFactory->get('simple_stripe_donation');
    $this->uuid = $uuid;
    $this->mailManager = $mailManager;
    $this->languageManager = $languageManager;
    $this->emailValidator = $emailValidator;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public function createDonation(array $input): array {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    $currency = $this->moneyService->normalizeCurrency((string) ($input['currency'] ?? $config->get('stripe.currency') ?? 'usd'));

    // Tolerate thousand-separator commas (e.g. from DonationForm's
    // client-side amount formatter, or any other caller) before validating.
    $amount = str_replace(',', '', trim((string) ($input['amount'] ?? '')));
    if ($amount === '' || !preg_match('/^\d+(\.\d{1,3})?$/', $amount)) {
      return ['success' => FALSE, 'error' => 'Please enter a valid donation amount.'];
    }

    $minMajor = (string) ($config->get('donation.minimum_amount') ?? '1');
    $maxMajor = (string) ($config->get('donation.maximum_amount') ?? '10000');

    try {
      $amountMinor = $this->moneyService->toMinorUnits($amount, $currency);
      $minMinor = $this->moneyService->toMinorUnits($minMajor, $currency);
      $maxMinor = $this->moneyService->toMinorUnits($maxMajor, $currency);
    }
    catch (\InvalidArgumentException $e) {
      return ['success' => FALSE, 'error' => 'Please enter a valid donation amount.'];
    }

    if ($amountMinor <= 0) {
      return ['success' => FALSE, 'error' => 'Donation amount must be greater than zero.'];
    }
    if ($amountMinor < $minMinor) {
      return ['success' => FALSE, 'error' => "The minimum donation amount is {$minMajor} " . strtoupper($currency) . '.'];
    }
    if ($amountMinor > $maxMinor) {
      return ['success' => FALSE, 'error' => "The maximum donation amount is {$maxMajor} " . strtoupper($currency) . '.'];
    }

    $email = trim((string) ($input['donor_email'] ?? ''));
    if ($email === '' || !$this->emailValidator->isValid($email)) {
      return ['success' => FALSE, 'error' => 'Please enter a valid email address.'];
    }

    $allowAnonymous = (bool) $config->get('donation.allow_anonymous');
    $anonymous = $allowAnonymous && !empty($input['anonymous']);

    $donorName = $config->get('donation.allow_donor_name') ? trim((string) ($input['donor_name'] ?? '')) : '';
    $donorPhone = $config->get('donation.allow_donor_phone') ? trim((string) ($input['donor_phone'] ?? '')) : '';
    $donorAddress = $config->get('donation.allow_donor_address') ? trim((string) ($input['donor_address'] ?? '')) : '';
    $message = $config->get('donation.allow_donor_message') ? trim((string) ($input['message'] ?? '')) : '';

    $uid = !empty($input['uid']) ? (int) $input['uid'] : NULL;

    $donationId = $this->repository->insert([
      'uuid' => $this->uuid->generate(),
      'amount' => $amountMinor,
      'currency' => $currency,
      'status' => self::STATUS_PENDING,
      'donor_name' => $donorName !== '' ? $donorName : NULL,
      'donor_email' => $email,
      'donor_phone' => $donorPhone !== '' ? $donorPhone : NULL,
      'donor_address' => $donorAddress !== '' ? $donorAddress : NULL,
      'message' => $message !== '' ? $message : NULL,
      'anonymous' => $anonymous ? 1 : 0,
      'created' => $this->time->getRequestTime(),
      'uid' => $uid,
    ]);

    $reference = $this->formatReference($donationId);

    $sessionParams = [
      'mode' => 'payment',
      'line_items' => [[
        'price_data' => [
          'currency' => $currency,
          'unit_amount' => $amountMinor,
          'product_data' => [
            'name' => 'Donation ' . $reference,
          ],
        ],
        'quantity' => 1,
      ]],
      'customer_email' => $email,
      'success_url' => Url::fromRoute('simple_stripe_donation.donate_success', [], ['absolute' => TRUE])->toString() . '?session_id={CHECKOUT_SESSION_ID}',
      'cancel_url' => Url::fromRoute('simple_stripe_donation.donate_cancel', [], ['absolute' => TRUE])->toString(),
      // Only a non-sensitive numeric reference travels through Stripe.
      // Donor name/message/etc. always stay in our own database.
      'metadata' => ['donation_id' => (string) $donationId],
      'payment_intent_data' => [
        'metadata' => ['donation_id' => (string) $donationId],
      ],
    ];

    try {
      $session = $this->stripeService->createCheckoutSession($sessionParams);
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to create Stripe Checkout Session for donation @id: @message', [
        '@id' => $donationId,
        '@message' => $e->getMessage(),
      ]);
      return ['success' => FALSE, 'error' => 'Unable to reach the payment processor right now. Please try again shortly.'];
    }

    $this->repository->update($donationId, [
      'stripe_checkout_session_id' => $session->id,
    ]);

    $this->logger->info('Donation @id created (pending); Stripe Checkout Session @session_id.', [
      '@id' => $donationId,
      '@session_id' => $session->id,
    ]);

    return [
      'success' => TRUE,
      'donation_id' => $donationId,
      'checkout_url' => $session->url,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDonation(string $uuid): ?object {
    return $this->repository->load($uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function getDonationById(int $donationId): ?object {
    return $this->repository->loadById($donationId);
  }

  /**
   * {@inheritdoc}
   */
  public function getDonationByCheckoutSessionId(string $sessionId): ?object {
    return $this->repository->loadByCheckoutSessionId($sessionId);
  }

  /**
   * {@inheritdoc}
   */
  public function markSucceeded(int $donationId, array $stripeContext = []): bool {
    $fields = [
      'status' => self::STATUS_SUCCEEDED,
      'completed' => $this->time->getRequestTime(),
    ];
    if (!empty($stripeContext['payment_intent_id'])) {
      $fields['stripe_payment_intent_id'] = $stripeContext['payment_intent_id'];
    }
    if (!empty($stripeContext['customer_id'])) {
      $fields['stripe_customer_id'] = $stripeContext['customer_id'];
    }

    $updated = $this->repository->update($donationId, $fields, ['status' => self::STATUS_PENDING]);

    if (!$updated) {
      $this->logger->info('Donation @id is no longer pending; ignoring duplicate/late succeeded event.', ['@id' => $donationId]);
      return FALSE;
    }

    $this->logger->info('Donation @id succeeded.', ['@id' => $donationId]);

    $donation = $this->repository->loadById($donationId);
    if ($donation) {
      $this->sendDonorReceipt($donation);
      $this->sendAdminNotification($donation);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function markFailed(int $donationId): bool {
    $updated = $this->repository->update($donationId, ['status' => self::STATUS_FAILED], ['status' => self::STATUS_PENDING]);
    if ($updated) {
      $this->logger->info('Donation @id failed.', ['@id' => $donationId]);
    }
    return $updated;
  }

  /**
   * {@inheritdoc}
   */
  public function markExpired(int $donationId): bool {
    $updated = $this->repository->update($donationId, ['status' => self::STATUS_EXPIRED], ['status' => self::STATUS_PENDING]);
    if ($updated) {
      $this->logger->info('Donation @id expired.', ['@id' => $donationId]);
    }
    return $updated;
  }

  /**
   * {@inheritdoc}
   */
  public function markRefunded(int $donationId, ?int $refundedAmount = NULL): bool {
    $updated = $this->repository->update($donationId, [
      'status' => self::STATUS_REFUNDED,
      'refunded' => $this->time->getRequestTime(),
    ], ['status' => self::STATUS_SUCCEEDED]);

    if ($updated) {
      $this->logger->info('Donation @id refunded.', ['@id' => $donationId]);
    }

    return $updated;
  }

  /**
   * {@inheritdoc}
   */
  public function processSuccessfulCheckout(object $session): array {
    $donation = $this->resolveDonationFromSession($session);
    if (!$donation) {
      $this->logger->warning('checkout.session.completed received for an unrecognized session @id.', [
        '@id' => $session->id ?? 'unknown',
      ]);
      return ['success' => FALSE, 'error' => 'Unknown donation for checkout session.'];
    }

    if (empty($donation->stripe_checkout_session_id) && !empty($session->id)) {
      $this->repository->update((int) $donation->id, ['stripe_checkout_session_id' => $session->id]);
    }

    $paymentStatus = $session->payment_status ?? NULL;
    if ($paymentStatus !== 'paid') {
      // Some payment methods settle asynchronously; this event alone does
      // not prove funds were received. payment_intent.succeeded (or a
      // later completed event with payment_status=paid) is authoritative.
      $this->logger->info('Checkout session @id completed with payment_status=@status; awaiting final confirmation.', [
        '@id' => $session->id ?? 'unknown',
        '@status' => $paymentStatus ?? 'unknown',
      ]);
      return ['success' => TRUE, 'pending' => TRUE];
    }

    $this->markSucceeded((int) $donation->id, [
      'payment_intent_id' => $this->extractId($session->payment_intent ?? NULL),
      'customer_id' => $this->extractId($session->customer ?? NULL),
    ]);

    return ['success' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function processCheckoutSessionExpired(object $session): array {
    $donation = $this->resolveDonationFromSession($session);
    if (!$donation) {
      $this->logger->warning('checkout.session.expired received for an unrecognized session @id.', [
        '@id' => $session->id ?? 'unknown',
      ]);
      return ['success' => FALSE, 'error' => 'Unknown donation for checkout session.'];
    }

    $this->markExpired((int) $donation->id);
    return ['success' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function processPaymentIntentSucceeded(object $paymentIntent): array {
    $donation = $this->resolveDonationFromPaymentIntent($paymentIntent);
    if (!$donation) {
      $this->logger->warning('payment_intent.succeeded received for an unrecognized payment intent @id.', [
        '@id' => $paymentIntent->id ?? 'unknown',
      ]);
      return ['success' => FALSE, 'error' => 'Unknown donation for payment intent.'];
    }

    $this->markSucceeded((int) $donation->id, [
      'payment_intent_id' => $paymentIntent->id ?? NULL,
      'customer_id' => $this->extractId($paymentIntent->customer ?? NULL),
    ]);

    return ['success' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function processPaymentIntentFailed(object $paymentIntent): array {
    $donation = $this->resolveDonationFromPaymentIntent($paymentIntent);
    if (!$donation) {
      $this->logger->warning('payment_intent.payment_failed received for an unrecognized payment intent @id.', [
        '@id' => $paymentIntent->id ?? 'unknown',
      ]);
      return ['success' => FALSE, 'error' => 'Unknown donation for payment intent.'];
    }

    $this->markFailed((int) $donation->id);
    return ['success' => TRUE];
  }

  /**
   * {@inheritdoc}
   */
  public function refundDonation(int $donationId, ?int $amount = NULL): array {
    $donation = $this->repository->loadById($donationId);
    if (!$donation) {
      return ['success' => FALSE, 'error' => 'Donation not found.'];
    }
    if ($donation->status !== self::STATUS_SUCCEEDED) {
      return ['success' => FALSE, 'error' => 'Only succeeded donations can be refunded.'];
    }
    if (empty($donation->stripe_payment_intent_id)) {
      return ['success' => FALSE, 'error' => 'This donation has no associated Stripe payment to refund.'];
    }

    try {
      $refund = $this->stripeService->refundPayment($donation->stripe_payment_intent_id, $amount);
    }
    catch (\Throwable $e) {
      $this->logger->error('Refund failed for donation @id: @message', ['@id' => $donationId, '@message' => $e->getMessage()]);
      return ['success' => FALSE, 'error' => 'Unable to process the refund right now. Please try again shortly.'];
    }

    $refundStatus = $refund->status ?? 'succeeded';

    if ($refundStatus === 'failed') {
      $this->logger->error('Stripe declined refund for donation @id.', ['@id' => $donationId]);
      return ['success' => FALSE, 'error' => 'Stripe declined the refund.'];
    }

    if ($refundStatus === 'succeeded') {
      $this->markRefunded($donationId, $amount);
      return ['success' => TRUE, 'refund_id' => $refund->id];
    }

    // Some refund methods settle asynchronously. Do not mark the donation
    // refunded until Stripe confirms it (see README roadmap: v1 does not
    // yet listen for the charge.refunded webhook).
    $this->logger->info('Refund @refund_id for donation @id is @status; awaiting final confirmation.', [
      '@refund_id' => $refund->id,
      '@id' => $donationId,
      '@status' => $refundStatus,
    ]);

    return ['success' => TRUE, 'refund_id' => $refund->id, 'pending' => TRUE];
  }

  /**
   * Formats a short human-facing reference for a donation ID.
   */
  public function formatReference(int $donationId): string {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    $prefix = (string) ($config->get('donation.reference_prefix') ?: 'DON');
    $digits = (int) ($config->get('donation.reference_digits') ?: 6);
    return $prefix . '-' . str_pad((string) $donationId, $digits, '0', STR_PAD_LEFT);
  }

  /**
   * Resolves the local donation for a Stripe Checkout Session payload.
   */
  protected function resolveDonationFromSession(object $session): ?object {
    $donationId = $session->metadata->donation_id ?? NULL;
    if ($donationId) {
      $donation = $this->repository->loadById((int) $donationId);
      if ($donation) {
        return $donation;
      }
    }
    if (!empty($session->id)) {
      return $this->repository->loadByCheckoutSessionId($session->id);
    }
    return NULL;
  }

  /**
   * Resolves the local donation for a Stripe PaymentIntent payload.
   */
  protected function resolveDonationFromPaymentIntent(object $paymentIntent): ?object {
    $donationId = $paymentIntent->metadata->donation_id ?? NULL;
    if ($donationId) {
      $donation = $this->repository->loadById((int) $donationId);
      if ($donation) {
        return $donation;
      }
    }
    if (!empty($paymentIntent->id)) {
      return $this->repository->loadByPaymentIntentId($paymentIntent->id);
    }
    return NULL;
  }

  /**
   * Stripe API objects sometimes expand a related object and sometimes
   * leave it as a bare ID string; this normalizes either to an ID.
   */
  protected function extractId($value): ?string {
    if (is_string($value)) {
      return $value;
    }
    if (is_object($value) && isset($value->id)) {
      return (string) $value->id;
    }
    return NULL;
  }

  /**
   * Sends the donor a receipt email, if enabled and an address is known.
   */
  protected function sendDonorReceipt(object $donation): void {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    if (!$config->get('email.send_donor_receipt') || empty($donation->donor_email)) {
      return;
    }

    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail('simple_stripe_donation', 'donor_receipt', $donation->donor_email, $langcode, [
      'donation' => $donation,
      'reference' => $this->formatReference((int) $donation->id),
    ]);

    if (empty($result['result'])) {
      $this->logger->error('Failed to send donor receipt for donation @id.', ['@id' => $donation->id]);
    }
  }

  /**
   * Sends the site admin a notification email, if enabled and configured.
   */
  protected function sendAdminNotification(object $donation): void {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    $adminEmail = $config->get('email.admin_notification_email');
    if (!$config->get('email.send_admin_notification') || empty($adminEmail)) {
      return;
    }

    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail('simple_stripe_donation', 'admin_notification', $adminEmail, $langcode, [
      'donation' => $donation,
      'reference' => $this->formatReference((int) $donation->id),
    ]);

    if (empty($result['result'])) {
      $this->logger->error('Failed to send admin notification for donation @id.', ['@id' => $donation->id]);
    }
  }

}
