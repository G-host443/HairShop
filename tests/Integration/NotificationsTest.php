<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Database;
use App\Services\Mail\MailerInterface;
use App\Services\Mail\MailMessage;
use App\Services\Notification\NotificationService;
use DI\Container;
use Tests\Support\Browser;
use Tests\Support\FailingMailer;

/**
 * E-mails automatiques (heure figée : lundi 1er mars 2027, 8 h).
 */
final class NotificationsTest extends AdminTestCase
{
    use CatalogFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('notifications.admin_email', 'garance@example.test');
        $this->setting('contact.private_address', '12 rue des Tresses, Torcy');
        $this->setting('contact.whatsapp_number', '33612345678');
    }

    protected function database(): Database
    {
        return $this->db;
    }

    private function setting(string $key, mixed $value): void
    {
        $this->db->execute('UPDATE site_settings SET value = CAST(:v AS jsonb) WHERE key = :k', ['k' => $key, 'v' => json_encode($value)]);
    }

    private function frozen(Browser $browser, string $at = '2027-03-01 08:00'): Browser
    {
        return $browser->configure(static fn (Container $c) => $c->get(Clock::class)->freeze($at));
    }

    /** Réservation en ligne de Knotless M / épaules (par défaut le lundi 8 mars à 10 h). */
    private function book(array $f, string $email = 'awa@example.test', string $date = '2027-03-08'): int
    {
        $this->frozen($this->browser())->post('/reserver', [
            'service' => (string) $f['service'], 'variant' => (string) $f['variants']['M'], 'length' => (string) $f['lengths']['epaules'],
            'c' => '1', 'date' => $date, 'time' => '10:00',
            // Téléphone propre à chaque e-mail : une cliente est reconnue par son e-mail ou son téléphone.
            'first_name' => 'Awa', 'last_name' => 'Diallo', 'email' => $email, 'phone' => '06' . substr((string) crc32($email), 0, 8), 'rules' => '1',
            'comment' => 'Mèches couleur 1B',
        ]);

        return (int) $this->db->fetchValue('SELECT id FROM bookings ORDER BY id DESC LIMIT 1');
    }

    /** @return list<MailMessage> */
    private function mailsTo(string $recipient): array
    {
        return array_values(array_filter($this->mailer->sent, static fn (MailMessage $m): bool => $m->to === $recipient));
    }

    private function service(?MailerInterface $mailer = null, string $at = '2027-03-01 08:00'): NotificationService
    {
        $container = self::$container;
        $container->set(MailerInterface::class, $mailer ?? $this->mailer);
        $container->get(Clock::class)->freeze($at);
        // Paramètres relus (le test vient de les modifier).
        $container->get(\App\Services\Settings\SettingsService::class)->refresh();

        return $container->make(NotificationService::class, [
            'renderer' => $container->make(\App\Services\Notification\NotificationRenderer::class),
        ]);
    }

    public function testNewBookingEmailsCustomerWithWorkingManageLinkAndAlertsAdmin(): void
    {
        $f = $this->matrixService();
        $this->book($f);

        $customer = $this->mailsTo('awa@example.test');
        self::assertCount(1, $customer);
        self::assertStringStartsWith('Votre demande de rendez-vous est bien reçue (HSG-', $customer[0]->subject);
        self::assertStringContainsString('Lundi 8 mars 2027 à 10 h 00', $customer[0]->html);
        self::assertStringContainsString('Venez avec les cheveux propres', $customer[0]->text, 'Règles rappelées.');
        self::assertStringNotContainsString('12 rue des Tresses', $customer[0]->html, 'À domicile : adresse cachée tant que ce n\'est pas confirmé.');
        self::assertStringNotContainsString('&#039;', $customer[0]->text, 'La version texte n\'est pas échappée en HTML.');

        // Le lien de l'e-mail ouvre bien la page de gestion du rendez-vous.
        self::assertSame(1, preg_match('#http://localhost:8000(/rdv/[a-f0-9]{64})#', $customer[0]->text, $m));
        self::assertSame(200, $this->browser()->get($m[1])->status());

        $admin = $this->mailsTo('garance@example.test');
        self::assertCount(1, $admin);
        self::assertStringStartsWith('Nouvelle demande — Awa Diallo, 08/03', $admin[0]->subject);
        self::assertStringContainsString('Mèches couleur 1B', $admin[0]->text);
        self::assertSame('awa@example.test', $admin[0]->replyTo, 'Répondre à l\'alerte écrit à la cliente.');

        self::assertSame(2, (int) $this->db->fetchValue("SELECT count(*) FROM notifications WHERE status = 'sent' AND subject IS NOT NULL"));
    }

    public function testConfirmationRevealsAddressAndRefusalCarriesTheNote(): void
    {
        $f = $this->matrixService();
        $id = $this->book($f);
        $admin = $this->frozen($this->loggedIn());
        $this->mailer->sent = [];

        $admin->post("/admin/reservations/{$id}/statut", ['status' => 'confirmed']);
        $mail = $this->mailsTo('awa@example.test')[0];
        self::assertStringStartsWith('Votre rendez-vous du 08/03 est confirmé', $mail->subject);
        self::assertStringContainsString('12 rue des Tresses, Torcy', $mail->html);
        self::assertStringContainsString('/rdv/', $mail->html, 'Le lien de gestion figure aussi dans la confirmation.');

        $second = $this->book($f, 'b@example.test', '2027-03-11');
        self::assertNotSame($id, $second);
        $this->mailer->sent = [];
        $admin->post("/admin/reservations/{$second}/statut", ['status' => 'refused', 'note' => 'Je suis en formation ce jour-là.']);
        $refusal = $this->mailsTo('b@example.test')[0];
        self::assertStringContainsString('Je suis en formation ce jour-là.', $refusal->text);
        self::assertStringContainsString('/reserver', $refusal->text);
    }

    public function testCustomerCancellationAndRescheduleEmails(): void
    {
        $f = $this->matrixService();
        $id = $this->book($f);
        $admin = $this->frozen($this->loggedIn());
        $this->mailer->sent = [];

        $admin->post("/admin/reservations/{$id}/deplacer", ['date' => '2027-03-11', 'time' => '14:00']);
        $moved = $this->mailsTo('awa@example.test')[0];
        self::assertStringStartsWith('Votre rendez-vous a été déplacé au 11/03', $moved->subject);
        self::assertStringContainsString('il était prévu le lundi 8 mars 2027 à 10 h 00', $moved->text);

        preg_match('#(/rdv/[a-f0-9]{64})#', $moved->text, $m);
        $this->mailer->sent = [];
        $customer = $this->frozen($this->browser());
        $customer->get($m[1]);
        $customer->post($m[1] . '/annuler', ['reason' => 'Malade']);

        self::assertStringStartsWith('Rendez-vous annulé', $this->mailsTo('awa@example.test')[0]->subject);
        $alert = $this->mailsTo('garance@example.test')[0];
        self::assertStringStartsWith('Annulation par la cliente — Awa Diallo', $alert->subject);
        self::assertStringContainsString('Malade', $alert->text);
    }

    public function testRemindersAreQueuedOnceAndSkippedWhenObsolete(): void
    {
        $f = $this->matrixService();
        $id = $this->book($f);
        $admin = $this->frozen($this->loggedIn());
        $admin->post("/admin/reservations/{$id}/statut", ['status' => 'confirmed']);
        $this->mailer->sent = [];

        // Trop tôt : le rendez-vous est dans plus de 24 h.
        self::assertSame(0, $this->service(null, '2027-03-06 10:00')->queueReminders(new \DateTimeImmutable('2027-03-06 10:00')));

        // 22 h avant : rappel programmé une seule fois, puis envoyé.
        $service = $this->service(null, '2027-03-07 12:00');
        self::assertSame(1, $service->queueReminders(new \DateTimeImmutable('2027-03-07 12:00')));
        self::assertSame(0, $service->queueReminders(new \DateTimeImmutable('2027-03-07 13:00')), 'Pas de doublon.');
        self::assertSame(1, $service->sendDue()['sent']);
        self::assertStringStartsWith('Rappel : votre rendez-vous du 08/03', $this->mailsTo('awa@example.test')[0]->subject);

        // Un rappel mis en file puis rendez-vous annulé avant l'envoi : rien ne part.
        $other = $this->book($f, 'c@example.test', '2027-03-13');
        self::assertNotSame($id, $other);
        $this->db->execute("UPDATE bookings SET status = 'confirmed' WHERE id = :id", ['id' => $other]);
        $service = $this->service(null, '2027-03-12 12:00');
        $service->queueReminders(new \DateTimeImmutable('2027-03-12 12:00'));
        $this->db->execute("UPDATE bookings SET status = 'cancelled', cancelled_at = now() WHERE id = :id", ['id' => $other]);
        $stats = $service->sendDue();
        self::assertSame(1, $stats['cancelled']);
        self::assertSame('cancelled', $this->db->fetchValue("SELECT status FROM notifications WHERE event = 'booking.reminder' AND booking_id = :id", ['id' => $other]));
    }

    public function testFailuresAreRetriedThenAbandoned(): void
    {
        $failing = new FailingMailer();
        $service = $this->service($failing);
        $service->test('garance@example.test');

        $service->sendDue();
        $row = $this->db->fetchOne("SELECT status, attempts, error, scheduled_at > now() AS later FROM notifications WHERE event = 'test'");
        self::assertSame(['queued', 1, 'Connexion SMTP refusée', true], [$row['status'], (int) $row['attempts'], $row['error'], (bool) $row['later']], 'Nouvel essai plus tard.');

        for ($i = 0; $i < 6; $i++) {
            $this->db->execute("UPDATE notifications SET scheduled_at = now() WHERE status = 'queued'");
            $service->sendDue();
        }
        self::assertSame(['failed', 5], array_values(array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $this->db->fetchOne("SELECT status, attempts FROM notifications WHERE event = 'test'"))), 'Abandon après 5 essais.');
        self::assertSame(5, $failing->attempts);
    }

    public function testCustomerWithoutEmailGetsNothingButAdminStillDoes(): void
    {
        $f = $this->matrixService();
        $admin = $this->frozen($this->loggedIn());

        $admin->post('/admin/reservations', [
            'service' => (string) $f['service'], 'combo' => $f['variants']['M'] . '-' . $f['lengths']['epaules'],
            'date' => '2027-03-08', 'time' => '10:00', 'first_name' => 'Fatou', 'last_name' => 'Ba', 'phone' => '0700000001',
            'source' => 'phone', 'status' => 'confirmed',
        ]);

        self::assertSame(['garance@example.test'], array_map(static fn (MailMessage $m) => $m->to, $this->mailer->sent));
    }

    public function testContactMessageAlertsAdminWithReplyTo(): void
    {
        $this->browser()->post('/contact', ['name' => 'Awa', 'email' => 'awa@example.test', 'message' => 'Bonjour, faites-vous les locs ?', 'consent' => '1']);

        $alert = $this->mailsTo('garance@example.test')[0];
        self::assertSame('Nouveau message de Awa', $alert->subject);
        self::assertSame('awa@example.test', $alert->replyTo);
        self::assertStringContainsString('faites-vous les locs ?', $alert->text);
    }

    public function testAdminLogPageTestEmailAndRetry(): void
    {
        $admin = $this->loggedIn();

        self::assertSame(200, $admin->get('/admin/notifications')->status());
        $admin->post('/admin/notifications/test');
        self::assertStringStartsWith('E-mail de test envoyé à admin@example.test', (string) $admin->flash('success'));
        self::assertSame('E-mail de test — Hair_style by Garance', $this->mailsTo('admin@example.test')[0]->subject);

        $id = (int) $this->db->fetchValue("INSERT INTO notifications (channel, event, recipient, status, attempts, error) VALUES ('email', 'test', 'x@example.test', 'failed', 5, 'Erreur') RETURNING id");
        $admin->get('/admin/notifications');
        $admin->post("/admin/notifications/{$id}/renvoyer");
        self::assertSame('sent', $this->db->fetchValue('SELECT status FROM notifications WHERE id = :id', ['id' => $id]));

        self::assertSame(403, $this->loggedIn('staff', 'collab@example.test')->get('/admin/notifications')->status());
    }
}
