<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Database;
use DI\Container;
use Tests\Support\Browser;

/**
 * Tableau de bord, fiches clientes, messages, journal d'activité, compteur de visites
 * (heure figée : lundi 1er mars 2027, 8 h).
 */
final class DashboardAndCustomersTest extends AdminTestCase
{
    use CatalogFixtures;

    private const NOW = '2027-03-01 08:00';

    protected function database(): Database
    {
        return $this->db;
    }

    private function frozen(Browser $browser, string $at = self::NOW): Browser
    {
        return $browser->configure(static fn (Container $c) => $c->get(Clock::class)->freeze($at));
    }

    private function customer(string $first, string $last, ?string $email, ?string $phone = null): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO customers (first_name, last_name, email, phone) VALUES (:f, :l, :e, :p) RETURNING id',
            ['f' => $first, 'l' => $last, 'e' => $email, 'p' => $phone],
        );
    }

    private function booking(int $customerId, int $serviceId, string $start, string $status, ?string $estimated = '70.00', ?string $final = null): int
    {
        static $n = 0;
        $n++;

        return (int) $this->db->fetchValue(
            "INSERT INTO bookings (reference, manage_token_hash, customer_id, service_id, service_label, starts_at, ends_at, status, estimated_total, final_total, cancelled_at)
             VALUES (:ref, :hash, :c, :s, 'Knotless taille M — Épaules', CAST(:start AS timestamptz), CAST(:start2 AS timestamptz) + interval '2 hours', :status, :est, :fin,
                     CASE WHEN :status2 = 'cancelled' THEN now() END) RETURNING id",
            ['ref' => sprintf('HSG-T%05d', $n), 'hash' => hash('sha256', 'tok' . $n . uniqid()), 'c' => $customerId, 's' => $serviceId,
             'start' => $start, 'start2' => $start, 'status' => $status, 'status2' => $status, 'est' => $estimated, 'fin' => $final],
        );
    }

    /** @return array{0: int, 1: int, 2: int} [awa, fatou, service] */
    private function seed(): array
    {
        $f = $this->matrixService();
        $awa = $this->customer('Awa', 'Diallo', 'awa@example.test', '06 12 34 56 78');
        $fatou = $this->customer('Fatou', 'Keita', null, '0798765432');
        $this->booking($awa, $f['service'], '2027-02-10 10:00', 'completed', '70.00', '80.00');   // février, réalisé 80
        $this->booking($awa, $f['service'], '2027-03-01 10:00', 'confirmed');                    // aujourd'hui
        $this->booking($fatou, $f['service'], '2027-03-05 14:00', 'pending', '100.00');           // en attente
        $this->booking($fatou, $f['service'], '2027-03-08 14:00', 'cancelled');
        $this->db->execute("INSERT INTO contact_messages (name, email, body) VALUES ('Inès', 'ines@example.test', 'Bonjour, avez-vous un créneau samedi ?')");

        return [$awa, $fatou, $f['service']];
    }

    public function testDashboardIndicatorsAndCharts(): void
    {
        $this->seed();
        $page = $this->frozen($this->loggedIn())->get('/admin');
        self::assertSame(200, $page->status());
        $html = $page->content();

        self::assertMatchesRegularExpression('~admin-stat-value">1</span>\s*<span class="admin-stat-label">\s*<a[^>]*>Rendez-vous aujourd&#039;hui~', $html);
        self::assertMatchesRegularExpression('~admin-stat-value">1</span>\s*<span class="admin-stat-label">\s*<a[^>]*>Demandes en attente~', $html);
        self::assertStringContainsString('170 €', $html, 'Prévu ce mois : 70 (confirmé) + 100 (en attente).');
        self::assertStringContainsString('Awa Diallo', $html, 'Rendez-vous du jour.');
        self::assertStringContainsString('data-chart="revenue"', $html);
        self::assertStringContainsString('<td>févr. 2027</td><td>80 €</td>', $html, 'Février : 80 € réalisés (prix final).');
        self::assertStringContainsString('"name":"Knotless","count":3', $html, 'Prestations populaires : annulés exclus.');
        self::assertStringContainsString('Messages non lus', $html);

        // Compte « staff » : rendez-vous oui, chiffre d'affaires et fréquentation non.
        $staff = $this->frozen($this->loggedIn('staff', 'staff@example.test'))->get('/admin')->content();
        self::assertStringContainsString('data-chart="bookings"', $staff);
        self::assertStringNotContainsString('data-chart="revenue"', $staff);
        self::assertStringNotContainsString('Chiffre d&#039;affaires', $staff);
        self::assertStringNotContainsString('data-chart="visits"', $staff);
    }

    public function testCustomerSearchAndFile(): void
    {
        [$awa, $fatou] = $this->seed();
        $admin = $this->frozen($this->loggedIn());

        $list = $admin->get('/admin/clientes')->content();
        self::assertStringContainsString('2 fiches', $list);
        self::assertStringContainsString('Awa', $admin->get('/admin/clientes?q=diallo')->content());
        self::assertStringNotContainsString('Fatou', $admin->get('/admin/clientes?q=diallo')->content());
        self::assertStringContainsString('Fatou', $admin->get('/admin/clientes?q=%2B33+7+98+76')->content(), 'Recherche par téléphone, indicatif compris.');
        self::assertStringContainsString('Awa', $admin->get('/admin/clientes?q=awa%40example')->content());

        $file = $admin->get("/admin/clientes/{$awa}")->content();
        self::assertStringContainsString('Prestations réalisées', $file);
        self::assertStringContainsString('Knotless', $file);
        self::assertStringContainsString('80 €', $file);
        self::assertStringContainsString('https://wa.me/33612345678', $file);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT count(*) FROM audit_logs WHERE action = 'customers.viewed' AND entity_id = :id", ['id' => $awa]), 'Consultation journalisée.');
        self::assertSame(404, $admin->get('/admin/clientes/999999')->status());

        // Nouveau rendez-vous depuis la fiche : coordonnées pré-remplies.
        $service = (int) $this->db->fetchValue('SELECT id FROM services LIMIT 1');
        self::assertStringContainsString('name="cliente" value="' . $fatou . '"', $admin->get("/admin/reservations/nouvelle?cliente={$fatou}")->content(), 'Cliente conservée après le choix de la prestation.');
        self::assertStringContainsString('value="0798765432"', $admin->get("/admin/reservations/nouvelle?cliente={$fatou}&service={$service}")->content());
    }

    public function testCustomerEditAndNotesPermissions(): void
    {
        [$awa, $fatou] = $this->seed();
        $staff = $this->loggedIn('staff', 'staff@example.test');
        self::assertSame(200, $staff->get("/admin/clientes/{$awa}")->status(), 'Consultation autorisée au rôle staff…');
        self::assertSame(403, $staff->post("/admin/clientes/{$awa}/notes", ['body' => 'Note'])->status(), '… mais pas les notes.');
        self::assertSame(403, $staff->post("/admin/clientes/{$awa}", ['first_name' => 'X', 'last_name' => 'Y'])->status());

        $admin = $this->loggedIn();
        $admin->post("/admin/clientes/{$fatou}", ['first_name' => 'Fatou', 'last_name' => 'Keïta', 'email' => '', 'phone' => '']);
        self::assertArrayHasKey('email', $admin->errors(), 'Au moins un moyen de contact.');
        $admin->get('/admin');

        $admin->post("/admin/clientes/{$fatou}", ['first_name' => 'Fatou', 'last_name' => 'Keïta', 'email' => 'awa@example.test', 'phone' => '+33 7 98 76 54 32', 'marketing_consent' => '1']);
        $row = $this->db->fetchOne('SELECT last_name, phone, email, marketing_consent FROM customers WHERE id = :id', ['id' => $fatou]);
        self::assertSame(['Keïta', '0798765432', 'awa@example.test', true], [$row['last_name'], $row['phone'], $row['email'], (bool) $row['marketing_consent']]);
        self::assertStringContainsString('même adresse e-mail', (string) $admin->flash('warning'), 'Doublon probable signalé.');

        $admin->post("/admin/clientes/{$awa}/notes", ['body' => "Cuir chevelu sensible.\nPréfère le matin."]);
        $note = (int) $this->db->fetchValue('SELECT id FROM customer_notes WHERE customer_id = :c', ['c' => $awa]);
        self::assertStringContainsString('Cuir chevelu sensible.<br />', $admin->get("/admin/clientes/{$awa}")->content());
        self::assertSame(404, $admin->post("/admin/clientes/{$fatou}/notes/{$note}/supprimer")->status(), 'Note d\'une autre fiche.');
        $admin->post("/admin/clientes/{$awa}/notes/{$note}/supprimer");
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM customer_notes'));
    }

    public function testMessagesInbox(): void
    {
        $this->seed();
        $id = (int) $this->db->fetchValue('SELECT id FROM contact_messages');
        $admin = $this->loggedIn();

        self::assertStringContainsString('Inès', $admin->get('/admin/messages?filtre=non-lus')->content());
        $show = $admin->get("/admin/messages/{$id}")->content();
        self::assertStringContainsString('avez-vous un créneau samedi', $show);
        self::assertTrue((bool) $this->db->fetchValue('SELECT is_read FROM contact_messages'), 'Lu à l\'ouverture.');
        self::assertStringContainsString('Aucun message non lu', $admin->get('/admin/messages?filtre=non-lus')->content());

        $admin->post("/admin/messages/{$id}/non-lu");
        self::assertFalse((bool) $this->db->fetchValue('SELECT is_read FROM contact_messages'));
        $admin->post("/admin/messages/{$id}/supprimer");
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM contact_messages'));
    }

    public function testAuditLog(): void
    {
        [$awa] = $this->seed();
        $admin = $this->loggedIn();
        $admin->get("/admin/clientes/{$awa}");

        $all = $admin->get('/admin/journal')->content();
        self::assertStringContainsString('Connexion', $all);
        self::assertStringContainsString('Fiche cliente consultée', $all);
        self::assertStringContainsString('href="/admin/clientes/' . $awa . '"', $all);

        $filtered = $admin->get('/admin/journal?domaine=customers')->content();
        self::assertStringContainsString('Fiche cliente consultée', $filtered);
        self::assertStringNotContainsString('>Connexion<', $filtered);

        self::assertSame(403, $this->loggedIn('staff', 'staff@example.test')->get('/admin/journal')->status());
    }

    public function testVisitCounter(): void
    {
        $visitor = $this->frozen($this->browser('10.0.0.70'));
        $visitor->get('/');
        $visitor->get('/');
        $visitor->get('/prestations');
        $visitor->get('/page-inexistante');                 // 404 : non comptée
        $this->frozen($this->loggedIn())->get('/admin');    // administration : non comptée

        $rows = $this->db->fetchAll("SELECT path, views FROM site_visits WHERE day = '2027-03-01' ORDER BY path");
        self::assertSame([['path' => '/', 'views' => 2], ['path' => '/prestations', 'views' => 1]], array_map(static fn ($r) => ['path' => $r['path'], 'views' => (int) $r['views']], $rows));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM information_schema.columns WHERE table_name = \'site_visits\' AND column_name IN (\'ip\', \'user_agent\', \'session\')'), 'Aucune donnée personnelle.');

        $dashboard = $this->frozen($this->loggedIn('admin', 'admin2@example.test'))->get('/admin')->content();
        self::assertStringContainsString('data-chart="visits"', $dashboard);
        self::assertStringContainsString('<a href="/" target="_blank" rel="noopener">/</a></td><td>2</td>', $dashboard);
    }
}
