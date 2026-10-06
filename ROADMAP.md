# Roadmap

Shared working memory across sessions. Two clearly separated sets: what we are
consolidating now, and a longer-term product vision we are *not* starting yet.

See `ARCHITECTURE.md` for how the system works today and `CLAUDE.md` for conventions.

---

## Immediate priority — critical-path consolidation (in progress)

Tackle in this order; each item is a step, not a parallel track.

1. **Stripe webhook.** Highest-value fix. Today order creation depends on the
   browser returning to `/checkout/success`; a payment whose browser never
   returns leaves a charged customer with **no order**. Add a signed Stripe
   webhook as the reliable source of truth for order creation, idempotent so a
   duplicated event (or webhook + browser return) never creates two orders.
2. **CheckoutController end-to-end tests.** The most financially critical flow
   (tax → Stripe/PayPal → shipping → order) is currently untested. Do this
   *after* the webhook, since the webhook changes the order-creation logic.
3. **Route a real business message on RabbitMQ.** Messenger/worker infra is
   wired but no business message flows through it. Target: stock management /
   concurrency control.
4. **Redis cache invalidation.** The `articles`/`categories` tags are set but
   never invalidated, so the public catalog can stay stale up to 1h. Invalidate
   in the admin create/edit/delete controllers for articles and categories.

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
