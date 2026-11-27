<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Models\Media;
use App\Repositories\MediaRepository;

/**
 * Liste JSON des médias pour le sélecteur d'images de l'administration.
 */
final class MediaApiController
{
    private const PER_PAGE = 30;

    public function __construct(private readonly MediaRepository $media)
    {
    }

    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query('page', 1));
        $type = in_array($request->query('type'), ['image', 'video'], true) ? $request->query('type') : null;
        $result = $this->media->search(['type' => $type, 'q' => mb_substr((string) $request->query('q', ''), 0, 100)], $page, self::PER_PAGE);

        return Response::json([
            'items' => array_map(static fn (Media $m): array => $m->toArray(), $result['items']),
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'total' => $result['total'],
        ])->withHeader('Cache-Control', 'no-store');
    }
}
