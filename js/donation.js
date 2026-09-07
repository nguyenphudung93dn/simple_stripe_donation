/**
 * @file
 * Progressive UX enhancements for the donation form.
 *
 * Nothing here is required for the form to work: preset selection and the
 * custom-amount field are plain radio buttons and a text field, validated
 * server-side by DonationForm/DonationService regardless of JavaScript.
 * The selected-pill look itself is pure CSS (`:checked + label`) — no JS
 * needed for that part, so it still works with JS disabled. The
 * thousand-separator formatting below is display-only: DonationForm and
 * DonationService both strip commas before parsing the submitted amount,
 * so the field still works perfectly with JS disabled (just without the
 * live "1,232.32" formatting as the donor types).
 */
(function (Drupal, once) {
  'use strict';

  /**
   * Reformats a raw input value with thousand separators on the integer
   * part, keeping at most one decimal point and 3 decimal digits (the
   * same limits DonationForm/DonationService enforce server-side).
   */
  function formatAmountValue(raw) {
    var cleaned = raw.replace(/[^\d.]/g, '');
    var firstDot = cleaned.indexOf('.');
    if (firstDot !== -1) {
      cleaned = cleaned.slice(0, firstDot + 1) + cleaned.slice(firstDot + 1).replace(/\./g, '');
    }

    var parts = cleaned.split('.');
    var intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    var decPart = parts.length > 1 ? parts[1].slice(0, 3) : null;

    return intPart + (decPart !== null ? '.' + decPart : '');
  }

  Drupal.behaviors.simpleStripeDonationForm = {
    attach: function (context) {
      once('simple-stripe-donation-custom-amount-focus', '.simple-stripe-donation-custom-amount', context).forEach(function (input) {
        var form = input.closest('form');
        if (!form) {
          return;
        }
        var otherRadio = form.querySelector('input[type="radio"][name="amount_preset"][value="other"]');
        if (!otherRadio) {
          return;
        }
        otherRadio.addEventListener('change', function () {
          if (otherRadio.checked) {
            input.focus();
          }
        });
      });

      once('simple-stripe-donation-amount-format', '.simple-stripe-donation-custom-amount', context).forEach(function (input) {
        input.addEventListener('input', function () {
          var caret = input.selectionStart;
          // Count digits AND the decimal point before the caret — commas
          // are the only characters this formatter invents, so they're
          // the only ones treated as weightless when relocating the
          // caret. Treating '.' as weightless too (e.g. via \D, which
          // matches it) is what let the caret land *before* a
          // just-typed decimal point instead of after it.
          var markersBeforeCaret = input.value.slice(0, caret).replace(/[^\d.]/g, '').length;

          input.value = formatAmountValue(input.value);

          var pos = 0;
          var markersSeen = 0;
          while (pos < input.value.length && markersSeen < markersBeforeCaret) {
            if (/[\d.]/.test(input.value[pos])) {
              markersSeen++;
            }
            pos++;
          }
          input.setSelectionRange(pos, pos);
        });
      });
    }
  };
})(Drupal, once);
