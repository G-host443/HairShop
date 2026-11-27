<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class PromotionRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> toutes les promotions, avec le nombre de prestations concernées */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT p.*, (SELECT count(*) FROM promotion_services ps WHERE ps.promotion_id = p.id) AS services_count
             FROM promotions p ORDER BY p.ends_at DESC, p.id DESC'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM promotions WHERE id = :id', ['id' => $id]);
    }

    /** @return list<int> */
    public function serviceIds(int $promotionId): array
    {
        return array_map('intval', array_column(
            $this->db->fetchAll('SELECT service_id FROM promotion_services WHERE promotion_id = :id ORDER BY service_id', ['id' => $promotionId]),
            'service_id',
        ));
    }

    /**
     * Promotions en cours à l'instant donné, avec leurs prestations.
     *
     * @return list<array{id: int, name: string, description: ?string, discount_type: string, value: string, starts_at: string, ends_at: string, service_id: int}>
     */
    public function activeAt(string $at): array
    {
        return $this->db->fetchAll(
            'SELECT p.id, p.name, p.description, p.discount_type, p.value, p.starts_at, p.ends_at, ps.service_id
             FROM promotions p JOIN promotion_services ps ON ps.promotion_id = p.id
             WHERE p.is_active AND p.starts_at <= CAST(:at AS timestamptz) AND p.ends_at > CAST(:at2 AS timestamptz)
             ORDER BY p.ends_at, p.id',
            ['at' => $at, 'at2' => $at],
        );
    }

    /**
     * Promotions en cours avec les prestations actives concernées (affichage public).
     *
     * @return list<array<string, mixed>>
     */
    public function activeWithServices(string $at): array
    {
        return $this->db->fetchAll(
            'SELECT p.id, p.name, p.description, p.discount_type, p.value, p.ends_at, s.name AS service_name, s.slug AS service_slug
             FROM promotions p
             JOIN promotion_services ps ON ps.promotion_id = p.id
             JOIN services s ON s.id = ps.service_id AND s.is_active
             JOIN service_categories c ON c.id = s.category_id AND c.is_active
             WHERE p.is_active AND p.starts_at <= CAST(:at AS timestamptz) AND p.ends_at > CAST(:at2 AS timestamptz)
             ORDER BY p.ends_at, p.id, s.position, s.name',
            ['at' => $at, 'at2' => $at],
        );
    }

    /** @param array{name: string, description: ?string, discount_type: string, value: string, starts_at: string, ends_at: string, is_active: bool} $data */
    public function create(array $data): int
    {
        return (int) $this->db->fetchValue(
            'INSERT INTO promotions (name, description, discount_type, value, starts_at, ends_at, is_active)
             VALUES (:name, :description, :type, :value, CAST(:starts AS timestamptz), CAST(:ends AS timestamptz), :active) RETURNING id',
            self::params($data),
        );
    }

    /** @param array{name: string, description: ?string, discount_type: string, value: string, starts_at: string, ends_at: string, is_active: bool} $data */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE promotions SET name = :name, description = :description, discount_type = :type, value = :value,
                starts_at = CAST(:starts AS timestamptz), ends_at = CAST(:ends AS timestamptz), is_active = :active
             WHERE id = :id',
            self::params($data) + ['id' => $id],
        );
    }

    /** @param list<int> $serviceIds */
    public function setServices(int $promotionId, array $serviceIds): void
    {
        $this->db->execute('DELETE FROM promotion_services WHERE promotion_id = :id', ['id' => $promotionId]);
        foreach (array_unique($serviceIds) as $serviceId) {
            $this->db->execute('INSERT INTO promotion_services (promotion_id, service_id) VALUES (:p, :s)', ['p' => $promotionId, 's' => $serviceId]);
        }
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->execute('UPDATE promotions SET is_active = :a WHERE id = :id', ['id' => $id, 'a' => $active]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM promotions WHERE id = :id', ['id' => $id]);
    }

    public function bookingsCount(int $id): int
    {
        return (int) $this->db->fetchValue('SELECT count(*) FROM bookings WHERE promotion_id = :id', ['id' => $id]);
    }

    private static function params(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'],
            'type' => $data['discount_type'],
            'value' => $data['value'],
            'starts' => $data['starts_at'],
            'ends' => $data['ends_at'],
            'active' => $data['is_active'],
        ];
    }
}
