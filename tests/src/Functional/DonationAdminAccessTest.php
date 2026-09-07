<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_stripe_donation\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests permission-based access to the admin settings and report pages.
 *
 * @group simple_stripe_donation
 */
class DonationAdminAccessTest extends BrowserTestBase {

  protected static $modules = ['simple_stripe_donation'];

  protected $defaultTheme = 'stark';

  public function testSettingsPageRequiresPermission(): void {
    $this->drupalGet('/admin/config/services/simple-stripe-donation');
    $this->assertSession()->statusCodeEquals(403);

    $admin = $this->drupalCreateUser(['administer simple stripe donation']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/services/simple-stripe-donation');
    $this->assertSession()->statusCodeEquals(200);
  }

  public function testReportPageRequiresPermission(): void {
    $this->drupalGet('/admin/reports/simple-stripe-donations');
    $this->assertSession()->statusCodeEquals(403);

    $viewer = $this->drupalCreateUser(['view simple stripe donations']);
    $this->drupalLogin($viewer);
    $this->drupalGet('/admin/reports/simple-stripe-donations');
    $this->assertSession()->statusCodeEquals(200);
  }

  public function testAuthenticatedUserWithoutPermissionIsDenied(): void {
    $user = $this->drupalCreateUser([]);
    $this->drupalLogin($user);

    $this->drupalGet('/admin/config/services/simple-stripe-donation');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalGet('/admin/reports/simple-stripe-donations');
    $this->assertSession()->statusCodeEquals(403);
  }

}
