<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a "Simple Stripe Donation" block, placeable anywhere via
 * /admin/structure/block.
 *
 * Renders the exact same DonationForm and DonationService used by the
 * /donate page; no donation logic is duplicated here, only block-specific
 * display configuration (description text, preset amount overrides).
 */
#[Block(
  id: 'simple_stripe_donation_block',
  admin_label: new TranslatableMarkup('Simple Stripe Donation'),
  category: new TranslatableMarkup('Simple Stripe Donation'),
)]
class DonationBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected FormBuilderInterface $formBuilder;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, FormBuilderInterface $formBuilder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->formBuilder = $formBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'description' => '',
      'preset_amounts' => '',
      'default_amount' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $config = $this->configuration;

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#description' => $this->t('Optional text shown above the donation form.'),
      '#default_value' => $config['description'],
      '#rows' => 3,
    ];

    $form['preset_amounts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Preset amounts (override)'),
      '#description' => $this->t('One amount per line. Leave blank to use the site-wide preset amounts from Simple Stripe Donation settings.'),
      '#default_value' => $config['preset_amounts'],
      '#rows' => 3,
    ];

    $form['default_amount'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default amount'),
      '#description' => $this->t('Leave blank to default to the first preset amount.'),
      '#default_value' => $config['default_amount'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['description'] = $form_state->getValue('description');
    $this->configuration['preset_amounts'] = $form_state->getValue('preset_amounts');
    $this->configuration['default_amount'] = trim((string) $form_state->getValue('default_amount'));
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $options = [];

    $presetLines = array_values(array_filter(array_map('trim', explode("\n", (string) $this->configuration['preset_amounts']))));
    if (!empty($presetLines)) {
      $options['preset_amounts'] = $presetLines;
    }
    if ($this->configuration['default_amount'] !== '') {
      $options['default_amount'] = $this->configuration['default_amount'];
    }

    // Built via buildForm() + a manually seeded FormState (mirroring what
    // FormBuilder::getForm() does internally) rather than getForm() with
    // extra arguments: FormBuilderInterface::getForm()'s variadic
    // signature is still commented out pending a Drupal 11 deprecation
    // cycle (see FormBuilderInterface::getForm() docblock), so calling it
    // with extra args works at runtime but is not yet reflected in the
    // interface's declared signature.
    $formState = new FormState();
    $formState->addBuildInfo('args', [$options]);
    $form = $this->formBuilder->buildForm('Drupal\simple_stripe_donation\Form\DonationForm', $formState);

    if (!empty($this->configuration['description'])) {
      $form['#description'] = $this->configuration['description'];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    // The form contains a CSRF token unique per session/request; never
    // cache it.
    return 0;
  }

}
