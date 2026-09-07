<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\simple_stripe_donation\Service\DonationServiceInterface;
use Drupal\simple_stripe_donation\Service\MoneyService;
use Drupal\simple_stripe_donation\Service\StripeServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public /donate/success and /donate/cancel pages, plus the admin donation
 * detail page linked from the report view.
 *
 * The donation form itself is served directly via the DonationForm route
 * (see simple_stripe_donation.routing.yml); no controller wrapper needed.
 */
class DonationController extends ControllerBase {

  protected DonationServiceInterface $donationService;
  protected StripeServiceInterface $stripeService;
  protected MoneyService $moneyService;
  protected $logger;

  public function __construct(
    DonationServiceInterface $donationService,
    StripeServiceInterface $stripeService,
    MoneyService $moneyService,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->donationService = $donationService;
    $this->stripeService = $stripeService;
    $this->moneyService = $moneyService;
    $this->logger = $loggerFactory->get('simple_stripe_donation');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('simple_stripe_donation.donation_service'),
      $container->get('simple_stripe_donation.stripe_service'),
      $container->get('simple_stripe_donation.money_service'),
      $container->get('logger.factory')
    );
  }

  /**
   * /admin/reports/simple-stripe-donations/{donation_id}
   *
   * Read-only detail view linked from the ID and Donor columns of the
   * donation report. Access is gated by the same 'view simple stripe
   * donations' permission as the report itself, so anyone who can see a
   * donation in the list can already see everything shown here.
   */
  public function detail(int $donation_id) {
    $donation = $this->donationService->getDonationById($donation_id);

    if (!$donation) {
      throw new NotFoundHttpException();
    }

    $currency = strtoupper($donation->currency);

    return [
      '#theme' => 'simple_stripe_donation_detail',
      '#donation' => $donation,
      '#reference' => $this->donationService->formatReference((int) $donation->id),
      '#amount_display' => $this->moneyService->formatMajorUnits((int) $donation->amount, $donation->currency),
      '#currency' => $currency,
      '#refund_url' => $donation->status === DonationServiceInterface::STATUS_SUCCEEDED && $this->currentUser()->hasPermission('manage simple stripe donations')
        ? Url::fromRoute('simple_stripe_donation.refund', ['donation_id' => $donation->id])->toString()
        : NULL,
      '#back_url' => Url::fromRoute('view.simple_stripe_donation_report.page_1')->toString(),
      '#attached' => ['library' => ['simple_stripe_donation/donation_form']],
    ];
  }

  /**
   * Title callback for the detail route.
   */
  public function detailTitle(int $donation_id): string {
    $donation = $this->donationService->getDonationById($donation_id);
    return $donation
      ? (string) $this->t('Donation @reference', ['@reference' => $this->donationService->formatReference((int) $donation->id)])
      : (string) $this->t('Donation');
  }

  /**
   * /donate/success?session_id={CHECKOUT_SESSION_ID}
   *
   * Never marks the donation as paid itself: the webhook is authoritative.
   * If the webhook has not landed yet, this asks Stripe directly so the
   * donor sees an accurate status rather than a false confirmation.
   */
  public function success(Request $request) {
    $sessionId = trim((string) $request->query->get('session_id', ''));

    if ($sessionId === '') {
      return $this->buildStatusRenderArray('unknown', NULL);
    }

    $donation = $this->donationService->getDonationByCheckoutSessionId($sessionId);

    if (!$donation) {
      $this->logger->warning('Success page visited for an unrecognized checkout session @id.', ['@id' => $sessionId]);
      return $this->buildStatusRenderArray('unknown', NULL);
    }

    if ($donation->status === DonationServiceInterface::STATUS_PENDING) {
      try {
        $session = $this->stripeService->retrieveCheckoutSession($sessionId);
        if (($session->payment_status ?? NULL) !== 'paid') {
          return $this->buildStatusRenderArray('pending', $donation);
        }
        // Stripe confirms payment, but our webhook has not landed yet.
        // Tell the donor it is confirmed without ourselves writing that
        // state: the webhook remains the source of truth for the record.
        return $this->buildStatusRenderArray('confirmed_pending_webhook', $donation);
      }
      catch (\Throwable $e) {
        $this->logger->error('Unable to retrieve checkout session @id for the success page: @message', [
          '@id' => $sessionId,
          '@message' => $e->getMessage(),
        ]);
        return $this->buildStatusRenderArray('pending', $donation);
      }
    }

    return $this->buildStatusRenderArray($donation->status, $donation);
  }

  /**
   * /donate/cancel
   *
   * Does not mark the donation as failed: visiting this page proves
   * nothing about the actual Stripe payment state.
   */
  public function cancel() {
    return [
      '#theme' => 'simple_stripe_donation_status',
      '#status' => 'cancelled',
      '#heading' => $this->t('Donation not completed'),
      '#message' => $this->t('Your donation was not completed. No completed donation has been recorded.'),
      '#try_again_url' => Url::fromRoute('simple_stripe_donation.donate')->toString(),
      '#attached' => ['library' => ['simple_stripe_donation/donation_form']],
    ];
  }

  /**
   * Builds the themed status render array shared by the success page.
   */
  protected function buildStatusRenderArray(string $status, ?object $donation): array {
    $copy = [
      'succeeded' => [
        $this->t('Thank you for your donation!'),
        $this->t('Your payment has been received and confirmed. A receipt has been sent to your email address.'),
      ],
      'confirmed_pending_webhook' => [
        $this->t('Thank you for your donation!'),
        $this->t('Stripe has confirmed your payment. Your receipt will arrive shortly.'),
      ],
      'pending' => [
        $this->t('Payment processing'),
        $this->t('Your payment is still being processed. Please check your email for confirmation shortly.'),
      ],
      'failed' => [
        $this->t('Payment not completed'),
        $this->t('Your payment could not be completed. No donation has been recorded. Please try again.'),
      ],
      'expired' => [
        $this->t('Checkout session expired'),
        $this->t('Your checkout session expired before payment was completed. No donation has been recorded. Please try again.'),
      ],
      'refunded' => [
        $this->t('Donation refunded'),
        $this->t('This donation has been refunded.'),
      ],
      'unknown' => [
        $this->t('Status unknown'),
        $this->t('We could not determine the status of this donation. If you were charged, please contact us with your payment confirmation.'),
      ],
    ];

    [$heading, $message] = $copy[$status] ?? $copy['unknown'];

    return [
      '#theme' => 'simple_stripe_donation_status',
      '#status' => $status,
      '#heading' => $heading,
      '#message' => $message,
      '#donation' => $donation,
      '#reference' => $donation ? $this->donationService->formatReference((int) $donation->id) : NULL,
      '#try_again_url' => in_array($status, ['failed', 'expired', 'unknown'], TRUE)
        ? Url::fromRoute('simple_stripe_donation.donate')->toString()
        : NULL,
      '#attached' => ['library' => ['simple_stripe_donation/donation_form']],
    ];
  }

}
