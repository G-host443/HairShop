<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Security\Csrf;
use App\Core\Session\Session;
use App\Models\Media;
use App\Repositories\MediaRepository;
use App\Services\Auth\AuthService;
use App\Services\Content\PreviewContext;
use App\Services\Settings\SettingsService;
use App\Services\Theme\TemplateManager;
use Psr\Container\ContainerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Moteur de vues Twig.
 *
 * - Espace de noms principal : resources/views (admin, compte, e-mails, erreurs).
 * - Espace @components : resources/components (macros partagées).
 * - Espace @theme : ajouté à l'étape 4 par le TemplateManager (templates/<slug>).
 *
 * L'échappement HTML automatique est activé partout (protection XSS).
 * Les services liés à la requête (session, authentification) sont résolus à la demande,
 * car la vue peut être utilisée avant le démarrage de la session (pages d'erreur, e-mails).
 */
final class View
{
    private Environment $twig;

    public function __construct(
        private readonly Config $config,
        private readonly Router $router,
        private readonly ContainerInterface $container,
    ) {
        $loader = new FilesystemLoader(base_path('resources/views'));
        $loader->addPath(base_path('resources/components'), 'components');

        $debug = (bool) $config->get('app.debug');

        $this->twig = new Environment($loader, [
            'cache' => $debug ? false : storage_path('cache/twig'),
            'debug' => $debug,
            'strict_variables' => $debug,
            'autoescape' => 'html',
        ]);

        $this->registerGlobals();
        $this->registerFunctions();
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    public function exists(string $template): bool
    {
        return $this->twig->getLoader()->exists($template);
    }

    /**
     * Ajoute un dossier de gabarits sous un espace de noms (@theme, @section…).
     */
    public function addPath(string $path, string $namespace, bool $prepend = false): void
    {
        $loader = $this->twig->getLoader();
        if ($loader instanceof FilesystemLoader) {
            $prepend ? $loader->prependPath($path, $namespace) : $loader->addPath($path, $namespace);
        }
    }

    public function addGlobal(string $name, mixed $value): void
    {
        $this->twig->addGlobal($name, $value);
    }

    public function twig(): Environment
    {
        return $this->twig;
    }

    private function registerGlobals(): void
    {
        $this->twig->addGlobal('app', [
            'name' => $this->config->get('app.name'),
            'env' => $this->config->get('app.env'),
            'debug' => (bool) $this->config->get('app.debug'),
            'url' => $this->config->get('app.url'),
            'locale' => substr((string) $this->config->get('app.locale', 'fr_FR'), 0, 2),
        ]);
    }

    private function registerFunctions(): void
    {
        $functions = [
            new TwigFunction('url', fn (string $name, array $params = []): string => $this->router->url($name, $params)),
            new TwigFunction('absolute_url', fn (string $path): string => $this->config->get('app.url') . '/' . ltrim($path, '/')),

            // Ajoute la date de modification pour forcer le rafraîchissement du cache navigateur.
            new TwigFunction('asset', static function (string $path): string {
                $path = '/' . ltrim($path, '/');
                $file = public_path($path);

                return is_file($file) ? $path . '?v=' . filemtime($file) : $path;
            }),

            // --- Formulaires ---
            new TwigFunction('csrf_token', fn (): string => $this->container->get(Csrf::class)->token()),
            new TwigFunction('csrf_field', fn (): Markup => new Markup(
                '<input type="hidden" name="_token" value="' . htmlspecialchars($this->container->get(Csrf::class)->token(), ENT_QUOTES, 'UTF-8') . '">',
                'UTF-8',
            )),
            new TwigFunction('method_field', static fn (string $method): Markup => new Markup(
                '<input type="hidden" name="_method" value="' . htmlspecialchars(strtoupper($method), ENT_QUOTES, 'UTF-8') . '">',
                'UTF-8',
            )),
            new TwigFunction('old', fn (string $key, mixed $default = ''): mixed => $this->session()?->old($key, $default) ?? $default),
            new TwigFunction('errors', fn (?string $field = null): array => $this->fieldErrors($field)),
            new TwigFunction('flash', fn (string $key): mixed => $this->session()?->get($key)),

            // --- Paramètres et médias ---
            new TwigFunction('setting', fn (string $key, mixed $default = null): mixed => $this->container->get(SettingsService::class)->get($key, $default)),
            new TwigFunction('media_url', function (int|string|Media|null $media, ?string $variant = null): ?string {
                if ($media === null || $media === '') {
                    return null;
                }
                $media = $media instanceof Media ? $media : $this->container->get(MediaRepository::class)->find((int) $media);

                return $media?->url($variant);
            }),

            // --- Template public actif ---
            new TwigFunction('theme_asset', fn (string $path): string => $this->container->get(TemplateManager::class)->assetUrl($path)),
            new TwigFunction('theme_tokens_css', fn (): string => $this->container->get(TemplateManager::class)->tokensCssUrl()),
            new TwigFunction('theme_fonts_url', fn (): ?string => $this->container->get(TemplateManager::class)->tokens()->fontsUrl()),
            new TwigFunction('theme_token', fn (string $key): ?string => $this->container->get(TemplateManager::class)->tokens()->get($key)),
            new TwigFunction('theme_motion', fn (): string => $this->container->get(TemplateManager::class)->tokens()->motion()),
            new TwigFunction('theme_layout', fn (string $option): ?string => $this->container->get(TemplateManager::class)->layout($option)),
            new TwigFunction('theme_slug', fn (): string => $this->container->get(TemplateManager::class)->manifest()->slug),
            new TwigFunction('preview_mode', fn (): bool => $this->container->get(PreviewContext::class)->active()),

            // --- Utilisateur connecté ---
            new TwigFunction('auth_user', fn () => $this->container->get(AuthService::class)->user()),
            new TwigFunction('can', fn (string $permission): bool => $this->container->get(AuthService::class)->can($permission)),
            // Cliente connectée à son espace (null sinon) — sans démarrer de session si elle ne l'est pas déjà.
            new TwigFunction('customer_user', function () {
                if ($this->session() === null) {
                    return null;
                }
                $user = $this->container->get(AuthService::class)->user();

                return $user?->isCustomer() ? $user : null;
            }),
        ];

        foreach ($functions as $function) {
            $this->twig->addFunction($function);
        }

        // Regroupe des lignes par valeur d'une colonne, en conservant l'ordre : rows|group_by('category_name')
        $this->twig->addFilter(new TwigFilter('group_by', static function (iterable $rows, string $key): array {
            $groups = [];
            foreach ($rows as $row) {
                $groups[(string) (is_array($row) ? ($row[$key] ?? '') : ($row->$key ?? ''))][] = $row;
            }

            return $groups;
        }));
        // Dates en français dans le fuseau du site : 'long' = « samedi 28 novembre 2026 », 'datetime' = « … à 14 h 30 »,
        // 'short' = « 28/11/2026 », 'time' = « 14 h 30 », 'month' = « novembre 2026 ».
        $this->twig->addFilter(new TwigFilter('date_fr', function (mixed $value, string $format = 'long'): string {
            if ($value === null || $value === '') {
                return '';
            }
            $zone = new \DateTimeZone(date_default_timezone_get());
            $date = $value instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($value)->setTimezone($zone)
                : (new \DateTimeImmutable((string) $value))->setTimezone($zone);
            $time = $date->format('G') . ' h ' . $date->format('i');
            $pattern = match ($format) {
                'short' => 'dd/MM/yyyy',
                'month' => 'MMMM yyyy',
                'day' => 'EEEE d MMMM',
                default => 'EEEE d MMMM yyyy',
            };
            if ($format === 'time') {
                return $time;
            }
            $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $zone, null, $pattern);
            $text = (string) $formatter->format($date);

            return $format === 'datetime' ? $text . ' à ' . $time : $text;
        }));
        $this->twig->addFilter(new TwigFilter('euros', static function (mixed $amount): string {
            $value = (float) $amount;

            return number_format($value, floor($value) === $value ? 0 : 2, ',', ' ') . ' €';
        }));
        $this->twig->addFilter(new TwigFilter('repeat', static fn (string $text, int $times): string => str_repeat($text, max(0, $times))));
        $this->twig->addFilter(new TwigFilter('filesize', static function (int|float|string $bytes): string {
            $bytes = (float) $bytes;
            $units = ['o', 'Ko', 'Mo', 'Go'];
            $i = 0;
            while ($bytes >= 1024 && $i < count($units) - 1) {
                $bytes /= 1024;
                $i++;
            }

            return number_format($bytes, $i === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$i];
        }));
    }

    /** @return array<string, list<string>>|list<string> */
    private function fieldErrors(?string $field): array
    {
        $errors = $this->session()?->errors() ?? [];

        return $field === null ? $errors : ($errors[$field] ?? []);
    }

    private function session(): ?Session
    {
        $session = $this->container->get(Session::class);

        return $session->isStarted() ? $session : null;
    }
}
