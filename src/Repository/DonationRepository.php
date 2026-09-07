<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Repository;

use Drupal\Core\Database\Connection;

/**
 * Default DonationRepository implementation using Drupal's Database API.
 *
 * Every query goes through the query builder (never raw, driver-specific
 * SQL strings) so the module works unmodified on MySQL/MariaDB and
 * PostgreSQL.
 */
class DonationRepository implements DonationRepositoryInterface {

  const TABLE = 'simple_stripe_donation';

  protected Connection $connection;

  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public function insert(array $fields): int {
    return (int) $this->connection->insert(self::TABLE)
      ->fields($fields)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function load(string $uuid): ?object {
    $result = $this->connection->select(self::TABLE, 'd')
      ->fields('d')
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchObject();

    return $result ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadById(int $id): ?object {
    $result = $this->connection->select(self::TABLE, 'd')
      ->fields('d')
      ->condition('id', $id)
      ->execute()
      ->fetchObject();

    return $result ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadByCheckoutSessionId(string $sessionId): ?object {
    $result = $this->connection->select(self::TABLE, 'd')
      ->fields('d')
      ->condition('stripe_checkout_session_id', $sessionId)
      ->execute()
      ->fetchObject();

    return $result ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadByPaymentIntentId(string $paymentIntentId): ?object {
    $result = $this->connection->select(self::TABLE, 'd')
      ->fields('d')
      ->condition('stripe_payment_intent_id', $paymentIntentId)
      ->execute()
      ->fetchObject();

    return $result ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function update(int $id, array $fields, array $conditions = []): bool {
    $query = $this->connection->update(self::TABLE)
      ->fields($fields)
      ->condition('id', $id);

    foreach ($conditions as $column => $value) {
      $query->condition($column, $value);
    }

    return $query->execute() > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(int $id): bool {
    return $this->connection->delete(self::TABLE)
      ->condition('id', $id)
      ->execute() > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function findDonations(array $filters = [], int $limit = 50, int $offset = 0): array {
    $query = $this->connection->select(self::TABLE, 'd')->fields('d');
    $this->applyFilters($query, $filters);
    $query->orderBy('d.created', 'DESC');
    $query->range($offset, $limit);

    return $query->execute()->fetchAll();
  }

  /**
   * {@inheritdoc}
   */
  public function countDonations(array $filters = []): int {
    $query = $this->connection->select(self::TABLE, 'd');
    $this->applyFilters($query, $filters);

    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary(array $filters = []): array {
    $query = $this->connection->select(self::TABLE, 'd');
    $this->applyFilters($query, $filters);
    $total_count = (int) $query->countQuery()->execute()->fetchField();

    $succeeded_filters = $filters;
    $succeeded_filters['status'] = 'succeeded';
    $succeeded_query = $this->connection->select(self::TABLE, 'd');
    $succeeded_query->addExpression('COUNT(d.id)', 'succeeded_count');
    $succeeded_query->addExpression('COALESCE(SUM(d.amount), 0)', 'succeeded_total');
    $this->applyFilters($succeeded_query, $succeeded_filters);
    $row = $succeeded_query->execute()->fetchAssoc();

    $succeeded_count = (int) ($row['succeeded_count'] ?? 0);
    $succeeded_total = (int) ($row['succeeded_total'] ?? 0);
    $succeeded_average = $succeeded_count > 0 ? (int) round($succeeded_total / $succeeded_count) : 0;

    return [
      'total_count' => $total_count,
      'succeeded_count' => $succeeded_count,
      'succeeded_total' => $succeeded_total,
      'succeeded_average' => $succeeded_average,
    ];
  }

  /**
   * Applies the shared set of report/lookup filters to a select query.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query to constrain. Passed by reference implicitly (objects).
   * @param array $filters
   *   See findDonations() for supported keys.
   */
  protected function applyFilters($query, array $filters): void {
    if (!empty($filters['status'])) {
      $query->condition('d.status', $filters['status']);
    }
    if (!empty($filters['donor_email'])) {
      $query->condition('d.donor_email', '%' . $this->connection->escapeLike($filters['donor_email']) . '%', 'LIKE');
    }
    if (!empty($filters['created_from'])) {
      $query->condition('d.created', $filters['created_from'], '>=');
    }
    if (!empty($filters['created_to'])) {
      $query->condition('d.created', $filters['created_to'], '<=');
    }
    if (isset($filters['amount_min']) && $filters['amount_min'] !== '') {
      $query->condition('d.amount', (int) $filters['amount_min'], '>=');
    }
    if (isset($filters['amount_max']) && $filters['amount_max'] !== '') {
      $query->condition('d.amount', (int) $filters['amount_max'], '<=');
    }
  }

}
