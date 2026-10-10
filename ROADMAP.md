# Roadmap

Shared working memory across sessions. Two clearly separated sets: what we are
consolidating now, and a longer-term product vision we are *not* starting yet.

See `ARCHITECTURE.md` for how the system works today and `CLAUDE.md` for conventions.

---

## Immediate priority — critical-path consolidation (in progress)

Tackle in this order; each item is a step, not a parallel track.

1. **Stripe webhook.** ✅ Deployed to EC2 (2026-10-06) and **activated 2026-10-09**
   (valid Let's Encrypt cert via Caddy+sslip.io → `https://3-96-53-69.sslip.io`,
   dashboard endpoint created, `STRIPE_WEBHOOK_SECRET` set, test-mode). ⚠️ **A real
   bug was found 2026-10-09:** the pre-payment `pending` order is never actually
   created before payment (the frontend saves the address only at payment submit,
   after `update-payment`'s pre-persist runs), so on a true **browser abandonment**
   the webhook finds no order to finalize → charged customer, no order. The webhook
   had only been validated with synthetic signed payloads + the browser-return path.
   **This bug is folded into priority #2 (Quote lifecycle)** rather than band-aided.
   Living plan: [`docs/stripe-webhook-plan.md`](docs/stripe-webhook-plan.md).
2. **Quote → Order lifecycle (IN PROGRESS — current focus, started 2026-10-09).** A
   Magento-style mutable `Quote` created when shipping is confirmed serviceable,
   converted to an immutable `Order` on payment (webhook/success/PayPal converge on
   one idempotent converter). Fixes the #1 webhook bug (a pre-payment record always
   exists) **and** lays the base for the business goals the user wants: abandoned-cart
   marketing (reminders, promo codes via the existing `Promotion` entity),
   conversion-rate tracking, seller reports. Decision: **A2** (go straight to the
   Quote model, no interim fix). Kickoff decisions resolved: lines stored as a
   **snapshot** (`QuoteItem`/`QuoteTaxLine`, mirroring `Order`); rollout is
   **incremental**. Full design + phased checklist:
   [`docs/quote-lifecycle-plan.md`](docs/quote-lifecycle-plan.md).
   - ✅ **Data model done (2026-10-09):** `Quote` + `QuoteItem` + `QuoteTaxLine`
     entities, repositories, migration `Version20261010014034` (applied locally,
     schema in sync), lifecycle state machine + unit tests (`tests/Unit/Entity/QuoteTest.php`).
     **Provider/engine-independent by design:** neutral tax lines (snapshot of
     `TaxEngineInterface` output), opaque shipping snapshot, and a provider-neutral
     payment linkage `(paymentProvider, paymentReference)` + `UNIQ_QUOTE_PAYMENT` —
     **no Stripe-specific column**; the Stripe/PayPal specifics stay in adapters.
   - ✅ **Checkout migration done (2026-10-09):** `QuoteService` creates/updates the
     quote before payment (PI linked up front at `createPaymentIntent`, quote id in PI
     metadata); idempotent `QuoteConverter` (one order per quote, claimed via
     `markConvertedIfNot`); webhook + `success` + PayPal capture all convert through it
     then finalize via `OrderFinalizer`; `app:checkout:purge-pending` generalized with a
     second quote-reconciliation pass. Old `upsertPendingOrder`/`findFinalizableOrder`/
     `populateOrderFromSession` removed — the webhook bug is fixed (a quote always exists
     before payment). Unit suite 95/95 + a real DB conversion round-trip verified.
   - 🔜 **Remaining before this is "done":** exercise the live browser flow end-to-end
     (Stripe test-mode checkout, true browser abandonment → webhook converts; PayPal
     capture; purge recovery), then land the whole thing as a **single commit** (per the
     user — no step-by-step commits for this feature). Phases 2–3 (analytics, marketing)
     are separate follow-ups.
3. **CheckoutController end-to-end tests.** The most financially critical flow
   (tax → Stripe/PayPal → shipping → order) is currently untested. Do this *after*
   the Quote lifecycle (#2), since it reshapes order creation — test the new
   quote-backed flow, not the old one.
4. **Route a real business message on RabbitMQ.** Messenger/worker infra is
   wired but no business message flows through it. Target: stock management /
   concurrency control.
5. **Redis cache invalidation.** The `articles`/`categories` tags are set but
   never invalidated, so the public catalog can stay stale up to 1h. Invalidate
   in the admin create/edit/delete controllers for articles and categories.
6. **Shipping-rate snapshot fallback.** ✅ **Done & deployed to EC2 (2026-10-06)** —
   migrations applied, baseline seeded, hourly cron live (runs as `www-data`), 27+
   real snapshots captured. Serves known-good real rates at checkout when Shippo
   returns none. Details: ARCHITECTURE.md §5 "Shipping-rate snapshot fallback".
   Replaced the simulated mock fallback removed on 2026-10-06: `ShippingService`
   now splits into `rateLive()` (Shippo only) and `getRates()` (live → fallback);
   when live returns nothing it serves the latest known-good real rates for the
   route/weight band (`ShippingRateSnapshot`, keyed by `country|region|band` via
   `ShippingRouteKey`). Snapshots hold real rates only — **no hardcoded addresses
   in app config**: the refresh rates real addresses held in the DB — a seeded,
   editable baseline (`shipping_destination_seed`, so the fallback works day one)
   plus the distinct destinations from order history (retry-on-empty, never
   overwrites a good snapshot with an empty one) — and opportunistic capture on
   each successful checkout rating keeps real routes warm between refreshes.
   Rate-limit aware: one attempt per route per run paced by the admin-tunable
   `shipping.snapshot.throttle` setting (`--throttle` overrides), empties retried
   by the schedule (not an in-run loop), only a 429 retried in-run after
   `--rate-limit-backoff`. Refreshed on server startup (`docker/entrypoint.sh`,
   backgrounded) and hourly via cron. Full details: ARCHITECTURE.md §5
   "Shipping-rate snapshot fallback".

---

## Deferred priority — product vision (do NOT start now; recorded only)

> This is an assumed direction, not a sprint commitment.

**Principle: every heavy service must have a degraded mode that works without
it**, so GearHub can be deployed minimally in low-infrastructure markets
(e.g. Afghanistan: little cloud, cash-dominant).

- **Database.** Connection is already portable via `DATABASE_URL` + Doctrine.
  Add **SQLite** as a zero-server option. Guard cross-engine SQL compatibility
  (migrations, native SQL) by running the test suite against each DBMS — which
  requires good test coverage first.
- **FAQ / pgvector.** Introduce a `FaqSearchInterface` with a SQL keyword-search
  fallback when pgvector is absent.
- **AI / Ollama.** Make optional.
- **Meilisearch / Redis / RabbitMQ.** A degraded mode for each: SQL `LIKE`
  search, file-based cache, synchronous processing.
- **Payment.** The `ManualGateway` (cash) already on the roadmap is the most
  relevant option for these markets.
