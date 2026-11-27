<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Exceptions\HttpException;
use App\Core\Http\Response;
use App\Repositories\PageRepository;
use App\Services\Content\PageRenderer;

/**
 * Page d'accueil : la page de type « home », rendue par le template actif.
 */
final class HomeController
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly PageRenderer $renderer,
    ) {
    }

    public function index(): Response
    {
        $page = $this->pages->findPublishedHome() ?? throw HttpException::notFound('La page d\'accueil n\'est pas encore publiée.');

        return Response::html($this->renderer->render($page, '/'));
    }
}
