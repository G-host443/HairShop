<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Http\Response;
use App\Services\Seo\SeoService;

/**
 * /robots.txt et /sitemap.xml, générés à partir du contenu publié.
 */
final class SeoController
{
    public function __construct(private readonly SeoService $seo)
    {
    }

    public function robots(): Response
    {
        return (new Response($this->seo->robotsTxt(), 200, ['Content-Type' => 'text/plain; charset=UTF-8']))
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }

    public function sitemap(): Response
    {
        return (new Response($this->seo->sitemapXml(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']))
            ->withHeader('Cache-Control', 'public, max-age=3600');
    }
}
