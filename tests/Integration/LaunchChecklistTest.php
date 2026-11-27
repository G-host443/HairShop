<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Dashboard\LaunchChecklist;
use Database\Seeders\CatalogDemoSeeder;

/**
 * Carte « Mise en route » du tableau de bord : configuration minimale d'un premier lancement.
 */
final class LaunchChecklistTest extends AdminTestCase
{
    private function setting(string $key, mixed $value): void
    {
        $this->db->execute('UPDATE site_settings SET value = CAST(:v AS jsonb) WHERE key = :k', ['k' => $key, 'v' => json_encode($value)]);
    }

    /** @return array<string, bool> libellé => fait */
    private function state(): array
    {
        $checklist = self::$container->make(LaunchChecklist::class, ['db' => $this->db, 'settings' => new \App\Services\Settings\SettingsService($this->db)]);
        $state = [];
        foreach ($checklist->items() as $item) {
            $state[$item['label']] = $item['ok'];
        }

        return $state;
    }

    public function testFreshInstallListsWhatIsMissing(): void
    {
        (new CatalogDemoSeeder($this->db))->run();
        $this->createUser('essai@exemple.test');
        $state = $this->state();

        self::assertFalse($state['Compte administrateur définitif (adresse e-mail réelle)'], 'Compte d\'essai seulement.');
        self::assertFalse($state['Coordonnées de contact (téléphone, WhatsApp ou e-mail)']);
        self::assertFalse($state['Prix réels à la place des prix fictifs de démonstration']);
        self::assertFalse($state['Mentions légales publiées']);
        self::assertTrue($state['Horaires d\'ouverture'], 'Semaine type fournie par les données de référence.');

        $page = $this->loggedIn('admin', 'admin@exemple.test')->get('/admin')->content();
        self::assertStringContainsString('Mise en route du site', $page);
        self::assertStringContainsString('à régler', $page);
        self::assertStringNotContainsString('Mise en route du site', $this->loggedIn('staff', 'staff@exemple.test')->get('/admin')->content(), 'Réservé aux comptes qui gèrent les paramètres.');
    }

    public function testItemsTurnGreenOnceConfigured(): void
    {
        (new CatalogDemoSeeder($this->db))->run();
        $this->createUser('garance@salon-garance.fr');
        $this->setting('contact.phone', '06 12 34 56 78');
        $this->setting('notifications.admin_email', 'garance@salon-garance.fr');
        $this->setting('notifications.sender_email', 'contact@salon-garance.fr');
        $this->db->execute("UPDATE variant_prices SET price = 85 WHERE price = 80");
        $this->db->execute("UPDATE pages SET status = 'published', published_at = now() WHERE slug IN ('mentions-legales', 'politique-de-confidentialite')");

        $state = $this->state();
        foreach (['Compte administrateur définitif (adresse e-mail réelle)', 'Coordonnées de contact (téléphone, WhatsApp ou e-mail)',
            'Adresse qui reçoit les alertes (nouvelles réservations, messages, avis)', 'Adresse d\'expédition des e-mails',
            'Prix réels à la place des prix fictifs de démonstration', 'Mentions légales publiées', 'Politique de confidentialité publiée'] as $label) {
            self::assertTrue($state[$label], $label);
        }
    }
}
