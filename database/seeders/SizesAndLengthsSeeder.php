<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeding\Seeder;

final class SizesAndLengthsSeeder extends Seeder
{
    public const SIZES = [
        'S' => 'Fines',
        'M' => 'Moyennes',
        'L' => 'Épaisses',
        'XL' => 'Très épaisses',
    ];

    /** De la plus courte à la plus longue. */
    public const LENGTHS = [
        'nuque' => 'Nuque',
        'epaules' => 'Épaules',
        'omoplates' => 'Omoplates',
        'soutien-gorge' => 'Attache du soutien-gorge',
        'mi-dos' => 'Mi-dos',
        'taille' => 'Taille',
        'hanches' => 'Hanches',
        'coccyx' => 'Coccyx',
        'classique' => 'Classique',
        'mi-cuisse' => 'Mi-cuisse',
    ];

    public function run(): void
    {
        $position = 0;
        foreach (self::SIZES as $code => $label) {
            $this->db->execute(
                'INSERT INTO service_sizes (code, label, position) VALUES (:code, :label, :position)
                 ON CONFLICT (code) DO NOTHING',
                ['code' => $code, 'label' => $label, 'position' => $position++],
            );
        }

        $position = 0;
        foreach (self::LENGTHS as $slug => $label) {
            $this->db->execute(
                'INSERT INTO service_lengths (slug, label, position) VALUES (:slug, :label, :position)
                 ON CONFLICT (slug) DO NOTHING',
                ['slug' => $slug, 'label' => $label, 'position' => $position++],
            );
        }
    }
}
