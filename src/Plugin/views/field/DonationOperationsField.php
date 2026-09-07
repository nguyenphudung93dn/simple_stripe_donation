<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Plugin\views\field;

use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\simple_stripe_donation\Service\DonationServiceInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a "Refund" operation link for donations that are succeeded and
 * the current user is permitted to manage.
 */
#[ViewsField('simple_stripe_donation_operations')]
class DonationOperationsField extends FieldPluginBase implements ContainerFactoryPluginInterface {

  protected AccountInterface $currentUser;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccountInterface $currentUser) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $this->ensureMyTable();
    $this->addAdditionalFields(['id', 'status']);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $status = $this->getValue($values, 'status');

    if ($status !== DonationServiceInterface::STATUS_SUCCEEDED || !$this->currentUser->hasPermission('manage simple stripe donations')) {
      return '';
    }

    $donationId = (int) $this->getValue($values, 'id');

    return Link::fromTextAndUrl($this->t('Refund'), Url::fromRoute('simple_stripe_donation.refund', [
      'donation_id' => $donationId,
    ]))->toString();
  }

}
