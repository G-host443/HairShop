<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Database;
use App\Services\Mail\MailMessage;
use DI\Container;
use Tests\Support\Browser;

/**
 * Avis : dépôt après un rendez-vous terminé, demande d'avis par e-mail, modération, affichage public.
 */
final class ReviewTest extends AdminTestCase
{
    use CatalogFixtures;
    use ContentFixtures;

    private const CUSTOMER_PASSWORD = 'Fulani-Tresses!2027';

    protected function database(): Database
    {
        return $this->db;
    }

    private function frozen(Browser $browser, string $at = '2027-03-01 08:00'): Browser
    {
        return $browser->configure(static fn (Container $c) => $c->get(Clock::class)->freeze($at));
    }

    /** Réserve en ligne et renvoie [id du rendez-vous, chemin /rdv/{jeton}]. */
    private function book(Browser $browser, string $email = 'awa@example.test'): array
    {
        $f = $this->matrixService();
        $browser->post('/reserver', [
            'service' => (string) $f['service'], 'variant' => (string) $f['variants']['M'], 'length' => (string) $f['lengths']['epaules'],
            'c' => '1', 'date' => '2027-03-08', 'time' => '10:00',
            'first_name' => 'Awa', 'last_name' => 'Diallo', 'email' => $email, 'phone' => '0622222222', 'rules' => '1',
        ]);
        $mails = array_values(array_filter($this->mailer->sent, static fn (MailMessage $mail) => $mail->to === $email));
        self::assertSame(1, preg_match('#(/rdv/[a-f0-9]{64})#', end($mails)->text, $m));

        return [(int) $this->db->fetchValue('SELECT max(id) FROM bookings'), $m[1]];
    }

    private function complete(Browser $admin, int $bookingId): void
    {
        $admin->post("/admin/reservations/{$bookingId}/statut", ['status' => 'confirmed']);
        $admin->post("/admin/reservations/{$bookingId}/statut", ['status' => 'completed']);
        self::assertSame('completed', $this->db->fetchValue('SELECT status FROM bookings WHERE id = :id', ['id' => $bookingId]));
    }

    public function testFullFlowFromRequestEmailToPublication(): void
    {
        $this->db->execute("UPDATE site_settings SET value = '\"garance@example.test\"' WHERE key = 'notifications.admin_email'");
        $customer = $this->frozen($this->browser('10.0.0.9'));
        [$bookingId, $manage] = $this->book($customer);
        $admin = $this->frozen($this->loggedIn());

        self::assertStringNotContainsString('Votre avis sur cette prestation', $customer->get($manage)->content(), 'Pas d\'avis avant la fin du rendez-vous.');

        $this->mailer->sent = [];
        $this->complete($admin, $bookingId);
        $request = array_values(array_filter($this->mailer->sent, static fn (MailMessage $m) => str_starts_with($m->subject, 'Votre avis compte')));
        self::assertCount(1, $request, 'E-mail de demande d\'avis envoyé.');
        self::assertStringContainsString($manage . '#avis', $request[0]->text);

        $page = $customer->get($manage)->content();
        self::assertStringContainsString('Votre avis sur cette prestation', $page);
        self::assertStringContainsString('value="Awa D."', $page, 'Nom affiché proposé : prénom + initiale.');

        $customer->post($manage . '/avis', ['rating' => '6', 'comment' => 'Court']);
        $errors = $customer->errors();
        self::assertArrayHasKey('rating', $errors);
        self::assertArrayHasKey('comment', $errors);
        $customer->get($manage);

        $this->mailer->sent = [];
        $response = $customer->post($manage . '/avis', ['rating' => '5', 'comment' => "Coiffure magnifique,\ntrès soignée et rapide. <b>Merci</b> !", 'author_name' => 'Awa']);
        self::assertSame($manage . '#avis', $response->header('Location'));
        $review = $this->db->fetchOne('SELECT id, status, rating, author_name, customer_id FROM reviews');
        self::assertSame(['pending', 5, 'Awa'], [$review['status'], (int) $review['rating'], $review['author_name']]);
        self::assertNotNull($review['customer_id']);
        self::assertStringStartsWith('Nouvel avis à modérer', $this->mailer->last()->subject);
        self::assertStringContainsString('Il sera publié après relecture', $customer->get($manage)->content());

        // Un seul avis par rendez-vous.
        $customer->post($manage . '/avis', ['rating' => '1', 'comment' => 'Deuxième avis pour tricher.']);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT count(*) FROM reviews'));

        // En attente : invisible.
        self::assertStringNotContainsString('Coiffure magnifique', $this->browser()->get('/avis')->content());

        self::assertStringContainsString('Coiffure magnifique', $admin->get('/admin/avis')->content());
        $admin->post("/admin/avis/{$review['id']}/approuver");
        $public = $this->browser()->get('/avis')->content();
        self::assertStringContainsString('Coiffure magnifique,<br />', $public, 'Sauts de ligne conservés…');
        self::assertStringContainsString('&lt;b&gt;Merci&lt;/b&gt;', $public, '… et HTML échappé.');
        self::assertStringContainsString('5,0', $public);
        self::assertStringContainsString('Coiffure magnifique', $this->browser()->get('/')->content(), 'Section Avis de l\'accueil.');
        self::assertStringContainsString('Lire tous les avis', $this->browser()->get('/')->content());
    }

    public function testRequestEmailCanBeDisabledAndBecomesObsolete(): void
    {
        $this->db->execute("UPDATE site_settings SET value = 'false' WHERE key = 'notifications.review_request'");
        [$bookingId] = $this->book($this->frozen($this->browser('10.0.0.9')));
        $this->mailer->sent = [];
        $this->complete($this->frozen($this->loggedIn()), $bookingId);

        self::assertSame([], array_filter($this->mailer->sent, static fn (MailMessage $m) => str_starts_with($m->subject, 'Votre avis compte')));
        self::assertSame(0, (int) $this->db->fetchValue("SELECT count(*) FROM notifications WHERE event = 'booking.review_request'"));
    }

    public function testCustomerAccountCanReview(): void
    {
        $this->createUser('awa@example.test', 'customer', self::CUSTOMER_PASSWORD);
        $customer = $this->frozen($this->browser('10.0.0.9'));
        $customer->post('/compte/connexion', ['email' => 'awa@example.test', 'password' => self::CUSTOMER_PASSWORD]);
        [$bookingId] = $this->book($customer);
        $reference = (string) $this->db->fetchValue('SELECT reference FROM bookings WHERE id = :id', ['id' => $bookingId]);
        $this->complete($this->frozen($this->loggedIn()), $bookingId);

        self::assertStringContainsString('Votre avis sur cette prestation', $customer->get("/compte/rendez-vous/{$reference}")->content());
        $customer->post("/compte/rendez-vous/{$reference}/avis", ['rating' => '4', 'comment' => 'Très bon moment, je reviendrai.', 'author_name' => '']);
        self::assertSame('Garance T.', $this->db->fetchValue('SELECT author_name FROM reviews'), 'Nom vide : prénom et initiale de la fiche du compte.');
    }

    public function testModerationActions(): void
    {
        $admin = $this->loggedIn();
        $photo = $this->image('portrait');

        $admin->post('/admin/avis', ['author_name' => 'Fatou K.', 'rating' => '5', 'comment' => 'Reçu sur Instagram : superbe travail !']);
        $id = (int) $this->db->fetchValue("SELECT id FROM reviews WHERE author_name = 'Fatou K.'");
        self::assertSame('approved', $this->db->fetchValue('SELECT status FROM reviews WHERE id = :id', ['id' => $id]), 'Saisi par l\'admin : publié directement.');

        $admin->post("/admin/avis/{$id}/mise-en-avant");
        self::assertTrue((bool) $this->db->fetchValue('SELECT is_featured FROM reviews WHERE id = :id', ['id' => $id]));

        $admin->post("/admin/avis/{$id}", ['author_name' => 'Fatou', 'photo_media_id' => (string) $photo]);
        self::assertSame('Fatou', $this->db->fetchValue('SELECT author_name FROM reviews WHERE id = :id', ['id' => $id]));
        self::assertStringContainsString('testimonial-photo', $this->browser()->get('/avis')->content());

        $admin->post("/admin/avis/{$id}/refuser");
        $row = $this->db->fetchOne('SELECT status, is_featured FROM reviews WHERE id = :id', ['id' => $id]);
        self::assertSame(['rejected', false], [$row['status'], (bool) $row['is_featured']], 'Retiré du site et plus mis en avant.');
        self::assertStringNotContainsString('superbe travail', $this->browser()->get('/avis')->content());

        $admin->post("/admin/avis/{$id}/supprimer");
        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM reviews'));

        self::assertSame(403, $this->loggedIn('staff', 'staff@example.test')->get('/admin/avis')->status());
    }
}
