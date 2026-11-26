# Hair_style by Garance

Plateforme de présentation, de réservation et de gestion pour un salon de tresses et coiffures protectrices.
PHP 8.2+ (MVC maison, Twig) et PostgreSQL 15+.

- Cahier des charges : [docs/PROJET_HAIR_STYLE_BY_GARANCE.md](docs/PROJET_HAIR_STYLE_BY_GARANCE.md)
- Architecture : [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)

## Prérequis

- PHP 8.2 ou plus, avec `pdo_pgsql`, `mbstring`, `intl`, `fileinfo`, `gd` (avec WebP), `exif`, `sodium`, `openssl`
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
php bin/console migrate            # crée le schéma (48 tables)
php bin/console db:seed --demo     # données de référence + catalogue de démonstration
php bin/console user:create-admin --email=vous@exemple.fr --first-name=Prénom --last-name=Nom
```

Sans `--password`, un mot de passe robuste est généré et affiché une seule fois. L'administration est sur `/admin`.

> Les prix du catalogue de démonstration sont **fictifs** et doivent être remplacés par les tarifs réels.

## Lancer et arrêter le serveur (Windows)

```powershell
.\start.ps1              # démarre en arrière-plan sur http://127.0.0.1:8000
.\start.ps1 -Port 8080 -Open
.\stop.ps1               # arrête le serveur
```

`start.ps1` vérifie PHP, les dépendances, le service PostgreSQL et le port, puis attend que `/sante` réponde. Le PID est enregistré dans `storage/server.pid` et le journal du serveur dans `storage/logs/server-error.log`.
Sans PowerShell : `composer serve`.

## E-mails en développement

Avec `MAIL_DRIVER=log` (par défaut), les e-mails ne sont pas envoyés : ils sont écrits dans `storage/logs/mail-AAAA-MM-JJ.log`. C'est là que se trouve, par exemple, le lien « mot de passe oublié ».

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
| `php bin/console templates:publish` | Vérifie les templates et copie leurs assets dans `public/themes` (à chaque déploiement) |
| `composer test` | Tests unitaires et d'intégration (base `_test`) |

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
templates/      Templates du site public (à partir de l'étape 4)
tests/          PHPUnit (Unit, Integration)
```

## Avancement

| Étape | Contenu | État |
|---|---|---|
| 0 | Socle : routeur, Kernel, middlewares, Twig, PDO, erreurs, logs, migrateur, console | Terminée |
| 1 | Base de données : 10 migrations (48 tables), seeders, tests des contraintes | Terminée |
| 2 | Authentification, rôles et permissions, CSRF, sessions, limitation des tentatives, mot de passe oublié, layout admin | Terminée |
| 3 | Paramètres du site (7 rubriques) et bibliothèque de médias (import contrôlé, déclinaisons WebP, dossiers, sélecteur d'images) | Terminée |
| 4 | Moteur de templates (manifest, jetons de design → CSS, sections, repli), template afro-natural, accueil et pages publiques, formulaire de contact | Terminée |
| 5 | Catalogue : catégories, prestations, grille taille × longueur, tailles / longueurs, options ; calcul serveur des prix et durées ; sélecteur de tarifs interactif ; pages /prestations | Terminée |
| 6 | Horaires et moteur de disponibilités | À venir |
