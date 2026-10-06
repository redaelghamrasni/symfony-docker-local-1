# Stripe webhook — consolidation plan (living document)

Working memory for this effort, kept up to date as implementation progresses so a
new session can resume by reading this file. Part of ROADMAP.md immediate priority #1.

Status legend: `[ ]` not started · `[~]` in progress · `[x]` done

---

## Problem

Order creation depends entirely on the browser returning to `/checkout/success`
**and** on the session still holding the checkout data (`buildOrderFromSession()`
reads everything from the session). Two failure modes:

- **Browser never returns** (tab closed, connectivity lost) → payment charged on
  Stripe, **no order** in our DB.
- **Session lost/expired** → even on return, no data → no order.

There is no DB-level idempotency today: duplicates are only avoided because the
cart is cleared after the first success — fragile, and useless for a webhook,
which has neither cart nor session.

## Solution (validated — clean version, fixes the cause)

1. **Pre-persist the order as `pending`** when the amount is finalized
   (`update-payment`, where the session already holds everything). Write
   `order.id` into the PaymentIntent **metadata** and set `stripePaymentIntentId`.
   This removes the webhook's dependency on the session.
2. **Webhook `payment_intent.succeeded` is the source of truth.** It finds the
   `pending` order by PaymentIntent id and performs a single idempotent
   transition `pending → paid` (amount/currency come from the event object),
   then email + reindex.
3. **Browser return `/checkout/success` becomes mainly display**, but performs
   the *same* idempotent transition if it wins the race (order visible
   immediately). Cart/session clearing stays browser-side (it is session-bound)
   and must not break anything if the webhook won first.
4. **Shared `OrderFinalizer`** — webhook, `success`, and `paypalCapture` all
   converge on one `pending → paid` transition. No triplication.
5. **Idempotency, two levels:**
   - DB guard: **unique index on `order.stripe_payment_intent_id`** (multiple
     `NULL` tolerated for PayPal).
   - Application guard: the finalizer transitions only once; already-paid → no-op.

Event: **`payment_intent.succeeded`** (+ `payment_intent.payment_failed` for
logging). NOT `checkout.session.completed` — integration is PaymentElement +
PaymentIntent, not hosted Stripe Checkout.

Signature: `\Stripe\Webhook::constructEvent(rawBody, Stripe-Signature header,
STRIPE_WEBHOOK_SECRET)`; bad/absent signature → 400, nothing processed. Read the
raw request body, never the re-parsed JSON.

Endpoint: `POST /stripe/webhook`, **outside the `/{_locale}` prefix** — declared
via a dedicated YAML route (no `#[Route]` attribute), + `access_control`
`^/stripe/webhook → PUBLIC_ACCESS` (auth is by signature, not firewall).

## Abandoned-`pending` purge (CONFIRMED)

- Console command `app:checkout:purge-pending`, manual + cron-schedulable.
- **No blind age delete.** For each `pending` older than the threshold, re-query
  Stripe for the PaymentIntent first:
  - succeeded/processing/requires_capture → do NOT purge; replay `OrderFinalizer`
    (recovery). The purge doubles as a reconciliation safety net.
  - canceled/requires_payment_method / no PI → treat as abandoned.
- **Criterion (confirmed):** `pending` with `updatedAt` older than **24h**
  (configurable via `--older-than`, default 24h) **and** PaymentIntent not
  succeeded on Stripe. 24h is far beyond any card confirmation window (seconds
  with PaymentElement), so no legitimate in-progress payment can be caught.
- **Soft, confirmed:** mark status `abandoned` (new enum value), never DELETE;
  optional `--delete` for a hard purge later.

## Pieces to implement

- [x] Migration: unique index on `order.stripe_payment_intent_id`
      (`Version20261005170000`, mirrored by an `#[ORM\UniqueConstraint]` on `Order`).
- [x] `OrderRepository::findOneByStripePaymentIntentId()` (+ `markPaidIfPending()`,
      the atomic conditional UPDATE that is the hard idempotency guard, and
      `findPendingOlderThan()` for the purge).
- [x] `update-payment`: upsert a `pending` order + write `order.id` into PI metadata
      (`CheckoutController::upsertPendingOrder`, session key `checkout_order_id`).
- [x] `OrderFinalizer` service (shared pending → paid transition, idempotent;
      verification, confirmation email and reindex live here). `PaymentOutcome` is
      the neutral provider-result value object it consumes.
- [x] Refactor `success` + `paypalCapture` to use `OrderFinalizer`.
- [x] `StripeWebhookController` + non-localized YAML route (`stripe_webhook`,
      `/stripe/webhook`) + `access_control ^/stripe/webhook → PUBLIC_ACCESS`.
      Handles `payment_intent.succeeded` (finalize) and `payment_intent.payment_failed`
      (log).
- [x] Abandoned-`pending` purge command (`app:checkout:purge-pending`, with
      `--older-than` default 24h, `--delete`, `--dry-run`) + `abandoned` order status
      (translations, admin filter tabs and badge styles updated; `paid` added too).
- [x] `.env.dist` placeholder + `.env.test` value + `services.yaml` wiring of
      `STRIPE_WEBHOOK_SECRET`.
- [x] Tests (Unit): idempotency (two calls = one transition, one order, one email) in
      `tests/Unit/Service/OrderFinalizerTest.php`; signature rejection (unsigned/bad →
      400, no processing) + valid-signature processing in
      `tests/Unit/Controller/StripeWebhookControllerTest.php`.

## ⚠️ Production activation requires a real domain + HTTPS (not done)

**Deployed to EC2 2026-10-06, but the webhook is INERT in production.** Stripe
only delivers webhooks to a publicly reachable endpoint with a **valid, CA-trusted
TLS certificate** — which in practice means a **domain**. The current EC2 is
**IP-only (`http://3.96.53.69`, no domain, no TLS)**, so no Stripe dashboard
endpoint can be registered against it (a bare IP / self-signed cert is rejected).
`STRIPE_WEBHOOK_SECRET` is therefore unset on the server and `/stripe/webhook`
returns 400 for everything.

This is **not blocking checkout**: order creation still works via the browser
return (pre-persist `pending` + `success`-page finalize). The webhook is the
reliability *safety net* for the browser-never-returns case, and stays off until a
**real production environment with a domain** exists. To activate then: point a
domain at the host, terminate TLS (Caddy/Let's Encrypt or nginx+certbot, open 443),
create the Stripe dashboard endpoint at `https://<domain>/stripe/webhook`
(events `payment_intent.succeeded` + `payment_intent.payment_failed`), put its
signing secret in the server `.env` (unquoted), and recreate the container.
(For an interim no-domain test, `stripe listen --api-key <sk> --forward-to
http://localhost/stripe/webhook` on the server connects outbound and needs no
inbound HTTPS.)

## Progress

All code pieces implemented (2026-10-05) and the Unit suite is green (60 tests).
Not yet done, and required before this can be considered finished:

- **Run the migration** (`php bin/console doctrine:migrations:migrate`) on each
  environment — not run here, to avoid mutating the dev DB without a heads-up.
- **Configure `STRIPE_WEBHOOK_SECRET`** in `.env.local` (dev) and the server `.env`
  (prod). Until then the endpoint boots fine but rejects every call with 400.
- **End-to-end local validation (webhook side): DONE 2026-10-06.** Drove the live
  `/stripe/webhook` with really-signed payloads: pending→paid+verified on a matching
  succeeded event; idempotent replay (one order, exactly one email confirmed via
  Mailpit); amount mismatch → paid-but-flagged (`amount_mismatch`); bad signature →
  400, order untouched. Still worth doing once with a **real test card through the
  browser** to exercise the other half (update-payment pre-persist + success-page
  race) before configuring the dashboard endpoint and deploying.
- **Reindex** after migrating/first runs (`app:meilisearch:reindex`) so the new
  `paid`/`abandoned` statuses are filterable in the back-office.

Committed on `feature/next` as `4cde8c1a1` ("Add Stripe webhook as idempotent
source of truth for order finalization").

A note on semantics introduced here: the post-payment status is now `paid` (orders
previously stayed `pending` forever). Admin fulfilment still moves `paid →
in_progress → shipped → completed` by hand; `abandoned` is set only by the purge
command.

## Config needed

`STRIPE_WEBHOOK_SECRET` (signing secret, `whsec_…`):
- `.env.dist` → placeholder `whsec_placeholder` (committed, next to `STRIPE_SECRET_KEY`).
- `.env.local` (dev, untracked) → the secret printed by
  `stripe listen --forward-to localhost:8000/stripe/webhook`.
- server `.env` on AWS (untracked) → the dashboard endpoint's signing secret.
- Injected via `services.yaml` (`$webhookSecret: '%env(STRIPE_WEBHOOK_SECRET)%'`).
- **Never committed** — consistent with `.env` already removed from git tracking.

## Vigilance points (must hold)

- Idempotency is **tested**: two calls (webhook + browser return, or the webhook
  twice) produce exactly one `pending → paid` transition and one order.
- Signature verification is **tested**: an unsigned/badly signed payload is
  rejected (400) with no processing.
- Webhook/browser cohabitation: cart/session clearing stays browser-side and does
  not break if the webhook wins the race.
- `STRIPE_WEBHOOK_SECRET` never committed.

## End-to-end validation (before AWS deploy)

Local, together: real test card + `stripe listen` forwarding to
`localhost:8000/stripe/webhook`. Only after the local flow is validated do we
configure the Stripe dashboard endpoint and deploy to AWS.
