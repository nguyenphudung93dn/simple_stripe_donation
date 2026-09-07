<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\simple_stripe_donation\Service\DonationServiceInterface;
use Drupal\simple_stripe_donation\Service\MoneyService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Confirms an admin-initiated refund before calling DonationService.
 *
 * A confirm form (standard Drupal Form API, POST + CSRF token) is used
 * rather than a bare link so a refund — a real, hard-to-reverse Stripe
 * API call — can never be triggered by a GET request.
 */
class RefundConfirmForm extends ConfirmFormBase {

  // Route name Views generates for the shipped donation report view's page
  // display (config/install/views.view.simple_stripe_donation_report.yml);
  // Views page routes always use the pattern view.{view_id}.{display_id}
  // and cannot be given a custom route name.
  const REPORT_ROUTE = 'view.simple_stripe_donation_report.page_1';

  protected DonationServiceInterface $donationService;
  protected MoneyService $moneyService;
  protected ?object $donation = NULL;

  public function __construct(DonationServiceInterface $donationService, MoneyService $moneyService) {
    $this->donationService = $donationService;
    $this->moneyService = $moneyService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('simple_stripe_donation.donation_service'),
      $container->get('simple_stripe_donation.money_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'simple_stripe_donation_refund_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $donation_id = NULL) {
    $this->donation = $donation_id !== NULL ? $this->donationService->getDonationById((int) $donation_id) : NULL;

    if (!$this->donation || $this->donation->status !== DonationServiceInterface::STATUS_SUCCEEDED) {
      throw new NotFoundHttpException();
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $amount = $this->moneyService->formatMajorUnits((int) $this->donation->amount, $this->donation->currency);
    return $this->t('Refund donation @ref (@amount @currency)?', [
      '@ref' => $this->donationService->formatReference((int) $this->donation->id),
      '@amount' => $amount,
      '@currency' => strtoupper($this->donation->currency),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This issues a real refund through Stripe and cannot be undone from this screen.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Refund');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute(self::REPORT_ROUTE);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $reference = $this->donationService->formatReference((int) $this->donation->id);
    $result = $this->donationService->refundDonation((int) $this->donation->id);

    if (!empty($result['success'])) {
      if (!empty($result['pending'])) {
        $this->messenger()->addStatus($this->t('Refund initiated for @ref; awaiting final confirmation from Stripe.', ['@ref' => $reference]));
      }
      else {
        $this->messenger()->addStatus($this->t('Donation @ref has been refunded.', ['@ref' => $reference]));
      }
    }
    else {
      $this->messenger()->addError($result['error'] ?? $this->t('Unable to refund donation @ref.', ['@ref' => $reference]));
    }

    $form_state->setRedirectUrl(Url::fromRoute(self::REPORT_ROUTE));
  }

}
