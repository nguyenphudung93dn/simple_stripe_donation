<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Plugin\views\filter;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\simple_stripe_donation\Service\MoneyService;
use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\filter\NumericFilter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters {simple_stripe_donation}.amount (stored in minor currency units)
 * by a value entered in major currency units (e.g. "25.50"), so the
 * exposed filter matches what donors and admins actually type.
 */
#[ViewsFilter('simple_stripe_donation_amount')]
class DonationAmountFilter extends NumericFilter implements ContainerFactoryPluginInterface {

  protected MoneyService $moneyService;
  protected ConfigFactoryInterface $configFactory;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, MoneyService $moneyService, ConfigFactoryInterface $configFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->moneyService = $moneyService;
    $this->configFactory = $configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('simple_stripe_donation.money_service'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $currency = strtoupper((string) ($this->configFactory->get('simple_stripe_donation.settings')->get('stripe.currency') ?: 'USD'));

    if (is_array($this->value)) {
      foreach (['min', 'max', 'value'] as $key) {
        if (isset($this->value[$key]) && $this->value[$key] !== '') {
          $this->value[$key] = $this->toMinorUnits((string) $this->value[$key], $currency);
        }
      }
    }

    parent::query();
  }

  /**
   * Converts a major-unit amount string to its minor-unit integer, treating
   * an unparsable value as "no filter" rather than failing the request.
   */
  protected function toMinorUnits(string $amount, string $currency): string {
    try {
      return (string) $this->moneyService->toMinorUnits($amount, $currency);
    }
    catch (\InvalidArgumentException $e) {
      return '';
    }
  }

}
