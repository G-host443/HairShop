<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Clock;
use App\Core\Database;
use DI\Container;
use DOMDocument;
use Tests\Support\Browser;

/**
 * Référencement et performances : robots.txt, sitemap.xml, balises robots et canoniques,
 * données structurées, nombre de requêtes par page.
 */
final class SeoTest extends AdminTestCase
{
    use CatalogFixtures;
    use ContentFixtures;

    protected function database(): Database
    {
        return $this->db;
    }

    private function setting(string $key, mixed $value): void
    {
        $this->db->execute('UPDATE site_settings SET value = CAST(:v AS jsonb) WHERE key = :k', ['k' => $key, 'v' => json_encode($value)]);
    }

    /** @return list<array<string, mixed>> données JSON-LD de la page */
    private static function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        return array_map(static fn (string $json): array => json_decode($json, true, 32, JSON_THROW_ON_ERROR), $m[1]);
    }

    private static function robotsMeta(string $html): ?string
    {
        return preg_match('#<meta name="robots" content="([^"]+)">#', $html, $m) ? $m[1] : null;
    }

    public function testRobotsTxt(): void
    {
        $response = $this->browser()->get('/robots.txt');
        self::assertSame(200, $response->status());
        self::assertStringStartsWith('text/plain', (string) $response->header('Content-Type'));
        self::assertStringContainsString("Disallow: /admin\n", $response->content());
        self::assertStringContainsString("Disallow: /compte\n", $response->content());
        self::assertMatchesRegularExpression('#Sitemap: https?://[^\s]+/sitemap\.xml#', $response->content());

        $this->setting('seo.indexing', false);
        $closed = $this->browser()->get('/robots.txt')->content();
        self::assertStringContainsString("Disallow: /\n", $closed);
        self::assertStringNotContainsString('Sitemap:', $closed);
    }

    public function testSitemapIsValidAndListsOnlyPublicContent(): void
    {
        $this->matrixService();
        $this->db->execute("UPDATE services SET is_active = false WHERE slug = 'knotless-test'");
        $this->matrixService('box-braids-test', 'Box braids');
        $this->db->execute(
            "INSERT INTO blog_posts (title, slug, content, status, published_at) VALUES
             ('Publié', 'article-publie', '<p>Texte</p>', 'published', now() - interval '1 day'),
             ('Programmé', 'article-futur', '<p>Texte</p>', 'scheduled', now() + interval '3 days'),
             ('Brouillon', 'article-brouillon', '<p>Texte</p>', 'draft', NULL)"
        );

        $response = $this->browser()->get('/sitemap.xml');
        self::assertSame(200, $response->status());
        self::assertStringStartsWith('application/xml', (string) $response->header('Content-Type'));

        $doc = new DOMDocument();
        self::assertTrue($doc->loadXML($response->content()), 'XML bien formé.');
        self::assertSame('urlset', $doc->documentElement->localName);
        self::assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $doc->documentElement->namespaceURI);

        $locs = [];
        foreach ($doc->getElementsByTagName('url') as $url) {
            $loc = $url->getElementsByTagName('loc')->item(0)?->textContent;
            self::assertNotNull($loc);
            self::assertMatchesRegularExpression('#^https?://#', $loc, 'Adresse absolue.');
            $lastmod = $url->getElementsByTagName('lastmod')->item(0)?->textContent;
            if ($lastmod !== null) {
                self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $lastmod, 'Date W3C.');
            }
            $locs[] = (string) parse_url($loc, PHP_URL_PATH);
        }
        self::assertLessThanOrEqual(50000, count($locs));
        self::assertSame(count($locs), count(array_unique($locs)), 'Aucun doublon.');

        foreach (['/', '/contact', '/prestations', '/prestations/box-braids-test', '/blog', '/blog/article-publie'] as $expected) {
            self::assertContains($expected, $locs, $expected);
        }
        foreach (['/prestations/knotless-test', '/blog/article-futur', '/blog/article-brouillon', '/mentions-legales', '/reserver', '/compte', '/galerie', '/avis'] as $excluded) {
            self::assertNotContains($excluded, $locs, $excluded . ' (inactif, non publié, privé ou vide)');
        }

        $this->setting('seo.indexing', false);
        self::assertStringNotContainsString('<url>', $this->browser()->get('/sitemap.xml')->content(), 'Indexation désactivée : plan vide.');
    }

    public function testRobotsMetaAndCanonical(): void
    {
        $f = $this->matrixService();
        $this->db->execute(
            "INSERT INTO gallery_items (media_id, title, category_id, is_published) VALUES (:m, 'Knotless', :c, true)",
            ['m' => $this->image(), 'c' => $f['category']],
        );
        $visitor = $this->browser();

        self::assertNull(self::robotsMeta($visitor->get('/')->content()), 'Accueil indexable.');
        self::assertSame('noindex, nofollow', self::robotsMeta($visitor->get('/reserver')->content()), 'Parcours de réservation non indexé.');
        self::assertSame('noindex, nofollow', self::robotsMeta($visitor->get('/compte/connexion')->content()));
        self::assertSame('noindex, follow', self::robotsMeta($visitor->get('/galerie?q=knotless')->content()), 'Recherche non indexée, liens suivis.');

        $category = $visitor->get('/galerie?categorie=knotless-test-cat&vue=grille')->content();
        self::assertMatchesRegularExpression('#<link rel="canonical" href="[^"]+/galerie\?categorie=knotless-test-cat">#', $category);

        $this->setting('seo.indexing', false);
        self::assertSame('noindex, nofollow', self::robotsMeta($this->browser()->get('/')->content()), 'Site en préparation.');
    }

    public function testStructuredData(): void
    {
        $this->matrixService();
        $this->setting('contact.location_type', 'home');
        $this->setting('contact.public_location', 'Lyon 7e');
        $this->setting('contact.private_address', '12 rue Secrète');
        $this->db->execute("INSERT INTO reviews (author_name, rating, comment, status) VALUES ('Awa', 5, 'Parfait, je recommande.', 'approved'), ('Fatou', 4, 'Très bien, merci beaucoup.', 'approved')");

        $home = $this->browser()->get('/')->content();
        $salon = array_values(array_filter(self::jsonLd($home), static fn (array $d) => $d['@type'] === 'HairSalon'))[0] ?? null;
        self::assertNotNull($salon, 'Fiche établissement sur l\'accueil.');
        self::assertSame('Lyon 7e', $salon['address']['addressLocality']);
        self::assertStringNotContainsString('Secrète', $home, 'Adresse privée jamais publiée (prestation à domicile).');
        self::assertSame(4.5, (float) $salon['aggregateRating']['ratingValue']);
        self::assertSame(2, $salon['aggregateRating']['reviewCount']);
        self::assertNotEmpty($salon['openingHoursSpecification']);
        self::assertSame('70 € – 100 €', $salon['priceRange']);

        $service = $this->browser()->get('/prestations/knotless-test')->content();
        $breadcrumb = array_values(array_filter(self::jsonLd($service), static fn (array $d) => $d['@type'] === 'BreadcrumbList'))[0];
        self::assertSame(['Prestations', 'Braids', 'Knotless'], array_column($breadcrumb['itemListElement'], 'name'));

        $this->db->execute("INSERT INTO blog_posts (title, slug, content, status, published_at, seo_description) VALUES ('Le soin </script><script>alert(1)</script>', 'le-soin', '<p>Texte</p>', 'published', now() - interval '1 day', 'Conseils')");
        $post = $this->browser()->get('/blog/le-soin')->content();
        self::assertStringContainsString('<meta property="og:type" content="article">', $post);
        self::assertStringContainsString('article:published_time', $post);
        self::assertStringNotContainsString('</script><script>alert(1)', $post, 'Titre neutralisé dans le JSON-LD.');
        $article = array_values(array_filter(self::jsonLd($post), static fn (array $d) => $d['@type'] === 'BlogPosting'))[0];
        self::assertSame('Le soin </script><script>alert(1)</script>', $article['headline']);
    }

    public function testPublicPagesStayWithinQueryBudget(): void
    {
        $this->matrixService();
        $this->matrixService('box-braids-test', 'Box braids');
        $this->matrixService('fulani-test', 'Fulani');

        foreach (['/', '/prestations', '/prestations/knotless-test', '/blog', '/galerie'] as $path) {
            $before = $this->db->stats()['queries'];
            self::assertSame(200, $this->browser()->get($path)->status());
            $queries = $this->db->stats()['queries'] - $before;
            self::assertLessThanOrEqual(40, $queries, "{$path} : {$queries} requêtes (une requête par prestation serait une régression).");
        }
    }
}
