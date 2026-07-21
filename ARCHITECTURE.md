# Architecture — my-symfony-project-1

E-commerce fullstack : back-office Symfony (Twig/Stimulus/Turbo), API REST hybride (API Platform + contrôleurs manuels), authentification JWT + session, front React (SPA) monté dans le même projet. Cible marché canadien (taxes provinciales, expédition Shippo, paiement Stripe/PayPal).

> Document généré par relecture directe des fichiers de config, entités, contrôleurs, tests et de la table de routage réelle (`bin/console debug:router`) — pas de résumé de mémoire.

## Sommaire

1. [Stack technique](#1-stack-technique)
2. [Architecture globale](#2-architecture-globale)
3. [Modèle de données](#3-modèle-de-données)
4. [Fonctionnalités principales](#4-fonctionnalités-principales)
5. [Décisions de design et justifications](#5-décisions-de-design-et-justifications)
6. [Problèmes techniques rencontrés et résolutions](#6-problèmes-techniques-rencontrés-et-résolutions)
7. [Dette technique et points de vigilance actuels](#7-dette-technique-et-points-de-vigilance-actuels)
8. [Tests](#8-tests)

---

## 1. Stack technique

### Backend

| Composant | Version | Rôle |
|---|---|---|
| PHP | ≥ 8.2 (8.5.7 en environnement de dev) | Runtime |
| Symfony | 7.4.* (framework-bundle, security-bundle, etc.) | Framework |
| API Platform | ^4.3 (core 4.2 installé) | Exposition REST/JSON-LD automatique |
| Doctrine ORM | ^3.5 | Persistance |
| doctrine-bundle | ^2.18 | Intégration Symfony |
| doctrine-migrations-bundle | ^3.7 | Migrations de schéma (26 migrations à date) |
| lexik/jwt-authentication-bundle | ^3.2 | Authentification JWT pour l'API |
| knpuniversity/oauth2-client-bundle | ^2.20 | Client OAuth2 (installé, **non configuré**, voir §7) |
| predis/predis | ^3.4 | Client Redis (cache applicatif) |
| meilisearch/meilisearch-php | ^1.16 | Client moteur de recherche |
| stripe/stripe-php | ^20.0 | Paiement par carte |
| shippo/shippo-php | ^2.0 | Calcul de frais de port / étiquettes d'expédition |
| guzzlehttp/guzzle | ^7.12 | Client HTTP (utilisé par PayPalService en HTTP direct) |
| sylius/resource-bundle | ^1.14 | Utilitaires ressources (Pagerfanta pour la pagination back-office) |
| nelmio/cors-bundle | ^2.6 | CORS pour le SPA React |
| symfony/stimulus-bundle + ux-turbo | ^2.31 | Interactivité côté serveur (rendu Twig) |
| symfonycasts/tailwind-bundle | ^0.12 | Compilation Tailwind pour les gabarits Twig (AssetMapper) |
| phpunit/phpunit | ^13.1 | Tests |
| doctrine/doctrine-fixtures-bundle + fakerphp/faker | ^4.3 / ^1.24 | Données de test |

### Frontend (SPA React embarqué)

| Composant | Version | Rôle |
|---|---|---|
| React / React DOM | ^19.2.4 | UI du SPA |
| react-router-dom | ^7.13.0 | Routing côté client |
| @tanstack/react-query | ^5.90.20 | Fetching/cache de données API |
| axios | ^1.13.5 | Client HTTP vers `/api/*` |
| instantsearch.js + @meilisearch/instant-meilisearch | ^4.103 / ^0.31.2 | Recherche instantanée branchée sur Meilisearch |
| Vite | ^7.3.1 | Bundler du SPA React (indépendant de l'AssetMapper Symfony) |
| Tailwind CSS | ^4.1.18 (via @tailwindcss/postcss) | Styles du SPA |
| TypeScript | ^5.9.3 (strict, `noUnusedLocals`/`noUnusedParameters`) | Typage |

### Infrastructure (Docker Compose — `compose.yaml`)

- **MySQL 8.0** — port hôte `3307:3306`, base `symfony_database`
- **Redis 7 (alpine)** — port `6379`, persistance AOF (`--appendonly yes`)
- **Meilisearch v1.10** — port `7700`
- **Mailpit** (`axllent/mailpit`) — serveur SMTP de dev, UI web sur `8025`

Deux systèmes de build coexistent : **AssetMapper** (natif Symfony, pour Stimulus/Turbo/Tailwind des pages Twig) et **Vite** (pour le SPA React, sortie dans `public/build/`).

---

## 2. Architecture globale

```
src/
├── ApiResource/       # vide (réservé API Platform, aucune ressource "DTO" custom actuellement)
├── Command/           # commandes CLI (réindexation Meilisearch, seed d'articles)
├── Controller/         # contrôleurs web (Twig) — préfixés /{_locale} par config/routes.yaml
│   ├── Admin/          # back-office (CRUD articles/catégories/commandes/promotions/users/settings)
│   └── Api/            # contrôleurs API "manuels" (voir §7 — chevauchement avec API Platform)
├── DataFixtures/       # fixtures Doctrine (dev/test)
├── Entity/             # 14 entités Doctrine (attributs #[ORM\...])
├── EventListener/      # listeners kernel (404, locale, timezone)
├── EventSubscriber/    # subscriber sécurité (préservation panier au logout)
├── Form/               # types de formulaire Symfony (dont Form/Admin/)
├── Repository/         # requêtes custom (pagination, comptes agrégés)
├── Security/            # UserProvider custom
├── Service/            # couche métier (panier, taxes, expédition, paiement, recherche, settings)
├── Traits/             # traits partagés (ex. gestion des flash messages)
└── Twig/Extension/     # extensions Twig custom

templates/              # Twig — pages web + back-office + emails + point de montage React
assets/                 # JS/CSS gérés par AssetMapper (Stimulus) et par Vite (React, sous assets/js)
config/packages/        # configuration par bundle
config/routes/          # imports de routes (api_platform, security, sylius_resource, web_profiler)
migrations/             # 26 migrations Doctrine
tests/Unit/ tests/Functional/
```

### Patterns utilisés

- **MVC classique côté web** : contrôleurs Twig épais, entités Doctrine, pas de couche « service » systématique (la logique métier est parfois dans l'entité — ex. `Article::getEffectivePrice()` — parfois dans un service dédié — ex. `TaxService`, `ShippingService`).
- **API hybride** : une partie des ressources est exposée automatiquement par API Platform via l'attribut `#[ApiResource]` sur l'entité (actuellement seule `Article` l'utilise), le reste via des contrôleurs API manuels retournant du JSON à la main (`OrderApiController`, `Api/ArticleController`, `Api/ArticleApiController`).
- **Double authentification par firewall** : un firewall stateless JWT pour `/api`, un firewall à sessions (`form_login` + `remember_me`) pour le reste du site, un firewall JSON dédié uniquement au login API.
- **Traduction « manuelle » par entités pivot** (`ArticleTranslation`, `CategoryTranslation`) plutôt que le composant Symfony Translation, pour du contenu métier stocké en base (voir §5).
- **Event listeners à priorités explicites** pour orchestrer timezone → locale → gestion d'erreurs (voir §6).
- **Cache applicatif à tags** (Redis + `TagAwareCacheInterface`) sur le listing d'articles public (voir §5).
- **Cohabitation de deux stacks front** : rendu serveur Twig + Stimulus/Turbo pour le site marchand et le back-office, SPA React autonome (Vite) montée sur une route dédiée `/react`, consommant l'API via axios/React Query.

---

## 3. Modèle de données

14 entités Doctrine, mapping par attributs PHP (`config/packages/doctrine.yaml`, `type: attribute`, `dir: src/Entity`). Toutes les entités horodatées utilisent explicitement le fuseau `America/Toronto` dans leurs callbacks de cycle de vie (`#[ORM\PrePersist]`/`#[ORM\PreUpdate]`), plutôt que le fuseau serveur par défaut.

### Catalogue

- **Article** — champs `title`, `content`, `sku` (généré automatiquement en `PrePersist` sous la forme `ART-XXXXXXXX`), `price` (`decimal(10,2)`), `imageUrl` (image de couverture unique, historique — voir ci-dessous), relation `ManyToOne` vers `Category`, `ManyToMany` vers `Promotion`, `OneToMany` vers `ArticleImage` (galerie, ajoutée par la migration `20260716120000`). Seule entité exposée via `#[ApiResource]` (CRUD complet, groupes de sérialisation `article:read`/`article:write`).
  - `getEffectivePrice()` applique la promotion active (si toujours dans sa fenêtre `startsAt`/`endsAt`) selon son `type` : `PERCENT_OFF`, `AMOUNT_OFF`, `FIXED_PRICE`.
  - Système de traduction par lookup dans une collection `ArticleTranslation` (locale → titre/contenu), avec repli sur l'autre locale puis sur le champ de base si la traduction est absente.
- **ArticleImage** *(nouveau)* — galerie d'images multiples par article : `path` (chemin sous `/uploads/articles/`, même convention que `Article::imageUrl`), `position` (entier, ordre d'affichage), `article` (`ManyToOne`, `onDelete: CASCADE`). Triée par `#[ORM\OrderBy(['position' => 'ASC'])]` sur `Article::$images`. Gérée exclusivement depuis `Admin/ArticleController` (upload multi-fichiers, suppression, réordonnancement par échange de `position` avec le voisin) — voir §4 et §5 « Galerie d'images d'articles ».
  - ⚠️ `ArticleImage` porte un callback `#[ORM\PrePersist]` pour horodater `createdAt` (`America/Toronto`, même convention que le reste du projet) : la classe **doit** porter l'attribut `#[ORM\HasLifecycleCallbacks]`, sans quoi Doctrine n'invoque jamais le callback et l'insertion échoue (`created_at` NOT NULL en base). Erreur commise puis corrigée pendant le développement de cette fonctionnalité — vérifier cet attribut en priorité sur toute nouvelle entité utilisant un callback de cycle de vie.
- **Category** — même pattern de traduction via `CategoryTranslation` (contrainte d'unicité `(category_id, locale)`).
- **Promotion** — 3 types de réduction (`isCurrentlyActive()` vérifie `isActive` + fenêtre de dates), liée aux articles en `ManyToMany`.

### Panier / commande

- **Cart / CartItem** — panier « vivant » : `CartItem` capture le `unitPrice` de l'article au moment de l'ajout, `Cart::recalculateTotal()` est appelée à chaque mutation. Persistance en base, référencée depuis la session via un `cart_id` (voir `CartService`).
- **Order / OrderItem** — commande figée : adresses de facturation/livraison **dénormalisées** (copiées, pas de FK vers `Address`), breakdown de prix (`subtotal`, `shippingAmount`, `taxGst`/`taxPst`/`taxHst`), champs de suivi Stripe (`stripePaymentIntentId`, `paymentBrand`, `paymentLast4`) et d'expédition Shippo (`shippingCarrier`, `trackingNumber`, `shippingLabelUrl`). `status` : `pending` → `in_progress` → `shipped` → `completed` (renommé depuis `processing` par la migration `20260625000000`).

### Utilisateur

- **User** — implémente `UserInterface`/`PasswordAuthenticatedUserInterface`, `roles` en JSON (défaut `['ROLE_USER']`), `locale` (`fr`/`en`), `autoFillCheckout`. Relations `OneToMany` vers `Address` et `Order` (cascade + orphanRemoval).
- **Address** — `type` (`shipping`/`billing`), `is_default`, `province` (ajouté par la migration `20260620000000`, nécessaire pour le calcul de taxes canadiennes).
- **PasswordResetToken** — token unique, expiration à +24h (`isExpired()`), `onDelete: CASCADE` avec `User`.

### Système

- **Setting** — table clé/valeur générique pour la configuration pilotable depuis le back-office (ex. `shipping.free_threshold`, seedé par la migration `20260620000000`).

### Évolution du schéma (migrations notables)

- `20260716120000` — table `article_image` (galerie d'images multiples par article, voir ci-dessus)
- `20260703002924` — ajout du breakdown de taxes/frais sur `Order` + `User.locale`
- `20260625000000` — renommage du statut de commande `processing` → `in_progress`
- `20260620000000` — `Address.province` + table `Setting`

---

## 4. Fonctionnalités principales

- **Catalogue multilingue** (FR/EN) avec catégories, promotions et recherche Meilisearch (indexation via `MeilisearchService`/`MeilisearchReindexCommand`, filtrable sur le prix, triable prix/date).
- **Listing d'articles avec pagination infinie** (`ArticleController::list`, requêtes XHR gérées via `isXmlHttpRequest()`, cf. `templates/article/list.html.twig` — bouton « load more »), filtrage par catégorie, cache Redis à tags.
- **Page de détail article** (`templates/article/show.html.twig`) : affiche la galerie d'images (`Article::images`, ordonnée) dans un balisage prêt pour Swiper.js (`.swiper` / `.swiper-wrapper` / `.swiper-slide`), avec repli sur l'image de couverture unique `imageUrl` si la galerie est vide — voir §5.
- **Panier** persistant en session + base, préservé lors de la déconnexion.
- **Checkout complet** : calcul de taxes canadiennes par province (GST/PST/HST), calcul de frais de port via Shippo, paiement par carte (Stripe PaymentIntent) ou PayPal (Orders API v2), génération d'étiquette d'expédition.
- **Compte utilisateur** : inscription, connexion (formulaire web + JWT API), réinitialisation de mot de passe par email, historique de commandes, gestion d'adresses, auto-remplissage du checkout.
- **Back-office admin** (`ROLE_ADMIN`) : CRUD articles (avec upload d'image de couverture + galerie d'images multiples — ajouter/supprimer/réordonner, voir §5 « Galerie d'images d'articles »), catégories, promotions, utilisateurs (création, édition, réinitialisation de mot de passe et génération de mot de passe temporaire), commandes (changement de statut, informations de paiement/expédition), réglages globaux. Recherche instantanée sur les listings articles/catégories/utilisateurs/commandes (voir §5 « Recherche admin »). La barre latérale du back-office affiche le login (`app.user.username`) de l'admin connecté plutôt qu'un libellé statique.
- **API REST** : lecture publique du catalogue (`GET /api/articles`), écriture protégée par JWT, historique de commandes protégé par rôle.
- **SPA React** (`/react`) : listing d'articles côté client via React Query + axios, indépendant du rendu Twig.
- **Internationalisation** : détection de locale (session → `Accept-Language` → défaut), URLs préfixées `/{_locale}` sur tout le périmètre web.

---

## 5. Décisions de design et justifications

### Redis avec cache à tags plutôt qu'un cache simple

`config/packages/cache.yaml` configure `cache.adapter.redis` comme pool d'application par défaut. Le listing public d'articles (`ArticleController::list`, `src/Controller/ArticleController.php:38-62`) utilise `TagAwareCacheInterface` et tague chaque entrée (`articles`, `categories`) plutôt qu'un simple TTL global :

```php
$item->expiresAfter(3600);
$item->tag(['articles']);
```

**Pourquoi des tags plutôt qu'un cache simple** : le listing d'articles dépend de plusieurs sources qui varient indépendamment (liste paginée, total par catégorie, compteurs de catégories). Un cache à tags permet — en théorie — d'invalidement sélectivement toutes les entrées liées à `articles` dès qu'un article change, sans devoir gérer manuellement une liste de clés ou attendre l'expiration du TTL pour le contenu obsolète. C'est un choix pertinent pour un catalogue e-commerce où le contenu change plus vite que le TTL choisi (1h) ne le tolérerait sinon.

⚠️ Cette invalidation par tag n'est en réalité **jamais déclenchée** dans le code actuel (aucun appel à `invalidateTags()` trouvé dans `src/`) — voir §7. Le bénéfice du tagging n'est donc pas encore exploité ; le cache se comporte aujourd'hui comme un cache à TTL simple (1h) qui n'ignore les créations/éditions d'articles admin.

### JWT pour l'API plutôt que des sessions

Le projet sert deux clients différents : le site web rendu côté serveur (Twig/Stimulus/Turbo) et une API consommée par un SPA React (potentiellement sur un autre port/domaine) et par convention pour tout consommateur API tiers. `config/packages/security.yaml` définit trois firewalls dédiés :

- `api_login` (stateless, `json_login`) — échange email/mot de passe contre un token JWT
- `api` (stateless, `jwt: ~`) — toutes les autres routes `/api/*`, authentification par `Authorization: Bearer <token>`
- `main` (avec session, `form_login` + `remember_me`) — le site web classique

**Pourquoi JWT plutôt que sessions pour l'API** : un client SPA/mobile qui appelle l'API depuis un domaine ou un port différent n'a pas de cookie de session fiable (contraintes CORS/SameSite), et un serveur stateless est plus simple à faire évoluer horizontalement (pas de partage de session entre instances). Garder le firewall web en session/`remember_me` reste pertinent pour le site marchand rendu côté serveur, où les cookies fonctionnent nativement et où le SEO/premier rendu profite du rendu Twig.

⚠️ Cette séparation nette entre firewall « API stateless » et firewall « web à session » est en pratique compromise pour les contrôleurs API maison, qui ne sont pas montés sous `/api/*` réel — voir §7 (finding vérifié via `debug:router`).

### API hybride : API Platform auto-généré + contrôleurs manuels

`Article` est exposé via `#[ApiResource]` (CRUD standard généré automatiquement, JSON-LD/Hydra, documentation OpenAPI). Les autres cas d'usage (liste d'articles paginée avec filtrage catégorie optimisé/caché, commandes utilisateur) passent par des contrôleurs `Api/*` écrits à la main retournant du JSON brut.

**Pourquoi ce mélange** : API Platform apporte gratuitement la sérialisation, la pagination Hydra, la doc OpenAPI et le CRUD générique — utile pour une ressource simple comme `Article` en écriture admin. Mais dès qu'il faut une logique de lecture spécifique (cache applicatif, jointures optimisées, format de réponse personnalisé pour le SPA), un contrôleur manuel est plus direct que de configurer un `Provider`/`Processor` API Platform custom. `Order` n'est volontairement pas exposé en `#[ApiResource]` car son accès doit être filtré par utilisateur connecté (pas un CRUD générique).

⚠️ Ce choix a produit un doublon non intentionnel : deux contrôleurs (`ArticleApiController` et `Api/ArticleController`) implémentent le même besoin en parallèle — voir §7.

### Taxes canadiennes par province, Stripe + PayPal, Shippo

- **TaxService** encode les règles GST/PST/HST des 13 provinces/territoires canadiens (taux fixes en dur, pas de service externe) — pertinent car les taux changent rarement et une dépendance externe ajouterait de la latence/un point de panne pour un calcul déterministe.
- **Double moyen de paiement** (`StripeClient` injecté + `PayPalService` en HTTP direct via Guzzle) : couvre à la fois le paiement carte intégré (Stripe Elements/PaymentIntent) et les utilisateurs préférant PayPal, un besoin courant en e-commerce grand public.
- **Shippo** pour les tarifs de transporteurs et la génération d'étiquettes : évite de coder une intégration par transporteur (Postes Canada, UPS, etc.), Shippo agrège plusieurs transporteurs derrière une seule API.

### Traduction « manuelle » (entités pivot) plutôt que le composant Symfony Translation

`ArticleTranslation`/`CategoryTranslation` sont des entités Doctrine classiques avec une colonne `locale`, plutôt que d'utiliser le composant Translation (fichiers `.yaml`/`.xlf` sous `translations/`, qui reste utilisé pour les libellés d'interface statiques).

**Pourquoi** : le contenu traduit ici est du **contenu métier géré en base par les admins** (titre/description d'un article, nom d'une catégorie), pas des libellés d'UI fixes livrés avec le code. Le composant Translation est conçu pour le second cas, pas pour du contenu éditable dynamiquement ; une entité pivot permet de créer/éditer les traductions depuis le back-office et de les requêter en SQL (jointures, recherche).

### Recherche admin : Meilisearch client-side + rendu des lignes côté serveur

Chaque listing back-office avec recherche (`admin/articles`, `admin/categories`, `admin/users`, `admin/orders`) suit le même mécanisme, porté par un unique module JS générique (`assets/admin_list_search.js`, importé une fois dans `assets/app.js`) plutôt que par du JS dupliqué par page :

1. La saisie (debounce 220ms) interroge **directement depuis le navigateur** l'index Meilisearch correspondant (`articles`/`categories`/`users`/`orders`), avec la même clé master hardcodée que `assets/autocomplete.js` — pas d'appel serveur à ce stade.
2. Les identifiants renvoyés par Meilisearch (ordre de pertinence) sont ensuite envoyés au contrôleur admin existant via `?ids=1,2,3` (nouveau paramètre, en plus de `offset` pour la pagination classique d'`admin_articles_index`).
3. Le contrôleur charge les entités via un nouveau `Repository::findByIds()` (qui préserve l'ordre de pertinence de Meilisearch, pas l'ordre SQL) et rend le même partiel `_rows.html.twig` que le listing normal — les lignes affichées gardent donc les jointures (catégorie, utilisateur), le formatage de date `America/Toronto`, et les tokens CSRF des actions (édition/suppression), que Meilisearch ne connaît pas.
4. Le JS remplace le `<tbody>` avec le HTML reçu. Vider le champ de recherche refait un appel XHR classique vers la même URL (sans `ids`) pour revenir à la vue initiale — pour `admin/orders`, cette URL inclut le filtre de statut actif (`?status=...`), donc l'onglet courant est préservé après un aller-retour recherche.

**Pourquoi Meilisearch plutôt qu'une recherche SQL `LIKE`** : c'était déjà le mécanisme de recherche utilisé côté public (`templates/search/index.html.twig`, `assets/autocomplete.js`) — le choix explicite ici est la cohérence technique (un seul mécanisme de recherche dans tout le projet, un seul index à maintenir par entité) plutôt que la simplicité d'implémentation. La contrepartie : `MeilisearchService` (`src/Service/MeilisearchService.php`) a dû être généralisé (il ne servait qu'à indexer des `Article`) en un wrapper générique par nom d'index, utilisé uniquement par `MeilisearchReindexCommand`, qui réindexe désormais 4 entités au lieu d'une (voir §7 pour les limites de cette approche, notamment l'exposition de données personnelles).

### Galerie d'images d'articles : entité additive plutôt que remplacement de `imageUrl`

`ArticleImage` (voir §3) a été ajoutée **en plus** du champ `Article::imageUrl` existant, pas à sa place. `imageUrl` reste tel quel et continue d'alimenter tout ce qui affichait déjà une vignette unique (`templates/article/_cards.html.twig`, `templates/home/index.html.twig`, `templates/search/index.html.twig` côté Meilisearch, `CartController` pour le toast d'ajout au panier). La galerie n'est consommée que par la page de détail article (`templates/article/show.html.twig`), avec repli explicite sur `imageUrl` si `Article::images` est vide.

**Pourquoi ne pas avoir fusionné les deux** : migrer tous les usages existants de `imageUrl` vers une collection aurait touché de nombreux templates/services sans rapport avec le besoin exprimé (une galerie pour la fiche produit), pour un bénéfice marginal — une vignette de listing n'a pas besoin de plusieurs images. Garder les deux mécanismes séparés limite le rayon d'impact du changement, au prix d'avoir deux notions d'image sur `Article` (compromis assumé, à documenter si un futur besoin justifie une fusion).

**Formulaires de galerie hors du formulaire principal** : dans `templates/admin/articles/form.html.twig`, la carte « Galerie d'images » est rendue **après** `{{ form_end(form) }}`, avec ses propres balises `<form>` (upload, suppression, déplacement haut/bas) pointant vers des actions dédiées de `Admin/ArticleController` (`images_add`, `images_delete`, `images_move`). Nécessaire car ces actions ne font pas partie de `ArticleType` (pas de champs mappés) — les imbriquer à l'intérieur du `<form id="article-form">` existant produirait des formulaires HTML imbriqués (invalide, comportement de soumission indéfini selon les navigateurs). Le réordonnancement est volontairement un simple échange de `position` avec le voisin (boutons ▲/▼, POST + redirection), pas du drag-and-drop JS, pour rester cohérent avec le reste du back-office (formulaires classiques + redirection, pas de couche AJAX dédiée ailleurs dans l'admin Twig).

### Fuseau horaire centralisé America/Toronto

Toutes les entités horodatées fixent explicitement `new DateTimeZone('America/Toronto')` dans leurs callbacks de cycle de vie, et `TimezoneListener` fixe `date_default_timezone_set()` sur chaque requête (priorité 300, avant `LocaleSubscriber` à 2000). Cohérent avec un commerce ciblant le marché canadien, où les horodatages de commande/expédition doivent s'afficher dans le fuseau local des clients/opérateurs plutôt qu'en UTC serveur.

### Panier anonyme découplé de l'utilisateur (préservation du workflow client)

`Cart` (`src/Entity/Cart.php`) n'a **aucune relation vers `User`** — il est identifié uniquement par un `cart_id` stocké en session (`CartService::getCurrentCart()`, `src/Service/CartService.php:23-43`). Cohérent avec `access_control` de `security.yaml:67` qui laisse `^/checkout` en `PUBLIC_ACCESS` : un visiteur peut parcourir le catalogue, ajouter au panier et passer commande sans jamais créer de compte.

**Pourquoi** : forcer une inscription avant achat est un frein de conversion classique en e-commerce. Découpler le panier de l'utilisateur permet un parcours 100% anonyme, tout en gardant la possibilité de rattacher la commande à un compte a posteriori (`Order::user` reste nullable, `CheckoutController::buildOrderFromSession` appelle `$order->setUser($this->getUser())` qui vaut `null` en mode invité).

La contrepartie de ce choix (le panier vit *seulement* en session) est que toute opération qui régénère la session — typiquement la déconnexion — casse ce lien par défaut. Trois mécanismes complémentaires existent spécifiquement pour préserver la continuité du parcours d'achat malgré cette fragilité structurelle :

1. **`CartPreserveOnLogoutSubscriber`** rétablit le `cart_id` après une déconnexion (détail en §6).
2. **État du checkout persisté en session** (`CheckoutController`, clés `checkout_email`, `checkout_shipping_*`, `checkout_pi_id`, `checkout_grand_total`) : le client peut naviguer entre les étapes du checkout (adresse → frais de port → paiement) sans reperdre sa saisie, et le serveur reconstruit la commande (`buildOrderFromSession()`) à la toute fin sans redemander l'information.
3. **Pré-remplissage pour les clients connus** (`CheckoutController::index`, lignes 74-101) : si l'utilisateur est connecté, son adresse par défaut (`AddressRepository::findDefaultShippingByUser`) ou, à défaut, celle de sa dernière commande (`OrderRepository::findLastByUser`) pré-remplit le formulaire — évite de ressaisir les coordonnées à chaque achat.

⚠️ Une préférence utilisateur existe pour ce comportement (`User::autoFillCheckout`, réglable dans le profil via `Form/UserType.php:145`) mais **n'est jamais lue** par `CheckoutController::index` — voir §7.

### Reprise automatique du flow après connexion (checkout invité + admin)

Deux points d'entrée déclenchent une authentification « en cours de route » sans faire perdre au client l'endroit où il se trouvait dans son parcours — tous deux s'appuient sur le même mécanisme Symfony (`Symfony\Component\Security\Http\Util\TargetPathTrait`, qui écrit/lit l'URL cible dans la session sous une clé propre au firewall `main`), mais l'un est déclenché explicitement par du code applicatif, l'autre est natif au framework.

**Checkout invité → connexion → retour au checkout (mécanisme explicite)** : `templates/base.html.twig:333-399` définit une modale (`checkout-modal-overlay`) rendue uniquement pour les invités (`{% if not app.user %}`), déclenchée par le bouton « passer commande » du mini-panier (`base.html.twig:196`) et de la page panier (`cart/index.html.twig:156`). Elle propose deux choix : « Se connecter » (`{{ path('app_auth_login') }}?redirect={{ path('app_checkout_index')|url_encode }}`) ou « Continuer en invité » (lien direct vers `app_checkout_index`). `AuthController::login` (`src/Controller/AuthController.php:25-34`) lit `?redirect=` et appelle explicitement `$this->saveTargetPath($session, 'main', $redirect)` — c'est ce code applicatif qui force Symfony à mémoriser la destination, puisque `/checkout` étant `PUBLIC_ACCESS` (achat invité autorisé, `security.yaml:67`), le framework ne déclencherait jamais ce comportement de lui-même sur cette route.

**`/admin` → connexion → retour à la page admin demandée (comportement natif, pas de code dédié)** : `^/(fr|en)/admin` est protégé par `access_control: { roles: ROLE_ADMIN }` (`security.yaml:61`). Quand un utilisateur non authentifié (ou insuffisamment privilégié) accède à une URL admin, le firewall `main` intercepte automatiquement la requête, sauvegarde l'URL demandée dans la **même** clé de session via le même `TargetPathTrait` — mais cette fois déclenché par le framework lui-même (l'entry point du firewall `form_login`), pas par du code écrit dans le projet —, redirige vers `/login`, puis renvoie l'utilisateur vers la page admin initialement demandée une fois connecté. Aucun contrôleur `Admin/*` ne contient de logique de redirection dédiée (vérifié : aucun `IsGranted`/redirect custom hormis `SettingController`).

⚠️ Jusqu'à ce que la règle soit corrigée en `^/(fr|en)/admin` (voir §7, « `access_control` pour `/admin` neutralisé... — corrigé »), ce paragraphe décrivait un comportement **prévu mais jamais exécuté en pratique** : la règle d'origine (`^/admin`) ne matchait jamais l'URL réelle (préfixée `/{_locale}`), donc ni le blocage `ROLE_ADMIN` ni la redirection `TargetPathTrait` ne se déclenchaient jamais — n'importe qui pouvait atteindre `/fr/admin/...` sans authentification.

---

## 6. Problèmes techniques rencontrés et résolutions

Ces mécanismes, visibles dans `src/EventListener/` et `src/EventSubscriber/`, documentent des problèmes concrets résolus par des event listeners à priorité explicite.

### Ordonnancement locale / fuseau horaire / gestion d'erreurs

Trois listeners s'exécutent sur `kernel.request`/`kernel.exception` avec des priorités choisies délibérément :

- `TimezoneListener` (priorité 300 sur `KernelEvents::REQUEST`) fixe le fuseau horaire du process **avant** toute autre logique dépendant de la date.
- `LocaleSubscriber` (priorité 2000 sur `KernelEvents::REQUEST`) détermine la locale (session → `Accept-Language` → défaut `en`), en excluant explicitement les routes `/api/*` du traitement (l'API ne doit pas rediriger/negocier de locale comme le fait le site web). ⚠️ Cette exclusion ne couvre en réalité que les routes API Platform pures — voir le point 4 de « Les contrôleurs API manuels ne sont pas montés sous `/api/*` » en §7.
- `ArticleNotFoundListener` et `EntityNotFoundListener` (priorité 100 sur `KernelEvents::EXCEPTION`) interceptent respectivement les 404 sur `/article/{id}` et les `EntityNotFoundException` Doctrine (résolution d'entité par l'`EntityValueResolver`) pour afficher une page 404 cohérente au lieu de l'erreur technique brute de Symfony/Doctrine.

**Problème résolu** : sans ces listeners, une entité manquante levait une exception Doctrine brute (message technique exposé, pas de page d'erreur adaptée), et la locale/le fuseau horaire dépendaient de l'ordre d'exécution implicite des services plutôt que d'un contrat explicite.

### Perte du panier à la déconnexion

`CartPreserveOnLogoutSubscriber` répond à un problème concret : Symfony invalide/regénère la session au `LogoutEvent`, ce qui effacerait normalement le `cart_id` stocké en session. Le subscriber sauvegarde `cart_id` juste avant l'invalidation (priorité 100) et le réinjecte juste après (priorité -100), pour qu'un utilisateur connecté qui se déconnecte retrouve son panier lors de sa prochaine visite anonyme. C'est la pièce centrale d'une stratégie plus large de préservation du parcours d'achat — voir « Panier anonyme découplé de l'utilisateur » en §5 pour le contexte complet (état de checkout en session, pré-remplissage des coordonnées).

### Fiabilité du montage du `PaymentElement` Stripe

Le montage du widget de paiement (`templates/checkout/index.html.twig`, fonction `tryMountPaymentElement`) est enveloppé dans une **boucle de retry explicite** (`MAX_RETRIES`, démontage/remontage d'une instance `elements` fraîche à chaque tentative en cas d'événement `loaderror`). C'est un correctif à un problème d'échec de chargement du `PaymentElement` (latence réseau vers l'API Stripe, script chargé en `async`) : sans retry, un simple échec de chargement transitoire aurait bloqué tout le tunnel de paiement carte.

### Génération de SKU et gestion des promotions concurrentes

`Article::generateSku()` (PrePersist) garantit qu'un SKU unique est toujours présent sans dépendre d'une saisie admin. `Promotion::isCurrentlyActive()` centralise la logique de fenêtre temporelle (avec gestion des `startsAt`/`endsAt` nuls) pour que `Article::getEffectivePrice()` n'ait pas à dupliquer cette logique — utile car un article peut avoir plusieurs promotions et une seule doit s'appliquer.

---

## 7. Dette technique et points de vigilance actuels

Les éléments suivants ont été identifiés en relisant le code et **en vérifiant la table de routage réelle** (`php bin/console debug:router`). Ce sont des points à traiter, à l'exception de ceux marqués ✅ (passphrase JWT, `access_control` du back-office) qui ont été corrigés depuis :

### Les contrôleurs API manuels ne sont pas montés sous `/api/*`

`config/routes.yaml` applique un préfixe `/{_locale}` (fr|en) à **tous** les contrôleurs sous `src/Controller/`, y compris `src/Controller/Api/`. Vérifié par `debug:router` :

```
api_articles_list    GET   /{_locale}/api/articles      (ArticleApiController)
api_articles_index   GET   /{_locale}/api/articles      (Api/ArticleController)
api_orders_list      GET   /{_locale}/api/orders        (OrderApiController)
```

Conséquences concrètes :
1. **Doublon de route** : `ArticleApiController::list` et `Api/ArticleController::index` déclarent tous deux `GET /{_locale}/api/articles` — un seul est effectivement joignable (le premier enregistré), l'autre est du code mort silencieux.
2. **Ces routes ne correspondent pas au firewall stateless `api`** dont le pattern est `^/api` (donc exige un chemin qui commence littéralement par `/api`). `/{_locale}/api/articles` (ex. `/fr/api/articles`) ne matche pas ce pattern : ces contrôleurs tombent en réalité sous le firewall `main` (session/`form_login`), pas sous JWT. `OrderApiController`, censé être protégé par JWT pour un usage API/SPA, s'appuie donc en pratique sur l'authentification par session.
3. Seule la ressource `Article` exposée nativement par API Platform vit réellement sous `/api/articles` (routes `_api_/articles{._format}_*`, montées séparément via `config/routes/api_platform.yaml`, hors du préfixe locale) — c'est elle, et non les contrôleurs manuels, qui répond aux vrais appels `/api/articles` sans préfixe de langue.
4. **Le garde-fou de `LocaleSubscriber` (§6) devient inefficace pour ces routes.** Le listener exclut le traitement de locale via `str_starts_with($request->getPathInfo(), '/api')` (`src/EventListener/LocaleSubscriber.php:36-38`), pensé pour ne jamais écrire de locale en session sur un appel API stateless. Mais le chemin réel de `ArticleApiController`, `Api/ArticleController` et `OrderApiController` est `/fr/api/...` ou `/en/api/...`, qui ne commence pas par `/api` : la condition ne matche pas, et `LocaleSubscriber` traite ces requêtes comme des requêtes web normales (détection de locale + écriture en session). Seules les vraies routes API Platform (montées sans préfixe locale) sont correctement exclues par ce garde-fou. Ce n'est pas un bug isolé de `LocaleSubscriber` : c'est une conséquence supplémentaire du même préfixage global de `config/routes.yaml` évoqué au point 2.

### ✅ Passphrase JWT mal référencée — corrigé (commit `1370284`)

`config/packages/lexik_jwt_authentication.yaml` référence désormais correctement `pass_phrase: '%env(JWT_PASSPHRASE)%'`. Le bug historique (`%env(02068707)%`, une valeur collée à la place du nom de variable, qui faisait échouer en 500 la construction du firewall `api` sur toute requête `^/api` — y compris `GET /api/articles`, censé être `PUBLIC_ACCESS`) a été corrigé après la rédaction initiale de ce document. Ne pas réintroduire une valeur en dur à la place du nom de la variable d'environnement dans ce fichier.

### ✅ `access_control` pour `/admin` neutralisé par le préfixe `/{_locale}` — corrigé

Même classe de bug que le point 4 ci-dessus (préfixage global de `config/routes.yaml`), mais côté firewall web plutôt qu'API. `security.yaml` définissait `- { path: ^/admin, roles: ROLE_ADMIN }` — une règle qui ne matche jamais l'URL réelle du back-office (`/fr/admin/...` ou `/en/admin/...`). Vérifié en pratique avant correctif : `GET /fr/admin/users/` répondait `200` **sans aucune session ni cookie**, pour tous les contrôleurs `Admin/*` à l'exception de `SettingController` (seul à porter son propre `#[IsGranted('ROLE_ADMIN')]`). Le back-office entier (utilisateurs, commandes, catégories, promotions, tableau de bord) était donc consultable et modifiable sans authentification.

Corrigé en changeant la règle en `- { path: ^/(fr|en)/admin, roles: ROLE_ADMIN }` (`security.yaml:61`). Vérifié après correctif : `GET /fr/admin/users/` et `GET /en/admin/users/` redirigent désormais (`302`) vers `/fr/login` pour un client sans session, sans affecter les autres règles (`/api/*`, `/login`, `/checkout`, listing public d'articles). Ne pas revenir à `^/admin` seul — voir aussi la note dans CLAUDE.md « Zones sensibles ».

### CORS large sur `/api`

`config/packages/nelmio_cors.yaml` restreint `allow_origin` à `localhost:5173`/`localhost:8000` par défaut, mais le bloc `paths: '^/api'` autorise `allow_origin: ['*']` et `allow_headers: ['*']`. Correct en développement pour un SPA sur un port différent, mais à resserrer avant mise en production (domaines explicites).

### OAuth2 installé mais non configuré

`knpuniversity/oauth2-client-bundle` est une dépendance déclarée et `config/packages/knpu_oauth2_client.yaml` ne définit aucun client — le bundle est présent mais inerte. Pas d'impact fonctionnel actuel (aucune route ne s'appuie dessus) mais à finaliser ou retirer.

### Invalidation du cache Redis non implémentée

Comme noté en §5, les tags Redis (`articles`, `categories`) sont posés à l'écriture du cache mais jamais invalidés (aucun `invalidateTags()` dans le code, y compris dans les contrôleurs admin de création/édition d'article). Le catalogue public peut donc rester obsolète jusqu'à 1h après une modification admin.

### Gestion d'erreurs silencieuse dans le checkout

`CheckoutController` avale plusieurs exceptions sans les logguer : `catch (\Exception) {}` sur le parsing de dates d'expédition, `catch (\Throwable) {}` sur la mise à jour du montant du PaymentIntent Stripe (`updatePayment`, ligne 220-222), et `catch (\Throwable) {}` sur la récupération des détails de carte après paiement (`capturePaymentInfo`, ligne 527-530 — si `paymentIntents->retrieve()` échoue, la commande est quand même créée avec un `paymentMethod` générique `'card'`, sans marque/4 derniers chiffres). Fonctionnellement non bloquant selon les commentaires en place, mais rend le diagnostic difficile en cas d'échec silencieux.

### Absence de webhook Stripe — commande dépendante du seul retour client

Il n'existe **aucun webhook Stripe** dans le projet (aucune route de type `/stripe/webhook`, aucun appel à `Webhook::constructEvent()`). La création de la commande dépend entièrement d'un retour navigateur : `CheckoutController::success` (ligne 236-263) lit le paramètre de requête `redirect_status=succeeded` après la redirection Stripe et construit la commande à partir des données de **session**, sans revalider côté serveur que le `PaymentIntent` est effectivement `succeeded` (`capturePaymentInfo` récupère les métadonnées de carte mais ne vérifie pas le statut du paiement).

Conséquence concrète : si le navigateur se ferme, perd la connexion, ou si le client n'atteint jamais `/checkout/success` après un paiement pourtant débité côté Stripe, **aucune commande n'est créée** — un paiement « fantôme » côté client sans commande associée en base. Un webhook `payment_intent.succeeded` serait l'approche standard pour créer/confirmer la commande de façon fiable, indépendamment du parcours navigateur. À noter que le flux PayPal (`paypalCapture`) est plus robuste sur ce point précis : il vérifie explicitement `capture['status'] === 'COMPLETED'` avant de créer la commande.

### Préférence `autoFillCheckout` non appliquée

`User::autoFillCheckout` (`src/Entity/User.php:53-54`) est une préférence réglable dans le profil (`src/Form/UserType.php:145`) censée permettre à un client de désactiver le pré-remplissage automatique de son adresse au checkout. Or `CheckoutController::index` (lignes 74-101) pré-remplit systématiquement les coordonnées sans jamais appeler `$user->isAutoFillCheckout()` — le réglage existe côté modèle et formulaire mais n'a aucun effet sur le comportement réel.

### Validation métier absente sur certains champs

`Article::price` n'a pas de contrainte de validation empêchant une valeur négative (documenté explicitement par un test, `tests/Unit/Entity/ArticleTest.php::testArticlePriceCannotBeNegative`, qui constate le comportement actuel plutôt que de l'imposer).

### Recherche admin : clé Meilisearch master exposée côté client, désormais sur des données personnelles

La recherche back-office (§5 « Recherche admin ») interroge Meilisearch directement depuis le navigateur avec la clé `changeme_master_key_dev` hardcodée en JS (`assets/admin_list_search.js`, `assets/autocomplete.js`) — c'était déjà le cas côté catalogue public, mais les index `users` (prénom/nom/email) et `orders` (prénom/nom/email client) rendent maintenant des données personnelles interrogeables via cette même clé côté client, pas seulement du contenu produit public. Ce choix a été fait consciemment (voir §5) plutôt que de dupliquer une recherche SQL séparée pour l'admin ; à resserrer avant mise en production (clé API restreinte en lecture seule côté recherche, plutôt que la master key).

De plus, aucun des 4 index Meilisearch n'est resynchronisé automatiquement à la création/modification/suppression d'une entité (même limitation, déjà connue, que l'invalidation du cache Redis ci-dessous) : seul `php bin/console app:meilisearch:reindex`, lancé manuellement, met les index à jour. Un article/utilisateur/commande/catégorie créé ou modifié depuis la dernière réindexation n'apparaîtra pas dans la recherche admin tant que la commande n'a pas été relancée.

### Couverture de tests très faible (~3% des lignes)

Mesuré par un rapport de couverture Xdebug réel (détail en §8) : **1.33% des classes, 6.33% des méthodes, 3.42% des lignes** sont couvertes. Seul `TaxService` est testé de façon exhaustive (100%). Tout le reste est à 0% ou couvert seulement en effet de bord de quelques tests fonctionnels : aucune entité hormis `Article` (partiellement) et `Cart`, aucun contrôleur `Admin/*`, `CheckoutController` (le flux le plus critique financièrement — taxes, Stripe, PayPal, expédition) entièrement non testé, ainsi que `ShippingService`, `PayPalService`, `MeilisearchService`, `SettingService`, les repositories custom, et les Event Listeners en tant qu'unité isolée. La suite actuelle protège contre la régression sur le calcul de taxes et, marginalement, sur le panier — elle ne constitue pas un filet de sécurité pour le reste de l'application.

---

## 8. Tests

`phpunit.dist.xml` : deux suites (`Unit`, `Functional`), `failOnDeprecation`/`failOnNotice`/`failOnWarning` activés (build strict en principe), couverture configurée sur `src/`.

> Chiffres ci-dessous obtenus en **exécutant réellement** la suite (`php bin/phpunit`, services Docker actifs) et un rapport de couverture Xdebug (`XDEBUG_MODE=coverage php bin/phpunit --coverage-text`) — pas une estimation.

### Résultat d'exécution (état à la date de cette revue)

Le bug de passphrase JWT documenté précédemment en §7 est corrigé (commit `1370284`) : les échecs `ArticleApiTest` qu'il provoquait (`"Environment variable not found: \"02068707\""`) n'apparaissent plus. Sur cet environnement de revue, la suite échoue désormais pour une raison distincte et locale : la base de test n'est pas provisionnée correctement (`Access denied for user 'symfony_user'@'%' to database 'symfony_database_test_test'` — nom de base doublé/droits manquants, à vérifier au cas par cas selon la configuration locale de `DATABASE_URL` en environnement `test`). Ce n'est pas un bug applicatif ; ne pas le confondre avec une régression du code.

### Couverture réelle

```
Classes:  1.33% (1/75)
Methods:  6.33% (31/490)
Lines:    3.42% (110/3215)
```

Seul `TaxService` est couvert à 100% (méthodes et lignes) — c'est le seul service testé de façon exhaustive. Le reste de la couverture vient uniquement de l'effet de bord des tests fonctionnels : `CartController` (17% lignes), `Cart` entité (37%), `CartService` (38%), quelques Event Listeners touchés en passant (`LocaleSubscriber`, `TimezoneListener`, `ArticleNotFoundListener`, `EntityNotFoundListener`, tous sous 40%). Tout le reste — entités `Order`/`User`/`Promotion`/`Address`/`Setting`, contrôleurs `Admin/*` et `CheckoutController`, services `ShippingService`/`PayPalService`/`MeilisearchService`/`SettingService`, repositories custom — est à 0%. **La couverture globale (~3% de lignes) fait de cette suite un garde-fou minimal sur le calcul de taxes et le panier, pas une protection de régression pour le reste de l'application.**

### Détail par fichier

| Fichier | Type | Couvre |
|---|---|---|
| `tests/Unit/Service/TaxServiceTest.php` | Unitaire, sans mock (`TaxService` n'a pas de dépendances) | Taux GST/PST/HST par province (`#[DataProvider]` sur 5 provinces : QC/ON/AB/BC/NS), calcul de montants bout en bout avec arrondi (ex. QC : 100$ → GST 5$ + PST 9.98$ = total 114.98$), province inconnue → GST seule par défaut |
| `tests/Unit/Service/CartServiceTest.php` | Unitaire (mocks `EntityManagerInterface`/`CartRepository`/`RequestStack`/`Session`) | Création d'un panier quand la session ne contient pas de `cart_id`, panier vide initial |
| `tests/Unit/Entity/ArticleTest.php` | Unitaire | Getters/setters, **documente** (sans l'imposer) l'absence de validation sur un prix négatif — voir §7 |
| `tests/Functional/Controller/CartControllerTest.php` | Fonctionnel (`WebTestCase`, HTTP réel) | `GET /fr/cart` (200), `GET /fr/cart/add/1` (405 — méthode non autorisée), `POST /fr/cart/clear` (redirection) |
| `tests/Functional/Api/ArticleApiTest.php` | Fonctionnel (`WebTestCase`, HTTP réel) | `GET /api/articles` (attend un JSON `{data, total}` et un 200), `GET /api/articles/{id}` (200 ou 404), `POST /api/articles` sans token (attend 401) — le bug JWT qui les faisait échouer en 500 est corrigé ; peuvent encore échouer localement si la base de test n'est pas provisionnée, voir ci-dessus |

Aucun test ne couvre actuellement le checkout (taxes + paiement + expédition bout en bout), les contrôleurs admin, ni les listeners d'événements en tant qu'unité isolée.
