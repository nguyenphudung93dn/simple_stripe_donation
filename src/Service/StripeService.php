<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\simple_stripe_donation\Exception\StripeConfigurationException;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Default StripeService implementation, backed by the Stripe PHP SDK.
 *
 * Secrets are resolved with environment variables taking priority over
 * configuration (see README.md "Secret management"). Nothing in this class
 * ever logs a key/secret value.
 */
class StripeService implements StripeServiceInterface {

  const CONFIG_NAME = 'simple_stripe_donation.settings';

  const DEFAULT_MODE = 'test';

  /**
   * The config factory service.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The 'simple_stripe_donation' logger channel.
   */
  protected $logger;

  /**
   * Lazily-instantiated Stripe SDK client.
   */
  protected ?StripeClient $client = NULL;

  public function __construct(ConfigFactoryInterface $configFactory, LoggerChannelFactoryInterface $loggerFactory) {
    $this->configFactory = $configFactory;
    $this->logger = $loggerFactory->get('simple_stripe_donation');
  }

  /**
   * {@inheritdoc}
   */
  public function getMode(): string {
    $mode = $this->configFactory->get(self::CONFIG_NAME)->get('stripe.mode');
    return in_array($mode, ['test', 'live'], TRUE) ? $mode : self::DEFAULT_MODE;
  }

  /**
   * {@inheritdoc}
   */
  public function getPublishableKey(): string {
    $envValue = getenv('STRIPE_PUBLISHABLE_KEY');
    if ($envValue !== FALSE && $envValue !== '') {
      return $envValue;
    }

    return (string) $this->configFactory->get(self::CONFIG_NAME)->get('stripe.' . $this->getMode() . '_publishable_key');
  }

  /**
   * Resolves the active secret key: environment variable first, then config.
   *
   * @throws \Drupal\simple_stripe_donation\Exception\StripeConfigurationException
   */
  protected function getSecretKey(): string {
    $envValue = getenv('STRIPE_SECRET_KEY');
    if ($envValue !== FALSE && $envValue !== '') {
      return $envValue;
    }

    $key = (string) $this->configFactory->get(self::CONFIG_NAME)->get('stripe.' . $this->getMode() . '_secret_key');
    if ($key === '') {
      throw new StripeConfigurationException('Stripe secret key is not configured for the active mode.');
    }

    return $key;
  }

  /**
   * Resolves the active webhook signing secret: environment first, then config.
   *
   * @throws \Drupal\simple_stripe_donation\Exception\StripeConfigurationException
   */
  protected function getWebhookSecret(): string {
    $envValue = getenv('STRIPE_WEBHOOK_SECRET');
    if ($envValue !== FALSE && $envValue !== '') {
      return $envValue;
    }

    $secret = (string) $this->configFactory->get(self::CONFIG_NAME)->get('stripe.' . $this->getMode() . '_webhook_secret');
    if ($secret === '') {
      throw new StripeConfigurationException('Stripe webhook signing secret is not configured for the active mode.');
    }

    return $secret;
  }

  /**
   * Gets (and lazily builds) the Stripe SDK client for the active secret key.
   *
   * @throws \Drupal\simple_stripe_donation\Exception\StripeConfigurationException
   */
  protected function getClient(): StripeClient {
    if (!$this->client) {
      $this->client = new StripeClient($this->getSecretKey());
    }

    return $this->client;
  }

  /**
   * {@inheritdoc}
   */
  public function createCheckoutSession(array $params): Session {
    try {
      $session = $this->getClient()->checkout->sessions->create($params);
      $this->logger->info('Stripe Checkout Session created: @id', ['@id' => $session->id]);
      return $session;
    }
    catch (ApiErrorException $e) {
      $this->logger->error('Stripe API error creating checkout session: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function retrieveCheckoutSession(string $sessionId): Session {
    try {
      return $this->getClient()->checkout->sessions->retrieve($sessionId, [
        'expand' => ['payment_intent'],
      ]);
    }
    catch (ApiErrorException $e) {
      $this->logger->error('Stripe API error retrieving checkout session @id: @message', [
        '@id' => $sessionId,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent {
    try {
      return $this->getClient()->paymentIntents->retrieve($paymentIntentId);
    }
    catch (ApiErrorException $e) {
      $this->logger->error('Stripe API error retrieving payment intent @id: @message', [
        '@id' => $paymentIntentId,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(string $paymentIntentId, ?int $amount = NULL): Refund {
    $params = ['payment_intent' => $paymentIntentId];
    if ($amount !== NULL) {
      $params['amount'] = $amount;
    }

    try {
      $refund = $this->getClient()->refunds->create($params);
      $this->logger->info('Stripe refund @refund_id created for payment intent @id.', [
        '@refund_id' => $refund->id,
        '@id' => $paymentIntentId,
      ]);
      return $refund;
    }
    catch (ApiErrorException $e) {
      $this->logger->error('Stripe API error refunding payment intent @id: @message', [
        '@id' => $paymentIntentId,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function constructWebhookEvent(string $payload, string $sigHeader): Event {
    return Webhook::constructEvent($payload, $sigHeader, $this->getWebhookSecret());
  }

  /**
   * {@inheritdoc}
   */
  public function testConnection(string $secretKeyCandidate): bool {
    try {
      (new StripeClient($secretKeyCandidate))->balance->retrieve();
      return TRUE;
    }
    catch (\Throwable $e) {
      // Never log $e->getMessage() here: some Stripe API error messages
      // for malformed keys echo back a fragment of the offending value.
      $this->logger->warning('Stripe connection test failed.');
      return FALSE;
    }
  }

}
