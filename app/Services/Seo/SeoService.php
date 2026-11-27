<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Repositories\MediaRepository;
use App\Repositories\ReviewRepository;
use App\Services\Schedule\OpeningHoursService;
use App\Services\Settings\SettingsService;
use DateTimeImmutable;

/**
 * Référencement : indexation, robots.txt, plan du site (sitemap.xml), données structurées (schema.org).
 */
final class SeoService
{
    /** Adresses jamais indexées : parcours de réservation, espace cliente, liens personnels. */
    public const PRIVATE_PREFIXES = ['/reserver', '/compte', '/rdv', '/admin', '/api'];

    private const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public function __construct(
        private readonly Database $db,
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly OpeningHoursService $hours,
        private readonly ReviewRepository $reviews,
        private readonly MediaRepository $media,
    ) {
    }

    /** Le site peut-il être indexé ? (désactivable pendant la préparation du site) */
    public function indexingAllowed(): bool
    {
        return $this->settings->bool('seo.indexing', true);
    }

    public static function isPrivate(string $path): bool
    {
        foreach (self::PRIVATE_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /** Valeur de la balise meta robots, ou null pour le comportement par défaut (index, follow). */
    public function robotsFor(string $path, ?string $requested = null): ?string
    {
        if (!$this->indexingAllowed() || self::isPrivate($path)) {
            return 'noindex, nofollow';
        }

        return $requested;
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->config->get('app.url'), '/');
    }

    public function robotsTxt(): string
    {
        if (!$this->indexingAllowed()) {
            return "# Site en préparation : indexation désactivée dans les paramètres.\nUser-agent: *\nDisallow: /\n";
        }

        $lines = ['User-agent: *'];
        foreach (self::PRIVATE_PREFIXES as $prefix) {
            $lines[] = 'Disallow: ' . $prefix;
        }
        $lines[] = 'Disallow: /*?*q=';
        $lines[] = '';
        $lines[] = 'Sitemap: ' . $this->baseUrl() . '/sitemap.xml';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Adresses publiques à indexer, avec leur date de dernière modification si elle est connue.
     *
     * @return list<array{loc: string, lastmod: ?string}>
     */
    public function sitemapEntries(): array
    {
        $base = $this->baseUrl();
        $now = $this->clock->now()->format(DATE_ATOM);
        $entries = [];
        $add = static function (string $path, mixed $lastmod = null) use (&$entries, $base): void {
            $entries[$path] = [
                'loc' => $base . $path,
                'lastmod' => $lastmod !== null ? (new DateTimeImmutable((string) $lastmod))->format('Y-m-d') : null,
            ];
        };

        foreach ($this->db->fetchAll("SELECT slug, kind, updated_at FROM pages WHERE status = 'published' ORDER BY kind = 'home' DESC, title") as $page) {
            $add($page['kind'] === 'home' ? '/' : '/' . $page['slug'], $page['updated_at']);
        }

        $catalogUpdate = $this->db->fetchValue('SELECT max(updated_at) FROM services WHERE is_active');
        $add('/prestations', $catalogUpdate);
        foreach ($this->db->fetchAll(
            'SELECT s.slug, s.updated_at FROM services s JOIN service_categories c ON c.id = s.category_id
             WHERE s.is_active AND c.is_active ORDER BY c.position, s.position, s.name'
        ) as $service) {
            $add('/prestations/' . $service['slug'], $service['updated_at']);
        }

        if ((int) $this->db->fetchValue('SELECT count(*) FROM gallery_items WHERE is_published') > 0) {
            $add('/galerie', $this->db->fetchValue('SELECT max(updated_at) FROM gallery_items WHERE is_published'));
        }
        if ((int) $this->db->fetchValue("SELECT count(*) FROM reviews WHERE status = 'approved'") > 0) {
            $add('/avis', $this->db->fetchValue("SELECT max(COALESCE(moderated_at, created_at)) FROM reviews WHERE status = 'approved'"));
        }

        $visible = "(p.status = 'published' OR p.status = 'scheduled') AND p.published_at <= CAST(:now AS timestamptz)";
        $posts = $this->db->fetchAll("SELECT p.slug, p.updated_at FROM blog_posts p WHERE {$visible} ORDER BY p.published_at DESC", ['now' => $now]);
        if ($posts !== []) {
            $add('/blog', $posts[0]['updated_at']);
            foreach ($this->db->fetchAll(
                "SELECT DISTINCT c.slug FROM blog_categories c JOIN blog_posts p ON p.category_id = c.id WHERE {$visible}",
                ['now' => $now],
            ) as $category) {
                $add('/blog/categorie/' . $category['slug']);
            }
            foreach ($posts as $post) {
                $add('/blog/' . $post['slug'], $post['updated_at']);
            }
        }

        return array_values($entries);
    }

    /** Document XML du plan du site (protocole sitemaps.org). */
    public function sitemapXml(): string
    {
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];
        if ($this->indexingAllowed()) {
            foreach ($this->sitemapEntries() as $entry) {
                $xml[] = '  <url><loc>' . htmlspecialchars($entry['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
                    . ($entry['lastmod'] !== null ? '<lastmod>' . $entry['lastmod'] . '</lastmod>' : '') . '</url>';
            }
        }
        $xml[] = '</urlset>';

        return implode("\n", $xml) . "\n";
    }

    /**
     * Fiche de l'établissement (schema.org HairSalon) pour l'accueil.
     *
     * @return array<string, mixed>
     */
    public function businessJsonLd(): array
    {
        $base = $this->baseUrl();
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'HairSalon',
            '@id' => $base . '/#salon',
            'name' => $this->settings->string('general.site_name', (string) $this->config->get('app.name')),
            'url' => $base . '/',
        ];

        $description = $this->settings->string('seo.default_description', $this->settings->string('general.description'));
        if ($description !== '') {
            $data['description'] = $description;
        }
        foreach (['general.logo_media_id' => 'logo', 'seo.og_media_id' => 'image'] as $key => $property) {
            $id = $this->settings->get($key);
            $media = is_numeric($id) ? $this->media->find((int) $id) : null;
            if ($media?->isImage()) {
                $data[$property] = $base . $media->url($property === 'logo' ? 'thumb' : 'large');
            }
        }
        $phone = $this->settings->string('contact.phone');
        if ($phone !== '') {
            $data['telephone'] = $phone;
        }
        $email = $this->settings->string('contact.email');
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $data['email'] = $email;
        }

        // À domicile, l'adresse exacte n'est jamais publiée : seule la localité l'est.
        $isSalon = $this->settings->string('contact.location_type', 'home') === 'salon';
        $locality = $this->settings->string('contact.public_location');
        if ($isSalon && $this->settings->string('contact.private_address') !== '') {
            $data['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $this->settings->string('contact.private_address'), 'addressCountry' => 'FR'];
        } elseif ($locality !== '') {
            $data['address'] = ['@type' => 'PostalAddress', 'addressLocality' => $locality, 'addressCountry' => 'FR'];
        }
        if ($locality !== '') {
            $data['areaServed'] = $locality;
        }

        $opening = [];
        foreach ($this->hours->weekly() as $day => $ranges) {
            foreach ($ranges as [$opens, $closes]) {
                $opening[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/' . self::DAYS[$day], 'opens' => $opens, 'closes' => $closes];
            }
        }
        if ($opening !== []) {
            $data['openingHoursSpecification'] = $opening;
        }

        $prices = $this->db->fetchOne(
            "SELECT min(p) AS low, max(p) AS high FROM (
                SELECT vp.price AS p FROM variant_prices vp JOIN service_variants v ON v.id = vp.variant_id JOIN services s ON s.id = v.service_id
                WHERE s.is_active AND v.is_active AND vp.price IS NOT NULL
                UNION ALL SELECT price FROM services WHERE is_active AND pricing_mode = 'simple' AND price IS NOT NULL
             ) t"
        );
        if ($prices !== null && $prices['low'] !== null) {
            $data['priceRange'] = sprintf('%d € – %d €', (int) $prices['low'], (int) $prices['high']);
        }

        $socials = [];
        foreach (['instagram', 'facebook', 'tiktok'] as $network) {
            $url = $this->settings->string('social.' . $network);
            if (preg_match('~^https?://~i', $url)) {
                $socials[] = $url;
            }
        }
        if ($socials !== []) {
            $data['sameAs'] = $socials;
        }

        $stats = $this->reviews->approvedStats();
        if ($stats['count'] > 0) {
            $data['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $stats['average'], 'reviewCount' => $stats['count'], 'bestRating' => 5, 'worstRating' => 1];
        }

        return $data;
    }

    /**
     * Fil d'Ariane (schema.org BreadcrumbList).
     *
     * @param list<array{0: string, 1: string}> $items [nom, chemin]
     * @return array<string, mixed>
     */
    public function breadcrumbJsonLd(array $items): array
    {
        $base = $this->baseUrl();
        $list = [];
        foreach (array_values($items) as $i => [$name, $path]) {
            $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $base . $path];
        }

        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }

    /**
     * Article du blog (schema.org BlogPosting).
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function articleJsonLd(array $post, ?string $imageUrl, string $description): array
    {
        $base = $this->baseUrl();
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => mb_substr((string) $post['title'], 0, 110),
            'mainEntityOfPage' => $base . '/blog/' . $post['slug'],
            'datePublished' => (new DateTimeImmutable((string) $post['published_at']))->format(DATE_ATOM),
            'dateModified' => (new DateTimeImmutable((string) $post['updated_at']))->format(DATE_ATOM),
            'author' => ['@type' => 'Person', 'name' => trim((string) ($post['author_first_name'] ?? '')) ?: $this->settings->string('general.site_name')],
            'publisher' => ['@id' => $base . '/#salon'],
            'description' => $description,
        ];
        if ($imageUrl !== null) {
            $data['image'] = $imageUrl;
        }

        return $data;
    }

    /** JSON sûr dans une balise <script type="application/ld+json"> (« </script> » neutralisé). */
    public static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }
}
