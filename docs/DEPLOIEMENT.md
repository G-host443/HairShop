# Guide de déploiement — Hair_style by Garance

Installation sur un serveur vierge (Linux, Debian/Ubuntu dans les exemples), mise en production, sauvegardes et mises à jour.
Ce guide a été validé en installant le site sur une base PostgreSQL vide, en mode production (`APP_DEBUG=false`).

---

## 1. Prérequis

| Élément | Version | Remarques |
|---|---|---|
| PHP | **8.4 ou plus** (PHP-FPM conseillé) | extensions `pdo_pgsql`, `mbstring`, `intl`, `fileinfo`, `gd` (avec WebP), `dom`, `sodium`, `openssl` |
| PostgreSQL | 15 ou plus | extensions `btree_gist` et `citext` (paquet `postgresql-contrib`, souvent installé d'office) |
| Serveur web | Apache 2.4 (mod_rewrite) **ou** Nginx | le dossier public est `public/` |
| Composer | 2.x | |
| HTTPS | obligatoire | Let's Encrypt (certbot) par exemple |
| Cron | | trois tâches planifiées (section 8) |

Exemple Debian 13 / Ubuntu 24.04 (avec un dépôt PHP 8.4 si nécessaire) :

```bash
sudo apt install php8.4-fpm php8.4-pgsql php8.4-mbstring php8.4-intl php8.4-gd php8.4-xml php8.4-curl \
                 postgresql postgresql-contrib nginx certbot python3-certbot-nginx unzip
```

Réglages `php.ini` (FPM) conseillés pour l'import de photos :

```ini
upload_max_filesize = 20M
post_max_size = 64M
memory_limit = 256M
expose_php = Off
```

## 2. Récupérer le code

```bash
sudo mkdir -p /var/www/hairstyle && sudo chown $USER: /var/www/hairstyle
cd /var/www/hairstyle
# copier le code (git clone, archive…) puis :
composer install --no-dev --optimize-autoloader
cp .env.example .env
php bin/console key:generate
```

## 3. Base de données

Choisissez un mot de passe solide pour l'utilisateur de l'application, puis, **avec un compte superutilisateur PostgreSQL** :

```bash
sudo -u postgres psql -v app_password="'MOT_DE_PASSE_DB'" -f database/setup/create_database.sql
```

Le script crée l'utilisateur `hairstyle_app`, la base `hairstyle_garance` (et la base de tests, inutile en production : vous pouvez la supprimer),
et les extensions `btree_gist` / `citext`. L'application ne se connecte **jamais** avec le superutilisateur.

## 4. Configuration (`.env`)

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://www.votre-domaine.fr        # adresse publique définitive, sans / final
APP_KEY=…                                   # générée par key:generate — à conserver précieusement
DB_HOST=127.0.0.1
DB_DATABASE=hairstyle_garance
DB_USERNAME=hairstyle_app
DB_PASSWORD=MOT_DE_PASSE_DB
MAIL_DRIVER=smtp
MAIL_DSN=smtp://utilisateur:mot%40de%40passe@smtp.fournisseur.fr:587
MAIL_FROM_ADDRESS=contact@votre-domaine.fr
LOG_LEVEL=warning
TRUSTED_PROXIES=                             # seulement si un proxy / répartiteur est placé devant le site
```

- **APP_KEY** chiffre les liens « gérer mon rendez-vous » envoyés par e-mail : si elle change, les anciens liens ne fonctionnent plus.
  Sauvegardez le fichier `.env` avec la base.
- **APP_URL** sert aux liens des e-mails, aux adresses canoniques, au plan du site et aux données structurées.
- **MAIL_DSN** : les caractères spéciaux du mot de passe doivent être encodés (`@` → `%40`, `:` → `%3A`, `/` → `%2F`).
- **TRUSTED_PROXIES** : adresses ou plages CIDR du proxy (Cloudflare, répartiteur de l'hébergeur). Sans ce réglage derrière un proxy,
  le HTTPS n'est pas détecté et la limitation des tentatives de connexion viserait l'adresse du proxy.

## 5. Installation

```bash
php bin/console app:check             # tout doit être ✔ (sauf « Migrations à jour » avant l'étape suivante)
php bin/console migrate               # crée le schéma
php bin/console db:seed               # données de référence (rôles, statuts, réglages, pages, templates)
php bin/console db:seed --demo        # facultatif : catalogue (prix fictifs) et contenu d'exemple
php bin/console user:create-admin --email=garance@… --first-name=Garance --last-name=…
php bin/console templates:publish     # copie les fichiers des templates dans public/themes
php bin/console app:check             # « Environnement prêt. »
```

`user:create-admin` affiche un mot de passe généré **une seule fois** : notez-le, puis changez-le à la première connexion (`/admin`).

Droits d'écriture du serveur web (utilisateur `www-data` dans les exemples) :

```bash
sudo chown -R $USER:www-data storage public/uploads public/cache public/themes
sudo chmod -R ug+rwX storage public/uploads public/cache public/themes
```

## 6. Serveur web

### Nginx (PHP-FPM)

Fichier d'exemple : [`docs/deploiement/nginx.conf`](deploiement/nginx.conf). Points essentiels :

- `root /var/www/hairstyle/public;` et toutes les requêtes non statiques vers `index.php` ;
- aucun script PHP exécuté dans `/uploads` ;
- fichiers versionnés (CSS, JS, images) mis en cache un an ; compression gzip.

```bash
sudo cp docs/deploiement/nginx.conf /etc/nginx/sites-available/hairstyle   # puis adapter server_name et le socket PHP
sudo ln -s /etc/nginx/sites-available/hairstyle /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d www.votre-domaine.fr -d votre-domaine.fr
```

### Apache

Fichier d'exemple : [`docs/deploiement/apache.conf`](deploiement/apache.conf) (`DocumentRoot` sur `public/`, `AllowOverride All`).
Les fichiers `public/.htaccess` (réécriture, cache, compression) et `public/uploads/.htaccess` (aucune exécution de script) font le reste.
Modules : `rewrite`, `headers`, `expires`, `deflate`.

## 7. Après l'installation : premiers réglages

Dans l'administration (`/admin`) :

1. **Paramètres** : nom, coordonnées (WhatsApp, téléphone, e-mail), localisation publique, logo, réseaux sociaux, adresse qui reçoit les alertes.
2. **E-mails** (`/admin/notifications`) : envoyer un e-mail de test.
3. **Prestations** : remplacer les prix fictifs du catalogue de démonstration par les vrais tarifs (ou créer le catalogue).
4. **Horaires** : semaine type, congés.
5. **Médias / Galerie** : importer de vraies photos.
6. **Pages** : rédiger la FAQ et les pages légales (mentions légales, CGV, confidentialité, annulation), puis les publier.
7. Contenu d'exemple : `php bin/console demo:clear --dry-run`, puis `php bin/console demo:clear`.
8. **Paramètres → Référencement** : « Autoriser les moteurs de recherche » (décochez-le tant que le site n'est pas prêt),
   puis déclarer `https://www.votre-domaine.fr/sitemap.xml` dans Google Search Console.

## 8. Tâches planifiées (cron)

```bash
crontab -e -u www-data
```

```cron
*/5  * * * * cd /var/www/hairstyle && php bin/console notifications:send       >> storage/logs/cron.log 2>&1
0    * * * * cd /var/www/hairstyle && php bin/console bookings:remind          >> storage/logs/cron.log 2>&1
*/15 * * * * cd /var/www/hairstyle && php bin/console blog:publish-scheduled   >> storage/logs/cron.log 2>&1
```

- `notifications:send` : file d'e-mails (nouveaux essais en cas d'échec du serveur SMTP) ;
- `bookings:remind` : rappels avant les rendez-vous confirmés ;
- `blog:publish-scheduled` : statut des articles programmés (ils s'affichent de toute façon à l'heure prévue).

## 9. Sauvegardes

À automatiser chaque nuit, avec une copie **hors du serveur** :

```bash
# Base de données (format compressé, restaurable avec pg_restore)
pg_dump -U hairstyle_app -h 127.0.0.1 -Fc hairstyle_garance > /sauvegardes/hairstyle-$(date +%F).dump
# Photos importées et configuration
tar czf /sauvegardes/uploads-$(date +%F).tar.gz -C /var/www/hairstyle public/uploads .env
```

Restauration : `pg_restore -U hairstyle_app -h 127.0.0.1 -d hairstyle_garance --clean --if-exists fichier.dump`,
puis remettre `public/uploads` et `.env`, et lancer `php bin/console cache:clear`.

## 10. Mettre à jour le site

```bash
cd /var/www/hairstyle
# récupérer le nouveau code (git pull, archive…)
composer install --no-dev --optimize-autoloader
php bin/console migrate               # applique les nouvelles migrations (chacune dans une transaction)
php bin/console db:seed               # nouvelles données de référence ; ne remplace rien de ce qui a été modifié
php bin/console templates:publish
php bin/console cache:clear           # indispensable : le conteneur et les gabarits compilés seraient périmés
sudo systemctl reload php8.4-fpm      # vide l'OPcache
```

Faites une sauvegarde juste avant toute mise à jour.

## 11. Sécurité : liste de contrôle

- [ ] `APP_DEBUG=false` et `APP_ENV=production` (aucun détail technique affiché en cas d'erreur).
- [ ] HTTPS actif ; les cookies de session sont alors marqués `Secure` et l'en-tête HSTS est envoyé.
- [ ] Seul `public/` est exposé par le serveur web (`.env`, `storage/`, `vendor/` inaccessibles).
- [ ] Aucun script exécutable dans `public/uploads`.
- [ ] Utilisateur PostgreSQL dédié (`hairstyle_app`), sans droits superutilisateur ; port 5432 fermé à l'extérieur.
- [ ] Sauvegardes testées (restauration à blanc au moins une fois).
- [ ] Comptes d'administration nominatifs, mots de passe robustes ; le journal d'activité (`/admin/journal`) est consultable.

## 12. Dépannage

| Symptôme | Piste |
|---|---|
| Page blanche ou erreur 500 | `storage/logs/app-AAAA-MM-JJ.log` ; `php bin/console app:check` |
| Erreur après une mise à jour | `php bin/console cache:clear`, puis rechargement de PHP-FPM |
| E-mails non reçus | `/admin/notifications` (journal et essai) ; vérifier `MAIL_DSN` ; la tâche `notifications:send` rattrape les échecs |
| Erreur 419 au formulaire | session expirée (ou cookies refusés) : recharger la page ; derrière un proxy, renseigner `TRUSTED_PROXIES` |
| Photos refusées à l'import | `upload_max_filesize` / `post_max_size` de PHP, droits d'écriture de `public/uploads` |
| Thème sans style | `php bin/console templates:publish`, droits de `public/themes` et `public/cache` |

### Accès perdu à la double authentification

Avec un code de secours, se connecter normalement puis reconfigurer. Sinon, sur le serveur :

```bash
php bin/console user:disable-2fa --email=adresse@du-compte
```

Pour un hébergement LWS, voir aussi [MIGRATION_LWS.md](MIGRATION_LWS.md).
