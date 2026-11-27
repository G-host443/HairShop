<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeding\Seeder;

/**
 * Groupes d'options et options par défaut (finitions, entretien, suppléments).
 * Les rattachements aux catégories sont faits par le seeder du catalogue.
 */
final class OptionGroupsSeeder extends Seeder
{
    private const GROUPS = [
        'finish' => ['Finition souhaitée', 'single'],
        'care' => ['Entretien en plus', 'single'],
        'extra' => ['Suppléments', 'multiple'],
    ];

    /** [groupe, nom, description, price_kind, prix, minutes en plus] */
    private const OPTIONS = [
        ['finish', 'Perles', 'Perles à apporter par vos soins.', 'fixed', 10, 15],
        ['finish', 'Nœuds en finition', null, 'fixed', 10, 15],
        ['finish', 'Boucles en finition', null, 'fixed', 0, 10],
        ['care', 'Défaire braids + shampoing', 'Le prix final dépend du volume et de la longueur.', 'from', 50, 90],
        ['care', 'Défaire locs + shampoing', 'Le prix final dépend du volume et de la longueur.', 'from', 100, 150],
        ['care', 'Shampoing simple', null, 'fixed', 10, 20],
        ['extra', 'Mèches fournies', 'Mèches achetées par la coiffeuse ; le montant est confirmé selon la coiffure.', 'quote', null, 0],
        ['extra', 'Knotless à l\'arrière', 'Remplace les box braids à l\'arrière par des knotless.', 'fixed', 15, 30],
    ];

    public function run(): void
    {
        $position = 0;
        foreach (self::GROUPS as $code => [$label, $selection]) {
            $this->db->execute(
                'INSERT INTO option_groups (code, label, selection, position) VALUES (:code, :label, :selection, :position)
                 ON CONFLICT (code) DO NOTHING',
                ['code' => $code, 'label' => $label, 'selection' => $selection, 'position' => $position++],
            );
        }

        foreach (self::OPTIONS as $position => [$group, $name, $description, $kind, $price, $minutes]) {
            $this->db->execute(
                'INSERT INTO service_options (group_id, name, description, price_kind, price, extra_minutes, position)
                 SELECT id, :name, :description, :kind, :price, :minutes, :position FROM option_groups WHERE code = :group
                 ON CONFLICT (group_id, name) DO NOTHING',
                [
                    'group' => $group,
                    'name' => $name,
                    'description' => $description,
                    'kind' => $kind,
                    'price' => $price === null ? null : (string) $price,
                    'minutes' => $minutes,
                    'position' => $position,
                ],
            );
        }
    }
}
