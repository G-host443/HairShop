<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\Response;
use App\Core\Router;
use App\Core\Session\Session;
use App\Core\Validation\Validator;
use App\Core\View;

/**
 * Raccourcis communs aux contrôleurs HTML.
 */
abstract class Controller
{
    public function __construct(
        protected readonly View $view,
        protected readonly Router $router,
        protected readonly Session $session,
    ) {
    }

    /** @param array<string, mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data), $status);
    }

    /** @param array<string, scalar> $params */
    protected function redirectTo(string $route, array $params = []): Response
    {
        return Response::redirect($this->router->url($route, $params), 303);
    }

    protected function flash(string $type, string $message): void
    {
        $this->session->flash($type, $message);
    }

    /**
     * Valide la saisie ; en cas d'erreur, ValidationException renvoie vers le formulaire.
     *
     * @param array<string, mixed>                $input
     * @param array<string, string|list<string>> $rules
     * @param array<string, string>               $labels
     * @return array<string, mixed>
     */
    protected function validate(array $input, array $rules, array $labels = []): array
    {
        return Validator::make($input, $rules, $labels)->validate();
    }
}
