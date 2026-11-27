<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeding\Seeder;

/**
 * Horaires d'exemple (à ajuster par Garance dans l'administration).
 * Insérés seulement si aucun horaire n'existe.
 */
final class OpeningHoursSeeder extends Seeder
{
    /** jour ISO => liste de plages [ouverture, fermeture] ; mercredi (3) fermé. */
    private const HOURS = [
        1 => [['10:00', '19:00']],
        2 => [['10:00', '15:00']],
        4 => [['10:00', '19:00']],
        5 => [['10:00', '19:00']],
        6 => [['10:00', '19:00']],
        7 => [['10:00', '19:00']],
    ];

    public function run(): void
    {
        if ((int) $this->db->fetchValue('SELECT count(*) FROM opening_hours') > 0) {
            return;
        }

        foreach (self::HOURS as $weekday => $ranges) {
            foreach ($ranges as [$opens, $closes]) {
                $this->db->execute(
                    'INSERT INTO opening_hours (weekday, opens_at, closes_at) VALUES (:d, :o, :c)',
                    ['d' => $weekday, 'o' => $opens, 'c' => $closes],
                );
            }
        }
    }
}
