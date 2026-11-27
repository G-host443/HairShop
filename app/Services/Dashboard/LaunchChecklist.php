<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Core\Config;
use App\Core\Database;
use App\Services\Auth\TwoFactorService;
use App\Services\Settings\SettingsService;
use Database\Seeders\DemoContentSeeder;

/**
 * Configuration minimale pour un premier lancement complet, vérifiée sur les données réelles.
 * Affichée sur le tableau de bord tant qu'un point obligatoire manque.
 */
final class LaunchChecklist
{
    public function __construct(
        private readonly Database $db,
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly TwoFactorService $twoFactor,
    ) {
    }

    /**
     * @return list<array{label: string, ok: bool, required: bool, hint: string, url: ?string}>
     */
    public function items(): array
    {
        $production = $this->config->get('app.env') === 'production';
        $appUrl = (string) $this->config->get('app.url');
        $email = static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/@(localhost|example\.[a-z]+|[^@]+\.test)$/i', $value);

        $contact = $this->settings->string('contact.phone') !== '' || $this->settings->string('contact.whatsapp_number') !== '' || $email($this->settings->string('contact.email'));
        $realAdmin = (int) $this->db->fetchValue(
            "SELECT count(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
             WHERE r.code = 'admin' AND u.is_active AND u.email !~* '@(localhost|example\\.[a-z]+|[^@]+\\.test)$'"
        ) > 0;
        $pricedServices = (int) $this->db->fetchValue(
            "SELECT count(*) FROM services s WHERE s.is_active AND (s.price IS NOT NULL OR EXISTS (
                SELECT 1 FROM service_variants v JOIN variant_prices vp ON vp.variant_id = v.id WHERE v.service_id = s.id AND vp.price IS NOT NULL))"
        );
        // Prix fictifs encore en place : les prix de base « Knotless / Nuque » du catalogue de démonstration
        // (S 80, M 60, L 50, XL 45 €) sont tous inchangés.
        $untouchedDemo = (int) $this->db->fetchValue(
            "SELECT count(*) FROM variant_prices vp
             JOIN service_variants v ON v.id = vp.variant_id
             JOIN service_sizes z ON z.id = v.size_id
             JOIN service_lengths l ON l.id = vp.length_id
             JOIN services s ON s.id = v.service_id
             WHERE s.slug = 'knotless' AND l.slug = 'nuque'
               AND (z.code, vp.price) IN (('S', 80.00), ('M', 60.00), ('L', 50.00), ('XL', 45.00))"
        ) === 4 ? 1 : 0;
        $legal = array_column($this->db->fetchAll("SELECT slug, status FROM pages WHERE slug IN ('mentions-legales', 'politique-de-confidentialite', 'conditions-generales', 'politique-d-annulation')"), 'status', 'slug');
        $demoContent = (int) $this->db->fetchValue('SELECT count(*) FROM media WHERE disk_path LIKE :p', ['p' => DemoContentSeeder::MEDIA_PREFIX . '%']);

        return [
            ['label' => 'Compte administrateur définitif (adresse e-mail réelle)', 'ok' => $realAdmin, 'required' => true,
                'hint' => 'Commande : php bin/console user:create-admin --email=… ; supprimez ensuite les comptes d\'essai.', 'url' => null],
            ['label' => 'Coordonnées de contact (téléphone, WhatsApp ou e-mail)', 'ok' => $contact, 'required' => true,
                'hint' => 'Affichées sur le site et dans les e-mails.', 'url' => '/admin/parametres/contact'],
            ['label' => 'Adresse qui reçoit les alertes (nouvelles réservations, messages, avis)', 'ok' => $email($this->settings->string('notifications.admin_email')), 'required' => true,
                'hint' => 'Sans elle, aucune alerte n\'est envoyée.', 'url' => '/admin/parametres/notifications'],
            ['label' => 'Adresse d\'expédition des e-mails', 'ok' => $email($this->settings->string('notifications.sender_email')) || $email((string) $this->config->get('mail.from_address')), 'required' => true,
                'hint' => 'Une adresse de votre domaine (ex. contact@…), autorisée par votre serveur SMTP.', 'url' => '/admin/parametres/notifications'],
            ['label' => 'Envoi réel des e-mails (SMTP)', 'ok' => $this->config->get('mail.driver') === 'smtp', 'required' => $production,
                'hint' => $production ? 'MAIL_DRIVER=smtp et MAIL_DSN dans le fichier .env, puis essai dans E-mails.' : 'En développement, les e-mails sont écrits dans storage/logs (normal). À régler au déploiement.', 'url' => '/admin/notifications'],
            ['label' => 'Horaires d\'ouverture', 'ok' => (int) $this->db->fetchValue('SELECT count(*) FROM opening_hours') > 0, 'required' => true,
                'hint' => 'Sans horaires, aucun créneau n\'est proposé à la réservation.', 'url' => '/admin/horaires'],
            ['label' => 'Au moins une prestation active avec un prix', 'ok' => $pricedServices > 0, 'required' => true,
                'hint' => 'Catalogue et grilles de prix.', 'url' => '/admin/prestations'],
            ['label' => 'Prix réels à la place des prix fictifs de démonstration', 'ok' => $untouchedDemo === 0, 'required' => true,
                'hint' => 'Les prestations de démonstration non modifiées affichent encore des prix calculés par formule.', 'url' => '/admin/prestations'],
            ['label' => 'Mentions légales publiées', 'ok' => ($legal['mentions-legales'] ?? null) === 'published', 'required' => true,
                'hint' => 'Obligatoires pour un site professionnel : identité, SIRET, hébergeur (LWS…), contact.', 'url' => '/admin/pages'],
            ['label' => 'Politique de confidentialité publiée', 'ok' => ($legal['politique-de-confidentialite'] ?? null) === 'published', 'required' => true,
                'hint' => 'Obligatoire (RGPD) : le site collecte des noms, e-mails et téléphones.', 'url' => '/admin/pages'],
            ['label' => 'Conditions générales et politique d\'annulation publiées', 'ok' => ($legal['conditions-generales'] ?? null) === 'published' && ($legal['politique-d-annulation'] ?? null) === 'published', 'required' => false,
                'hint' => 'Recommandées pour encadrer les réservations, retards et annulations.', 'url' => '/admin/pages'],
            ['label' => 'Adresse publique du site en HTTPS (APP_URL)', 'ok' => str_starts_with($appUrl, 'https://') && !preg_match('~//(localhost|127\.0\.0\.1)~', $appUrl), 'required' => $production,
                'hint' => $production ? 'APP_URL=https://www.votre-domaine.fr dans .env.' : 'À régler au déploiement (actuellement ' . $appUrl . ').', 'url' => null],
            ['label' => 'Logo et localisation publique', 'ok' => $this->settings->get('general.logo_media_id') !== null && $this->settings->string('contact.public_location') !== '', 'required' => false,
                'hint' => 'Identité visuelle et ville affichée (jamais l\'adresse exacte pour une activité à domicile).', 'url' => '/admin/parametres'],
            ['label' => 'Contenu d\'exemple supprimé', 'ok' => $demoContent === 0, 'required' => false,
                'hint' => 'Après avoir importé vos photos : php bin/console demo:clear', 'url' => null],
            ['label' => 'Double authentification', 'ok' => true, 'required' => false,
                'hint' => $this->twoFactor->isEnabled() ? 'Activée sur le site : chaque compte peut la configurer.' : 'Désactivée (réglage par défaut). Activable dans Paramètres → Sécurité.', 'url' => '/admin/profil/double-authentification'],
        ];
    }

    public function isComplete(): bool
    {
        foreach ($this->items() as $item) {
            if ($item['required'] && !$item['ok']) {
                return false;
            }
        }

        return true;
    }
}
