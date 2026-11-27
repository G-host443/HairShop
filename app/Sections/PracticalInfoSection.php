<?php

declare(strict_types=1);

namespace App\Sections;

use App\Core\Database;
use App\Services\Schedule\OpeningHoursService;
use App\Services\Settings\SettingsService;

final class PracticalInfoSection implements SectionType
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly OpeningHoursService $hours,
        private readonly Database $db,
    ) {
    }

    public function type(): string
    {
        return 'practical_info';
    }

    public function label(): string
    {
        return 'Infos pratiques (lieu, horaires, règles)';
    }

    public function fields(): array
    {
        return [
            'anchor' => ['label' => 'Ancre (lien du menu)', 'type' => 'anchor'],
            'title' => ['label' => 'Titre', 'type' => 'text', 'max' => 80],
            'show_location' => ['label' => 'Afficher le lieu', 'type' => 'bool', 'default' => true],
            'show_hours' => ['label' => 'Afficher les horaires', 'type' => 'bool', 'default' => true],
            'show_rules' => ['label' => 'Afficher les règles « À savoir »', 'type' => 'bool', 'default' => true],
        ];
    }

    public function prepare(array $content): ?array
    {
        $location = null;
        if (Content::bool($content, 'show_location', true)) {
            $isSalon = $this->settings->string('contact.location_type', 'home') === 'salon';
            $location = [
                'is_salon' => $isSalon,
                'public' => $this->settings->string('contact.public_location'),
                // L'adresse exacte n'est publique que pour un salon ; à domicile, elle est envoyée à la confirmation.
                'address' => $isSalon ? $this->settings->string('contact.private_address') : '',
            ];
            if ($location['public'] === '' && $location['address'] === '') {
                $location = null;
            }
        }

        $hours = Content::bool($content, 'show_hours', true) ? $this->hours->summary() : [];
        $closures = $hours !== [] ? $this->hours->upcomingClosures() : [];

        $rules = Content::bool($content, 'show_rules', true)
            ? array_column($this->db->fetchAll('SELECT body FROM booking_rules WHERE is_active ORDER BY position, id'), 'body')
            : [];

        if ($location === null && $hours === [] && $rules === []) {
            return null;
        }

        return [
            'title' => Content::text($content, 'title', 'Infos pratiques'),
            'location' => $location,
            'hours' => $hours,
            'closures' => $closures,
            'rules' => $rules,
        ];
    }
}
