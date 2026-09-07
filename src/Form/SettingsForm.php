<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Form;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\simple_stripe_donation\Service\StripeServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Stripe, donation and email settings for Simple Stripe Donation.
 */
class SettingsForm extends ConfigFormBase {

  const CONFIG_NAME = 'simple_stripe_donation.settings';

  protected StripeServiceInterface $stripeService;
  protected EmailValidatorInterface $emailValidator;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    StripeServiceInterface $stripeService,
    EmailValidatorInterface $emailValidator
  ) {
    parent::__construct($config_factory, $typedConfigManager);
    $this->stripeService = $stripeService;
    $this->emailValidator = $emailValidator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('simple_stripe_donation.stripe_service'),
      $container->get('email.validator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'simple_stripe_donation_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(self::CONFIG_NAME);

    $form['env_notice'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      'message' => [
        '#markup' => $this->t(
          'Secret keys entered below are stored in Drupal configuration. For production, it is strongly recommended to set the STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET and STRIPE_PUBLISHABLE_KEY environment variables instead — when set, they always take precedence over the values stored here, for whichever mode is active below.'
        ),
      ],
    ];

    $form['stripe'] = [
      '#type' => 'details',
      '#title' => $this->t('Stripe'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['stripe']['mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Stripe mode'),
      '#options' => [
        'test' => $this->t('Test'),
        'live' => $this->t('Live'),
      ],
      '#default_value' => $config->get('stripe.mode') ?: 'test',
      '#required' => TRUE,
      '#description' => $this->t('Only the keys for the selected mode are used. Test and live credentials are never mixed.'),
    ];

    $form['stripe']['currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency'),
      '#default_value' => $config->get('stripe.currency') ?: 'usd',
      '#size' => 6,
      '#maxlength' => 3,
      '#required' => TRUE,
      '#description' => $this->t('ISO 4217 currency code, e.g. usd, eur, jpy.'),
    ];

    $form['stripe']['test'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Test mode credentials'),
    ];
    $form['stripe']['test']['test_publishable_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Test publishable key'),
      '#default_value' => $config->get('stripe.test_publishable_key') ?: '',
      '#description' => $this->t('Can be overridden by the STRIPE_PUBLISHABLE_KEY environment variable.'),
    ];
    $form['stripe']['test']['test_secret_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Test secret key'),
      '#description' => $this->t('Leave blank to keep the currently stored value. Can be overridden by the STRIPE_SECRET_KEY environment variable.'),
    ];
    $form['stripe']['test']['test_webhook_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Test webhook signing secret'),
      '#description' => $this->t('Leave blank to keep the currently stored value. Can be overridden by the STRIPE_WEBHOOK_SECRET environment variable.'),
    ];
    $form['stripe']['test']['test_connection_test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test connection (test mode)'),
      '#submit' => ['::testConnectionTest'],
      '#limit_validation_errors' => [],
    ];

    $form['stripe']['live'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Live mode credentials'),
    ];
    $form['stripe']['live']['live_publishable_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Live publishable key'),
      '#default_value' => $config->get('stripe.live_publishable_key') ?: '',
      '#description' => $this->t('Can be overridden by the STRIPE_PUBLISHABLE_KEY environment variable.'),
    ];
    $form['stripe']['live']['live_secret_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Live secret key'),
      '#description' => $this->t('Leave blank to keep the currently stored value. Can be overridden by the STRIPE_SECRET_KEY environment variable.'),
    ];
    $form['stripe']['live']['live_webhook_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Live webhook signing secret'),
      '#description' => $this->t('Leave blank to keep the currently stored value. Can be overridden by the STRIPE_WEBHOOK_SECRET environment variable.'),
    ];
    $form['stripe']['live']['test_connection_live'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test connection (live mode)'),
      '#submit' => ['::testConnectionLive'],
      '#limit_validation_errors' => [],
    ];

    $form['donation'] = [
      '#type' => 'details',
      '#title' => $this->t('Donation'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $presetAmounts = $config->get('donation.preset_amounts') ?? ['10', '25', '50', '100'];
    $form['donation']['preset_amounts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Preset amounts'),
      '#default_value' => implode("\n", $presetAmounts),
      '#rows' => 4,
      '#description' => $this->t('One amount per line, in major currency units (e.g. 25 or 25.50).'),
      '#required' => TRUE,
    ];

    $form['donation']['minimum_amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Minimum donation amount'),
      '#default_value' => $config->get('donation.minimum_amount') ?: '1',
      '#required' => TRUE,
    ];

    $form['donation']['maximum_amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Maximum donation amount'),
      '#default_value' => $config->get('donation.maximum_amount') ?: '10000',
      '#required' => TRUE,
    ];

    $form['donation']['allow_custom_amount'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow custom amount'),
      '#default_value' => (bool) $config->get('donation.allow_custom_amount'),
    ];

    $form['donation']['allow_donor_name'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow donor name'),
      '#default_value' => (bool) $config->get('donation.allow_donor_name'),
    ];

    $form['donation']['allow_donor_phone'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow donor phone'),
      '#default_value' => (bool) $config->get('donation.allow_donor_phone'),
    ];

    $form['donation']['allow_donor_address'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow donor address'),
      '#default_value' => (bool) $config->get('donation.allow_donor_address'),
    ];

    $form['donation']['allow_donor_message'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow donor message'),
      '#default_value' => (bool) $config->get('donation.allow_donor_message'),
    ];

    $form['donation']['allow_anonymous'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow anonymous donation'),
      '#default_value' => (bool) $config->get('donation.allow_anonymous'),
    ];

    $form['donation']['reference_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Reference prefix'),
      '#default_value' => $config->get('donation.reference_prefix') ?: 'DON',
      '#size' => 10,
      '#maxlength' => 10,
      '#required' => TRUE,
      '#description' => $this->t('Prefix used for donation references shown to donors and admins, e.g. @prefix-000123.', ['@prefix' => 'DON']),
    ];

    $form['donation']['reference_digits'] = [
      '#type' => 'number',
      '#title' => $this->t('Reference number padding'),
      '#default_value' => (int) ($config->get('donation.reference_digits') ?: 6),
      '#min' => 1,
      '#max' => 10,
      '#required' => TRUE,
      '#description' => $this->t('Number of digits the donation ID is zero-padded to in the reference, e.g. 6 digits gives DON-000123.'),
    ];

    $form['email'] = [
      '#type' => 'details',
      '#title' => $this->t('Email'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['email']['send_donor_receipt'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send donor receipt'),
      '#default_value' => (bool) $config->get('email.send_donor_receipt'),
    ];

    $form['email']['send_admin_notification'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send admin notification'),
      '#default_value' => (bool) $config->get('email.send_admin_notification'),
    ];

    $adminNotificationEmail = $config->get('email.admin_notification_email');
    if ($adminNotificationEmail === NULL || $adminNotificationEmail === '') {
      // Defaults to the site email so admin notifications work out of the
      // box; the admin can still override it below.
      $adminNotificationEmail = $this->config('system.site')->get('mail') ?: '';
    }
    $form['email']['admin_notification_email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Admin notification email'),
      '#default_value' => $adminNotificationEmail,
      '#description' => $this->t('Defaults to the site email address. Separate multiple addresses with a comma.'),
    ];

    $form['email']['donor_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Donor receipt subject'),
      '#default_value' => $config->get('email.donor_subject') ?: '',
      '#description' => $this->t('Placeholders: [donation:reference], [donation:amount], [donation:currency], [donation:date]'),
    ];

    $form['email']['admin_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Admin notification subject'),
      '#default_value' => $config->get('email.admin_subject') ?: '',
      '#description' => $this->t('Placeholders: [donation:reference], [donation:amount], [donation:currency], [donation:date]'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $currency = strtolower((string) $form_state->getValue(['stripe', 'currency']));
    if (!preg_match('/^[a-z]{3}$/', $currency)) {
      $form_state->setError($form['stripe']['currency'], $this->t('Currency must be a 3-letter ISO 4217 code.'));
    }

    $min = trim((string) $form_state->getValue(['donation', 'minimum_amount']));
    $max = trim((string) $form_state->getValue(['donation', 'maximum_amount']));
    $numericPattern = '/^\d+(\.\d{1,3})?$/';

    if (!preg_match($numericPattern, $min)) {
      $form_state->setError($form['donation']['minimum_amount'], $this->t('Minimum amount must be a non-negative number.'));
    }
    if (!preg_match($numericPattern, $max)) {
      $form_state->setError($form['donation']['maximum_amount'], $this->t('Maximum amount must be a non-negative number.'));
    }
    if (preg_match($numericPattern, $min) && preg_match($numericPattern, $max) && (float) $min > (float) $max) {
      $form_state->setError($form['donation']['maximum_amount'], $this->t('Maximum amount must be greater than or equal to the minimum amount.'));
    }

    $presetLines = array_filter(array_map('trim', explode("\n", (string) $form_state->getValue(['donation', 'preset_amounts']))));
    if (empty($presetLines)) {
      $form_state->setError($form['donation']['preset_amounts'], $this->t('Provide at least one preset amount.'));
    }
    foreach ($presetLines as $line) {
      if (!preg_match($numericPattern, $line)) {
        $form_state->setError($form['donation']['preset_amounts'], $this->t('Each preset amount must be a non-negative number, one per line.'));
        break;
      }
    }

    $referencePrefix = trim((string) $form_state->getValue(['donation', 'reference_prefix']));
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $referencePrefix)) {
      $form_state->setError($form['donation']['reference_prefix'], $this->t('Reference prefix may only contain letters, numbers, hyphens and underscores.'));
    }

    $adminEmails = array_filter(array_map('trim', explode(',', (string) $form_state->getValue(['email', 'admin_notification_email']))));
    if ($form_state->getValue(['email', 'send_admin_notification']) && empty($adminEmails)) {
      $form_state->setError($form['email']['admin_notification_email'], $this->t('An admin notification email is required when admin notifications are enabled.'));
    }
    foreach ($adminEmails as $adminEmail) {
      if (!$this->emailValidator->isValid($adminEmail)) {
        $form_state->setError($form['email']['admin_notification_email'], $this->t('%email is not a valid email address.', ['%email' => $adminEmail]));
        break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config(self::CONFIG_NAME);

    $config->set('stripe.mode', $form_state->getValue(['stripe', 'mode']));
    $config->set('stripe.currency', strtolower((string) $form_state->getValue(['stripe', 'currency'])));
    $config->set('stripe.test_publishable_key', $form_state->getValue(['stripe', 'test', 'test_publishable_key']));
    $config->set('stripe.live_publishable_key', $form_state->getValue(['stripe', 'live', 'live_publishable_key']));

    if ($form_state->getValue(['stripe', 'test', 'test_secret_key'])) {
      $config->set('stripe.test_secret_key', $form_state->getValue(['stripe', 'test', 'test_secret_key']));
    }
    if ($form_state->getValue(['stripe', 'test', 'test_webhook_secret'])) {
      $config->set('stripe.test_webhook_secret', $form_state->getValue(['stripe', 'test', 'test_webhook_secret']));
    }
    if ($form_state->getValue(['stripe', 'live', 'live_secret_key'])) {
      $config->set('stripe.live_secret_key', $form_state->getValue(['stripe', 'live', 'live_secret_key']));
    }
    if ($form_state->getValue(['stripe', 'live', 'live_webhook_secret'])) {
      $config->set('stripe.live_webhook_secret', $form_state->getValue(['stripe', 'live', 'live_webhook_secret']));
    }

    $presetAmounts = array_values(array_filter(array_map('trim', explode("\n", (string) $form_state->getValue(['donation', 'preset_amounts'])))));
    $config->set('donation.preset_amounts', $presetAmounts);
    $config->set('donation.minimum_amount', trim((string) $form_state->getValue(['donation', 'minimum_amount'])));
    $config->set('donation.maximum_amount', trim((string) $form_state->getValue(['donation', 'maximum_amount'])));
    $config->set('donation.allow_custom_amount', (bool) $form_state->getValue(['donation', 'allow_custom_amount']));
    $config->set('donation.allow_donor_name', (bool) $form_state->getValue(['donation', 'allow_donor_name']));
    $config->set('donation.allow_donor_phone', (bool) $form_state->getValue(['donation', 'allow_donor_phone']));
    $config->set('donation.allow_donor_address', (bool) $form_state->getValue(['donation', 'allow_donor_address']));
    $config->set('donation.allow_donor_message', (bool) $form_state->getValue(['donation', 'allow_donor_message']));
    $config->set('donation.allow_anonymous', (bool) $form_state->getValue(['donation', 'allow_anonymous']));
    $config->set('donation.reference_prefix', trim((string) $form_state->getValue(['donation', 'reference_prefix'])));
    $config->set('donation.reference_digits', (int) $form_state->getValue(['donation', 'reference_digits']));

    $config->set('email.send_donor_receipt', (bool) $form_state->getValue(['email', 'send_donor_receipt']));
    $config->set('email.send_admin_notification', (bool) $form_state->getValue(['email', 'send_admin_notification']));
    $adminEmails = array_filter(array_map('trim', explode(',', (string) $form_state->getValue(['email', 'admin_notification_email']))));
    $config->set('email.admin_notification_email', implode(',', $adminEmails));
    $config->set('email.donor_subject', $form_state->getValue(['email', 'donor_subject']));
    $config->set('email.admin_subject', $form_state->getValue(['email', 'admin_subject']));

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Submit handler for the "Test connection (test mode)" button.
   */
  public function testConnectionTest(array &$form, FormStateInterface $form_state): void {
    $key = trim((string) $form_state->getValue(['stripe', 'test', 'test_secret_key']));
    $key = $key !== '' ? $key : (string) $this->config(self::CONFIG_NAME)->get('stripe.test_secret_key');
    $this->runConnectionTest($key);
  }

  /**
   * Submit handler for the "Test connection (live mode)" button.
   */
  public function testConnectionLive(array &$form, FormStateInterface $form_state): void {
    $key = trim((string) $form_state->getValue(['stripe', 'live', 'live_secret_key']));
    $key = $key !== '' ? $key : (string) $this->config(self::CONFIG_NAME)->get('stripe.live_secret_key');
    $this->runConnectionTest($key);
  }

  /**
   * Runs a connection test and reports the outcome without ever exposing
   * the secret key value itself.
   */
  protected function runConnectionTest(string $secretKey): void {
    if ($secretKey === '') {
      $this->messenger()->addError($this->t('No secret key is available to test (nothing entered and nothing stored).'));
      return;
    }

    if ($this->stripeService->testConnection($secretKey)) {
      $this->messenger()->addStatus($this->t('Successfully connected to Stripe with this secret key.'));
    }
    else {
      $this->messenger()->addError($this->t('Could not connect to Stripe with this secret key. Check the Drupal log for details.'));
    }
  }

}
