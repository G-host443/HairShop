<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeding\Seeder;

/**
 * Règles « À savoir avant de venir ». Insérées seulement si la table est vide,
 * pour ne jamais écraser les règles modifiées depuis l'administration.
 */
final class BookingRulesSeeder extends Seeder
{
    private const RULES = [
        'Venez avec les cheveux propres et démêlés.',
        'Toute annulation doit être faite au moins 48 h avant le rendez-vous.',
        'Les mèches peuvent être fournies sur demande, avec un supplément.',
        'Une seule finition au choix : perles (+10 €, à apporter), nœuds (+10 €) ou boucles (offertes).',
        'Les longueurs Classique et Mi-cuisse sont sur devis.',
    ];

    public function run(): void
    {
        if ((int) $this->db->fetchValue('SELECT count(*) FROM booking_rules') > 0) {
            return;
        }

        foreach (self::RULES as $position => $body) {
            $this->db->execute(
                'INSERT INTO booking_rules (body, position) VALUES (:body, :position)',
                ['body' => $body, 'position' => $position],
            );
        }
    }
}
