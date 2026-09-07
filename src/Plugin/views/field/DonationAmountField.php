<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Plugin\views\field;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\simple_stripe_donation\Service\MoneyService;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders {simple_stripe_donation}.amount (stored in minor currency units)
 * as a major-unit string with its currency code, e.g. "25.50 USD".
 */
#[ViewsField('simple_stripe_donation_amount')]
class DonationAmountField extends FieldPluginBase implements ContainerFactoryPluginInterface {

  protected MoneyService $moneyService;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, MoneyService $moneyService) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->moneyService = $moneyService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('simple_stripe_donation.money_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    parent::query();
    $this->addAdditionalFields(['currency']);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $amount = (int) $this->getValue($values);
    $currency = strtoupper((string) $this->getValue($values, 'currency'));

    return $this->moneyService->formatMajorUnits($amount, $currency) . ' ' . $currency;
  }

}
