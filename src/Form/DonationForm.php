<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\simple_stripe_donation\Service\DonationServiceInterface;
use Drupal\simple_stripe_donation\Service\MoneyService;
use Drupal\simple_stripe_donation\Service\StripeServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The public donation form, used directly at /donate and reused by the
 * Simple Stripe Donation block.
 *
 * Laid out as a stack of cards: Amount, Dedication (optional donor
 * identity, progressively disclosed behind a Yes/No toggle) and Message,
 * followed by a standalone submit button.
 *
 * All amount/eligibility validation performed here is a UX convenience;
 * DonationService re-validates everything server-side before creating a
 * Stripe Checkout Session, so this form can never be the sole gate on a
 * malicious or malformed submission.
 */
class DonationForm extends FormBase {

  const FLOOD_EVENT = 'simple_stripe_donation.donate';
  const FLOOD_LIMIT = 20;
  const FLOOD_WINDOW = 3600;
  const CUSTOM_AMOUNT_KEY = 'other';

  protected DonationServiceInterface $donationService;
  protected MoneyService $moneyService;
  protected FloodInterface $flood;
  protected AccountInterface $currentUser;
  protected StripeServiceInterface $stripeService;

  // $requestStack and $configFactory are already declared (untyped) on
  // FormBase; redeclaring them with a native type here is not allowed by
  // PHP's property variance rules, so this class only assigns to them.
  public function __construct(
    DonationServiceInterface $donationService,
    MoneyService $moneyService,
    FloodInterface $flood,
    RequestStack $requestStack,
    AccountInterface $currentUser,
    ConfigFactoryInterface $configFactory,
    StripeServiceInterface $stripeService
  ) {
    $this->donationService = $donationService;
    $this->moneyService = $moneyService;
    $this->flood = $flood;
    $this->requestStack = $requestStack;
    $this->currentUser = $currentUser;
    $this->configFactory = $configFactory;
    $this->stripeService = $stripeService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('simple_stripe_donation.donation_service'),
      $container->get('simple_stripe_donation.money_service'),
      $container->get('flood'),
      $container->get('request_stack'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('simple_stripe_donation.stripe_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'simple_stripe_donation_donation_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $options
   *   Optional overrides, used by DonationBlock: 'preset_amounts' (array
   *   of decimal strings) and 'default_amount' (decimal string).
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $options = []) {
    $config = $this->configFactory->get('simple_stripe_donation.settings');
    $currency = strtoupper($config->get('stripe.currency') ?: 'USD');
    $symbol = $this->moneyService->getCurrencySymbol($currency);

    $presetAmounts = $options['preset_amounts'] ?? ($config->get('donation.preset_amounts') ?: ['10', '25', '50', '100']);
    $allowCustom = (bool) $config->get('donation.allow_custom_amount');
    $defaultAmount = (string) ($options['default_amount'] ?? ($presetAmounts[0] ?? '25'));

    $form['#attached']['library'][] = 'simple_stripe_donation/donation_form';
    $form['#attributes']['class'][] = 'simple-stripe-donation-form';
    // Wraps the rendered form in the wrapper markup from
    // simple-stripe-donation-form.html.twig (see hook_theme()); without
    // this the form renders through the generic default form theme and
    // never gets the divs the CSS design tokens/card styling live on.
    $form['#theme'] = 'simple_stripe_donation_form';

    if ($this->stripeService->getMode() === 'test') {
      $form['test_mode_notice'] = [
        '#markup' => '<div class="simple-stripe-donation-test-mode-notice">' . $this->t('This is a TEST donation form. No real payment will be processed.') . '</div>',
        '#weight' => -100,
      ];
    }

    // ------------------------------------------------------------------
    // Card: Amount.
    // ------------------------------------------------------------------
    $form['amount_card'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['simple-stripe-donation-card', 'simple-stripe-donation-card--amount']],
    ];

    $radioOptions = [];
    foreach ($presetAmounts as $amount) {
      $radioOptions[(string) $amount] = $symbol . $amount;
    }
    if ($allowCustom) {
      $radioOptions[self::CUSTOM_AMOUNT_KEY] = $this->t('Custom');
    }

    $form['amount_card']['amount_preset'] = [
      '#type' => 'radios',
      '#title' => $this->t('Donation amount'),
      '#options' => $radioOptions,
      '#default_value' => $defaultAmount,
      '#required' => TRUE,
      '#attributes' => ['class' => ['simple-stripe-donation-amount-presets']],
    ];

    if ($allowCustom) {
      $form['amount_card']['custom_amount'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Custom amount (@symbol)', ['@symbol' => $symbol]),
        '#attributes' => [
          'class' => ['simple-stripe-donation-custom-amount'],
          'inputmode' => 'decimal',
          'placeholder' => '0.00',
        ],
        '#states' => [
          'visible' => [':input[name="amount_preset"]' => ['value' => self::CUSTOM_AMOUNT_KEY]],
        ],
      ];
    }

    // ------------------------------------------------------------------
    // Card: Dedication (email is always required, regardless of the
    // Yes/No toggle below it — Stripe/the receipt email need it either
    // way; only the optional identity fields are progressively disclosed).
    // ------------------------------------------------------------------
    $showName = (bool) $config->get('donation.allow_donor_name');
    $showPhone = (bool) $config->get('donation.allow_donor_phone');
    $showAddress = (bool) $config->get('donation.allow_donor_address');
    $showAnonymous = (bool) $config->get('donation.allow_anonymous');
    $showDedicationToggle = $showName || $showPhone || $showAddress || $showAnonymous;

    $form['dedication_card'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['simple-stripe-donation-card', 'simple-stripe-donation-card--dedication']],
    ];

    $form['dedication_card']['donor_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
      '#description' => $this->t('Your donation receipt will be sent to this address.'),
      '#attributes' => ['autocomplete' => 'email'],
    ];

    if ($showDedicationToggle) {
      $form['dedication_card']['dedication'] = [
        '#type' => 'radios',
        '#title' => $this->t('Dedication'),
        '#description' => $this->t('Add your name, contact details, or dedicate this donation.'),
        '#options' => [
          'no' => $this->t('No'),
          'yes' => $this->t('Yes'),
        ],
        '#default_value' => 'no',
        '#attributes' => ['class' => ['simple-stripe-donation-dedication-toggle']],
      ];

      $dedicationStates = [
        'visible' => [':input[name="dedication"]' => ['value' => 'yes']],
      ];

      if ($showName) {
        $form['dedication_card']['donor_name'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Full name'),
          '#maxlength' => 255,
          '#attributes' => ['autocomplete' => 'name'],
          '#states' => $dedicationStates,
        ];
      }

      if ($showPhone) {
        $form['dedication_card']['donor_phone'] = [
          '#type' => 'tel',
          '#title' => $this->t('Phone'),
          '#maxlength' => 64,
          '#attributes' => ['autocomplete' => 'tel'],
          '#states' => $dedicationStates,
        ];
      }

      if ($showAddress) {
        $form['dedication_card']['donor_address'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Address'),
          '#maxlength' => 500,
          '#attributes' => ['autocomplete' => 'street-address'],
          '#states' => $dedicationStates,
        ];
      }

      if ($showAnonymous) {
        $form['dedication_card']['anonymous'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Please make my donation anonymous'),
          '#states' => $dedicationStates,
        ];
      }
    }

    // ------------------------------------------------------------------
    // Card: Message.
    // ------------------------------------------------------------------
    if ($config->get('donation.allow_donor_message')) {
      $form['message_card'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['simple-stripe-donation-card', 'simple-stripe-donation-card--message']],
      ];
      $form['message_card']['message'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Message (optional)'),
        '#maxlength' => 1000,
        '#rows' => 3,
      ];
    }

    // Standalone submit button, outside any card.
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Donate'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $preset = $form_state->getValue('amount_preset');
    $amount = $preset;

    if ($preset === self::CUSTOM_AMOUNT_KEY) {
      // Strip the thousand-separator commas the client-side formatter adds
      // as the donor types (see js/donation.js) before validating.
      $amount = str_replace(',', '', trim((string) $form_state->getValue('custom_amount')));
      if ($amount === '' || !preg_match('/^\d+(\.\d{1,3})?$/', $amount)) {
        $form_state->setErrorByName('custom_amount', $this->t('Please enter a valid custom amount.'));
        return;
      }
    }

    $form_state->set('resolved_amount', $amount);

    $request = $this->requestStack->getCurrentRequest();
    $clientIp = $request ? ($request->getClientIp() ?? 'unknown') : 'unknown';
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW, $clientIp)) {
      $form_state->setErrorByName('', $this->t('Too many donation attempts from this location. Please try again later.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $request = $this->requestStack->getCurrentRequest();
    $clientIp = $request ? ($request->getClientIp() ?? 'unknown') : 'unknown';
    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $clientIp);

    // The dedication fields are only meaningful when the donor opted in;
    // if they left it on "No" their (untouched, empty) values are simply
    // not persisted — DonationService already treats empty strings as
    // "not provided" for each of these.
    $dedicationOptedIn = $form_state->getValue('dedication') === 'yes';

    $input = [
      'amount' => $form_state->get('resolved_amount'),
      'donor_email' => $form_state->getValue('donor_email'),
      'donor_name' => $dedicationOptedIn ? $form_state->getValue('donor_name') : '',
      'donor_phone' => $dedicationOptedIn ? $form_state->getValue('donor_phone') : '',
      'donor_address' => $dedicationOptedIn ? $form_state->getValue('donor_address') : '',
      'anonymous' => $dedicationOptedIn && (bool) $form_state->getValue('anonymous'),
      'message' => $form_state->getValue('message'),
      'uid' => $this->currentUser->isAuthenticated() ? (int) $this->currentUser->id() : NULL,
    ];

    $result = $this->donationService->createDonation($input);

    if (empty($result['success'])) {
      $this->messenger()->addError($result['error'] ?? $this->t('Unable to process your donation right now. Please try again shortly.'));
      return;
    }

    $form_state->setResponse(new TrustedRedirectResponse($result['checkout_url']));
  }

}
