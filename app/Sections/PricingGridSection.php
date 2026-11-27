<?php

declare(strict_types=1);

namespace App\Sections;

use App\Services\Catalog\BookingLinkBuilder;
use App\Services\Catalog\CatalogReader;

final class PricingGridSection implements SectionType
{
    public function __construct(
        private readonly CatalogReader $catalog,
        private readonly BookingLinkBuilder $links,
    ) {
    }

    public function type(): string
    {
        return 'pricing_grid';
    }

    public function label(): string
    {
        return 'Tarifs';
    }

    public function fields(): array
    {
        return [
            'anchor' => ['label' => 'Ancre (lien du menu)', 'type' => 'anchor'],
            'title' => ['label' => 'Titre', 'type' => 'text', 'max' => 80],
            'intro' => ['label' => 'Introduction', 'type' => 'textarea', 'max' => 400],
        ];
    }

    public function prepare(array $content): ?array
    {
        $categories = $this->catalog->categoriesWithServices();
        if ($categories === []) {
            return null;
        }

        return [
            'title' => Content::text($content, 'title', 'Tarifs'),
            'intro' => Content::text($content, 'intro'),
            'categories' => $categories,
            // Données du sélecteur interactif, encodées pour être insérées sans risque dans la page.
            'selector_json' => self::json([
                'categories' => $this->catalog->selectorData(),
                'booking' => $this->links->config(),
            ]),
        ];
    }

    /** JSON sûr dans un élément <script type="application/json"> (« </script> » neutralisé). */
    public static function json(mixed $data): string
    {
        return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
