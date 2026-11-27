<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Settings\SettingsSchema;

final class AdminSettingsTest extends AdminTestCase
{
    private function setting(string $key): mixed
    {
        return json_decode((string) $this->db->fetchValue('SELECT value::text FROM site_settings WHERE key = :k', ['k' => $key]), true);
    }

    public function testEveryGroupPageRenders(): void
    {
        $browser = $this->loggedIn();

        self::assertSame(200, $browser->get('/admin/parametres')->status());
        foreach (array_keys(SettingsSchema::GROUPS) as $group) {
            $response = $browser->get("/admin/parametres/{$group}");
            self::assertSame(200, $response->status(), "Rubrique {$group}");
            self::assertStringContainsString(SettingsSchema::GROUPS[$group]['label'], $response->content());
        }
    }

    public function testUnknownGroupIs404(): void
    {
        self::assertSame(404, $this->loggedIn()->get('/admin/parametres/inconnu')->status());
    }

    public function testStaffCannotAccessSettings(): void
    {
        self::assertSame(403, $this->loggedIn('staff', 'collab@example.test')->get('/admin/parametres')->status());
    }

    public function testValidValuesAreSavedNormalizedAndAudited(): void
    {
        $browser = $this->loggedIn();

        $response = $browser->post('/admin/parametres/contact', [
            'contact__location_type' => 'salon',
            'contact__public_location' => 'Centre-ville, à 2 min du métro',
            'contact__private_address' => '12 rue des Tresses',
            'contact__phone' => '06 12 34 56 78',
            'contact__whatsapp_number' => '+33 6 12 34 56 78',
            'contact__whatsapp_message' => 'Bonjour !',
            'contact__email' => 'contact@garance.test',
            'contact__invitation_text' => 'Écrivez-moi.',
        ]);

        self::assertSame(303, $response->status());
        self::assertSame([], $browser->errors());
        self::assertSame('salon', $this->setting('contact.location_type'));
        self::assertSame('33612345678', $this->setting('contact.whatsapp_number'), 'Numéro WhatsApp normalisé.');

        $audit = $this->db->fetchOne("SELECT changes::text AS changes FROM audit_logs WHERE action = 'settings.updated'");
        self::assertNotNull($audit);
        self::assertStringContainsString('contact.whatsapp_number', (string) $audit['changes']);
    }

    public function testBooleansAndIntegersAreTyped(): void
    {
        $browser = $this->loggedIn();
        $fields = [];
        foreach (array_keys(SettingsSchema::group('booking')) as $key) {
            $fields[SettingsSchema::inputName($key)] = '';
        }

        $browser->post('/admin/parametres/booking', array_merge($fields, [
            'booking__online_enabled' => '0',
            'booking__auto_confirm' => '1',
            'booking__require_rules_acceptance' => '1',
            'booking__min_notice_hours' => '24',
            'booking__horizon_days' => '90',
            'booking__cancellation_hours' => '48',
            'booking__buffer_minutes' => '15',
            'booking__slot_interval_minutes' => '60',
        ]));

        self::assertSame([], $browser->errors());
        self::assertFalse($this->setting('booking.online_enabled'));
        self::assertTrue($this->setting('booking.auto_confirm'));
        self::assertSame(24, $this->setting('booking.min_notice_hours'));
        self::assertSame(60, $this->setting('booking.slot_interval_minutes'));
    }

    public function testInvalidValuesAreRejectedWithoutPartialSave(): void
    {
        $browser = $this->loggedIn();
        $before = $this->setting('contact.email');

        $browser->post('/admin/parametres/contact', [
            'contact__location_type' => 'home',
            'contact__email' => 'pas-une-adresse',
            'contact__whatsapp_number' => '0612345678',
            'contact__phone' => 'abc',
        ]);

        $errors = $browser->errors();
        self::assertArrayHasKey('contact__email', $errors);
        self::assertArrayHasKey('contact__whatsapp_number', $errors);
        self::assertArrayHasKey('contact__phone', $errors);
        self::assertSame($before, $this->setting('contact.email'), 'Rien n\'est enregistré si un champ est invalide.');
    }

    public function testRequiredAndRangeConstraints(): void
    {
        $browser = $this->loggedIn();

        $browser->post('/admin/parametres/general', ['general__site_name' => '', 'general__currency' => 'EUR', 'general__timezone' => 'Europe/Paris', 'general__locale' => 'fr_FR']);
        self::assertArrayHasKey('general__site_name', $browser->errors());

        $browser->post('/admin/parametres/security', ['security__max_login_attempts' => '1', 'security__lockout_minutes' => '15', 'security__password_min_length' => '12']);
        self::assertArrayHasKey('security__max_login_attempts', $browser->errors());
    }

    public function testMediaSettingMustPointToAnExistingImage(): void
    {
        $browser = $this->loggedIn();

        $browser->post('/admin/parametres/general', [
            'general__site_name' => 'Hair_style by Garance',
            'general__currency' => 'EUR',
            'general__timezone' => 'Europe/Paris',
            'general__locale' => 'fr_FR',
            'general__logo_media_id' => '999999',
        ]);

        self::assertArrayHasKey('general__logo_media_id', $browser->errors());
    }
}
