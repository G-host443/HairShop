# Hair_style by Garance

Plateforme de présentation, de réservation et de gestion pour un salon de tresses et coiffures protectrices.
PHP 8.4+ (MVC maison, Twig) et PostgreSQL 15+.

- Cahier des charges : [docs/PROJET_HAIR_STYLE_BY_GARANCE.md](docs/PROJET_HAIR_STYLE_BY_GARANCE.md)
- Architecture : [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- **Mise en production : [docs/DEPLOIEMENT.md](docs/DEPLOIEMENT.md)** (serveur vierge, HTTPS, cron, sauvegardes, mises à jour)
- **Premier lancement et migration vers LWS : [docs/MIGRATION_LWS.md](docs/MIGRATION_LWS.md)** (configuration minimale, choix de l'offre, plan pas à pas)

## Fonctionnalités

- **Site public** : accueil composé de sections, catalogue avec grille de prix taille × longueur et sélecteur interactif, galerie filtrable (avant / après, plein écran), avis, blog, contact, pages libres ; cinq templates au choix.
- **Réservation en ligne** sans compte (créneaux calculés depuis les horaires, double réservation impossible), lien personnel « gérer mon rendez-vous », e-mails de confirmation et de rappel, demande d'avis après la prestation.
- **Espace cliente** : rendez-vous, annulation dans le délai, favoris, profil, export et suppression des données.
- **Administration** : tableau de bord et graphiques, rendez-vous (liste, calendrier, saisie manuelle, export), fiches clientes et notes, prestations et tarifs, horaires et congés, promotions, galerie, avis, blog, pages (constructeur avec brouillon et aperçu), apparence, médias, messages, e-mails, paramètres, journal d'activité.
- **Sécurité et vie privée** : rôles et permissions, CSRF, limitation des tentatives, mots de passe Argon2id (bcrypt si indisponible), contenu riche nettoyé par liste blanche, aucune adresse personnelle publiée, fréquentation mesurée sans cookie, double authentification (application TOTP + codes de secours) **désactivée par défaut**, activable dans Paramètres → Sécurité.
- **Mise en route** : carte du tableau de bord qui vérifie la configuration minimale d'un premier lancement (compte définitif, contact, e-mails, prix réels, pages légales, HTTPS…).

## Prérequis

- PHP 8.4 ou plus, avec `pdo_pgsql`, `mbstring`, `intl`, `fileinfo`, `gd` (avec WebP), `dom`, `sodium`, `openssl`
- Réglages `php.ini` conseillés pour l'import de photos : `upload_max_filesize = 20M`, `post_max_size = 64M`, `memory_limit = 256M`
- Composer 2
- PostgreSQL 15 ou plus, avec les extensions `btree_gist` et `citext` (fournies par défaut)

## Installation (développement)

```bash
composer install
cp .env.example .env
php bin/console key:generate
```

Choisissez un mot de passe pour l'utilisateur de l'application, renseignez-le dans `DB_PASSWORD` du fichier `.env`, puis créez l'utilisateur et les bases avec un compte superutilisateur PostgreSQL :

```bash
psql -U postgres -h localhost -v app_password="'le-mot-de-passe-de-DB_PASSWORD'" -f database/setup/create_database.sql
```

Le script crée l'utilisateur `hairstyle_app`, les bases `hairstyle_garance` et `hairstyle_garance_test`, et leurs extensions. L'application ne se connecte jamais avec le superutilisateur.

```bash
php bin/console app:check          # vérifie l'environnement
php bin/console migrate            # crée le schéma (49 tables)
php bin/console db:seed --demo     # données de référence + catalogue de démonstration
php bin/console user:create-admin --email=vous@exemple.fr --first-name=Prénom --last-name=Nom
```

Sans `--password`, un mot de passe robuste est généré et affiché une seule fois. L'administration est sur `/admin`.

> Les prix du catalogue de démonstration sont **fictifs** et doivent être remplacés par les tarifs réels.

### Contenu de démonstration

`db:seed --demo` ajoute, en plus du catalogue, de quoi présenter le site complet : images d'exemple (motifs de tresses générés, marqués « démonstration »), galerie, trois articles de blog, une FAQ en brouillon, une promotion **désactivée** et trois avis d'exemple **à modérer** (jamais publiés automatiquement). Aucun rendez-vous ni aucune cliente fictive n'est créé : ils bloqueraient de vrais créneaux.

```bash
php bin/console demo:clear --dry-run   # liste ce qui serait supprimé
php bin/console demo:clear             # supprime le contenu d'exemple (le catalogue est conservé)
```

## Lancer et arrêter le serveur (Windows)

```powershell
.\start.ps1              # démarre en arrière-plan sur http://127.0.0.1:8000
.\start.ps1 -Port 8080 -Open
.\stop.ps1               # arrête le serveur
```

`start.ps1` vérifie PHP, les dépendances, le service PostgreSQL et le port, puis attend que `/sante` réponde. Le PID est enregistré dans `storage/server.pid` et le journal du serveur dans `storage/logs/server-error.log`.
Sans PowerShell : `composer serve`.

## E-mails

**En développement**, avec `MAIL_DRIVER=log` (par défaut), les e-mails ne sont pas envoyés : ils sont écrits dans `storage/logs/mail-AAAA-MM-JJ.log` (lien « mot de passe oublié », confirmations…).

**En production**, dans `.env` :

```
MAIL_DRIVER=smtp
MAIL_DSN=smtp://contact%40mondomaine.fr:motdepasse@smtp.mondomaine.fr:587
```

(les caractères spéciaux de l'identifiant et du mot de passe s'écrivent encodés : `@` → `%40`). L'expéditeur et l'adresse qui reçoit les alertes se règlent dans **Administration › Paramètres › Notifications**. Le bouton « M'envoyer un e-mail de test » de la page **E-mails** vérifie la configuration.

E-mails envoyés automatiquement : demande reçue / rendez-vous confirmé (avec le lien « gérer mon rendez-vous »), confirmation (avec l'adresse si les prestations ont lieu à domicile), refus, annulation, déplacement, rappel la veille ; alertes pour l'administratrice (nouvelle réservation, annulation par une cliente, message de contact).

## Tâches planifiées (cron)

```
*/5 * * * *  cd /chemin/du/site && php bin/console notifications:send   # file d'e-mails (nouveaux essais en cas d'échec)
0   * * * *  cd /chemin/du/site && php bin/console bookings:remind      # rappels des rendez-vous confirmés
*/15 * * * * cd /chemin/du/site && php bin/console blog:publish-scheduled  # statut des articles programmés
```

Les e-mails partent immédiatement après chaque action ; la tâche `notifications:send` rattrape ceux qui ont échoué (5 essais, espacés de 10, 20, 40 puis 80 minutes).

## Commandes utiles

| Commande | Rôle |
|---|---|
| `php bin/console app:check` | Diagnostic : PHP, extensions, dossiers, PostgreSQL, migrations |
| `php bin/console migrate` | Applique les migrations en attente |
| `php bin/console migrate:status` | État des migrations |
| `php bin/console migrate:rollback [--step=N]` | Annule le ou les derniers lots |
| `php bin/console migrate:fresh --seed [--demo]` | Reconstruit la base (développement uniquement) |
| `php bin/console db:seed [--demo] [--class=Nom]` | Données de référence (idempotent) |
| `php bin/console user:create-admin --email= --first-name= --last-name= [--role=admin\|staff]` | Crée un compte d'administration |
| `php bin/console user:disable-2fa --email=` | Désactive la double authentification d'un compte (téléphone perdu, plus de code de secours) |
| `php bin/console templates:publish` | Vérifie les templates et copie leurs assets dans `public/themes` (à chaque déploiement) |
| `php bin/console notifications:send` | Envoie la file d'e-mails (tâche planifiée) |
| `php bin/console bookings:remind` | Programme et envoie les rappels de rendez-vous (tâche planifiée) |
| `php bin/console cache:clear` | Vide les caches (conteneur compilé, gabarits, paramètres, CSS des thèmes) — **après chaque mise à jour en production** |
| `php bin/console demo:clear [--dry-run]` | Supprime le contenu d'exemple de `db:seed --demo` (galerie, blog, avis, promotion, images) |
| `php bin/console blog:publish-scheduled` | Passe en « publié » les articles programmés arrivés à échéance (tâche planifiée ; le site les affiche déjà à l'heure dite) |
| `composer test` | Tests unitaires et d'intégration (base `_test`) |

## Tests

```bash
composer test        # ou : php vendor/bin/phpunit
```

Les tests d'intégration utilisent la base `hairstyle_garance_test` (reconstruite au lancement, chaque test dans une transaction annulée).
Ils couvrent notamment : contraintes de la base, réservation (concurrence, délais, prix), notifications, espace cliente, constructeur de pages,
apparence et templates (contrastes WCAG AA), galerie, avis, promotions, blog (nettoyage du HTML), tableau de bord, référencement
(plan du site, données structurées) et un budget de requêtes SQL par page.

## Structure

```
app/            Code applicatif (Core, Controllers, Services, Repositories, Middleware…)
bootstrap/      Amorçage et conteneur d'injection de dépendances
bin/console     Ligne de commande
config/         Configuration (lit .env)
database/       Migrations SQL versionnées, seeders, script de création de la base
public/         Racine web (index.php, assets, uploads)
resources/      Vues Twig (admin, e-mails, erreurs) et composants
routes/         web.php, admin.php, account.php, api.php
storage/        Logs, cache, sessions
templates/      Templates du site public (manifest, mise en page, style, variantes propres)
docs/           Cahier des charges, architecture, guide de déploiement et configurations serveur
tests/          PHPUnit (Unit, Integration)
```

## Référencement

- `APP_URL` doit contenir l'adresse publique définitive (https://…) : elle sert aux adresses canoniques, au plan du site et aux données structurées.
- Paramètres → Référencement → « Autoriser les moteurs de recherche » : à décocher pendant la préparation du site (`robots.txt` ferme alors tout le site et chaque page porte `noindex`).
- `/sitemap.xml` se met à jour tout seul (pages publiées, prestations actives, galerie et avis s'ils ont du contenu, articles en ligne) : à déclarer dans Google Search Console.
- L'adresse exacte n'apparaît jamais dans les données structurées pour une activité à domicile (seule la localité publique est donnée).

## Créer un template

Un template ne contient que sa présentation ; le contenu (pages, prestations, photos) ne dépend jamais de lui.

```
templates/<slug>/
├── manifest.json          nom, description, jetons de design, variantes de sections, options de mise en page
├── layouts/page.twig      {% extends 'layouts/site.twig' %} (blocs head, header, page_header, footer, scripts)
├── assets/css/theme.css   personnalité, chargée après le socle commun public/assets/css/site.css
└── sections/<type>/<variante>.twig   uniquement les variantes propres au template
```

- Les jetons de design doivent reprendre toutes les clés d'afro-natural (le socle les utilise) ; un test vérifie leur validité et les contrastes WCAG AA.
- Une variante déclarée est cherchée dans le template, puis dans la bibliothèque partagée `resources/views/sections/<type>/<variante>.twig`, puis dans le gabarit générique `default.twig`.
- Après ajout : `php bin/console templates:publish`, puis le template apparaît dans Apparence → Templates (bouton « Essayer »).

## Avancement

| Étape | Contenu | État |
|---|---|---|
| 0 | Socle : routeur, Kernel, middlewares, Twig, PDO, erreurs, logs, migrateur, console | Terminée |
| 1 | Base de données : 10 migrations (48 tables), seeders, tests des contraintes | Terminée |
| 2 | Authentification, rôles et permissions, CSRF, sessions, limitation des tentatives, mot de passe oublié, layout admin | Terminée |
| 3 | Paramètres du site (7 rubriques) et bibliothèque de médias (import contrôlé, déclinaisons WebP, dossiers, sélecteur d'images) | Terminée |
| 4 | Moteur de templates (manifest, jetons de design → CSS, sections, repli), template afro-natural, accueil et pages publiques, formulaire de contact | Terminée |
| 5 | Catalogue : catégories, prestations, grille taille × longueur, tailles / longueurs, options ; calcul serveur des prix et durées ; sélecteur de tarifs interactif ; pages /prestations | Terminée |
| 6 | Horaires (semaine type avec pauses, horaires exceptionnels, congés, créneaux bloqués, aperçu), moteur de disponibilités, API `/api/disponibilites` | Terminée |
| 7 | Réservation en ligne (parcours en 6 étapes sans JavaScript requis, verrou + contrainte anti-chevauchement), lien « gérer mon rendez-vous », administration des rendez-vous (liste, calendrier, statuts, déplacement, prix final, saisie manuelle, export CSV) | Terminée |
| 8 | Notifications : e-mails de réservation, confirmation, refus, annulation, déplacement, rappel ; alertes administratrice ; SMTP ; file d'envoi avec nouveaux essais ; journal des e-mails ; lien WhatsApp vers la cliente | Terminée |
| 9 | Espace cliente `/compte` : inscription avec confirmation de l'adresse, connexion séparée de l'administration, rendez-vous à venir et historique, annulation dans le délai, favoris, profil, mot de passe oublié, export des données (JSON) et suppression du compte ; réservations faites sans compte rattachées une fois l'adresse confirmée ; réservation sans compte toujours possible | Terminée |
| 10 | CMS et personnalisation : pages (création, adresse, référencement, publication, retrait), constructeur de sections par glisser-déposer (ajout, édition générée depuis le schéma, variantes du template, masquage, duplication, suppression restaurable), brouillon complet avec aperçu ordinateur / mobile et publication en une transaction ; écrans Apparence (templates, couleurs, polices, formes, animations, en-tête) avec avertissements de contraste WCAG ; bloc Texte mis en forme sans HTML saisi | Terminée |
| 11 | Galerie (administration, avant / après, tags, mise en avant, ordre ; page /galerie filtrable par catégorie et tag, recherche, grille ou mosaïque, plein écran ; réalisations sur les fiches prestations), avis (dépôt après un rendez-vous terminé par le lien personnel ou l'espace cliente, e-mail de demande d'avis, modération, mise en avant, photo, avis reçus ailleurs ; page /avis), promotions (pourcentage, montant ou prix promotionnel sur une période, appliquées au prix affiché et enregistré, section « Promotions en cours ») | Terminée |
| 12 | Blog : articles avec éditeur riche (Quill 2, copie locale) et images de la bibliothèque, contenu nettoyé par liste blanche (HtmlSanitizer), brouillon, publication immédiate ou programmée (`blog:publish-scheduled`), aperçu, catégories et tags ; pages /blog, par catégorie, par tag et article ; section « Derniers articles » | Terminée |
| 13 | Tableau de bord (indicateurs, rendez-vous du jour, demandes à confirmer, graphiques Chart.js avec tableaux de données : rendez-vous par semaine, chiffre d'affaires réalisé / prévu, prestations populaires, fréquentation anonyme sans cookie), fiches clientes (recherche nom / e-mail / téléphone, historique, prestations réalisées, notes internes, modification, consultation journalisée), boîte de réception des messages, journal d'activité ; permission `stats.view` | Terminée |
| 14 | Quatre nouveaux templates (Élégance Premium, Beauty Chic, Modern Hair Studio, Minimal Professional) sur un socle commun (`site.css`, `site.js`, mise en page et bibliothèque de sections partagées) ; essai d'un template avant activation ; variantes de section mémorisées par template (bascule sans perte) ; contrastes WCAG AA vérifiés par les tests | Terminée |
| 15 | SEO : `robots.txt` et `sitemap.xml` générés depuis le contenu publié, balises robots (pages privées, recherches et filtres non indexés, réglage « Autoriser les moteurs de recherche »), canoniques des pages paginées, Open Graph article, données structurées schema.org (salon de coiffure, fil d'Ariane, article). Performances : requêtes N+1 supprimées (≈ 90-160 → ≈ 25 requêtes par page), catalogue lu une fois par requête, images prioritaires, compression et cache long des fichiers versionnés (`.htaccess`) | Terminée |
| 16 | Finalisation : contenu de démonstration complet et `demo:clear`, installation vérifiée sur une base vierge en mode production, guide de déploiement (Nginx / Apache, HTTPS, cron, sauvegardes, mises à jour, dépannage), `cache:clear`, prise en charge des proxys de confiance (`TRUSTED_PROXIES`), PHP 8.4 minimum | Terminée |
| + | Premier lancement : double authentification TOTP (désactivée par défaut), carte « Mise en route du site » sur le tableau de bord, plan de migration vers LWS | Terminée |
