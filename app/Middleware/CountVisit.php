<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Http\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Content\PreviewContext;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Compte les pages publiques vues (par jour et par adresse), sans cookie ni donnée personnelle.
 * Ne compte pas : administration, espace cliente, liens privés, aperçus, robots, erreurs, réponses non HTML.
 * Une panne du compteur ne fait jamais échouer la page.
 */
final class CountVisit implements MiddlewareInterface
{
    private const EXCLUDED_PREFIXES = ['/admin', '/api', '/compte', '/rdv', '/reserver/confirmation', '/sante'];
    private const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|headless|lighthouse|facebookexternalhit|bingpreview/i';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly PreviewContext $preview,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);

        if ($this->shouldCount($request, $response)) {
            try {
                // Transaction propre (point de sauvegarde si une transaction est déjà ouverte) :
                // un échec du compteur n'annule jamais le reste du traitement.
                $this->db->transaction(fn () => $this->db->execute(
                    'INSERT INTO site_visits (day, path, views) VALUES (CAST(:day AS date), :path, 1)
                     ON CONFLICT (day, path) DO UPDATE SET views = site_visits.views + 1',
                    ['day' => $this->clock->now()->format('Y-m-d'), 'path' => '/' . ltrim(mb_substr($request->path(), 0, 199), '/')],
                ));
            } catch (Throwable $e) {
                $this->logger->warning('Compteur de visites indisponible : ' . $e->getMessage());
            }
        }

        return $response;
    }

    private function shouldCount(Request $request, Response $response): bool
    {
        if ($request->method() !== 'GET' || $response->status() !== 200 || $this->preview->active()) {
            return false;
        }
        if (!str_starts_with((string) $response->header('Content-Type'), 'text/html')) {
            return false;
        }
        if (str_contains((string) $response->header('X-Robots-Tag'), 'noindex')) {
            return false;
        }
        $path = $request->path();
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return false;
            }
        }
        $agent = (string) $request->userAgent();

        return $agent !== '' && preg_match(self::BOT_PATTERN, $agent) !== 1;
    }
}
