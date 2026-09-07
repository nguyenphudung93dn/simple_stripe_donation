<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Repository;

/**
 * Database access layer for the {simple_stripe_donation} table.
 *
 * Donations are returned as plain \stdClass row objects (this is a custom
 * table, not a Drupal content entity type), matching Drupal's Database API
 * conventions. All queries use Drupal's database abstraction layer so the
 * module works on both MySQL/MariaDB and PostgreSQL.
 */
interface DonationRepositoryInterface {

  /**
   * Inserts a new donation row.
   *
   * @param array $fields
   *   Column => value pairs. Must include uuid, amount, currency, status
   *   and created at minimum.
   *
   * @return int
   *   The new donation's internal ID.
   */
  public function insert(array $fields): int;

  /**
   * Loads a donation by its public UUID.
   */
  public function load(string $uuid): ?object;

  /**
   * Loads a donation by its internal ID.
   */
  public function loadById(int $id): ?object;

  /**
   * Loads a donation by its Stripe Checkout Session ID.
   */
  public function loadByCheckoutSessionId(string $sessionId): ?object;

  /**
   * Loads a donation by its Stripe PaymentIntent ID.
   */
  public function loadByPaymentIntentId(string $paymentIntentId): ?object;

  /**
   * Updates a donation by internal ID, optionally guarded by extra
   * equality conditions so the write only applies from an expected state
   * (e.g. only transition to 'succeeded' while status is still 'pending').
   * This makes concurrent state transitions (two webhook events racing
   * for the same donation) safe without needing an application-level lock.
   *
   * @param int $id
   *   The donation's internal ID.
   * @param array $fields
   *   Column => value pairs to update.
   * @param array $conditions
   *   Additional column => value equality conditions the row must already
   *   satisfy for the update to apply.
   *
   * @return bool
   *   TRUE if a row was updated.
   */
  public function update(int $id, array $fields, array $conditions = []): bool;

  /**
   * Deletes a donation by internal ID.
   */
  public function delete(int $id): bool;

  /**
   * Finds donations matching optional filters, newest first.
   *
   * @param array $filters
   *   Supported keys: status, donor_email, created_from, created_to,
   *   amount_min, amount_max.
   * @param int $limit
   *   Maximum rows to return.
   * @param int $offset
   *   Row offset, for pagination.
   *
   * @return object[]
   *   Matching donation rows.
   */
  public function findDonations(array $filters = [], int $limit = 50, int $offset = 0): array;

  /**
   * Counts donations matching optional filters (same filter keys as
   * findDonations()), for pager construction.
   */
  public function countDonations(array $filters = []): int;

  /**
   * Computes report summary statistics for donations matching filters.
   *
   * @return array
   *   Keys: total_count, succeeded_count, succeeded_total, succeeded_average.
   *   Amount keys are integers in the smallest currency unit.
   */
  public function getSummary(array $filters = []): array;

}
