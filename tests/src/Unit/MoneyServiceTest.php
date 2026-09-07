<?php

declare(strict_types=1);

namespace Drupal\Tests\simple_stripe_donation\Unit;

use Drupal\simple_stripe_donation\Service\MoneyService;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\simple_stripe_donation\Service\MoneyService
 * @group simple_stripe_donation
 */
class MoneyServiceTest extends UnitTestCase {

  protected MoneyService $moneyService;

  protected function setUp(): void {
    parent::setUp();
    $this->moneyService = new MoneyService();
  }

  /**
   * @covers ::toMinorUnits
   * @dataProvider providerToMinorUnits
   */
  public function testToMinorUnits(string $amount, string $currency, int $expected): void {
    $this->assertSame($expected, $this->moneyService->toMinorUnits($amount, $currency));
  }

  public function providerToMinorUnits(): array {
    return [
      'whole dollars' => ['25', 'usd', 2500],
      'two decimals' => ['25.50', 'USD', 2550],
      'single decimal padded' => ['25.5', 'usd', 2550],
      'rounds up' => ['25.505', 'usd', 2551],
      'rounds down' => ['25.504', 'usd', 2550],
      'zero-decimal currency (JPY)' => ['1500', 'jpy', 1500],
      'zero-decimal currency ignores decimals input' => ['1500.4', 'JPY', 1500],
      'three-decimal currency rounds to nearest 10' => ['1.234', 'bhd', 1230],
      'three-decimal currency rounds up to nearest 10' => ['1.236', 'BHD', 1240],
      'zero amount' => ['0', 'usd', 0],
      'leading zero' => ['05', 'usd', 500],
    ];
  }

  /**
   * @covers ::toMinorUnits
   * @dataProvider providerInvalidAmounts
   */
  public function testToMinorUnitsRejectsInvalidInput(string $amount): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->moneyService->toMinorUnits($amount, 'usd');
  }

  public function providerInvalidAmounts(): array {
    return [
      'negative' => ['-5'],
      'empty' => [''],
      'not numeric' => ['abc'],
      'trailing garbage' => ['25.50abc'],
      'multiple dots' => ['25.5.0'],
    ];
  }

  /**
   * @covers ::formatMajorUnits
   */
  public function testFormatMajorUnitsRoundTrips(): void {
    $this->assertSame('25.50', $this->moneyService->formatMajorUnits(2550, 'usd'));
    $this->assertSame('0.05', $this->moneyService->formatMajorUnits(5, 'usd'));
    $this->assertSame('1500', $this->moneyService->formatMajorUnits(1500, 'jpy'));
    $this->assertSame('1.230', $this->moneyService->formatMajorUnits(1230, 'bhd'));
  }

  /**
   * @covers ::getDecimalDigits
   */
  public function testGetDecimalDigits(): void {
    $this->assertSame(2, $this->moneyService->getDecimalDigits('usd'));
    $this->assertSame(0, $this->moneyService->getDecimalDigits('JPY'));
    $this->assertSame(3, $this->moneyService->getDecimalDigits('kwd'));
  }

  /**
   * @covers ::normalizeCurrency
   */
  public function testNormalizeCurrency(): void {
    $this->assertSame('usd', $this->moneyService->normalizeCurrency(' USD '));
  }

}
