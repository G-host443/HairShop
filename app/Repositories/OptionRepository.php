<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class OptionRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> groupes avec leurs options (clé « options ») */
    public function groupsWithOptions(bool $activeOnly = false): array
    {
        $groups = $this->db->fetchAll('SELECT * FROM option_groups ORDER BY position, id');
        $options = $this->db->fetchAll(
            'SELECT * FROM service_options' . ($activeOnly ? ' WHERE is_active' : '') . ' ORDER BY position, id'
        );

        $byGroup = [];
        foreach ($options as $option) {
            $byGroup[(int) $option['group_id']][] = $option;
        }
        foreach ($groups as &$group) {
            $group['options'] = $byGroup[(int) $group['id']] ?? [];
        }

        return $groups;
    }

    /** @return array<string, mixed>|null */
    public function findGroup(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM option_groups WHERE id = :id', ['id' => $id]);
    }

    /** @return list<int> */
    public function groupIds(): array
    {
        return array_map('intval', array_column($this->db->fetchAll('SELECT id FROM option_groups'), 'id'));
    }

    public function createGroup(string $code, string $label, string $selection, bool $required): int
    {
        $this->forget();
        return (int) $this->db->fetchValue(
            'INSERT INTO option_groups (code, label, selection, is_required, position)
             VALUES (:code, :label, :selection, :required, (SELECT COALESCE(max(position), -1) + 1 FROM option_groups)) RETURNING id',
            ['code' => $code, 'label' => $label, 'selection' => $selection, 'required' => $required],
        );
    }

    public function updateGroup(int $id, string $label, string $selection, bool $required): void
    {
        $this->forget();
        $this->db->execute(
            'UPDATE option_groups SET label = :label, selection = :selection, is_required = :required WHERE id = :id',
            ['label' => $label, 'selection' => $selection, 'required' => $required, 'id' => $id],
        );
    }

    public function groupCodeExists(string $code): bool
    {
        return (bool) $this->db->fetchValue('SELECT EXISTS (SELECT 1 FROM option_groups WHERE code = :c)', ['c' => $code]);
    }

    public function groupIsUsed(int $id): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS (SELECT 1 FROM booking_options bo JOIN service_options o ON o.id = bo.option_id WHERE o.group_id = :id)',
            ['id' => $id],
        );
    }

    public function deleteGroup(int $id): void
    {
        $this->forget();
        $this->db->execute('DELETE FROM option_groups WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string, mixed>|null */
    public function findOption(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM service_options WHERE id = :id', ['id' => $id]);
    }

    /** @param array{name: string, description: ?string, price_kind: string, price: ?string, extra_minutes: int, is_active: bool} $data */
    public function createOption(int $groupId, array $data): int
    {
        $this->forget();
        return (int) $this->db->fetchValue(
            'INSERT INTO service_options (group_id, name, description, price_kind, price, extra_minutes, is_active, position)
             VALUES (:g, :name, :description, :kind, :price, :minutes, :active,
                     (SELECT COALESCE(max(position), -1) + 1 FROM service_options WHERE group_id = :g2)) RETURNING id',
            ['g' => $groupId, 'g2' => $groupId, 'name' => $data['name'], 'description' => $data['description'], 'kind' => $data['price_kind'],
             'price' => $data['price'], 'minutes' => $data['extra_minutes'], 'active' => $data['is_active']],
        );
    }

    /** @param array{name: string, description: ?string, price_kind: string, price: ?string, extra_minutes: int, is_active: bool} $data */
    public function updateOption(int $id, array $data): void
    {
        $this->forget();
        $this->db->execute(
            'UPDATE service_options SET name = :name, description = :description, price_kind = :kind, price = :price,
                    extra_minutes = :minutes, is_active = :active WHERE id = :id',
            ['name' => $data['name'], 'description' => $data['description'], 'kind' => $data['price_kind'], 'price' => $data['price'],
             'minutes' => $data['extra_minutes'], 'active' => $data['is_active'], 'id' => $id],
        );
    }

    public function optionNameTaken(int $groupId, string $name, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT EXISTS (SELECT 1 FROM service_options WHERE group_id = :g AND lower(name) = lower(:n) AND id IS DISTINCT FROM CAST(:id AS bigint))',
            ['g' => $groupId, 'n' => $name, 'id' => $exceptId],
        );
    }

    public function optionIsUsed(int $id): bool
    {
        return (bool) $this->db->fetchValue('SELECT EXISTS (SELECT 1 FROM booking_options WHERE option_id = :id)', ['id' => $id]);
    }

    public function deleteOption(int $id): void
    {
        $this->forget();
        $this->db->execute('DELETE FROM service_options WHERE id = :id', ['id' => $id]);
    }

    /**
     * Groupes d'options actifs applicables à une prestation : ceux de sa catégorie, moins les exclusions,
     * plus les ajouts propres à la prestation. Chaque groupe contient ses options actives.
     *
     * @return list<array<string, mixed>>
     */
    public function applicableGroups(int $serviceId): array
    {
        $groupIds = $this->applicableIds[$serviceId] ?? array_map('intval', array_column($this->db->fetchAll(
            'SELECT g.id FROM option_groups g
             WHERE (
                 EXISTS (SELECT 1 FROM option_group_assignments a JOIN services s ON s.category_id = a.category_id
                         WHERE a.option_group_id = g.id AND s.id = :s1 AND NOT a.is_excluded)
                 OR EXISTS (SELECT 1 FROM option_group_assignments a WHERE a.option_group_id = g.id AND a.service_id = :s2 AND NOT a.is_excluded)
             )
             AND NOT EXISTS (SELECT 1 FROM option_group_assignments a WHERE a.option_group_id = g.id AND a.service_id = :s3 AND a.is_excluded)
             ORDER BY g.position, g.id',
            ['s1' => $serviceId, 's2' => $serviceId, 's3' => $serviceId],
        ), 'id'));

        if ($groupIds === []) {
            return [];
        }

        // Listes de tarifs (après preloadApplicable) : groupes et options actifs chargés une seule fois.
        // Calcul isolé (devis, réservation) : toujours relu, pour refléter le catalogue à jour.
        $active = $this->applicableIds !== []
            ? ($this->activeGroups ??= $this->groupsWithOptions(activeOnly: true))
            : $this->groupsWithOptions(activeOnly: true);
        $groups = array_filter($active, static fn (array $g): bool => in_array((int) $g['id'], $groupIds, true));

        return array_values(array_filter($groups, static fn (array $g): bool => $g['options'] !== []));
    }

    /** @var list<array<string, mixed>>|null */
    private ?array $activeGroups = null;

    private function forget(): void
    {
        $this->activeGroups = null;
        $this->applicableIds = [];
    }

    /** @var array<int, list<int>> groupes applicables préchargés, par prestation */
    private array $applicableIds = [];

    /**
     * Précharge en une requête les groupes applicables à plusieurs prestations
     * (évite une requête par prestation dans les listes de tarifs).
     *
     * @param list<int> $serviceIds
     */
    public function preloadApplicable(array $serviceIds): void
    {
        $serviceIds = array_values(array_unique(array_map('intval', $serviceIds)));
        if ($serviceIds === []) {
            return;
        }
        foreach ($serviceIds as $id) {
            $this->applicableIds[$id] = [];
        }
        foreach ($this->db->fetchAll(
            'SELECT s.id AS service_id, g.id AS group_id
             FROM services s CROSS JOIN option_groups g
             WHERE s.id = ANY (CAST(:ids AS bigint[]))
               AND (
                   EXISTS (SELECT 1 FROM option_group_assignments a WHERE a.option_group_id = g.id AND a.category_id = s.category_id AND NOT a.is_excluded)
                   OR EXISTS (SELECT 1 FROM option_group_assignments a WHERE a.option_group_id = g.id AND a.service_id = s.id AND NOT a.is_excluded)
               )
               AND NOT EXISTS (SELECT 1 FROM option_group_assignments a WHERE a.option_group_id = g.id AND a.service_id = s.id AND a.is_excluded)
             ORDER BY s.id, g.position, g.id',
            ['ids' => '{' . implode(',', $serviceIds) . '}'],
        ) as $row) {
            $this->applicableIds[(int) $row['service_id']][] = (int) $row['group_id'];
        }
    }
}
