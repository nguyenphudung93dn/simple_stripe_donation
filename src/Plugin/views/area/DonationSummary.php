<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Plugin\views\area;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\simple_stripe_donation\Repository\DonationRepositoryInterface;
use Drupal\simple_stripe_donation\Service\DonationServiceInterface;
use Drupal\simple_stripe_donation\Service\MoneyService;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Summary statistic tiles (total/succeeded count, total/average amount)
 * shown above the donation report table.
 *
 * Reads filters directly from the request query string rather than from
 * the view's applied filters, so the numbers always match whatever the
 * exposed filter form above the table is currently showing — the same
 * filter identifiers (status, donor_email) the shipped view's exposed
 * filters use.
 *
 * Implements CacheableDependencyInterface (not just cache_context on the
 * view display) because DisplayPluginBase::calculateCacheMetadata() only
 * merges an area handler's cache metadata when the handler itself is
 * CacheableDependencyInterface — without this, a cached page render would
 * show the same tiles regardless of the query string.
 */
#[ViewsArea('simple_stripe_donation_summary')]
class DonationSummary extends AreaPluginBase implements ContainerFactoryPluginInterface, CacheableDependencyInterface {

  protected DonationRepositoryInterface $repository;
  protected MoneyService $moneyService;
  protected ConfigFactoryInterface $configFactory;
  protected RequestStack $requestStack;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    DonationRepositoryInterface $repository,
    MoneyService $moneyService,
    ConfigFactoryInterface $configFactory,
    RequestStack $requestStack
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->repository = $repository;
    $this->moneyService = $moneyService;
    $this->configFactory = $configFactory;
    $this->requestStack = $requestStack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('simple_stripe_donation.donation_repository'),
      $container->get('simple_stripe_donation.money_service'),
      $container->get('config.factory'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE) {
    $summary = $this->repository->getSummary($this->extractFiltersFromRequest());
    $currency = strtoupper((string) ($this->configFactory->get('simple_stripe_donation.settings')->get('stripe.currency') ?: 'USD'));

    $items = [
      'total' => [$this->t('Total donations'), (string) $summary['total_count']],
      'succeeded' => [$this->t('Successful donations'), (string) $summary['succeeded_count']],
      'amount' => [
        $this->t('Total amount'),
        $this->moneyService->formatMajorUnits((int) $summary['succeeded_total'], $currency) . ' ' . $currency,
      ],
      'average' => [
        $this->t('Average donation'),
        $this->moneyService->formatMajorUnits((int) $summary['succeeded_average'], $currency) . ' ' . $currency,
      ],
    ];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['simple-stripe-donation-report-summary']],
      '#attached' => ['library' => ['simple_stripe_donation/donation_form']],
    ];

    foreach ($items as $key => [$label, $value]) {
      $build[$key] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => [
            'simple-stripe-donation-report-summary__item',
            'simple-stripe-donation-report-summary__item--' . $key,
          ],
        ],
        'label' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['simple-stripe-donation-report-summary__label']],
          '#value' => $label,
        ],
        'value' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => ['class' => ['simple-stripe-donation-report-summary__value']],
          '#value' => $value,
        ],
      ];
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return ['url.query_args'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

  /**
   * Extracts and sanitizes report filters from the request query string.
   *
   * @see \Drupal\simple_stripe_donation\Repository\DonationRepositoryInterface::getSummary()
   */
  protected function extractFiltersFromRequest(): array {
    $filters = [];
    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return $filters;
    }

    $validStatuses = [
      DonationServiceInterface::STATUS_PENDING,
      DonationServiceInterface::STATUS_SUCCEEDED,
      DonationServiceInterface::STATUS_FAILED,
      DonationServiceInterface::STATUS_EXPIRED,
      DonationServiceInterface::STATUS_REFUNDED,
    ];
    $status = (string) $request->query->get('status', '');
    if (in_array($status, $validStatuses, TRUE)) {
      $filters['status'] = $status;
    }

    $email = trim((string) $request->query->get('donor_email', ''));
    if ($email !== '') {
      $filters['donor_email'] = $email;
    }

    return $filters;
  }

}
