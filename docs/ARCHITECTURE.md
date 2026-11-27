# Hair_style by Garance — Dossier d'architecture

> Version 1.0 — 26/11/2026 — **à valider avant développement**
> Document de référence fonctionnelle : [PROJET_HAIR_STYLE_BY_GARANCE.md](PROJET_HAIR_STYLE_BY_GARANCE.md)

Sommaire

1. [Analyse fonctionnelle et technique](#1-analyse-fonctionnelle-et-technique)
2. [Architecture MVC](#2-architecture-mvc)
3. [Schéma PostgreSQL](#3-schéma-postgresql)
4. [Système de templates et de personnalisation](#4-système-de-templates-et-de-personnalisation)
5. [Structure des dossiers et fichiers](#5-structure-des-dossiers-et-fichiers)
6. [Plan de développement par étapes](#6-plan-de-développement-par-étapes)
7. [Points à valider](#7-points-à-valider)

---

## 1. Analyse fonctionnelle et technique

### 1.1 Acteurs

| Acteur | Description | Accès |
|---|---|---|
| Visiteur | Consulte le site, la grille de tarifs, la galerie, le blog | Public |
| Cliente invitée | Réserve sans compte (nom, téléphone, e-mail) | Public + lien de gestion du RDV par e-mail |
| Cliente inscrite | Réserve, consulte et modifie ses RDV, favoris, historique | `/compte` |
| Administratrice (Garance) | Gère tout le site, les RDV, le contenu, l'apparence | `/admin` |
| Collaborateur (optionnel) | Accès restreint (ex. RDV et clients uniquement) | `/admin`, selon permissions |
| Tâches planifiées (cron) | Rappels de RDV, envoi de la file d'e-mails, nettoyage | CLI |

### 1.2 Modules et dépendances

```
                    ┌──────────────┐
                    │  Socle (Core)│  routeur, conteneur, DB, vues, erreurs, config
                    └──────┬───────┘
          ┌────────────────┼─────────────────┬──────────────────┐
   ┌──────▼──────┐  ┌──────▼──────┐   ┌──────▼──────┐    ┌──────▼──────┐
   │ Auth & rôles│  │  Réglages   │   │   Médias    │    │  Templates  │
   └──────┬──────┘  └──────┬──────┘   └──────┬──────┘    │  (rendu)    │
          │                │                 │           └──────┬──────┘
   ┌──────▼─────────────────▼─────────────────▼──────────────────▼──────┐
   │                Catalogue (catégories, prestations,                  │
   │             tailles, longueurs, grille de prix, options)            │
   └──────┬───────────────────────────────┬─────────────────────────────┘
   ┌──────▼──────┐                 ┌──────▼──────┐
   │  Horaires & │                 │ Promotions  │
   │disponibilités│                └──────┬──────┘
   └──────┬──────┘                        │
   ┌──────▼──────────────────────────────▼──┐      ┌──────────────┐
   │              Réservations               ├─────►│ Notifications│
   └──────┬──────────────────────────────────┘      └──────────────┘
   ┌──────▼──────┐  ┌────────────┐  ┌──────────┐  ┌──────────┐  ┌────────────┐
   │Espace client│  │  Clients   │  │  Avis    │  │ CMS pages│  │ Blog       │
   └─────────────┘  └────────────┘  └──────────┘  │ & sections│  └────────────┘
                                                  └──────────┘
   Transverses : Dashboard (lit tous les modules), SEO, Audit, Contact
```

| Module | Dépend de | Rôle |
|---|---|---|
| Core | — | Requête/réponse, routage, middlewares, conteneur, PDO, Twig, logs, erreurs |
| Auth | Core | Connexion, rôles/permissions, reset mot de passe, limitation des tentatives |
| Settings | Core | Paramètres du site (clé/valeur JSON) mis en cache |
| Media | Core, Auth | Upload contrôlé, dossiers, variantes d'images (miniature, moyenne, WebP) |
| Templates | Core, Settings, Media | Résolution du template actif, jetons de design, rendu des sections |
| Catalog | Media | Catégories, prestations, grille taille × longueur, options |
| Schedule | Settings | Horaires, pauses, fermetures, congés, créneaux bloqués |
| Availability | Schedule, Catalog, Bookings | Calcul des créneaux libres |
| Bookings | Catalog, Availability, Customers | Parcours, transactions, statuts, annulation |
| Notifications | Bookings, Settings | File d'envoi e-mail, rappels, liens WhatsApp |
| Customers | Auth | Fiches clientes (avec ou sans compte), notes internes |
| Account | Auth, Bookings | Espace cliente |
| CMS | Templates, Media | Pages, sections, brouillon/publication, aperçu |
| Gallery | Media, Catalog | Portfolio, filtres, avant/après |
| Reviews | Customers, Bookings | Dépôt et modération des avis |
| Promotions | Catalog | Réductions sur période |
| Blog | Media | Articles, catégories, tags, programmation |
| Dashboard | Tous | Indicateurs et graphiques |
| SEO | CMS, Catalog, Blog | Méta, Open Graph, sitemap, robots |

### 1.3 Parcours clés

**Réservation depuis la grille de tarifs**

```
Grille tarifs ──► choix coiffure ► taille ► longueur ► finition / entretien
      │                                                   │
      └──────────────► récapitulatif (total estimé) ◄─────┘
                               │ « Réserver »
                               ▼
   /reserver?service=knotless&variant=12&length=5&options=3,7
   Étape 2 date ► Étape 3-4 créneau ► Étape 5 coordonnées + règles cochées ► Étape 6 confirmation
                               │
                               ▼
   Transaction PostgreSQL ► statut « En attente » (ou « Confirmée » si auto-confirmation activée)
                               │
                               ▼
   E-mail de confirmation (+ lien de gestion du RDV) ► rappel J-1 (cron)
```

**Calcul de la durée d'un RDV** : durée de la cellule taille × longueur si renseignée, sinon durée de la variante, sinon durée de la prestation, puis + durée des options (ex. défaisage). Le créneau bloqué = durée totale + temps tampon (paramètre).

**Prix « sur devis »** : la réservation est enregistrée « En attente », le total estimé est `NULL`, l'admin saisit le prix final lors de la confirmation.

### 1.4 Règles métier principales

| Règle | Mise en œuvre |
|---|---|
| Pas de double réservation | Contrainte d'exclusion PostgreSQL sur la plage horaire + verrou transactionnel (§ 3.6) |
| Respect des horaires, pauses, congés | Moteur de disponibilités : intervalles d'ouverture − pauses − fermetures − créneaux bloqués − RDV existants |
| Délai minimum de réservation | Paramètre (ex. 12 h) : pas de créneau avant `now() + délai` |
| Horizon de réservation | Paramètre (ex. 60 jours) |
| Annulation en ligne | Autorisée jusqu'à `starts_at − 48 h` (paramètre), ensuite contact obligatoire |
| Une seule finition | Groupe d'options en sélection « single » |
| Promotions expirées masquées | Filtre `now() BETWEEN starts_at AND ends_at AND is_active` |
| Avis modérés | Statut `pending` par défaut, seul `approved` est public |
| Prix figé à la réservation | Copie du libellé et du prix dans la réservation (instantané) |
| Adresse privée si domicile | L'adresse exacte n'est injectée que dans les messages de confirmation |

### 1.5 Exigences non fonctionnelles

| Domaine | Exigence |
|---|---|
| Hébergement | Serveur PHP classique (Apache + `.htaccess` ou Nginx), sans Node en production |
| Performance | Pages publiques < 1 s côté serveur, cache des réglages et du CSS de thème, images WebP + lazy loading |
| Sécurité | OWASP Top 10 : requêtes préparées, CSRF, échappement automatique Twig, CSP, sessions durcies, upload contrôlé |
| Accessibilité | Contrastes WCAG AA, navigation clavier, `prefers-reduced-motion`, textes alternatifs obligatoires sur les médias |
| RGPD | Données clientes minimales, consentement, export et suppression d'une cliente, journal des accès sensibles |
| Maintenabilité | PSR-4, PSR-12, typage strict, tests PHPUnit sur les services métier |
| Langue / fuseau | Français, `Europe/Paris`, montants en euros (`numeric(10,2)`) |

### 1.6 Choix techniques

| Besoin | Choix | Justification |
|---|---|---|
| Langage | **PHP 8.2 minimum** (développement en 8.6) | Exigé ; enums, readonly, typage |
| Base de données | **PostgreSQL 15+** (développement en 18) avec `btree_gist`, `citext` | Contrainte d'exclusion anti-chevauchement, JSONB, e-mails insensibles à la casse |
| Accès DB | **PDO** + Repositories maison | Exigé ; requêtes préparées, pas d'ORM lourd |
| Routage | `nikic/fast-route` | Léger, rapide, éprouvé |
| Conteneur DI | `php-di/php-di` | Autowiring, contrôleurs testables |
| Vues | **`twig/twig`** | Échappement automatique (XSS), héritage de gabarits : idéal pour les templates interchangeables |
| Config | `vlucas/phpdotenv` | Fichier `.env` |
| Validation | Validateurs maison + `respect/validation` | Validation serveur explicite par formulaire |
| Logs | `monolog/monolog` | Journal des erreurs |
| E-mails | `symfony/mailer` | SMTP, gabarits Twig |
| Images | `intervention/image` (GD) | Redimensionnement, WebP |
| HTML riche | `ezyang/htmlpurifier` | Nettoyage du contenu des articles et sections riches |
| Tests | `phpunit/phpunit` | Tests unitaires et d'intégration (base de test) |
| Front public | CSS maison par template, **variables CSS** alimentées par les réglages ; JS vanilla (modules ES) | Pas de build obligatoire, thème modifiable sans recompilation |
| Front admin | **Bootstrap 5** (CDN ou copie locale) | Composants prêts (tableaux, modales, formulaires), rapide à développer |
| Calendrier admin | FullCalendar 6 | Vues jour / semaine / mois |
| Graphiques | Chart.js 4 | Dashboard |
| Glisser-déposer | SortableJS | Réordonner sections, catégories, galerie |
| Éditeur riche | Quill 2 | Licence MIT, autonome |
| Appels asynchrones | Fetch API + endpoints JSON `/api/...` | Créneaux, grille de prix, actions admin |

> Bootstrap 5 a été préféré à Tailwind pour éviter une étape de compilation : le site doit pouvoir être modifié et déployé sur un hébergement PHP classique.

### 1.7 Prérequis de développement

État constaté sur ce poste le 26/11/2026 :

| Outil | Emplacement | État |
|---|---|---|
| PostgreSQL **18.1** | `C:\Program Files\PostgreSQL\18` | Service `postgresql-x64-18` démarré ; extensions `btree_gist` et `citext` disponibles |
| PHP **8.6.0** (NTS) | `C:\php` | **Aucun `php.ini` chargé** : extensions présentes mais non activées |
| PHP 8.2.12 (XAMPP) | `C:\xampp\php` | `php.ini` chargé, mais `pdo_pgsql`, `gd`, `intl` et `sodium` ne sont pas activées |
| Composer | — | **Non trouvé** |
| Node, Git | dans le PATH | OK (Node n'est pas nécessaire au projet) |
| PHP, psql | — | **Absents du PATH** |

Actions à faire avant l'étape 0 :

1. Utiliser **PHP 8.6 de `C:\php`** : copier `php.ini-development` en `php.ini`, puis activer `extension_dir = "ext"` et les extensions `pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `gd`, `fileinfo`, `sodium`, `openssl`.
2. Ajouter `C:\php` et `C:\Program Files\PostgreSQL\18\bin` au PATH.
3. Installer **Composer 2**.
4. Créer la base `hairstyle_garance` et un utilisateur dédié (pas le superutilisateur `postgres`).

La cible minimale reste PHP 8.2 / PostgreSQL 15 pour la production. Le serveur intégré `php -S localhost:8000 -t public` suffit pour développer.

---

## 2. Architecture MVC

### 2.1 Cycle d'une requête

```
navigateur
   │
   ▼
public/index.php ── bootstrap/app.php (env, conteneur, gestion d'erreurs)
   │
   ▼
Kernel ──► Pipeline de middlewares
            ├─ SecurityHeaders (CSP, X-Frame-Options, …)
            ├─ StartSession (cookie HttpOnly, Secure, SameSite=Lax)
            ├─ VerifyCsrfToken (POST/PUT/PATCH/DELETE)
            ├─ Authenticate / RequireRole / RequirePermission (routes protégées)
            ├─ ThrottleRequests (connexion, réservation, contact)
            └─ PreviewMode (aperçu des brouillons pour l'admin)
   │
   ▼
Router (FastRoute) ──► Controller::action(Request)
                          │  valide l'entrée (Validator)
                          │  appelle un ou plusieurs Services (logique métier)
                          │        └─ Repositories (SQL via PDO) ─► PostgreSQL
                          ▼
                    Response : HTML (Twig) | JSON | Redirect | Fichier
```

### 2.2 Rôle des couches

| Couche | Responsabilité | Ne fait pas |
|---|---|---|
| **Controllers** | Lire la requête, appeler le validateur et les services, choisir la réponse | Pas de SQL, pas de règle métier |
| **Validators** | Règles de saisie par formulaire, messages d'erreur en français | Pas d'accès à la base (sauf règles « unique » via repository) |
| **Services** | Logique métier : calcul de prix, disponibilités, création de RDV, publication | Pas de HTML, pas de `$_POST` |
| **Repositories** | Requêtes SQL préparées, hydratation en modèles | Pas de règle métier |
| **Models** | Objets de données typés (readonly) + petites méthodes métier (`Booking::canBeCancelledBy()`) | Pas de persistance active |
| **Middleware** | Préoccupations transverses (session, CSRF, droits) | — |
| **Views** | Twig : `resources/views` (admin, compte, e-mails) et `templates/<slug>` (site public) | Pas de logique au-delà de l'affichage |

> Écart volontaire avec la structure indicative du cahier des charges : les vues ne sont pas dans `app/Views` mais dans `resources/views` (admin, e-mails) et `templates/` (site public). Ça évite d'avoir deux dossiers de vues.

### 2.3 Exemple de découpage : création d'une réservation

```
POST /reserver                       (BookingController::store)
  ├─ BookingRequestValidator         champs, format téléphone, règles cochées
  ├─ PricingService::quote()         prix + durée depuis la grille et les options
  ├─ CustomerService::findOrCreate() cliente invitée ou connectée
  ├─ BookingService::create()        transaction + verrou + insertion
  │     ├─ AvailabilityService::assertSlotIsFree()
  │     └─ BookingRepository::insert()   (contrainte d'exclusion = filet de sécurité)
  ├─ NotificationService::queue('booking.created')
  └─ redirect /reserver/confirmation/{reference}
```

### 2.4 Routes (extraits)

| Fichier | Préfixe | Exemples |
|---|---|---|
| `routes/web.php` | `/` | `/`, `/prestations`, `/prestations/{slug}`, `/tarifs`, `/galerie`, `/blog/{slug}`, `/reserver`, `/contact`, `/{page-slug}` |
| `routes/account.php` | `/compte` | `/compte/connexion`, `/compte/reservations`, `/compte/favoris`, `/rdv/{token}` (gestion sans compte) |
| `routes/admin.php` | `/admin` | `/admin`, `/admin/reservations`, `/admin/prestations/{id}/grille`, `/admin/apparence`, `/admin/pages/{id}/sections` |
| `routes/api.php` | `/api` | `GET /api/disponibilites?service=…&date=…`, `GET /api/tarifs/{service}`, `POST /api/admin/sections/ordre` |

### 2.5 Tâches planifiées (CLI)

`bin/console` (petite application console maison) :

| Commande | Fréquence | Rôle |
|---|---|---|
| `notifications:send` | toutes les 5 min | Envoie la file d'e-mails en attente |
| `bookings:remind` | toutes les heures | Met en file les rappels J-1 |
| `blog:publish-scheduled` | toutes les 15 min | Publie les articles programmés |
| `migrate` / `migrate:rollback` / `db:seed` | à la demande | Migrations et données de démo |
| `templates:publish` | au déploiement | Copie les assets des templates vers `public/themes/` |
| `cache:clear` | à la demande | Vide le cache des réglages et du CSS de thème |

---

## 3. Schéma PostgreSQL

### 3.1 Conventions

- Clés primaires `bigint GENERATED ALWAYS AS IDENTITY`.
- Horodatage `timestamptz` (`created_at`, `updated_at` avec trigger `set_updated_at()`).
- Montants `numeric(10,2)`, toujours en euros.
- E-mails en `citext` (unicité insensible à la casse).
- Énumérations : `text` + contrainte `CHECK` (plus simples à faire évoluer que les types `ENUM`).
- Contenus flexibles (sections, réglages de thème) en `jsonb`.
- Suppression : `ON DELETE RESTRICT` par défaut, `CASCADE` uniquement pour les tables d'association et les enfants stricts.
- Extensions : `btree_gist`, `citext`.

### 3.2 Vue d'ensemble des relations

```
users ─┬─< user_roles >─ roles ─< role_permissions >─ permissions
       └── customers (0..1) ─┬─< bookings >── services ──> service_categories
                             ├─< customer_notes                │
                             ├─< favorites >── services        ├─< service_variants ──> service_sizes
                             └─< reviews                       │        └─< variant_prices ──> service_lengths
                                                               ├─< service_images ──> media
bookings ─< booking_options >── service_options ──> option_groups ─< option_group_assignments
bookings ──> booking_statuses                                       (catégorie ou prestation)
promotions ─< promotion_services >── services
pages ─< page_sections          templates ─< template_settings        site_settings (clé/valeur)
blog_posts ──> blog_categories ; blog_posts ─< blog_post_tags >── blog_tags
media ──> media_folders         opening_hours ; blocked_dates ; blocked_slots ; special_hours
notifications ; contact_messages ; audit_logs ; login_attempts ; password_resets ; gallery_items
```

### 3.3 Domaine Utilisateurs et sécurité

| Table | Colonnes principales | Contraintes / index |
|---|---|---|
| **users** | id, email `citext`, password_hash, first_name, last_name, phone, is_active, email_verified_at, last_login_at, created_at, updated_at | `UNIQUE(email)` |
| **roles** | id, code (`admin`, `staff`, `customer`), label | `UNIQUE(code)` |
| **permissions** | id, code (`bookings.manage`, `content.edit`, `settings.edit`, …), label | `UNIQUE(code)` |
| **role_permissions** | role_id, permission_id | PK composite, `CASCADE` |
| **user_roles** | user_id, role_id | PK composite, `CASCADE` |
| **password_resets** | id, user_id, token_hash, expires_at, used_at | index `(token_hash)` ; jeton stocké haché |
| **login_attempts** | id, email `citext`, ip `inet`, succeeded, attempted_at | index `(email, attempted_at)`, `(ip, attempted_at)` |
| **audit_logs** | id, user_id NULL, action, entity_type, entity_id, changes `jsonb`, ip `inet`, user_agent, created_at | index `(entity_type, entity_id)`, `(created_at)` |

> `role_permissions`, `password_resets` et `login_attempts` complètent la liste minimale du cahier des charges : ils sont nécessaires aux rôles, à la réinitialisation et à la limitation des tentatives.

### 3.4 Domaine Catalogue

| Table | Colonnes principales | Contraintes / index |
|---|---|---|
| **service_categories** | id, name, slug, description, header_note, image_media_id, position, is_active | `UNIQUE(slug)` |
| **services** | id, category_id, name, slug, summary, description (HTML purifié), pricing_mode (`matrix`/`simple`), price_kind (`fixed`/`from`/`quote`), price NULL, duration_minutes, alert_text, note, benefits `jsonb`, steps `jsonb`, recommendations, is_featured, is_active, position, seo_title, seo_description | `UNIQUE(slug)` ; `CHECK` : en mode `simple` avec `fixed`/`from`, `price` est obligatoire |
| **service_images** | id, service_id, media_id, position, is_cover | `CASCADE` sur service |
| **service_related** | service_id, related_service_id | PK composite (prestations complémentaires) |
| **service_sizes** | id, code (`S`, `M`, `L`, `XL`), label (« fines », « épaisses »), position | `UNIQUE(code)` |
| **service_lengths** | id, label (Nuque … Mi-cuisse), slug, position | `UNIQUE(slug)` |
| **service_variants** | id, service_id, size_id, label NULL (ex. « S – box braids »), note, image_media_id, duration_minutes NULL, position, is_active | `UNIQUE(service_id, size_id, label)` ; `CASCADE` sur service |
| **variant_prices** | variant_id, length_id, price_kind (`fixed`/`quote`), price NULL, duration_minutes NULL | PK `(variant_id, length_id)` ; `CHECK (price_kind='quote' OR price IS NOT NULL)` ; **absence de ligne = longueur non proposée** |
| **option_groups** | id, code (`finish`, `care`, `extra`), label, selection (`single`/`multiple`), is_required, position | `UNIQUE(code)` |
| **service_options** | id, group_id, name, description, price_kind (`fixed`/`from`/`quote`), price, extra_minutes, position, is_active | `price = 0` signifie « offert » |
| **option_group_assignments** | id, option_group_id, category_id NULL, service_id NULL, is_excluded | `CHECK` : exactement une cible parmi catégorie et prestation ; permet d'activer un groupe pour une catégorie et de l'exclure pour une prestation |

Comment ça reproduit la grille de la référence :

- *Knotless* = `services` (mode `matrix`) → 4 `service_variants` (S, M, L, XL) → jusqu'à 10 `variant_prices` chacune.
- *Bantu styles* = `service_categories` → *Bantu locs*, *Bantu knots*, *Bantu braids* = `services` en mode `simple`.
- *Perles +10 €* = `service_options` dans le groupe `finish` (sélection `single`).

### 3.5 Domaine Planning

| Table | Colonnes principales | Contraintes / index |
|---|---|---|
| **opening_hours** | id, weekday (1 = lundi … 7), opens_at `time`, closes_at `time` | `CHECK (closes_at > opens_at)` ; **plusieurs lignes par jour = pauses** (ex. 10h-13h et 14h-19h) ; jour sans ligne = fermé |
| **special_hours** | id, date, opens_at, closes_at, note | Horaires exceptionnels qui remplacent ceux du jour |
| **blocked_dates** | id, starts_on `date`, ends_on `date`, reason (`holiday`, `closure`, …), note | `CHECK (ends_on >= starts_on)` ; congés et fermetures |
| **blocked_slots** | id, period `tstzrange`, reason, created_by | index GiST sur `period` |

### 3.6 Domaine Réservations

| Table | Colonnes principales | Contraintes / index |
|---|---|---|
| **booking_statuses** | code (`pending`, `confirmed`, `in_progress`, `completed`, `cancelled`, `refused`, `no_show`), label, color, blocks_slot, position | PK `code` |
| **customers** | id, user_id NULL, first_name, last_name, email `citext`, phone, marketing_consent, created_at | `UNIQUE(user_id)` ; index `(email)`, `(phone)` |
| **customer_notes** | id, customer_id, author_id, body, created_at | `CASCADE` sur customer |
| **favorites** | customer_id, service_id | PK composite |
| **bookings** | id, reference (ex. `HSG-7K2P9Q`), manage_token_hash, customer_id, service_id, variant_id NULL, length_id NULL, service_label (instantané), starts_at, ends_at, **during `tstzrange` GENERATED**, status, estimated_total NULL, final_total NULL, promotion_id NULL, discount_amount, customer_comment, rules_accepted_at, cancelled_at, cancel_reason, source (`web`, `admin`, `whatsapp`), created_at, updated_at | `UNIQUE(reference)` ; FK `status → booking_statuses(code)` ; index `(starts_at)`, `(customer_id)`, `(status)` |
| **booking_options** | booking_id, option_id, label (instantané), price (instantané) | PK composite, `CASCADE` |
| **booking_status_history** | id, booking_id, from_status, to_status, changed_by, changed_at | Traçabilité des changements |
| **booking_rules** | id, body, position, is_active | Règles « À savoir avant de venir » |

**Protection contre les doubles réservations**, en deux niveaux :

```sql
-- 1. Filet de sécurité absolu, garanti par PostgreSQL
ALTER TABLE bookings
  ADD COLUMN during tstzrange GENERATED ALWAYS AS (tstzrange(starts_at, ends_at, '[)')) STORED,
  ADD CONSTRAINT bookings_no_overlap
  EXCLUDE USING gist (during WITH &&)
  WHERE (status IN ('pending', 'confirmed', 'in_progress'));
```

```
-- 2. Dans BookingService::create(), une seule transaction :
BEGIN;
  SELECT pg_advisory_xact_lock(<jour du RDV>);   -- sérialise les réservations d'un même jour
  -- revérifie horaires, fermetures, blocked_slots (non couverts par la contrainte)
  INSERT INTO bookings ...;
  INSERT INTO booking_options ...;
COMMIT;
-- Si SQLSTATE 23P01 (violation d'exclusion) : message « Ce créneau vient d'être réservé » + nouveaux créneaux proposés
```

> Les statuts `cancelled`, `refused`, `no_show` et `completed` libèrent automatiquement le créneau, puisqu'ils sont exclus de la clause `WHERE`.
> Pour passer plus tard à plusieurs coiffeuses, il suffira d'ajouter `stylist_id` à la contrainte (`stylist_id WITH =`).

### 3.7 Domaine Contenu et apparence

| Table | Colonnes principales | Contraintes / index |
|---|---|---|
| **pages** | id, title, slug, kind (`home`, `standard`, `legal`, `system`), status (`draft`/`published`), seo_title, seo_description, og_media_id, published_at, has_unpublished_changes | `UNIQUE(slug)` ; une seule page `home` (index unique partiel) |
| **page_sections** | id, page_id, type (`hero`, `pricing_grid`, `gallery`, …), variant NULL, position, is_visible, **content `jsonb`** (publié), **draft_content `jsonb`** NULL, settings `jsonb` (apparence) | index `(page_id, position)` ; `CASCADE` |
| **templates** | id, slug, name, version, is_active, installed_at | `UNIQUE(slug)` ; un seul actif (index unique partiel `WHERE is_active`) |
| **template_settings** | template_id, **settings `jsonb`** (publié), **draft_settings `jsonb`** NULL, updated_at | PK `template_id` ; réglages conservés par template |
| **site_settings** | key, value `jsonb`, group_name (`general`, `contact`, `social`, `booking`, `notifications`, `seo`, `security`) | PK `key` (colonne `group_name` car `group` est un mot réservé SQL) |
| **media_folders** | id, parent_id NULL, name | arborescence simple |
| **media** | id, folder_id NULL, disk_path, original_name, mime_type, size_bytes, width, height, alt_text, variants `jsonb` (thumb, medium, large, webp), uploaded_by, created_at | index `(folder_id)`, recherche `(original_name, alt_text)` |
| **gallery_items** | id, media_id, after_media_id NULL (avant/après), title, description, category_id NULL, service_id NULL, tags `text[]`, is_featured, position, is_published | index GIN sur `tags` |
| **reviews** | id, customer_id NULL, booking_id NULL, author_name, rating `smallint`, comment, photo_media_id NULL, status (`pending`/`approved`/`rejected`), is_featured, created_at, moderated_at, moderated_by | `CHECK (rating BETWEEN 1 AND 5)` ; un avis par réservation |
| **promotions** | id, name, description, discount_type (`percent`/`amount`/`fixed_price`), value, starts_at, ends_at, is_active | `CHECK (ends_at > starts_at)`, `CHECK (discount_type <> 'percent' OR value <= 100)` |
| **promotion_services** | promotion_id, service_id | PK composite ; `fixed_price` réservé aux prestations en mode `simple` (vérifié par le service) |
| **blog_categories** | id, name, slug | `UNIQUE(slug)` |
| **blog_tags** | id, name, slug | `UNIQUE(slug)` |
| **blog_posts** | id, category_id, author_id, title, slug, excerpt, content (HTML purifié), cover_media_id, status (`draft`/`scheduled`/`published`), published_at, seo_title, seo_description | `UNIQUE(slug)` ; index `(status, published_at)` |
| **blog_post_tags** | post_id, tag_id | PK composite |
| **contact_messages** | id, name, email, phone, subject, body, ip `inet`, is_read, created_at | index `(is_read, created_at)` |
| **notifications** | id, channel (`email`, `whatsapp_link`), event, recipient, subject, payload `jsonb`, status (`queued`/`sent`/`failed`), attempts, scheduled_at, sent_at, error, booking_id NULL | index `(status, scheduled_at)` |

**Total : 48 tables** (hors `schema_migrations`). Les 31 tables minimales du cahier des charges sont toutes présentes, plus celles imposées par la grille de tarifs, le planning et la sécurité.

Correspondance avec les noms provisoires ajoutés au cahier des charges : `service_prices` → **variant_prices**, `service_option_links` → **option_group_assignments** (les options sont rattachées par groupe, pas une par une).

### 3.8 Migrations

- Fichiers SQL versionnés `database/migrations/2026_12_01_000100_create_users.sql`, avec un `up` et un `down` séparés par un marqueur.
- Table `schema_migrations(version, applied_at)`.
- Chaque migration s'exécute dans une transaction.
- Seeders PHP : rôles et permissions, statuts, tailles, longueurs, groupes d'options, template par défaut, pages système, puis données de démo (catalogue « tresses » sur le modèle de la référence).

---

## 4. Système de templates et de personnalisation

### 4.1 Principe : le contenu est séparé de la présentation

```
            BASE DE DONNÉES (commune à tous les templates)
  pages ─ page_sections { type, content jsonb } ─ services ─ media ─ site_settings
                               │
                   SectionRegistry (app/Sections)
          définit chaque type de section : champs, validation,
          données dynamiques (ex. pricing_grid charge la grille)
                               │
                 ┌─────────────┴───────────────┐
        TemplateManager                   ThemeTokens
   template actif + aperçu          couleurs, polices, rayons,
                 │                  espacements → CSS :root
                 ▼
   templates/<slug>/sections/<type>/<variant>.twig
   (repli : resources/views/sections/<type>/default.twig)
```

- Les **types de section** (`hero`, `about`, `pricing_grid`, `featured_services`, `gallery`, `stylist`, `testimonials`, `promotions`, `practical_info`, `cta`, `social`, `faq`, `contact`, `blog_latest`, `rich_text`) sont déclarés une seule fois dans le code, avec un **schéma de champs**. Ce schéma sert à générer automatiquement le formulaire d'administration et à valider la saisie.
- Un **template** ne fournit que la présentation : gabarit de page, rendu de chaque section (une ou plusieurs variantes), CSS et réglages par défaut.
- **Changer de template ne touche à aucune donnée** : seules les lignes `templates.is_active` changent. Les réglages de chaque template sont conservés dans `template_settings`, donc revenir à un ancien template retrouve ses réglages.

### 4.2 Anatomie d'un template

```
templates/afro-natural/
├── manifest.json          métadonnées, variantes, réglages par défaut, schéma des options
├── preview.jpg            vignette dans l'administration
├── layouts/
│   └── base.twig          <head>, en-tête, pied de page, blocs
├── partials/
│   ├── header.twig
│   ├── footer.twig
│   └── booking-bar.twig
├── sections/
│   ├── hero/
│   │   ├── centered.twig
│   │   └── split-logo.twig
│   ├── pricing_grid/
│   │   └── chips.twig
│   └── practical_info/
│       └── three-columns.twig
└── assets/
    ├── css/theme.css      utilise uniquement des variables CSS (var(--color-accent)…)
    ├── js/theme.js        animations propres au template (facultatif)
    └── img/
```

Exemple de `manifest.json` (résumé) :

```json
{
  "slug": "afro-natural",
  "name": "Afro Hair & Natural Beauty",
  "version": "1.0.0",
  "tokens": {
    "color.bg": "#F6EFDF", "color.surface": "#FAF8F5", "color.surface-alt": "#EDE4D3",
    "color.border": "#D9CFBF", "color.accent": "#DFB05E", "color.accent-light": "#F3D89B",
    "color.accent-dark": "#806217", "color.text": "#5A4A44", "color.text-muted": "#6D5B54",
    "color.heading": "#17140F",
    "font.heading": "Cormorant Garamond", "font.body": "Jost", "font.script": "Parisienne",
    "radius.card": "14px", "radius.button": "40px", "space.section": "96px",
    "motion.level": "subtle"
  },
  "layout": { "header": ["sticky", "static"], "container": ["narrow", "wide"] },
  "sections": {
    "hero": ["split-logo", "centered"],
    "pricing_grid": ["chips"],
    "gallery": ["grid", "masonry"],
    "testimonials": ["cards", "carousel"]
  },
  "home_preset": ["hero", "pricing_grid", "gallery", "practical_info", "testimonials", "cta", "contact"]
}
```

> **Réalisation (étape 14).** Pour éviter de dupliquer le CSS des pages communes (prestations, réservation, espace cliente…), les templates partagent un socle : `public/assets/css/site.css` et `site.js`, la mise en page `resources/views/layouts/site.twig`, l'en-tête et le pied de page `resources/views/partials/`, et une bibliothèque de variantes de sections `resources/views/sections/<type>/<variante>.twig`. Un template fournit son manifest, un `layouts/page.twig` qui étend la mise en page commune, son `theme.css` et seulement ses variantes propres. La variante choisie pour une section est mémorisée avec le template pour lequel elle a été choisie (`page_sections.variant_template`) : un autre template applique sa variante par défaut, et revenir au premier retrouve le choix.

### 4.3 Les cinq templates

| Template | Esprit | Variantes typiques |
|---|---|---|
| **afro-natural** *(par défaut)* | Référence Lemusée : une colonne, crème et doré, grille de tarifs juste après l'accueil | hero `split-logo`, pricing `chips`, galerie `grid` |
| **elegance-premium** | Luxe minimaliste, grandes images, beaucoup d'espace | hero plein écran image ou vidéo, galerie `masonry` |
| **beauty-chic** | Doux, arrondi, rose poudré, animations délicates | cartes arrondies, témoignages `carousel` |
| **modern-hair-studio** | Asymétrique, contrasté, typographie forte | sections décalées, hero `split` |
| **minimal-professional** | Sobre, centré sur prestations et tarifs | tableau de tarifs, peu d'images |

Ordre de réalisation : **afro-natural** d'abord, complet. Les quatre autres réutilisent la même mécanique et ne demandent que des gabarits et du CSS.

### 4.4 Jetons de design et CSS dynamique

1. Fusion des jetons : `manifest.tokens` ← `template_settings.settings` (ou `draft_settings` en aperçu).
2. `ThemeTokens::toCss()` génère un bloc `:root { --color-bg: #F6EFDF; … }`.
3. Le CSS est mis en cache dans `public/cache/theme-<hash>.css` (le hash change à chaque publication, donc pas de problème de cache navigateur).
4. Les polices sont choisies dans une **liste blanche** de Google Fonts : pas de saisie libre d'URL, ce qui protège la CSP.
5. Les couleurs sont validées (`#RRGGBB`) et l'admin affiche un **avertissement de contraste** si une combinaison texte/fond passe sous WCAG AA.

### 4.5 Personnalisation dans l'administration (`/admin/apparence`)

| Écran | Contenu |
|---|---|
| Templates | Vignettes, « Aperçu », « Activer » |
| Identité | Logo, favicon, nom affiché, slogan |
| Couleurs | Sélecteurs de couleur par jeton, retour aux valeurs du template |
| Typographie | Polices titres / texte / manuscrite, tailles de titres |
| Formes et espaces | Rayons des cartes et boutons, espacement des sections, style des cartes |
| Animations | Aucune / discrètes / prononcées (toujours désactivées si `prefers-reduced-motion`) |
| Mise en page | En-tête fixe ou non, largeur du conteneur, variante de chaque section |

Le constructeur de pages (`/admin/pages/{id}`) affiche la liste des sections, qu'on peut **réordonner par glisser-déposer** (SortableJS), masquer, dupliquer ou supprimer. Chaque section s'édite dans un panneau latéral généré depuis son schéma, avec la liste des variantes proposées par le template actif.

### 4.6 Brouillon, aperçu et publication

```
Modification admin ──► draft_content / draft_settings (rien ne change pour les visiteurs)
        │
        ├─ « Aperçu » : iframe /?preview=<jeton signé, 30 min>
        │                 bascule Ordinateur (100 %) / Mobile (390 px)
        │                 le middleware PreviewMode lit les brouillons (admin connecté uniquement)
        │
        └─ « Publier » : transaction : draft → publié, draft = NULL,
                         vidage du cache, entrée dans audit_logs
```

« Annuler les modifications » remet simplement les colonnes `draft_*` à `NULL`.

---

## 5. Structure des dossiers et fichiers

```
HairShop/
├── app/
│   ├── Core/                        socle maison
│   │   ├── Kernel.php
│   │   ├── Router.php               (adaptateur FastRoute)
│   │   ├── Request.php / Response.php
│   │   ├── Database.php             (PDO, transactions)
│   │   ├── View.php                 (Twig + extensions)
│   │   ├── Session.php / Csrf.php
│   │   ├── Validator.php
│   │   ├── Migrator.php
│   │   └── Console/                 commandes bin/console
│   ├── Controllers/
│   │   ├── Front/                   Home, Page, Service, Pricing, Gallery, Blog, Booking, Contact
│   │   ├── Account/                 Auth, Register, Bookings, Favorites, Profile, ManageBooking
│   │   ├── Admin/                   Dashboard, Bookings, Calendar, Services, ServicePricing, Categories,
│   │   │                            Options, Schedule, Customers, Reviews, Promotions, Pages, Sections,
│   │   │                            Appearance, Templates, Media, Blog, Settings, Users, AuditLog
│   │   └── Api/                     Availability, Pricing, Sections, Media
│   ├── Models/                      User, Customer, Service, ServiceVariant, Booking, Page, Section, …
│   ├── Repositories/                un repository par agrégat
│   ├── Services/
│   │   ├── Auth/                    AuthService, PasswordResetService, LoginThrottler
│   │   ├── Catalog/                 PricingService, CatalogService
│   │   ├── Booking/                 AvailabilityService, BookingService, CancellationPolicy
│   │   ├── Notification/            NotificationService, Mailer, WhatsAppLinkBuilder
│   │   ├── Content/                 PageService, PublishingService, PreviewService
│   │   ├── Theme/                   TemplateManager, ThemeTokens, FontCatalog
│   │   ├── Media/                   UploadService, ImageProcessor
│   │   ├── Seo/                     SeoService, SitemapGenerator
│   │   └── Dashboard/               StatsService
│   ├── Sections/                    SectionRegistry + une classe par type (HeroSection, PricingGridSection, …)
│   ├── Middleware/                  StartSession, VerifyCsrfToken, Authenticate, RequirePermission,
│   │                                ThrottleRequests, SecurityHeaders, PreviewMode
│   ├── Validators/                  BookingRequestValidator, ServiceValidator, …
│   ├── Helpers/                     Money, Slug, DateFr, Html
│   └── Exceptions/                  SlotUnavailableException, ValidationException, …
├── bootstrap/
│   ├── app.php                      chargement .env, conteneur, gestion d'erreurs
│   └── container.php                définitions PHP-DI
├── bin/
│   └── console
├── config/
│   ├── app.php  database.php  mail.php  booking.php  media.php  security.php
├── database/
│   ├── migrations/                  *.sql versionnés
│   └── seeders/                     RolesSeeder, CatalogDemoSeeder, ScheduleSeeder, …
├── public/                          seule racine web exposée
│   ├── index.php
│   ├── .htaccess                    réécriture vers index.php
│   ├── assets/
│   │   ├── css/  (admin.css, front-core.css)
│   │   ├── js/   (pricing-grid.js, booking.js, admin/*.js)
│   │   ├── images/
│   │   └── vendor/                  Bootstrap, FullCalendar, Chart.js, SortableJS, Quill (copies locales)
│   ├── uploads/                     médias (.htaccess : aucune exécution PHP)
│   ├── themes/                      assets des templates copiés par templates:publish
│   ├── cache/                       CSS de thème généré
│   ├── robots.txt                   généré dynamiquement via une route
│   └── favicon.ico
├── resources/
│   ├── views/
│   │   ├── admin/                   layout + écrans d'administration
│   │   ├── account/                 espace cliente
│   │   ├── emails/                  confirmation, rappel, annulation, reset mot de passe
│   │   ├── sections/                rendus par défaut (repli) de chaque type de section
│   │   └── errors/                  404, 403, 419, 500
│   └── components/                  macros Twig partagées (boutons, champs, pagination)
├── routes/
│   ├── web.php  account.php  admin.php  api.php
├── storage/
│   ├── logs/
│   ├── cache/                       cache Twig, réglages
│   └── sessions/
├── templates/
│   ├── afro-natural/
│   ├── elegance-premium/
│   ├── beauty-chic/
│   ├── modern-hair-studio/
│   └── minimal-professional/
├── tests/
│   ├── Unit/                        PricingService, AvailabilityService, CancellationPolicy, ThemeTokens
│   └── Integration/                 BookingService (concurrence), migrations, routes protégées
├── docs/
│   ├── PROJET_HAIR_STYLE_BY_GARANCE.md
│   └── ARCHITECTURE.md
├── vendor/
├── .env.example
├── .gitignore
├── composer.json
├── phpunit.xml
└── README.md
```

> Choix : `public/uploads/` plutôt que `public/assets/uploads/`, pour séparer les fichiers envoyés (non versionnés) des assets du code (versionnés).

---

## 6. Plan de développement par étapes

Chaque étape produit un résultat qui fonctionne et peut être testé.

| # | Étape | Contenu | Résultat vérifiable |
|---|---|---|---|
| 0 | **Socle** | Composer, `.env`, Kernel, routeur, conteneur, Twig, PDO, erreurs et logs, migrateur, `bin/console` | Page « Hello » rendue par Twig, `migrate` fonctionne |
| 1 | **Base de données** | Toutes les migrations + seeders de référence | Schéma complet créé, test de la contrainte anti-chevauchement |
| 2 | **Auth et sécurité** | Connexion, rôles, CSRF, sessions, limitation des tentatives, reset, layout admin | Connexion admin sur `/admin`, routes protégées |
| 3 | **Réglages et médias** | Paramètres généraux, bibliothèque de médias, variantes WebP | Upload d'images, logo modifiable |
| 4 | **Moteur de templates** | TemplateManager, ThemeTokens, SectionRegistry, template `afro-natural`, page d'accueil depuis la base | Accueil public rendu depuis PostgreSQL |
| 5 | **Catalogue** | Admin catégories, prestations, tailles, longueurs, grille éditable, options ; pages publiques prestations + grille interactive | Choix coiffure → taille → longueur → prix |
| 6 | **Horaires et disponibilités** | Admin horaires, fermetures, créneaux bloqués ; AvailabilityService + API | `GET /api/disponibilites` renvoie les bons créneaux |
| 7 | **Réservations** | Parcours en 6 étapes, transaction, statuts, admin liste + calendrier, ajout manuel, export CSV | Réservation de bout en bout, double réservation refusée |
| 8 | **Notifications** | File d'e-mails, gabarits, rappels J-1, liens WhatsApp, cron | E-mails reçus (Mailpit en local) |
| 9 | **Espace cliente** | Inscription, RDV, annulation selon délai, favoris, gestion sans compte par lien | Parcours cliente complet |
| 10 | **CMS et personnalisation** | Pages, sections par glisser-déposer, brouillon et aperçu ordinateur/mobile, écrans Apparence | Modifier, prévisualiser, publier |
| 11 | **Galerie, avis, promotions** | Portfolio filtrable, modération, promotions sur période | Contenus visibles côté public |
| 12 | **Blog** | Articles, éditeur Quill, catégories, tags, programmation | Article programmé publié par le cron |
| 13 | **Dashboard et clients** | Indicateurs, graphiques, fiches clientes, notes, audit | Tableau de bord alimenté |
| 14 | **Templates restants** | elegance-premium, beauty-chic, modern-hair-studio, minimal-professional | Bascule de template sans perte |
| 15 | **SEO et performances** | Méta, Open Graph, sitemap, robots, lazy loading, cache | Sitemap valide |
| 16 | **Finalisation** | Données de démo complètes, tests, README, guide de déploiement | Installation sur un serveur vierge |

---

## 7. Points à valider

| # | Question | Proposition par défaut |
|---|---|---|
| 1 | Moteur de vues | **Twig** (sécurité XSS, héritage de gabarits). Alternative : vues PHP natives |
| 2 | Front | **Bootstrap 5 pour l'admin**, CSS maison avec variables pour le site public, sans étape de compilation |
| 3 | Hébergement | Serveur autorisant l'extension PostgreSQL `btree_gist` (VPS ou hébergeur PostgreSQL managé). Sur un mutualisé qui l'interdit : verrou applicatif seul, moins robuste |
| 4 | Nombre de coiffeuses | **Une seule** en V1, schéma prêt pour plusieurs |
| 5 | Lieu | Salon **ou** domicile, choisi dans les réglages (adresse masquée si domicile) |
| 6 | Confirmation des RDV | **Manuelle par défaut** (statut « En attente »), auto-confirmation activable |
| 7 | WhatsApp | **Liens pré-remplis** en V1 ; l'API WhatsApp Business (payante, validation Meta) peut venir plus tard |
| 8 | Paiement / acompte | **Hors périmètre V1** (non demandé) ; pourra être ajouté (Stripe) |
| 9 | Factures / reçus | Reçu PDF simple **activable**, en fin de projet |
| 10 | Environnement local | PHP 8.6 (`C:\php`) + PostgreSQL 18 déjà présents : configurer `php.ini` et le PATH, installer Composer (§ 1.7) |
