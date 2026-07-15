# CLAUDE.md

Voir [ARCHITECTURE.md](ARCHITECTURE.md) pour le détail complet (décisions de design, dette technique).

## Objectif du projet

E-commerce fullstack ciblant le marché canadien : catalogue multilingue (FR/EN), panier, checkout avec taxes provinciales (GST/PST/HST) et expédition (Shippo), paiement Stripe/PayPal, back-office admin, API REST, plus un SPA React embarqué.

## Stack

- **Backend** : PHP 8.2+, Symfony 7.4, API Platform 4, Doctrine ORM 3, JWT (lexik) pour l'API, sessions pour le site web.
- **Frontend web** : Twig + Stimulus/Turbo (AssetMapper), Tailwind.
- **SPA React** (`/react`) : React 19 + TypeScript + Vite + React Query + axios, bundle séparé dans `public/build/`.
- **Infra** (`compose.yaml`) : MySQL 8, Redis 7, Meilisearch, Mailpit.
- **Tests** : PHPUnit 13 (`tests/Unit`, `tests/Functional`).

## Conventions de code

- Indentation 4 espaces, LF, UTF-8 (`.editorconfig`), 2 espaces dans les fichiers `compose*.yaml`.
- PSR-4 : `App\` → `src/`, `App\Tests\` → `tests/`.
- Entités Doctrine par attributs PHP (pas d'annotations/XML/YAML).
- Toute date persistée utilise explicitement le fuseau `America/Toronto` (pas UTC serveur) — voir les callbacks `PrePersist`/`PreUpdate`.
- Traductions de contenu métier (articles, catégories) via entités pivot (`ArticleTranslation`, `CategoryTranslation`), **pas** le composant Symfony Translation (réservé aux libellés d'UI statiques).
- Pas de PHPStan/PHP-CS-Fixer configuré dans le repo — rester cohérent avec le style existant.
- Reprise du flow après login : `?redirect=<url>` sur `/login` (lu par `AuthController::login`, `src/Controller/AuthController.php:25-34`) renvoie l'utilisateur où il était (utilisé par la modale checkout invité). Pour `/admin`, ce même comportement est natif à Symfony (`access_control` + `form_login`), aucun code dédié à toucher.

## Commandes courantes

```bash
# Docker (MySQL, Redis, Meilisearch, Mailpit)
docker compose up -d

# Dépendances
composer install
npm install

# Migrations
php bin/console doctrine:migrations:migrate

# Tests (toute la suite, ou une suite précise)
php bin/phpunit
php bin/phpunit --testsuite Unit
php bin/phpunit --testsuite Functional
# ⚠️ état connu : les 4 tests de tests/Functional/Api/ArticleApiTest.php échouent (500) à
# cause du bug JWT_PASSPHRASE ci-dessous — ne pas confondre avec une régression que tu aurais introduite.

# Réindexation Meilisearch
php bin/console app:meilisearch:reindex

# Serveur web Symfony
symfony server:start   # ou: php -S localhost:8000 -t public

# SPA React (dev server Vite, port 5173, séparé du serveur Symfony)
npm run dev
npm run build           # tsc + vite build
npm run type-check
```

## Zones sensibles — ne pas modifier sans prévenir

- **`config/routes.yaml`** : applique `/{_locale}` (fr|en) à **tous** les contrôleurs sous `src/Controller/`, y compris `src/Controller/Api/`. C'est déjà la source d'un bug connu (routes API hors du firewall JWT — voir ARCHITECTURE.md §7) ; ne pas ajouter de contrôleur API sous `src/Controller/` sans vérifier son chemin réel via `bin/console debug:router`.
- **`config/packages/security.yaml`** : 3 firewalls (`api_login`, `api` stateless JWT, `main` à session). Toute route censée être protégée par JWT doit répondre sous `/api/*` **exact**, sinon elle retombe sur le firewall session.
- **`src/Entity/Order.php` / `OrderItem.php`** : les adresses et prix sont dénormalisés (snapshot au moment de la commande) volontairement — ne pas les remplacer par des FK vers `User`/`Address`/`Article` sans casser l'historique des commandes passées.
- **Cache Redis à tags** (`ArticleController::list`) : les tags `articles`/`categories` sont posés mais jamais invalidés. Si vous ajoutez une invalidation, le faire dans les contrôleurs admin (create/edit/delete article et catégorie).
- **`config/packages/lexik_jwt_authentication.yaml`** : `pass_phrase: '%env(02068707)%'` référence une variable d'environnement inexistante (faute de frappe probable pour `JWT_PASSPHRASE`). **Confirmé par les tests (4 échecs en 500)** : toute requête vers `/api/*` fait échouer la construction du firewall `api` — l'API publique elle-même est cassée, pas seulement l'auth. Priorité de correction n°1 si on touche à l'auth/API.
- **Migrations** (`migrations/`) : ne jamais éditer une migration déjà appliquée ; en créer une nouvelle.
