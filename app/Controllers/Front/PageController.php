<?php

declare(strict_types=1);

namespace App\Controllers\Front;

use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\PageRepository;
use App\Services\Content\PageRenderer;

/**
 * Pages de contenu publiées : /contact, /mentions-legales, /faq…
 */
final class PageController
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly PageRenderer $renderer,
    ) {
    }

    public function show(Request $request, string $slug): Response
    {
        $page = $this->pages->findPublishedBySlug($slug) ?? throw HttpException::notFound();

        // L'accueil n'a qu'une adresse : « / ».
        if ($page['kind'] === 'home') {
            return Response::redirect('/', 301);
        }

        // Message pré-rempli du formulaire de contact (depuis le sélecteur de tarifs) : texte brut, 500 caractères max.
        $prefill = $request->query('message');
        $prefill = is_string($prefill) ? mb_substr(trim(strip_tags($prefill)), 0, 500) : '';

        return Response::html($this->renderer->render($page, '/' . $slug, ['prefill_message' => $prefill]));
    }
}
