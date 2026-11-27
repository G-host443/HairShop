<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeding\Seeder;

final class BookingStatusesSeeder extends Seeder
{
    /**
     * blocks_slot doit correspondre à la contrainte bookings_no_overlap
     * (pending, confirmed, in_progress bloquent le créneau).
     */
    private const STATUSES = [
        ['pending', 'En attente', '#DFB05E', true],
        ['confirmed', 'Confirmée', '#3E7B4F', true],
        ['in_progress', 'En cours', '#3B6EA5', true],
        ['completed', 'Terminée', '#6D5B54', false],
        ['cancelled', 'Annulée', '#9A7F73', false],
        ['refused', 'Refusée', '#A23B2A', false],
        ['no_show', 'Absence de la cliente', '#5E4712', false],
    ];

    public function run(): void
    {
        foreach (self::STATUSES as $position => [$code, $label, $color, $blocks]) {
            $this->db->execute(
                'INSERT INTO booking_statuses (code, label, color, blocks_slot, position)
                 VALUES (:code, :label, :color, :blocks, :position)
                 ON CONFLICT (code) DO UPDATE
                 SET label = EXCLUDED.label, color = EXCLUDED.color, blocks_slot = EXCLUDED.blocks_slot, position = EXCLUDED.position',
                ['code' => $code, 'label' => $label, 'color' => $color, 'blocks' => $blocks, 'position' => $position],
            );
        }
    }
}
