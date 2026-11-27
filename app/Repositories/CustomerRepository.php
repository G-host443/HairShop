<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class CustomerRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Cliente existante reconnue par son e-mail, sinon par son téléphone ; créée sinon.
     * Les coordonnées manquantes d'une fiche existante sont complétées.
     *
     * Une fiche rattachée à un compte dont l'e-mail n'est pas encore vérifié est ignorée :
     * sinon, quelqu'un qui s'inscrirait avec l'adresse d'une autre personne verrait ses réservations.
     */
    public function findOrCreate(string $firstName, string $lastName, ?string $email, ?string $phone, bool $marketingConsent = false): int
    {
        $existing = null;
        if ($email !== null) {
            $existing = $this->db->fetchOne(
                'SELECT c.id FROM customers c LEFT JOIN users u ON u.id = c.user_id
                 WHERE c.email = :e AND (c.user_id IS NULL OR u.email_verified_at IS NOT NULL)
                 ORDER BY c.user_id IS NULL, c.id LIMIT 1',
                ['e' => $email],
            );
        }
        if ($existing === null && $phone !== null) {
            // Même normalisation qu'en PHP : chiffres seuls, +33 / 0033 remplacés par 0.
            $existing = $this->db->fetchOne(
                "SELECT c.id FROM customers c LEFT JOIN users u ON u.id = c.user_id
                 WHERE regexp_replace(regexp_replace(c.phone, '[^0-9+]', '', 'g'), '^(\\+33|0033)', '0') = :p
                   AND (c.user_id IS NULL OR u.email_verified_at IS NOT NULL)
                 ORDER BY c.user_id IS NULL, c.id LIMIT 1",
                ['p' => self::normalizePhone($phone)],
            );
        }

        if ($existing !== null) {
            $this->db->execute(
                'UPDATE customers SET email = COALESCE(email, :e), phone = COALESCE(phone, :p),
                        marketing_consent = marketing_consent OR :m WHERE id = :id',
                ['e' => $email, 'p' => $phone, 'm' => $marketingConsent, 'id' => (int) $existing['id']],
            );

            return (int) $existing['id'];
        }

        return $this->create($firstName, $lastName, $email, $phone, $marketingConsent, null);
    }

    public function create(string $firstName, string $lastName, ?string $email, ?string $phone, bool $marketingConsent, ?int $userId): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO customers (first_name, last_name, email, phone, marketing_consent, user_id) VALUES (:f, :l, :e, :p, :m, :u) RETURNING id',
            ['f' => $firstName, 'l' => $lastName, 'e' => $email, 'p' => $phone, 'm' => $marketingConsent, 'u' => $userId],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM customers WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null fiche cliente d'un compte */
    public function findByUserId(int $userId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM customers WHERE user_id = :u', ['u' => $userId]);
    }

    /** @param array{first_name: string, last_name: string, email: string, phone: ?string, marketing_consent: bool} $data */
    public function updateProfile(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE customers SET first_name = :f, last_name = :l, email = :e, phone = :p, marketing_consent = :m WHERE id = :id',
            ['f' => $data['first_name'], 'l' => $data['last_name'], 'e' => $data['email'], 'p' => $data['phone'], 'm' => $data['marketing_consent'], 'id' => $id],
        );
    }

    /**
     * Rattache au compte les fiches « invitée » (sans compte) portant la même adresse e-mail :
     * leurs réservations, favoris, notes et avis passent sur la fiche du compte, puis elles sont supprimées.
     * À n'appeler qu'une fois l'adresse vérifiée.
     *
     * @return int nombre de fiches fusionnées
     */
    public function mergeGuestsInto(int $targetId, string $email): int
    {
        return $this->db->transaction(function () use ($targetId, $email): int {
            $guests = array_map('intval', array_column($this->db->fetchAll(
                'SELECT id FROM customers WHERE email = :e AND user_id IS NULL AND id <> :t',
                ['e' => $email, 't' => $targetId],
            ), 'id'));

            foreach ($guests as $guestId) {
                $this->db->execute('UPDATE bookings SET customer_id = :t WHERE customer_id = :g', ['t' => $targetId, 'g' => $guestId]);
                $this->db->execute('UPDATE customer_notes SET customer_id = :t WHERE customer_id = :g', ['t' => $targetId, 'g' => $guestId]);
                $this->db->execute('UPDATE reviews SET customer_id = :t WHERE customer_id = :g', ['t' => $targetId, 'g' => $guestId]);
                $this->db->execute(
                    'INSERT INTO favorites (customer_id, service_id, created_at) SELECT :t, service_id, created_at FROM favorites WHERE customer_id = :g ON CONFLICT DO NOTHING',
                    ['t' => $targetId, 'g' => $guestId],
                );
                $this->db->execute('DELETE FROM customers WHERE id = :g', ['g' => $guestId]);
            }

            return count($guests);
        });
    }

    /** Détache la fiche du compte supprimé ; l'historique des rendez-vous est conservé pour le salon. */
    public function detachFromUser(int $customerId): void
    {
        $this->db->execute('UPDATE customers SET user_id = NULL, marketing_consent = false WHERE id = :id', ['id' => $customerId]);
        $this->db->execute('DELETE FROM favorites WHERE customer_id = :id', ['id' => $customerId]);
    }

    // ---------- Favoris ----------

    /** @return list<int> */
    public function favoriteServiceIds(int $customerId): array
    {
        return array_map('intval', array_column($this->db->fetchAll('SELECT service_id FROM favorites WHERE customer_id = :c', ['c' => $customerId]), 'service_id'));
    }

    /** @return bool true si ajouté, false si retiré */
    public function toggleFavorite(int $customerId, int $serviceId): bool
    {
        $removed = $this->db->execute('DELETE FROM favorites WHERE customer_id = :c AND service_id = :s', ['c' => $customerId, 's' => $serviceId]);
        if ($removed > 0) {
            return false;
        }
        $this->db->execute('INSERT INTO favorites (customer_id, service_id) VALUES (:c, :s) ON CONFLICT DO NOTHING', ['c' => $customerId, 's' => $serviceId]);

        return true;
    }

    /** « 06 12 34 56 78 », « +33 6 12 34 56 78 », « 0033612345678 » → « 0612345678 ». */
    public static function normalizePhone(string $phone): string
    {
        $digits = (string) preg_replace('/[^0-9+]/', '', $phone);

        return (string) preg_replace('/^(\+33|0033)/', '0', $digits);
    }
}
