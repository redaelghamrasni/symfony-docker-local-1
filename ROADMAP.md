# Roadmap

Shared working memory across sessions. Two clearly separated sets: what we are
consolidating now, and a longer-term product vision we are *not* starting yet.

See `ARCHITECTURE.md` for how the system works today and `CLAUDE.md` for conventions.

---

## Immediate priority — critical-path consolidation (in progress)

Tackle in this order; each item is a step, not a parallel track.

1. **Stripe webhook.** ✅ Code-complete (2026-10-06), Unit suite green, webhook
   side validated locally with signed payloads; **not yet committed/deployed**.
   Remaining: commit, run migration on prod, configure the dashboard endpoint +
   server `STRIPE_WEBHOOK_SECRET`, and one browser test-card pass. Was the
   highest-value fix: order creation no longer depends on the browser returning
   to `/checkout/success`, so a payment whose browser never returns no longer
   leaves a charged customer with **no order**; idempotent so a duplicated event
   (or webhook + browser return) never creates two orders.
   Detailed living plan + progress: [`docs/stripe-webhook-plan.md`](docs/stripe-webhook-plan.md).
2. **CheckoutController end-to-end tests.** The most financially critical flow
   (tax → Stripe/PayPal → shipping → order) is currently untested. Do this
   *after* the webhook, since the webhook changes the order-creation logic.
3. **Route a real business message on RabbitMQ.** Messenger/worker infra is
   wired but no business message flows through it. Target: stock management /
   concurrency control.
4. **Redis cache invalidation.** The `articles`/`categories` tags are set but
   never invalidated, so the public catalog can stay stale up to 1h. Invalidate
   in the admin create/edit/delete controllers for articles and categories.
5. **Shipping-rate snapshot fallback.** ✅ Code-complete (2026-10-06), Unit suite
   green, container + entity mapping validated; **not yet committed/deployed**.
   green, container + entity mapping validated; **not yet committed/deployed**.
   Remaining: commit, run migrations `20261006120000` + `20261006130000` +
   `20261006140000` on each environment (they create the snapshot table, seed the
   baseline destinations, and seed the editable `shipping.snapshot.throttle`
   setting), add the hourly cron entry, and one live refresh run
   (`app:shipping:refresh-snapshots`, also fires backgrounded on container
   startup) once `SHIPPO_API_KEY` is set.
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
