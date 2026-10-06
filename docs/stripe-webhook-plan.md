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

- [ ] Migration: unique index on `order.stripe_payment_intent_id`
- [ ] `OrderRepository::findOneByStripePaymentIntentId()`
- [ ] `update-payment`: upsert a `pending` order + write `order.id` into PI metadata
- [ ] `OrderFinalizer` service (shared pending → paid transition, idempotent)
- [ ] Refactor `success` + `paypalCapture` to use `OrderFinalizer`
- [ ] `StripeWebhookController` + non-localized YAML route + `access_control` rule
- [ ] Abandoned-`pending` purge command (+ `abandoned` order status)
- [ ] `.env.dist` placeholder + `services.yaml` wiring of `STRIPE_WEBHOOK_SECRET`
- [ ] Tests (Unit): idempotency (two calls = one transition, one order), signature
      rejection (unsigned/bad → 400, no processing)

## Progress

Nothing implemented yet — plan fully validated (incl. purge criterion), work to
**start in a new session**. Order of work: migration + `OrderFinalizer` first (the
two structural pieces), then webhook, then refactor of `success`/`paypalCapture`,
then purge, then tests. Resume by reading this file; implement step by step; run
the Unit suite before committing; commit only on the user's explicit signal.

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
