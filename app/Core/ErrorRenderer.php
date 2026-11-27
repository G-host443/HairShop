<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Transforme une exception en réponse HTML ou JSON et journalise les erreurs serveur.
 * En mode debug, le détail de l'exception est affiché ; jamais en production.
 */
final class ErrorRenderer
{
    private const MESSAGES = [
        400 => 'Requête invalide',
        403 => 'Accès refusé',
        404 => 'Page introuvable',
        405 => 'Méthode non autorisée',
        419 => 'Session expirée',
        422 => 'Données invalides',
        429 => 'Trop de requêtes',
        500 => 'Erreur interne',
        503 => 'Service indisponible',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Config $config,
        private readonly View $view,
        private readonly Session $session,
    ) {
    }

    public function render(Throwable $e, Request $request): Response
    {
        if ($e instanceof ValidationException) {
            return $this->renderValidation($e, $request);
        }

        $status = $e instanceof HttpException ? $e->statusCode() : 500;
        $headers = $e instanceof HttpException ? $e->headers() : [];
        $debug = (bool) $this->config->get('app.debug');

        if ($status >= 500) {
            $this->logger->error($e->getMessage(), [
                'exception' => $e,
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);
        }

        $title = self::MESSAGES[$status] ?? 'Erreur';
        // Le message d'une HttpException est destiné à l'utilisateur ; celui d'une autre exception, non.
        $message = $e instanceof HttpException && $e->getMessage() !== '' ? $e->getMessage() : $title;

        if ($request->wantsJson()) {
            $payload = ['error' => ['status' => $status, 'message' => $message]];
            if ($debug && !$e instanceof HttpException) {
                $payload['error']['exception'] = $e::class . ': ' . $e->getMessage();
                $payload['error']['file'] = $e->getFile() . ':' . $e->getLine();
            }
            $response = Response::json($payload, $status);
        } else {
            $response = Response::html($this->renderHtml($status, $title, $message, $debug ? $e : null), $status);
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Formulaire invalide : retour au formulaire avec erreurs et saisie (HTML) ou 422 (JSON).
     */
    private function renderValidation(ValidationException $e, Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(['error' => ['status' => 422, 'message' => 'Données invalides', 'fields' => $e->errors()]], 422);
        }

        $this->session->flashErrors($e->errors());
        $this->session->flashInput($request->all());

        return Response::redirect($e->redirectTo() ?? $this->previousUrl($request), 303);
    }

    /**
     * URL de la page précédente, uniquement si elle appartient au site (pas de redirection ouverte).
     */
    private function previousUrl(Request $request): string
    {
        $referer = (string) $request->header('referer', '');
        $parts = parse_url($referer);
        $host = (string) $request->header('host', '');

        if ($referer === '' || $parts === false || !isset($parts['path']) || (isset($parts['host']) && $host !== '' && strcasecmp($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''), $host) !== 0)) {
            return $request->path();
        }

        return $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function renderHtml(int $status, string $title, string $message, ?Throwable $debugException): string
    {
        $data = [
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'exception' => $debugException,
        ];

        try {
            $template = $this->view->exists("errors/{$status}.twig") ? "errors/{$status}.twig" : 'errors/default.twig';

            return $this->view->render($template, $data);
        } catch (Throwable $renderError) {
            // Dernier recours si Twig lui-même échoue.
            $this->logger->critical('Échec du rendu de la page d\'erreur : ' . $renderError->getMessage(), ['exception' => $renderError]);

            return '<!doctype html><html lang="fr"><meta charset="utf-8"><title>' . $status . '</title>'
                . '<body style="font-family:sans-serif;padding:3rem"><h1>' . $status . ' — '
                . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1></body></html>';
        }
    }
}
