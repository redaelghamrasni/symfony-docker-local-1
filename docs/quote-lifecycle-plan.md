# Quote lifecycle — design plan (living document)

Working memory for a new initiative: a **`Quote` → `Order`** lifecycle.
Kept up to date as design/implementation progresses so a new session can resume by
reading this file. Chosen direction: **A2** (go directly to the Quote model, no
interim band-aid). Status legend: `[ ]` not started · `[~]` in progress · `[x]` done.

> **Phase 1 complete (2026-10-09), pending end-to-end testing + single commit.**
> Kickoff decisions resolved (see "Open decisions"). The full checkout migration is
> done: quote created before payment, provider-neutral converter, webhook/success/
> PayPal all route through it, purge generalized to quotes. `upsertPendingOrder` and
> friends removed. Unit suite 95/95 + a real DB conversion round-trip pass. Not yet
> committed (per the user: finish → test the live flow → one commit). Phases 2–3
> (analytics, marketing) remain.

---

## Why (motivation)

Two problems, one model solves both:

1. **Reliability (a real bug found 2026-10-09).** The Stripe webhook can only *finalize*
   an existing pre-payment record — it never creates one (`StripeWebhookController::
   onPaymentIntentSucceeded` looks up by PI id / PI metadata, else logs
   `payment.webhook.order_not_found`). But today the pending `Order` is pre-persisted
   only in `CheckoutController::updatePayment` (which runs **before** the address
   reaches the session) and in `success` (browser return). `updatePayment`'s
   `upsertPendingOrder` therefore **always returns null** (guard at
   `CheckoutController.php` ~675: needs `checkout_email`/`shipping_address`/`city`/
   `postal`, set only by `save-customer-info`, which the frontend calls at payment
   submit — lines 444/779 of `templates/checkout/index.html.twig`). Net effect: if the
   **browser never returns**, no order exists → the webhook finds nothing → charged
   customer, no order. The webhook was validated with synthetic signed payloads + the
   browser-return path, never with a true browser abandonment. The Quote model fixes
   this: a `Quote` always exists before payment, and the webhook converts it.

2. **Business value.** A persisted pre-payment object unlocks what the user wants to
   start now: **abandoned-cart marketing** (reminder emails, promo codes),
   **conversion-rate tracking**, and **seller reports** — standard e-commerce growth
   levers. A quote is the natural home for that data.

## Decision: A2, separate `Quote` entity

Go straight to the Quote model (no Option-B band-aid). Use a **separate `Quote`
entity**, NOT an `Order` with a `draft` status, because `Order` is deliberately an
**immutable snapshot** (addresses/prices frozen at order time — see CLAUDE.md
"Sensitive areas", `Order`/`OrderItem`). The mutable pre-payment object must not live
in the `order` table (it would pollute order numbering and break immutability). This
mirrors the following: mutable `quote` ↔ immutable `sales_order`.

## Lifecycle

```
draft ──(shipping confirmed serviceable)──▶ ready ──(payment succeeds)──▶ converted → Order (paid)
                                              │
                                              └──(unpaid after threshold)──▶ abandoned  (soft; delayed hard-delete only via flag)
```

- **`draft` → `ready` gate (user's point 1):** the quote becomes `ready` only when the
  **abstract `ShippingService` returns ≥1 serviceable rate** for the destination. No
  quote for a mere checkout visitor — only once a cart has a deliverable address + a
  chosen method. Ties into the ports & adapters shipping layer (ARCHITECTURE §5).
- **`ready` → `converted`:** on `payment_intent.succeeded` (webhook) OR on the browser
  return (`success`) OR PayPal capture — all converge on one idempotent **Quote→Order
  converter** (same spirit as `OrderFinalizer`). Exactly one `Order` per quote.
- **`ready`/`draft` → `abandoned`:** soft-marked after a delay, **after re-checking
  Stripe** (never abandon/delete a quote whose PI actually succeeded — recover it
  instead). Generalize the existing `app:checkout:purge-pending` to quotes. Delayed
  hard-delete only with an explicit flag (user agreed: delay deletion to avoid a
  catastrophe).

## Provider/engine independence (design constraint — decided 2026-10-09)

The whole quote process must stay **independent of the concrete engines a market
plugs in** (tax engine, shipping carrier, payment provider). The quote schema and
the converter name no concrete implementation:
- **Tax:** `QuoteTaxLine` is neutral (`code`/`label`/`rate`/`amount`/`jurisdiction`),
  the snapshot of whatever `TaxEngineInterface` produced — never fixed GST/PST columns.
- **Shipping:** carrier/method/reference/amount are opaque snapshot strings; the
  `ready` gate asks the abstract shipping port for serviceable rates, not Shippo.
- **Payment:** the quote stores only `(paymentProvider, paymentReference)` — a neutral
  pair mirroring `PaymentOutcome`, with a unique index `UNIQ_QUOTE_PAYMENT` as the
  idempotency anchor. **No `stripePaymentIntentId` column.** The Stripe-specific bits
  (writing the quote id into PI metadata, reading PI status) live in a Stripe payment
  adapter, not in the entity or the converter.

The natural next layer is a **payment-provider port** (a `PaymentProviderInterface`
with Stripe/PayPal adapters + a registry, analogous to `TaxEngineRegistry`) so the
converter/checkout depend on the port, not on the Stripe SDK directly — to be
introduced with the checkout migration step.

## Webhook / conversion integration

- The `Quote` carries a neutral `(paymentProvider, paymentReference)`; for Stripe the
  reference is the PaymentIntent id and the quote id is written into the PI
  **metadata** (as `order_id` is today) as a provider-specific fallback lookup handled
  by the Stripe adapter.
- `payment_intent.succeeded` → find the quote by `(provider='stripe', reference=PI id)`
  via `QuoteRepository::findOneByPaymentReference` (or PI-metadata fallback) →
  **convert → Order** (idempotent: already-converted = no-op, return the existing
  order) → finalize `paid` (reuse `OrderFinalizer`'s verification + email + reindex).
- Keep the unique index on `order.stripe_payment_intent_id` (Order is unchanged this
  step); the quote's equivalent is the provider-neutral `UNIQ_QUOTE_PAYMENT`.

## Relationship to existing code (what changes)

- **Replaces** `CheckoutController::upsertPendingOrder` + `findFinalizableOrder`: that
  pre-persist logic moves to **quote create/update** (at the shipping-ready point) and
  **quote→order conversion**.
- `CheckoutController`: create/update the quote when shipping becomes serviceable
  (`updatePayment`), attach the PI; `saveCustomerInfo` updates the quote's customer
  fields. Session key `checkout_order_id` → `checkout_quote_id`.
- `success` / `paypalCapture` / webhook → all call the shared **QuoteConverter**.
- `app:checkout:purge-pending` → generalize to stale quotes.
- **`#2 (CheckoutController e2e tests)` on the roadmap now follows this work** — it
  should test the new quote-backed flow, not the old one.

## Phases & checklist

**Phase 1 — Core lifecycle (makes the webhook correct):**
- [x] `Quote` entity + migration (MySQL `default` conn) + repository. **Done 2026-10-09.** `src/Entity/Quote.php` (+ `QuoteItem`, `QuoteTaxLine` snapshot children mirroring `Order`/`OrderItem`/`OrderTaxLine`), repositories `QuoteRepository` (`findOneByPaymentReference`, `markConvertedIfNot`, `findStaleUnconvertedOlderThan`) / `QuoteItemRepository` / `QuoteTaxLineRepository`, migration `Version20261010014034` (applied locally, `doctrine:schema:validate` clean for quote tables). Fields as specified: user (nullable), `sessionId` (guest reattach + purge keying), customer email/name/phone, shipping + billing snapshot, chosen shipping (carrier/method/reference/amount), line snapshot (`QuoteItem[]`), currency, subtotal, `taxTotal` + `QuoteTaxLine[]`, total, **provider-neutral payment linkage** `(paymentProvider, paymentReference)` with unique index `UNIQ_QUOTE_PAYMENT` (no Stripe-specific column — see "Provider/engine independence" above), lookup via `QuoteRepository::findOneByPaymentReference`, `status` (draft/ready/converted/abandoned via `markReady`/`markConverted`/`markAbandoned`/`markDraft`, each stamping its timestamp once), timestamps (`createdAt`/`updatedAt`/`readyAt`/`convertedAt`/`abandonedAt`, America/Toronto, `#[ORM\HasLifecycleCallbacks]`), `convertedOrderId`. Unit tests: `tests/Unit/Entity/QuoteTest.php` (state-machine idempotency, tax total, tz).
- [x] Quote create/update at the shipping-ready gate in checkout; write quote id into PI metadata. **Done 2026-10-09.** New `App\Service\QuoteService::upsert()` builds/updates the quote from session+cart (snapshot of customer/addresses/shipping/tax/items) and applies the ready gate (address + chosen shipping method → `ready`, else `draft`). `CheckoutController` calls it via a Stripe-side helper `syncStripeQuote()` from `createPaymentIntent` (earliest: quote + PI link created up front), `updatePayment` and `saveCustomerInfo`; the quote id is written into the PI metadata by `linkQuoteToStripeMetadata()`. Session key `checkout_order_id` → `checkout_quote_id`. `upsertPendingOrder`/`findFinalizableOrder`/`populateOrderFromSession`/`applyTaxQuote` **removed**.
- [x] `QuoteConverter` service (quote → immutable Order, idempotent). **Done 2026-10-09.** `App\Service\QuoteConverter::convert()`: fast-path returns the already-converted order; otherwise builds the Order snapshot, persists, then claims the quote with `QuoteRepository::markConvertedIfNot()` (conditional UPDATE) — the winner stamps the Stripe reference on its order, a loser discards its order and returns the winner's. Provider-neutral (reads `(paymentProvider, paymentReference)`; Order models only a Stripe column today). Verified with a real DB round-trip (one order per quote, idempotent on repeat).
- [x] Refactor `StripeWebhookController` + `success` + `paypalCapture` to convert via `QuoteConverter` (+ `OrderFinalizer`). **Done 2026-10-09.** Webhook finds the quote by `findOneByPaymentReference('stripe', pi)` (PI-metadata `quote_id` fallback) → `convert` → `finalizePaid`. `success` finds the session quote (or by reference) → `convert` → `finalizePaid`. `paypalCapture` finds/creates the quote, sets `(paypal, orderId)` → `convert` → `finalizePaid`.
- [x] Generalize `app:checkout:purge-pending` → abandon stale quotes (re-check Stripe first; soft; delayed delete via `--delete`). **Done 2026-10-09.** Second `reconcileQuotes()` pass over `findStaleUnconvertedOlderThan`: Stripe PI succeeded → recover (convert+finalize); in-flight → keep; canceled/requires_payment_method → abandon; unreachable → keep. Soft `markAbandoned` by default. (The order pass is kept for legacy/leftover pending orders.)
- [x] Unit tests. **Done 2026-10-09.** `QuoteTest` (state machine), `QuoteConverterTest` (idempotency: one order per quote, race-lost discards), rewritten `StripeWebhookControllerTest` (signed succeeded → convert+finalize, orphan PI acknowledged without convert). Full Unit suite: 95/95. Shipping-ready gate + abandonment criteria exercised via the purge dry-run and the DB round-trip; a dedicated gate unit test is deferred (gate logic is a trivial address+method check inside `QuoteService::populate`).

**Phase 2 — Analytics / reporting (seller):**
- [ ] Conversion funnel metrics: quotes `created → ready → converted → abandoned`, conversion rate by period.
- [ ] Admin report/dashboard page.

**Phase 3 — Marketing (seller-configurable):**
- [ ] Abandoned-cart reminder emails (configurable via `Setting`, like other toggles).
- [ ] Promo codes on abandoned quotes — **reuse the existing `Promotion` entity** (confirmed present) rather than a new mechanism.

## Open decisions to resolve at kickoff

1. **Line storage:** ✅ **RESOLVED 2026-10-09 → snapshot.** `QuoteItem` + `QuoteTaxLine`
   copy price/qty/tax at quote time (mirroring `OrderItem`/`OrderTaxLine`), so a later
   cart edit or price change never rewrites what was quoted and conversion is a direct
   copy. Chosen over a live `Cart`/`CartItem` FK for history/analytics safety.
2. **Guest PII & privacy:** abandoned-cart emails to guests — consent/retention policy?
   Keep guest quotes keyed by session; define a retention window.
3. **Numbering:** quote uses an internal id (NOT customer-facing); the `Order` keeps
   the customer-facing number on conversion. Confirm.
4. **Thresholds:** `ready` unpaid > X h → `abandoned`; reminder at Y h; hard-delete at
   Z days (flag-gated). Pick defaults (make them `Setting`s).
5. **Migration scope:** ✅ **RESOLVED 2026-10-09 → incremental, but NOT deferred.**
   Build quote create/update + converter + webhook/success/PayPal rewiring without
   regressing the live flow, then fully replace `upsertPendingOrder`. The user set the
   checkout migration as the **absolute top priority** for the immediately following
   steps — "incremental" is a rollout safety constraint, not permission to leave the
   old order-pre-persist path in place.
6. **PayPal:** confirm the PayPal capture path also routes through the converter.

## Vigilance / constraints (must hold)

- `Order` stays immutable; `Quote` is the mutable pre-payment object.
- Never hard-delete a quote whose PI may have succeeded — re-check Stripe, delay delete.
- All quote dates in `America/Toronto` via lifecycle callbacks (+ `HasLifecycleCallbacks`).
- Deploy rules (see CLAUDE.md / [[reference-ec2-staging-access]]): console runs as
  `www-data` (entrypoint `su-exec`, cron `-u www-data`); migrations run on boot.
- Keep idempotency testable: one order per quote, duplicate webhook = no-op.
```
