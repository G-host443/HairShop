<?php

declare(strict_types=1);

namespace App\Sections;

use App\Core\Clock;
use App\Repositories\PromotionRepository;
use App\Services\Catalog\PromotionService;
use DateTimeImmutable;

/**
 * Offres en cours. Une promotion terminée ou désactivée disparaît d'elle-même ;
 * sans aucune offre en cours, la section ne s'affiche pas.
 */
final class PromotionsSection implements SectionType
{
    public function __construct(
        private readonly PromotionRepository $promotions,
        private readonly Clock $clock,
    ) {
    }

    public function type(): string
    {
        return 'promotions';
    }

    public function label(): string
    {
        return 'Promotions en cours';
    }

    public function fields(): array
    {
        return [
            'anchor' => ['label' => 'Ancre (lien du menu)', 'type' => 'anchor'],
            'title' => ['label' => 'Titre', 'type' => 'text', 'max' => 80],
            'intro' => ['label' => 'Introduction', 'type' => 'textarea', 'max' => 300],
        ];
    }

    public function prepare(array $content): ?array
    {
        $offers = [];
        foreach ($this->promotions->activeWithServices($this->clock->now()->format(DATE_ATOM)) as $row) {
            $id = (int) $row['id'];
            $offers[$id] ??= [
                'name' => (string) $row['name'],
                'description' => $row['description'] !== null ? (string) $row['description'] : '',
                'badge' => PromotionService::badge($row),
                'last_day' => (new DateTimeImmutable((string) $row['ends_at']))->setTimezone($this->clock->zone())->modify('-1 second')->format('Y-m-d'),
                'services' => [],
            ];
            $offers[$id]['services'][] = ['name' => (string) $row['service_name'], 'slug' => (string) $row['service_slug']];
        }

        if ($offers === []) {
            return null;
        }

        return [
            'title' => Content::text($content, 'title', 'Offres du moment'),
            'intro' => Content::text($content, 'intro'),
            'offers' => array_values($offers),
        ];
    }
}
