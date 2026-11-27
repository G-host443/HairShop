<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Kernel;
use App\Core\Session\ArraySessionStorage;
use App\Core\Session\SessionStorage;
use App\Services\Mail\MailerInterface;
use DI\Container;

/**
 * Navigateur de test : chaque requête passe par un conteneur neuf (comme une vraie requête PHP),
 * en partageant la session (cookie simulé), la connexion à la base (transaction du test) et le mailer.
 */
final class Browser
{
    public readonly ArraySessionStorage $session;

    /** @var list<callable(Container): void> */
    private array $setup = [];

    public function __construct(
        private readonly Database $db,
        private readonly ArrayMailer $mailer,
        private readonly string $ip = '127.0.0.1',
    ) {
        $this->session = new ArraySessionStorage();
    }

    /** Ajoute une configuration appliquée au conteneur de chaque requête (routes de test…). */
    public function configure(callable $callback): self
    {
        $this->setup[] = $callback;

        return $this;
    }

    public function get(string $path): Response
    {
        return $this->request('GET', $path);
    }

    /**
     * @param array<string, mixed> $data
     * @param bool $withCsrf ajoute automatiquement le jeton CSRF de la session
     */
    public function post(string $path, array $data = [], bool $withCsrf = true): Response
    {
        if ($withCsrf) {
            $data['_token'] = $this->csrfToken();
        }

        return $this->request('POST', $path, $data);
    }

    /**
     * Envoi de fichiers (formulaire multipart).
     *
     * @param list<\App\Core\Http\UploadedFile> $files
     * @param array<string, mixed>              $data
     */
    public function upload(string $path, array $files, array $data = [], bool $json = false): Response
    {
        $data['_token'] = $this->csrfToken();

        return $this->request('POST', $path, $data, ['files' => $files], $json);
    }

    public function getJson(string $path): Response
    {
        return $this->request('GET', $path, [], [], true);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     */
    public function request(string $method, string $path, array $body = [], array $files = [], bool $json = false): Response
    {
        /** @var Container $container */
        $container = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $container->set(Database::class, $this->db);
        $container->set(SessionStorage::class, $this->session);
        $container->set(MailerInterface::class, $this->mailer);
        foreach ($this->setup as $callback) {
            $callback($container);
        }

        $parts = parse_url($path);
        parse_str($parts['query'] ?? '', $query);

        $request = new Request(
            $method,
            $parts['path'] ?? '/',
            $query,
            $body,
            ['host' => 'localhost', 'accept' => $json ? 'application/json' : 'text/html', 'user-agent' => 'PHPUnit'],
            [],
            $files,
            ['REMOTE_ADDR' => $this->ip],
        );

        return $container->get(Kernel::class)->handle($request);
    }

    public function csrfToken(): string
    {
        $data = $this->session->data();
        if (!isset($data['_csrf_token'])) {
            $this->get('/admin/connexion'); // génère le jeton
            $data = $this->session->data();
        }

        return (string) ($data['_csrf_token'] ?? '');
    }

    /** @return array<string, list<string>> erreurs de validation en attente d'affichage */
    public function errors(): array
    {
        return $this->session->data()['_errors'] ?? [];
    }

    public function flash(string $key): mixed
    {
        return $this->session->data()[$key] ?? null;
    }
}
