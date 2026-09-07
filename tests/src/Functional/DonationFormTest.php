<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_stripe_donation\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the public donation form.
 *
 * No Stripe credentials are configured in this test environment, so a
 * valid submission cannot reach Stripe's API — StripeService fails fast
 * with a configuration error before any network call, letting these tests
 * verify server-side validation and error handling without mocking the
 * Stripe SDK or making a real API call.
 *
 * @group simple_stripe_donation
 */
class DonationFormTest extends BrowserTestBase {

  protected static $modules = ['simple_stripe_donation'];

  protected $defaultTheme = 'stark';

  /**
   * Anonymous (guest) donations must be allowed.
   */
  public function testAnonymousCanAccessDonationForm(): void {
    $this->drupalGet('/donate');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('donor_email');
    $this->assertSession()->buttonExists('Donate');
  }

  /**
   * Required-field and format validation must happen server-side.
   */
  public function testEmptySubmissionShowsValidationErrors(): void {
    $this->drupalGet('/donate');
    $this->submitForm(['donor_email' => 'not-an-email'], 'Donate');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('email');
  }

  /**
   * A well-formed submission is rejected gracefully, not with a crash, when
   * Stripe is not configured.
   */
  public function testValidSubmissionFailsGracefullyWithoutStripeConfigured(): void {
    $this->drupalGet('/donate');
    $edit = [
      'amount_preset' => '25',
      'donor_email' => 'donor@example.com',
    ];
    $this->submitForm($edit, 'Donate');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Unable to reach the payment processor');
  }

}
