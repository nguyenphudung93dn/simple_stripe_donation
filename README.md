# Simple Stripe Donation

A lightweight, one-time donation system for Drupal, powered by Stripe Checkout.
**This module does not depend on, and does not require, Drupal Commerce.**

Install it, configure Stripe, drop the donation block anywhere on the site, and
start accepting donations.

**Author:** Nguyen Phu Dung ([phudung.io.vn](https://phudung.io.vn))

## 1. Overview

Simple Stripe Donation lets a site administrator collect one-time donations
through Stripe's hosted Checkout page. Drupal never sees, handles, or stores
card numbers, CVCs or expiry dates — the donor is redirected to Stripe for
payment and Stripe redirects them back. A background webhook from Stripe is
the authoritative signal that a payment succeeded; the module never trusts
the browser redirect alone to mark a donation as paid.

Three distinct things work together, and it's worth being precise about
what each one is:

| Concept | What it is | Who is authoritative |
|---|---|---|
| **Stripe Checkout** | Stripe's hosted payment page. The donor enters card details there, not on your site. | Stripe |
| **Stripe webhook** | An asynchronous HTTP callback Stripe sends to `/stripe-donation/webhook` when something happens (payment succeeded, failed, expired). | Stripe |
| **Drupal donation record** | The row in the `simple_stripe_donation` table that tracks status or your site's donation. | Drupal, but its `status` field is only ever set to `succeeded` in response to a verified webhook (or a same-state read-through from the Stripe API on the success page), never from the donor's browser alone. |

## 2. Features

- Stripe Checkout (hosted, `mode=payment`) — no card fields, no PCI scope
- Configurable preset amounts, min/max, custom-amount toggle
- Optional donor name, message, anonymous donations, guest checkout
- Signed, idempotent webhook processing (`checkout.session.completed`,
  `checkout.session.expired`, `payment_intent.succeeded`,
  `payment_intent.payment_failed`)
- Donor receipt + admin notification emails (Drupal mail manager, themeable)
- Admin donation report with filters, summary stats, and CSV-free pagination
- Admin refund action (confirm form, real Stripe refund, full or partial)
- Environment-variable secret overrides for production deployments
- A reusable donation Block plugin, placeable anywhere via Block Layout
- No dependency on Drupal Commerce, and no vendored Stripe SDK

## 3. Requirements

- Drupal 10.3+ or Drupal 11
- PHP 8.2+
- Composer
- MySQL/MariaDB or PostgreSQL (the module only uses Drupal's Database API —
  no driver-specific SQL)
- A Stripe account (test mode is free and requires no live keys)

## 4. Installation

```bash
# From the Drupal project root
composer require stripe/stripe-php:"^15.10 || ^18.0"
```

If `stripe/stripe-php` is already present (e.g. pulled in by another module),
just make sure the installed version satisfies the module's `composer.json`
constraint.

Then enable the module:

```bash
drush en simple_stripe_donation -y
# or, without Drush:
# vendor/bin/drush pm:enable simple_stripe_donation
```

Enabling the module creates two new database tables:

- `simple_stripe_donation` — one row per donation
- `simple_stripe_donation_event` — one row per Stripe webhook event received
  (used for idempotency and auditing)

## 5. Composer

The module ships its own `composer.json` (standard for a `drupal-module`
package) declaring:

```json
{
  "require": {
    "php": ">=8.2",
    "stripe/stripe-php": "^15.10 || ^18.0"
  }
}
```

The vendor directory is never bundled inside the module — Composer manages
`stripe/stripe-php` at the project root like any other dependency.

## 6. Stripe setup

1. Create a [Stripe account](https://dashboard.stripe.com/register) if you
   don't have one.
2. In the Stripe Dashboard, switch to **Test mode** first.
3. Go to **Developers → API keys** and copy:
   - **Publishable key** (`pk_test_...`)
   - **Secret key** (`sk_test_...`)
4. Do not use live keys until you've tested the full flow end to end.

## 7. Webhook setup

Webhooks are how Stripe tells Drupal that a payment actually succeeded —
without one, donations will stay `pending` forever no matter what the donor
sees in their browser.

1. In the Stripe Dashboard, go to **Developers → Webhooks → Add endpoint**.
2. Endpoint URL: `https://your-site-domain.example/stripe-donation/webhook`
   (replace with your real domain — this is never hardcoded by the module).
3. Select these events (minimum required; more can be added later without
   code changes elsewhere, see "Future roadmap"):
   - `checkout.session.completed`
   - `checkout.session.expired`
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
4. After creating the endpoint, copy its **Signing secret** (`whsec_...`)
   into the module's settings form (or the `STRIPE_WEBHOOK_SECRET`
   environment variable).

### Local development with the Stripe CLI

```bash
stripe login
stripe listen --forward-to https://your-local-site.ddev.site/stripe-donation/webhook
```

`stripe listen` prints a signing secret starting with `whsec_` — use that
one for local testing (it's different from the Dashboard endpoint's secret).
Trigger test events with:

```bash
stripe trigger checkout.session.completed
stripe trigger payment_intent.payment_failed
```

## 8. Drupal configuration

Visit **Admin → Configuration → Web services → Simple Stripe Donation**
(`/admin/config/services/simple-stripe-donation`). Requires the
`administer simple stripe donation` permission.

- **Stripe**: mode (test/live), currency, and separate credential sets for
  test and live mode — they are never mixed. A "Test connection" button
  next to each credential set calls Stripe's API with that key (never
  logging or displaying the key itself) to confirm it's valid.
- **Donation**: preset amounts, minimum/maximum amount, and toggles for
  custom amount, donor name, donor message, and anonymous donations.
- **Email**: donor receipt and admin notification toggles, the admin
  notification address, and subject line templates (placeholders:
  `[donation:reference]`, `[donation:amount]`, `[donation:currency]`,
  `[donation:date]`).

## 9. Creating a donation block

1. Go to **Admin → Structure → Block layout** (`/admin/structure/block`).
2. Click **Place block** in the region you want.
3. Search for **Simple Stripe Donation**.
4. Optionally set a block-specific description, preset amount overrides, and
   default amount — these override (not duplicate) the site-wide settings;
   the block renders the exact same `DonationForm` and calls the exact same
   `DonationService` as the standalone `/donate` page.

The form is also available directly at `/donate`, with no block required.

## 10. Testing

### Automated tests

```bash
# From the Drupal project root, with drupal/core-dev + phpunit/phpunit
# installed as dev dependencies (not required for production installs):
composer require --dev drupal/core-dev phpunit/phpunit --with-all-dependencies

SIMPLETEST_DB=mysql://user:pass@localhost/drupal_test \
  vendor/bin/phpunit -c core --group simple_stripe_donation
```

- **Unit** (`tests/src/Unit`): amount conversion (including zero- and
  three-decimal currencies), donation state-transition guards, webhook event
  dispatch, webhook error handling — all with the Stripe SDK and Drupal
  container mocked, no network calls.
- **Kernel** (`tests/src/Kernel`): real database schema install, repository
  CRUD, guarded state transitions, and the webhook event table's
  `UNIQUE(stripe_event_id)` idempotency guarantee (including "retry after a
  failed processing attempt" and "ignore an already-processed duplicate").
- **Functional** (`tests/src/Functional`): donation form access and
  server-side validation, and permission-gated access to the settings and
  report pages.

No test calls the real Stripe API. `DonationFormTest` deliberately runs with
no Stripe credentials configured, which makes `StripeService` fail with a
configuration error *before* any network call — exercising the same
error-handling path a misconfigured production site would hit, without
mocking the SDK.

### Manual testing with Stripe test cards

Use `4242 4242 4242 4242`, any future expiry, any CVC, any postal code, to
simulate a successful payment on Stripe's Checkout page. See
[Stripe's test card list](https://docs.stripe.com/testing) for
failure/3-D-Secure scenarios.

## 11. Production deployment

- Set `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` and
  `STRIPE_PUBLISHABLE_KEY` as environment variables rather than storing live
  secrets in configuration/`config export` output. When set, they always take
  precedence over whatever is stored in config, for the currently active
  mode.
- Switch **Stripe mode** to Live only once the full flow (donate → Checkout
  → webhook → receipt email) has been verified in test mode.
- Register a **separate** live-mode webhook endpoint in the Stripe
  Dashboard (Stripe does not share test/live webhook secrets).
- Make sure your reverse proxy / CDN does not cache or block
  `POST /stripe-donation/webhook`.

## 12. Security considerations

- **No card data ever reaches Drupal.** Payment happens entirely on
  Stripe's hosted Checkout page.
- **Webhook signature verification is mandatory.** Every request to
  `/stripe-donation/webhook` is verified against `Stripe-Signature` using
  the Stripe SDK before any data is read; invalid signatures get a 400 and
  touch no donation data.
- **Idempotency is enforced at the database level.** The
  `simple_stripe_donation_event` table has a `UNIQUE(stripe_event_id)`
  constraint — a retried webhook cannot create a duplicate donation or send
  a duplicate email, even under concurrent delivery.
- **State transitions are guarded, not assumed.** `pending → succeeded`
  (and every other transition) is a conditional `UPDATE ... WHERE status =
  <expected>`, so two events racing for the same donation can't both apply.
- **The browser is never trusted.** `/donate/success` reads the donation's
  actual stored status (and, if still pending, asks Stripe directly) — it
  never writes `succeeded` itself. `/donate/cancel` never writes `failed`.
- **Amounts are always re-validated server-side**, in the smallest currency
  unit, using integer arithmetic (see `MoneyService`) — the client-supplied
  amount is never forwarded to Stripe unchecked.
- **Secrets are never logged, never in exception messages, never sent to
  the browser.** Only the Stripe *publishable* key (safe by design) is ever
  exposed client-side, and v1 doesn't even need it (Checkout redirect uses
  the Session's own hosted `url`).
- **CSRF**: the donation form and settings form use Drupal Form API's
  built-in token; the refund action is a POST-only confirm form (never a
  bare link) for the same reason.
- **Rate limiting**: donation submissions are flood-controlled per client
  IP (20/hour by default) to blunt automated abuse without requiring a
  CAPTCHA in v1 (the form is structured so one can be added later without
  changing the submission flow).
- **Access control**: three distinct permissions gate settings, viewing
  reports, and refunding — `/stripe-donation/webhook` is the only public
  route, and it's protected by signature verification instead of a Drupal
  permission (Stripe cannot present a session or CSRF token).

## 13. Troubleshooting

| Symptom | Likely cause |
|---|---|
| Donation stays `pending` forever | Webhook not configured, wrong signing secret, or the endpoint isn't reachable from the public internet (check `/admin/reports/dblog` for "Stripe webhook: invalid signature" or connection errors). |
| "Unable to reach the payment processor right now" on the donation form | Missing/invalid secret key for the active mode — check the settings form's "Test connection" button. |
| Duplicate donor emails | Should not happen — `markSucceeded()` only ever fires once per donation (guarded transition) regardless of how many times a webhook is retried. If you see this, check for two Checkout Sessions being created for one submission (e.g. a double form-submit bypassing flood control). |
| Refund button missing | Requires the `manage simple stripe donations` permission and a donation in `succeeded` status. |
| `stripe/stripe-php` not found (status report warning) | Run `composer require stripe/stripe-php` at the project root. |

## 14. Development

Key classes:

- `Service/StripeService` — the only class that calls the Stripe SDK.
- `Service/DonationService` — business rules and state transitions.
- `Service/WebhookService` — signature verification, idempotency, dispatch.
- `Service/MoneyService` — decimal ⇄ smallest-currency-unit conversion.
- `Repository/DonationRepository` — all `simple_stripe_donation` table
  access, via Drupal's Database API (portable across MySQL/PostgreSQL).

Everything is wired through `simple_stripe_donation.services.yml` — nothing
is instantiated with `new` inside a controller or form except where noted
in the code (e.g. `RefundConfirmForm` is a `ConfirmFormBase`, not a service).

Run static analysis before submitting changes:

```bash
vendor/bin/phpstan analyse modules/simple_stripe_donation
vendor/bin/phpcs --standard=Drupal,DrupalPractice modules/simple_stripe_donation
```

## 15. Known limitations

- Refunds are tracked as a single `refunded` timestamp; partial-refund
  *amounts* aren't stored separately from the original donation amount
  (Stripe's dashboard remains the source of truth for exact refunded
  amounts).
- Refunds that settle asynchronously (rare, some non-card payment methods)
  are not yet confirmed via a `charge.refunded` webhook — v1 only confirms
  refunds that Stripe's refund-creation API call reports as `succeeded`
  synchronously.
- Report summary totals assume a single active currency; multi-currency
  aggregation is not implemented (see roadmap).

## 16. Future roadmap

Deliberately **not** implemented in v1, but the architecture (Stripe
metadata carrying `donation_id`, a dedicated webhook event table, a service
layer independent of the transport) is designed so these can be added
without a rewrite:

- Recurring/subscription donations (monthly/yearly)
- Stripe Customer + Customer Portal integration
- Fundraising campaigns and goals
- Webform and Paragraphs integration
- CSV export of the donation report
- A REST/JSON:API endpoint for donations
- Multi-currency report aggregation
- Tax receipts
- `charge.refunded` webhook handling for asynchronous refund confirmation
