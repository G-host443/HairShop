# Premier lancement et migration vers LWS — Hair_style by Garance

Ce document complète le [guide de déploiement](DEPLOIEMENT.md) (commandes détaillées, configurations Nginx / Apache).
Il couvre : la configuration minimale d'un premier lancement complet, le choix de l'offre LWS, et le plan de migration étape par étape.

> Informations LWS vérifiées en novembre 2026 sur les pages publiques de LWS (liens en fin de document). Les offres et tarifs
> évoluent : confirmez-les dans l'espace client LWS ou auprès du support avant de commander.

---

## 1. Ce dont l'application a besoin

| Besoin | Minimum | Pourquoi |
|---|---|---|
| PHP | **8.4** avec `pdo_pgsql`, `intl`, `mbstring`, `gd` (WebP), `dom`, `sodium`, `fileinfo`, `openssl` | nettoyage du HTML du blog (analyseur HTML5 de PHP 8.4), photos WebP, chiffrement |
| PostgreSQL | **15** | contraintes `NULLS NOT DISTINCT`, contrainte anti-chevauchement des rendez-vous |
| Extensions PostgreSQL | `btree_gist`, `citext` | impossibilité de réserver deux fois le même créneau ; e-mails insensibles à la casse |
| Accès SSH | oui | `composer`, migrations, `bin/console` |
| Tâches planifiées (cron) | 3 tâches | e-mails, rappels, articles programmés |
| Racine web | dossier `public/` uniquement | `.env`, `storage/`, `vendor/` ne doivent jamais être accessibles |
| HTTPS | oui | sessions sécurisées, confiance des clientes |

La commande `php bin/console app:check` vérifie tous ces points sur le serveur (versions, extensions, droits, base, migrations).

## 2. Quelle offre LWS ?

| Offre LWS | Verdict | Raison |
|---|---|---|
| **VPS Linux (Debian 12 ou 13)** | **Recommandée** | accès root : PHP 8.4, PostgreSQL 15+ et ses extensions s'installent librement ; c'est la configuration décrite et validée dans le guide de déploiement |
| Hébergement **cPanel** (mutualisé) | Possible **seulement après vérification** (section 7) | PostgreSQL est annoncé, mais ni sa version ni la possibilité de créer `btree_gist` / `citext` (droits élevés) ne sont précisées |
| Hébergement mutualisé **LWS Panel** | **Incompatible** | d'après l'aide officielle de LWS, PostgreSQL n'est plus proposé sur ces offres (MySQL uniquement) |

Gabarit conseillé pour le VPS : 2 vCPU, 4 Go de mémoire, 40 Go de disque SSD, sauvegardes activées. Largement suffisant pour un salon ;
on peut monter en gamme plus tard sans réinstaller.

Distribution : **Debian 13** fournit directement PHP 8.4 et PostgreSQL 17 ; sur **Debian 12**, PostgreSQL 15 est fourni mais PHP 8.4
demande le dépôt `packages.sury.org`.

---

## 3. Configuration minimale pour un premier lancement complet

### 3.1 Fichier `.env` (sur le serveur)

```dotenv
APP_NAME="Hair_style by Garance"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://www.votre-domaine.fr
APP_TIMEZONE=Europe/Paris
APP_KEY=                      # php bin/console key:generate — puis la conserver avec les sauvegardes

DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=hairstyle_garance
DB_USERNAME=hairstyle_app
DB_PASSWORD=                  # mot de passe fort, propre à ce serveur

MAIL_DRIVER=smtp
MAIL_DSN=smtp://utilisateur:motdepasse@serveur-smtp:587      # paramètres SMTP de votre boîte e-mail (encodez @ en %40)
MAIL_FROM_ADDRESS=contact@votre-domaine.fr
MAIL_FROM_NAME="Hair_style by Garance"

LOG_LEVEL=warning
SESSION_IDLE_MINUTES=120
TRUSTED_PROXIES=              # vide, sauf proxy / CDN placé devant le site
```

### 3.2 Réglages dans l'administration

Le tableau de bord affiche une carte **« Mise en route du site »** qui vérifie ces points sur les données réelles et indique ce qui manque.

| Point | Où | Obligatoire |
|---|---|---|
| Compte administrateur définitif (adresse réelle de Garance), comptes d'essai supprimés | `php bin/console user:create-admin` | oui |
| Coordonnées : téléphone, WhatsApp, e-mail | Paramètres → Contact | oui |
| Adresse qui reçoit les alertes (réservations, messages, avis) | Paramètres → Notifications | oui |
| Adresse d'expédition (de votre domaine) + e-mail de test reçu | Paramètres → Notifications, puis E-mails | oui |
| Horaires de la semaine, congés | Horaires | oui |
| Vrais prix (remplacer les prix fictifs de démonstration) | Prestations | oui |
| Mentions légales (identité, SIRET, **hébergeur : LWS** et son adresse), politique de confidentialité | Pages → publier | oui |
| Conditions générales, politique d'annulation, FAQ | Pages → publier | conseillé |
| Logo, localisation publique (ville), réseaux sociaux | Paramètres | conseillé |
| Vraies photos, puis suppression du contenu d'exemple | Médias, Galerie, puis `php bin/console demo:clear` | conseillé |
| Moteurs de recherche autorisés le jour de l'ouverture | Paramètres → Référencement | à l'ouverture |
| **Double authentification** | Paramètres → Sécurité | **désactivée par défaut** ; activable ensuite compte par compte (« Double authentification » dans le menu du compte) |

### 3.3 Tâches planifiées

```cron
*/5  * * * * cd /var/www/hairstyle && php bin/console notifications:send
0    * * * * cd /var/www/hairstyle && php bin/console bookings:remind
*/15 * * * * cd /var/www/hairstyle && php bin/console blog:publish-scheduled
```

---

## 4. Plan de migration (VPS LWS)

Durée indicative : une demi-journée de travail technique, plus le délai de propagation DNS (quelques heures, jusqu'à 48 h).
Le site actuel (s'il en existe un) reste en ligne jusqu'à la bascule DNS de la phase 5.

### Phase 0 — Préparation (sur le poste de développement)

- [ ] Rassembler : accès à l'espace client LWS, nom de domaine, paramètres SMTP de la boîte e-mail, textes légaux (SIRET…).
- [ ] Choisir le point de départ des données :
  - **installation neuve** (recommandé pour un premier lancement) : schéma créé par les migrations, contenu saisi dans l'administration ;
  - **reprise du contenu préparé en local** : sauvegarde à transférer (prestations, pages, photos déjà saisies).
- [ ] Si reprise : `pg_dump -U hairstyle_app -h 127.0.0.1 -Fc hairstyle_garance > hairstyle.dump` et archive de `public/uploads`.
- [ ] Vérifier que la suite de tests passe (`composer test`).

### Phase 1 — Commande et sécurisation du VPS

- [ ] Commander le VPS LWS sous Debian 13 (ou 12), activer les sauvegardes proposées par LWS.
- [ ] Première connexion SSH (root), création d'un utilisateur dédié avec `sudo`, connexion par clé SSH, mot de passe root désactivé en SSH.
- [ ] Pare-feu : `ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw enable` (PostgreSQL n'est pas ouvert sur Internet).
- [ ] Mises à jour automatiques de sécurité : `apt install unattended-upgrades`.
- [ ] Fuseau horaire : `timedatectl set-timezone Europe/Paris`.

### Phase 2 — Installation de la pile logicielle

- [ ] Nginx, PHP 8.4-FPM et ses extensions, PostgreSQL, Composer, certbot (commandes : [DEPLOIEMENT.md §1](DEPLOIEMENT.md#1-prérequis)).
- [ ] Réglages `php.ini` (taille des envois de photos) et `expose_php = Off`.
- [ ] `psql --version` ≥ 15 et `php -v` ≥ 8.4.

### Phase 3 — Déploiement de l'application

- [ ] Copier le code dans `/var/www/hairstyle` (git, ou archive via `scp` / SFTP), sans `vendor/`, `.env`, `storage/logs`.
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `.env` de production (section 3.1) et `php bin/console key:generate`.
- [ ] Base : `sudo -u postgres psql -v app_password="'…'" -f database/setup/create_database.sql`
- [ ] **Installation neuve** : `migrate`, `db:seed` (et `db:seed --demo` si l'on veut partir du catalogue de démonstration), `user:create-admin`.
  **Reprise** : `pg_restore -U hairstyle_app -h 127.0.0.1 -d hairstyle_garance --no-owner hairstyle.dump`, copie de `public/uploads`, puis `migrate`.
  Attention : la reprise exige la **même APP_KEY** qu'en local, sinon les liens « gérer mon rendez-vous » déjà envoyés ne fonctionnent plus.
- [ ] `php bin/console templates:publish && php bin/console cache:clear`
- [ ] Droits d'écriture : `storage/`, `public/uploads`, `public/cache`, `public/themes` (utilisateur `www-data`).
- [ ] `php bin/console app:check` → « Environnement prêt. »
- [ ] Hôte virtuel Nginx ([docs/deploiement/nginx.conf](deploiement/nginx.conf)), `nginx -t`, rechargement.

### Phase 4 — Essai avant la bascule DNS

- [ ] Sur le poste de test, faire pointer le domaine vers l'IP du VPS dans le fichier `hosts`
  (`C:\Windows\System32\drivers\etc\hosts` : `IP_DU_VPS  www.votre-domaine.fr`).
- [ ] Parcourir le site et l'administration ; `https://www.votre-domaine.fr/sante` doit répondre.
- [ ] Laisser **« Autoriser les moteurs de recherche » décoché** tant que le site n'est pas ouvert.

### Phase 5 — DNS, HTTPS et e-mails

- [ ] Dans la zone DNS du domaine (espace client LWS) : enregistrements **A** (et **AAAA** si IPv6) de `@` et `www` vers l'IP du VPS.
  Baisser le TTL à 300 s la veille pour une bascule rapide.
- [ ] Une fois la propagation faite : `certbot --nginx -d votre-domaine.fr -d www.votre-domaine.fr` (renouvellement automatique).
- [ ] E-mails : configurer `MAIL_DSN` ; dans la zone DNS, vérifier les enregistrements **SPF**, **DKIM** et **DMARC** du service d'envoi
  (sinon les confirmations de rendez-vous risquent d'arriver en indésirables). Envoyer un e-mail de test depuis E-mails.

### Phase 6 — Exploitation

- [ ] Tâches planifiées (section 3.3), sous l'utilisateur `www-data`.
- [ ] Sauvegarde nocturne de la base et des photos, copiée hors du serveur ([DEPLOIEMENT.md §9](DEPLOIEMENT.md#9-sauvegardes)) ; test de restauration.
- [ ] Surveillance : sonde externe sur `https://www.votre-domaine.fr/sante` (alerte si le site ne répond plus).

### Phase 7 — Recette et ouverture

- [ ] Réservation complète depuis un téléphone : e-mail de confirmation reçu, lien « gérer mon rendez-vous », annulation.
- [ ] Confirmation d'un rendez-vous dans l'administration, rappel reçu (ou `php bin/console bookings:remind` pour l'essai).
- [ ] Formulaire de contact, inscription à l'espace cliente, avis après un rendez-vous terminé.
- [ ] Carte « Mise en route du site » : tous les points obligatoires sont verts.
- [ ] Ouverture : cocher « Autoriser les moteurs de recherche », déclarer `https://www.votre-domaine.fr/sitemap.xml` dans Google Search Console.

### Retour arrière

Tant que le DNS n'est pas basculé, rien n'est visible des clientes. Après la bascule, en cas de problème bloquant :
remettre les anciens enregistrements DNS (d'où le TTL court), corriger sur le VPS, puis rebasculer.
Une sauvegarde complète est faite juste avant chaque mise à jour ([DEPLOIEMENT.md §10](DEPLOIEMENT.md#10-mettre-à-jour-le-site)).

---

## 5. Mises à jour après la mise en ligne

```bash
cd /var/www/hairstyle
# sauvegarde, puis récupération du nouveau code
composer install --no-dev --optimize-autoloader
php bin/console migrate && php bin/console db:seed && php bin/console templates:publish
php bin/console cache:clear
sudo systemctl reload php8.4-fpm
```

## 6. En cas de perte d'accès

- Mot de passe oublié : lien « Mot de passe oublié ? » de la page de connexion (e-mail).
- Double authentification et téléphone perdu : un code de secours, sinon `php bin/console user:disable-2fa --email=…` sur le serveur.
- Nouveau compte d'administration : `php bin/console user:create-admin --email=… --first-name=… --last-name=…`.

---

## 7. Variante : hébergement cPanel LWS

À n'envisager qu'avec une **réponse écrite du support LWS** aux questions suivantes :

1. Quelle **version de PostgreSQL** est installée ? (15 minimum)
2. Les extensions **`btree_gist`** et **`citext`** peuvent-elles être **créées dans ma base** (par vous si elles demandent des droits
   superutilisateur) ? Sans elles, la toute première migration échoue.
3. **PHP 8.4** est-il disponible, avec `pdo_pgsql`, `intl`, `gd` (WebP), `dom`, `sodium`, aussi **en ligne de commande** (SSH et cron) ?
   Quel est le chemin du binaire PHP 8.4 à utiliser dans les tâches cron ?
4. Puis-je faire pointer la **racine du domaine vers un sous-dossier** (`public/`) ?
5. L'accès **SSH** permet-il d'utiliser Composer ?

Si toutes les réponses sont positives :

- Placer le projet hors de `public_html` (par exemple `~/hairstyle`) et faire pointer le domaine vers `~/hairstyle/public`
  (réglage de la racine du domaine dans cPanel, ou lien symbolique `public_html` → `hairstyle/public`).
- Créer la base et l'utilisateur avec l'assistant **« Bases de données PostgreSQL »** de cPanel, faire créer les extensions par le support,
  renseigner `DB_*` dans `.env` (le préfixe de compte cPanel fait partie des noms).
- Choisir PHP 8.4 dans le **sélecteur de version PHP** de cPanel.
- Créer les trois tâches dans **« Tâches Cron »** avec le chemin complet du binaire PHP 8.4 indiqué par le support
  (vérifiable en SSH avec `which php` / `php -v`).
- Le fichier `public/.htaccess` fourni gère la réécriture, le cache et la compression sous Apache.
- Terminer par `php bin/console app:check` : chaque ligne doit être ✔.

Sinon, revenir à l'offre VPS.

---

## Sources (pages LWS consultées)

- [Aide LWS — FAQ (PostgreSQL non proposé sur les offres LWS Panel)](https://help.lws.net/en/faq)
- [Hébergement cPanel LWS (PostgreSQL annoncé)](https://www.lws.fr/hebergement-cpanel.php)
- [Hébergement web LWS (versions de PHP proposées)](https://www.lws.fr/hebergement_php_mysql.php)
- [Créer une tâche cron depuis le LWS Panel](https://help.lws.net/en/How-to-create-a-LWS-Panel-cron-job)
- [VPS Debian LWS](https://www.lws.net/debian-vps)
