<?php

declare(strict_types=1);

namespace Drupal\simple_stripe_donation\Service;

/**
 * Converts donation amounts between major currency units and Stripe's
 * smallest-currency-unit integers, without relying on floating-point math.
 *
 * Stripe expects amounts as integers in the currency's smallest unit (e.g.
 * cents for USD), except for a small set of "zero-decimal" currencies (e.g.
 * JPY) where the integer amount equals the major unit, and a small set of
 * "three-decimal" currencies where Stripe additionally requires the amount
 * be a multiple of 10.
 *
 * @see https://docs.stripe.com/currencies#zero-decimal
 * @see https://docs.stripe.com/currencies#three-decimal
 */
class MoneyService {

  /**
   * Currencies with no minor unit: the integer amount equals the major unit.
   */
  const ZERO_DECIMAL_CURRENCIES = [
    'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
    'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
  ];

  /**
   * Currencies with three decimal places, where Stripe requires the final
   * integer amount to be a multiple of 10.
   */
  const THREE_DECIMAL_CURRENCIES = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

  /**
   * Common currency symbols, for donation form display only (never used in
   * amounts sent to Stripe, which always use the ISO code).
   */
  const CURRENCY_SYMBOLS = [
    'USD' => '$', 'AUD' => '$', 'CAD' => '$', 'NZD' => '$', 'SGD' => '$',
    'HKD' => '$', 'MXN' => '$',
    'EUR' => '€',
    'GBP' => '£',
    'JPY' => '¥', 'CNY' => '¥',
    'KRW' => '₩',
    'VND' => '₫',
    'INR' => '₹',
    'THB' => '฿',
    'PHP' => '₱',
    'ILS' => '₪',
    'TRY' => '₺',
    'RUB' => '₽',
    'CHF' => 'CHF',
    'SEK' => 'kr', 'NOK' => 'kr', 'DKK' => 'kr',
    'PLN' => 'zł',
    'CZK' => 'Kč',
    'HUF' => 'Ft',
    'ZAR' => 'R',
    'BRL' => 'R$',
    'MYR' => 'RM',
    'IDR' => 'Rp',
    'AED' => 'د.إ',
    'SAR' => 'ر.س',
  ];

  /**
   * Normalizes a currency code to the lowercase form Stripe's API expects.
   */
  public function normalizeCurrency(string $currency): string {
    return strtolower(trim($currency));
  }

  /**
   * Gets the display symbol for a currency, e.g. "$" for USD, falling back
   * to the uppercase ISO code for currencies without a common symbol.
   *
   * Display only — amounts sent to Stripe always use the ISO code.
   */
  public function getCurrencySymbol(string $currencyCode): string {
    $currency = strtoupper(trim($currencyCode));
    return self::CURRENCY_SYMBOLS[$currency] ?? $currency;
  }

  /**
   * Gets the number of minor-unit decimal digits used by a currency.
   */
  public function getDecimalDigits(string $currencyCode): int {
    $currency = strtoupper(trim($currencyCode));

    if (in_array($currency, self::ZERO_DECIMAL_CURRENCIES, TRUE)) {
      return 0;
    }

    if (in_array($currency, self::THREE_DECIMAL_CURRENCIES, TRUE)) {
      return 3;
    }

    return 2;
  }

  /**
   * Converts a decimal amount string (e.g. "25.50") into Stripe's smallest
   * currency unit (e.g. 2550), using only integer arithmetic.
   *
   * @param string $amount
   *   A non-negative decimal amount, e.g. "25", "25.5" or "25.50".
   * @param string $currencyCode
   *   The ISO 4217 currency code (case-insensitive).
   *
   * @return int
   *   The amount expressed in the currency's smallest unit.
   *
   * @throws \InvalidArgumentException
   *   If the amount is not a valid non-negative decimal string.
   */
  public function toMinorUnits(string $amount, string $currencyCode): int {
    $amount = trim($amount);

    if (!preg_match('/^\d+(\.\d+)?$/', $amount)) {
      throw new \InvalidArgumentException("Invalid amount format: '{$amount}'.");
    }

    $decimals = $this->getDecimalDigits($currencyCode);

    [$intPart, $fracPart] = array_pad(explode('.', $amount, 2), 2, '');
    $intPart = ltrim($intPart, '0');
    $intPart = $intPart === '' ? '0' : $intPart;

    // Pad one extra digit so we can round the final digit off cleanly.
    $fracPart = str_pad(substr($fracPart, 0, $decimals + 1), $decimals + 1, '0');
    $roundDigit = (int) $fracPart[$decimals];
    $fracPart = substr($fracPart, 0, $decimals);

    $minorUnits = ((int) $intPart) * (10 ** $decimals) + ($fracPart === '' ? 0 : (int) $fracPart);

    if ($roundDigit >= 5) {
      $minorUnits++;
    }

    if (in_array(strtoupper($currencyCode), self::THREE_DECIMAL_CURRENCIES, TRUE)) {
      $remainder = $minorUnits % 10;
      $minorUnits = $remainder >= 5 ? $minorUnits + (10 - $remainder) : $minorUnits - $remainder;
    }

    return $minorUnits;
  }

  /**
   * Converts an integer amount in the smallest currency unit back into a
   * decimal major-unit string (e.g. 2550 -> "25.50"), for display only.
   */
  public function formatMajorUnits(int $minorUnits, string $currencyCode): string {
    $decimals = $this->getDecimalDigits($currencyCode);

    if ($decimals === 0) {
      return (string) $minorUnits;
    }

    $divisor = 10 ** $decimals;
    $intPart = intdiv($minorUnits, $divisor);
    $fracPart = str_pad((string) ($minorUnits % $divisor), $decimals, '0', STR_PAD_LEFT);

    return $intPart . '.' . $fracPart;
  }

}
