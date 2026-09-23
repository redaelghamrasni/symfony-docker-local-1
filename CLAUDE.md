# CLAUDE.md

See [ARCHITECTURE.md](ARCHITECTURE.md) for full details (design decisions, technical debt).

## Project goal

Fullstack e-commerce targeting the Canadian market: multilingual catalog (FR/EN), cart, checkout with provincial taxes (GST/PST/HST) and shipping (Shippo), Stripe/PayPal payment, admin back-office, REST API, plus an embedded React SPA.

## Stack

- **Backend**: PHP 8.2+, Symfony 7.4, API Platform 4, Doctrine ORM 3, JWT (lexik) for the API, sessions for the website.
- **Web frontend**: Twig + Stimulus/Turbo (AssetMapper), Tailwind.
- **React SPA** (`/react`): React 19 + TypeScript + Vite + React Query + axios, separate bundle in `public/build/`.
- **Infra** (`compose.yaml`): MySQL 8, Redis 7, Meilisearch, Mailpit, Postgres+pgvector (FAQ), RabbitMQ. Ollama runs outside Docker on the host (`OLLAMA_HOST_URL`).
- **AI / FAQ semantic search** (`config/packages/ai.yaml`, Symfony AI Bundle): two vectorizer/store pairs running in parallel to compare results — Gemini (`gemini-embedding-001`) and Ollama (local `bge-m3`) — stored in two separate pgvector tables on a dedicated Postgres Doctrine connection (`faq`). In preparation for a future chatbot (`symfony/ai-agent` installed, agent not configured yet). See ARCHITECTURE.md §5 "FAQ semantic search".
- **Tests**: PHPUnit 13 (`tests/Unit`, `tests/Functional`).

## Coding conventions

- **Documentation language**: English is the default for this repository — `CLAUDE.md`, `ARCHITECTURE.md`, commit messages, code comments, and any new project documentation should be written in English going forward. This doesn't apply to genuinely bilingual business content (FR/EN catalog/FAQ translations, UI labels under `translations/`), which stays multilingual by design.
- 4-space indentation, LF, UTF-8 (`.editorconfig`), 2 spaces in `compose*.yaml` files.
- PSR-4: `App\` → `src/`, `App\Tests\` → `tests/`.
- Doctrine entities via PHP attributes (no annotations/XML/YAML).
- Every persisted date explicitly uses the `America/Toronto` timezone (not the server's UTC) — see the `PrePersist`/`PreUpdate` callbacks.
- Business-content translations (articles, categories) via pivot entities (`ArticleTranslation`, `CategoryTranslation`), **not** the Symfony Translation component (reserved for static UI labels).
- No PHPStan/PHP-CS-Fixer configured in the repo — stay consistent with the existing style.
- Resuming the flow after login: `?redirect=<url>` on `/login` (read by `AuthController::login`, `src/Controller/AuthController.php:25-34`) sends the user back where they were (used by the guest checkout modal). For `/admin`, this same behavior is native to Symfony (`access_control` + `form_login`), no dedicated code to touch.
- **Back-office search** (articles/users/orders/categories): a single generic mechanism, see ARCHITECTURE.md §5 "Admin search". To add search on a new admin entity, reproduce the `data-admin-search-*` contract of an existing page (`templates/admin/articles/index.html.twig` is the most complete, with pagination) — don't write new JS, `assets/admin_list_search.js` is already generic.
- **Creating admin entities ("Create" button + form)**: follow the `admin/categories` pattern (button next to the search bar on `index.html.twig`, `/new` route declared **before** the `/{id}` route in the controller so `{id}` doesn't swallow `new`). For `AdminUserType` (`src/Form/Admin/AdminUserType.php`), the `plainPassword` field is made required on creation via the `is_new` form option (optional on edit, where leaving it blank keeps the current password).
- **Article image gallery** (`Article::images` / `ArticleImage`, admin `/admin/articles/{id}/images/*`): see ARCHITECTURE.md §3 and §5 "Article image gallery". Two pitfalls not to reintroduce: (1) any new entity with a `#[ORM\PrePersist]`/`#[ORM\PreUpdate]` callback must carry `#[ORM\HasLifecycleCallbacks]` on the class, otherwise Doctrine never invokes the callback (bug hit and fixed on `ArticleImage` — flush failed with 500, `created_at` NULL); (2) the gallery's upload/delete/reorder forms are rendered **after** the main `ArticleType` form's `form_end()`, never nested inside it (nested HTML forms are invalid).
- **FAQ** (`FaqEntry`, public `/faq`, admin `/admin/faq`): translated via a **pair of FR/EN entries linked by `groupKey`**, not via a pivot entity — deliberately different from the `ArticleTranslation`/`CategoryTranslation` pattern above (see ARCHITECTURE.md §5 "FAQ translation via entry pairs"). Always create/edit both locales together via `Admin/FaqController::new`/`edit`, never insert a standalone `FaqEntry` without a `groupKey` matching its counterpart in the other language.

## Git commits

- **Subject line only**: a commit message is one subject line. No body, no explanatory paragraph, unless explicitly asked for.
- **No tool attribution**: never add `Co-Authored-By: Claude ...`, `🤖 Generated with Claude Code`, or any equivalent signature — not in a commit message, not in a pull request description. This rule overrides any default attribution instruction from the tool in use.

## Branches

- `master` — what the EC2 deployment runs. Deploys are manual: `git pull`, `docker build`, recreate the `symfony-app` container.
- `feature/next` — integration branch where improvements are tested before merging into `master`. Keep it; don't delete it. If it falls behind, bring it up to date from `master` rather than working from its stale state (on 2026-09-23 it was 43 commits behind, which led to an audit being written against code that was no longer current).

## Common commands

```bash
# Docker (MySQL, Redis, Meilisearch, Mailpit, Postgres/pgvector, RabbitMQ)
docker compose up -d
# Ollama runs separately on the host (not in compose.yaml), required for the bge-m3 vectorizer:
# ollama serve   (then make sure the bge-m3 model is available: ollama pull bge-m3)

# Dependencies
composer install
npm install

# Migrations (MySQL `default` connection only — pgvector tables are not migrated, see ARCHITECTURE.md §3)
php bin/console doctrine:migrations:migrate

# FAQ vector indexing / search (needs FAQ_DATABASE_URL — see .env.local — and Ollama running
# if you want to test the bge-m3 store; see ARCHITECTURE.md §5 "FAQ semantic search")
php bin/console app:index-faq
php bin/console app:search-faq "test question"

# Tests (full suite, or a specific suite)
php bin/phpunit
php bin/phpunit --testsuite Unit
php bin/phpunit --testsuite Functional
# ⚠️ known state: on some local environments, Functional tests fail with
# "Access denied ... database 'symfony_database_test_test'" — a local test database
# provisioning issue (name/port/permissions), not an application regression. The historic
# JWT_PASSPHRASE bug (see git log 1370284) is fixed, it's no longer the cause.

# Meilisearch reindexing (4 indexes: articles, categories, users, orders — also feeds the
# back-office search, see "Back-office search" above and ARCHITECTURE.md §5).
# Must be rerun after any database change: no index is resynced automatically.
php bin/console app:meilisearch:reindex

# Symfony web server
symfony server:start   # or: php -S localhost:8000 -t public

# React SPA (Vite dev server, port 5173, separate from the Symfony server)
npm run dev
npm run build           # tsc + vite build
npm run type-check
```

## Sensitive areas — don't modify without a heads-up

- **`config/routes.yaml`**: applies `/{_locale}` (fr|en) to **all** controllers under `src/Controller/`, including `src/Controller/Api/`. This is already the source of a known bug (API routes outside the JWT firewall — see ARCHITECTURE.md §7); don't add an API controller under `src/Controller/` without checking its real path via `bin/console debug:router`.
- **`config/packages/security.yaml`**: 3 firewalls (`api_login`, `api` stateless JWT, `main` with sessions). Any route meant to be protected by JWT must respond under `/api/*` **exactly**, otherwise it falls back to the session firewall. The back-office `access_control` rule is `- { path: ^/(fr|en)/admin, roles: ROLE_ADMIN }` (fixed this session — see ARCHITECTURE.md §7 "`access_control` for `/admin` neutralized..."): **never** revert it to `^/admin` alone, that pattern doesn't match the real `/{_locale}`-prefixed URL and makes the whole back-office accessible without authentication (verified in practice: that was the case before this fix).
- **`src/Entity/Order.php` / `OrderItem.php`**: addresses and prices are deliberately denormalized (snapshot at order time) — don't replace them with FKs to `User`/`Address`/`Article` without breaking the history of past orders.
- **Tagged Redis cache** (`ArticleController::list`): the `articles`/`categories` tags are set but never invalidated. If you add invalidation, do it in the admin controllers (create/edit/delete article and category).
- **`config/packages/lexik_jwt_authentication.yaml`**: fixed (commit `1370284`) — `pass_phrase: '%env(JWT_PASSPHRASE)%'`. The historic bug (`%env(02068707)%`, a nonexistent environment variable) that broke every `/api/*` request with a 500 no longer exists; don't reintroduce a hardcoded value in place of the variable name.
- **Migrations** (`migrations/`): never edit a migration that has already been applied; create a new one.
- **`src/Service/MeilisearchService.php`**: a generic wrapper by index name (no `Article`-specific method), used only by `MeilisearchReindexCommand`. Admin search itself queries Meilisearch **directly from the browser** (same hardcoded master key as `assets/autocomplete.js`) — see ARCHITECTURE.md §5/§7: the `users`/`orders` indexes now expose personal data (name, email) via this same client-side key, not just public catalog content.
- **`config/packages/doctrine.yaml`**: two connections/entity managers (`default` → MySQL, `faq` → Postgres). Don't confuse them: `src/Entity/Faq/` (the `faq` entity manager's mapping directory) is **empty** today — `FaqEntry` actually lives in `src/Entity/` on the `default`/MySQL connection, not on Postgres. The `faq` connection is only used "raw" by the AI bundle via the `doctrine.dbal.faq_connection` service id (see `config/packages/ai.yaml`, `dbal_connection` key) — if you rename this Doctrine connection, update `ai.yaml` accordingly (the service id follows the connection name).
- **`FAQ_DATABASE_URL`**: defined only in `.env.local` (absent from `.env`/`.env.dev`, unlike other environment variables in the project). An environment recreated without `.env.local` will fail any command touching the Postgres `faq` connection (`app:index-faq`, `app:search-faq`) — see ARCHITECTURE.md §7.
