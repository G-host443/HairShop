<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Core\Database;
use App\Repositories\MediaRepository;
use App\Repositories\OptionRepository;

/**
 * Lecture du catalogue pour le site public : catégories et prestations actives, résumé de prix
 * (« à partir de », « sur devis ») et données complètes du sélecteur interactif.
 * Les promotions en cours sont déjà appliquées aux prix ; le prix d'origine est fourni pour être barré.
 */
final class CatalogReader
{
    public function __construct(
        private readonly Database $db,
        private readonly OptionRepository $options,
        private readonly MediaRepository $media,
        private readonly PromotionService $promotions,
    ) {
    }

    /**
     * @return list<array{id: int, slug: string, name: string, note: ?string, services: list<array<string, mixed>>}>
     */
    public function categoriesWithServices(): array
    {
        return $this->categories ??= $this->loadCategoriesWithServices();
    }

    /** @var list<array<string, mixed>>|null catalogue de la requête en cours (lu une seule fois) */
    private ?array $categories = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $selector = null;

    /** @return list<array<string, mixed>> */
    private function loadCategoriesWithServices(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT c.id AS category_id, c.slug AS category_slug, c.name AS category_name, c.header_note,
                    s.id, s.slug, s.name, s.summary, s.pricing_mode, s.price_kind, s.price, s.alert_text, s.note,
                    s.duration_minutes, m.min_price, COALESCE(m.has_quote, false) AS has_quote,
                    m.sizes
             FROM service_categories c
             JOIN services s ON s.category_id = c.id AND s.is_active
             LEFT JOIN LATERAL (
                 SELECT min(vp.price) FILTER (WHERE vp.price_kind = 'fixed') AS min_price,
                        bool_or(vp.price_kind = 'quote') AS has_quote,
                        string_agg(DISTINCT z.code, ', ' ORDER BY z.code) AS sizes
                 FROM service_variants v
                 JOIN service_sizes z ON z.id = v.size_id
                 JOIN variant_prices vp ON vp.variant_id = v.id
                 WHERE v.service_id = s.id AND v.is_active
             ) m ON s.pricing_mode = 'matrix'
             WHERE c.is_active
             ORDER BY c.position, c.name, s.position, s.name"
        );

        $categories = [];
        foreach ($rows as $row) {
            $id = (int) $row['category_id'];
            $categories[$id] ??= [
                'id' => $id,
                'slug' => (string) $row['category_slug'],
                'name' => (string) $row['category_name'],
                'note' => $row['header_note'] !== null ? (string) $row['header_note'] : null,
                'services' => [],
            ];

            $promotion = $this->promotionFor($row);
            $categories[$id]['services'][] = [
                'id' => (int) $row['id'],
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'summary' => $row['summary'],
                'alert' => $row['alert_text'],
                'note' => $row['note'],
                'mode' => (string) $row['pricing_mode'],
                'price_label' => $this->priceLabel($row, $promotion),
                'original_price_label' => $promotion !== null ? self::euros((float) $promotion['price'] + (float) $promotion['discount']) : null,
                'promotion' => $promotion,
                'sizes' => $row['sizes'] !== null ? $this->sortSizes((string) $row['sizes']) : null,
            ];
        }

        return array_values($categories);
    }

    /**
     * Données du sélecteur de tarifs : pour chaque prestation, ses variantes, les prix par longueur
     * et les options applicables. Transmises au navigateur en JSON.
     *
     * @return list<array<string, mixed>> catégories
     */
    public function selectorData(): array
    {
        return $this->selector ??= $this->loadSelectorData();
    }

    /** @return list<array<string, mixed>> */
    private function loadSelectorData(): array
    {
        $categories = $this->categoriesWithServices();
        if ($categories === []) {
            return [];
        }

        $serviceIds = [];
        foreach ($categories as $category) {
            foreach ($category['services'] as $service) {
                $serviceIds[] = $service['id'];
            }
        }
        $idList = '{' . implode(',', $serviceIds) . '}';
        $this->options->preloadApplicable($serviceIds);

        $details = [];
        foreach ($this->db->fetchAll('SELECT id, pricing_mode, price_kind, price, duration_minutes FROM services WHERE id = ANY (CAST(:ids AS bigint[]))', ['ids' => $idList]) as $row) {
            $details[(int) $row['id']] = $row;
        }

        $variantRows = $this->db->fetchAll(
            'SELECT v.id, v.service_id, v.label, v.note, v.image_media_id, v.duration_minutes, z.code AS size_code, z.label AS size_label
             FROM service_variants v JOIN service_sizes z ON z.id = v.size_id
             WHERE v.is_active AND v.service_id = ANY (CAST(:ids AS bigint[]))
             ORDER BY z.position, v.position, v.id',
            ['ids' => $idList],
        );
        $cellRows = $this->db->fetchAll(
            'SELECT v.service_id, vp.variant_id, vp.length_id, vp.price_kind, vp.price, vp.duration_minutes, l.label AS length_label
             FROM variant_prices vp
             JOIN service_lengths l ON l.id = vp.length_id
             JOIN service_variants v ON v.id = vp.variant_id
             WHERE v.is_active AND v.service_id = ANY (CAST(:ids AS bigint[]))
             ORDER BY l.position, l.id',
            ['ids' => $idList],
        );

        $media = $this->media->findMany(array_filter(array_column($variantRows, 'image_media_id')));

        $cells = [];
        foreach ($cellRows as $cell) {
            $promotion = $cell['price_kind'] !== 'quote'
                ? $this->promotions->bestFor((int) $cell['service_id'], 'matrix', $cell['price'] !== null ? (string) $cell['price'] : null)
                : null;
            $cells[(int) $cell['variant_id']][] = [
                'length_id' => (int) $cell['length_id'],
                'length' => (string) $cell['length_label'],
                'kind' => (string) $cell['price_kind'],
                'price' => $promotion !== null ? (float) $promotion['price'] : ($cell['price'] !== null ? (float) $cell['price'] : null),
                'original' => $promotion !== null ? (float) $cell['price'] : null,
                'duration' => $cell['duration_minutes'] !== null ? (int) $cell['duration_minutes'] : null,
            ];
        }

        $variants = [];
        foreach ($variantRows as $v) {
            $image = $v['image_media_id'] !== null ? ($media[(int) $v['image_media_id']] ?? null) : null;
            $variants[(int) $v['service_id']][] = [
                'id' => (int) $v['id'],
                'size' => (string) $v['size_code'],
                'size_label' => (string) $v['size_label'],
                'label' => $v['label'] !== null ? (string) $v['label'] : (string) $v['size_code'],
                'note' => $v['note'],
                'duration' => $v['duration_minutes'] !== null ? (int) $v['duration_minutes'] : null,
                'image' => $image?->isImage() ? ['src' => $image->url('medium'), 'alt' => $image->altText] : null,
                'prices' => $cells[(int) $v['id']] ?? [],
            ];
        }

        foreach ($categories as &$category) {
            foreach ($category['services'] as &$service) {
                $detail = $details[$service['id']];
                $service['url'] = '/prestations/' . $service['slug'];
                $service['duration'] = (int) $detail['duration_minutes'];
                $simplePromotion = $detail['pricing_mode'] === 'simple' && $detail['price_kind'] !== 'quote'
                    ? $this->promotions->bestFor($service['id'], 'simple', $detail['price'] !== null ? (string) $detail['price'] : null)
                    : null;
                $service['simple'] = $detail['pricing_mode'] === 'simple'
                    ? [
                        'kind' => (string) $detail['price_kind'],
                        'price' => $simplePromotion !== null ? (float) $simplePromotion['price'] : ($detail['price'] !== null ? (float) $detail['price'] : null),
                        'original' => $simplePromotion !== null ? (float) $detail['price'] : null,
                    ]
                    : null;
                // Une variante sans aucune longueur proposée n'a rien à afficher.
                $service['variants'] = array_values(array_filter($variants[$service['id']] ?? [], static fn (array $v): bool => $v['prices'] !== []));
                $service['option_groups'] = array_map(static fn (array $g): array => [
                    'id' => (int) $g['id'],
                    'label' => (string) $g['label'],
                    'selection' => (string) $g['selection'],
                    'required' => (bool) $g['is_required'],
                    'options' => array_map(static fn (array $o): array => [
                        'id' => (int) $o['id'],
                        'name' => (string) $o['name'],
                        'description' => $o['description'],
                        'kind' => (string) $o['price_kind'],
                        'price' => $o['price'] !== null ? (float) $o['price'] : null,
                        'minutes' => (int) $o['extra_minutes'],
                    ], $g['options']),
                ], $this->options->applicableGroups($service['id']));
            }
            unset($service);
            // Prestations à grille sans aucune variante tarifée : non sélectionnables.
            $category['services'] = array_values(array_filter(
                $category['services'],
                static fn (array $s): bool => $s['simple'] !== null || $s['variants'] !== [],
            ));
        }
        unset($category);

        return array_values(array_filter($categories, static fn (array $c): bool => $c['services'] !== []));
    }

    /**
     * Promotion en cours sur le prix affiché en résumé (plus petit prix de la grille, ou prix unique).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function promotionFor(array $row): ?array
    {
        $base = $row['pricing_mode'] === 'matrix'
            ? ($row['min_price'] !== null ? (string) $row['min_price'] : null)
            : ($row['price_kind'] !== 'quote' && $row['price'] !== null ? (string) $row['price'] : null);

        return $this->promotions->bestFor((int) $row['id'], (string) $row['pricing_mode'], $base);
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $promotion prix remisé à afficher à la place du prix d'origine
     */
    private function priceLabel(array $row, ?array $promotion = null): string
    {
        if ($row['pricing_mode'] === 'matrix') {
            if ($row['min_price'] === null) {
                return 'sur devis';
            }

            return 'à partir de ' . self::euros($promotion['price'] ?? (string) $row['min_price']);
        }

        return match ($row['price_kind']) {
            'quote' => 'sur devis',
            'from' => 'à partir de ' . self::euros($promotion['price'] ?? (string) $row['price']),
            default => self::euros($promotion['price'] ?? (string) $row['price']),
        };
    }

    public static function euros(string|float|int $amount): string
    {
        $value = (float) $amount;
        $decimals = floor($value) === $value ? 0 : 2;

        return number_format($value, $decimals, ',', ' ') . ' €';
    }

    /** « 330 » → « 5 h 30 » */
    public static function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h === 0 ? "{$m} min" : $h . ' h' . ($m > 0 ? ' ' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
    }

    /** « L, M, S, XL » → « S, M, L, XL » */
    private function sortSizes(string $sizes): string
    {
        $order = ['XS' => 0, 'S' => 1, 'M' => 2, 'L' => 3, 'XL' => 4, 'XXL' => 5];
        $list = array_map('trim', explode(',', $sizes));
        usort($list, static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

        return implode(', ', $list);
    }
}
