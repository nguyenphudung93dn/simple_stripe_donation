<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_stripe_donation\Kernel;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\simple_stripe_donation\Repository\DonationRepository;

/**
 * @coversDefaultClass \Drupal\simple_stripe_donation\Repository\DonationRepository
 * @group simple_stripe_donation
 */
class DonationRepositoryTest extends KernelTestBase {

  protected static $modules = ['simple_stripe_donation'];

  protected DonationRepository $repository;

  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('simple_stripe_donation', [
      'simple_stripe_donation',
      'simple_stripe_donation_event',
    ]);
    $this->repository = new DonationRepository(\Drupal::database());
  }

  /**
   * @covers ::insert
   * @covers ::load
   * @covers ::loadById
   */
  public function testTablesExistAndInsertLoad(): void {
    $schema = \Drupal::database()->schema();
    $this->assertTrue($schema->tableExists('simple_stripe_donation'));
    $this->assertTrue($schema->tableExists('simple_stripe_donation_event'));

    $id = $this->repository->insert([
      'uuid' => 'uuid-1',
      'amount' => 2500,
      'currency' => 'usd',
      'status' => 'pending',
      'donor_email' => 'donor@example.com',
      'created' => 1700000000,
    ]);

    $this->assertGreaterThan(0, $id);

    $byId = $this->repository->loadById($id);
    $this->assertNotNull($byId);
    $this->assertSame('donor@example.com', $byId->donor_email);

    $byUuid = $this->repository->load('uuid-1');
    $this->assertNotNull($byUuid);
    $this->assertSame($id, (int) $byUuid->id);

    $this->assertNull($this->repository->load('does-not-exist'));
  }

  /**
   * @covers ::loadByCheckoutSessionId
   * @covers ::loadByPaymentIntentId
   * @covers ::update
   */
  public function testLoadByStripeIdentifiers(): void {
    $id = $this->repository->insert([
      'uuid' => 'uuid-2',
      'amount' => 1000,
      'currency' => 'usd',
      'status' => 'pending',
      'created' => 1700000000,
      'stripe_checkout_session_id' => 'cs_test_1',
    ]);

    $donation = $this->repository->loadByCheckoutSessionId('cs_test_1');
    $this->assertNotNull($donation);
    $this->assertSame($id, (int) $donation->id);

    $this->repository->update($id, ['stripe_payment_intent_id' => 'pi_test_1']);
    $byPi = $this->repository->loadByPaymentIntentId('pi_test_1');
    $this->assertNotNull($byPi);
    $this->assertSame($id, (int) $byPi->id);
  }

  /**
   * @covers ::update
   */
  public function testGuardedUpdateOnlyAppliesFromExpectedState(): void {
    $id = $this->repository->insert([
      'uuid' => 'uuid-3',
      'amount' => 500,
      'currency' => 'usd',
      'status' => 'pending',
      'created' => 1700000000,
    ]);

    $updated = $this->repository->update($id, ['status' => 'succeeded'], ['status' => 'pending']);
    $this->assertTrue($updated);

    // Donation is no longer pending: the guarded update is a safe no-op,
    // exactly as it must be for a duplicate/late webhook delivery.
    $updatedAgain = $this->repository->update($id, ['status' => 'succeeded'], ['status' => 'pending']);
    $this->assertFalse($updatedAgain);

    $donation = $this->repository->loadById($id);
    $this->assertSame('succeeded', $donation->status);
  }

  /**
   * @covers ::insert
   */
  public function testUniqueCheckoutSessionIdConstraint(): void {
    $this->repository->insert([
      'uuid' => 'uuid-4',
      'amount' => 100,
      'currency' => 'usd',
      'status' => 'pending',
      'created' => 1700000000,
      'stripe_checkout_session_id' => 'cs_dup',
    ]);

    $this->expectException(IntegrityConstraintViolationException::class);
    $this->repository->insert([
      'uuid' => 'uuid-5',
      'amount' => 200,
      'currency' => 'usd',
      'status' => 'pending',
      'created' => 1700000000,
      'stripe_checkout_session_id' => 'cs_dup',
    ]);
  }

  /**
   * @covers ::findDonations
   * @covers ::countDonations
   * @covers ::getSummary
   */
  public function testFindDonationsFiltersAndSummary(): void {
    $this->repository->insert(['uuid' => 'a', 'amount' => 1000, 'currency' => 'usd', 'status' => 'succeeded', 'donor_email' => 'a@example.com', 'created' => 1000]);
    $this->repository->insert(['uuid' => 'b', 'amount' => 2000, 'currency' => 'usd', 'status' => 'pending', 'donor_email' => 'b@example.com', 'created' => 2000]);
    $this->repository->insert(['uuid' => 'c', 'amount' => 3000, 'currency' => 'usd', 'status' => 'succeeded', 'donor_email' => 'c@example.com', 'created' => 3000]);

    $succeeded = $this->repository->findDonations(['status' => 'succeeded']);
    $this->assertCount(2, $succeeded);

    $this->assertSame(2, $this->repository->countDonations(['status' => 'succeeded']));
    $this->assertSame(3, $this->repository->countDonations([]));

    $summary = $this->repository->getSummary([]);
    $this->assertSame(3, $summary['total_count']);
    $this->assertSame(2, $summary['succeeded_count']);
    $this->assertSame(4000, $summary['succeeded_total']);
    $this->assertSame(2000, $summary['succeeded_average']);
  }

}
