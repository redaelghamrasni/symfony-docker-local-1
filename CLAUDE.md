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
- **Recherche back-office** (articles/utilisateurs/commandes/catégories) : un seul mécanisme générique, voir ARCHITECTURE.md §5 « Recherche admin ». Pour ajouter une recherche sur une nouvelle entité admin, reproduire le contrat `data-admin-search-*` d'une page existante (`templates/admin/articles/index.html.twig` est la plus complète, avec pagination) — ne pas écrire de nouveau JS, `assets/admin_list_search.js` est déjà générique.
- **Création d'entités admin (bouton « Créer » + formulaire)** : suivre le pattern `admin/categories` (bouton à côté de la barre de recherche sur `index.html.twig`, route `/new` déclarée **avant** la route `/{id}` dans le contrôleur pour éviter que `{id}` n'avale `new`). Pour `AdminUserType` (`src/Form/Admin/AdminUserType.php`), le champ `plainPassword` est rendu obligatoire à la création via l'option de formulaire `is_new` (facultative en édition, où laisser vide conserve le mot de passe actuel).
- **Galerie d'images d'articles** (`Article::images` / `ArticleImage`, admin `/admin/articles/{id}/images/*`) : voir ARCHITECTURE.md §3 et §5 « Galerie d'images d'articles ». Deux pièges à ne pas réintroduire : (1) toute nouvelle entité avec un callback `#[ORM\PrePersist]`/`#[ORM\PreUpdate]` doit porter `#[ORM\HasLifecycleCallbacks]` sur la classe, sinon le callback n'est jamais invoqué (bug rencontré et corrigé sur `ArticleImage` — flush en 500, `created_at` NULL) ; (2) les formulaires d'upload/suppression/réordonnancement de la galerie sont rendus **après** `form_end()` du formulaire principal d'`ArticleType`, jamais imbriqués dedans (formulaires HTML imbriqués invalides).

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
# ⚠️ état connu : sur certains environnements locaux, les tests Functional échouent avec
# "Access denied ... database 'symfony_database_test_test'" — problème de provisioning de
# la base de test locale (nom/port/droits), pas une régression applicative. Le bug
# JWT_PASSPHRASE historique (voir git log 1370284) est corrigé, ce n'est plus la cause.

# Réindexation Meilisearch (4 index : articles, categories, users, orders — alimente aussi
# la recherche back-office, voir "Recherche back-office" ci-dessous et ARCHITECTURE.md §5).
# À relancer après toute modification en base : aucun index n'est resynchronisé automatiquement.
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
- **`config/packages/security.yaml`** : 3 firewalls (`api_login`, `api` stateless JWT, `main` à session). Toute route censée être protégée par JWT doit répondre sous `/api/*` **exact**, sinon elle retombe sur le firewall session. La règle `access_control` du back-office est `- { path: ^/(fr|en)/admin, roles: ROLE_ADMIN }` (corrigée cette session — voir ARCHITECTURE.md §7 « `access_control` pour `/admin` neutralisé... ») : ne **jamais** la repasser à `^/admin` seul, ce pattern ne matche pas l'URL réelle préfixée `/{_locale}` et rend tout le back-office accessible sans authentification (vérifié en pratique : c'était le cas avant ce correctif).
- **`src/Entity/Order.php` / `OrderItem.php`** : les adresses et prix sont dénormalisés (snapshot au moment de la commande) volontairement — ne pas les remplacer par des FK vers `User`/`Address`/`Article` sans casser l'historique des commandes passées.
- **Cache Redis à tags** (`ArticleController::list`) : les tags `articles`/`categories` sont posés mais jamais invalidés. Si vous ajoutez une invalidation, le faire dans les contrôleurs admin (create/edit/delete article et catégorie).
- **`config/packages/lexik_jwt_authentication.yaml`** : corrigé (commit `1370284`) — `pass_phrase: '%env(JWT_PASSPHRASE)%'`. Le bug historique (`%env(02068707)%`, variable d'environnement inexistante) qui cassait toute requête `/api/*` en 500 n'existe plus ; ne pas réintroduire une valeur en dur à la place du nom de variable.
- **Migrations** (`migrations/`) : ne jamais éditer une migration déjà appliquée ; en créer une nouvelle.
- **`src/Service/MeilisearchService.php`** : wrapper générique par nom d'index (pas de méthode spécifique à `Article`), utilisé uniquement par `MeilisearchReindexCommand`. La recherche admin, elle, interroge Meilisearch **directement depuis le navigateur** (même clé master hardcodée que `assets/autocomplete.js`) — voir ARCHITECTURE.md §5/§7 : les index `users`/`orders` exposent des données personnelles (nom, email) via cette clé côté client, pas seulement du contenu catalogue public.
