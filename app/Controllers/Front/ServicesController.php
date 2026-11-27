<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Exceptions\HttpException;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Repositories\CustomerRepository;
use App\Repositories\GalleryRepository;
use App\Repositories\MediaRepository;
use App\Services\Auth\AuthService;
use App\Repositories\ServiceRepository;
use App\Sections\PricingGridSection;
use App\Services\Catalog\BookingLinkBuilder;
use App\Services\Catalog\CatalogReader;
use App\Services\Content\PageRenderer;
use App\Services\Seo\SeoService;

/**
 * Pages publiques du catalogue : /prestations et /prestations/{slug}.
 */
final class ServicesController
{
    public function __construct(
        private readonly CatalogReader $catalog,
        private readonly ServiceRepository $services,
        private readonly MediaRepository $media,
        private readonly BookingLinkBuilder $links,
        private readonly PageRenderer $renderer,
        private readonly AuthService $auth,
        private readonly CustomerRepository $customers,
        private readonly Session $session,
        private readonly GalleryRepository $gallery,
        private readonly SeoService $seo,
    ) {
    }

    public function index(): Response
    {
        $categories = $this->catalog->categoriesWithServices();

        // Couverture de chaque prestation.
        $covers = [];
        foreach ($this->services->list(['status' => 'active']) as $row) {
            if ($row['cover_media_id'] !== null) {
                $covers[(int) $row['id']] = (int) $row['cover_media_id'];
            }
        }
        $media = $this->media->findMany(array_values($covers));
        foreach ($categories as &$category) {
            foreach ($category['services'] as &$service) {
                $service['cover'] = isset($covers[$service['id']]) ? ($media[$covers[$service['id']]] ?? null) : null;
            }
        }
        unset($category, $service);

        return Response::html($this->renderer->renderView('services', ['categories' => $categories], [
            'title' => 'Prestations',
            'seo_description' => 'Toutes les coiffures proposées, avec leurs tarifs selon la taille et la longueur.',
        ], '/prestations'));
    }

    public function show(string $slug): Response
    {
        $service = $this->services->findActiveBySlug($slug) ?? throw HttpException::notFound('Cette prestation n\'existe pas ou n\'est plus proposée.');
        $id = (int) $service['id'];

        // Données du sélecteur, limitées à cette prestation.
        $selector = null;
        foreach ($this->catalog->selectorData() as $category) {
            foreach ($category['services'] as $entry) {
                if ($entry['id'] === $id) {
                    $selector = $entry;
                }
            }
        }

        $imageIds = $this->services->imageIds($id);
        $media = $this->media->findMany($imageIds);
        $images = array_values(array_filter(array_map(static fn (int $mid) => $media[$mid] ?? null, $imageIds), static fn ($m) => $m !== null && $m->isImage()));

        $summary = null;
        foreach ($this->catalog->categoriesWithServices() as $category) {
            foreach ($category['services'] as $entry) {
                if ($entry['id'] === $id) {
                    $summary = $entry;
                }
            }
        }

        return Response::html($this->renderer->renderView('service', [
            'service' => $service,
            'images' => $images,
            'price_label' => $summary['price_label'] ?? null,
            'summary' => $summary,
            'duration_label' => self::durationRange($service, $selector),
            'paragraphs' => array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', (string) $service['description']) ?: []))),
            'selector' => $selector,
            'selector_json' => $selector !== null ? PricingGridSection::json([
                'categories' => [['slug' => $service['category_slug'], 'name' => $service['category_name'], 'note' => null, 'services' => [$selector]]],
                'booking' => $this->links->config(),
                'single' => true,
            ]) : null,
            'booking_url' => $this->links->urlFor($id, (string) $service['name'], $selector === null || $selector['simple'] === null || $selector['option_groups'] !== []),
            'booking_label' => $this->links->config()['mode'] === 'online' ? 'Réserver' : $this->links->config()['label'],
            'related' => $this->services->activeRelated($id),
            'is_favorite' => $this->isFavorite($id),
            'gallery' => $this->galleryFor($id),
        ], [
            'title' => (string) $service['name'],
            'json_ld' => [$this->seo->breadcrumbJsonLd([
                ['Prestations', '/prestations'],
                [(string) $service['category_name'], '/prestations#categorie-' . $service['category_slug']],
                [(string) $service['name'], '/prestations/' . $slug],
            ])],
            'seo_title' => $service['seo_title'] ?? null,
            'seo_description' => $service['seo_description'] ?? $service['summary'] ?? null,
            'og_media_id' => $images !== [] ? $images[0]->id : null,
        ], '/prestations/' . $slug));
    }

    /**
     * Réalisations publiées liées à la prestation (8 au plus).
     *
     * @return list<array<string, mixed>>
     */
    private function galleryFor(int $serviceId): array
    {
        $rows = $this->gallery->published(['service' => $serviceId], 8);
        $media = $this->media->findMany(array_merge(array_column($rows, 'media_id'), array_filter(array_column($rows, 'after_media_id'))));
        $items = [];
        foreach ($rows as $row) {
            $image = $media[$row['media_id']] ?? null;
            if ($image !== null && $image->isImage()) {
                $after = $row['after_media_id'] !== null ? ($media[$row['after_media_id']] ?? null) : null;
                $items[] = $row + ['image' => $image, 'after' => $after?->isImage() ? $after : null];
            }
        }

        return $items;
    }

    /** Prestation en favori chez la cliente connectée (false si personne n'est connecté). */
    private function isFavorite(int $serviceId): bool
    {
        if (!$this->session->isStarted()) {
            return false;
        }
        $user = $this->auth->user();
        if ($user === null || !$user->isCustomer()) {
            return false;
        }
        $customer = $this->customers->findByUserId($user->id);

        return $customer !== null && in_array($serviceId, $this->customers->favoriteServiceIds((int) $customer['id']), true);
    }

    /**
     * « environ 3 h » ou « de 3 h à 6 h » selon la taille et la longueur.
     *
     * @param array<string, mixed>      $service
     * @param array<string, mixed>|null $selector
     */
    private static function durationRange(array $service, ?array $selector): string
    {
        $durations = [(int) $service['duration_minutes']];
        if ($selector !== null && $selector['variants'] !== []) {
            $durations = [];
            foreach ($selector['variants'] as $variant) {
                foreach ($variant['prices'] as $cell) {
                    $durations[] = $cell['duration'] ?? $variant['duration'] ?? (int) $service['duration_minutes'];
                }
            }
        }

        $min = min($durations);
        $max = max($durations);

        return $min === $max
            ? 'environ ' . CatalogReader::duration($min)
            : 'de ' . CatalogReader::duration($min) . ' à ' . CatalogReader::duration($max) . ' selon la taille et la longueur';
    }
}
